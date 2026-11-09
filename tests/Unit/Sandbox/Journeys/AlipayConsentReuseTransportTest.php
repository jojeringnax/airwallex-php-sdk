<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReuseTransport;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class AlipayConsentReuseTransportTest extends TestCase
{
    private const PATH = '/api/v1/pa/customers/create';
    private string $directory;
    private RunLedger $ledger;
    private string $context;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/consent-wire-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->context = str_repeat('a', 64);
        $this->ledger = RunLedger::create($this->context, $this->directory);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->directory,
            \FilesystemIterator::SKIP_DOTS
        ), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testNativeDefaultConfigurationReallyStreamsAndKeepsTlsAndNoRedirectsCookies(): void
    {
        $transport = new AlipayConsentReuseTransport('PRIVATE_ACCOUNT');
        $http = (new \ReflectionProperty($transport, 'http'))->getValue($transport);
        self::assertInstanceOf(Client::class, $http);
        self::assertInstanceOf(StreamHandler::class, $http->getConfig('handler'));
        foreach (['stream', 'verify'] as $option) {
            self::assertTrue($http->getConfig($option));
        }
        foreach (['cookies', 'allow_redirects', 'decode_content', 'http_errors'] as $option) {
            self::assertFalse($http->getConfig($option));
        }
        self::assertSame(5, $http->getConfig('read_timeout'));
        self::assertSame(30, $http->getConfig('timeout'));
    }

    /** @param (Closure():float)|null $clock */
    private function transport(?ClientInterface $http = null, ?Closure $clock = null): AlipayConsentReuseTransport
    {
        $transport = new AlipayConsentReuseTransport('PRIVATE_ACCOUNT', $http ?? new Client([
            'handler' => new MockHandler([new Response(201, [], '{"id":"PRIVATE_CUSTOMER"}')]),
        ]), $clock);
        $transport->bind($this->ledger, $this->context);
        return $transport;
    }

    /** @param array<string,mixed> $payload */
    private function prepared(
        AlipayConsentReuseTransport $transport,
        array $payload = ['merchant_customer_id' => '123'],
    ): Request {
        $id = $this->ledger->requestId(
            'customer_create',
            ['path' => self::PATH, 'payload' => $payload],
            $this->context
        );
        $body = [...$payload, 'request_id' => $id];
        $transport->arm('customer_create', self::PATH, $body);
        return new Request(
            'POST',
            'https://api.sandbox.airwallex.com' . self::PATH,
            ['Authorization' => 'Bearer PRIVATE_TOKEN', 'x-api-version' => '2026-08-21',
                'Accept' => 'application/json', 'Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    /** @return array<string,mixed> */
    private function observations(): array
    {
        $paths = glob($this->directory . '/sdk-validation-*/ledger.json') ?: [];
        self::assertCount(1, $paths);
        $raw = file_get_contents($paths[0]);
        self::assertIsString($raw);
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['observations']);
        $observations = [];
        foreach ($data['observations'] as $key => $value) {
            self::assertIsString($key);
            $observations[$key] = $value;
        }
        return $observations;
    }

    public function testActualPreparedBodyIsDurableBeforeNativeLoopbackReceivesSameBytes(): void
    {
        // Only this fixture maps the fixed PSR target to a loopback listener. No production target override exists.
        $code = <<<'PHP'
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($listener === false) { exit(1); }
        echo stream_socket_get_name($listener, false) . "\n";
        fflush(STDOUT);
        $client = stream_socket_accept($listener, 5);
        if ($client === false) { exit(2); }
        stream_set_timeout($client, 5);
        $header = '';
        while (!str_ends_with($header, "\r\n\r\n") && strlen($header) < 16384) {
            $part = fread($client, 1);
            if ($part === false || $part === '') { exit(3); }
            $header .= $part;
        }
        if (preg_match('/Content-Length: (\d+)/i', $header, $match) !== 1) { exit(4); }
        $body = '';
        while (strlen($body) < (int) $match[1]) {
            $part = fread($client, (int) $match[1] - strlen($body));
            if ($part === false || $part === '') { exit(5); }
            $body .= $part;
        }
        $json = '{"id":"PRIVATE_CUSTOMER"}';
        fwrite($client, "HTTP/1.1 201 Created\r\nConnection: close\r\nContent-Length: " . strlen($json)
            . "\r\n\r\n" . $json);
        fclose($client);
        echo hash('sha256', $body);
        PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        self::assertIsResource($pipes[0] ?? null);
        self::assertIsResource($pipes[1] ?? null);
        self::assertIsResource($pipes[2] ?? null);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 5);
        $line = fgets($pipes[1], 128);
        self::assertIsString($line);
        $address = trim($line);
        self::assertMatchesRegularExpression('/^127\.0\.0\.1:[0-9]+$/D', $address);
        $forward = new class ($address, $this) implements ClientInterface {
            public string $failure = '';
            public function __construct(
                private readonly string $address,
                private readonly AlipayConsentReuseTransportTest $test,
            ) {
            }
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                try {
                    $this->test->assertBeforeNative($request);
                    return (new Client(['handler' => new StreamHandler(), 'stream' => true, 'timeout' => 5,
                    'http_errors' => false, 'allow_redirects' => false, 'proxy' => '']))->sendRequest(
                        $request->withUri(new Uri('http://' . $this->address . '/fixture'))
                    );
                } catch (\Throwable $error) {
                    $this->failure = $error->getMessage(); // Synthetic fixture only, never production diagnostics.
                    throw $error;
                }
            }
        };
        $transport = $this->transport($forward);
        try {
            $request = $this->prepared($transport);
            try {
                $response = $transport->sendRequest($request);
            } catch (ProtocolException) {
                self::fail('Local fixture: ' . $forward->failure);
            }
            self::assertSame(201, $response->getStatusCode());
            self::assertSame(0, $response->getBody()->tell());
            $hash = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
        }
        self::assertSame(0, $exit);
        self::assertSame('', $stderr);
        self::assertSame(hash('sha256', (string) $request->getBody()), $hash);
        self::assertArrayHasKey('customer_create_wire_result', $this->observations());
        self::assertSame('PRIVATE_CUSTOMER', $transport->native(self::PATH)->id);
        $this->expectException(ProtocolException::class);
        $transport->sendRequest($request); // One actual invocation despite a second caller attempt.
    }

    public function assertBeforeNative(RequestInterface $request): void
    {
        $observations = $this->observations();
        self::assertSame(
            ['invocation' => 'possible', 'body_sha256' => hash('sha256', (string) $request->getBody())],
            $observations['customer_create_possible_send']
        );
        self::assertIsArray($observations['customer_create_wire_prepared']);
        self::assertSame(
            hash('sha256', (string) $request->getBody()),
            $observations['customer_create_wire_prepared']['body_sha256']
        );
        self::assertArrayNotHasKey('customer_create_wire_result', $observations);
    }

    /** @return iterable<string,array{string,string}> */
    public static function deniedRequests(): iterable
    {
        foreach (
            ['https://api.airwallex.com/api/v1/pa/customers/create',
            'https://api.sandbox.airwallex.com:444/api/v1/pa/customers/create',
            'https://PRIVATE@api.sandbox.airwallex.com/api/v1/pa/customers/create',
            'https://api.sandbox.airwallex.com/api/v1/pa/customers/create?x=PRIVATE',
            'https://api.sandbox.airwallex.com/api/v1/pa/customers/create#PRIVATE',
            'https://api.sandbox.airwallex.com/api/v1/simulation/pa/shopper_actions/pay',
            'https://api.sandbox.airwallex.com/api/v1/simulation/pa/shopper_actions/verify_continue',
            'https://api.sandbox.airwallex.com/api/v1/pa/payment_methods/create',
            'https://api.sandbox.airwallex.com/api/v1/pa/customers/PRIVATE_FOREIGN'] as $uri
        ) {
            yield $uri => ['uri', $uri];
        }
        foreach (['Cookie', 'Referer', 'x-on-behalf-of', 'x-login-as', 'x-connected-account-id', 'Host'] as $header) {
            yield $header => ['header', $header];
        }
    }

    #[DataProvider('deniedRequests')]
    public function testNoSendForChangedAuthorityRoleOrHeaders(string $kind, string $value): void
    {
        $sent = 0;
        $client = new Client(['handler' => new MockHandler([function () use (&$sent): ResponseInterface {
            ++$sent;
            return new Response(200);
        }])]);
        $transport = $this->transport($client);
        $request = $this->prepared($transport);
        $request = $kind === 'uri' ? $request->withUri(new Uri($value)) : $request->withHeader($value, 'PRIVATE');
        try {
            $transport->sendRequest($request);
            self::fail('Changed authority was accepted.');
        } catch (ProtocolException $error) {
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
        }
        self::assertSame(0, $sent);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidBodies(): iterable
    {
        yield 'changed value' => ['{"merchant_customer_id":"PRIVATE_OTHER"}'];
        yield 'numeric identity' => ['{"merchant_customer_id":123}'];
        yield 'escaped duplicate' => ['{"merchant_customer_id":"PRIVATE","merchant_customer_\\u0069d":"123"}'];
    }

    #[DataProvider('invalidBodies')]
    public function testPreparedPayloadNativeIdentityAndDuplicatesFailBeforeSend(string $body): void
    {
        $transport = $this->transport();
        $request = $this->prepared($transport);
        $this->expectException(ProtocolException::class);
        $transport->sendRequest($request->withBody(Utils::streamFor($body)));
    }

    public function testChangedPositionOrNonseekableStreamFailsBeforeSend(): void
    {
        $transport = $this->transport();
        $request = $this->prepared($transport);
        $request->getBody()->read(1);
        try {
            $transport->sendRequest($request);
            self::fail('Non-original stream position was accepted.');
        } catch (ProtocolException) {
            self::assertSame(0, $transport->report()['http_invocations']);
        }
        $transport = $this->transport();
        $request = $this->prepared($transport);
        $this->expectException(ProtocolException::class);
        $transport->sendRequest($request->withBody(new NoSeekStream($request->getBody())));
    }

    /** @return iterable<string,array{string,array<string,string>}> */
    public static function invalidResponses(): iterable
    {
        yield 'numeric ID' => ['{"id":123}', []];
        yield 'numeric owner' => ['{"id":"123","customer_id":123}', []];
        yield 'escaped duplicate' => ['{"id":"PRIVATE_OTHER","\\u0069d":"PRIVATE_CUSTOMER"}', []];
        yield 'nested duplicate' => ['{"metadata":{"a":1,"a":2}}', []];
        yield 'not object' => ['[]', []];
        yield 'oversize' => [str_repeat('x', 65537), []];
        yield 'gzip' => ['{}', ['Content-Encoding' => 'gzip']];
        yield 'headers' => ['{}', ['X-Filler' => str_repeat('x', 16385)]];
    }

    /** @param array<string,string> $headers */
    #[DataProvider('invalidResponses')]
    public function testBoundedUniqueLiteralResponseProofPrecedesSdkDelivery(string $body, array $headers): void
    {
        $transport = $this->transport(new Client(['handler' => new MockHandler([new Response(201, $headers, $body)])]));
        $request = $this->prepared($transport);
        try {
            $transport->sendRequest($request);
            self::fail('An unusable response escaped.');
        } catch (ProtocolException) {
            self::assertTrue($transport->report()['transport_poisoned']);
            self::assertSame(1, $transport->report()['http_invocations']);
        }
    }

    /** @return iterable<string,array{float}> */
    public static function lateCloseTimes(): iterable
    {
        yield 'original read window' => [10.0];
        yield 'original case window' => [301.0];
    }

    #[DataProvider('lateCloseTimes')]
    public function testPostCloseReadOrGlobalExpiryCannotAuthorizeLateResponse(float $elapsed): void
    {
        $now = 0.0;
        $stream = new class (Utils::streamFor('{"id":"PRIVATE_CUSTOMER"}')) implements
            \Psr\Http\Message\StreamInterface
        {
            use StreamDecoratorTrait;

            private \Psr\Http\Message\StreamInterface $stream;

            public ?Closure $closed = null;
            public function __construct(\Psr\Http\Message\StreamInterface $stream)
            {
                $this->stream = $stream;
            }
            public function close(): void
            {
                $this->stream->close();
                ($this->closed ?? static fn (): null => null)();
            }
        };
        $stream->closed = static function () use (&$now, $elapsed): void {
            $now = $elapsed;
        };
        $transport = $this->transport(
            new Client(['handler' => new MockHandler([new Response(201, [], $stream)])]),
            static function () use (&$now): float {
                return $now;
            }
        );
        $request = $this->prepared($transport);
        $this->expectException(ProtocolException::class);
        $transport->sendRequest($request);
    }

    public function testNoAuthenticationWithoutFreshBoundLedgerAndCorrectContext(): void
    {
        $transport = new AlipayConsentReuseTransport('PRIVATE_ACCOUNT');
        $this->expectException(ProtocolException::class);
        $transport->sendRequest(new Request('POST', 'https://api.sandbox.airwallex.com/api/v1/authentication/login'));
    }

    public function testPrivateDebugAndSerializationNeverExposeTransportOrAccount(): void
    {
        $transport = $this->transport();
        self::assertStringNotContainsString('PRIVATE', print_r($transport, true));
        $this->expectException(ProtocolException::class);
        serialize($transport);
    }

    private static function login(): Request
    {
        return new Request(
            'POST',
            'https://api.sandbox.airwallex.com/api/v1/authentication/login',
            ['x-client-id' => 'PRIVATE_CLIENT', 'x-api-key' => 'PRIVATE_KEY', 'x-login-as' => 'PRIVATE_ACCOUNT',
            'x-api-version' => '2026-08-21',
            'Accept' => 'application/json']
        );
    }

    public function testHttpInvocationBudgetIsFiniteEvenForRepeatedAuthentication(): void
    {
        $transport = $this->transport(new Client(['handler' => new MockHandler(array_map(
            static fn (int $index): Response =>
                new Response(201, [], '{"token":"PRIVATE","expires_at":"2100-01-01T00:00:00Z"}'),
            range(1, 65)
        ))]));
        for ($i = 0; $i < 64; ++$i) {
            $transport->sendRequest(self::login());
        }
        try {
            $transport->sendRequest(self::login());
            self::fail('A sixty-fifth invocation escaped.');
        } catch (ProtocolException) {
            self::assertSame(64, $transport->report()['http_invocations']);
        }
    }

    public function testMainByteBudgetLeavesCleanupReserveAndStillHasAbsoluteCeiling(): void
    {
        $body = '{"pad":"' . str_repeat('x', 60000) . '"}';
        $transport = $this->transport(new Client(['handler' => new MockHandler(array_map(
            static fn (int $index): Response => new Response(201, [], $body),
            range(1, 40)
        ))]));
        for ($i = 0; $i < 30; ++$i) {
            $transport->sendRequest(self::login());
        }
        try {
            $transport->sendRequest(self::login());
            self::fail('The reserved cleanup bytes were consumed by the main phase.');
        } catch (ProtocolException) {
            self::assertGreaterThan(1835008, $transport->report()['response_bytes']);
        }
        $transport->beginCleanup(); // Read-only reconciliation remains bounded; poisoned writes cannot rearm.
        $transport->sendRequest(self::login());
        self::assertLessThan(2097152, $transport->report()['response_bytes']);
        try {
            for ($i = 0; $i < 8; ++$i) {
                $transport->sendRequest(self::login());
            }
            self::fail('The absolute response-body byte ceiling was not enforced.');
        } catch (ProtocolException) {
            self::assertGreaterThan(2097152, $transport->report()['response_bytes']);
            self::assertTrue($transport->report()['transport_poisoned']);
            self::assertLessThan(40, $transport->report()['http_invocations']);
        }
    }

    public function testMainAndCleanupClocksUseOneOriginalAnchorWithoutReset(): void
    {
        $now = 0.0;
        $transport = $this->transport(new Client(['handler' => new MockHandler([
            new Response(201, [], '{}'),
        ])]), static function () use (&$now): float {
            return $now;
        });
        $now = 240.0;
        try {
            $transport->sendRequest(self::login());
            self::fail('An expired main admission escaped.');
        } catch (ProtocolException) {
            self::assertSame(0, $transport->report()['http_invocations']);
        }
        $transport->beginCleanup();
        $transport->sendRequest(self::login());
        $now = 300.0;
        $this->expectException(ProtocolException::class);
        $transport->sendRequest(self::login());
    }

    public function testUnusedForeignTargetsCannotBeRegisteredWithoutDurableInventory(): void
    {
        $transport = $this->transport();
        $this->expectException(ProtocolException::class);
        $transport->register('payment_consents', 'PRIVATE_FOREIGN');
    }

    public function testLiteralNumericIdentityCannotMatchStringThroughLosslessCoercion(): void
    {
        $transport = $this->transport();
        $request = $this->prepared($transport);
        $raw = str_replace(
            '"merchant_customer_id":"123"',
            '"merchant_customer_id":123',
            (string) $request->getBody()
        );
        $request = $request->withBody(Utils::streamFor($raw));
        try {
            $transport->sendRequest($request);
            self::fail('A native numeric identity was authorized as a string.');
        } catch (ProtocolException) {
            self::assertSame(0, $transport->report()['http_invocations']);
        }
    }

    public function testPreparedRequestSizeLimitPrecedesAnyNativeSend(): void
    {
        $transport = $this->transport();
        $request = $this->prepared($transport, ['merchant_customer_id' => str_repeat('x', 16385)]);
        $this->expectException(ProtocolException::class);
        $transport->sendRequest($request);
    }

    public function testErrorDuplicateKeysCannotReachPassiveSdkCodeProjection(): void
    {
        $transport = $this->transport(new Client(['handler' => new MockHandler([new Response(
            400,
            [],
            '{"code":"PRIVATE","co\\u0064e":"invalid_argument"}'
        )])]));
        $request = $this->prepared($transport);
        $this->expectException(ProtocolException::class);
        $transport->sendRequest($request);
    }

    /** @return iterable<string,array{string}> */
    public static function failedStreams(): iterable
    {
        foreach (['no_progress', 'slow_progress', 'read_throws', 'close_throws'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('failedStreams')]
    public function testResponseProgressAndCloseFailuresCannotDeliverOrRearm(string $mode): void
    {
        $now = 0.0;
        $stream = new class (Utils::streamFor('{"id":"PRIVATE_CUSTOMER"}'), $mode) implements
            \Psr\Http\Message\StreamInterface
        {
            use StreamDecoratorTrait;

            public ?Closure $advance = null;
            public function __construct(
                private \Psr\Http\Message\StreamInterface $stream,
                private readonly string $mode,
            ) {
            }
            public function read($length): string
            {
                if ($this->mode === 'no_progress') {
                    return '';
                }
                if ($this->mode === 'read_throws') {
                    throw new \RuntimeException('PRIVATE synthetic response');
                }
                if ($this->mode === 'slow_progress') {
                    ($this->advance ?? static fn (): null => null)();
                    return $this->stream->read(1);
                }
                return $this->stream->read($length);
            }
            public function close(): void
            {
                $this->stream->close();
                if ($this->mode === 'close_throws') {
                    throw new \RuntimeException('PRIVATE synthetic close');
                }
            }
        };
        $stream->advance = static function () use (&$now): void {
            $now = 11.0;
        };
        $transport = $this->transport(
            new Client(['handler' => new MockHandler([new Response(201, [], $stream)])]),
            static function () use (&$now): float {
                return $now;
            }
        );
        $request = $this->prepared($transport);
        try {
            $transport->sendRequest($request);
            self::fail('An unusable stream escaped.');
        } catch (ProtocolException $error) {
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            self::assertSame(1, $transport->report()['http_invocations']);
            self::assertTrue($transport->report()['transport_poisoned']);
            self::assertSame(
                ['outcome' => 'unusable_or_unacknowledged'],
                $this->observations()['customer_create_wire_result']
            );
        }
        $this->expectException(ProtocolException::class);
        $this->prepared($transport);
    }
}
