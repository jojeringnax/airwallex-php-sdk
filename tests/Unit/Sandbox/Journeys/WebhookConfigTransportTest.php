<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\TransportException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookConfigTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class WebhookConfigTransportTest extends TestCase
{
    private const string URL = 'https://api.sandbox.airwallex.com/api/v1/webhooks';

    public function testDefaultReallyUsesStreamHandlerWithTlsAndReadTimeout(): void
    {
        $http = new ReflectionProperty(WebhookConfigTransport::class, 'http');
        $client = $http->getValue(new WebhookConfigTransport());
        self::assertInstanceOf(Client::class, $client);
        self::assertInstanceOf(StreamHandler::class, $client->getConfig('handler'));
        foreach (
            ['stream' => true, 'verify' => true, 'cookies' => false,
            'allow_redirects' => false, 'http_errors' => false, 'read_timeout' => 5,
            'timeout' => 30, 'connect_timeout' => 10] as $name => $value
        ) {
            self::assertSame($value, $client->getConfig($name));
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function foreignRequests(): iterable
    {
        yield 'production' => ['GET', 'https://api.airwallex.com/api/v1/webhooks'];
        yield 'lookalike' => ['GET', 'https://api.sandbox.airwallex.com.example.test/api/v1/webhooks'];
        yield 'userinfo' => ['GET', 'https://private@api.sandbox.airwallex.com/api/v1/webhooks'];
        yield 'port' => ['GET', 'https://api.sandbox.airwallex.com:444/api/v1/webhooks'];
        yield 'http' => ['GET', 'http://api.sandbox.airwallex.com/api/v1/webhooks'];
        yield 'query' => ['GET', self::URL . '?page=PRIVATE_CURSOR'];
        yield 'fragment' => ['GET', self::URL . '#PRIVATE_FRAGMENT'];
        yield 'identity' => ['GET', self::URL . '/PRIVATE_ID'];
        yield 'write' => ['POST', self::URL . '/create'];
        yield 'delete' => ['DELETE', self::URL];
        yield 'login get' => ['GET', 'https://api.sandbox.airwallex.com/api/v1/authentication/login'];
    }

    #[DataProvider('foreignRequests')]
    public function testOtherOperationsRejectBeforeSend(string $method, string $url): void
    {
        $client = new WebhookConfigFixtureClient([]);
        try {
            (new WebhookConfigTransport($client))->sendRequest(new Request($method, $url));
            self::fail('A non-fixed operation was accepted.');
        } catch (ProtocolException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('PRIVATE', (string) $error);
        }
        self::assertSame([], $client->requests);
    }

    /** @return iterable<string,array{array<string,string>,string}> */
    public static function forbiddenRequestData(): iterable
    {
        yield 'body' => [[], 'SYNTHETIC_PRIVATE_BODY'];
        yield 'OBO' => [['x-on-behalf-of' => 'SYNTHETIC_PRIVATE_ACCOUNT'], ''];
        yield 'cookie' => [['Cookie' => 'SYNTHETIC_PRIVATE_COOKIE'], ''];
        yield 'referer' => [['Referer' => 'https://SYNTHETIC_PRIVATE.test'], ''];
        yield 'login header on read' => [['x-login-as' => 'SYNTHETIC_PRIVATE_ACCOUNT'], ''];
    }

    /** @param array<string,string> $headers */
    #[DataProvider('forbiddenRequestData')]
    public function testRequestHasNoBodyDelegationCookiesOrReferer(array $headers, string $body): void
    {
        $client = new WebhookConfigFixtureClient([]);
        try {
            (new WebhookConfigTransport($client))->sendRequest(new Request('GET', self::URL, $headers, $body));
            self::fail('Unexpected request data was sent.');
        } catch (ProtocolException) {
            self::assertSame([], $client->requests);
        }
    }

    public function testOneLoginOneGetWithoutAutomaticRedirectOrRetry(): void
    {
        $client = new WebhookConfigFixtureClient([new Response(201, [], '{}'),
            new Response(302, ['Location' => 'https://PRIVATE_RECEIVER.test'], 'PRIVATE_BODY')]);
        $http = new WebhookConfigTransport($client);
        $http->sendRequest(new Request('POST', 'https://api.sandbox.airwallex.com/api/v1/authentication/login'));
        $response = $http->sendRequest(new Request('GET', self::URL));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('PRIVATE_BODY', (string) $response->getBody());
        foreach (
            ['GET' => self::URL,
            'POST' => 'https://api.sandbox.airwallex.com/api/v1/authentication/login'] as $method => $url
        ) {
            try {
                $http->sendRequest(new Request($method, $url));
                self::fail('An observation was repeated.');
            } catch (ProtocolException) {
                self::assertCount(2, $client->requests);
            }
        }
    }

    public function testTransportFailureIsRedactedAndCannotResend(): void
    {
        $client = new WebhookConfigFixtureClient([new RuntimeException('SYNTHETIC_PRIVATE_FAILURE')]);
        $http = new WebhookConfigTransport($client);
        try {
            $http->sendRequest(new Request('GET', self::URL));
            self::fail('The transport failure was ignored.');
        } catch (TransportException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', (string) $error);
        }
        try {
            $http->sendRequest(new Request('GET', self::URL));
            self::fail('The failed send was repeated.');
        } catch (ProtocolException) {
            self::assertCount(1, $client->requests);
        }
    }

    public function testIncrementalExactBoundaryCopyClosesOriginalWithoutStringification(): void
    {
        $stream = new WebhookConfigFixtureStream(str_repeat('x', 65536), chunk: 701);
        $client = new WebhookConfigFixtureClient([new Response(200, [], $stream)]);
        $response = (new WebhookConfigTransport($client))->sendRequest(new Request('GET', self::URL));
        self::assertSame(str_repeat('x', 65536), (string) $response->getBody());
        self::assertSame(65536, $stream->bytesRead);
        self::assertTrue($stream->closed);
        self::assertGreaterThan(1, $stream->reads);
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function streamFailures(): iterable
    {
        yield 'oversized read success' => ['normal', 200, str_repeat('x', 100000)];
        yield 'oversized login success' => ['normal', 201, str_repeat('x', 100000)];
        yield 'oversized error' => ['normal', 403, str_repeat('x', 100000)];
        yield 'zero progress' => ['stalled', 200, 'secret'];
        yield 'read exception' => ['read-failure', 200, 'secret'];
        yield 'close exception' => ['close-failure', 200, 'secret'];
    }

    #[DataProvider('streamFailures')]
    public function testUnsafeStreamsFailClosedAndClose(string $mode, int $status, string $body): void
    {
        $stream = new WebhookConfigFixtureStream($body, $mode);
        $client = new WebhookConfigFixtureClient([new Response($status, [], $stream)]);
        try {
            (new WebhookConfigTransport($client))->sendRequest(new Request('GET', self::URL));
            self::fail('An unsafe stream was accepted.');
        } catch (ProtocolException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', (string) $error);
        }
        self::assertTrue($stream->closed);
        self::assertLessThanOrEqual(65537, $stream->bytesRead);
        self::assertCount(1, $client->requests);
    }

    public function testSlowPositiveProgressHitsElapsedDeadline(): void
    {
        $clock = 0.0;
        $stream = new WebhookConfigFixtureStream(str_repeat('x', 100), chunk: 1);
        $http = new WebhookConfigTransport(
            new WebhookConfigFixtureClient([new Response(200, [], $stream)]),
            static function () use (&$clock): float {
                return $clock += 2;
            }
        );
        try {
            $http->sendRequest(new Request('GET', self::URL));
            self::fail('Positive but slow reads were unbounded.');
        } catch (ProtocolException) {
            self::assertGreaterThan(0, $stream->bytesRead);
            self::assertLessThan(100, $stream->bytesRead);
            self::assertTrue($stream->closed);
        }
    }

    public function testCloseTimeIsIncludedAndPrivateClientIsNotInspectableOrSerializable(): void
    {
        $calls = 0;
        $stream = new WebhookConfigFixtureStream('x');
        $http = new WebhookConfigTransport(
            new WebhookConfigFixtureClient([new Response(200, [], $stream)]),
            static function () use (&$calls): float {
                return ++$calls >= 4 ? 11.0 : 0.0;
            }
        );
        try {
            $http->sendRequest(new Request('GET', self::URL));
            self::fail('Close elapsed time was ignored.');
        } catch (ProtocolException) {
            self::assertTrue($stream->closed);
        }
        ob_start();
        var_dump($http);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('WebhookConfigFixtureClient', $debug);
        self::assertStringNotContainsString('requests', $debug);
        $this->expectException(ProtocolException::class);
        serialize($http);
    }
}
