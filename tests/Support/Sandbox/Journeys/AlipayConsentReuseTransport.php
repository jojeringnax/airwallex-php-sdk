<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use stdClass;
use Throwable;

/** Closed, test-only account transport. The simulator URL is body data, never an HTTP target. */
final class AlipayConsentReuseTransport implements ClientInterface
{
    private readonly ClientInterface $http;
    /** @var Closure():float */
    private readonly Closure $clock;
    private readonly float $started;
    private ?RunLedger $ledger = null;
    private string $context = '';
    private string $toolHash = '';
    private bool $cleanup = false;
    private bool $poisoned = false;
    private int $requests = 0;
    private int $bytes = 0;
    /** @var array<string,list<string>> */
    private array $targets = [];
    /** @var array{action:string,path:string,body:array<string,mixed>}|null */
    private ?array $armed = null;
    /** @var array<string,true> */
    private array $used = [];
    private ?stdClass $native = null;
    private string $nativePath = '';
    private string $responseHash = '';

    /** @param ClientInterface|null $http Already-streaming fixture seam; arbitrary clients can prebuffer.
     * @param (Closure():float)|null $clock Monotonic seconds.
     */
    public function __construct(
        #[SensitiveParameter] private readonly string $account,
        ?ClientInterface $http = null,
        ?Closure $clock = null,
        ?float $started = null,
    ) {
        self::identity($account);
        $this->http = $http ?? new Client([
            'handler' => new StreamHandler(), 'stream' => true, 'verify' => true,
            'cookies' => false, 'allow_redirects' => false, 'http_errors' => false, 'decode_content' => false,
            'read_timeout' => 5, 'timeout' => 30, 'connect_timeout' => 10,
        ]);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->started = $started ?? ($this->clock)();
        $this->remaining();
    }

    public function bind(RunLedger $ledger, string $context, ?string $expectedToolHash = null): void
    {
        if ($this->ledger !== null || $context === '') {
            throw new ProtocolException('A fresh Consent transport requires one private journal.');
        }
        $ledger->assertContext($context);
        $this->ledger = $ledger;
        $this->context = $context;
        $hash = hash_file('sha256', dirname(__DIR__, 4) . '/tools/alipay-hk-consent-reuse.php');
        if ($hash === false || ($expectedToolHash !== null && $hash !== $expectedToolHash)) {
            throw new ProtocolException('The fixed Consent entry source is unavailable.');
        }
        $this->toolHash = $hash;
        $this->source();
    }

