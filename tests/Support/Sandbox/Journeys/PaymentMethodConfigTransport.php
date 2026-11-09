<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use Throwable;

/** Two isolated roles: one ACCOUNT login and one fixed configuration page. */
final class PaymentMethodConfigTransport implements ClientInterface
{
    public const string PATH = '/api/v1/pa/config/payment_method_types';
    public const string QUERY = 'active=true&country_code=HK&page_num=0&page_size=100'
        . '&transaction_currency=HKD&transaction_mode=recurring';
    public const string TARGET = 'https://api.sandbox.airwallex.com' . self::PATH . '?' . self::QUERY;
    private readonly ClientInterface $http;
    /** @var Closure():float */
    private readonly Closure $clock;
    /** @var Closure():int */
    private readonly Closure $unixClock;
    private readonly float $started;
    private ?float $lastClock = null;
    private readonly string $account;
    /** @var array<string,string> */
    private readonly array $credentials;
    private ?RunLedger $ledger = null;
    private ?Closure $gate = null;
    private string $context = '';
    private bool $poisoned = false;
    private int $logins = 0;
    private int $reads = 0;
    private int $possible = 0;
    private int $invocations = 0;
    private int $bytes = 0;
    private ?int $status = null;
    private string $token = '';
    private int $expiry = 0;
    private int $lastUnix = 0;
    private ?string $page = null;

    /**
     * @param (Closure():float)|null $clock Monotonic test seam, never a CLI selector.
     * @param (Closure():int)|null $unixClock Token-expiration seam, not a timeout clock.
     */
    public function __construct(
        #[SensitiveParameter] Configuration $configuration,
        ?ClientInterface $http = null,
        ?Closure $clock = null,
        ?float $started = null,
        ?Closure $unixClock = null,
    ) {
        $this->account = AlipayConsentReuseTransport::identity($configuration->accountId());
        $this->credentials = $configuration->credentials()->headers();
        $this->http = $http ?? new Client(['handler' => new StreamHandler(), 'stream' => true,
            'verify' => true, 'cookies' => false, 'allow_redirects' => false, 'http_errors' => false,
            'decode_content' => false, 'proxy' => '', 'timeout' => 10, 'read_timeout' => 5]);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->unixClock = $unixClock ?? time(...);
        $this->started = $started ?? ($this->clock)();
        $this->assertTime();
    }

    /** @param Closure():void $gate Source, tool, credential and context proof after durability. */
    public function bind(RunLedger $ledger, string $context, Closure $gate): void
    {
        if ($this->ledger !== null) {
            throw new ProtocolException('A configuration binding is once-only.');
        }
        $this->ledger = $ledger;
        $this->context = $context;
        $this->gate = $gate;
        $this->proof();
    }

    public function assertTime(?float $responseStarted = null): void
    {
        $this->sampleTime($responseStarted);
    }

    private function sampleTime(?float $responseStarted = null): float
    {
        $now = ($this->clock)();
        if (
            !is_finite($now) || !is_finite($this->started) || $now < $this->started
            || ($this->lastClock !== null && $now < $this->lastClock) || $now - $this->started >= 120
            || ($responseStarted !== null && (!is_finite($responseStarted)
                || $now < $responseStarted || $now - $responseStarted >= 10))
        ) {
            $this->poison();
            throw new ProtocolException('The original configuration observation deadline expired.');
        }
        $this->lastClock = $now;
        return $now;
    }

    public function poison(): void
    {
        $this->poisoned = true;
        $this->page = null;
    }

    public function page(): string
    {
        $this->proof();
        if ($this->page === null) {
            throw new ProtocolException('A current configuration page is unavailable.');
        }
        return $this->page;
    }

    /** @return array<string,int|bool|null> */
    public function report(): array
    {
        return ['http_invocations' => $this->invocations, 'login_attempts' => $this->logins,
            'configuration_get_attempts' => $this->reads, 'possible_invocations' => $this->possible,
            'response_bytes' => $this->bytes, 'last_http_status' => $this->status,
            'transport_poisoned' => $this->poisoned];
    }

