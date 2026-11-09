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

/** Fixed isolated management and existing account Intent operations, with a genuinely streaming default. */
final class WebhookDeliveryTransport implements ClientInterface
{
    private readonly ClientInterface $http;
    /** @var array<string,int> */
    private array $counts = [];
    private ?string $webhookPath = null;
    private ?string $intentPath = null;
    /** @var Closure():float */
    private readonly Closure $clock;
    private readonly float $overallDeadline;
    private readonly ?float $subscriptionStarted;

    /**
     * @param ClientInterface|null $http Fixture or already-streaming seam; an arbitrary client may prebuffer.
     * @param (Closure():float)|null $clock Monotonic seconds for deterministic deadline tests.
     */
    public function __construct(
        #[SensitiveParameter] private readonly string $accountId,
        ?ClientInterface $http = null,
        ?Closure $clock = null,
        ?float $overallDeadline = null,
        private readonly bool $managementOnly = false,
        private readonly WebhookManagementProfile $managementProfile = WebhookManagementProfile::Account,
        private readonly ?WebhookDeliveryEvents $eventPair = null,
        ?float $subscriptionStarted = null,
    ) {
        if ($managementProfile === WebhookManagementProfile::DefaultLogin && !$managementOnly) {
            throw new ProtocolException('Default management cannot grant producer authority.');
        }
        if (
            $eventPair === WebhookDeliveryEvents::Subscription
            && (!$managementOnly || $managementProfile !== WebhookManagementProfile::DefaultLogin)
        ) {
            throw new ProtocolException('Subscription delivery requires its isolated DEFAULT management pair.');
        }
        if (
            $managementProfile === WebhookManagementProfile::DefaultLogin
            && $eventPair === WebhookDeliveryEvents::Payments
        ) {
            throw new ProtocolException('Default management cannot select the Payments producer pair.');
        }
        $this->http = $http ?? new Client([
            'handler' => new StreamHandler(), 'stream' => true, 'read_timeout' => 5,
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
            'allow_redirects' => false, 'http_errors' => false, 'cookies' => false,
        ]);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        if ($eventPair === WebhookDeliveryEvents::Subscription) {
            if ($overallDeadline !== null && $subscriptionStarted === null) {
                throw new ProtocolException('An explicit Subscription window requires its original clock sample.');
            }
            $this->subscriptionStarted = $subscriptionStarted ?? ($this->clock)();
            $this->overallDeadline = SubscriptionWebhookTransport::caseDeadline(
                $this->subscriptionStarted,
                $overallDeadline
            );
        } else {
            // Preserve all legacy clock observations and window construction exactly.
            $this->subscriptionStarted = null;
            $this->overallDeadline = $overallDeadline ?? ($this->clock)() + 300;
            if (!is_finite($this->overallDeadline)) {
                throw new ProtocolException('The delivery probe requires a finite elapsed deadline.');
            }
        }
    }

    public function assertManagementProfile(WebhookManagementProfile $profile): void
    {
        if ($profile !== $this->managementProfile) {
            throw new ProtocolException('Webhook management profile does not match its isolated transport.');
        }
    }

