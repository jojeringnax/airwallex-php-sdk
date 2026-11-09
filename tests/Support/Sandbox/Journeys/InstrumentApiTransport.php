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
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use stdClass;

/** Development-only SDK transport: exact armed mutations and inventoried reads, without retries. */
final class InstrumentApiTransport implements ClientInterface
{
    private readonly ClientInterface $http;
    /** @var Closure():float */
    private readonly Closure $clock;
    /** @var Closure():int */
    private readonly Closure $unixClock;
    private int $lastUnix = 0;
    private readonly float $started;
    private float $last;
    private ?RunLedger $ledger = null;
    private ?Closure $gate = null;
    private string $context = '';
    /** @var array<string,string> */
    private readonly array $credentials;
    private readonly string $account;
    /** @var array<string,array{context:string,expiry:int}> */
    private array $tokens = [];
    /** @var array<string,int> */
    private array $logins = [];
    /** @var array<string,array{context:string,body:string,role:string}> */
    private array $armed = [];
    /** @var array<string,true> */
    private array $mutationPaths = [];
    /** @var array<string,string> */
    private array $reads = ['/api/v1/account' => 'account'];
    private int $invocations = 0;
    private int $bytes = 0;
    private bool $poisoned = false;

    /**
     * @param (Closure():float)|null $clock Monotonic fixture seam.
     * @param (Closure():int)|null $unixClock Token-expiry fixture seam, never a deadline selector.
     */
    public function __construct(
        #[SensitiveParameter] Configuration $configuration,
        ?ClientInterface $http = null,
        ?Closure $clock = null,
        ?Closure $unixClock = null,
    ) {
        $this->credentials = $configuration->credentials()->headers();
        $this->account = self::identity($configuration->accountId());
        $this->http = $http ?? new Client(['handler' => new StreamHandler(), 'stream' => true,
            'verify' => true, 'cookies' => false, 'allow_redirects' => false, 'http_errors' => false,
            'decode_content' => false, 'proxy' => '', 'timeout' => 10, 'connect_timeout' => 5, 'read_timeout' => 5]);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->unixClock = $unixClock ?? time(...);
        $this->started = $this->last = ($this->clock)();
    }

    public function bind(RunLedger $ledger, string $context, Closure $gate): void
    {
        if ($this->ledger !== null) {
            throw new ProtocolException('The original SDK binding cannot be replaced.');
        }
        $this->ledger = $ledger;
        $this->context = $context;
        $this->gate = $gate;
        $this->proof();
    }

