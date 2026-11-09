<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\PaymentMethodConfigObservation;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\PaymentMethodConfigTransport;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final class PaymentMethodConfigTransportTest extends TestCase
{
    /** @var array{base:string,state:string,credentials:string} */
    private array $fixture;
    private Configuration $configuration;
    private string $context;

    protected function setUp(): void
    {
        $this->fixture = PaymentMethodConfigObservationTest::fixture();
        $this->configuration = Configuration::load('sandbox', $this->fixture['credentials']);
        $this->context = PaymentMethodConfigObservation::contextFingerprint($this->configuration);
    }

    protected function tearDown(): void
    {
        PaymentMethodConfigObservationTest::remove($this->fixture['base']);
    }

    /**
     * @param (Closure():float)|null $clock
     * @param (Closure():void)|null $gate
     * @param (Closure(resource):bool)|null $synchronize
     * @param (Closure():int)|null $unixClock
     */
    private function transport(
        ?ClientInterface $http = null,
        ?Closure $clock = null,
        ?Closure $gate = null,
        ?Closure $synchronize = null,
        ?Closure $unixClock = null
    ): PaymentMethodConfigTransport {
        $http ??= PaymentMethodConfigObservationTest::client(static fn (RequestInterface $r): ResponseInterface =>
            $r->getMethod() === 'POST' ? PaymentMethodConfigObservationTest::login()
                : PaymentMethodConfigObservationTest::response('{"items":[],"has_more":false}'));
        $ledger = RunLedger::create($this->context, $this->fixture['state'], synchronize: $synchronize);
        $transport = new PaymentMethodConfigTransport($this->configuration, $http, $clock, unixClock: $unixClock);
        $transport->bind($ledger, $this->context, $gate ?? static function (): void {
        });
        return $transport;
    }

    private function request(bool $login = true): RequestInterface
    {
        $headers = ['Accept' => 'application/json', 'x-api-version' => '2026-08-21'];
        return $login ? new Request(
            'POST',
            'https://api.sandbox.airwallex.com/api/v1/authentication/login',
            $headers + $this->configuration->credentials()->headers()
                + ['x-login-as' => $this->configuration->accountId()]
        )
            : new Request('GET', PaymentMethodConfigTransport::TARGET, $headers
                + ['Authorization' => 'Bearer SYNTHETIC_PRIVATE_TOKEN', 'Content-Type' => 'application/json']);
    }

    public function testOnlyOneLoginAndOnePageAreAvailable(): void
    {
        $t = $this->transport();
        $t->sendRequest($this->request());
        $t->sendRequest($this->request(false));
        self::assertSame(2, $t->report()['http_invocations']);
        self::assertSame(2, $t->report()['possible_invocations']);
        self::assertSame('{"items":[],"has_more":false}', $t->page());
        try {
            $t->sendRequest($this->request());
            self::fail('A token refresh was admitted.');
        } catch (ProtocolException $e) {
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $e->getMessage());
            self::assertSame(2, $t->report()['http_invocations']);
            self::assertTrue($t->report()['transport_poisoned']);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function forbiddenTargets(): iterable
    {
        foreach (
            ['https://api.airwallex.com/api/v1/authentication/login',
            'https://api.sandbox.airwallex.com:444/api/v1/authentication/login',
            'https://SYNTHETIC_PRIVATE@api.sandbox.airwallex.com/api/v1/authentication/login',
            'https://api.sandbox.airwallex.com/api/v1/authentication/login?x=1',
            PaymentMethodConfigTransport::TARGET . '#x', PaymentMethodConfigTransport::TARGET . '&page_num=1',
            str_replace('active=true', 'active=false', PaymentMethodConfigTransport::TARGET),
            str_replace('country_code=HK', 'country_code=AU', PaymentMethodConfigTransport::TARGET),
            str_replace('transaction_currency=HKD', 'transaction_currency=USD', PaymentMethodConfigTransport::TARGET),
            str_replace('page_num=0', 'page_num=1', PaymentMethodConfigTransport::TARGET),
            str_replace('page_size=100', 'page_size=1000', PaymentMethodConfigTransport::TARGET),
            str_replace('transaction_mode=recurring', 'transaction_mode=oneoff', PaymentMethodConfigTransport::TARGET),
            'https://api.sandbox.airwallex.com/api/v1/pa/customers',
            'https://api.sandbox.airwallex.com/api/v1/pa/payment_consents/PRIVATE/disable'] as $n => $target
        ) {
            yield (string) $n => [$target];
        }
    }

    #[DataProvider('forbiddenTargets')]
    public function testOtherRoutesAndFiltersNeverInvokeTheClient(string $target): void
    {
        $t = $this->transport();
        try {
            $t->sendRequest($this->request()->withUri(new Uri($target)));
            self::fail('A different route was admitted.');
        } catch (ProtocolException) {
            self::assertSame(0, $t->report()['http_invocations']);
            self::assertSame(0, $t->report()['possible_invocations']);
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function forbiddenHeaders(): iterable
    {
        foreach (
            ['Cookie', 'Referer', 'x-on-behalf-of', 'client-secret', 'x-cloud-trace-context', 'Authorization'] as $name
        ) {
            yield $name => [$name, 'SYNTHETIC_PRIVATE'];
        }
        yield 'Host' => ['Host', 'foreign.test'];
        yield 'account' => ['x-login-as', 'SYNTHETIC_PRIVATE_FOREIGN'];
        yield 'key' => ['x-api-key', 'SYNTHETIC_PRIVATE_OTHER'];
        yield 'version' => ['x-api-version', '2024-06-14'];
    }

    #[DataProvider('forbiddenHeaders')]
    public function testUnknownOrMismatchedHeaderRolesNeverInvoke(string $name, string $value): void
    {
        $t = $this->transport();
        try {
            $t->sendRequest($this->request()->withHeader($name, $value));
            self::fail('A mismatched header was admitted.');
        } catch (ProtocolException) {
            self::assertSame(0, $t->report()['http_invocations']);
        }
    }

    public function testPageRequiresExactlyTheFreshTokenAndCannotPrecedeLogin(): void
    {
        foreach ([false, true] as $login) {
            $t = $this->transport();
            if ($login) {
                $t->sendRequest($this->request());
            }
            try {
                $t->sendRequest($this->request(false)->withHeader('Authorization', 'Bearer SYNTHETIC_PRIVATE_OTHER'));
                self::fail('An unbound token was admitted.');
            } catch (ProtocolException) {
                self::assertSame($login ? 1 : 0, $t->report()['http_invocations']);
            }
        }
    }

    /** @return iterable<string,array{int}> */
    public static function rejectedStatuses(): iterable
    {
        foreach ([200, 202, 204, 301, 302, 400, 401, 403, 404, 429, 500] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('rejectedStatuses')]
    public function testLoginRejectionDoesNotReadErrorBodyOrOpenThePage(int $status): void
    {
        $body = new class (Utils::streamFor('SYNTHETIC_PRIVATE_ERROR')) implements StreamInterface {
            use StreamDecoratorTrait;

            private StreamInterface $stream;
            public function __construct(StreamInterface $stream)
            {
                $this->stream = $stream;
            }
            public int $reads = 0;
            public int $closes = 0;
            public function read(int $length): string
            {
                ++$this->reads;
                return $this->stream->read($length);
            }
            public function close(): void
            {
                ++$this->closes;
                $this->stream->close();
            }
        };
        $http = PaymentMethodConfigObservationTest::client(
            static fn (): ResponseInterface => new Response($status, [], $body)
        );
        $t = $this->transport($http);
        try {
            $t->sendRequest($this->request());
            self::fail('A rejected login was delivered.');
        } catch (ProtocolException) {
            self::assertSame(0, $body->reads);
            self::assertSame(1, $body->closes);
            self::assertSame($status, $t->report()['last_http_status']);
            self::assertSame(1, $t->report()['http_invocations']);
        }
        try {
            $t->sendRequest($this->request(false));
            self::fail('A rejected login opened a read.');
        } catch (ProtocolException) {
            self::assertSame(0, $t->report()['configuration_get_attempts']);
            self::assertTrue($t->report()['transport_poisoned']);
        }
    }

    /** @return iterable<string,array{int}> */
    public static function rejectedPageStatuses(): iterable
    {
        foreach ([201, 204, 301, 302, 400, 401, 403, 429, 500] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('rejectedPageStatuses')]
    public function testPageRejectionNeverReadsAnErrorBodyOrRefreshes(int $status): void
    {
        $body = new class (Utils::streamFor('SYNTHETIC_PRIVATE_ERROR')) implements StreamInterface {
            use StreamDecoratorTrait;

            private StreamInterface $stream;
            public int $reads = 0;
            public int $closes = 0;
            public function __construct(StreamInterface $stream)
            {
                $this->stream = $stream;
            }
            public function read(int $length): string
            {
                ++$this->reads;
                throw new RuntimeException('SYNTHETIC_PRIVATE_ERROR');
            }
            public function close(): void
            {
                ++$this->closes;
                $this->stream->close();
            }
        };
        $http = PaymentMethodConfigObservationTest::client(static fn (RequestInterface $r): ResponseInterface =>
            $r->getMethod() === 'POST' ? PaymentMethodConfigObservationTest::login()
                : new Response($status, [], $body));
        $t = $this->transport($http);
        $t->sendRequest($this->request());
        try {
            $t->sendRequest($this->request(false));
            self::fail('An unexpected Config response was delivered.');
        } catch (ProtocolException $e) {
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $e->getMessage());
            self::assertSame(0, $body->reads);
            self::assertSame(1, $body->closes);
            self::assertSame($status, $t->report()['last_http_status']);
        }
        try {
            $t->sendRequest($this->request());
            self::fail('A rejected page opened token refresh.');
        } catch (ProtocolException) {
            self::assertSame(2, $t->report()['http_invocations']);
            self::assertSame(1, $t->report()['configuration_get_attempts']);
            self::assertTrue($t->report()['transport_poisoned']);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalidLogins(): iterable
    {
        foreach (
            ['{}', '{"token":12,"expires_at":"2030-01-01T00:00:00Z"}',
            '{"token":"x","token":"y","expires_at":"2030-01-01T00:00:00Z"}',
            '{"token":"x","expires_at":12}', '{"token":"x","expires_at":"2030-02-31T00:00:00Z"}',
            '{"token":"x","expires_at":"2000-01-01T00:00:00Z"}',
            '{"token":"x y","expires_at":"2030-01-01T00:00:00Z"}'] as $n => $raw
        ) {
            yield (string) $n => [$raw];
        }
    }

    #[DataProvider('invalidLogins')]
    public function testInvalidNativeLoginNeverOpensAResource(string $raw): void
    {
        $t = $this->transport(PaymentMethodConfigObservationTest::client(static fn (): ResponseInterface =>
            PaymentMethodConfigObservationTest::response($raw, 201)));
        try {
            $t->sendRequest($this->request());
            self::fail('A malformed token was accepted.');
        } catch (ProtocolException) {
            self::assertSame(1, $t->report()['http_invocations']);
            self::assertTrue($t->report()['transport_poisoned']);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function rollbackStages(): iterable
    {
        foreach (
            ['before', 'prepared', 'possible_invocation', 'final', 'native', 'read', 'close', 'result'] as $stage
        ) {
            yield $stage => [$stage];
        }
    }

    #[DataProvider('rollbackStages')]
    public function testEveryObservedClockRollbackPoisonsWithoutResettingTheOriginalBudget(string $stage): void
    {
        $state = new class {
            public float $now = 0.0;
            public int $calls = 0;
            public int $closes = 0;
            public bool $armed = false;
            public bool $ready = false;
            public bool $sampled = false;
            public bool $changed = false;
            public ?PaymentMethodConfigTransport $transport = null;
        };
        $clock = static function () use ($state): float {
            if ($state->armed) {
                $state->now = $state->sampled ? 1.0 : 2.0;
                $state->sampled = true;
            }
            return $state->now;
        };
        $rollback = static function () use ($state): void {
            $owner = $state->transport;
            if (!$owner instanceof PaymentMethodConfigTransport) {
                throw new RuntimeException('The synthetic clock owner is unavailable.');
            }
            $state->now = 2.0;
            $owner->assertTime();
            $state->now = 1.0;
        };
        $action = static function (string $where) use ($stage, $rollback, $state): void {
            if ($where === 'close') {
                ++$state->closes;
            }
            if ($stage === $where && !$state->changed) {
                $state->changed = true;
                $rollback();
            }
        };
        $client = PaymentMethodConfigObservationTest::client(static function () use (
            $state,
            $action
        ): ResponseInterface {
            ++$state->calls;
            $action('native');
            $stream = new class (
                Utils::streamFor((string) PaymentMethodConfigObservationTest::login()->getBody()),
                $action
            ) implements StreamInterface {
                use StreamDecoratorTrait;

                private StreamInterface $stream;
                /** @param Closure(string):void $action */
                public function __construct(StreamInterface $stream, private readonly Closure $action)
                {
                    $this->stream = $stream;
                }
                public function read(int $length): string
                {
                    ($this->action)('read');
                    return $this->stream->read($length);
                }
                public function close(): void
                {
                    $this->stream->close();
                    ($this->action)('close');
                }
            };
            return new Response(201, ['Content-Type' => 'application/json'], $stream);
        });
        $sync = function ($stream) use ($stage, $rollback, $state): bool {
            if (!is_resource($stream)) {
                return false;
            }
            if ($stage === 'final' && $this->hasPhase('payment_config_login_possible_invocation')) {
                $state->ready = true;
            } elseif (!$state->changed && $this->hasPhase('payment_config_login_' . $stage)) {
                $state->changed = true;
                $rollback();
            }
            return fsync($stream);
        };
        $gate = static function () use ($state): void {
            if ($state->ready) {
                $state->armed = true;
                $state->ready = false;
            }
        };
        $transport = $this->transport($client, $clock, $gate, $sync);
        $state->transport = $transport;
        if ($stage === 'before') {
            $state->now = 119.0;
            $transport->assertTime();
            $state->now = 90.0;
        }
        try {
            $transport->sendRequest($this->request());
            self::fail('Observed monotonic progress was reset by a later sample.');
        } catch (ProtocolException) {
            $sent = in_array($stage, ['native', 'read', 'close', 'result'], true) ? 1 : 0;
            self::assertSame($sent, $state->calls);
            self::assertSame($sent, $state->closes);
            self::assertSame($stage === 'before' ? 0 : 1, $transport->report()['possible_invocations']);
            self::assertTrue($transport->report()['transport_poisoned']);
        }
        try {
            $transport->sendRequest($this->request(false));
            self::fail('A rollback-poisoned run opened a later read.');
        } catch (ProtocolException) {
            self::assertSame($state->calls, $transport->report()['http_invocations']);
            self::assertSame(0, $transport->report()['configuration_get_attempts']);
        }
    }

    public function testEqualSamplesAndOrdinaryProgressKeepTheOriginalBudget(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = $this->transport(clock: $clock);
        foreach ([0.0, 1.0, 1.0, 2.0] as $now) {
            $transport->assertTime();
        }
        $transport->sendRequest($this->request());
        $now = 3.0;
        $transport->sendRequest($this->request(false));
        self::assertSame(2, $transport->report()['http_invocations']);
        self::assertFalse($transport->report()['transport_poisoned']);
    }

    /** @return iterable<string,array{float,bool}> */
    public static function remainingBudgets(): iterable
    {
        yield 'one whole second' => [118.999, true];
        yield 'less than one second' => [119.001, false];
        yield 'expired' => [120.0, false];
        yield 'rollback' => [-1.0, false];
        yield 'nonfinite' => [INF, false];
    }

    #[DataProvider('remainingBudgets')]
    public function testFinalNativeOptionsUseOriginalRemainingBudget(float $afterDurability, bool $send): void
    {
        $now = 0.0;
        $native = [];
        $client = new Client(['handler' => static function (
            RequestInterface $r,
            array $options
        ) use (&$native): FulfilledPromise {
            $native[] = $options;
            return new FulfilledPromise(PaymentMethodConfigObservationTest::login());
        }]);
        $clock = static function () use (&$now): float {
            return $now;
        };
        $sync = function ($stream) use (&$now, $afterDurability): bool {
            if (!is_resource($stream)) {
                return false;
            }
            if ($this->hasPhase('payment_config_login_possible_invocation')) {
                $now = $afterDurability;
            }
            return fsync($stream);
        };
        $t = $this->transport($client, $clock, synchronize: $sync);
        try {
            $t->sendRequest($this->request());
            self::assertTrue($send);
            self::assertCount(1, $native);
            foreach (['timeout', 'connect_timeout', 'read_timeout'] as $option) {
                self::assertIsFloat($native[0][$option]);
                self::assertSame(1.0, $native[0][$option]);
            }
            foreach (['cookies', 'allow_redirects', 'http_errors', 'decode_content'] as $option) {
                self::assertFalse($native[0][$option]);
            }
            self::assertTrue($native[0]['verify']);
            self::assertSame('', $native[0]['proxy']);
        } catch (ProtocolException) {
            self::assertFalse($send);
            self::assertSame([], $native);
            self::assertSame(0, $t->report()['http_invocations']);
            self::assertSame(1, $t->report()['possible_invocations']);
        }
    }

    /** @return iterable<string,array{string,string,bool}> */
    public static function responseHeaderCases(): iterable
    {
        foreach (['login', 'page'] as $role) {
            foreach (
                ['exact' => true, 'over' => false, 'long value' => false,
                'empty flood' => false, 'multivalue' => true, 'nonASCII' => true] as $kind => $valid
            ) {
                yield $role . '/' . $kind => [$role, $kind, $valid];
            }
        }
    }

    #[DataProvider('responseHeaderCases')]
    public function testRepresentedResponseHeaderFramingAtBothBoundRoles(string $role, string $kind, bool $valid): void
    {
        $payload = $role === 'login' ? (string) PaymentMethodConfigObservationTest::login()->getBody()
            : '{"items":[],"has_more":false}';
        $headers = ['Content-Type' => 'application/json'];
        if ($kind === 'empty flood') {
            $headers['X-Flood'] = array_fill(0, 5000, '');
        } elseif ($kind === 'long value') {
            $headers['X-Large'] = str_repeat('a', 8193);
        } elseif ($kind === 'multivalue') {
            $headers['X-Multi'] = ['a', '', 'b'];
        } else {
            $headers['X-A'] = $kind === 'nonASCII' ? str_repeat('é', 4096) : str_repeat('a', 8192);
            $headers['X-B'] = '';
            $bytes = 2;
            foreach ($headers as $name => $value) {
                $bytes += strlen($name) + 2 + strlen($value) + 2;
            }
            $headers['X-B'] = str_repeat('b', 16384 + ($kind === 'over' ? 1 : 0) - $bytes);
        }
        $body = new class (Utils::streamFor($payload)) implements StreamInterface {
            use StreamDecoratorTrait;

            private StreamInterface $stream;
            public function __construct(StreamInterface $stream)
            {
                $this->stream = $stream;
            }
            public int $reads = 0;
            public int $closes = 0;
            public function read(int $length): string
            {
                ++$this->reads;
                return $this->stream->read($length);
            }
            public function close(): void
            {
                ++$this->closes;
                $this->stream->close();
            }
        };
        $calls = 0;
        $client = PaymentMethodConfigObservationTest::client(static function (RequestInterface $r) use (
            &$calls,
            $role,
            $headers,
            $body,
        ): ResponseInterface {
            ++$calls;
            return $role === 'page' && $r->getMethod() === 'POST' ? PaymentMethodConfigObservationTest::login()
                : new Response($role === 'login' ? 201 : 200, $headers, $body);
        });
        $t = $this->transport($client);
        if ($role === 'page') {
            $t->sendRequest($this->request());
        }
        try {
            $t->sendRequest($this->request($role === 'login'));
            self::assertTrue($valid);
            self::assertGreaterThan(0, $body->reads);
        } catch (ProtocolException) {
            self::assertFalse($valid);
            self::assertSame(0, $body->reads);
            self::assertTrue($t->report()['transport_poisoned']);
        }
        self::assertSame(1, $body->closes);
        self::assertSame($role === 'login' ? 1 : 2, $calls);
    }

    /** @return iterable<string,array{string}> */
    public static function durabilityStages(): iterable
    {
        foreach (['login', 'page'] as $role) {
            foreach (['prepared', 'possible_invocation', 'result'] as $stage) {
                yield $role . '/' . $stage => ['payment_config_' . $role . '_' . $stage];
            }
        }
    }

    #[DataProvider('durabilityStages')]
    public function testEachFailedDurabilityBoundaryBlocksAllLaterInvocations(string $phase): void
    {
        $calls = 0;
        $client = PaymentMethodConfigObservationTest::client(static function (
            RequestInterface $r
        ) use (&$calls): ResponseInterface {
            ++$calls;
            return $r->getMethod() === 'POST' ? PaymentMethodConfigObservationTest::login()
                : PaymentMethodConfigObservationTest::response('{"items":[],"has_more":false}');
        });
        $sync = function ($stream) use ($phase): bool {
            return is_resource($stream) && !$this->hasPhase($phase) && fsync($stream);
        };
        $t = $this->transport($client, synchronize: $sync);
        try {
            $t->sendRequest($this->request());
            $t->sendRequest($this->request(false));
            self::fail('The requested durability boundary did not fail.');
        } catch (ProtocolException) {
            $expected = str_contains($phase, '_page_') ? 1 : 0;
            $expected += (int) str_ends_with($phase, '_result');
            self::assertSame($expected, $calls);
            self::assertTrue($t->report()['transport_poisoned']);
        }
        $before = $calls;
        try {
            $t->sendRequest($this->request(false));
            self::fail('A poisoned run resumed.');
        } catch (ProtocolException) {
            self::assertSame($before, $calls);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function mutationStages(): iterable
    {
        yield 'prepared' => ['payment_config_login_prepared'];
        yield 'possible' => ['payment_config_login_possible_invocation'];
    }

    #[DataProvider('mutationStages')]
    public function testDurabilityCallbacksCannotChangeThePrivatePacket(string $phase): void
    {
        $base = $this->request();
        $caller = new class ($base->getMethod(), $base->getUri(), $base->getHeaders()) extends Request {
            public bool $altered = false;
            public function getMethod(): string
            {
                return $this->altered ? 'DELETE' : parent::getMethod();
            }
            public function getUri(): UriInterface
            {
                return $this->altered ? new Uri('https://foreign.test') : parent::getUri();
            }
            public function getProtocolVersion(): string
            {
                return $this->altered ? '2.0' : parent::getProtocolVersion();
            }
            /** @return array<string,list<string>> */
            public function getHeaders(): array
            {
                if ($this->altered) {
                    return ['Cookie' => ['SYNTHETIC_PRIVATE']];
                }
                $headers = [];
                foreach (parent::getHeaders() as $name => $values) {
                    if (!is_string($name) || !array_is_list($values)) {
                        throw new RuntimeException('Synthetic packet headers changed.');
                    }
                    $headers[$name] = $values;
                }
                return $headers;
            }
        };
        $captured = [];
        $client = PaymentMethodConfigObservationTest::client(static function (
            RequestInterface $r
        ) use (&$captured): ResponseInterface {
            $captured = [
                $r->getMethod(), (string) $r->getUri(), $r->getHeaders(),
                (string) $r->getBody(), $r->getProtocolVersion()
            ];
            return PaymentMethodConfigObservationTest::login();
        });
        $sync = function ($stream) use ($phase, $caller): bool {
            if (!is_resource($stream)) {
                return false;
            }
            if ($this->hasPhase($phase)) {
                $caller->altered = true;
                $caller->getBody()->write('SYNTHETIC_PRIVATE');
            }
            return fsync($stream);
        };
        $t = $this->transport($client, synchronize: $sync);
        $t->sendRequest($caller);
        self::assertTrue($caller->altered);
        self::assertSame(['POST', (string) $base->getUri(), $base->getHeaders(), '', '1.1'], $captured);
        $paths = glob($this->fixture['state'] . '/sdk-validation-*/ledger.json');
        self::assertIsArray($paths);
        $raw = file_get_contents($paths[0]);
        self::assertIsString($raw);
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['observations']);
        $facts = $data['observations']['payment_config_login_prepared'];
        self::assertIsArray($facts);
        self::assertSame(hash('sha256', ''), $facts['body_sha256']);
        self::assertSame(
            hash('sha256', json_encode($base->getHeaders(), JSON_THROW_ON_ERROR)),
            $facts['prepared_headers_sha256']
        );
    }

    public function testTokenExpiryDuringPossibleInvocationDurabilityStopsTheGetWithoutRefresh(): void
    {
        $unix = 1_700_000_000;
        $clock = static function () use (&$unix): int {
            return $unix;
        };
        $sync = function ($stream) use (&$unix): bool {
            if (!is_resource($stream)) {
                return false;
            }
            if ($this->hasPhase('payment_config_page_possible_invocation')) {
                $unix = 2_000_000_000;
            }
            return fsync($stream);
        };
        $t = $this->transport(synchronize: $sync, unixClock: $clock);
        $t->sendRequest($this->request());
        try {
            $t->sendRequest($this->request(false));
            self::fail('An expired token was invoked.');
        } catch (ProtocolException) {
            self::assertSame(1, $t->report()['http_invocations']);
            self::assertSame(2, $t->report()['possible_invocations']);
            self::assertSame(1, $t->report()['login_attempts']);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function badStreams(): iterable
    {
        foreach (['close time', 'close throws', 'no progress', 'slow progress', 'oversize'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('badStreams')]
    public function testOriginalTimeAndCloseBoundsRejectLateOrUnusableBodies(string $mode): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $advance = static function (float $seconds) use (&$now): void {
            $now += $seconds;
        };
        $stream = new class (
            Utils::streamFor($mode === 'oversize' ? str_repeat('x', 65537)
            : (string) PaymentMethodConfigObservationTest::login()->getBody()),
            $mode,
            $advance
        ) implements StreamInterface {
            use StreamDecoratorTrait;

            private StreamInterface $stream;
            public int $closes = 0;
            public function __construct(
                StreamInterface $stream,
                private readonly string $mode,
                private readonly Closure $advance
            ) {
                $this->stream = $stream;
            }
            public function read(int $length): string
            {
                if ($this->mode === 'no progress') {
                    return '';
                }
                if ($this->mode === 'slow progress') {
                    ($this->advance)(2.0);
                    return $this->stream->read(1);
                }
                return $this->stream->read($length);
            }
            public function close(): void
            {
                ++$this->closes;
                $this->stream->close();
                if ($this->mode === 'close time') {
                    ($this->advance)(11.0);
                }
                if ($this->mode === 'close throws') {
                    throw new RuntimeException('SYNTHETIC_PRIVATE');
                }
            }
        };
        $client = PaymentMethodConfigObservationTest::client(
            static fn (): ResponseInterface => new Response(201, ['Content-Type' => 'application/json'], $stream)
        );
        $t = $this->transport($client, $clock);
        try {
            $t->sendRequest($this->request());
            self::fail('An unusable body was delivered.');
        } catch (ProtocolException $e) {
            self::assertSame(1, $stream->closes);
            self::assertTrue($t->report()['transport_poisoned']);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $e->getMessage());
        }
    }

    public function testSinglePreparedHeaderValueBoundIsIndependentOfAggregateCap(): void
    {
        $clientId = str_repeat('x', 8193);
        $f = PaymentMethodConfigObservationTest::fixture($clientId, 'short');
        try {
            $configuration = Configuration::load('sandbox', $f['credentials']);
            $context = PaymentMethodConfigObservation::contextFingerprint($configuration);
            $ledger = RunLedger::create($context, $f['state']);
            $t = new PaymentMethodConfigTransport($configuration, PaymentMethodConfigObservationTest::client(
                static fn (): ResponseInterface => PaymentMethodConfigObservationTest::login()
            ));
            $t->bind($ledger, $context, static function (): void {
            });
            $request = $this->request()->withHeader('x-client-id', $clientId)->withHeader('x-api-key', 'short');
            $bytes = 2;
            foreach ($request->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    $bytes += strlen($name) + 2 + strlen($value) + 2;
                }
            }
            self::assertLessThan(16384, $bytes);
            try {
                $t->sendRequest($request);
                self::fail('A value above8192 bytes was admitted despite a small aggregate.');
            } catch (ProtocolException) {
                self::assertSame(0, $t->report()['possible_invocations']);
                self::assertSame(0, $t->report()['http_invocations']);
                self::assertTrue($t->report()['transport_poisoned']);
            }
        } finally {
            PaymentMethodConfigObservationTest::remove($f['base']);
        }
    }

    /** @return iterable<string,array{int,bool}> */
    public static function requestHeaderCaps(): iterable
    {
        yield 'exact16KiB' => [16384, true];
        yield 'plus1' => [16385, false];
    }

    #[DataProvider('requestHeaderCaps')]
    public function testAggregatePreparedHeaderCapBeforeDurability(int $target, bool $valid): void
    {
        $base = $this->request();
        $bytes = 2;
        foreach ($base->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $bytes += strlen($name) + 2 + strlen($value) + 2;
            }
        }
        $clientId = str_repeat('a', 8192);
        $keyLength = $target - $bytes + strlen($this->configuration->credentials()->headers()['x-client-id'])
            + strlen($this->configuration->credentials()->headers()['x-api-key']) - strlen($clientId);
        self::assertGreaterThan(0, $keyLength);
        self::assertLessThanOrEqual(8192, $keyLength);
        $key = str_repeat('b', $keyLength);
        $f = PaymentMethodConfigObservationTest::fixture($clientId, $key);
        try {
            $configuration = Configuration::load('sandbox', $f['credentials']);
            $context = PaymentMethodConfigObservation::contextFingerprint($configuration);
            $ledger = RunLedger::create($context, $f['state']);
            $t = new PaymentMethodConfigTransport($configuration, PaymentMethodConfigObservationTest::client(
                static fn (): ResponseInterface => PaymentMethodConfigObservationTest::login()
            ));
            $t->bind($ledger, $context, static function (): void {
            });
            $request = $base->withHeader('x-client-id', $clientId)->withHeader('x-api-key', $key);
            try {
                $t->sendRequest($request);
                self::assertTrue($valid);
            } catch (ProtocolException) {
                self::assertFalse($valid);
            }
            self::assertSame($valid ? 1 : 0, $t->report()['http_invocations']);
            self::assertSame($valid ? 1 : 0, $t->report()['possible_invocations']);
        } finally {
            PaymentMethodConfigObservationTest::remove($f['base']);
        }
    }

    /** @return iterable<string,array{array<string,string|list<string>>,string}> */
    public static function invalidResponseProfiles(): iterable
    {
        yield 'missing media' => [[], '{}'];
        yield 'HTML' => [['Content-Type' => 'text/html'], '{}'];
        yield 'repeated media' => [['Content-Type' => ['application/json', 'application/json']], '{}'];
        yield 'compressed' => [['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'], '{}'];
        yield 'oversize length' => [['Content-Type' => 'application/json', 'Content-Length' => '65537'], '{}'];
        yield 'wrong length' => [['Content-Type' => 'application/json', 'Content-Length' => '100'], '{}'];
        yield 'malformed JSON' => [['Content-Type' => 'application/json'], '{'];
        yield 'UTF8' => [['Content-Type' => 'application/json'], "{\"token\":\"\xff\"}"];
        yield 'depth' => [
            ['Content-Type' => 'application/json'], '{"x":' . str_repeat('[', 65) . '0' . str_repeat(']', 65) . '}'
        ];
    }

    /** @param array<string,string|list<string>> $headers */
    #[DataProvider('invalidResponseProfiles')]
    public function testInvalidResponseEnvelopeCannotDeliverTokenOrPage(array $headers, string $raw): void
    {
        $t = $this->transport(PaymentMethodConfigObservationTest::client(
            static fn (): ResponseInterface => new Response(201, $headers, $raw)
        ));
        try {
            $t->sendRequest($this->request());
            self::fail('An invalid response was delivered.');
        } catch (ProtocolException $e) {
            self::assertSame(1, $t->report()['http_invocations']);
            self::assertSame(0, $t->report()['configuration_get_attempts']);
            self::assertTrue($t->report()['transport_poisoned']);
            self::assertStringNotContainsString($raw, $e->getMessage());
        }
    }

    public function testBodyProtocolHeaderRepetitionAndPrivateStateCannotOpenARequest(): void
    {
        foreach (
            [$this->request()->withBody(Utils::streamFor('SYNTHETIC_PRIVATE')),
            $this->request()->withProtocolVersion('2.0'),
            $this->request()->withAddedHeader('Accept', 'application/json')] as $request
        ) {
            $t = $this->transport();
            try {
                $t->sendRequest($request);
                self::fail('An ambiguous request was admitted.');
            } catch (ProtocolException) {
                self::assertSame(0, $t->report()['http_invocations']);
            }
        }
        $t = $this->transport();
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', print_r($t, true));
        $this->expectException(ProtocolException::class);
        serialize($t);
    }

    public function testRealNativeHttpLoopbackPreservesTargetAndNeverReusesResponseCookie(): void
    {
        $log = $this->fixture['base'] . '/native.log';
        $script = <<<'SERVER'
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if($server===false){exit(81);} $address=stream_socket_get_name($server,false);
if(!is_string($address)){exit(82);} fwrite(STDOUT,substr($address,strrpos($address,':')+1)."\n");fflush(STDOUT);
for($i=0;$i<2;++$i){
$connection=stream_socket_accept($server,5);if($connection===false){exit(83);}
stream_set_timeout($connection,3);
$raw='';while(!str_ends_with($raw,"\r\n\r\n")&&strlen($raw)<32768){
$part=fread($connection,1);if($part===false||$part===''){exit(84);}$raw.=$part;}
file_put_contents($argv[1],json_encode($raw,JSON_THROW_ON_ERROR)."\n",FILE_APPEND);chmod($argv[1],0600);
$body=$i===0?' {"token":"SYNTHETIC_PRIVATE_TOKEN","expires_at":"2030-01-01T00:00:00Z"}':'{"items":[],"has_more":false}';
$response='HTTP/1.1 '.($i===0?'201 Created':'200 OK')."\r\nContent-Type: application/json"
."\r\nSet-Cookie: PRIVATE_COOKIE=PRIVATE; Path=/\r\nContent-Length: ".strlen($body)
."\r\nConnection: close\r\n\r\n".$body;
while($response!==''){
$written=fwrite($connection,$response);if($written===false||$written===0){exit(85);}
$response=substr($response,$written);}fclose($connection);}
fclose($server);
SERVER;
        $process = proc_open(
            [PHP_BINARY, '-r', $script, $log],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The synthetic native child is unavailable.');
        }
        try {
            [$input, $output, $errors] = self::pipes($pipes);
            fclose($input);
            stream_set_timeout($output, 5);
            $line = fgets($output);
            self::assertIsString($line);
            self::assertMatchesRegularExpression('/^[0-9]+\n$/D', $line);
            $port = trim($line);
            $handler = new \GuzzleHttp\Handler\StreamHandler();
            $client = new Client(['handler' => static function (
                RequestInterface $r,
                array $options
            ) use (
                $handler,
                $port
): PromiseInterface {
                self::assertSame('https', $r->getUri()->getScheme());
                self::assertSame('api.sandbox.airwallex.com', $r->getUri()->getHost());
                $local = new Uri('http://127.0.0.1:' . $port . $r->getRequestTarget());
                return $handler($r->withUri($local, true), $options);
            }]);
            $t = $this->transport($client);
            $t->sendRequest($this->request());
            $t->sendRequest($this->request(false));
            self::assertSame(2, $t->report()['http_invocations']);
            $raw = file_get_contents($log);
            self::assertIsString($raw);
            $requests = array_map(
                static fn (string $s): mixed => json_decode($s, flags: JSON_THROW_ON_ERROR),
                array_filter(explode("\n", $raw))
            );
            self::assertCount(2, $requests);
            self::assertIsString($requests[0]);
            self::assertIsString($requests[1]);
            self::assertStringStartsWith("POST /api/v1/authentication/login HTTP/1.1\r\n", $requests[0]);
            self::assertStringStartsWith(
                'GET ' . PaymentMethodConfigTransport::PATH . '?' . PaymentMethodConfigTransport::QUERY
                    . " HTTP/1.1\r\n",
                $requests[1]
            );
            self::assertStringContainsString("Host: api.sandbox.airwallex.com\r\n", $requests[1]);
            self::assertStringNotContainsString('Cookie:', $requests[1]);
            self::assertSame('', stream_get_contents($errors));
        } finally {
            self::closePipes($pipes);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }

    /** @return array{resource,resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (!is_array($pipes)) {
            throw new RuntimeException('Synthetic native pipes are unavailable.');
        }
        $input = $pipes[0] ?? null;
        $output = $pipes[1] ?? null;
        $errors = $pipes[2] ?? null;
        if (!is_resource($input) || !is_resource($output) || !is_resource($errors)) {
            throw new RuntimeException('Synthetic native pipes are unavailable.');
        }
        return [$input, $output, $errors];
    }

    private function hasPhase(string $phase): bool
    {
        foreach (glob($this->fixture['state'] . '/sdk-validation-*/ledger.json.writing') ?: [] as $path) {
            $raw = file_get_contents($path);
            if (!is_string($raw)) {
                continue;
            }
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (
                is_array($data) && is_array($data['observations'] ?? null)
                && array_key_exists($phase, $data['observations'])
            ) {
                return true;
            }
        }
        return false;
    }
    /** Cleanup also covers an OS result that failed tuple validation. */
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
}
