<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SubscriptionWebhookTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;
use RuntimeException;
use Throwable;

final class SubscriptionWebhookTransportTest extends TestCase
{
    private const string HOST = 'https://api.sandbox.airwallex.com';

    /** @return array<string,string> */
    private static function headers(): array
    {
        return ['Accept' => 'application/json', 'Authorization' => 'Bearer SYNTHETIC_PRIVATE_TOKEN',
            'x-api-version' => '2026-08-21', 'Content-Type' => 'application/json'];
    }

    private static function read(): RequestInterface
    {
        return new Request('GET', self::HOST . '/api/v1/billing/billing_customers/own', self::headers());
    }

    private static function productBody(): string
    {
        $marker = 'sdk-validation-11111111-1111-4111-8111-111111111111';
        return json_encode(['name' => $marker, 'active' => true, 'metadata' => ['sdk_validation_run' => $marker],
            'request_id' => '11111111-1111-4111-8111-111111111111'], JSON_THROW_ON_ERROR);
    }

    public function testNativeClientHasTlsStreamingAndNoCookieRedirectRetryGrant(): void
    {
        $transport = new SubscriptionWebhookTransport();
        $http = (new ReflectionProperty($transport, 'http'))->getValue($transport);
        self::assertInstanceOf(Client::class, $http);
        self::assertInstanceOf(StreamHandler::class, $http->getConfig('handler'));
        foreach (
            ['verify' => true, 'stream' => true, 'cookies' => false, 'allow_redirects' => false,
            'http_errors' => false, 'timeout' => 30, 'read_timeout' => 5, 'connect_timeout' => 10] as $name => $value
        ) {
            self::assertSame($value, $http->getConfig($name));
        }
    }

    /** @return iterable<string,array{string,string,array<string,string>}> */
    public static function forbidden(): iterable
    {
        foreach (
            ['/api/v1/billing/subscriptions', '/api/v1/billing/invoices/own', '/api/v1/pa/payment_intents/create',
            '/api/v1/billing/subscriptions/foreign', '/api/v1/billing/subscriptions/own/update',
            '/api/v1/billing/subscriptions/own/start', '/api/v1/billing/payment_sources/own'] as $path
        ) {
            yield $path => ['GET', self::HOST . $path, []];
        }
        yield 'production' => ['GET', 'https://api.airwallex.com/api/v1/billing/billing_customers/own', []];
        yield 'query' => ['GET', self::HOST . '/api/v1/billing/billing_customers/own?page_size=20', []];
        yield 'fragment' => ['GET', self::HOST . '/api/v1/billing/billing_customers/own#PRIVATE', []];
        yield 'OBO' => ['GET', self::HOST . '/api/v1/billing/billing_customers/own', ['x-on-behalf-of' => 'PRIVATE']];
        yield 'org' => ['GET', self::HOST . '/api/v1/billing/billing_customers/own', ['x-org-id' => 'PRIVATE']];
        yield 'cookie' => ['GET', self::HOST . '/api/v1/billing/billing_customers/own', ['Cookie' => 'PRIVATE']];
        yield 'referrer' => ['GET', self::HOST . '/api/v1/billing/billing_customers/own', ['Referer' => 'PRIVATE']];
        yield 'key' => ['GET', self::HOST . '/api/v1/billing/billing_customers/own', ['x-api-key' => 'PRIVATE']];
    }

    /** @param array<string,string> $headers */
    #[DataProvider('forbidden')]
    public function testUnknownAuthorityRoleOrHeaderCannotReachDelegate(
        string $method,
        string $uri,
        array $headers
    ): void {
        $http = new WebhookConfigFixtureClient([]);
        $transport = new SubscriptionWebhookTransport($http);
        $transport->bind('customer', 'own');
        try {
            $transport->sendRequest(new Request($method, $uri, $headers + self::headers()));
            self::fail('Unsupported Subscription request escaped.');
        } catch (ProtocolException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('PRIVATE', (string) $error);
        }
        self::assertSame([], $http->requests);
    }

