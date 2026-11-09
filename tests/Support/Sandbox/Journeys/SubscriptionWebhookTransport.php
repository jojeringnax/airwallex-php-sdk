<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\TransportException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use stdClass;
use Throwable;

/** Fixed, isolated organization catalogue/future-Subscription roles; no financial route or retry. */
final class SubscriptionWebhookTransport implements ClientInterface
{
    private readonly ClientInterface $http;
    /** @var Closure():float */
    private readonly Closure $clock;
    private readonly float $deadline;
    /** @var array<string,string> */
    private array $paths = [];
    /** @var array<string,int> */
    private array $counts = [];
    /** @var array<string,Closure(RequestInterface):void> */
    private array $reservations = [];
    /** @var array<string,bool> */
    private array $reserved = [];
    /** @var array<string,bool> */
    private array $sent = [];
    private bool $authenticationFailed = false;
    /** @var array<string,string> */
    private array $pagination = [];
    private ?string $pageCandidate = null;

    /** @param (Closure():float)|null $clock */
    public function __construct(
        ?ClientInterface $http = null,
        ?Closure $clock = null,
        ?float $deadline = null,
        #[SensitiveParameter] private readonly ?string $accountId = null,
    ) {
        if ($accountId !== null && !WebhookDeliveryTransport::validIdentity($accountId)) {
            throw new ProtocolException('The separate account prerequisite identity is unavailable.');
        }
        $this->http = $http ?? new Client([
            'handler' => new StreamHandler(), 'stream' => true, 'read_timeout' => 5,
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
            'allow_redirects' => false, 'http_errors' => false, 'cookies' => false,
        ]);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->deadline = $deadline ?? ($this->clock)() + 300;
        if (!is_finite($this->deadline)) {
            throw new ProtocolException('Subscription observation needs its finite original deadline.');
        }
    }

