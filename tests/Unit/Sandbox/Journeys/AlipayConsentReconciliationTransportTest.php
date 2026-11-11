<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\FulfilledPromise;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliation;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SourceEvidence;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\HistoricalAlipayTestCase;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliationTransport;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliationCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AlipayConsentReconciliationTransportTest extends HistoricalAlipayTestCase
{
    /** @var array{base:string,state:string,credentials:string,prior:string,provider:AlipayConsentReconciliationProvider,
     * marker:string,customer:string,consent:string} */
    private array $fixture;
    private string $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = AlipayConsentReconciliationTest::fixture();
        $configuration = Configuration::load('sandbox', $this->fixture['credentials']);
        $session = new SandboxSession($configuration, 'account', $this->fixture['provider']);
        $this->context = $session->contextFingerprint();
    }

    protected function tearDown(): void
    {
        AlipayConsentReconciliationTest::remove($this->fixture['base']);
    }

    /**
     * @param (Closure():float)|null $clock
     *
     * @param (Closure():void)|null $gate
     *
     * @param (Closure(resource):bool)|null $synchronize
     */
    private function transport(
        ?ClientInterface $client = null,
        ?Closure $clock = null,
        ?Closure $gate = null,
        ?Closure $synchronize = null,
    ): AlipayConsentReconciliationTransport {
        $ledger = RunLedger::create($this->context, $this->fixture['state'], synchronize: $synchronize);
        $transport = new AlipayConsentReconciliationTransport(
            'PRIVATE_ACCOUNT',
            $client ?? $this->fixture['provider'],
            $clock
        );
        $transport->bind(
            $this->fixture['customer'],
            $this->fixture['consent'],
            $ledger,
            $this->context,
            $gate ?? static function (): void {
            }
        );
        return $transport;
    }

    private function request(string $role = 'customer'): RequestInterface
    {
        $headers = ['Accept' => 'application/json', 'x-api-version' => '2026-08-21'];
        if ($role === 'login') {
            return new Request(
                'POST',
                'https://api.sandbox.airwallex.com/api/v1/authentication/login',
                $headers + ['x-client-id' => 'PRIVATE_CLIENT', 'x-api-key' => 'PRIVATE_KEY',
                    'x-login-as' => 'PRIVATE_ACCOUNT']
            );
        }
        $path = $role === 'customer' ? '/api/v1/pa/customers/' . $this->fixture['customer']
            : '/api/v1/pa/payment_consents/' . $this->fixture['consent'];
        return new Request(
            'GET',
            'https://api.sandbox.airwallex.com' . $path,
            $headers + ['Authorization' => 'Bearer PRIVATE']
        );
    }

    public function testOneAttemptPerResourceAndNoSecondLoginUntilCustomerOwnershipProof(): void
    {
        $t = $this->transport();
        $t->sendRequest($this->request('login'));
        $t->sendRequest($this->request());
        $t->customerVerified();
        $t->sendRequest($this->request('login'));
        $t->sendRequest($this->request('consent'));
        self::assertSame(4, $t->report()['http_invocations']);
        self::assertSame(2, $t->report()['login_attempts']);
        try {
            $t->sendRequest($this->request('consent'));
            self::fail('A repeated resource read was admitted.');
        } catch (ProtocolException) {
            self::assertSame(4, $t->report()['http_invocations']);
            self::assertSame(1, $t->report()['consent_get_attempts']);
            self::assertTrue($t->report()['transport_poisoned']);
        }
    }

    /**
     * @return iterable<string,array{string}> */
    public static function deniedRoutes(): iterable
    {
        foreach (
            ['https://api.airwallex.com/api/v1/pa/customers/PRIVATE_RESOURCE_1',
            'https://api.sandbox.airwallex.com:444/api/v1/pa/customers/PRIVATE_RESOURCE_1',
            'https://PRIVATE@api.sandbox.airwallex.com/api/v1/pa/customers/PRIVATE_RESOURCE_1',
            'https://api.sandbox.airwallex.com/api/v1/pa/customers/PRIVATE_RESOURCE_1?x=PRIVATE',
            'https://api.sandbox.airwallex.com/api/v1/pa/customers/PRIVATE_RESOURCE_1#PRIVATE',
            'https://api.sandbox.airwallex.com/api/v1/pa/customers/FOREIGN',
            'https://api.sandbox.airwallex.com/api/v1/pa/payment_consents',
            'https://api.sandbox.airwallex.com/api/v1/pa/payment_intents/PRIVATE',
            'https://api.sandbox.airwallex.com/api/v1/pa/payment_methods/PRIVATE',
            'https://api.sandbox.airwallex.com/api/v1/billing/payment_sources/PRIVATE',
            'https://api.sandbox.airwallex.com/api/v1/simulation/pa/shopper_actions/verify'] as $uri
        ) {
            yield $uri => [$uri];
        }
    }

    #[DataProvider('deniedRoutes')]
    public function testNoOtherOriginTargetQueryListOrFamilyCanBeSelected(string $uri): void
    {
        $t = $this->transport();
        try {
            $t->sendRequest($this->request()->withUri(new Uri($uri)));
            self::fail('A forbidden target was admitted.');
        } catch (ProtocolException $failure) {
            self::assertStringNotContainsString('PRIVATE', $failure->getMessage());
            self::assertSame(0, $t->report()['http_invocations']);
            self::assertSame([], $this->fixture['provider']->requests);
        }
    }

    /**
     * @return iterable<string,array{string,string}> */
    public static function badHeaders(): iterable
    {
        yield 'Host' => ['Host', 'wrong.invalid'];
        yield 'Cookie' => ['Cookie', 'PRIVATE'];
        yield 'Referer' => ['Referer', 'https://PRIVATE'];
        yield 'OBO' => ['x-on-behalf-of', 'PRIVATE'];
        yield 'account on read' => ['x-login-as', 'PRIVATE'];
        yield 'version' => ['x-api-version', '2024-01-01'];
        yield 'accept' => ['Accept', '*/*'];
        yield 'body media' => ['Content-Type', 'application/json'];
        yield 'auth' => ['Authorization', 'Basic PRIVATE'];
        yield 'oversize' => ['Authorization', 'Bearer ' . str_repeat('a', 8192)];
    }

    #[DataProvider('badHeaders')]
    public function testExactHeaderRolesAreIndependentOfResourceOwnership(string $name, string $value): void
    {
        $t = $this->transport();
        $this->expectException(ProtocolException::class);
        try {
            $t->sendRequest($this->request()->withHeader($name, $value));
        } finally {
            self::assertSame(0, $t->report()['http_invocations']);
        }
    }

    /**
     * @return iterable<string,array{string,array<string,list<string>>,bool,int}> */
    public static function responseHeaderBlocks(): iterable
    {
        $cases = [
            'exact block' => [['X-A' => [str_repeat('a', 8192), str_repeat('b', 8176)]], true, 16384],
            'one extra byte' => [['X-A' => [str_repeat('a', 8192), str_repeat('b', 8177)]], false, 16385],
            'repeated empty' => [['X-Flood' => array_fill(0, 5000, '')], false, 55002],
            'repeated multivalue' => [['X-A' => array_fill(0, 60, str_repeat('a', 270))], false, 16622],
            'independent value overflow' => [['X-A' => [str_repeat('a', 8193)]], false, 8202],
            'exact value' => [['X-A' => [str_repeat('a', 8192)]], true, 8201],
            'nonASCII exact bytes' => [['X-A' => [str_repeat('é', 4096), str_repeat('ß', 4088)]], true, 16384],
            'nonASCII one extra byte' => [
                ['X-A' => [str_repeat('é', 4096), str_repeat('ß', 4088) . 'a']], false, 16385,
            ],
        ];
        foreach (['login', 'customer'] as $role) {
            foreach ($cases as $name => [$headers, $accepted, $bytes]) {
                yield $role . '/' . $name => [$role, $headers, $accepted, $bytes];
            }
        }
    }

    /**
     * @param array<string,list<string>> $headers */
    #[DataProvider('responseHeaderBlocks')]
    public function testEveryRepresentedResponseHeaderLineAndValueIsBoundedBeforeBodyOrDelivery(
        string $role,
        array $headers,
        bool $accepted,
        int $expectedBytes,
    ): void {
        $counter = new class {
            public int $reads = 0;
            public int $closes = 0;
        };
        $raw = $role === 'login' ? '{"token":"PRIVATE","expires_at":"2100-01-01T00:00:00Z"}' : '{}';
        $body = self::countedBody($raw, static function () use ($counter): void {
            ++$counter->reads;
        }, static function () use ($counter): void {
            ++$counter->closes;
        });
        $response = new Response($role === 'login' ? 201 : 200, $headers, $body);
        $bytes = 2;
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $bytes += strlen($name) + 2 + strlen($value) + 2;
            }
        }
        self::assertSame($expectedBytes, $bytes);
        $client = new class ($response) implements ClientInterface {
            public int $calls = 0;
            public function __construct(private readonly ResponseInterface $response)
            {
            }
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->calls;
                return $this->response;
            }
        };
        $t = $this->transport($client);
        if ($accepted) {
            $delivered = $t->sendRequest($this->request($role));
            self::assertSame($raw, (string) $delivered->getBody());
            self::assertGreaterThan(0, $counter->reads);
        } else {
            try {
                $t->sendRequest($this->request($role));
                self::fail('An oversized represented response header block or value was delivered.');
            } catch (ProtocolException $failure) {
                self::assertSame(0, $counter->reads);
                self::assertTrue($t->report()['transport_poisoned']);
                self::assertStringNotContainsString('PRIVATE', $failure->getMessage());
            }
            try {
                $t->sendRequest($this->request($role));
                self::fail('An oversized response was retried.');
            } catch (ProtocolException) {
                self::assertSame(1, $client->calls);
            }
        }
        self::assertSame(1, $counter->closes);
        self::assertSame(1, $t->report()['http_invocations']);
    }

    /**
     * @param Closure():void $read
     *
     * @param Closure():void $close
     */
    private static function countedBody(string $raw, Closure $read, Closure $close): StreamInterface
    {
        return new class (Utils::streamFor($raw), $read, $close) implements StreamInterface {
            use StreamDecoratorTrait;

            public function __construct(private StreamInterface $stream, private Closure $read, private Closure $close)
            {
            }
            public function read(int $length): string
            {
                ($this->read)();
                return $this->stream->read($length);
            }
            public function close(): void
            {
                $this->stream->close();
                ($this->close)();
            }
        };
    }

    /**
     * @return iterable<string,array{string,bool}> */
    public static function failedHeaderRoles(): iterable
    {
        foreach (['login', 'customer'] as $role) {
            yield $role => [$role, false];
            yield $role . '/close throws' => [$role, true];
        }
    }

    #[DataProvider('failedHeaderRoles')]
    public function testOversizedAuthOrFirstResourceHeadersCloseAndPreventDependentConsentRead(
        string $role,
        bool $closeThrows,
    ): void {
        $counter = new class {
            public int $reads = 0;
            public int $closes = 0;
        };
        $body = self::countedBody('{"PRIVATE":"not delivered"}', static function () use ($counter): void {
            ++$counter->reads;
        }, static function () use ($counter, $closeThrows): void {
            ++$counter->closes;
            if ($closeThrows) {
                throw new \RuntimeException('PRIVATE_CLOSE');
            }
        });
        $this->fixture['provider']->intercept = static function (RequestInterface $request) use (
            $role,
            $body
        ): ?ResponseInterface {
            $login = str_ends_with($request->getUri()->getPath(), '/login');
            return ($role === 'login') === $login
                ? new Response($login ? 201 : 200, ['X-Flood' => array_fill(0, 5000, '')], $body) : null;
        };
        $before = hash_file('sha256', $this->fixture['prior']);
        $r = AlipayConsentReconciliationCommand::run(
            AlipayConsentReconciliationTest::options($this->fixture),
            $this->fixture['provider']
        );
        self::assertSame('fail', $r['observation_result']);
        self::assertFalse($r['owner_chain_matched']);
        self::assertTrue($r['historical_reconciliation_required']);
        self::assertTrue($r['transport_poisoned']);
        self::assertSame($role === 'login' ? 1 : 2, $r['http_invocations']);
        self::assertSame(0, $r['consent_get_attempts']);
        self::assertSame(0, $r['cleanup_writes_sent']);
        self::assertSame(0, $counter->reads);
        self::assertSame(1, $counter->closes);
        self::assertSame($before, hash_file('sha256', $this->fixture['prior']));
        self::assertStringNotContainsString('PRIVATE', json_encode($r, JSON_THROW_ON_ERROR));
        $paths = array_values(array_filter(
            glob($this->fixture['state'] . '/sdk-validation-*/ledger.json') ?: [],
            fn (string $path): bool => $path !== $this->fixture['prior']
        ));
        self::assertCount(1, $paths);
        $journal = file_get_contents($paths[0]);
        self::assertIsString($journal);
        $record = $role === 'login' ? 'login_1' : $role;
        self::assertStringNotContainsString('consent_readonly_' . $record . '_result', $journal);
        self::assertStringNotContainsString('consent_reconciliation_customer_owned', $journal);
    }

    /**
     * @return iterable<string,array{int,string,array<string,string>}> */
    public static function responseFailures(): iterable
    {
        yield 'redirect' => [302, '{}', ['Location' => 'https://PRIVATE']];
        yield 'duplicate escaped owner' => [200, '{"id":"ok","\\u0069d":"PRIVATE"}', []];
        yield 'nonobject' => [200, '[]', []];
        yield 'invalid UTF8' => [200, "{\"id\":\"\xff\"}", []];
        yield 'numeric identity' => [200, '{"id":123}', []];
        yield 'numeric owner' => [200, '{"customer_id":123}', []];
        yield 'metadata identity' => [200, '{"metadata":{"sdk_validation_run":123}}', []];
        yield 'too large' => [200, str_repeat(' ', 65537) . '{}', []];
        yield 'length mismatch' => [200, '{}', ['Content-Length' => '3']];
        yield 'length over cap' => [200, '{}', ['Content-Length' => '65537']];
        yield 'encoding' => [200, '{}', ['Content-Encoding' => 'gzip']];
        yield 'too deep' => [200, '{"data":' . str_repeat('[', 65) . '0' . str_repeat(']', 65) . '}', []];
        yield 'oversize headers' => [200, '{}', ['X-Fixture' => str_repeat('a', 16385)]];
        yield 'wrong media' => [200, '{}', ['Content-Type' => 'text/html']];
        yield 'server failure' => [503, '{"message":"PRIVATE"}', []];
        yield 'missing auth' => [401, '{"message":"PRIVATE"}', []];
    }

    /**
     * @param array<string,string> $headers */
    #[DataProvider('responseFailures')]
    public function testBoundedResponseFailurePoisonsInsteadOfRetrying(int $status, string $body, array $headers): void
    {
        $client = new class ($status, $body, $headers) implements ClientInterface {
            public int $calls = 0;
            /**
     * @param array<string,string> $headers */
            public function __construct(private int $status, private string $body, private array $headers)
            {
            }
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->calls;
                return new Response($this->status, $this->headers, $this->body);
            }
        };
        $t = $this->transport($client);
        try {
            $t->sendRequest($this->request());
            self::fail('An invalid response was delivered.');
        } catch (ProtocolException $failure) {
            self::assertStringNotContainsString('PRIVATE', $failure->getMessage());
            self::assertSame(1, $t->report()['http_invocations']);
        }
        try {
            $t->sendRequest($this->request());
            self::fail('A failed read was retried.');
        } catch (ProtocolException) {
            self::assertSame(1, $client->calls);
            self::assertTrue($t->report()['transport_poisoned']);
        }
    }

    /**
     * @return iterable<string,array{string}> */
    public static function streamFaults(): iterable
    {
        yield 'no progress' => ['no_progress'];
        yield 'read throws' => ['read'];
        yield 'close throws' => ['close'];
    }

    #[DataProvider('streamFaults')]
    public function testFailedStreamsCloseAndPoisonWithoutRetainingRawError(string $fault): void
    {
        $body = new class (Utils::streamFor('{}'), $fault) implements StreamInterface {
            use StreamDecoratorTrait;

            public int $closes = 0;
            public function __construct(private StreamInterface $stream, private readonly string $fault)
            {
            }
            public function read(int $length): string
            {
                if ($this->fault === 'read') {
                    throw new \RuntimeException('PRIVATE_STREAM');
                }
                return $this->fault === 'no_progress' ? '' : $this->stream->read($length);
            }
            public function close(): void
            {
                ++$this->closes;
                $this->stream->close();
                if ($this->fault === 'close') {
                    throw new \RuntimeException('PRIVATE_CLOSE');
                }
            }
        };
        $client = new class ($body) implements ClientInterface {
            public function __construct(private readonly StreamInterface $body)
            {
            }
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, [], $this->body);
            }
        };
        $t = $this->transport($client);
        try {
            $t->sendRequest($this->request());
            self::fail('A broken stream was delivered.');
        } catch (ProtocolException $failure) {
            self::assertSame(1, $body->closes);
            self::assertSame(1, $t->report()['http_invocations']);
            self::assertTrue($t->report()['transport_poisoned']);
            self::assertStringNotContainsString('PRIVATE', $failure->getMessage());
        }
    }

    /**
     * @return iterable<string,array{int,string}> */
    public static function invalidLogin(): iterable
    {
        yield 'wrong status' => [200, '{"token":"PRIVATE","expires_at":"2099-01-01T00:00:00Z"}'];
        yield 'numeric token' => [201, '{"token":123,"expires_at":"2099-01-01T00:00:00Z"}'];
        yield 'missing expiry' => [201, '{"token":"PRIVATE"}'];
    }

    #[DataProvider('invalidLogin')]
    public function testNativeLoginStatusAndStringFieldsPrecedeAnyOwnedResourceRead(int $status, string $body): void
    {
        $this->fixture['provider']->intercept = static fn (): ResponseInterface => new Response($status, [], $body);
        $t = $this->transport();
        $this->expectException(ProtocolException::class);
        try {
            $t->sendRequest($this->request('login'));
        } finally {
            self::assertSame(0, $t->report()['customer_get_attempts']);
            self::assertSame(1, $t->report()['login_attempts']);
            self::assertTrue($t->report()['transport_poisoned']);
        }
    }

    public function testExactPreparedHeaderBlockCapAndOneByteOverflowAreIndependentOfPerValueCap(): void
    {
        $base = $this->request('login')->withHeader('x-client-id', str_repeat('a', 8192))->withHeader('x-api-key', 'b');
        $bytes = 2;
        foreach ($base->getHeaders() as $name => $values) {
            $bytes += strlen($name) + 2 + strlen($values[0]) + 2;
        }
        $length = 16384 - $bytes + 1;
        self::assertGreaterThan(0, $length);
        self::assertLessThan(8192, $length);
        $exact = $base->withHeader('x-api-key', str_repeat('b', $length));
        $this->transport()->sendRequest($exact);
        self::assertCount(1, $this->fixture['provider']->requests);
        $over = $this->transport();
        try {
            $over->sendRequest($exact->withHeader('x-api-key', str_repeat('b', $length + 1)));
            self::fail('An oversized private prepared header block was admitted.');
        } catch (ProtocolException) {
            self::assertSame(0, $over->report()['http_invocations']);
            self::assertCount(1, $this->fixture['provider']->requests);
        }
    }

    public function testExactBodyLimitIsDeliveredWithFreshCursorAndNoNativeBodyValueInReport(): void
    {
        $body = str_repeat(' ', 65534) . '{}';
        $client = new class ($body) implements ClientInterface {
            public function __construct(private string $body)
            {
            }
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Length' => '65536'], $this->body);
            }
        };
        $t = $this->transport($client);
        $response = $t->sendRequest($this->request());
        self::assertSame(0, $response->getBody()->tell());
        self::assertSame($body, (string) $response->getBody());
        self::assertSame(65536, $t->report()['response_bytes']);
        self::assertArrayNotHasKey('private_json', $t->report());
    }

    /**
     * @return iterable<string,array{float}> */
    public static function lateClose(): iterable
    {
        yield 'original read window' => [11.0];
        yield 'original global window' => [121.0];
    }

    #[DataProvider('lateClose')]
    public function testClosingOriginalReadWindowLateRejectsWithoutResetOrSecondInvocation(float $late): void
    {
        $now = 0.0;
        $body = new class (Utils::streamFor('{}'), static function () use (&$now, $late): void {
            $now = $late;
        }) implements StreamInterface {
            use StreamDecoratorTrait;

            public function __construct(private StreamInterface $stream, private Closure $closed)
            {
            }
            public function close(): void
            {
                $this->stream->close();
                ($this->closed)();
            }
        };
        $client = new class ($body) implements ClientInterface {
            public function __construct(private StreamInterface $body)
            {
            }
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, [], $this->body);
            }
        };
        $t = $this->transport($client, static function () use (&$now): float {
            return $now;
        });
        $this->expectException(ProtocolException::class);
        try {
            $t->sendRequest($this->request());
        } finally {
            self::assertSame(1, $t->report()['http_invocations']);
            self::assertSame($late, $now);
        }
    }

    public function testPrivatePreparedGetSurvivesCallerStreamUriAndHeaderMutationDuringDurability(): void
    {
        $target = $this->request()->getUri();
        $request = new class ('GET', (string) $target, $this->request()->getHeaders()) extends Request {
            public ?UriInterface $target = null;
            /** @var array<string,list<string>>|null */
            public ?array $changedHeaders = null;
            public function getUri(): UriInterface
            {
                return $this->target ?? parent::getUri();
            }
            public function getHeaders(): array
            {
                return $this->changedHeaders ?? parent::getHeaders();
            }
        };
        $state = new class {
            public bool $armed = false;
            public bool $mutated = false;
        };
        $t = $this->transport(synchronize: static function ($stream) use ($request, $state): bool {
            if ($state->armed && !$state->mutated) {
                $state->mutated = true;
                $request->target = new Uri('https://PRIVATE.invalid/forbidden');
                $request->changedHeaders = ['Cookie' => ['PRIVATE']];
                $request->getBody()->write('PRIVATE');
            }
            return fsync($stream);
        });
        $state->armed = true; // Initial journal durability is complete; mutate this exact caller at possible-send.
        $t->sendRequest($request);
        self::assertTrue($state->mutated);
        self::assertSame('https://private.invalid/forbidden', (string) $request->getUri());
        self::assertSame('PRIVATE', (string) $request->getBody());
        self::assertSame(['Cookie' => ['PRIVATE']], $request->getHeaders());
        self::assertCount(1, $this->fixture['provider']->requests);
        $sent = $this->fixture['provider']->requests[0];
        self::assertSame((string) $target, (string) $sent->getUri());
        self::assertSame('', (string) $sent->getBody());
        self::assertFalse($sent->hasHeader('Cookie'));
        self::assertSame('Bearer PRIVATE', $sent->getHeaderLine('Authorization'));
    }

    /**
     * @return iterable<string,array{float}> */
    public static function finalClockCrossing(): iterable
    {
        yield 'exact deadline' => [120.0];
        yield 'past deadline' => [121.0];
        yield 'nonfinite' => [INF];
        yield 'rollback' => [-1.0];
        yield 'near-zero positive duration' => [119.9999999];
        yield 'less than one whole second' => [119.5];
    }

    #[DataProvider('finalClockCrossing')]
    public function testSameSampleRemainingDeadlineIsCheckedAfterDurabilityBeforeInvocation(float $expired): void
    {
        $state = new class {
            public bool $armed = false;
            public bool $possible = false;
            public int $after = 0;
        };
        $clock = static function () use ($state, $expired): float {
            if (!$state->possible) {
                return 0.0;
            }
            return ++$state->after >= 3 ? $expired : 119.0;
        };
        $t = $this->transport(clock: $clock, synchronize: static function ($stream) use ($state): bool {
            if ($state->armed) {
                $state->possible = true;
            }
            return fsync($stream);
        });
        $state->armed = true;
        try {
            $t->sendRequest($this->request());
            self::fail('An invalid remaining timeout was invoked.');
        } catch (ProtocolException) {
            self::assertGreaterThanOrEqual(3, $state->after);
            self::assertSame(0, $t->report()['http_invocations']);
            self::assertSame([], $this->fixture['provider']->requests);
            self::assertTrue($t->report()['transport_poisoned']);
            $records = array_values(array_filter(
                glob($this->fixture['state'] . '/sdk-validation-*/ledger.json') ?: [],
                fn (string $path): bool => $path !== $this->fixture['prior']
            ));
            self::assertCount(1, $records);
            $raw = file_get_contents($records[0]);
            self::assertIsString($raw);
            self::assertStringContainsString('consent_readonly_customer_possible_invocation', $raw);
        }
    }

    public function testNativeLocalStreamResponseAndPrivateJournalPrecedeSdkDelivery(): void
    {
        // Fixture-only loopback forwarding; production exposes no HTTP target option.
        $code = <<<'CHILD'
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) { exit(1); }
        echo stream_socket_get_name($server, false) . "\n";
        fflush(STDOUT);
        $connection = stream_socket_accept($server, 5);
        if ($connection === false) { exit(2); }
        stream_set_timeout($connection, 5);
        $headers = '';
        while (!str_ends_with($headers, "\r\n\r\n") && strlen($headers) <= 16384) {
            $part = fread($connection, 1);
            if ($part === false || $part === '') { exit(3); }
            $headers .= $part;
        }
        $body = '{"id":"PRIVATE_HTTP"}';
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);
        fclose($connection);
        fclose($server);
        echo json_encode(['cookie_seen' => stripos($headers, "\r\nCookie:") !== false,
            'get_seen' => str_starts_with($headers, 'GET /fixture HTTP/1.1')]);
        CHILD;
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: ['LANG' => 'C']
        );
        self::assertIsResource($process);
        try {
            [$input, $output, $errors] = self::processPipes($pipes);
            fclose($input);
            stream_set_timeout($output, 8);
            $address = fgets($output, 128);
            self::assertIsString($address);
            $address = trim($address);
            self::assertMatchesRegularExpression('/^127\.0\.0\.1:[0-9]+$/D', $address);
            $native = new StreamHandler();
            $seen = [];
            $client = new Client(['handler' => static function (
                RequestInterface $request,
                array $options,
            ) use (
                $address,
                $native,
                &$seen
): PromiseInterface {
                $seen = $options;
                return $native($request->withUri(new Uri('http://' . $address . '/fixture')), $options);
            }]);
            $state = new class {
                public bool $armed = false;
                public bool $possible = false;
            };
            $t = $this->transport(
                $client,
                static fn (): float => $state->possible ? 119.0 : 0.0,
                synchronize: static function ($stream) use ($state): bool {
                    if ($state->armed) {
                        $state->possible = true;
                    }
                    return fsync($stream);
                }
            );
            $state->armed = true;
            $response = $t->sendRequest($this->request());
            foreach (['timeout', 'connect_timeout', 'read_timeout'] as $option) {
                self::assertSame(1.0, $seen[$option] ?? null);
            }
            self::assertSame(0, $response->getBody()->tell());
            self::assertSame('{"id":"PRIVATE_HTTP"}', (string) $response->getBody());
            $raw = stream_get_contents($output);
            self::assertIsString($raw);
            self::assertSame(
                ['cookie_seen' => false, 'get_seen' => true],
                json_decode($raw, true, flags: JSON_THROW_ON_ERROR)
            );
            self::assertSame('', stream_get_contents($errors));
            fclose($output);
            fclose($errors);
            self::assertSame(0, proc_close($process));
            $new = array_values(array_filter(
                glob($this->fixture['state'] . '/sdk-validation-*/ledger.json') ?: [],
                fn (string $path): bool => $path !== $this->fixture['prior']
            ));
            self::assertCount(1, $new);
            $journal = file_get_contents($new[0]);
            self::assertIsString($journal);
            self::assertStringContainsString('consent_readonly_customer_result', $journal);
            self::assertStringContainsString('PRIVATE_HTTP', $journal); // Private only, never report().
            self::assertStringNotContainsString('PRIVATE_HTTP', json_encode($t->report(), JSON_THROW_ON_ERROR));
        } finally {
            self::closePipes($pipes);
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    /**
     * @return array{resource,resource,resource} */
    private static function processPipes(mixed $pipes): array
    {
        self::assertIsArray($pipes);
        $input = $pipes[0] ?? null;
        $output = $pipes[1] ?? null;
        $errors = $pipes[2] ?? null;
        self::assertIsResource($input);
        self::assertIsResource($output);
        self::assertIsResource($errors);
        return [$input, $output, $errors];
    }

    private static function closePipes(mixed $pipes): void
    {
        if (is_array($pipes)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe) && get_resource_type($pipe) === 'stream') {
                    fclose($pipe);
                }
            }
        }
    }

    public function testNativeDefaultsKeepTlsAndDisableProxyCookiesRedirectsAndBuffering(): void
    {
        $t = new AlipayConsentReconciliationTransport('PRIVATE_ACCOUNT');
        $client = (new \ReflectionProperty($t, 'http'))->getValue($t);
        self::assertInstanceOf(Client::class, $client);
        self::assertTrue($client->getConfig('verify'));
        self::assertTrue($client->getConfig('stream'));
        self::assertFalse($client->getConfig('cookies'));
        self::assertFalse($client->getConfig('allow_redirects'));
        self::assertFalse($client->getConfig('decode_content'));
        self::assertSame('', $client->getConfig('proxy'));
    }

    /**
     * @param (Closure(resource):bool)|null $sync
     * @param (Closure():float)|null $clock
     *
     * @param (Closure():int)|null $unixClock
     * @return array{AlipayConsentReconciliationTransport,RunLedger,string}
     */
    private function cleanupTransport(
        ?Closure $sync = null,
        ?Closure $clock = null,
        ?Closure $unixClock = null
    ): array {
        $configuration = Configuration::load('sandbox', $this->fixture['credentials']);
        $intake = new SandboxSession($configuration, 'account', $this->fixture['provider']);
        $prior = AlipayConsentReconciliation::load($this->fixture['prior'], $this->fixture['state'], $intake);
        $context = $prior->cleanupContext(str_repeat('a', 64));
        $ledger = RunLedger::createConsentCleanup($context, $this->fixture['state'], synchronize: $sync);
        $transport = AlipayConsentReconciliationTransport::forCleanup(
            $configuration,
            $prior,
            $this->fixture['provider'],
            $clock,
            unixClock: $unixClock
        );
        $source = SourceEvidence::capture();
        $prior->bindCleanupTransport($transport, $ledger, $context, static function () use (
            $prior,
            $intake,
            $source
): void {
            $prior->assertCleanupOriginal($intake);
            if (SourceEvidence::capture() !== $source) {
                throw new \RuntimeException('Source changed.');
            }
        });
        $session = new SandboxSession($configuration, 'account', $transport);
        $prior->readCleanupCustomer($session);
        $prior->readCleanupConsent($session);
        $prior->readCleanupCustomer($session);
        $prior->readCleanupConsent($session);
        $uuid = $ledger->requestId('consent_disable_cleanup', ['test' => 'private-packet'], $context);
        $transport->armCleanupDisable($uuid);
        return [$transport, $ledger, $uuid];
    }

    /**
     * @return iterable<string,array{string,string}> */
    public static function cleanupPacketMutations(): iterable
    {
        foreach (['prepared', 'possible_invocation'] as $boundary) {
            $mutations = [
                'body', 'method', 'uri', 'headers', 'protocol', 'source', 'clock', 'prior', 'context', 'token',
            ];
            foreach ($mutations as $mutation) {
                yield $boundary . '/' . $mutation => [$boundary, $mutation];
            }
        }
    }

    /** @return array{request:RequestInterface,mutate:Closure():void} */
    private static function mutableCleanupRequest(string $consent, string $raw, string $mutation): array
    {
        $request = new class ($consent, $raw, $mutation) extends Request {
            public bool $changed = false;
            public function __construct(string $consent, string $raw, private readonly string $mutation)
            {
                parent::__construct(
                    'POST',
                    'https://api.sandbox.airwallex.com/api/v1/pa/payment_consents/'
                    . $consent . '/disable',
                    ['Accept' => 'application/json', 'x-api-version' => '2026-08-21',
                        'Content-Type' => 'application/json', 'Authorization' => 'Bearer synthetic-cleanup-token'],
                    $raw
                );
            }
            public function getMethod(): string
            {
                return $this->changed && $this->mutation === 'method' ? 'DELETE' : parent::getMethod();
            }
            public function getUri(): UriInterface
            {
                return $this->changed && $this->mutation === 'uri'
                    ? new Uri('https://foreign.invalid/PRIVATE') : parent::getUri();
            }
            public function getHeaders(): array
            {
                return $this->changed && $this->mutation === 'headers'
                    ? ['X-Unsafe' => ['PRIVATE']] : parent::getHeaders();
            }
            public function getProtocolVersion(): string
            {
                return $this->changed && $this->mutation === 'protocol' ? '2.0' : parent::getProtocolVersion();
            }
        };
        $mutate = static function () use ($request, $mutation): void {
            $request->changed = true;
            if ($mutation === 'body') {
                $request->getBody()->write('MUTATED');
                $request->getBody()->seek(4);
            }
        };
        return ['request' => $request, 'mutate' => $mutate];
    }

    #[DataProvider('cleanupPacketMutations')]
    public function testPrivateDisableSnapshotAndFinalProofSurviveDurability(
        string $boundary,
        string $mutation
    ): void {
        $replacement = AlipayConsentDisableCleanupTest::fixture();
        AlipayConsentReconciliationTest::remove($this->fixture['base']);
        $this->fixture = $replacement;
        $state = new class {
            public ?Closure $mutate = null;
            public bool $fired = false;
            public float $now = 0.0;
            public int $unix = 0;
            public ?RunLedger $ledger = null;
        };
        $state->unix = time();
        $priorPath = $this->fixture['prior'];
        $priorBytes = file_get_contents($priorPath);
        self::assertIsString($priorBytes);
        $sourcePath = self::sdkRoot() . '/tests/Support/Sandbox/Journeys/AlipayConsentReconciliation.php';
        $source = file_get_contents($sourcePath);
        self::assertIsString($source);
        $sync = static function ($stream) use (
            $state,
            $mutation,
            $boundary,
            $sourcePath,
            $source,
            $priorPath
): bool {
            self::assertIsResource($stream);
            $uri = self::streamPath(stream_get_meta_data($stream));
            if (!$state->fired && $state->mutate !== null && str_ends_with($uri, '.writing')) {
                $raw = file_get_contents($uri);
                if (is_string($raw) && str_contains($raw, 'consent_cleanup_disable_' . $boundary)) {
                    $state->fired = true;
                    ($state->mutate)();
                    if ($mutation === 'source') {
                        file_put_contents($sourcePath, $source . "\n// synthetic source mutation\n");
                    } elseif ($mutation === 'clock') {
                        $state->now = 120.0;
                    } elseif ($mutation === 'prior') {
                        file_put_contents($priorPath, '{}');
                    } elseif ($mutation === 'token') {
                        $state->unix = 4102444801;
                    } elseif ($mutation === 'context') {
                        self::assertNotNull($state->ledger);
                        $property = new \ReflectionProperty(RunLedger::class, 'state');
                        $image = $property->getValue($state->ledger);
                        self::assertIsArray($image);
                        $image['context_fingerprint'] = str_repeat('f', 64);
                        $property->setValue($state->ledger, $image);
                    }
                }
            }
            return fsync($stream);
        };
        try {
            [$t, $ledger, $uuid] = $this->cleanupTransport(
                $sync,
                static function () use ($state): float {
                    return $state->now;
                },
                static fn (): int => $state->unix
            );
            $state->ledger = $ledger;
            $raw = '{"request_id":"' . $uuid . '"}';
            $packet = self::mutableCleanupRequest($this->fixture['consent'], $raw, $mutation);
            $request = $packet['request'];
            $state->mutate = $packet['mutate'];
            try {
                $t->sendRequest($request);
                self::assertNotContains($mutation, ['source', 'clock', 'prior', 'context', 'token']);
                $sent = $this->fixture['provider']->requests[5];
                self::assertSame('POST', $sent->getMethod());
                self::assertSame('api.sandbox.airwallex.com', $sent->getUri()->getHost());
                self::assertSame('1.1', $sent->getProtocolVersion());
                self::assertSame($raw, (string) $sent->getBody());
                self::assertSame(['Bearer synthetic-cleanup-token'], $sent->getHeader('Authorization'));
                $file = $this->fixture['state'] . '/' . $ledger->runId() . '/ledger.json';
                $image = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($image);
                self::assertIsArray($image['observations']);
                $prepared = $image['observations']['consent_cleanup_disable_prepared'];
                self::assertIsArray($prepared);
                self::assertSame(hash('sha256', $raw), $prepared['body_sha256']);
                self::assertSame(
                    hash('sha256', json_encode($sent->getHeaders(), JSON_THROW_ON_ERROR)),
                    $prepared['prepared_headers_sha256']
                );
                self::assertSame($prepared, $image['observations']['consent_cleanup_disable_possible_invocation']);
            } catch (ProtocolException) {
                self::assertContains($mutation, ['source', 'clock', 'prior', 'context', 'token']);
                self::assertSame(5, $t->report()['http_invocations']);
                self::assertSame(0, $t->cleanupReport()['disable_invocations']);
                self::assertTrue($t->cleanupReport()['disable_possible']);
            }
            self::assertTrue($state->fired);
        } finally {
            file_put_contents($sourcePath, $source);
            file_put_contents($priorPath, $priorBytes);
        }
    }

    public function testLegacyReadOnlyTransportStillDeniesTheNewExactDisableRouteBeforeAnyInvocation(): void
    {
        $t = $this->transport();
        $request = $this->request('consent')->withMethod('POST')->withUri(new Uri(
            'https://api.sandbox.airwallex.com/api/v1/pa/payment_consents/' . $this->fixture['consent'] . '/disable'
        ))->withHeader('Content-Type', 'application/json')->withBody(Utils::streamFor(
            '{"request_id":"00000000-0000-4000-8000-000000000001"}'
        ));
        try {
            $t->sendRequest($request);
            self::fail('The legacy constructor acquired the cleanup write role.');
        } catch (ProtocolException) {
            self::assertSame([], $this->fixture['provider']->requests);
            self::assertSame(0, $t->report()['http_invocations']);
            self::assertTrue($t->report()['transport_poisoned']);
        }
    }

    public function testCleanupFactoryAndBindingCannotSubstituteAccountContextOrOriginalTargets(): void
    {
        $configuration = Configuration::load('sandbox', $this->fixture['credentials']);
        $session = new SandboxSession($configuration, 'account', $this->fixture['provider']);
        $prior = AlipayConsentReconciliation::load($this->fixture['prior'], $this->fixture['state'], $session);
        $context = $prior->cleanupContext(str_repeat('a', 64));
        $ledger = RunLedger::createConsentCleanup($context, $this->fixture['state']);
        $t = AlipayConsentReconciliationTransport::forCleanup($configuration, $prior, $this->fixture['provider']);
        try {
            $t->bind(
                'FOREIGN_CUSTOMER',
                $this->fixture['consent'],
                $ledger,
                $context,
                static function (): void {
                },
                $prior
            );
            self::fail('A naked target replaced the validated original target.');
        } catch (\RuntimeException $error) {
            if ($error instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $error;
            }
            self::assertSame(0, $t->report()['http_invocations']);
        }
        file_put_contents(
            $this->fixture['credentials'],
            "AIRWALLEX_CLIENT_ID=OTHER\nAIRWALLEX_SANDBOX_API_KEY_MASTER=OTHER\nAIRWALLEX_ACCOUNT_ID=OTHER\n"
        );
        $other = Configuration::load('sandbox', $this->fixture['credentials']);
        try {
            AlipayConsentReconciliationTransport::forCleanup($other, $prior, $this->fixture['provider']);
            self::fail('A different auth context acquired the original proof.');
        } catch (\RuntimeException $error) {
            if ($error instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $error;
            }
            self::assertSame([], $this->fixture['provider']->requests);
        }
    }
    private static function streamPath(mixed $metadata): string
    {
        self::assertIsArray($metadata);
        $uri = $metadata['uri'] ?? null;
        self::assertIsString($uri);
        return $uri;
    }
}
