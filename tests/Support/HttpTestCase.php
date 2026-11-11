<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support;

use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\RetryPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\SimpleCache\CacheInterface;

/** @internal */
abstract class HttpTestCase extends TestCase
{
    /** @param list<array{int, string}|\Throwable> $responses */
    protected function scenario(array $responses): HttpScenario
    {
        $http = $this->createStub(ClientInterface::class);
        $requests = $this->createStub(RequestFactoryInterface::class);
        $streams = $this->createStub(StreamFactoryInterface::class);
        $scenario = new HttpScenario($responses, $http, $requests, $streams);
        $streams->method('createStream')->willReturnCallback(
            fn (string $content = ''): StreamInterface => $this->stream($content),
        );
        $requests->method('createRequest')->willReturnCallback(function (
            string $method,
            string $uri,
        ): RequestInterface {
            $request = $this->createStub(RequestInterface::class);
            $headers = [];
            $body = $this->stream('');
            $uriObject = $this->createStub(UriInterface::class);
            $uriObject->method('__toString')->willReturn($uri);
            $request->method('getMethod')->willReturn($method);
            $request->method('getUri')->willReturn($uriObject);
            $request->method('withHeader')->willReturnCallback(static function (
                string $name,
                string $value,
            ) use (
                &$headers,
                $request
): RequestInterface {
                $headers[strtolower($name)] = $value;
                return $request;
            });
            $request->method('getHeaderLine')->willReturnCallback(static function (
                string $name,
            ) use (&$headers): string {
                return $headers[strtolower($name)] ?? '';
            });
            $request->method('withBody')->willReturnCallback(static function (
                StreamInterface $value,
            ) use (
                &$body,
                $request
): RequestInterface {
                $body = $value;
                return $request;
            });
            $request->method('getBody')->willReturnCallback(static function () use (&$body): StreamInterface {
                return $body;
            });
            return $request;
        });
        $http->method('sendRequest')->willReturnCallback(function (
            RequestInterface $request,
        ) use ($scenario): ResponseInterface {
            $scenario->requests[] = $request;
            $next = array_shift($scenario->responses);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            if ($next === null) {
                self::fail('Unexpected network request.');
            }
            $response = $this->createStub(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn($next[0]);
            $response->method('getBody')->willReturn($this->stream($next[1]));
            return $response;
        });
        return $scenario;
    }

    protected function stream(string $content): StreamInterface
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($content);
        return $stream;
    }

    protected function client(
        HttpScenario $http,
        Environment $environment = Environment::Sandbox,
        ?string $loginAccountId = null,
        ?\Closure $clock = null,
        ?RetryPolicy $retryPolicy = null,
        ?CacheInterface $cache = null,
        ?Credentials $credentials = null,
        bool $preserveResponseObjects = false,
    ): Client {
        return new Client(
            environment: $environment,
            credentials: $credentials ?? new Credentials('test_client_id', 'test_api_key'),
            httpClient: $http->client,
            requestFactory: $http->requestFactory,
            streamFactory: $http->streamFactory,
            loginAccountId: $loginAccountId,
            tokenCache: $cache,
            clock: $clock ?? static fn (): int => 100,
            retryPolicy: $retryPolicy ?? new RetryPolicy(maxAttempts: 1),
            preserveResponseObjects: $preserveResponseObjects,
        );
    }
}
