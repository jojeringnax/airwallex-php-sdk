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

/** A genuinely streaming default, allowing only fixed authentication and the single bare config GET. */
final class WebhookConfigTransport implements ClientInterface
{
    private readonly ClientInterface $http;
    private bool $readSent = false;
    private bool $loginSent = false;
    /** @var Closure():float */
    private readonly Closure $clock;

    /**
     * @param ClientInterface|null $http Fixture or already-streaming seam; an arbitrary client may prebuffer.
     * @param (Closure():float)|null $clock Monotonic seconds for deterministic deadline tests.
     */
    public function __construct(
        ?ClientInterface $http = null,
        ?Closure $clock = null,
        private readonly WebhookConfigProfile $profile = WebhookConfigProfile::Account,
    ) {
        $this->http = $http ?? new Client([
            'handler' => new StreamHandler(), 'stream' => true, 'read_timeout' => 5,
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
            'allow_redirects' => false, 'http_errors' => false, 'cookies' => false,
        ]);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    public static function assertAvailable(): void
    {
        if (
            !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)
            || !in_array('https', stream_get_wrappers(), true)
        ) {
            throw new ProtocolException('Streaming Sandbox observation is unavailable.');
        }
    }

    /** @throws ProtocolException|TransportException */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $login = $request->getMethod() === 'POST' && $uri->getPath() === '/api/v1/authentication/login';
        $read = $request->getMethod() === 'GET' && $uri->getPath() === '/api/v1/webhooks';
        if (
            (!$login && !$read) || $uri->getScheme() !== 'https'
            || $uri->getHost() !== 'api.sandbox.airwallex.com' || $uri->getPort() !== null
            || $uri->getUserInfo() !== '' || $uri->getQuery() !== '' || $uri->getFragment() !== ''
            || $request->getBody()->getSize() !== 0 || $request->hasHeader('x-on-behalf-of')
            || $request->hasHeader('Cookie') || $request->hasHeader('Referer')
            || ($read && $request->hasHeader('x-login-as'))
        ) {
            throw new ProtocolException('Webhook configuration transport requires its fixed Sandbox operations.');
        }
        if ($this->profile === WebhookConfigProfile::DefaultLogin) {
            $this->defaultHeaders($request, $login);
            if ($read && !$this->loginSent) {
                throw new ProtocolException('The default-login read requires its separate authentication exchange.');
            }
        }
        if (($read && $this->readSent) || ($login && $this->loginSent)) {
            throw new ProtocolException('Webhook configuration operations cannot be repeated in this observation.');
        }
        $this->readSent = $this->readSent || $read;
        $this->loginSent = $this->loginSent || $login;
        try {
            $response = $this->http->sendRequest($request);
        } catch (Throwable) {
            throw new TransportException('Webhook configuration request failed without a usable response.');
        }
        try {
            $stream = $response->getBody();
            try {
                $started = ($this->clock)();
                $body = '';
                while (strlen($body) <= 65536) {
                    $this->deadline($started);
                    $part = $stream->read(min(8192, 65537 - strlen($body)));
                    $this->deadline($started);
                    if ($part === '') {
                        if ($stream->eof()) {
                            break;
                        }
                        throw new ProtocolException('The private response made no bounded progress.');
                    }
                    $body .= $part;
                    if ($stream->eof()) {
                        break;
                    }
                }
                if (strlen($body) > 65536) {
                    throw new ProtocolException('The private response exceeded its observation boundary.');
                }
                $bounded = $response->withBody(Utils::streamFor($body));
            } finally {
                $stream->close();
            }
            $this->deadline($started);
            if ($this->profile === WebhookConfigProfile::DefaultLogin && $login && $response->getStatusCode() === 201) {
                $proof = WebhookConfigObservation::nativeObject($body);
                if (!is_string($proof->token ?? null) || !is_string($proof->expires_at ?? null)) {
                    throw new ProtocolException('The default login did not return its native string contract.');
                }
                $this->deadline($started);
            }
            return $bounded;
        } catch (Throwable) {
            throw new ProtocolException('Webhook configuration response could not be safely observed.');
        }
    }

    private function defaultHeaders(#[SensitiveParameter] RequestInterface $request, bool $login): void
    {
        $allowed = ['host', 'accept', 'x-api-version', ...($login ? ['x-client-id', 'x-api-key'] : ['authorization'])];
        foreach (array_keys($request->getHeaders()) as $name) {
            if (!in_array(strtolower($name), $allowed, true) || count($request->getHeader($name)) !== 1) {
                throw new ProtocolException('Default login rejects alternate authentication or context headers.');
            }
        }
        if (
            $request->getHeaderLine('Accept') !== 'application/json'
            || $request->getHeaderLine('x-api-version') !== '2026-08-21'
            || ($login && ($request->getHeaderLine('x-client-id') === ''
                || $request->getHeaderLine('x-api-key') === ''))
            || (!$login && preg_match('/^Bearer [^\x00-\x20\x7f]+$/D', $request->getHeaderLine('Authorization')) !== 1)
        ) {
            throw new ProtocolException('The default-login profile requires its fixed credential/token header roles.');
        }
    }

    private function deadline(float $started): void
    {
        $now = ($this->clock)();
        if (!is_finite($started) || !is_finite($now) || $now < $started || $now - $started >= 10) {
            throw new ProtocolException('The private response exceeded its observation deadline.');
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['http' => '[redacted]', 'clock' => '[redacted]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Webhook configuration transports cannot be serialized.');
    }
}
