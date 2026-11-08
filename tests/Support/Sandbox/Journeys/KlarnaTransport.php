<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\TransportException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use Throwable;

/** Closed test-only streaming operations; anonymous capability reads have a separate instance. */
final class KlarnaTransport implements ClientInterface
{
    private readonly ClientInterface $http;
    /** @var Closure():float */
    private readonly Closure $clock;
    private int $requests = 0;

    /**
     * @param ClientInterface|null $http Fixture/already-streaming seam; arbitrary clients may prebuffer.
     * @param (Closure():float)|null $clock Monotonic seconds.
     */
    public function __construct(
        ?ClientInterface $http = null,
        #[SensitiveParameter] private readonly ?string $redirect = null,
        ?Closure $clock = null,
    ) {
        if (
            $redirect !== null && (!AlipayRedirect::eligible($redirect)
            || preg_match('~^/pa/redirect/(?:hk|sg)/[A-Za-z0-9_-]+$~D', parse_url($redirect, PHP_URL_PATH) ?: '') !== 1)
        ) {
            throw new ProtocolException('A verified Klarna Sandbox redirect is required.');
        }
        $this->http = $http ?? new Client([
            'handler' => new StreamHandler(), 'stream' => true, 'verify' => true,
            'cookies' => false, 'allow_redirects' => false, 'http_errors' => false, 'decode_content' => false,
            'read_timeout' => 5, 'timeout' => 30, 'connect_timeout' => 10,
        ]);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $anonymous = $this->redirect !== null;
        $allowed = $anonymous ? (string) $uri === $this->redirect && $request->getMethod() === 'GET'
            && $this->requests === 0 : match ($request->getMethod()) {
                'POST' => in_array($path, ['/api/v1/authentication/login', '/api/v1/pa/payment_intents/create',
                    '/api/v1/pa/refunds/create', '/api/v1/simulation/pa/shopper_actions/pay'], true)
                    || preg_match('~^/api/v1/pa/payment_intents/[A-Za-z0-9_-]+/(confirm|capture|cancel)$~D',
                        $path) === 1,
                'GET' => preg_match('~^/api/v1/pa/(payment_intents|refunds)/[A-Za-z0-9_-]+$~D', $path) === 1,
                default => false,
            };
        if (
            !$allowed || ++$this->requests > 64 || $uri->getScheme() !== 'https'
            || $uri->getHost() !== 'api.sandbox.airwallex.com' || $uri->getPort() !== null
            || $uri->getUserInfo() !== '' || $uri->getFragment() !== '' || (!$anonymous && $uri->getQuery() !== '')
            || $request->hasHeader('Cookie') || $request->hasHeader('Referer')
        ) {
            throw new ProtocolException('Only the bounded Klarna Sandbox operations are permitted.');
        }
        if ($anonymous) {
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers[strtolower($name)] = $values;
            }
            if (
                count($headers) !== 2 || ($headers['host'] ?? null) !== ['api.sandbox.airwallex.com']
                || ($headers['accept'] ?? null) !== ['text/html, application/xhtml+xml']
                || $request->getBody()->getSize() !== 0
            ) {
                throw new ProtocolException('The customer redirect requires credential-free fixed headers.');
            }
        }
        try {
            $response = $this->http->sendRequest($request);
        } catch (Throwable) {
            throw new TransportException('Klarna Sandbox request failed without a usable response.');
        }
        try {
            $stream = $response->getBody();
            try {
                $started = ($this->clock)();
                $headerBytes = 0;
                foreach ($response->getHeaders() as $name => $values) {
                    $headerBytes += strlen($name) + strlen(implode('', $values));
                }
                $encoding = $response->getHeader('Content-Encoding');
                if ($headerBytes > 16384 || ($encoding !== [] && $encoding !== ['identity'])) {
                    throw new ProtocolException('The response headers exceed the observation contract.');
                }
                $limit = $path === '/api/v1/simulation/pa/shopper_actions/pay'
                    && $response->getStatusCode() === 200 ? 0 : 65536;
                $body = '';
                while (strlen($body) <= $limit) {
                    $this->deadline($started);
                    $part = $stream->read(min(8192, $limit + 1 - strlen($body)));
                    $this->deadline($started);
                    if ($part === '') {
                        if ($stream->eof()) {
                            break;
                        }
                        throw new ProtocolException('The response made no bounded progress.');
                    }
                    $body .= $part;
                    if ($stream->eof()) {
                        break;
                    }
                }
                if (strlen($body) > $limit) {
                    throw new ProtocolException('The response exceeded its private observation bound.');
                }
                $bounded = $response->withBody(Utils::streamFor($body));
            } finally {
                $stream->close();
            }
            $this->deadline($started);
            return $bounded;
        } catch (Throwable) {
            throw new ProtocolException('Klarna response could not be safely observed.');
        }
    }

    private function deadline(float $started): void
    {
        $now = ($this->clock)();
        if (!is_finite($now) || !is_finite($started) || $now < $started || $now - $started >= 10) {
            throw new ProtocolException('Klarna response exceeded its observation deadline.');
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['http' => '[redacted]', 'redirect' => '[private]', 'clock' => '[redacted]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Private Klarna transports cannot be serialized.');
    }
}