    /** @throws ProtocolException A failed role, original proof, durability or response permanently closes this run. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        try {
            $this->proof();
            $body = $request->getBody();
            if (!$body->isReadable() || !$body->isSeekable() || $body->tell() !== 0 || $body->getSize() !== 0) {
                throw new ProtocolException('Only the fixed empty request body is admitted.');
            }
            try {
                if ($body->read(1) !== '' || !$body->eof()) {
                    throw new ProtocolException('The empty request body changed.');
                }
            } finally {
                $body->seek(0);
            }
            $prepared = new Request(
                $request->getMethod(),
                (string) $request->getUri(),
                $request->getHeaders(),
                '',
                $request->getProtocolVersion()
            );
            $role = $this->admit($prepared);
            $facts = ['method' => $prepared->getMethod(), 'protocol' => $prepared->getProtocolVersion(),
                'target_sha256' => hash('sha256', (string) $prepared->getUri()),
                'prepared_headers_sha256' => hash('sha256', json_encode($prepared->getHeaders(), JSON_THROW_ON_ERROR)),
                'body_sha256' => hash('sha256', '')];
            $this->note($role . '_prepared', $facts);
            ++$this->possible;
            $this->note($role . '_possible_invocation', $facts + ['ordinal' => (string) $this->possible]);
            $this->proof();
            if ($role === 'page') {
                $this->tokenValid();
            }
            // One final sample supplies the native options; no zero/default-unlimited timeout.
            $now = $this->sampleTime();
            $remaining = floor(120 - ($now - $this->started));
            if ($remaining < 1 || $this->invocations >= 2) {
                throw new ProtocolException('The remaining native invocation budget is unavailable.');
            }
            ++$this->invocations;
            $response = $this->http instanceof Client ? $this->http->send($prepared, [
                'stream' => true, 'verify' => true, 'allow_redirects' => false, 'cookies' => false,
                'http_errors' => false, 'decode_content' => false, 'proxy' => '',
                'timeout' => min(10, $remaining), 'connect_timeout' => min(10, $remaining),
                'read_timeout' => min(5, $remaining),
            ]) : $this->http->sendRequest($prepared);
            $result = $this->response($response, $role, $now);
            $this->proof();
            return $result;
        } catch (Throwable) {
            $this->poisoned = true;
            throw new ProtocolException('The bounded configuration request or durable proof failed.');
        }
    }

    private function proof(): void
    {
        $this->assertTime();
        if ($this->poisoned || $this->ledger === null || $this->gate === null) {
            throw new ProtocolException('The private configuration binding is unavailable.');
        }
        ($this->gate)();
        $this->ledger->assertContext($this->context);
        $this->assertTime();
    }

    private function admit(#[SensitiveParameter] RequestInterface $request): string
    {
        $target = (string) $request->getUri();
        $login = $request->getMethod() === 'POST'
            && $target === 'https://api.sandbox.airwallex.com/api/v1/authentication/login';
        $page = $request->getMethod() === 'GET' && $target === self::TARGET;
        if ((!$login && !$page) || $request->getProtocolVersion() !== '1.1') {
            throw new ProtocolException('Only the exact two Sandbox request roles are admitted.');
        }
        $this->headers($request, $login);
        if ($login && $this->logins === 0 && $this->reads === 0) {
            ++$this->logins;
            return 'login';
        }
        if ($page && $this->logins === 1 && $this->reads === 0) {
            $this->tokenValid();
            ++$this->reads;
            return 'page';
        }
        throw new ProtocolException('No retry, refresh or second page is admitted.');
    }

    private function headers(#[SensitiveParameter] RequestInterface $request, bool $login): void
    {
        $expected = ['host' => 'api.sandbox.airwallex.com', 'accept' => 'application/json',
            'x-api-version' => '2026-08-21'] + ($login
            ? $this->credentials + ['x-login-as' => $this->account]
            : ['authorization' => 'Bearer ' . $this->token, 'content-type' => 'application/json']);
        $seen = [];
        $bytes = 2;
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $bytes += strlen($name) + 2 + strlen($value) + 2;
                if (strlen($value) > 8192 || $bytes > 16384) {
                    throw new ProtocolException('The prepared header block exceeded its bound.');
                }
            }
            $lower = strtolower($name);
            if (isset($seen[$lower]) || $values !== [$expected[$lower] ?? null]) {
                throw new ProtocolException('The isolated request header role changed.');
            }
            $seen[$lower] = true;
        }
        if (array_diff(array_keys($expected), array_keys($seen)) !== []) {
            throw new ProtocolException('A required request header is unavailable.');
        }
    }

    private function tokenValid(): void
    {
        $now = ($this->unixClock)();
        if ($now < 0 || $now < $this->lastUnix || $this->token === '' || $this->expiry <= $now) {
            throw new ProtocolException('The original token expired or its clock changed.');
        }
        $this->lastUnix = $now;
    }

    private function response(
        #[SensitiveParameter] ResponseInterface $response,
        string $role,
        float $started
    ): ResponseInterface {
        $stream = $response->getBody();
        try {
            $this->assertTime($started);
            $status = $response->getStatusCode();
            if ($status < 100 || $status > 599) {
                throw new ProtocolException('A bounded HTTP status is required.');
            }
            $this->status = $status;
            $bytes = 2;
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    $bytes += strlen($name) + 2 + strlen($value) + 2;
                    if ($bytes > 16384 || strlen($value) > 8192) {
                        throw new ProtocolException('The represented response headers exceeded their bound.');
                    }
                }
            }
            $raw = '';
            if ($status === ($role === 'login' ? 201 : 200)) {
                $length = $response->getHeader('Content-Length');
                $media = $response->getHeader('Content-Type');
                if (
                    count($length) > 1 || count($media) !== 1
                    || preg_match('/^application\/json(?:\s*;\s*charset=utf-8)?$/iD', $media[0]) !== 1
                    || !in_array($response->getHeader('Content-Encoding'), [[], ['identity']], true)
                    || ($length !== [] && (preg_match('/^[0-9]+$/D', $length[0]) !== 1
                        || strlen(ltrim($length[0], '0')) > 5
                        || strlen(ltrim($length[0], '0')) === 5 && strcmp(ltrim($length[0], '0'), '65536') > 0))
                ) {
                    throw new ProtocolException('The response media or length boundary failed.');
                }
                while (strlen($raw) <= 65536) {
                    $this->assertTime($started);
                    $part = $stream->read(min(8192, 65537 - strlen($raw)));
                    $this->assertTime($started);
                    $this->bytes += strlen($part);
                    if ($this->bytes > 131072 || $part === '' && !$stream->eof()) {
                        throw new ProtocolException('The response exceeded its progress or cumulative bound.');
                    }
                    $raw .= $part;
                    if ($stream->eof()) {
                        break;
                    }
                }
                if (strlen($raw) > 65536 || $length !== [] && (int) $length[0] !== strlen($raw)) {
                    throw new ProtocolException('The response body exceeded its bound.');
                }
            }
        } finally {
            $stream->close();
        }
        $this->assertTime($started);
        $this->note($role . '_result', ['http_status' => (string) $status,
            ...($raw === '' ? [] : ['response_sha256' => hash('sha256', $raw)])]);
        if ($status !== ($role === 'login' ? 201 : 200)) {
            throw new ProtocolException('The fixed HTTP role was rejected.');
        }
        $native = WebhookConfigObservation::nativeObject($raw);
        if ($role === 'login') {
            $token = $native->token ?? null;
            $expires = $native->expires_at ?? null;
            if (
                !is_string($token) || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token) === 1
                || !is_string($expires) || preg_match(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)$/D',
                    $expires
                ) !== 1
            ) {
                throw new ProtocolException('The native token response is unavailable.');
            }
            $date = new DateTimeImmutable($expires);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new ProtocolException('The native token expiration is invalid.');
            }
            $this->token = $token;
            $this->expiry = $date->getTimestamp();
            $this->tokenValid();
        } else {
            $this->page = $raw;
        }
        $this->assertTime($started);
        return $response->withBody(Utils::streamFor($raw));
    }

    /** @param array<string,string> $facts */
    private function note(string $phase, array $facts): void
    {
        if ($this->ledger === null) {
            throw new ProtocolException('A healthy private journal is required.');
        }
        $this->ledger->recordObservation('payment_config_' . $phase, $facts, $this->context);
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['transport' => '[private fixed configuration observation]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Private configuration state cannot be serialized.');
    }
}
