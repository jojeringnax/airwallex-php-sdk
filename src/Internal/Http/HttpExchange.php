<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Internal\Http;

use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Exception\TransportException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/** @internal */
final readonly class HttpExchange
{
    public function __construct(
        private Environment $environment,
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['httpClient' => '[redacted]', 'requestFactory' => '[redacted]', 'streamFactory' => '[redacted]'];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new ConfigurationException('Configured HTTP exchanges cannot be serialized.');
    }

    /** @param array<string, string> $headers */
    public function send(
        string $method,
        string $path,
        #[SensitiveParameter] array $headers,
        #[SensitiveParameter] ?string $body = null,
    ): ResponseInterface {
        try {
            $request = $this->requestFactory->createRequest($method, $this->environment->baseUrl() . $path);
            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            if ($body !== null) {
                $request = $request->withBody($this->streamFactory->createStream($body));
            }
            return $this->httpClient->sendRequest($request);
        } catch (\Throwable) {
            // The original exception may contain a request with credentials or a sensitive response.
            throw new TransportException('Airwallex request failed without a usable HTTP response.');
        }
    }
}
