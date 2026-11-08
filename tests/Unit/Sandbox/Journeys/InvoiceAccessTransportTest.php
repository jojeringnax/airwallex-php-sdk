<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\TransportException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoiceAccessPolicy;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoiceAccessTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;
use RuntimeException;

final class InvoiceAccessTransportTest extends TestCase
{
    private const string PDF = InvoiceAccessPolicy::ORIGIN . '/billing/invoices/pdf?s=PRIVATE_PDF';

    private static function request(bool $pdf = true): Request
    {
        return new Request('GET', $pdf ? self::PDF : InvoiceAccessPolicy::PAYER, $pdf ? [
            'Accept' => 'application/pdf', 'Accept-Encoding' => 'identity', 'Range' => 'bytes=0-1023',
        ] : ['Accept' => 'application/json', 'Accept-Encoding' => 'identity',
            'Authorization' => 'Bearer PRIVATE_CAPABILITY']);
    }

    public function testDefaultIsExplicitStreamingWithNoCredentialCookieRedirectOrDecodeMiddleware(): void
    {
        $property = new ReflectionProperty(InvoiceAccessTransport::class, 'http');
        $client = $property->getValue(new InvoiceAccessTransport(null, null));
        self::assertInstanceOf(Client::class, $client);
        self::assertInstanceOf(StreamHandler::class, $client->getConfig('handler'));
        foreach (
            ['stream' => true, 'verify' => true, 'cookies' => false, 'allow_redirects' => false,
            'http_errors' => false, 'decode_content' => false, 'read_timeout' => 5,
            'timeout' => 30, 'connect_timeout' => 10] as $name => $value
        ) {
            self::assertSame($value, $client->getConfig($name));
        }
    }

    public function testPdfPrefixStopsAtSentinelAndClosesWithoutUnboundedStringificationOrReplay(): void
    {
        $stream = new SimulationProbeStream(str_repeat('x', 100000));
        $client = new RecordingClient(new Client(['handler' => new MockHandler([new Response(200, [], $stream)])]));
        $http = new InvoiceAccessTransport(self::PDF, null, $client);
        $response = $http->sendRequest(self::request());
        self::assertSame(1025, strlen((string) $response->getBody()));
        self::assertSame(1025, $stream->bytes);
        self::assertTrue($stream->closed);
        $this->expectException(ProtocolException::class);
        $http->sendRequest(self::request());
    }

    public function testErrorOrRedirectClosesWithoutReadingOrFollowingBody(): void
    {
        $stream = new SimulationProbeStream('PRIVATE_ERROR', 'broken');
        $client = new RecordingClient(new Client(['handler' => new MockHandler([
            new Response(302, ['Location' => 'https://PRIVATE_THIRD_PARTY.test'], $stream),
        ])]));
        $response = (new InvoiceAccessTransport(self::PDF, null, $client))->sendRequest(self::request());
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(0, $stream->bytes);
        self::assertTrue($stream->closed);
        self::assertCount(1, $client->requests);
    }

    /** @return iterable<string,array{string}> */
    public static function rejectedData(): iterable
    {
        foreach (
            ['Authorization', 'Cookie', 'Referer', 'x-api-version', 'x-on-behalf-of',
            'x-client-id', 'x-api-key', 'x-login-as', 'client-secret'] as $name
        ) {
            yield $name => [$name];
        }
    }

    #[DataProvider('rejectedData')]
    public function testPdfNeverReceivesCredentialsOrUnreviewedHeaders(string $name): void
    {
        $client = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        $http = new InvoiceAccessTransport(self::PDF, 'PRIVATE_CAPABILITY', $client);
        try {
            $http->sendRequest(self::request()->withHeader($name, 'PRIVATE_CONTEXT'));
            self::fail('An unreviewed header was accepted.');
        } catch (ProtocolException $error) {
            self::assertNull($error->getPrevious());
            self::assertSame([], $client->requests);
        }
    }

