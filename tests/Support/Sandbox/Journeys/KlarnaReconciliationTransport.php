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

/** Only one recorded Intent, its two zero-refund pages, and one separately authorized cancel. */
final class KlarnaReconciliationTransport implements ClientInterface
{
    private readonly ClientInterface $http;
    private readonly bool $native;
    /** @var Closure():float */
    private readonly Closure $clock;
    private readonly float $started;
    private ?string $intent = null;
    private ?string $cancelHash = null;
    private bool $cancelSent = false;
    private int $logins = 0;
    private int $reads = 0;
    private int $refundReads = 0;

    /** @param (Closure():float)|null $clock Monotonic fixture seam. */
    public function __construct(
        #[SensitiveParameter] private readonly string $account,
        ?ClientInterface $http = null,
        ?Closure $clock = null,
    ) {
        $this->native = $http === null;
        $this->http = $http ?? new Client(['handler' => new StreamHandler(), 'stream' => true,
            'verify' => true, 'cookies' => false, 'allow_redirects' => false, 'http_errors' => false,
            'decode_content' => false, 'read_timeout' => 5, 'timeout' => 30, 'connect_timeout' => 10]);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->started = ($this->clock)();
        $this->deadline($this->started);
    }

    public function bindIntent(#[SensitiveParameter] string $id): void
    {
        if ($this->intent !== null || preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $id) !== 1) {
            throw new ProtocolException('One recorded safe Intent identity is required.');
        }
        $this->intent = $id;
    }