    public function register(string $collection, #[SensitiveParameter] string $id): void
    {
        self::identity($id);
        $kind = match ($collection) {
            'customers' => 'payment_customers', 'payment_consents' => 'payment_consents',
            'payment_intents' => 'payment_intents', 'refunds' => 'payment_refunds',
            default => throw new ProtocolException('An unsupported Consent resource was selected.'),
        };
        if (!in_array($id, $this->journal()->created($kind), true)) {
            throw new ProtocolException('Resource inventory must be durable before dependent requests.');
        }
        $this->targets[$collection][] = $id;
    }

    /** @param array<string,mixed> $body The full expected prepared payload, including its original SDK UUID. */
    public function arm(string $action, string $path, #[SensitiveParameter] array $body): void
    {
        $this->source();
        $this->remaining();
        if (
            $this->poisoned || $this->armed !== null || isset($this->used[$path])
            || preg_match('/^[a-z_]+$/D', $action) !== 1 || !$this->allowed('POST', $path)
            || $path === '/api/v1/authentication/login'
        ) {
            throw new ProtocolException('A Consent mutation cannot be resumed or replayed.');
        }
        if ($path === '/api/v1/simulation/pa/shopper_actions/verify') {
            $this->journal()->requestId($action, $body, $this->context); // Local identity, no SDK wire UUID.
        } else {
            $payload = $body;
            unset($payload['request_id']);
            $id = $this->journal()->requestId($action, ['path' => $path, 'payload' => $payload], $this->context);
            if (($body['request_id'] ?? null) !== $id) {
                throw new ProtocolException('The prepared SDK identity differs from its durable original intent.');
            }
        }
        $this->armed = ['action' => $action, 'path' => $path, 'body' => $body];
    }

    public function beginCleanup(): void
    {
        $this->cleanup = true;
        $this->remaining();
    }

    public function healthy(): bool
    {
        return !$this->poisoned && $this->armed === null;
    }

    public function remaining(): float
    {
        $now = ($this->clock)();
        $remaining = ($this->cleanup ? 300 : 240) - ($now - $this->started);
        if (!is_finite($now) || !is_finite($this->started) || $now < $this->started || $remaining <= 0) {
            throw new ProtocolException('The Consent case exceeded its original observation window.');
        }
        return $remaining;
    }

    public function native(#[SensitiveParameter] string $path): stdClass
    {
        if ($this->native === null || $this->nativePath !== $path) {
            throw new ProtocolException('A current literal response proof is unavailable.');
        }
        return $this->native;
    }

    /** @return array{http_invocations:int,response_bytes:int,transport_poisoned:bool} */
    public function report(): array
    {
        return ['http_invocations' => $this->requests, 'response_bytes' => $this->bytes,
            'transport_poisoned' => $this->poisoned];
    }

    /** @throws ProtocolException */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $this->native = null;
        $this->nativePath = '';
        $this->responseHash = '';
        $action = null;
        $resultRecorded = false;
        try {
            $this->source();
            $this->remaining();
            $path = $request->getUri()->getPath();
            $login = $path === '/api/v1/authentication/login';
            $this->request($request, $login);
            $raw = $this->body($request);
            if ($request->getMethod() === 'GET' || $login) {
                if ($raw !== '') {
                    throw new ProtocolException('A bodyless Consent request gained a body.');
                }
            } else {
                $armed = $this->armed;
                if ($this->poisoned || $armed === null || $armed['path'] !== $path || isset($this->used[$path])) {
                    throw new ProtocolException('The prepared Consent mutation lacks one unused identity.');
                }
                $literal = KlarnaIntentReconciliation::assertUniqueJsonObject($raw);
                if (!self::literal($literal, $armed['body'])) {
                    throw new ProtocolException('A literal prepared identity changed its native JSON type.');
                }
                $data = (new JsonCodec())->decodeObject($raw, ['/amount']);
                if (!self::same($data, $armed['body'])) {
                    throw new ProtocolException('The actual prepared Consent body changed.');
                }
                $action = $armed['action'];
                $this->note($action . '_wire_prepared', ['method' => 'POST', 'target_sha256' => hash(
                    'sha256',
                    (string) $request->getUri()
                ), 'body_sha256' => hash('sha256', $raw)]);
                $this->used[$path] = true;
                $this->armed = null;
                $this->note($action . '_possible_send', ['invocation' => 'possible',
                    'body_sha256' => hash('sha256', $raw)]);
            }
            if ($this->requests >= 64) {
                throw new ProtocolException('The Consent HTTP invocation budget is exhausted.');
            }
            $remaining = $this->remaining();
            ++$this->requests;
            $response = $this->http instanceof Client ? $this->http->send($request, [
                'timeout' => min(30, $remaining), 'connect_timeout' => min(10, $remaining),
                'read_timeout' => min(5, $remaining), 'stream' => true, 'verify' => true,
                'allow_redirects' => false, 'cookies' => false, 'http_errors' => false, 'decode_content' => false,
            ]) : $this->http->sendRequest($request);
            $response = $this->response($response, $path);
            $status = $response->getStatusCode();
            if ($action !== null) {
                $this->note($action . '_wire_result', ['http_status' => (string) $status,
                    'response_sha256' => $this->responseHash]);
                $resultRecorded = true;
            }
            $this->source();
            $this->remaining();
            if ($status < 200 || $status >= 300) {
                $this->poisoned = true;
            }
            return $response;
        } catch (Throwable) {
            $this->poisoned = true;
            if ($action !== null && !$resultRecorded) {
                try {
                    $this->note($action . '_wire_result', ['outcome' => 'unusable_or_unacknowledged']);
                } catch (Throwable) {
                    // A poisoned journal cannot promise a durable outcome; the possible-send record remains.
                }
            }
            throw new ProtocolException('The bounded Consent request or its durable proof failed.');
        }
    }

    private function request(RequestInterface $request, bool $login): void
    {
        $uri = $request->getUri();
        if (
            !$this->allowed($request->getMethod(), $uri->getPath()) || $uri->getScheme() !== 'https'
            || $uri->getHost() !== 'api.sandbox.airwallex.com' || $uri->getPort() !== null
            || $uri->getUserInfo() !== '' || $uri->getQuery() !== '' || $uri->getFragment() !== ''
        ) {
            throw new ProtocolException('Only closed account Consent Sandbox routes are permitted.');
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values;
        }
        $simulation = $uri->getPath() === '/api/v1/simulation/pa/shopper_actions/verify';
        $names = $login ? ['host', 'x-client-id', 'x-api-key', 'x-login-as', 'x-api-version', 'accept']
            : ['host', 'authorization', 'x-api-version', ...($simulation ? [] : ['accept']),
                ...($request->getMethod() === 'POST' ? ['content-type'] : [])];
        if (
            array_diff(array_keys($headers), $names) !== [] || array_diff($names, array_keys($headers)) !== []
            || ($headers['host'] ?? null) !== ['api.sandbox.airwallex.com']
            || ($headers['x-api-version'] ?? null) !== ['2026-08-21']
            || (!$simulation && ($headers['accept'] ?? null) !== ['application/json'])
            || ($login && ($headers['x-login-as'] ?? null) !== [$this->account])
            || (!$login && $request->getMethod() === 'POST'
                && ($headers['content-type'] ?? null) !== ['application/json'])
        ) {
            throw new ProtocolException('The Consent request header profile changed.');
        }
        foreach ($headers as $name => $values) {
            if (
                count($values) !== 1 || $values[0] === '' || strlen($values[0]) > 8192
                || preg_match('/[\x00-\x20\x7f]/', $values[0]) === 1 && $name !== 'authorization'
            ) {
                throw new ProtocolException('A Consent header is not a bounded single value.');
            }
        }
        if (!$login && preg_match('/^Bearer [^\x00-\x20\x7f]+$/D', $request->getHeaderLine('Authorization')) !== 1) {
            throw new ProtocolException('The Consent request lacks its isolated bearer.');
        }
    }

    private function allowed(string $method, string $path): bool
    {
        if (
            $method === 'POST' && in_array($path, ['/api/v1/authentication/login',
            '/api/v1/pa/customers/create', '/api/v1/pa/payment_consents/create',
            '/api/v1/pa/payment_intents/create', '/api/v1/pa/refunds/create',
            '/api/v1/simulation/pa/shopper_actions/verify'], true)
        ) {
            return true;
        }
        foreach ($this->targets as $kind => $ids) {
            foreach ($ids as $id) {
                $root = '/api/v1/pa/' . $kind . '/' . $id;
                if ($method === 'GET' && $path === $root) {
                    return true;
                }
                $operations = match ($kind) {
                    'payment_consents' => ['verify', 'disable'], 'payment_intents' => ['confirm', 'cancel'],
                    default => [],
                };
                foreach ($operations as $operation) {
                    if ($method === 'POST' && $path === $root . '/' . $operation) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function body(RequestInterface $request): string
    {
        $stream = $request->getBody();
        if (!$stream->isReadable() || !$stream->isSeekable() || $stream->tell() !== 0) {
            throw new ProtocolException('The prepared request stream must start at its original position.');
        }
        $raw = $stream->read(16385);
        if (strlen($raw) > 16384 || !$stream->eof()) {
            throw new ProtocolException('The prepared request body exceeds its bounded exact-wire proof.');
        }
        $stream->seek(0);
        return $raw;
    }

    private function response(ResponseInterface $response, string $path): ResponseInterface
    {
        $started = ($this->clock)();
        $stream = $response->getBody();
        try {
            $headers = 0;
            foreach ($response->getHeaders() as $name => $values) {
                $headers += strlen($name) + strlen(implode('', $values));
            }
            if ($headers > 16384 || !in_array($response->getHeader('Content-Encoding'), [[], ['identity']], true)) {
                throw new ProtocolException('The Consent response header boundary failed.');
            }
            $limit = $path === '/api/v1/simulation/pa/shopper_actions/verify'
                && $response->getStatusCode() === 200 ? 0 : 65536;
            $body = '';
            while (strlen($body) <= $limit) {
                $this->deadline($started);
                $part = $stream->read(min(8192, $limit + 1 - strlen($body)));
                $this->deadline($started);
                $this->bytes += strlen($part);
                if ($this->bytes > ($this->cleanup ? 2097152 : 1835008)) {
                    throw new ProtocolException('The Consent cumulative response budget is exhausted.');
                }
                if ($part === '') {
                    if ($stream->eof()) {
                        break;
                    }
                    throw new ProtocolException('The Consent response made no bounded progress.');
                }
                $body .= $part;
                if ($stream->eof()) {
                    break;
                }
            }
            if (strlen($body) > $limit) {
                throw new ProtocolException('The Consent response body exceeds its private limit.');
            }
        } finally {
            $stream->close();
        }
        $this->deadline($started); // Original read and case clocks also cover close completion.
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            KlarnaIntentReconciliation::assertUniqueJsonObject($body); // Ambiguous errors cannot project a code either.
        }
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            if ($path === '/api/v1/authentication/login' && $response->getStatusCode() !== 201) {
                throw new ProtocolException('The selected login success contract changed.');
            }
            if ($limit !== 0) {
                $native = KlarnaIntentReconciliation::assertUniqueJsonObject($body);
                KlarnaIntentReconciliation::assertLiteralStrings($native, ['id', 'customer_id', 'status',
                    'merchant_customer_id', 'merchant_order_id', 'next_triggered_by', 'merchant_trigger_reason',
                    'currency', 'payment_intent_id', 'payment_consent_id', 'token', 'expires_at']);
                if (property_exists($native, 'metadata')) {
                    if (!$native->metadata instanceof stdClass) {
                        throw new ProtocolException('The literal run metadata is not an object.');
                    }
                    KlarnaIntentReconciliation::assertLiteralStrings($native->metadata, ['sdk_validation_run']);
                }
                foreach (['payment_consent', 'latest_payment_attempt'] as $field) {
                    if (isset($native->{$field})) {
                        if (!$native->{$field} instanceof stdClass) {
                            throw new ProtocolException('An embedded identity proof is not an object.');
                        }
                        KlarnaIntentReconciliation::assertLiteralStrings(
                            $native->{$field},
                            ['id', 'payment_intent_id', 'payment_consent_id', 'merchant_order_id', 'currency', 'status']
                        );
                    }
                }
                $this->native = $native;
                $this->nativePath = $path;
            }
        }
        $this->responseHash = hash('sha256', $body);
        return $response->withBody(Utils::streamFor($body));
    }

    private function deadline(float $started): void
    {
        $now = ($this->clock)();
        $this->remaining();
        if (!is_finite($started) || !is_finite($now) || $now < $started || $now - $started >= 10) {
            throw new ProtocolException('The Consent response exceeded its original read window.');
        }
    }

    private function journal(): RunLedger
    {
        return $this->ledger ?? throw new ProtocolException('The Consent journal must precede authentication.');
    }

    /** @param array<string,string> $facts */
    private function note(string $phase, array $facts): void
    {
        $this->journal()->recordObservation($phase, $facts, $this->context);
    }

    private function source(): void
    {
        $this->journal()->assertContext($this->context);
        if (
            $this->journal()->evidence()['source'] !== SourceEvidence::capture()
            || $this->toolHash !== hash_file('sha256', dirname(__DIR__, 4) . '/tools/alipay-hk-consent-reuse.php')
        ) {
            throw new ProtocolException('The Consent source changed after its private preflight.');
        }
    }

    public static function identity(#[SensitiveParameter] mixed $id): string
    {
        if (
            !is_string($id) || $id === '' || strlen($id) > 256
            || preg_match('/[\x00-\x20\x7f\/\\\\]/', $id) === 1 || in_array($id, ['.', '..'], true)
            || rawurlencode($id) !== $id
        ) {
            throw new ProtocolException('A literal bounded local resource identity is required.');
        }
        return $id;
    }

    /** @param array<array-key,mixed> $actual
     * @param array<array-key,mixed> $expected
     */
    private static function same(array $actual, array $expected): bool
    {
        if (array_diff_key($actual, $expected) !== [] || array_diff_key($expected, $actual) !== []) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (is_array($value) && is_array($actual[$key])) {
                if (!self::same($actual[$key], $value)) {
                    return false;
                }
            } elseif ($actual[$key] !== $value) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $expected */
    private static function literal(stdClass $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (!property_exists($actual, $key)) {
                return false;
            }
            if (is_array($value)) {
                $nested = [];
                foreach ($value as $name => $entry) {
                    if (!is_string($name)) {
                        return false;
                    }
                    $nested[$name] = $entry;
                }
                if (!$actual->{$key} instanceof stdClass || !self::literal($actual->{$key}, $nested)) {
                    return false;
                }
            } elseif ($key !== 'amount' && $actual->{$key} !== $value) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['transport' => '[private]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Consent transport state cannot be serialized.');
    }
}