    public function assertEventPair(WebhookDeliveryEvents $events): void
    {
        if (
            ($this->eventPair !== null && $events !== $this->eventPair)
            || ($events === WebhookDeliveryEvents::Subscription && $this->eventPair !== $events)
        ) {
            throw new ProtocolException('Webhook event pair does not match its isolated transport.');
        }
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

    public function allowWebhook(#[SensitiveParameter] string $id): void
    {
        $this->webhookPath = $this->target($this->webhookPath, '/api/v1/webhooks/', $id);
    }

    public function allowIntent(#[SensitiveParameter] string $id): void
    {
        if ($this->managementOnly) {
            throw new ProtocolException('The Billing experiment grants no Payments producer authority.');
        }
        $this->intentPath = $this->target($this->intentPath, '/api/v1/pa/payment_intents/', $id);
    }

    private function target(?string $existing, string $prefix, #[SensitiveParameter] string $id): string
    {
        if ($existing !== null || !self::validIdentity($id)) {
            throw new ProtocolException('The delivery probe cannot replace or invent its owned target.');
        }
        return $prefix . rawurlencode($id);
    }

    public static function validIdentity(#[SensitiveParameter] string $id): bool
    {
        return $id !== '' && strlen($id) <= 1024 && $id !== '.' && $id !== '..'
            && !str_contains($id, '/') && !str_contains($id, '\\')
            && preg_match('/[\x00-\x20\x7f]/', $id) !== 1;
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $method = $request->getMethod();
        $path = $uri->getPath();
        $login = $method === 'POST' && $path === '/api/v1/authentication/login';
        $create = $method === 'POST' && in_array(
            $path,
            ['/api/v1/webhooks/create', '/api/v1/pa/payment_intents/create'],
            true
        );
        $read = $method === 'GET' && in_array($path, [$this->webhookPath, $this->intentPath], true);
        $delete = $method === 'POST' && $this->webhookPath !== null && $path === $this->webhookPath . '/delete';
        $cancel = $method === 'POST' && $this->intentPath !== null && $path === $this->intentPath . '/cancel';
        if (
            ($this->managementOnly && (($create && $path !== '/api/v1/webhooks/create')
                || ($read && $path !== $this->webhookPath) || $cancel))
            || (!$login && !$create && !$read && !$delete && !$cancel) || $uri->getScheme() !== 'https'
            || $uri->getHost() !== 'api.sandbox.airwallex.com' || $uri->getPort() !== null
            || $uri->getUserInfo() !== '' || $uri->getQuery() !== '' || $uri->getFragment() !== ''
            || $request->hasHeader('x-on-behalf-of') || $request->hasHeader('Cookie') || $request->hasHeader('Referer')
            || $request->getHeaderLine('x-api-version') !== '2026-08-21'
            || ($login && ($this->managementProfile === WebhookManagementProfile::Account
                ? $request->getHeaderLine('x-login-as') !== $this->accountId : $request->hasHeader('x-login-as')))
            || (!$login && ($request->hasHeader('x-login-as') || $request->hasHeader('x-api-key')
                || $request->hasHeader('x-client-id')))
            || (($read || $delete || $login) && $request->getBody()->getSize() !== 0)
        ) {
            throw new ProtocolException('Webhook delivery requires its fixed account-owned Sandbox operations.');
        }
        if ($this->managementProfile === WebhookManagementProfile::DefaultLogin) {
            $this->defaultRequest($request, $login, $create);
        }
        $key = $method . ' ' . $path;
        $limit = $login ? 2 : ($read ? ($path === $this->webhookPath ? 3 : 12) : 1);
        if (
            ($this->counts[$key] ?? 0) >= $limit
            || array_sum($this->counts) >= ($this->eventPair === WebhookDeliveryEvents::Subscription ? 7 : 24)
        ) {
            throw new ProtocolException('Webhook delivery operation budget is exhausted.');
        }
        $now = ($this->clock)();
        if (
            !is_finite($now) || $now + 45 > $this->overallDeadline
            || ($this->subscriptionStarted !== null && $now < $this->subscriptionStarted)
        ) {
            throw new ProtocolException('The delivery probe has insufficient bounded request time.');
        }
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1; // Latch before a possibly effective send.
        try {
            $response = $this->http->sendRequest($request);
        } catch (Throwable) {
            throw new TransportException('Webhook delivery request failed without a usable response.');
        }
        try {
            $stream = $response->getBody();
            try {
                $started = ($this->clock)();
                if ($this->eventPair === WebhookDeliveryEvents::Subscription) {
                    $bytes = 2;
                    foreach ($response->getHeaders() as $name => $values) {
                        foreach ($values as $value) {
                            $bytes += strlen($name) + 2 + strlen($value) + 2;
                        }
                    }
                    if ($bytes > 16384) {
                        throw new ProtocolException('Subscription management headers exceeded their parsed cap.');
                    }
                }
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
                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    if (
                        $login && $this->managementProfile === WebhookManagementProfile::DefaultLogin
                        && $response->getStatusCode() !== 201
                    ) {
                        throw new ProtocolException('Default management login requires the selected success status.');
                    }
                    $native = WebhookDeliveryApi::assertUnambiguousObject($body);
                    if ($login) {
                        WebhookDeliveryApi::assertStringFields($native, ['token', 'expires_at']);
                    } elseif (str_starts_with($path, '/api/v1/webhooks/')) {
                        WebhookDeliveryApi::assertStringFields($native, $delete ? ['id']
                            : ['id', 'url', 'secret', 'version', 'request_id']);
                    } else {
                        WebhookDeliveryApi::assertStringFields(
                            $native,
                            ['id', 'merchant_order_id', 'currency', 'status', 'account_id',
                            'customer_id',
                            'connected_account_id'],
                            ['customer_id', 'connected_account_id']
                        );
                    }
                }
                $bounded = $response->withBody(Utils::streamFor($body));
            } finally {
                $stream->close();
            }
            $this->deadline($started);
            return $bounded;
        } catch (Throwable) {
            throw new ProtocolException('Webhook delivery response could not be safely observed.');
        }
    }

    private function defaultRequest(#[SensitiveParameter] RequestInterface $request, bool $login, bool $create): void
    {
        $allowed = ['host', 'accept', 'content-type', 'x-api-version',
            ...($login ? ['x-client-id', 'x-api-key'] : ['authorization'])];
        foreach (array_keys($request->getHeaders()) as $name) {
            if (!in_array(strtolower($name), $allowed, true) || count($request->getHeader($name)) !== 1) {
                throw new ProtocolException('Default management rejects alternate authentication or context headers.');
            }
        }
        if (
            $request->getHeaderLine('Host') !== 'api.sandbox.airwallex.com'
            || $request->getHeaderLine('Accept') !== 'application/json'
            || ($request->hasHeader('Content-Type') && $request->getHeaderLine('Content-Type') !== 'application/json')
            || ($login && ($request->getHeaderLine('x-client-id') === ''
                || $request->getHeaderLine('x-api-key') === ''))
            || (!$login && preg_match('/^Bearer [^\x00-\x20\x7f]+$/D', $request->getHeaderLine('Authorization')) !== 1)
        ) {
            throw new ProtocolException('Default management requires its fixed credential and bearer roles.');
        }
        if (!$create) {
            return;
        }
        $size = $request->getBody()->getSize();
        if ($size === null || $size <= 0 || $size > 65536) {
            throw new ProtocolException('The fresh registration body is outside its fixed boundary.');
        }
        $stream = $request->getBody();
        if (!$stream->isSeekable()) {
            throw new ProtocolException('The fresh registration requires a bounded seekable body.');
        }
        $position = $stream->tell();
        if ($this->eventPair === WebhookDeliveryEvents::Subscription && $position !== 0) {
            throw new ProtocolException('Subscription registration body must start at its prepared cursor.');
        }
        try {
            $stream->rewind();
            $raw = $stream->read($size + 1);
            if (strlen($raw) !== $size || !$stream->eof()) {
                throw new ProtocolException('The fresh registration body did not match its bounded length.');
            }
            $body = WebhookDeliveryApi::assertUnambiguousObject($raw);
        } finally {
            $stream->seek($position);
        }
        $keys = array_keys(get_object_vars($body));
        sort($keys);
        $events = $body->events ?? null;
        if (
            !is_array($events) || !array_is_list($events) || count($events) !== 2
            || !is_string($events[0]) || !is_string($events[1])
        ) {
            throw new ProtocolException('Default management requires the selected Billing event pair.');
        }
        sort($events, SORT_STRING);
        $expected = ($this->eventPair ?? WebhookDeliveryEvents::BillingCheckout)->names();
        sort($expected, SORT_STRING);
        if (
            $keys !== ['events', 'request_id', 'url', 'version'] || $events !== $expected
            || !is_string($body->url ?? null) || !WebhookDeliveryApi::destination($body->url)
            || ($body->version ?? null) !== '2026-08-21' || !is_string($body->request_id ?? null)
            || $body->request_id === '' || strlen($body->request_id) > 50
            || preg_match('/[\x00-\x20\x7f]/', $body->request_id) === 1
        ) {
            throw new ProtocolException('Default management cannot broaden the fresh registration contract.');
        }
    }

    private function deadline(float $started): void
    {
        $now = ($this->clock)();
        if (
            !is_finite($started) || !is_finite($now) || $now < $started || $now - $started >= 10
            || $now >= $this->overallDeadline
        ) {
            throw new ProtocolException('The private response exceeded its observation deadline.');
        }
    }

    public function sent(string $action): bool
    {
        $path = match ($action) {
            'create_webhook' => '/api/v1/webhooks/create',
            'delete_webhook' => $this->webhookPath === null ? '' : $this->webhookPath . '/delete',
            'create_intent' => '/api/v1/pa/payment_intents/create',
            'cancel_intent' => $this->intentPath === null ? '' : $this->intentPath . '/cancel',
            default => throw new ProtocolException('Unknown delivery action.'),
        };
        return $path !== '' && ($this->counts['POST ' . $path] ?? 0) > 0;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['http' => '[redacted]', 'clock' => '[redacted]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Webhook delivery transports cannot be serialized.');
    }
}