    public function read(string $family, #[SensitiveParameter] string $id): void
    {
        $prefix = match ($family) {
            'billing_customer' => '/api/v1/billing/billing_customers/',
            'checkout' => '/api/v1/billing/billing_checkouts/',
            'source' => '/api/v1/billing/payment_sources/',
            'consent' => '/api/v1/pa/payment_consents/',
            'method' => '/api/v1/pa/payment_methods/',
            'intent' => '/api/v1/pa/payment_intents/',
            'payments_customer' => '/api/v1/pa/customers/',
            default => throw new ProtocolException('Unknown SDK read family.'),
        };
        $this->reads[$prefix . self::identity($id)] = str_contains($prefix, '/billing/') ? 'organization' : 'account';
    }

    /** @param array<string,mixed>|null $body Exact body prepared by the owner, including its durable UUID. */
    public function arm(string $role, #[SensitiveParameter] string $path, #[SensitiveParameter] ?array $body): void
    {
        $valid = ($role === 'customer_create' && $path === '/api/v1/billing/billing_customers/create')
            || ($role === 'checkout_create' && $path === '/api/v1/billing/billing_checkouts/create')
            || ($role === 'checkout_cancel' && $body === null
                && preg_match('~^/api/v1/billing/billing_checkouts/[A-Za-z0-9_-]{1,128}/cancel$~D', $path) === 1
                && isset($this->reads[substr($path, 0, -7)]));
        if (!$valid || isset($this->mutationPaths[$path])) {
            throw new ProtocolException('The SDK mutation role cannot be armed.');
        }
        $this->armed[$path] = ['context' => 'organization',
            'body' => $body === null ? '' : (new JsonCodec())->encode($body),
            'role' => $role];
        $this->mutationPaths[$path] = true;
    }

    public function proof(?float $requestStarted = null): void
    {
        $now = ($this->clock)();
        if (
            $this->poisoned || !is_finite($now) || $now < $this->last || $now - $this->started >= 600
            || ($requestStarted !== null && ($now < $requestStarted || $now - $requestStarted >= 10))
            || $this->ledger === null || $this->gate === null
        ) {
            $this->poisoned = true;
            throw new ProtocolException('The original SDK case window is unavailable.');
        }
        $this->last = $now;
        ($this->gate)();
        $this->ledger->assertContext($this->context);
    }

    /** @throws ProtocolException Original scope, durability, identity or byte bounds failed. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $this->proof();
        if (
            $this->invocations >= 32 || $request->getProtocolVersion() !== '1.1'
            || $request->getUri()->getScheme() !== 'https'
                || $request->getUri()->getHost() !== 'api.sandbox.airwallex.com'
            || !in_array($request->getUri()->getPort(), [null, 443], true) || $request->getUri()->getQuery() !== ''
            || $request->getUri()->getFragment() !== '' || $request->getUri()->getUserInfo() !== ''
        ) {
            throw new ProtocolException('The SDK invocation exceeds its closed Sandbox profile.');
        }
        $stream = $request->getBody();
        if (!$stream->isSeekable() || !$stream->isReadable() || $stream->tell() !== 0) {
            throw new ProtocolException('The original SDK body is unavailable.');
        }
        $body = $stream->read(65537);
        $eof = $stream->eof();
        $stream->seek(0);
        if (strlen($body) > 65536 || !$eof) {
            throw new ProtocolException('The SDK body exceeds its bound.');
        }
        $path = $request->getUri()->getPath();
        $login = $request->getMethod() === 'POST' && $path === '/api/v1/authentication/login';
        $loginAs = $request->getHeader('x-login-as');
        $context = $login ? ($loginAs === [] ? 'organization' : 'account')
            : ($this->tokens[$request->getHeaderLine('Authorization')]['context'] ?? '');
        if ($login) {
            if (
                $body !== '' || !in_array($loginAs, [[], [$this->account]], true)
                || ($this->logins[$context] ?? 0) >= ($context === 'account' ? 2 : 1)
            ) {
                throw new ProtocolException('The isolated SDK login role is unavailable.');
            }
            $this->logins[$context] = ($this->logins[$context] ?? 0) + 1;
            $role = 'login_' . $context;
        } elseif ($request->getMethod() === 'GET' && $body === '' && ($this->reads[$path] ?? null) === $context) {
            $role = 'known_read';
        } elseif (
            $request->getMethod() === 'POST' && isset($this->armed[$path])
            && $context === $this->armed[$path]['context'] && hash_equals($this->armed[$path]['body'], $body)
        ) {
            $role = $this->armed[$path]['role'];
            // Consume before durability or transport: an ambiguous failure is never replayed.
            unset($this->armed[$path]);
        } else {
            throw new ProtocolException('Only an original armed mutation or inventoried SDK read is admitted.');
        }
        $expected = ['host' => 'api.sandbox.airwallex.com', 'accept' => 'application/json',
            'x-api-version' => '2026-08-21']
            + ($login ? $this->credentials + ($context === 'account' ? ['x-login-as' => $this->account] : [])
                : ['authorization' => $request->getHeaderLine('Authorization')]
                    + ($body !== '' ? ['content-type' => 'application/json'] : []));
        $seen = [];
        foreach ($request->getHeaders() as $name => $values) {
            $lower = strtolower($name);
            if (isset($seen[$lower]) || $values !== [$expected[$lower] ?? null]) {
                throw new ProtocolException('The isolated SDK header role changed.');
            }
            $seen[$lower] = true;
        }
        if (array_diff(array_keys($expected), array_keys($seen)) !== []) {
            throw new ProtocolException('A required SDK header is unavailable.');
        }
        self::headerBound($request->getHeaders());
        if (!$login) {
            $this->tokenValid($expected['authorization']);
        }
        $prepared = new Request($request->getMethod(), (string) $request->getUri(), $request->getHeaders(), $body);
        $ordinal = ++$this->invocations;
        $this->note('possible_send_' . $ordinal, ['role' => $role, 'context' => $context,
            'method' => $prepared->getMethod(), 'target_sha256' => hash('sha256', (string) $prepared->getUri()),
            'body_sha256' => hash('sha256', $body),
            'headers_sha256' => hash('sha256', json_encode($prepared->getHeaders(), JSON_THROW_ON_ERROR))]);
        $this->proof();
        $started = ($this->clock)();
        $remaining = min(10.0, 600 - ($started - $this->started));
        if ($remaining <= 0) {
            throw new ProtocolException('The native SDK timeout is unavailable.');
        }
        if (!$login) {
            $this->tokenValid($expected['authorization']);
        }
        $response = $this->http instanceof Client ? $this->http->send($prepared, ['stream' => true, 'verify' => true,
            'cookies' => false, 'allow_redirects' => false, 'http_errors' => false, 'decode_content' => false,
                'proxy' => '',
            'timeout' => $remaining, 'connect_timeout' => min(5.0, $remaining), 'read_timeout' => min(5.0, $remaining)])
            : $this->http->sendRequest($prepared);
        $responseStream = $response->getBody();
        try {
            $this->proof($started);
            self::headerBound($response->getHeaders());
            if (
                $response->getStatusCode() < 200 || $response->getStatusCode() > 599
                || count($response->getHeader('Content-Type')) !== 1
                || preg_match(
                    '/^application\/json(?:\s*;\s*charset=utf-8)?$/iD',
                    $response->getHeaderLine('Content-Type')
                ) !== 1
                || !in_array($response->getHeader('Content-Encoding'), [[], ['identity']], true)
            ) {
                throw new ProtocolException('The SDK response status or media is unavailable.');
            }
            $raw = '';
            do {
                $this->proof($started);
                $part = $responseStream->read(min(8192, 65537 - strlen($raw)));
                $raw .= $part;
                $this->bytes += strlen($part);
                if (strlen($raw) > 65536 || $this->bytes > 1048576 || $part === '' && !$responseStream->eof()) {
                    throw new ProtocolException('The SDK response exceeded its progress or byte bound.');
                }
                $this->proof($started);
            } while (!$responseStream->eof());
            $length = $response->getHeader('Content-Length');
            if (
                $length !== [] && (count($length) !== 1 || preg_match('/^[0-9]{1,6}$/D', $length[0]) !== 1
                || (int) $length[0] !== strlen($raw))
            ) {
                throw new ProtocolException('The SDK response length is unconfirmed.');
            }
        } finally {
            $responseStream->close();
        }
        $native = WebhookConfigObservation::nativeObject($raw);
        self::nativeIdentities($native);
        $this->note('received_' . $ordinal, ['role' => $role, 'response_sha256' => hash('sha256', $raw),
            'http_status' => (string) $response->getStatusCode()]);
        if (in_array($role, ['customer_create', 'checkout_create'], true) && isset($native->id)) {
            $this->ledger?->recordCreated('candidate_' . $role, self::identity($native->id));
        }
        if ($response->getStatusCode() >= 300) {
            throw new ProtocolException('The SDK role was rejected; its possible-send inventory is retained.');
        }
        if ($login) {
            $token = $native->token ?? null;
            $expires = $native->expires_at ?? null;
            if (
                !is_string($token) || preg_match('/^[\x21-\x7e]{1,8192}$/D', $token) !== 1 || !is_string($expires)
                || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:?\d\d)$/D', $expires) !== 1
            ) {
                throw new ProtocolException('The native SDK token is unavailable.');
            }
            $date = new DateTimeImmutable($expires);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false) {
                throw new ProtocolException('The SDK token expiration is invalid.');
            }
            $key = 'Bearer ' . $token;
            if (isset($this->tokens[$key]) && $this->tokens[$key]['context'] !== $context) {
                throw new ProtocolException('SDK token contexts overlap.');
            }
            $this->tokens[$key] = ['context' => $context, 'expiry' => $date->getTimestamp()];
            $this->tokenValid($key);
        }
        $this->proof($started);
        return $response->withBody(Utils::streamFor($raw));
    }

    /** @param array<array<string>> $headers PSR-7 represented header fields. */
    private static function headerBound(array $headers): void
    {
        $bytes = 2;
        if (array_sum(array_map(count(...), $headers)) > 64) {
            throw new ProtocolException('The SDK header count exceeded its bound.');
        }
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $bytes += strlen($name) + strlen($value) + 4;
                if (strlen($value) > 8192 || $bytes > 16384) {
                    throw new ProtocolException('The SDK header bytes exceeded their bound.');
                }
            }
        }
    }