    public function authorizeCancel(#[SensitiveParameter] string $wireHash): void
    {
        if ($this->intent === null || $this->cancelHash !== null || preg_match('/^[a-f0-9]{64}$/D', $wireHash) !== 1) {
            throw new ProtocolException('One durable cancellation body is required.');
        }
        $this->cancelHash = $wireHash;
    }

    /** @throws ProtocolException
     * @throws TransportException
     */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $this->deadline(($this->clock)());
        $uri = $request->getUri();
        $path = $uri->getPath();
        $method = $request->getMethod();
        if (
            $this->intent === null || $uri->getScheme() !== 'https'
            || $uri->getHost() !== 'api.sandbox.airwallex.com' || $uri->getPort() !== null
            || $uri->getUserInfo() !== '' || $uri->getFragment() !== ''
            || $request->hasHeader('Cookie') || $request->hasHeader('Referer') || $request->hasHeader('x-on-behalf-of')
        ) {
            throw new ProtocolException('Only the bound account Sandbox reconciliation is permitted.');
        }
        $login = $method === 'POST' && $path === '/api/v1/authentication/login';
        $this->headers($request, $login);
        if (($login || $method === 'GET') && $request->getBody()->getSize() !== 0) {
            throw new ProtocolException('Reconciliation reads and login must have empty bodies.');
        }
        if ($login) {
            $allowed = $uri->getQuery() === '' && ++$this->logins === 1
                && $request->getHeader('x-login-as') === [$this->account];
        } elseif ($method === 'GET' && $path === '/api/v1/pa/payment_intents/' . $this->intent) {
            $allowed = $uri->getQuery() === '' && ++$this->reads <= 9;
        } elseif ($method === 'GET' && $path === '/api/v1/pa/refunds') {
            $allowed = $uri->getQuery() === http_build_query(['payment_intent_id' => $this->intent,
                'page_num' => 0, 'page_size' => 100], '', '&', PHP_QUERY_RFC3986) && ++$this->refundReads <= 2;
        } else {
            $allowed = $method === 'POST' && $path === '/api/v1/pa/payment_intents/' . $this->intent . '/cancel'
                && $uri->getQuery() === '' && !$this->cancelSent && $this->cancelHash !== null
                && hash_equals($this->cancelHash, $this->bodyHash($request));
            if ($allowed) {
                $this->cancelSent = true; // Before invocation, including an ambiguous transport failure.
            }
        }
        if (!$allowed || (!$login && $request->hasHeader('x-login-as'))) {
            throw new ProtocolException('A reconciliation route, identity or finite budget did not match.');
        }
        try {
            $remaining = max(0.001, 120 - (($this->clock)() - $this->started));
            $response = $this->native && $this->http instanceof Client
                ? $this->http->send($request, ['timeout' => min(30, $remaining),
                    'connect_timeout' => min(10, $remaining), 'read_timeout' => min(5, $remaining)])
                : $this->http->sendRequest($request);
        } catch (\Throwable) {
            throw new TransportException('Reconciliation request has no usable response.');
        }
        try {
            $stream = $response->getBody();
            $readStarted = ($this->clock)();
            try {
                $bytes = 0;
                foreach ($response->getHeaders() as $name => $values) {
                    $bytes += strlen($name) + strlen(implode('', $values));
                }
                $length = $response->getHeader('Content-Length');
                $encoding = $response->getHeader('Content-Encoding');
                if (
                    $response->getStatusCode() >= 300 && $response->getStatusCode() < 400
                    || $bytes > 16384 || count($length) > 1
                    || ($length !== [] && (preg_match('/^[0-9]+$/D', $length[0]) !== 1
                        || strlen(ltrim($length[0], '0')) > 5 || (strlen(ltrim($length[0], '0')) === 5
                        && strcmp(ltrim($length[0], '0'), '65536') > 0)))
                    || ($encoding !== [] && $encoding !== ['identity'])
                ) {
                    throw new ProtocolException('The reconciliation response is outside its bound.');
                }
                $body = '';
                do {
                    $this->deadline($readStarted);
                    $part = $stream->read(min(8192, 65537 - strlen($body)));
                    $this->deadline($readStarted);
                    if ($part === '') {
                        if (!$stream->eof()) {
                            throw new ProtocolException('The reconciliation response made no progress.');
                        }
                        break;
                    }
                    $body .= $part;
                } while (!$stream->eof() && strlen($body) <= 65536);
                if (strlen($body) > 65536) {
                    throw new ProtocolException('The reconciliation response exceeds its bound.');
                }
                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    $native = KlarnaIntentReconciliation::assertUniqueJsonObject($body);
                    if ($login) {
                        KlarnaIntentReconciliation::assertLiteralStrings($native, ['token', 'expires_at']);
                    } elseif ($path !== '/api/v1/pa/refunds') {
                        KlarnaIntentReconciliation::assertLiteralStrings($native, ['id', 'status',
                            'currency', 'merchant_order_id', 'return_url']);
                    }
                }
                return $response->withBody(Utils::streamFor($body));
            } finally {
                $stream->close();
                $this->deadline($readStarted);
            }
        } catch (\Throwable) {
            throw new ProtocolException('Reconciliation response could not be safely observed.');
        }
    }

    private function headers(RequestInterface $request, bool $login): void
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values;
        }
        $allowed = ['host', 'accept', 'x-api-version', ...($login ? ['x-client-id', 'x-api-key', 'x-login-as']
            : ['authorization', ...($request->getMethod() === 'POST' ? ['content-type'] : [])])];
        if (
            array_diff(array_keys($headers), $allowed) !== []
            || ($headers['host'] ?? null) !== ['api.sandbox.airwallex.com']
            || ($headers['accept'] ?? null) !== ['application/json']
            || ($headers['x-api-version'] ?? null) !== ['2026-08-21']
            || (!$login && (count($headers['authorization'] ?? []) !== 1
                || !str_starts_with($headers['authorization'][0], 'Bearer ')))
            || (!$login && $request->getMethod() === 'POST'
                && ($headers['content-type'] ?? null) !== ['application/json'])
        ) {
            throw new ProtocolException('Only the selected SDK account headers are permitted.');
        }
    }

    private function bodyHash(RequestInterface $request): string
    {
        try {
            $stream = $request->getBody();
            $size = $stream->getSize();
            if (!$stream->isSeekable() || $size === null || $size > 4096) {
                throw new ProtocolException('A bounded seekable cancellation body is required.');
            }
            $position = $stream->tell();
            if ($position !== 0) {
                throw new ProtocolException('Cancellation bytes must start at their recorded boundary.');
            }
            try {
                $stream->rewind();
                return hash('sha256', $stream->getContents());
            } finally {
                $stream->seek($position);
            }
        } catch (\Throwable) {
            throw new ProtocolException('The authorized cancellation bytes could not be verified.');
        }
    }

    private function deadline(float $readStarted): void
    {
        $now = ($this->clock)();
        if (
            !is_finite($now) || !is_finite($this->started) || !is_finite($readStarted)
            || $now < $readStarted || $now < $this->started || $now - $readStarted >= 10 || $now - $this->started >= 120
        ) {
            throw new ProtocolException('Reconciliation exceeded its fixed deadline.');
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['transport' => '[private bound reconciliation]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Private reconciliation transports cannot be serialized.');
    }
}
