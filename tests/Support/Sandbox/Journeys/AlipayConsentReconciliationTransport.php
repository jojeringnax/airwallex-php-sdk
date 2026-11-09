<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use stdClass;
use Throwable;

/** One Customer GET, then one Consent GET; response data never opens another role. */
final class AlipayConsentReconciliationTransport implements ClientInterface
{
    private readonly ClientInterface $http;
    /** @var Closure():float */
    private readonly Closure $clock;
    private readonly float $started;
    private ?RunLedger $ledger = null;
    private ?Closure $gate = null;
    private string $context = '';
    private string $customerPath = '';
    private string $consentPath = '';
    private bool $customerProof = false;
    private bool $poisoned = false;
    private int $logins = 0;
    private int $customerAttempts = 0;
    private int $consentAttempts = 0;
    private int $invocations = 0;
    private int $bytes = 0;
    private ?int $status = null;
    private ?stdClass $native = null;
    private string $nativeRole = '';

    /** @param (Closure():float)|null $clock Monotonic fixture only. */
    public function __construct(
        #[SensitiveParameter] private readonly string $account,
        ?ClientInterface $http = null,
        ?Closure $clock = null,
        ?float $started = null,
    ) {
        AlipayConsentReuseTransport::identity($account);
        $this->http = $http ?? new Client(['handler' => new StreamHandler(), 'stream' => true,
            'verify' => true, 'cookies' => false, 'allow_redirects' => false, 'http_errors' => false,
            'decode_content' => false, 'proxy' => '', 'read_timeout' => 5, 'timeout' => 30, 'connect_timeout' => 10]);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->started = $started ?? ($this->clock)();
        $this->assertTime();
    }

    /** @param Closure():void $gate Current source/context/prior/credential proof after durability. */
    public function bind(
        #[SensitiveParameter] string $customer,
        #[SensitiveParameter] string $consent,
        RunLedger $ledger,
        string $context,
        Closure $gate,
    ): void {
        if ($this->ledger !== null || $customer === $consent) {
            throw new ProtocolException('One fresh read-only binding is required.');
        }
        $this->customerPath = '/api/v1/pa/customers/' . AlipayConsentReuseTransport::identity($customer);
        $this->consentPath = '/api/v1/pa/payment_consents/' . AlipayConsentReuseTransport::identity($consent);
        $ledger->assertContext($context);
        $this->ledger = $ledger;
        $this->context = $context;
        $this->gate = $gate;
        $this->proof();
    }

    public function customerVerified(): void
    {
        $this->proof();
        if ($this->nativeRole !== 'customer' || $this->native === null) {
            throw new ProtocolException('The current Customer proof must precede Consent admission.');
        }
        $this->customerProof = true;
    }

    public function native(string $role): stdClass
    {
        if ($this->poisoned || $this->native === null || $this->nativeRole !== $role) {
            throw new ProtocolException('A current native owned-resource view is unavailable.');
        }
        return $this->native;
    }

    public function assertTime(?float $readStarted = null): void
    {
        $now = ($this->clock)();
        if (
            !is_finite($now) || !is_finite($this->started) || $now < $this->started || $now - $this->started >= 120
            || ($readStarted !== null && (!is_finite($readStarted) || $now < $readStarted || $now - $readStarted >= 10))
        ) {
            throw new ProtocolException('The read-only original deadline expired.');
        }
    }