    private function tokenValid(#[SensitiveParameter] string $key): void
    {
        $now = ($this->unixClock)();
        if ($now < $this->lastUnix || $now < 0 || ($this->tokens[$key]['expiry'] ?? 0) <= $now) {
            throw new ProtocolException('The original SDK token expired or its clock changed.');
        }
        $this->lastUnix = $now;
    }

    private static function nativeIdentities(mixed $value): void
    {
        if ($value instanceof stdClass || is_array($value)) {
            foreach ((array) $value as $key => $child) {
                if (
                    is_string($key) && ($key === 'id' || str_ends_with($key, '_id') || $key === 'status'
                        || $key === 'type')
                    && $child !== null && !is_string($child)
                ) {
                    throw new ProtocolException('A native SDK identity or state is ambiguous.');
                }
                self::nativeIdentities($child);
            }
        }
    }

    public static function identity(#[SensitiveParameter] mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value) !== 1) {
            throw new ProtocolException('A native resource identity is unavailable.');
        }
        return $value;
    }

    /** @param array<string,string> $facts */
    private function note(string $phase, array $facts): void
    {
        $this->ledger?->recordObservation('instrument_sdk_' . $phase, $facts, $this->context);
    }

    /** @return array<string,int|bool> */
    public function evidence(): array
    {
        return ['http_invocations' => $this->invocations, 'response_bytes' => $this->bytes,
            'poisoned' => $this->poisoned];
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['transport' => '[private instrument SDK validation]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Private instrument SDK state cannot be serialized.');
    }
}
