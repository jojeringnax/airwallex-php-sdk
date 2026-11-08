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
use stdClass;

/** Isolated fixed account prerequisite or organization Customer/Checkout roles; no financial route. */
final class BillingWebhookTransport implements ClientInterface
{
    private readonly ClientInterface $http;
    /** @var array<string,int> */
    private array $counts = [];
    private bool $authenticationFailed = false;
    private ?string $customerPath = null;
    private ?string $checkoutPath = null;
    /** @var Closure():float */
    private readonly Closure $clock;
    private readonly float $overallDeadline;

    /**
     * @param ClientInterface|null $http Fixture or already-streaming seam; an arbitrary client may prebuffer.
     * @param (Closure():float)|null $clock Monotonic seconds for deterministic deadline tests.
     */
    public function __construct(
        #[SensitiveParameter] private readonly string $accountId,
        private readonly bool $organization,
        ?ClientInterface $http = null,
        ?Closure $clock = null,
        ?float $overallDeadline = null,
    ) {
        $this->http = $http ?? new Client([
            'handler' => new StreamHandler(), 'stream' => true, 'read_timeout' => 5,
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
            'allow_redirects' => false, 'http_errors' => false, 'cookies' => false,
        ]);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->overallDeadline = $overallDeadline ?? ($this->clock)() + 300;
        if (!is_finite($this->overallDeadline)) {
            throw new ProtocolException('The delivery probe requires a finite elapsed deadline.');
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

    public function allowCustomer(#[SensitiveParameter] string $id): void
    {
        $this->customerPath = $this->target($this->customerPath, '/api/v1/billing/billing_customers/', $id);
    }

    public function allowCheckout(#[SensitiveParameter] string $id): void
    {
        $this->checkoutPath = $this->target($this->checkoutPath, '/api/v1/billing/billing_checkouts/', $id);
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
            ['/api/v1/billing/billing_customers/create', '/api/v1/billing/billing_checkouts/create'],
            true
        );
        $read = $method === 'GET' && in_array($path, [$this->customerPath, $this->checkoutPath], true);
        $account = $method === 'GET' && $path === '/api/v1/account';
        $cancel = $method === 'POST' && $this->checkoutPath !== null && $path === $this->checkoutPath . '/cancel';
        if (
            $this->authenticationFailed
            || (!$login && !($this->organization ? ($create || $read || $cancel) : $account))
            || $uri->getScheme() !== 'https'
            || $uri->getHost() !== 'api.sandbox.airwallex.com' || $uri->getPort() !== null
            || $uri->getUserInfo() !== '' || $uri->getQuery() !== '' || $uri->getFragment() !== ''
            || $request->hasHeader('x-on-behalf-of') || $request->hasHeader('x-org-id')
            || $request->hasHeader('x-organization-id')
            || $request->hasHeader('Cookie') || $request->hasHeader('Referer')
            || $request->getHeaderLine('x-api-version') !== '2026-08-21'
            || ($login && ($this->organization ? $request->hasHeader('x-login-as')
                : $request->getHeaderLine('x-login-as') !== $this->accountId))
            || (!$login && ($request->hasHeader('x-login-as') || $request->hasHeader('x-api-key')
                || $request->hasHeader('x-client-id')))
            || (($read || $account || $cancel || $login) && $request->getBody()->getSize() !== 0)
        ) {
            throw new ProtocolException('Webhook delivery requires its fixed account-owned Sandbox operations.');
        }
        $key = $method . ' ' . $path;
        $limit = $login ? 2 : ($read ? ($path === $this->customerPath ? 2 : 8) : 1);
        if (($this->counts[$key] ?? 0) >= $limit || array_sum($this->counts) >= 16) {
            throw new ProtocolException('Webhook delivery operation budget is exhausted.');
        }
        $now = ($this->clock)();
        if (!is_finite($now) || $now + 45 > $this->overallDeadline) {
            throw new ProtocolException('The delivery probe has insufficient bounded request time.');
        }
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1; // Latch before a possibly effective send.
        try {
            $response = $this->http->sendRequest($request);
        } catch (Throwable) {
            throw new TransportException('Webhook delivery request failed without a usable response.');
        }
        if ($response->getStatusCode() === 401) {
            // SDK GET recovery must not turn this bounded probe into another login/read attempt.
            $this->authenticationFailed = true;
        }
        try {
            $headerBytes = 0;
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    $headerBytes += strlen($name) + strlen($value);
                }
            }
            $stream = $response->getBody();
            try {
                if ($headerBytes > 16384) {
                    throw new ProtocolException('The bounded response headers exceeded their cap.');
                }
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
                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    $native = WebhookDeliveryApi::assertUnambiguousObject($body);
                    if ($login) {
                        WebhookDeliveryApi::assertStringFields($native, ['token', 'expires_at']);
                    } elseif ($account) {
                        WebhookDeliveryApi::assertStringFields($native, ['id']);
                        $details = $native->account_details ?? null;
                        if ($details instanceof stdClass) {
                            WebhookDeliveryApi::assertStringFields($details, ['legal_entity_id']);
                        }
                    } elseif (str_starts_with($path, '/api/v1/billing/billing_customers/')) {
                        WebhookDeliveryApi::assertStringFields(
                            $native,
                            ['id', 'default_legal_entity_id', 'default_billing_currency']
                        );
                    } else {
                        WebhookDeliveryApi::assertStringFields(
                            $native,
                            ['id', 'billing_customer_id', 'legal_entity_id', 'linked_payment_account_id',
                            'mode',
                            'ui_mode',
                            'currency',
                            'status',
                            'url'],
                            ['url']
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
            'create_customer' => '/api/v1/billing/billing_customers/create',
            'create_checkout' => '/api/v1/billing/billing_checkouts/create',
            'cancel_checkout' => $this->checkoutPath === null ? '' : $this->checkoutPath . '/cancel',
            default => throw new ProtocolException('Unknown Billing delivery action.'),
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
