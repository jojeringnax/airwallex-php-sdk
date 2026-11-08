<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\KlarnaTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class KlarnaTransportTest extends TestCase
{
    private static function request(): Request
    {
        return new Request('GET', KlarnaProvider::REDIRECT, ['Accept' => 'text/html, application/xhtml+xml']);
    }

    public function testDefaultStreamingTlsCookieRedirectAndTimeBoundConfiguration(): void
    {
        $client = (new ReflectionProperty(KlarnaTransport::class, 'http'))->getValue(new KlarnaTransport());
        self::assertInstanceOf(Client::class, $client);
        self::assertInstanceOf(StreamHandler::class, $client->getConfig('handler'));
        foreach (
            ['stream' => true, 'verify' => true, 'cookies' => false, 'allow_redirects' => false,
            'decode_content' => false, 'read_timeout' => 5, 'timeout' => 30, 'connect_timeout' => 10] as $key => $value
        ) {
            self::assertSame($value, $client->getConfig($key));
        }
    }

    public function testFreshBoundSgRedirectIsUnchangedCredentialFreeAndOneUse(): void
    {
        $url = str_replace('/redirect/hk/', '/redirect/sg/', KlarnaProvider::REDIRECT);
        $recording = new RecordingClient(new Client(['handler' => new MockHandler([
            new Response(302, ['Location' => KlarnaProvider::MOCK, 'Set-Cookie' => 'PRIVATE_COOKIE=PRIVATE']),
        ])]));
        $http = new KlarnaTransport($recording, $url);
        $request = new Request('GET', $url, ['Accept' => 'text/html, application/xhtml+xml']);
        self::assertSame(302, $http->sendRequest($request)->getStatusCode());
        self::assertCount(1, $recording->requests);
        self::assertSame($url, (string) $recording->requests[0]->getUri());
        foreach (['Cookie', 'Authorization', 'Referer', 'x-api-key', 'x-on-behalf-of', 'client-secret'] as $header) {
            self::assertFalse($recording->requests[0]->hasHeader($header));
        }
        $this->expectException(ProtocolException::class);
        try {
            $http->sendRequest($request);
        } finally {
            self::assertCount(1, $recording->requests);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function unknownRegions(): iterable
    {
        foreach (['au', 'eu', 'de', 'HK', 'sg/extra', ''] as $region) {
            yield $region === '' ? 'missing' : $region => [$region];
        }
    }

    #[DataProvider('unknownRegions')]
    public function testUnknownRegionalShapeCannotBindOrSend(string $region): void
    {
        $url = str_replace('/redirect/hk/', '/redirect/' . $region . '/', KlarnaProvider::REDIRECT);
        $recording = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        $this->expectException(ProtocolException::class);
        try {
            new KlarnaTransport($recording, $url);
        } finally {
            self::assertSame([], $recording->requests);
        }
    }

    /** @return iterable<string,array{Request}> */
    public static function badReads(): iterable
    {
        yield 'production' => [new Request('GET', 'https://api.airwallex.com/PRIVATE')];
        yield 'mock second GET' => [new Request('GET', KlarnaProvider::MOCK)];
        yield 'different token' => [new Request('GET', KlarnaProvider::REDIRECT . 'OTHER')];
        yield 'POST' => [self::request()->withMethod('POST')];
        foreach (['Authorization', 'Cookie', 'Referer', 'x-api-key', 'x-on-behalf-of', 'client-secret'] as $name) {
            yield $name => [self::request()->withHeader($name, 'PRIVATE')];
        }
    }

    #[DataProvider('badReads')]
    public function testBoundAnonymousUrlCannotBroadenHeadersRouteOrMethod(Request $request): void
    {
        $recording = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        $http = new KlarnaTransport($recording, KlarnaProvider::REDIRECT);
        $this->expectException(ProtocolException::class);
        try {
            $http->sendRequest($request);
        } finally {
            self::assertSame([], $recording->requests);
        }
    }

    /** @return iterable<string,array{string,int}> */
    public static function streamFailures(): iterable
    {
        yield 'over limit' => ['', 65537];
        yield 'no progress' => ['stalled', 10];
        yield 'read throws' => ['broken', 10];
        yield 'close throws' => ['close_failure', 10];
    }

    #[DataProvider('streamFailures')]
    public function testResponseFailuresClosePrivateStreamsAndNeverReplay(string $mode, int $size): void
    {
        $stream = new SimulationProbeStream(str_repeat('x', $size), $mode);
        $recording = new RecordingClient(new Client(['handler' => new MockHandler([new Response(200, [], $stream)])]));
        $http = new KlarnaTransport($recording, KlarnaProvider::REDIRECT);
        try {
            $http->sendRequest(self::request());
            self::fail('Unsafe response passed.');
        } catch (ProtocolException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('PRIVATE', (string) $error);
            self::assertTrue($stream->closed);
            self::assertLessThanOrEqual(65537, $stream->bytes);
        }
        try {
            $http->sendRequest(self::request());
            self::fail('An anonymous read repeated.');
        } catch (ProtocolException) {
            self::assertCount(1, $recording->requests);
        }
    }

    public function testPositiveProgressAndCloseAreIncludedInElapsedDeadline(): void
    {
        $now = 0.0;
        $stream = new SimulationProbeStream(str_repeat('x', 100), 'one_byte');
        $http = new KlarnaTransport(
            new Client(['handler' => new MockHandler([new Response(200, [], $stream)])]),
            KlarnaProvider::REDIRECT,
            static function () use (&$now): float {
                return $now += 2;
            }
        );
        $this->expectException(ProtocolException::class);
        try {
            $http->sendRequest(self::request());
        } finally {
            self::assertTrue($stream->closed);
            self::assertGreaterThan(0, $stream->bytes);
            self::assertLessThan(100, $stream->bytes);
        }
    }

    public function testSimulationRequiresTheDocumentedEmptyResponseAndClosesAfterOneByte(): void
    {
        $stream = new SimulationProbeStream('PRIVATE_NONEMPTY');
        $http = new KlarnaTransport(new Client(['handler' => new MockHandler([new Response(200, [], $stream)])]));
        $this->expectException(ProtocolException::class);
        try {
            $http->sendRequest(new Request(
                'POST',
                'https://api.sandbox.airwallex.com/api/v1/simulation/pa/shopper_actions/pay'
            ));
        } finally {
            self::assertTrue($stream->closed);
            self::assertSame(1, $stream->bytes);
        }
    }

    public function testApiCannotInvokeUnknownProductsOrProduction(): void
    {
        foreach (
            ['https://api.airwallex.com/api/v1/pa/payment_intents/create',
            'https://api.sandbox.airwallex.com/api/v1/accounts/create',
            'https://api.sandbox.airwallex.com/api/v1/simulation/pa/shopper_actions/reject'] as $url
        ) {
            $recording = new RecordingClient(new Client(['handler' => new MockHandler([])]));
            try {
                (new KlarnaTransport($recording))->sendRequest(new Request('POST', $url));
                self::fail('An unrelated operation passed.');
            } catch (ProtocolException) {
                self::assertSame([], $recording->requests);
            }
        }
    }
}