    public function testNoReservationNoSendAndFailedReservationCannotReplay(): void
    {
        $http = new WebhookConfigFixtureClient([]);
        $transport = new SubscriptionWebhookTransport($http);
        $request = new Request(
            'POST',
            self::HOST . '/api/v1/billing/products/create',
            self::headers(),
            self::productBody()
        );
        $transport->reserve('create_product', static function (): void {
            throw new RuntimeException('PRIVATE_FSYNC');
        });
        foreach ([1, 2] as $attempt) {
            try {
                $transport->sendRequest($request);
                self::fail('Failed one-use reservation escaped.');
            } catch (Throwable) {
                self::assertSame([], $http->requests);
                self::assertFalse($transport->sent('create_product'));
            }
        }
        $this->expectException(ProtocolException::class);
        $transport->reserve('create_product', static function (): void {
        });
    }

    public function testActualDelegateFailureConsumesTheOnlyWrite(): void
    {
        $http = new WebhookConfigFixtureClient([new RuntimeException('PRIVATE_PROVIDER')]);
        $transport = new SubscriptionWebhookTransport($http);
        $request = new Request(
            'POST',
            self::HOST . '/api/v1/billing/products/create',
            self::headers(),
            self::productBody()
        );
        $transport->reserve('create_product', static function (): void {
        });
        foreach ([1, 2] as $attempt) {
            try {
                $transport->sendRequest($request);
                self::fail('Ambiguous write replayed.');
            } catch (Throwable $error) {
                self::assertStringNotContainsString('PRIVATE', (string) $error);
                self::assertCount(1, $http->requests);
                self::assertTrue($transport->sent('create_product'));
            }
        }
    }

    /** @return iterable<string,array{string}> */
    public static function preparedChanges(): iterable
    {
        foreach (['body', 'cursor', 'method', 'target', 'headers', 'token', 'protocol'] as $field) {
            yield $field => [$field];
        }
    }

    /** Fault injection into an otherwise concrete PSR packet; no repository/runtime source mutation. */
    private static function changePrepared(RequestInterface $request, string $field): void
    {
        self::assertInstanceOf(Request::class, $request);
        if ($field === 'body' || $field === 'cursor') {
            $body = $request->getBody();
            if ($field === 'cursor') {
                $body->seek(1);
            } else {
                $raw = self::productBody();
                $raw = str_replace(
                    '11111111-1111-4111-8111-111111111111',
                    '22222222-2222-4222-8222-222222222222',
                    $raw
                );
                $body->rewind();
                $body->write($raw);
                $body->rewind();
            }
            return;
        }
        $property = match ($field) {
            'method' => 'method', 'target' => 'uri', 'protocol' => 'protocol', default => 'headers',
        };
        $value = match ($field) {
            'method' => 'DELETE', 'target' => Utils::uriFor(self::HOST . '/api/v1/billing/products/foreign/update'),
            'protocol' => '2.0',
            'token' => array_replace($request->getHeaders(), ['Authorization' => ['Bearer CHANGED_PRIVATE_TOKEN']]),
            default => $request->getHeaders() + ['Cookie' => ['CHANGED_PRIVATE_COOKIE']],
        };
        (new ReflectionProperty(Request::class, $property))->setValue($request, $value);
    }

