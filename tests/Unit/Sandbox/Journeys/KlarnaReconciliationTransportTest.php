<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\KlarnaIntentReconciliation;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\KlarnaReconciliationTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class KlarnaReconciliationTransportTest extends TestCase
{
    private const string BASE = 'https://api.sandbox.airwallex.com';

    private function request(string $path = '/api/v1/pa/payment_intents/PRIVATE_INTENT'): Request
    {
        return new Request(
            'GET',
            self::BASE . $path,
            ['Accept' => 'application/json', 'x-api-version' => '2026-08-21', 'Authorization' => 'Bearer PRIVATE_TOKEN']
        );
    }

    private function provider(): KlarnaReconciliationProvider
    {
        return new KlarnaReconciliationProvider(KlarnaIntentReconciliation::createBody('SYNTHETIC', '1.23'));
    }

    /** @return iterable<string,array{string,string,string,string}> */
    public static function denied(): iterable
    {
        yield 'production' => ['GET', 'https://api.airwallex.com/api/v1/pa/payment_intents/PRIVATE_INTENT', '', ''];
        yield 'userinfo' => ['GET',
            'https://PRIVATE@api.sandbox.airwallex.com/api/v1/pa/payment_intents/PRIVATE_INTENT', '', ''];
        yield 'foreign' => ['GET', self::BASE . '/api/v1/pa/payment_intents/FOREIGN', '', ''];
        yield 'create' => ['POST', self::BASE . '/api/v1/pa/payment_intents/create', '', ''];
        yield 'confirm' => ['POST', self::BASE . '/api/v1/pa/payment_intents/PRIVATE_INTENT/confirm', '', ''];
        yield 'capture' => ['POST', self::BASE . '/api/v1/pa/payment_intents/PRIVATE_INTENT/capture', '', ''];
        yield 'simulation' => ['POST', self::BASE . '/api/v1/simulation/pa/shopper_actions/pay', '', ''];
        yield 'refund mutation' => ['POST', self::BASE . '/api/v1/pa/refunds/create', '', ''];
        yield 'unfiltered list' => ['GET', self::BASE . '/api/v1/pa/refunds', '', ''];
        yield 'query' => ['GET', self::BASE . '/api/v1/pa/payment_intents/PRIVATE_INTENT?PRIVATE=1', '', ''];
        yield 'OBO' => ['GET', self::BASE . '/api/v1/pa/payment_intents/PRIVATE_INTENT', 'x-on-behalf-of', 'PRIVATE'];
        yield 'cookie' => ['GET', self::BASE . '/api/v1/pa/payment_intents/PRIVATE_INTENT', 'Cookie', 'PRIVATE'];
        yield 'referer' => ['GET', self::BASE . '/api/v1/pa/payment_intents/PRIVATE_INTENT', 'Referer', 'PRIVATE'];
        yield 'unknown auth' => ['GET', self::BASE . '/api/v1/pa/payment_intents/PRIVATE_INTENT',
            'x-api-key', 'PRIVATE'];
        yield 'old version' => ['GET', self::BASE . '/api/v1/pa/payment_intents/PRIVATE_INTENT',
            'x-api-version', '2024-06-14'];
    }

    #[DataProvider('denied')]
    public function testClosedAuthorityBeforeDelegate(string $method, string $url, string $name, string $value): void
    {
        $provider = $this->provider();
        $transport = new KlarnaReconciliationTransport('PRIVATE_ACCOUNT', $provider);
        $transport->bindIntent('PRIVATE_INTENT');
        $request = $this->request()->withMethod($method)->withUri(Utils::uriFor($url));
        if ($name !== '') {
            $request = $request->withHeader($name, $value);
        }
        try {
            $transport->sendRequest($request);
            self::fail('Unsafe route or header was admitted.');
        } catch (ProtocolException $error) {
            self::assertStringNotContainsString('PRIVATE', (string) $error);
        }
        self::assertSame([], $provider->requests);
    }

    public function testReadBodyMustBeEmpty(): void
    {
        $provider = $this->provider();
        $transport = new KlarnaReconciliationTransport('PRIVATE_ACCOUNT', $provider);
        $transport->bindIntent('PRIVATE_INTENT');
        try {
            $transport->sendRequest($this->request()->withBody(Utils::streamFor('PRIVATE')));
            self::fail('GET body was admitted.');
        } catch (ProtocolException) {
            self::assertSame([], $provider->requests);
        }
    }

    public function testSingleCancellationWireHashAndCursorAreEnforced(): void
    {
        $provider = $this->provider();
        $transport = new KlarnaReconciliationTransport('PRIVATE_ACCOUNT', $provider);
        $transport->bindIntent('PRIVATE_INTENT');
        $bytes = '{"request_id":"synthetic-id"}';
        $transport->authorizeCancel(hash('sha256', $bytes));
        $request = $this->request('/api/v1/pa/payment_intents/PRIVATE_INTENT/cancel')->withMethod('POST')
            ->withHeader('Content-Type', 'application/json')->withBody(Utils::streamFor($bytes));
        $request->getBody()->seek(3);
        try {
            $transport->sendRequest($request);
            self::fail('A partial cancellation body was admitted.');
        } catch (ProtocolException) {
            self::assertSame([], $provider->requests);
        }
        $request->getBody()->rewind();
        $provider->before = static function (RequestInterface $outgoing): void {
            self::assertSame(0, $outgoing->getBody()->tell());
        };
        $transport->sendRequest($request);
        self::assertCount(1, $provider->requests);
        $this->expectException(ProtocolException::class);
        $transport->sendRequest($request);
    }

    /** @return iterable<string,array{Response}> */
    public static function badResponses(): iterable
    {
        yield 'redirect' => [new Response(302, ['Location' => 'https://PRIVATE.test'])];
        yield 'long body' => [new Response(200, [], str_repeat('x', 65537))];
        yield 'long header' => [new Response(200, ['x-private' => str_repeat('x', 16385)])];
        yield 'long length' => [new Response(200, ['Content-Length' => '65537'])];
        yield 'duplicate length' => [new Response(200, ['Content-Length' => ['1', '1']])];
        yield 'duplicate identity' => [new Response(200, [], '{"id":"FOREIGN","id":"PRIVATE_INTENT"}')];
        yield 'duplicate items' => [new Response(200, [], '{"items":[{}],"items":[],"has_more":false}')];
        yield 'escaped items alias' => [new Response(200, [], '{"items":[{}],"it\\u0065ms":[],"has_more":false}')];
        yield 'compressed' => [new Response(200, ['Content-Encoding' => 'gzip'])];
    }

    #[DataProvider('badResponses')]
    public function testBoundedResponsesCloseAndDoNotExposeRawData(Response $response): void
    {
        $provider = $this->provider();
        $provider->alter = static fn (): ResponseInterface => $response;
        $transport = new KlarnaReconciliationTransport('PRIVATE_ACCOUNT', $provider);
        $transport->bindIntent('PRIVATE_INTENT');
        try {
            $transport->sendRequest($this->request());
            self::fail('Unbounded response was admitted.');
        } catch (ProtocolException $error) {
            self::assertStringNotContainsString('PRIVATE', (string) $error);
        }
        self::assertFalse($response->getBody()->isReadable());
        self::assertCount(1, $provider->requests);
    }

    public function testAbsoluteElapsedBudgetAndNonfiniteClockRejectBeforeSend(): void
    {
        $provider = $this->provider();
        $now = 1.0;
        $transport = new KlarnaReconciliationTransport(
            'PRIVATE_ACCOUNT',
            $provider,
            static function () use (&$now): float {
                return $now;
            }
        );
        $transport->bindIntent('PRIVATE_INTENT');
        $now = 121.0;
        try {
            $transport->sendRequest($this->request());
            self::fail('Expired transport was admitted.');
        } catch (ProtocolException) {
            self::assertSame([], $provider->requests);
        }
        $this->expectException(ProtocolException::class);
        new KlarnaReconciliationTransport('PRIVATE_ACCOUNT', $provider, static fn (): float => NAN);
    }

    public function testDebugAndSerializationNeverContainPrivateContext(): void
    {
        $transport = new KlarnaReconciliationTransport('PRIVATE_ACCOUNT', $this->provider());
        $transport->bindIntent('PRIVATE_INTENT');
        ob_start();
        var_dump($transport);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('PRIVATE', $debug);
        $this->expectException(ProtocolException::class);
        serialize($transport);
    }
    /** @return iterable<string,array{float,float}> */
    public static function closeExpiry(): iterable
    {
        yield 'original read window' => [0.0, 11.0];
        yield 'original global window' => [115.0, 121.0];
    }

    #[DataProvider('closeExpiry')]
    public function testLateStreamCloseCannotReturnAResponse(float $readStart, float $closeAt): void
    {
        $now = 0.0;
        $provider = $this->provider();
        $body = Utils::streamFor('{}');
        $closed = 0;
        $stream = FnStream::decorate($body, ['close' => static function () use (
            &$now,
            $closeAt,
            $body,
            &$closed
        ): void {
            $closed++;
            $body->close();
            $now = $closeAt;
        }]);
        $provider->before = static function () use (&$now, $readStart): void {
            $now = $readStart;
        };
        $provider->alter = static fn (): ResponseInterface => new Response(200, [], $stream);
        $transport = new KlarnaReconciliationTransport(
            'PRIVATE_ACCOUNT',
            $provider,
            static function () use (&$now): float {
                return $now;
            }
        );
        $transport->bindIntent('PRIVATE_INTENT');
        try {
            $transport->sendRequest($this->request());
            self::fail('Late response closure was accepted.');
        } catch (ProtocolException $error) {
            self::assertStringNotContainsString('PRIVATE', (string) $error);
        }
        self::assertSame(1, $closed);
        self::assertCount(1, $provider->requests);
    }
}