    /** @return array<string,int|bool|null> */
    public function report(): array
    {
        return ['http_invocations' => $this->invocations, 'login_attempts' => $this->logins,
            'customer_get_attempts' => $this->customerAttempts, 'consent_get_attempts' => $this->consentAttempts,
            'response_bytes' => $this->bytes, 'last_http_status' => $this->status,
            'transport_poisoned' => $this->poisoned];
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $this->native = null;
        $this->nativeRole = '';
        try {
            $this->proof();
            $this->emptyBody($request);
            $prepared = new Request(
                $request->getMethod(),
                (string) $request->getUri(),
                $request->getHeaders(),
                '',
                $request->getProtocolVersion()
            );
            $role = $this->admit($prepared);
            $name = $role === 'login' ? 'login_' . $this->logins : $role;
            $this->note($name . '_possible_invocation', ['method' => $prepared->getMethod(),
                'target_sha256' => hash('sha256', (string) $prepared->getUri()), 'body_sha256' => hash('sha256', '')]);
            $this->proof(); // Nothing caller-owned survives the fsync boundary.
            $now = ($this->clock)();
            $remaining = floor(120 - ($now - $this->started));
            if (!is_finite($now) || $now < $this->started || $remaining <= 0) {
                throw new ProtocolException('The original remaining deadline is unavailable.');
            }
            if ($this->invocations >= 4) {
                throw new ProtocolException('The finite read-only invocation budget is exhausted.');
            }
            ++$this->invocations; // A local possible invocation, never a provider receipt guarantee.
            $response = $this->http instanceof Client ? $this->http->send($prepared, [
                'stream' => true, 'verify' => true, 'allow_redirects' => false, 'cookies' => false,
                'http_errors' => false, 'decode_content' => false, 'proxy' => '',
                // StreamHandler has no independent connect timeout; use the tighter HTTP cap too.
                'timeout' => min(10, $remaining), 'connect_timeout' => min(10, $remaining),
                'read_timeout' => min(5, $remaining),
            ]) : $this->http->sendRequest($prepared);
            $response = $this->response($response, $role, $name);
            $this->proof();
            return $response;
        } catch (Throwable) {
            $this->poisoned = true;
            throw new ProtocolException('The bounded read-only request or its durable proof failed.');
        }
    }

    private function proof(): void
    {
        $this->assertTime();
        if ($this->poisoned || $this->ledger === null || $this->gate === null) {
            throw new ProtocolException('The fresh private binding is unavailable.');
        }
        ($this->gate)();
        $this->ledger->assertContext($this->context);
        $this->assertTime();
    }

    private function emptyBody(RequestInterface $request): void
    {
        $stream = $request->getBody();
        if (!$stream->isReadable() || !$stream->isSeekable() || $stream->tell() !== 0 || $stream->getSize() !== 0) {
            throw new ProtocolException('The selected login and reads require an empty body.');
        }
        try {
            if ($stream->read(1) !== '' || !$stream->eof()) {
                throw new ProtocolException('The declared empty body changed.');
            }
        } finally {
            $stream->seek(0);
        }
    }

    private function admit(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $method = $request->getMethod();
        $path = $uri->getPath();
        if (
            $uri->getScheme() !== 'https' || $uri->getHost() !== 'api.sandbox.airwallex.com'
            || $uri->getPort() !== null || $uri->getUserInfo() !== '' || $uri->getQuery() !== ''
            || $uri->getFragment() !== '' || $request->getProtocolVersion() !== '1.1'
        ) {
            throw new ProtocolException('Only the exact fixed Sandbox request is admitted.');
        }
        $login = $method === 'POST' && $path === '/api/v1/authentication/login';
        $this->headers($request, $login);
        if ($login && $this->consentAttempts === 0 && $this->logins < ($this->customerProof ? 2 : 1)) {
            ++$this->logins;
            return 'login';
        }
        if ($method === 'GET' && $path === $this->customerPath && $this->customerAttempts === 0) {
            ++$this->customerAttempts;
            return 'customer';
        }
        if (
            $method === 'GET' && $path === $this->consentPath && $this->customerProof
            && $this->consentAttempts === 0
        ) {
            ++$this->consentAttempts;
            return 'consent';
        }
        throw new ProtocolException('No other route, repetition or write is admitted.');
    }

