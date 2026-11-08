<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Internal\Http;

use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\TransportException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\Internal\Http\ApiTransport;
use Jojeringnax\Airwallex\Internal\Http\HttpExchange;
use Jojeringnax\Airwallex\Internal\Http\Operation;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\HttpScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionProperty;
use RuntimeException;

final class ClientCoreTest extends HttpTestCase
{
    private const LOGIN = '{"token":"test-token-only","expires_at":"2030-01-01T00:00:00Z"}';

    public function testInitializationAndIndependentDelegationPreserveAccountHeadersAndVersion(): void
    {
        $http = $this->scenario([[201,
             self::LOGIN],
             [200,
             '{}'],
             [201,
             self::LOGIN],
             [200,
             '{}'],
             [201,
             self::LOGIN],
             [200,
             '{}']]);
        $platform = $this->client($http);
        $a = $platform->forConnectedAccount('acct_A');
        $b = $platform->forConnectedAccount('acct_B');
        $this->send($a);
        $this->send($b);
        $this->send($platform);
        self::assertSame('acct_A', $http->requests[1]->getHeaderLine('x-on-behalf-of'));
        self::assertSame('acct_B', $http->requests[3]->getHeaderLine('x-on-behalf-of'));
        self::assertSame('', $http->requests[5]->getHeaderLine('x-on-behalf-of'));
        foreach ($http->requests as $request) {
            self::assertSame('2026-08-21', $request->getHeaderLine('x-api-version'));
            self::assertStringStartsWith('https://api.sandbox.airwallex.com/', (string) $request->getUri());
        }
        self::assertSame('', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('Bearer test-token-only', $http->requests[1]->getHeaderLine('Authorization'));
        self::assertSame('', $http->requests[1]->getHeaderLine('x-api-key'));
        self::assertSame('', $http->requests[0]->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $http->requests[0]->getBody());
    }

    public function testSelectedLoginAccountAndProductionAreExplicit(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{}']]);
        $client = $this->client($http, Environment::Production, 'acct_selected');
        $this->send($client);
        self::assertSame('acct_selected', $http->requests[0]->getHeaderLine('x-login-as'));
        self::assertSame('', $http->requests[1]->getHeaderLine('x-login-as'));
        self::assertStringStartsWith('https://api.airwallex.com/', (string) $http->requests[0]->getUri());
        $this->expectException(ConfigurationException::class);
        $client->forConnectedAccount('acct_unsupported');
    }

    public function testDelegatedBillingIsRejectedWithoutAnyNetworkCall(): void
    {
        $http = $this->scenario([]);
        $client = $this->client($http)->forConnectedAccount('acct_A');
        try {
            $client->billing();
            self::fail('Expected configuration failure.');
        } catch (ConfigurationException) {
            self::assertSame([], $http->requests);
        }
        $this->expectException(ConfigurationException::class);
        $this->send(
            $client,
            new Operation(
                method: 'GET',
                path: '/api/v1/billing/billing_customers',
                family: 'billing'
            )
        );
    }

    public function testExactBodyEncodingAndNativeListEnvelope(): void
    {
        $http = $this->scenario([[201,
             self::LOGIN],
             [201,
             '{"amount":19.90,"quantity":2,"extra":1.2300}'],
             [200,
             '{"items":[],"page_after":null,"page_before":"bookmark"}']]);
        $client = $this->client($http);
        $result = $this->send(
            $client,
            new Operation(
                method: 'POST',
                path: '/api/v1/pa/payment_intents/create',
                family: 'payments',
                requestDecimalPaths: ['/amount'],
                responseDecimalPaths: ['/amount'],
                responseIntegerPaths: ['/quantity']
            ),
            ['request_id' => 'durable-id',
             'amount' => '19.90',
            'metadata' => ['number_like' => '19.90']]
        );
        self::assertSame(['amount' => '19.90', 'quantity' => 2, 'extra' => '1.2300'], $result);
        self::assertSame(
            '{"request_id":"durable-id","amount":19.90,"metadata":{"number_like":"19.90"}}',
            (string) $http->requests[1]->getBody()
        );
        self::assertSame('application/json', $http->requests[1]->getHeaderLine('Content-Type'));
        $page = $this->send(
            $client,
            new Operation(
                method: 'GET',
                path: '/api/v1/billing/products',
                family: 'billing',
                pagination: 'billing'
            ),
            query: ['page_size' => 20,
             'page' => 'a+b /',
            'active' => false]
        );
        self::assertSame(['items' => [], 'page_after' => null, 'page_before' => 'bookmark'], $page);
        self::assertStringEndsWith(
            '?page_size=20&page=a%2Bb%20%2F&active=false',
            (string) $http->requests[2]->getUri()
        );
        self::assertSame('', (string) $http->requests[2]->getBody());
    }

    public function testTokenReuseAndReturnedExpiration(): void
    {
        $now = 100;
        $http = $this->scenario([[201,
             '{"token":"first","expires_at":"1970-01-01T00:01:41Z"}'],
             [200,
             '{}'],
             [200,
             '{}'],
             [201,
             self::LOGIN],
             [200,
             '{}']]);
        $client = $this->client($http, clock: static function () use (&$now): int {
            return $now;
        });
        $this->send($client);
        $this->send($client);
        $now = 101;
        $this->send($client);
        self::assertCount(5, $http->requests);
        self::assertSame('Bearer first', $http->requests[1]->getHeaderLine('Authorization'));
        self::assertSame('Bearer first', $http->requests[2]->getHeaderLine('Authorization'));
        self::assertSame('Bearer test-token-only', $http->requests[4]->getHeaderLine('Authorization'));
    }

    public function testReadRetriesAreBoundedWithDeterministicJitter(): void
    {
        $delays = [];
        $http = $this->scenario([[201, self::LOGIN], [429, '{}'], [503, '{}'], [200, '{"ok":true}']]);
        $policy = new RetryPolicy(sleeper: static function (int $delay) use (&$delays): void {
            $delays[] = $delay;
        }, randomInt: static fn (int $min, int $max): int => $max);
        $result = $this->send($this->client($http, retryPolicy: $policy));
        self::assertSame(['ok' => true], $result);
        self::assertSame([1000, 2000], $delays);
        self::assertSame((string) $http->requests[1]->getUri(), (string) $http->requests[3]->getUri());
        self::assertSame(
            $http->requests[1]->getHeaderLine('x-on-behalf-of'),
            $http->requests[3]->getHeaderLine('x-on-behalf-of')
        );
    }

    public function testOnlyOneReadReauthenticationAndNoLoop(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [401, '{}'], [201, self::LOGIN], [401, '{}']]);
        $this->expectException(ApiException::class);
        try {
            $this->send($this->client($http, retryPolicy: new RetryPolicy()));
        } finally {
            self::assertCount(4, $http->requests);
        }
    }

    #[DataProvider('mutationFailures')]
    public function testMutationsNeverReplayAndPreserveTheirIdentity(int $status): void
    {
        $http = $this->scenario([[201,
             self::LOGIN],
             [$status,
             '{"code":"internal_error","message":"customer-secret"}']]);
        $this->expectException(ApiException::class);
        try {
            $this->send(
                $this->client($http),
                new Operation(
                    method: 'POST',
                    path: '/api/v1/pa/payment_intents/create',
                    family: 'payments'
                ),
                ['request_id' => 'original-stable-id']
            );
        } finally {
            self::assertCount(2, $http->requests);
            self::assertSame('{"request_id":"original-stable-id"}', (string) $http->requests[1]->getBody());
        }
    }

    /** @return iterable<string, array{int}> */
    public static function mutationFailures(): iterable
    {
        foreach ([401, 429, 500, 502, 503, 504] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('apiFailures')]
    public function testSafeApiFailuresRetainStatusWithoutMessagesOrBodies(
        int $status,
        string $body,
        ?string $code,
        ?string $source
    ): void {
        $http = $this->scenario([[201, self::LOGIN], [$status, $body]]);
        try {
            $this->send($this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 1)));
            self::fail('Expected API failure.');
        } catch (ApiException $error) {
            self::assertSame($status, $error->httpStatus());
            self::assertSame($code, $error->providerCode());
            self::assertSame($source, $error->providerSource());
            self::assertStringNotContainsString('SENSITIVE', (string) $error);
            self::assertStringNotContainsString('SENSITIVE', serialize($error));
            self::assertNull($error->getPrevious());
            $restored = unserialize(serialize($error), ['allowed_classes' => [ApiException::class]]);
            self::assertInstanceOf(ApiException::class, $restored);
            self::assertSame($status, $restored->httpStatus());
            self::assertSame($code, $restored->providerCode());
            self::assertSame($source, $restored->providerSource());
        }
    }

    /** @return iterable<string, array{int, string, ?string, ?string}> */
    public static function apiFailures(): iterable
    {
        foreach ([400, 401, 403, 404, 409, 429, 500] as $status) {
            yield 'status-' . $status => [$status,
                 '{"code":"validation_error","source":"amount","message":"SENSITIVE","details":{"key":"SENSITIVE"}}',
                 'validation_error',
                 'amount'];
        }
        yield 'html' => [502, '<html>SENSITIVE</html>', null, null];
        yield 'empty' => [503, '', null, null];
        yield 'malformed' => [400, '{SENSITIVE', null, null];
        yield 'array' => [400, '[]', null, null];
        yield 'unsafe-values' => [400, '{"code":"SENSITIVE","source":"buyer@example.test"}', null, null];
        yield 'dynamic-metadata-source' => [
            400,
            '{"code":"validation_error","source":"metadata.customer_secret_name"}',
            'validation_error',
            null,
        ];
        yield 'unknown-root-source' => [
            400, '{"code":"validation_error","source":"customer_secret_name"}', 'validation_error', null,
        ];
        yield 'credential-echo' => [400, '{"code":"test_api_key","source":"test_client_id"}', null, null];
    }

    public function testTransportFailuresHaveNoUnsafePreviousExceptionAndMutationsDoNotReplay(): void
    {
        $failure = new class ('SENSITIVE') extends RuntimeException implements ClientExceptionInterface {
        };
        $http = $this->scenario([[201, self::LOGIN], $failure]);
        try {
            $this->send(
                $this->client($http),
                new Operation(
                    method: 'POST',
                    path: '/api/v1/pa/payment_intents/create',
                    family: 'payments'
                ),
                ['request_id' => 'stable']
            );
            self::fail('Expected transport failure.');
        } catch (TransportException $error) {
            self::assertStringNotContainsString('SENSITIVE', serialize($error));
            self::assertNull($error->getPrevious());
            self::assertCount(2, $http->requests);
        }
    }

    public function testMalformedSuccessIsProtocolFailureWithoutRetry(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '[]']]);
        $this->expectException(ProtocolException::class);
        try {
            $this->send($this->client($http));
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    #[DataProvider('invalidLogins')]
    public function testMalformedExpiredOrInvalidLoginNeverSendsResourceRequest(string $body): void
    {
        $http = $this->scenario([[201, $body]]);
        $this->expectException(ProtocolException::class);
        try {
            $this->send($this->client($http));
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLogins(): iterable
    {
        foreach (
            ['{}',
             '[]',
             '{"token":"x","expires_at":"yesterday"}',
             '{"token":"x","expires_at":"2030-01-01T00:00:00+24:00"}',
             '{"token":"x","expires_at":"2030-01-01T00:00:00+00:60"}',
             '{"token":"x","expires_at":"2026-02-30T00:00:00Z"}',
             '{"token":"x","expires_at":"1970-01-01T00:00:00Z"}',
             '{"token":"bad\r\nheader","expires_at":"2030-01-01T00:00:00Z"}'] as $index => $body
        ) {
            yield 'invalid-' . $index => [$body];
        }
    }

    public function testCacheReusesOnlyMatchingContextAndDiscardsMalformedEntries(): void
    {
        /** @var array<string, mixed> $entries */
        $entries = [];
        $keys = [];
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(static function (string $key) use (&$entries, &$keys): mixed {
            $keys[] = $key;
            return $entries[$key] ?? ['token' => 'poison', 'expires_at' => 'tomorrow'];
        });
        $cache->method('set')->willReturnCallback(static function (
            string $key,
            mixed $value,
            mixed $ttl
        ) use (&$entries): bool {
            self::assertIsInt($ttl);
            $entries[$key] = $value;
            return true;
        });
        $http = $this->scenario([[201,
             self::LOGIN],
             [200,
             '{}'],
             [200,
             '{}'],
             [201,
             self::LOGIN],
             [200,
             '{}'],
             [201,
             self::LOGIN],
             [200,
             '{}']]);
        $this->send($this->client($http, cache: $cache));
        $this->send($this->client($http, cache: $cache));
        $this->send($this->client($http, cache: $cache)->forConnectedAccount('acct_A'));
        $this->send($this->client($http, Environment::Production, cache: $cache));
        self::assertCount(4, $keys);
        self::assertSame(array_slice($keys, 0, 1), array_slice($keys, 1, 1));
        self::assertCount(3, array_unique($keys));
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
            self::assertStringNotContainsString('test_api_key', $key);
        }
    }

    public function testInvalidPagingIsRejectedBeforeAuthentication(): void
    {
        $http = $this->scenario([]);
        $this->expectException(ValidationException::class);
        try {
            $this->send(
                $this->client($http),
                new Operation(
                    method: 'GET',
                    path: '/api/v1/pa/payment_intents',
                    family: 'payments',
                    pagination: 'payments'
                ),
                query: ['page_num' => -1]
            );
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function testCredentialsAndClientsRedactDebugOutputAndBlockSerialization(): void
    {
        $credentials = new Credentials('test_client_id', 'test_api_key');
        ob_start();
        var_dump($credentials, $this->client($this->scenario([])));
        $output = ob_get_clean();
        self::assertIsString($output);
        self::assertStringNotContainsString('test_api_key', $output);
        self::assertStringNotContainsString('test_client_id', $output);
        $this->expectException(ConfigurationException::class);
        serialize($credentials);
    }

    public function testResourceAndTransportDebuggingHidesConsumerClientHistory(): void
    {
        $http = $this->scenario([]);
        $historyClient = new class implements ClientInterface {
            public string $history = 'SENSITIVE-client-history-with-token';

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('No network operations expected.');
            }
        };
        $client = new Client(
            Environment::Sandbox,
            new Credentials('test_client_id', 'test_api_key'),
            $historyClient,
            $http->requestFactory,
            $http->streamFactory,
        );
        $exchange = new HttpExchange(
            Environment::Sandbox,
            $historyClient,
            $http->requestFactory,
            $http->streamFactory,
        );
        $billing = $client->billing();
        $payments = $client->payments();
        ob_start();
        var_dump($billing, $payments, $exchange);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('SENSITIVE', $debug);
        self::assertStringNotContainsString('test_api_key', $debug);
        foreach ([$billing, $payments, $exchange] as $configured) {
            try {
                serialize($configured);
                self::fail('Configured transport state was serialized.');
            } catch (ConfigurationException $error) {
                self::assertStringNotContainsString('SENSITIVE', serialize($error));
            }
        }
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidQueries')]
    public function testInvalidQueryArgumentsFailBeforeLogin(array $query, string $pagination): void
    {
        $http = $this->scenario([]);
        $this->expectException(ValidationException::class);
        try {
            $this->send(
                $this->client($http),
                new Operation(
                    method: 'GET',
                    path: '/api/v1/pa/payment_intents',
                    family: 'payments',
                    pagination: $pagination,
                ),
                query: $query,
            );
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidQueries(): iterable
    {
        yield 'zero-size' => [['page_size' => 0], 'payments'];
        yield 'oversized' => [['page_size' => 1001], 'billing'];
        yield 'string-size' => [['page_size' => '20'], 'billing'];
        yield 'null-size' => [['page_size' => null], 'billing'];
        yield 'float-size' => [['page_size' => 20.0], 'payments'];
        yield 'string-page-num' => [['page_num' => '0'], 'payments'];
        yield 'billing-page-num' => [['page_num' => 0], 'billing'];
        yield 'payments-bookmark' => [['page' => 'bookmark'], 'payments'];
        yield 'non-string-bookmark' => [['page' => 1], 'billing'];
        yield 'nested-query' => [['status' => ['unexpected']], 'payments'];
    }

    #[DataProvider('nonretryableFailures')]
    public function testNonretryableApiAndLoginFailuresDoNotLoop(int $status): void
    {
        $http = $this->scenario([[201, self::LOGIN], [$status, '{}']]);
        try {
            $this->send($this->client($http, retryPolicy: new RetryPolicy()));
            self::fail('Expected API failure.');
        } catch (ApiException $error) {
            self::assertSame($status, $error->httpStatus());
            self::assertCount(2, $http->requests);
        }
        $login = $this->scenario([[$status, '{}']]);
        try {
            $this->send($this->client($login, retryPolicy: new RetryPolicy()));
            self::fail('Expected login failure.');
        } catch (ApiException $error) {
            self::assertSame($status, $error->httpStatus());
            self::assertCount(1, $login->requests);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function nonretryableFailures(): iterable
    {
        foreach ([400, 403, 404, 409, 422] as $status) {
            yield 'http-' . $status => [$status];
        }
    }

    public function testCredentialAndAccountHeaderInjectionFailsLocally(): void
    {
        foreach ([['', 'key'], ['id', ''], ['id', "key\r\nsecret"], ['white space', 'key']] as [$id, $key]) {
            try {
                new Credentials($id, $key);
                self::fail('Expected credential configuration failure.');
            } catch (ConfigurationException $error) {
                self::assertStringNotContainsString('secret', serialize($error));
            }
        }
        $http = $this->scenario([]);
        $this->expectException(ConfigurationException::class);
        $this->client($http)->forConnectedAccount("acct_A\r\nx-api-key: secret");
    }

    public function testMutationRejectionInvalidatesTokenForNextDeliberateOperation(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [401, '{}'], [201, self::LOGIN], [200, '{}']]);
        $client = $this->client($http, retryPolicy: new RetryPolicy());
        try {
            $this->send(
                $client,
                new Operation(method: 'POST', path: '/api/v1/pa/payment_intents/create', family: 'payments'),
                ['request_id' => 'original-id'],
            );
            self::fail('Expected rejected mutation.');
        } catch (ApiException $error) {
            self::assertSame(401, $error->httpStatus());
            self::assertCount(2, $http->requests);
        }
        $this->send($client);
        self::assertCount(4, $http->requests);
        self::assertSame('POST', $http->requests[2]->getMethod());
        self::assertSame('GET', $http->requests[3]->getMethod());
    }

    public function testReadTransportRetriesStopAtAttemptLimit(): void
    {
        $failure = new class ('SENSITIVE transport') extends RuntimeException implements ClientExceptionInterface {
        };
        $http = $this->scenario([[201, self::LOGIN], $failure, $failure, $failure]);
        $delays = [];
        $policy = new RetryPolicy(
            maxAttempts: 3,
            maxDelayMilliseconds: 1000,
            sleeper: static function (int $delay) use (&$delays): void {
                $delays[] = $delay;
            },
            randomInt: static fn (int $min, int $max): int => $max,
        );
        try {
            $this->send($this->client($http, retryPolicy: $policy));
            self::fail('Expected exhausted transport failure.');
        } catch (TransportException $error) {
            self::assertCount(4, $http->requests);
            self::assertSame([1000, 1000], $delays);
            self::assertStringNotContainsString('SENSITIVE', serialize($error));
        }
    }

    public function testInvalidCachedTimezoneOffsetIsDiscarded(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn(['token' => 'poison', 'expires_at' => '2030-01-01T00:00:00+24:00']);
        $cache->method('set')->willReturn(true);
        $http = $this->scenario([[201, self::LOGIN], [200, '{}']]);
        $this->send($this->client($http, cache: $cache));
        self::assertCount(2, $http->requests);
        self::assertSame('Bearer test-token-only', $http->requests[1]->getHeaderLine('Authorization'));
    }

    public function testDocumentedFailureClassificationExecutesAgainstActualExceptionTypes(): void
    {
        $classify = require dirname(__DIR__, 4) . '/examples/classify-failure.php';
        self::assertInstanceOf(\Closure::class, $classify);
        foreach (
            [
            [new ValidationException('Invalid local arguments.'), 'fix_local_arguments'],
            [new ConfigurationException('Invalid configuration.'), 'fix_configuration'],
            [new TransportException('No usable response.'), 'reconcile_ambiguous_outcome'],
            [new ApiException(400, 'validation_error', 'amount'), 'fix_provider_request'],
            [new ApiException(403), 'check_permissions'],
            [new ApiException(429), 'back_off_before_next_safe_operation'],
            [new ApiException(401), 'check_credentials'],
            [new ApiException(500, 'internal_error'), 'inspect_safe_http_status_and_code'],
            [new ProtocolException('Malformed response.'), 'inspect_protocol_failure'],
            ] as [$error, $expected]
        ) {
            self::assertSame($expected, $classify($error));
        }
    }

    public function testCredentialRotationAndLoginSelectionsHaveDistinctCacheNamespaces(): void
    {
        $keys = [];
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(static function (string $key) use (&$keys): mixed {
            $keys[] = $key;
            return null;
        });
        $http = $this->scenario(array_merge(...array_fill(0, 4, [[201, self::LOGIN], [200, '{}']])));
        $cache->method('set')->willReturn(true);
        $this->send($this->client($http, cache: $cache));
        $this->send($this->client($http, loginAccountId: 'acct_A', cache: $cache));
        $this->send($this->client($http, loginAccountId: 'acct_B', cache: $cache));
        $this->send($this->client(
            $http,
            cache: $cache,
            credentials: new Credentials('test_client_id', 'rotated_key'),
        ));
        self::assertCount(4, array_unique($keys));
    }

    public function testFailedCacheDeletionAbortsWithoutReloadingRejectedToken(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn(['token' => 'rejected-cached-token', 'expires_at' => '2030-01-01T00:00:00Z']);
        $cache->method('delete')->willReturn(false);
        $http = $this->scenario([[401, '{}']]);
        try {
            $this->send($this->client($http, cache: $cache, retryPolicy: new RetryPolicy()));
            self::fail('Failed cache invalidation was ignored.');
        } catch (ConfigurationException $error) {
            self::assertCount(1, $http->requests);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('rejected-cached-token', serialize($error));
        }
    }

    public function testFailedCacheStorageAbortsBeforeResourceRequest(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->method('set')->willReturn(false);
        $http = $this->scenario([[201, self::LOGIN]]);
        $this->expectException(ConfigurationException::class);
        try {
            $this->send($this->client($http, cache: $cache));
        } finally {
            self::assertCount(1, $http->requests);
            self::assertStringEndsWith('/authentication/login', (string) $http->requests[0]->getUri());
        }
    }

    public function testCacheAndClockFailuresProduceOnlySafeDefaultDiagnostics(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willThrowException(new RuntimeException('SENSITIVE cache state'));
        $http = $this->scenario([]);
        foreach (
            [
            $this->client($http, cache: $cache),
            $this->client($http, clock: static fn (): never => throw new RuntimeException('SENSITIVE clock state')),
            ] as $client
        ) {
            try {
                $this->send($client);
                self::fail('Expected safe configuration failure.');
            } catch (ConfigurationException $error) {
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('SENSITIVE', (string) $error);
                self::assertStringNotContainsString('SENSITIVE', serialize($error));
            }
        }
        self::assertSame([], $http->requests);
    }

    public function testRetryCallbacksAreBoundedAndTheirFailuresAreRedacted(): void
    {
        foreach (
            [
            new RetryPolicy(randomInt: static fn (): int => -1),
            new RetryPolicy(randomInt: static fn (): never => throw new RuntimeException('SENSITIVE random state')),
            new RetryPolicy(sleeper: static fn (): never => throw new RuntimeException('SENSITIVE sleeper state')),
            ] as $policy
        ) {
            $http = $this->scenario([[201, self::LOGIN], [429, '{}']]);
            try {
                $this->send($this->client($http, retryPolicy: $policy));
                self::fail('Expected retry configuration failure.');
            } catch (ConfigurationException $error) {
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('SENSITIVE', (string) $error);
                self::assertStringNotContainsString('SENSITIVE', serialize($error));
                self::assertCount(2, $http->requests);
            }
        }
    }

    public function testPaymentsPagingRetainsZeroFiltersAndNativeTerminalState(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [200, '{"items":[{"id":"one"}],"has_more":true}'],
            [200, '{"items":[],"has_more":false}'],
        ]);
        $client = $this->client($http)->forConnectedAccount('acct_A');
        $operation = new Operation(
            method: 'GET',
            path: '/api/v1/pa/payment_intents',
            family: 'payments',
            pagination: 'payments',
        );
        self::assertTrue($this->send($client, $operation, query: [
            'page_num' => 0, 'page_size' => 10, 'merchant_order_id' => 'order zero',
        ])['has_more']);
        self::assertSame(['items' => [], 'has_more' => false], $this->send($client, $operation, query: [
            'page_num' => 1, 'page_size' => 10, 'merchant_order_id' => 'order zero',
        ]));
        self::assertStringEndsWith(
            '?page_num=0&page_size=10&merchant_order_id=order%20zero',
            (string) $http->requests[1]->getUri(),
        );
        self::assertStringEndsWith(
            '?page_num=1&page_size=10&merchant_order_id=order%20zero',
            (string) $http->requests[2]->getUri(),
        );
        self::assertSame('acct_A', $http->requests[2]->getHeaderLine('x-on-behalf-of'));
    }

    public function testAnExplicitEmptyObjectBodyDiffersFromBodylessAction(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{}'], [200, '{}']]);
        $client = $this->client($http);
        $operation = new Operation(
            method: 'POST',
            path: '/api/v1/pa/payment_consents/example/disable',
            family: 'payments',
        );
        $this->send($client, $operation, []);
        $this->send($client, $operation);
        self::assertSame('{}', (string) $http->requests[1]->getBody());
        self::assertSame('application/json', $http->requests[1]->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $http->requests[2]->getBody());
        self::assertSame('', $http->requests[2]->getHeaderLine('Content-Type'));
    }

    /**
     * @param array<array-key, mixed>|null $body
     * @param array<array-key, mixed> $query
     * @return array<array-key, mixed>
     */
    private function send(Client $client, ?Operation $operation = null, ?array $body = null, array $query = []): array
    {
        $property = new ReflectionProperty(Client::class, 'transport');
        $transport = $property->getValue($client);
        self::assertInstanceOf(ApiTransport::class, $transport);
        return $transport->request(
            $operation ?? new Operation(
                method: 'GET',
                path: '/api/v1/pa/payment_intents/test',
                family: 'payments'
            ),
            $body,
            $query
        );
    }
}