    /** @return iterable<string,array{RequestInterface}> */
    public static function unboundRequests(): iterable
    {
        yield 'method' => [self::request()->withMethod('POST')];
        yield 'body' => [self::request()->withBody(Utils::streamFor('PRIVATE_BODY'))];
        yield 'production' => [new Request('GET', 'https://invoice.airwallex.com/PRIVATE')];
        yield 'query' => [new Request('GET', InvoiceAccessPolicy::PAYER . '?PRIVATE')];
        yield 'API bearer' => [self::request(false)->withHeader('Authorization', 'Bearer PRIVATE_SDK_TOKEN')];
    }

    #[DataProvider('unboundRequests')]
    public function testDifferentUrlsBodiesMethodsAndBearersNeverSend(RequestInterface $request): void
    {
        $client = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        $http = new InvoiceAccessTransport(self::PDF, 'PRIVATE_CAPABILITY', $client);
        $this->expectException(ProtocolException::class);
        try {
            $http->sendRequest($request);
        } finally {
            self::assertSame([], $client->requests);
        }
    }

    public function testDefaultTransportBindsNoCapability(): void
    {
        $client = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        $this->expectException(ProtocolException::class);
        (new InvoiceAccessTransport(null, null, $client))->sendRequest(self::request());
    }

    /** @return iterable<string,array{string,int}> */
    public static function failedStreams(): iterable
    {
        yield 'oversize' => ['', 100000];
        yield 'stalled' => ['stalled', 10];
        yield 'broken' => ['broken', 10];
        yield 'close' => ['close_failure', 10];
    }

    #[DataProvider('failedStreams')]
    public function testPayerStreamsAreBoundedClosedRedactedAndNeverRepeated(string $mode, int $size): void
    {
        $stream = new SimulationProbeStream(str_repeat('x', $size), $mode);
        $client = new RecordingClient(new Client(['handler' => new MockHandler([new Response(200, [], $stream)])]));
        $http = new InvoiceAccessTransport(null, 'PRIVATE_CAPABILITY', $client);
        try {
            $http->sendRequest(self::request(false));
            self::fail('An unsafe private stream was accepted.');
        } catch (ProtocolException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('PRIVATE', (string) $error);
            self::assertTrue($stream->closed);
            self::assertLessThanOrEqual(65537, $stream->bytes);
        }
        try {
            $http->sendRequest(self::request(false));
            self::fail('An incomplete read was replayed.');
        } catch (ProtocolException) {
            self::assertCount(1, $client->requests);
        }
    }

    public function testPositiveProgressAndCloseTimeCannotEscapeDeadline(): void
    {
        $now = 0.0;
        $stream = new SimulationProbeStream(str_repeat('x', 100), 'one_byte');
        $client = new RecordingClient(new Client(['handler' => new MockHandler([new Response(200, [], $stream)])]));
        $http = new InvoiceAccessTransport(null, 'PRIVATE_CAPABILITY', $client, static function () use (&$now): float {
            return $now += 2;
        });
        try {
            $http->sendRequest(self::request(false));
            self::fail('Slow positive progress was not bounded.');
        } catch (ProtocolException) {
            self::assertGreaterThan(0, $stream->bytes);
            self::assertLessThan(100, $stream->bytes);
            self::assertTrue($stream->closed);
        }
        $stream = new SimulationProbeStream('x', afterClose: static function () use (&$now): void {
            $now = 20;
        });
        $now = 0;
        $http = new InvoiceAccessTransport(null, 'PRIVATE_CAPABILITY', new Client([
            'handler' => new MockHandler([new Response(200, [], $stream)]),
        ]), static function () use (&$now): float {
            return $now;
        });
        $this->expectException(ProtocolException::class);
        $http->sendRequest(self::request(false));
    }

    public function testTransportErrorsAndDebugCannotExposePrivateBoundCapabilities(): void
    {
        $client = new RecordingClient(new Client(['handler' => new MockHandler([
            new RuntimeException('PRIVATE_URL_CONTEXT'),
        ])]));
        $http = new InvoiceAccessTransport(self::PDF, 'PRIVATE_CAPABILITY', $client);
        try {
            $http->sendRequest(self::request());
            self::fail('A send failure was ignored.');
        } catch (TransportException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('PRIVATE', (string) $error);
        }
        ob_start();
        var_dump($http);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('PRIVATE', $debug);
        $this->expectException(ProtocolException::class);
        serialize($http);
    }
}