    public function bind(string $kind, #[SensitiveParameter] string $id): void
    {
        $prefix = match ($kind) {
            'customer' => 'billing_customers', 'product' => 'products', 'price' => 'prices',
            'subscription' => 'subscriptions',
            default => throw new ProtocolException('Unknown owned Subscription role.'),
        };
        if (isset($this->paths[$kind]) || !WebhookDeliveryTransport::validIdentity($id)) {
            throw new ProtocolException('Owned Subscription targets cannot be invented or replaced.');
        }
        $this->paths[$kind] = '/api/v1/billing/' . $prefix . '/' . rawurlencode($id);
    }

    /** Only an Item proved by the current bounded page may supply this one-use target. */
    public function bindItem(#[SensitiveParameter] string $id): void
    {
        if (
            !isset($this->paths['subscription']) || $id !== $this->pageCandidate
            || !WebhookDeliveryTransport::validIdentity($id)
        ) {
            throw new ProtocolException('The current owned Item target is unavailable.');
        }
        $this->pageCandidate = null;
        $this->paths['item'] = $this->paths['subscription'] . '/items/' . rawurlencode($id);
    }

    /** @param Closure(RequestInterface):void $beforeSend Durable wire proof, then fresh post-reservation guards. */
    public function reserve(string $action, Closure $beforeSend): void
    {
        if (
            !in_array($action, ['create_customer', 'create_product', 'create_price', 'create_subscription',
            'cancel_subscription', 'archive_price', 'archive_product'], true) || isset($this->reserved[$action])
        ) {
            throw new ProtocolException('The one-use Subscription action was already reserved or is unknown.');
        }
        $this->reserved[$action] = true;
        $this->reservations[$action] = $beforeSend;
    }

    public function sent(string $action): bool
    {
        return $this->sent[$action] ?? false;
    }

    /** @return array<string,string> */
    public function pagination(): array
    {
        return $this->pagination;
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        [$role, $limit, $action] = $this->role($request);
        $guard = null;
        $packet = null;
        if ($action !== null) {
            $guard = $this->reservations[$action] ?? null;
            unset($this->reservations[$action]); // Every attempt consumes the one-use reservation.
            if ($guard === null) {
                throw new ProtocolException('Subscription mutation requires its original durable reservation.');
            }
        }
        $this->request($request, $role);
        if ($action !== null) {
            $body = $this->mutationBody($request, $action);
            $packet = self::packet($request);
            if ($packet['body'] !== $body) {
                throw new ProtocolException('The checked Subscription body changed during preparation.');
            }
            $request = self::prepared($packet);
            if ($this->role($request) !== [$role, $limit, $action] || self::packet($request) !== $packet) {
                throw new ProtocolException('The original Subscription packet changed during preparation.');
            }
            $this->request($request, $role);
        }
        $this->time();
        if (
            $this->authenticationFailed || ($this->counts[$role] ?? 0) >= $limit
            || array_sum($this->counts) >= ($this->accountId === null ? 30 : 2)
        ) {
            throw new ProtocolException('The fixed Subscription operation budget is exhausted.');
        }
        if ($guard !== null) {
            $guard($request);
            $this->time(); // No send after a long durability or ownership check.
            if (
                $this->authenticationFailed || ($this->counts[$role] ?? 0) >= $limit
                || array_sum($this->counts) >= ($this->accountId === null ? 30 : 2)
            ) {
                throw new ProtocolException('Post-reservation reads exhausted the original operation budget.');
            }
            if (self::packet($request) !== $packet) {
                throw new ProtocolException('The durably prepared Subscription packet changed before delegation.');
            }
            // The callback cannot retain a mutable reference to the actual delegate body.
            $request = self::prepared($packet);
        }
        if ($role === 'items') {
            $this->pageCandidate = null;
            unset($this->paths['item']);
        }
        $this->counts[$role] = ($this->counts[$role] ?? 0) + 1;
        if ($action !== null) {
            $this->sent[$action] = true; // A delegate call is potentially effective, even if it throws.
        }
        try {
            $response = $this->http->sendRequest($request);
        } catch (Throwable) {
            throw new TransportException('The Subscription request has no usable response.');
        }
        if ($response->getStatusCode() === 401) {
            $this->authenticationFailed = true;
        }
        $started = ($this->clock)();
        $stream = $response->getBody();
        try {
            $bytes = 2;
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    $bytes += strlen($name) + 2 + strlen($value) + 2;
                }
            }
            if ($bytes > 16384) {
                throw new ProtocolException('Subscription parsed headers exceed their bounded observation.');
            }
            $body = '';
            while (strlen($body) <= 65536) {
                $this->readTime($started);
                $part = $stream->read(min(8192, 65537 - strlen($body)));
                $this->readTime($started);
                if ($part === '') {
                    if ($stream->eof()) {
                        break;
                    }
                    throw new ProtocolException('Subscription response made no bounded progress.');
                }
                $body .= $part;
                if ($stream->eof()) {
                    break;
                }
            }
            if (strlen($body) > 65536) {
                throw new ProtocolException('Subscription response exceeds its bounded observation.');
            }
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $this->native(WebhookDeliveryApi::assertUnambiguousObject($body), $role);
            }
            $bounded = $response->withBody(Utils::streamFor($body));
        } catch (Throwable) {
            throw new ProtocolException('Subscription response cannot establish a bounded private proof.');
        } finally {
            try {
                $stream->close();
            } catch (Throwable) {
                throw new ProtocolException('Subscription response close did not complete safely.');
            }
            $this->readTime($started); // A late/throwing close never releases the response as proof.
        }
        return $bounded;
    }

    /** @return array{method:string,target:string,headers:array<string,string[]>,protocol:string,body:string} */
    private static function packet(#[SensitiveParameter] RequestInterface $request): array
    {
        $stream = $request->getBody();
        $size = $stream->getSize();
        if (
            !$stream->isReadable() || !$stream->isSeekable() || $stream->tell() !== 0
            || $size === null || $size < 1 || $size > 65536
        ) {
            throw new ProtocolException('The prepared Subscription packet has no bounded original body.');
        }
        try {
            $body = $stream->read($size + 1);
            if (strlen($body) !== $size || !$stream->eof()) {
                throw new ProtocolException('The prepared Subscription packet has inconsistent body bytes.');
            }
        } finally {
            $stream->rewind();
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (!is_string($name)) {
                throw new ProtocolException('The prepared Subscription header name is not a string.');
            }
            $headers[$name] = $values;
        }
        return ['method' => $request->getMethod(), 'target' => (string) $request->getUri(),
            'headers' => $headers, 'protocol' => $request->getProtocolVersion(), 'body' => $body];
    }

    /** @param array{method:string,target:string,headers:array<string,string[]>,protocol:string,body:string} $packet */
    private static function prepared(#[SensitiveParameter] array $packet): RequestInterface
    {
        return new Request(
            $packet['method'],
            $packet['target'],
            $packet['headers'],
            $packet['body'],
            $packet['protocol']
        );
    }

    /** @return array{string,int,?string} */
    private function role(RequestInterface $request): array
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        if ($this->accountId !== null) {
            return match ([$method, $path]) {
                ['POST', '/api/v1/authentication/login'] => ['login', 1, null],
                ['GET', '/api/v1/account'] => ['account', 1, null],
                default => throw new ProtocolException('The account prerequisite grants no producer authority.'),
            };
        }
        if ($method === 'POST' && $path === '/api/v1/authentication/login') {
            return ['login', 2, null];
        }
        foreach (
            ['customer' => 'billing_customers', 'product' => 'products', 'price' => 'prices',
            'subscription' => 'subscriptions'] as $kind => $prefix
        ) {
            if ($method === 'POST' && $path === '/api/v1/billing/' . $prefix . '/create') {
                return ['create_' . $kind, 1, 'create_' . $kind];
            }
        }
        foreach ($this->paths as $kind => $target) {
            if ($method === 'GET' && $path === $target) {
                return [$kind, match ($kind) {
                    'customer' => 2, 'subscription' => 7, default => 3
                }, null];
            }
            if ($kind === 'subscription' && $method === 'GET' && $path === $target . '/items') {
                return ['items', 3, null];
            }
            if ($kind === 'subscription' && $method === 'POST' && $path === $target . '/cancel') {
                return ['cancel_subscription', 1, 'cancel_subscription'];
            }
            if (in_array($kind, ['product', 'price'], true) && $method === 'POST' && $path === $target . '/update') {
                return ['archive_' . $kind, 1, 'archive_' . $kind];
            }
        }
        throw new ProtocolException('The Subscription route has no original owned target.');
    }

    private function request(RequestInterface $request, string $role): void
    {
        $uri = $request->getUri();
        $login = $role === 'login';
        $read = $request->getMethod() === 'GET';
        if (
            $uri->getScheme() !== 'https' || $uri->getHost() !== 'api.sandbox.airwallex.com'
            || $uri->getPort() !== null || $uri->getUserInfo() !== '' || $uri->getFragment() !== ''
            || $uri->getQuery() !== ($role === 'items' ? 'page_size=20' : '')
            || $request->getHeaderLine('Host') !== 'api.sandbox.airwallex.com'
            || $request->getHeaderLine('Accept') !== 'application/json'
            || $request->getHeaderLine('x-api-version') !== '2026-08-21'
            || (($login || $read) && $request->getBody()->getSize() !== 0)
        ) {
            throw new ProtocolException('Subscription transport requires its exact Sandbox request profile.');
        }
        $allowed = ['host', 'accept', 'content-type', 'x-api-version',
            ...($login ? ['x-client-id',
                'x-api-key',
                ...($this->accountId === null ? [] : ['x-login-as'])] : ['authorization'])];
        foreach ($request->getHeaders() as $name => $values) {
            if (!in_array(strtolower($name), $allowed, true) || count($values) !== 1) {
                throw new ProtocolException('Subscription request has an alternate header or context.');
            }
        }
        if (
            ($request->hasHeader('Content-Type') && $request->getHeaderLine('Content-Type') !== 'application/json')
            || ($login && ($request->getHeaderLine('x-client-id') === ''
                || $request->getHeaderLine('x-api-key') === ''))
            || ($login && $this->accountId !== null && $request->getHeaderLine('x-login-as') !== $this->accountId)
            || (!$login && preg_match('/^Bearer [^\x00-\x20\x7f]+$/D', $request->getHeaderLine('Authorization')) !== 1)
        ) {
            throw new ProtocolException('Subscription credentials are confined to their original authentication role.');
        }
    }

    private function mutationBody(RequestInterface $request, string $action): string
    {
        $stream = $request->getBody();
        $size = $stream->getSize();
        if (!$stream->isSeekable() || $stream->tell() !== 0 || $size === null || $size < 1 || $size > 65536) {
            throw new ProtocolException('Subscription mutation needs its bounded original prepared body.');
        }
        try {
            $raw = $stream->read($size + 1);
            if (strlen($raw) !== $size || !$stream->eof()) {
                throw new ProtocolException('Subscription mutation bytes differ from their declared length.');
            }
            $data = WebhookDeliveryApi::assertUnambiguousObject($raw);
        } finally {
            $stream->rewind();
        }
        $keys = array_keys(get_object_vars($data));
        sort($keys);
        $allowed = match ($action) {
            'create_customer' => ['name',
                'email',
                'default_billing_currency',
                'default_legal_entity_id',
                'metadata',
                'request_id'],

            'create_product' => ['name', 'active', 'metadata', 'request_id'],
            'create_price' => ['product_id',
                'active',
                'currency',
                'unit_amount',
                'pricing_model',
                'billing_type',
                'recurring',
                'metadata',
                'request_id'],

            'create_subscription' => ['billing_customer_id',
                'legal_entity_id',
                'collection_method',
                'currency',
                'starts_at',

                'metadata', 'items', 'days_until_due', 'duration', 'request_id'],
            'cancel_subscription' => ['proration_behavior', 'request_id'],
            default => ['active', 'request_id'],
        };
        sort($allowed);
        if (
            $keys !== $allowed || !is_string($data->request_id ?? null)
            || preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $data->request_id) !== 1
            || ($action === 'cancel_subscription' && ($data->proration_behavior ?? null) !== 'NONE')
            || (str_starts_with($action, 'archive_') && ($data->active ?? null) !== false)
        ) {
            throw new ProtocolException('Subscription mutation cannot broaden its fixed body profile.');
        }
        if (str_starts_with($action, 'create_')) {
            $metadata = $data->metadata ?? null;
            $marker = $metadata instanceof stdClass ? ($metadata->sdk_validation_run ?? null) : null;
            if (
                !$metadata instanceof stdClass || count(get_object_vars($metadata)) !== 1 || !is_string($marker)
                || preg_match('/^sdk-validation-[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $marker) !== 1
                || (in_array($action, ['create_customer', 'create_product'], true) && ($data->name ?? null) !== $marker)
                || ($action === 'create_customer' && (($data->email ?? null) !== $marker . '@example.test'
                    || ($data->default_billing_currency ?? null) !== 'EUR'
                    || !is_string($data->default_legal_entity_id ?? null)
                    || !WebhookDeliveryTransport::validIdentity($data->default_legal_entity_id)))
                || (in_array($action, ['create_product', 'create_price'], true) && ($data->active ?? null) !== true)
            ) {
                throw new ProtocolException('Only the original synthetic marked catalogue profile is admitted.');
            }
        }
        if ($action === 'create_price') {
            $lossless = (new \Jojeringnax\Airwallex\Internal\Json\JsonCodec())->decodeObject($raw);
            if (
                ($data->currency ?? null) !== 'EUR' || ($data->pricing_model ?? null) !== 'PER_UNIT'
                || ($data->billing_type ?? null) !== 'IN_ADVANCE'
                || !ExactDecimal::equalsLiteral($lossless['unit_amount'] ?? null, '1.23')
                || !is_string($data->product_id ?? null)
                || ($this->paths['product'] ?? null) !== '/api/v1/billing/products/' . rawurlencode($data->product_id)
                || !($data->recurring ?? null) instanceof stdClass || count(get_object_vars($data->recurring)) !== 2
                || ($data->recurring->period ?? null) !== 1 || ($data->recurring->period_unit ?? null) !== 'MONTH'
            ) {
                throw new ProtocolException('Only the original one-month owned EUR Price is admitted.');
            }
        }
        if ($action === 'create_subscription') {
            $items = $data->items ?? null;
            $item = is_array($items) && count($items) === 1 ? $items[0] : null;
            if (
                ($data->collection_method ?? null) !== 'OUT_OF_BAND' || ($data->currency ?? null) !== 'EUR'
                || ($data->days_until_due ?? null) !== 30 || !($data->duration ?? null) instanceof stdClass
                || count(get_object_vars($data->duration)) !== 2
                || !is_string($data->legal_entity_id ?? null)
                || !WebhookDeliveryTransport::validIdentity($data->legal_entity_id)
                || !is_string($data->starts_at ?? null)
                || !SubscriptionWebhookApi::sameInstant($data->starts_at, $data->starts_at)
                || ($data->duration->period ?? null) !== 1 || ($data->duration->period_unit ?? null) !== 'MONTH'
                || !$item instanceof stdClass || count(get_object_vars($item)) !== 2 || ($item->quantity ?? null) !== 1
                || !is_string($item->price_id ?? null) || !is_string($data->billing_customer_id ?? null)
                || ($this->paths['price'] ?? null) !== '/api/v1/billing/prices/' . rawurlencode($item->price_id)

                    || ($this->paths['customer'] ?? null) !== '/api/v1/billing/billing_customers/'
                        . rawurlencode($data->billing_customer_id)
            ) {
                throw new ProtocolException('Only the original one-item future OOB Subscription is admitted.');
            }
        }
        return $raw;
    }

    private function native(stdClass $data, string $role): void
    {
        if ($role === 'login') {
            WebhookDeliveryApi::assertStringFields($data, ['token', 'expires_at']);
            return;
        }
        if ($role === 'account') {
            WebhookDeliveryApi::assertStringFields($data, ['id']);
            if (($data->account_details ?? null) instanceof stdClass) {
                WebhookDeliveryApi::assertStringFields($data->account_details, ['legal_entity_id']);
            }
            return;
        }
        if ($role === 'items') {
            $this->pagination = [];
            foreach (['page_before', 'page_after'] as $name) {
                if (!property_exists($data, $name)) {
                    $this->pagination[$name] = 'absent';
                } elseif ($data->$name === null) {
                    $this->pagination[$name] = 'null_observed';
                } else {
                    $this->pagination[$name] = is_string($data->$name) ? 'string_present' : 'wrong_type';
                }
            }
            if (!is_array($data->items ?? null) || !array_is_list($data->items)) {
                throw new ProtocolException('The bounded original Item page is unavailable.');
            }
            foreach ($data->items as $item) {
                if (!$item instanceof stdClass) {
                    throw new ProtocolException('The bounded Item has no native object proof.');
                }
                $this->native($item, 'item');
            }
            if (count($data->items) === 1) {
                $item = $data->items[0];
                if (
                    $item instanceof stdClass && is_string($item->id ?? null)
                        && is_string($item->subscription_id ?? null)

                        && ($this->paths['subscription'] ?? null) === '/api/v1/billing/subscriptions/'
                            . rawurlencode($item->subscription_id)
                ) {
                    $this->pageCandidate = $item->id;
                }
            }
            return;
        }
        WebhookDeliveryApi::assertStringFields($data, ['id']);
        if ($role === 'customer' || $role === 'create_customer') {
            WebhookDeliveryApi::assertStringFields($data, ['default_billing_currency', 'default_legal_entity_id']);
        } elseif (str_contains($role, 'subscription')) {
            WebhookDeliveryApi::assertStringFields(
                $data,
                ['billing_customer_id', 'legal_entity_id', 'currency',
                'collection_method', 'status', 'starts_at', 'payment_source_id', 'trial_ends_at', 'trial_starts_at'],
                ['payment_source_id', 'trial_ends_at', 'trial_starts_at']
            );
            foreach (['days_until_due'] as $field) {
                if (property_exists($data, $field) && !is_int($data->$field)) {
                    throw new ProtocolException('Subscription numeric proof needs a literal small integer.');
                }
            }
            foreach (['duration', 'recurring'] as $field) {
                $this->period($data->$field ?? null);
            }
        } elseif ($role === 'item') {
            WebhookDeliveryApi::assertStringFields($data, ['subscription_id']);
            if (property_exists($data, 'quantity') && !is_int($data->quantity)) {
                throw new ProtocolException('The original Item quantity needs a native integer.');
            }
            if (($data->price ?? null) instanceof stdClass) {
                $this->native($data->price, 'price');
            }
        } elseif (str_contains($role, 'price')) {
            WebhookDeliveryApi::assertStringFields($data, ['product_id', 'currency', 'pricing_model', 'billing_type']);
            $this->period($data->recurring ?? null);
            if (($data->product ?? null) instanceof stdClass) {
                $this->native($data->product, 'product');
            }
        } else {
            WebhookDeliveryApi::assertStringFields($data, ['name']);
        }
    }

    private function period(mixed $value): void
    {
        if ($value instanceof stdClass) {
            WebhookDeliveryApi::assertStringFields($value, ['period_unit']);
            if (property_exists($value, 'period') && !is_int($value->period)) {
                throw new ProtocolException('Recurrence proof needs a native integer period.');
            }
        }
    }

    private function time(): void
    {
        $now = ($this->clock)();
        if (!is_finite($now) || $now < $this->deadline - 300 || $now + 45 > $this->deadline) {
            throw new ProtocolException('The original Subscription request reserve is unavailable.');
        }
    }

    private function readTime(float $started): void
    {
        $now = ($this->clock)();
        if (
            !is_finite($started) || !is_finite($now) || $now < $started
            || $now - $started >= 10 || $now >= $this->deadline
        ) {
            throw new ProtocolException('The original Subscription response deadline elapsed.');
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['operations' => '[private]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Subscription transport cannot be serialized.');
    }
}