    #[DataProvider('preparedChanges')]
    public function testCallerMutationDuringReservationCannotChangeDurableOrDelegatedPacket(string $field): void
    {
        $http = new WebhookConfigFixtureClient([new Response(200, [], '{"id":"own"}')]);
        $transport = new SubscriptionWebhookTransport($http);
        $original = new Request(
            'POST',
            self::HOST . '/api/v1/billing/products/create',
            self::headers(),
            self::productBody()
        );
        $durable = [];
        $guarded = null;
        $transport->reserve('create_product', static function (RequestInterface $request) use (
            $original,
            $field,
            &$durable,
            &$guarded
        ): void {
            $guarded = $request;
            $durable = ['body' => hash('sha256', (string) $request->getBody()),
                'target' => (string) $request->getUri(), 'headers' => $request->getHeaders(),
                'method' => $request->getMethod(), 'protocol' => $request->getProtocolVersion()];
            $request->getBody()->rewind();
            self::changePrepared($original, $field);
        });
        self::assertSame(200, $transport->sendRequest($original)->getStatusCode());
        self::assertCount(1, $http->requests);
        $actual = $http->requests[0];
        self::assertNotSame($original, $guarded);
        self::assertNotSame($guarded, $actual);
        self::assertSame(0, $actual->getBody()->tell());
        self::assertSame(hash('sha256', self::productBody()), $durable['body']);
        self::assertSame($durable, ['body' => hash('sha256', (string) $actual->getBody()),
            'target' => (string) $actual->getUri(), 'headers' => $actual->getHeaders(),
            'method' => $actual->getMethod(), 'protocol' => $actual->getProtocolVersion()]);
        self::assertTrue($transport->sent('create_product'));
    }

    #[DataProvider('preparedChanges')]
    public function testCallbackPacketMutationIsWithheldAndCannotReplay(string $field): void
    {
        $http = new WebhookConfigFixtureClient([]);
        $transport = new SubscriptionWebhookTransport($http);
        $request = new Request(
            'POST',
            self::HOST . '/api/v1/billing/products/create',
            self::headers(),
            self::productBody()
        );
        $guards = 0;
        $transport->reserve('create_product', static function (RequestInterface $prepared) use (
            $field,
            &$guards
        ): void {
            ++$guards;
            self::changePrepared($prepared, $field);
        });
        foreach ([1, 2] as $attempt) {
            try {
                $transport->sendRequest($request);
                self::fail('A substituted or replayed prepared packet escaped.');
            } catch (Throwable $error) {
                self::assertInstanceOf(ProtocolException::class, $error);
                self::assertStringNotContainsString('CHANGED_PRIVATE', (string) $error);
                self::assertSame([], $http->requests);
                self::assertFalse($transport->sent('create_product'));
            }
        }
        self::assertSame(1, $guards);
        $this->expectException(ProtocolException::class);
        $transport->reserve('create_product', static function (): void {
        });
    }

    public function testPreparationCannotNormalizeAnOriginallyRejectedAuthorityIntoAdmission(): void
    {
        $uri = new class (self::HOST . '/api/v1/billing/products/create') extends \GuzzleHttp\Psr7\Uri {
            public function getHost(): string
            {
                return 'API.SANDBOX.AIRWALLEX.COM';
            }
        };
        $request = new class (
            'POST',
            self::HOST . '/api/v1/billing/products/create',
            self::headers(),
            self::productBody()
) extends Request {
            public \Psr\Http\Message\UriInterface $observedUri;

            public function getUri(): \Psr\Http\Message\UriInterface
            {
                return $this->observedUri;
            }
        };
        $request->observedUri = $uri;
        $http = new WebhookConfigFixtureClient([new Response(200, [], '{"id":"own"}')]);
        $transport = new SubscriptionWebhookTransport($http);
        $guards = 0;
        $transport->reserve('create_product', static function () use (&$guards): void {
            ++$guards;
        });
        try {
            $transport->sendRequest($request);
            self::fail('Preparation normalized away an original rejection.');
        } catch (Throwable $error) {
            self::assertInstanceOf(ProtocolException::class, $error);
            self::assertSame(0, $guards);
            self::assertSame([], $http->requests);
        }
    }