    private function headers(RequestInterface $request, bool $login): void
    {
        $headers = [];
        $bytes = 2;
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values;
            foreach ($values as $value) {
                $bytes += strlen($name) + 2 + strlen($value) + 2;
                if (
                    $value === '' || strlen($value) > 8192 || preg_match('/[\x00-\x20\x7f]/', $value) === 1
                    && strtolower($name) !== 'authorization'
                ) {
                    throw new ProtocolException('A bounded single header value is required.');
                }
            }
            if (count($values) !== 1) {
                throw new ProtocolException('Repeated header values are withheld.');
            }
        }
        $names = ['host', 'accept', 'x-api-version', ...($login
            ? ['x-client-id', 'x-api-key', 'x-login-as'] : ['authorization'])];
        if (
            $bytes > 16384 || array_diff(array_keys($headers), $names) !== []
            || array_diff($names, array_keys($headers)) !== []
            || $headers['host'] !== ['api.sandbox.airwallex.com'] || $headers['accept'] !== ['application/json']
            || $headers['x-api-version'] !== ['2026-08-21']
            || ($login && $headers['x-login-as'] !== [$this->account])
            || (!$login && preg_match('/^Bearer [^\x00-\x20\x7f]+$/D', $headers['authorization'][0]) !== 1)
        ) {
            throw new ProtocolException('The exact isolated ACCOUNT header profile changed.');
        }
    }

    private function response(ResponseInterface $response, string $role, string $name): ResponseInterface
    {
        $started = ($this->clock)();
        $stream = $response->getBody();
        try {
            $status = $response->getStatusCode();
            if ($status < 100 || $status > 599) {
                throw new ProtocolException('The bounded HTTP status is unavailable.');
            }
            $this->status = $status;
            $headerBytes = 2; // Final CRLF in the private represented PSR response block.
            foreach ($response->getHeaders() as $key => $values) {
                foreach ($values as $value) {
                    $headerBytes += strlen($key) + 2 + strlen($value) + 2;
                    if ($headerBytes > 16384 || strlen($value) > 8192) {
                        throw new ProtocolException('The represented response header block or value is too large.');
                    }
                }
            }
            $length = $response->getHeader('Content-Length');
            $media = $response->getHeader('Content-Type');
            if (
                !in_array($response->getHeader('Content-Encoding'), [[], ['identity']], true)
                || count($length) > 1 || count($media) > 1
                || ($media !== [] && preg_match('/^application\/json(?:\s*;\s*charset=utf-8)?$/iD', $media[0]) !== 1)
                || ($length !== [] && (preg_match('/^[0-9]+$/D', $length[0]) !== 1
                    || strlen(ltrim($length[0], '0')) > 5
                    || strlen(ltrim($length[0], '0')) === 5 && strcmp(ltrim($length[0], '0'), '65536') > 0))
            ) {
                throw new ProtocolException('The response header boundary failed.');
            }
            $raw = '';
            while (strlen($raw) <= 65536) {
                $this->assertTime($started);
                $part = $stream->read(min(8192, 65537 - strlen($raw)));
                $this->assertTime($started);
                $this->bytes += strlen($part);
                if ($this->bytes > 262144) {
                    throw new ProtocolException('The cumulative response bound failed.');
                }
                if ($part === '') {
                    if ($stream->eof()) {
                        break;
                    }
                    throw new ProtocolException('The response made no progress.');
                }
                $raw .= $part;
                if ($stream->eof()) {
                    break;
                }
            }
            if (strlen($raw) > 65536 || ($length !== [] && (int) $length[0] !== strlen($raw))) {
                throw new ProtocolException('The bounded response length changed.');
            }
        } finally {
            $stream->close();
        }
        $this->assertTime($started); // Also reject late completion of close.
        $native = KlarnaIntentReconciliation::assertUniqueJsonObject($raw);
        $facts = ['http_status' => (string) $status];
        if ($role !== 'login') {
            $facts += ['response_sha256' => hash('sha256', $raw), 'private_json' => $raw];
        }
        $this->note($name . '_result', $facts);
        if ($status !== ($role === 'login' ? 201 : 200)) {
            throw new ProtocolException('The selected read-only HTTP contract did not succeed.');
        }
        if ($role === 'login') {
            foreach (['token', 'expires_at'] as $key) {
                if (!is_string($native->{$key} ?? null) || $native->{$key} === '') {
                    throw new ProtocolException('The native token proof failed.');
                }
            }
        } else {
            KlarnaIntentReconciliation::assertLiteralStrings($native, ['id', 'customer_id', 'status',
                'merchant_customer_id', 'next_triggered_by', 'merchant_trigger_reason']);
            if (property_exists($native, 'metadata')) {
                if (!$native->metadata instanceof stdClass) {
                    throw new ProtocolException('Native metadata must be an object.');
                }
                KlarnaIntentReconciliation::assertLiteralStrings($native->metadata, ['sdk_validation_run']);
            }
            $this->native = $native;
            $this->nativeRole = $role;
        }
        $this->assertTime($started);
        return $response->withBody(Utils::streamFor($raw));
    }

    /** @param array<string,string> $facts */
    private function note(string $name, array $facts): void
    {
        if ($this->ledger === null) {
            throw new ProtocolException('The private journal is unavailable.');
        }
        $this->ledger->recordObservation('consent_readonly_' . $name, $facts, $this->context);
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['transport' => '[private GET-only Consent observation]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Private read-only transports cannot be serialized.');
    }
}