    public function testBodyChangeBetweenOriginalValidationAndCopyCannotChangeTheOriginalUuid(): void
    {
        $handle = fopen('php://temp', 'w+b');
        self::assertIsResource($handle);
        fwrite($handle, self::productBody());
        rewind($handle);
        $stream = new class ($handle) extends \GuzzleHttp\Psr7\Stream {
            public bool $changed = false;

            public function rewind(): void
            {
                parent::rewind();
                if (!$this->changed) {
                    $this->changed = true;
                    $raw = parent::getContents();
                    parent::rewind();
                    parent::write(str_replace(
                        '11111111-1111-4111-8111-111111111111',
                        '22222222-2222-4222-8222-222222222222',
                        $raw
                    ));
                    parent::rewind();
                }
            }
        };
        $request = new Request('POST', self::HOST . '/api/v1/billing/products/create', self::headers(), $stream);
        $http = new WebhookConfigFixtureClient([new Response(200, [], '{"id":"own"}')]);
        $transport = new SubscriptionWebhookTransport($http);
        $guards = 0;
        $transport->reserve('create_product', static function () use (&$guards): void {
            ++$guards;
        });
        try {
            $transport->sendRequest($request);
            self::fail('A different UUID replaced the originally checked body.');
        } catch (Throwable $error) {
            self::assertInstanceOf(ProtocolException::class, $error);
            self::assertTrue($stream->changed);
            self::assertSame(0, $guards);
            self::assertSame([], $http->requests);
            self::assertFalse($transport->sent('create_product'));
        }
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function streams(): iterable
    {
        yield 'success overflow' => ['normal', 200, str_repeat('x', 65537)];
        yield 'error overflow' => ['normal', 400, str_repeat('x', 65537)];
        yield 'no progress' => ['stalled', 200, 'PRIVATE'];
        yield 'read failure' => ['read-failure', 200, 'PRIVATE'];
        yield 'close failure' => ['close-failure', 200, '{"id":"own"}'];
        yield 'duplicate' => ['normal', 200, '{"id":"own","i\\u0064":"own"}'];
        yield 'numeric ID' => ['normal', 200, '{"id":123}'];
        yield 'invalid UTF8' => ['normal', 200, "{\"id\":\"\xff\"}"];
    }

    #[DataProvider('streams')]
    public function testBoundedStreamingProofFailureClosesAndRedacts(string $mode, int $status, string $body): void
    {
        $stream = new WebhookConfigFixtureStream($body, $mode);
        $http = new WebhookConfigFixtureClient([new Response($status, [], $stream)]);
        $transport = new SubscriptionWebhookTransport($http);
        $transport->bind('customer', 'own');
        try {
            $transport->sendRequest(self::read());
            self::fail('Invalid proof was released.');
        } catch (Throwable $error) {
            // A close exception is not exported by the controller; direct stream exceptions are not proof.
            self::assertTrue($stream->closed);
            self::assertLessThanOrEqual(65537, $stream->bytesRead);
        }
    }

    /** @return iterable<string,array{int}> */
    public static function headerBoundary(): iterable
    {
        yield 'exact' => [16384];
        yield 'plus one' => [16385];
    }

    #[DataProvider('headerBoundary')]
    public function testRepresentedHeaderBudgetIncludesSeparatorsAndFinalCrlf(int $total): void
    {
        $stream = new WebhookConfigFixtureStream('{"id":"own"}');
        $response = new Response(200, ['X' => str_repeat('v', $total - 7)], $stream);
        $transport = new SubscriptionWebhookTransport(new WebhookConfigFixtureClient([$response]));
        $transport->bind('customer', 'own');
        if ($total === 16384) {
            self::assertSame(200, $transport->sendRequest(self::read())->getStatusCode());
            self::assertGreaterThan(0, $stream->bytesRead);
        } else {
            try {
                $transport->sendRequest(self::read());
                self::fail('Header plus one escaped.');
            } catch (ProtocolException) {
                self::assertSame(0, $stream->bytesRead);
            }
        }
        self::assertTrue($stream->closed);
    }

    public function testDelayDuringReservationDoesNotResetTheRequestReserve(): void
    {
        $now = 0.0;
        $http = new WebhookConfigFixtureClient([]);
        $transport = new SubscriptionWebhookTransport($http, static function () use (&$now): float {
            return $now;
        }, 300);
        $transport->reserve('archive_product', static function () use (&$now): void {
            $now = 256;
        });
        $transport->bind('product', 'own');
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/billing/products/own/update',
                self::headers(),
                '{"active":false,"request_id":"11111111-1111-4111-8111-111111111111"}'
            ));
            self::fail('Durability elapsed time reset the bound.');
        } catch (ProtocolException) {
            self::assertSame([], $http->requests);
        }
    }

    public function testNoLaterAuthRecoveryAfterUnauthorizedResponse(): void
    {
        $http = new WebhookConfigFixtureClient([new Response(401, [], '{"code":"unauthorized"}')]);
        $transport = new SubscriptionWebhookTransport($http);
        $transport->bind('customer', 'own');
        self::assertSame(401, $transport->sendRequest(self::read())->getStatusCode());
        $this->expectException(ProtocolException::class);
        try {
            $transport->sendRequest(self::read());
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    public function testSuccessfulResponseWithQuotedNumericIdentityStaysAString(): void
    {
        $transport = new SubscriptionWebhookTransport(new WebhookConfigFixtureClient([new Response(
            200,
            [],
            '{"id":"123"}'
        )]));
        $transport->bind('customer', '123');
        $request = self::read()->withUri(Utils::uriFor(self::HOST . '/api/v1/billing/billing_customers/123'));
        self::assertSame('{"id":"123"}', (string) $transport->sendRequest($request)->getBody());
    }
    public function testAnItemMustComeFromTheCurrentNativeParentPageAndCannotBeAdopted(): void
    {
        $http = new WebhookConfigFixtureClient([new Response(
            200,
            [],
            '{"items":[{"id":"item2","subscription_id":"sub","quantity":1}]}'
        )]);
        $t = new SubscriptionWebhookTransport($http);
        $t->bind('subscription', 'sub');
        try {
            $t->bindItem('external');
            self::fail('External Item adopted.');
        } catch (ProtocolException) {
            self::assertSame([], $http->requests);
        }
        $t->sendRequest(new Request(
            'GET',
            self::HOST . '/api/v1/billing/subscriptions/sub/items?page_size=20',
            self::headers()
        ));
        try {
            $t->bindItem('item1');
            self::fail('Wrong page Item adopted.');
        } catch (ProtocolException) {
            self::assertCount(1, $http->requests);
        }
        $t->bindItem('item2');
        $this->expectException(ProtocolException::class);
        $t->bindItem('item2');
    }

    public function testPerRoleReadCapsCannotBeSpentOnAnotherTarget(): void
    {
        $responses = array_map(static fn (int $index): Response => new Response(200, [], '{"id":"own"}'), range(1, 30));
        $http = new WebhookConfigFixtureClient($responses);
        $t = new SubscriptionWebhookTransport($http);
        foreach (['customer', 'product', 'price', 'subscription'] as $kind) {
            $t->bind($kind, 'own');
        }
        // Consume the finite read roles without inventing another target.
        foreach (['billing_customers' => 2, 'products' => 3, 'prices' => 3, 'subscriptions' => 7] as $kind => $limit) {
            for ($index = 0; $index < $limit; ++$index) {
                $t->sendRequest(new Request('GET', self::HOST . '/api/v1/billing/' . $kind . '/own', self::headers()));
            }
        }
        self::assertCount(15, $http->requests);
        $this->expectException(ProtocolException::class);
        try {
            $t->sendRequest(self::read());
        } finally {
            self::assertCount(15, $http->requests);
        }
    }

    /** @return iterable<string,array{float,float,float}> */
    public static function closeWindows(): iterable
    {
        yield 'read deadline' => [0.0, 0.0, 11.0];
        yield 'global deadline' => [0.0, 295.0, 301.0];
    }

    #[DataProvider('closeWindows')]
    public function testTheOriginalReadAndCaseBoundsIncludeClose(float $start, float $readStart, float $finish): void
    {
        $now = $start;
        $stream = new SimulationProbeStream('{"id":"own"}', afterClose: static function () use (&$now, $finish): void {
            $now = $finish;
        });
        $http = new WebhookConfigFixtureClient([new Response(200, [], $stream)]);
        $http->intercept = static function () use (&$now, $readStart): void {
            $now = $readStart;
        };
        $t = new SubscriptionWebhookTransport($http, static function () use (&$now): float {
            return $now;
        }, 300);
        $t->bind('customer', 'own');
        $this->expectException(ProtocolException::class);
        try {
            $t->sendRequest(self::read());
        } finally {
            self::assertTrue($stream->closed);
        }
    }

    /** @return iterable<string,array{bool}> */
    public static function nativePackets(): iterable
    {
        yield 'existing read' => [false];
        yield 'prepared producer write' => [true];
    }

    #[DataProvider('nativePackets')]
    public function testNativeLoopbackHttpStreamRemainsASeparateFixtureOnly(bool $mutation): void
    {
        $code = <<<'CHILD'
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) { exit(1); }
        echo stream_socket_get_name($server, false) . "\n"; fflush(STDOUT);
        $client = stream_socket_accept($server, 5);
        if ($client === false) { exit(2); }
        stream_set_timeout($client, 5);
        $headers = '';
        while (!str_ends_with($headers, "\r\n\r\n") && strlen($headers) <= 16384) {
            $part = fread($client, 1);
            if ($part === false || $part === '') { exit(3); }
            $headers .= $part;
        }
        $length = preg_match('/\r\nContent-Length: ([0-9]+)\r\n/i', $headers, $match) === 1 ? (int) $match[1] : 0;
        if ($length > 65536) { exit(4); }
        $received = '';
        while (strlen($received) < $length) {
            $part = fread($client, $length - strlen($received));
            if ($part === false || $part === '') { exit(5); }
            $received .= $part;
        }
        $body = '{"id":"own"}';
        fwrite($client, "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: " . strlen($body)
            . "\r\n\r\n" . $body);
        fclose($client); fclose($server);
        echo json_encode(['cookie' => stripos($headers, "\r\nCookie:") !== false,
            'get' => str_starts_with($headers, 'GET /fixture HTTP/1.1'),
            'post' => str_starts_with($headers, 'POST /fixture HTTP/1.1'),
            'body_sha256' => hash('sha256', $received)]);
        CHILD;
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: ['LANG' => 'C']
        );
        self::assertIsResource($process);
        try {
            [$in, $out, $err] = self::pipes($pipes);
            fclose($in);
            stream_set_timeout($out, 8);
            $address = fgets($out, 128);
            self::assertIsString($address);
            $address = trim($address);
            self::assertMatchesRegularExpression('/^127\.0\.0\.1:[0-9]+$/D', $address);
            $native = new StreamHandler();
            $client = new Client(['stream' => true, 'verify' => true, 'allow_redirects' => false, 'cookies' => false,
                'timeout' => 5,
                    'read_timeout' => 5,
                    'handler' => static function (
                        RequestInterface $request,
                        array $options
                    ) use (
                        $native,
                        $address
): \GuzzleHttp\Promise\PromiseInterface {
                        return $native($request->withUri(Utils::uriFor('http://' . $address . '/fixture')), $options);
                    }]);
            $t = new SubscriptionWebhookTransport($client);
            $request = $mutation ? new Request(
                'POST',
                self::HOST . '/api/v1/billing/products/create',
                self::headers(),
                self::productBody()
            ) : self::read();
            $wireHash = hash('sha256', '');
            if ($mutation) {
                $t->reserve('create_product', static function (RequestInterface $prepared) use (
                    $request,
                    &$wireHash
                ): void {
                    $wireHash = hash('sha256', (string) $prepared->getBody());
                    $prepared->getBody()->rewind();
                    self::changePrepared($request, 'body');
                    self::changePrepared($request, 'cursor');
                });
            } else {
                $t->bind('customer', 'own');
            }
            self::assertSame('{"id":"own"}', (string) $t->sendRequest($request)->getBody());
            $raw = stream_get_contents($out);
            self::assertIsString($raw);
            self::assertSame(['cookie' => false, 'get' => !$mutation, 'post' => $mutation,
                'body_sha256' => $wireHash], json_decode($raw, true, flags: JSON_THROW_ON_ERROR));
            if ($mutation) {
                self::assertSame(hash('sha256', self::productBody()), $wireHash);
            }
            self::assertSame('', stream_get_contents($err));
            fclose($out);
            fclose($err);
            self::assertSame(0, proc_close($process));
        } finally {
            self::closePipes($pipes);
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    /** @return array{resource,resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (
            !is_array($pipes) || !is_resource($pipes[0] ?? null)
            || !is_resource($pipes[1] ?? null) || !is_resource($pipes[2] ?? null)
        ) {
            throw new RuntimeException('The synthetic native process pipes are unavailable.');
        }
        return [$pipes[0], $pipes[1], $pipes[2]];
    }

    private static function closePipes(mixed $pipes): void
    {
        if (is_array($pipes)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }

    public function testNativePipeWitnessValidatesShapeAndClosesFailureHandles(): void
    {
        $in = fopen('php://temp', 'w+b');
        $out = fopen('php://temp', 'w+b');
        $err = fopen('php://temp', 'w+b');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        $handles = [$in, $out, $err];
        try {
            self::assertSame($handles, self::pipes($handles));
            foreach ([null, [], [$in], [$in, $out], [$in, $out, false]] as $invalid) {
                try {
                    self::pipes($invalid);
                    self::fail('Invalid native pipe shape escaped.');
                } catch (RuntimeException $error) {
                    self::assertSame('The synthetic native process pipes are unavailable.', $error->getMessage());
                }
            }
        } finally {
            self::closePipes($handles);
            self::closePipes(null);
        }
        self::assertSame(
            ['resource (closed)', 'resource (closed)', 'resource (closed)'],
            array_map(gettype(...), $handles)
        );
        $this->expectException(RuntimeException::class);
        self::pipes($handles);
    }
    /** @return iterable<string,array{string,mixed}> */
    public static function invalidProductBodies(): iterable
    {
        yield 'inactive' => ['active', false];
        yield 'unmarked' => ['metadata', []];
        yield 'foreign marker' => ['metadata', ['sdk_validation_run' => 'foreign']];
        yield 'other name' => ['name', 'foreign'];
        yield 'noncanonical UUID' => ['request_id', 'not-a-uuid'];
        yield 'extra field' => ['future_parameter', 'PRIVATE'];
    }

    #[DataProvider('invalidProductBodies')]
    public function testAOneUseGuardCannotBroadenTheFixedSyntheticCreateProfile(string $field, mixed $value): void
    {
        $http = new WebhookConfigFixtureClient([]);
        $t = new SubscriptionWebhookTransport($http);
        $body = json_decode(self::productBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $body[$field] = $value;
        $guards = 0;
        $t->reserve('create_product', static function () use (&$guards): void {
            ++$guards;
        });
        $request = new Request(
            'POST',
            self::HOST . '/api/v1/billing/products/create',
            self::headers(),
            json_encode($body, JSON_THROW_ON_ERROR)
        );
        foreach ([1, 2] as $attempt) {
            try {
                $t->sendRequest($request);
                self::fail('A broadened body or replay escaped.');
            } catch (Throwable $error) {
                self::assertInstanceOf(ProtocolException::class, $error);
                self::assertSame([], $http->requests);
            }
        }
        self::assertSame(0, $guards);
    }
}
