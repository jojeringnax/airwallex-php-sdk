<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookManagementProfile;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Synthetic provider only; its runtime writes local signed fixtures, never starts a tunnel. */
final class SubscriptionWebhookFixtureProvider implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    public ?WebhookDeliveryFixtureRuntime $runtime = null;
    /** @var (Closure(RequestInterface):?ResponseInterface)|null */
    public ?Closure $intercept = null;
    /** @var (Closure(ResponseInterface,RequestInterface):ResponseInterface)|null */
    public ?Closure $responseFilter = null;
    /** @var (Closure(array<string,mixed>):array<string,mixed>)|null */
    public ?Closure $cancelledProjection = null;
    /** @var array<string,mixed> */
    private array $registration = [];
    /** @var array<string,mixed> */
    private array $customer = [];
    /** @var array<string,mixed> */
    private array $subscription = [];
    /** @var array<string,mixed> */
    private array $product = [];
    /** @var array<string,mixed> */
    private array $price = [];
    /** @var array<string,mixed> */
    public array $cursors = [];
    public int $itemPage = 0;
    public bool $cancelEffective = true;
    private bool $deleted = false;
    private int $defaultLogins = 0;
    private int $accountLogins = 0;

    public function __construct(
        private readonly bool $defaultManagement = true,
        private readonly WebhookManagementProfile $managementProfile = WebhookManagementProfile::DefaultLogin,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->intercept !== null && ($response = ($this->intercept)($request)) !== null) {
            return $response;
        }
        $response = $this->dispatch($request);
        return $this->responseFilter === null ? $response : ($this->responseFilter)($response, $request);
    }

    private function dispatch(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $body = (string) $request->getBody();
        $decoded = $body === '' ? [] : (new JsonCodec())->decodeObject($body);
        $data = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException('Synthetic request requires named fields.');
            }
            $data[$key] = $value;
        }
        $data = self::nativeIntegers($data);
        if ($path === '/api/v1/authentication/login') {
            $token = $request->hasHeader('x-login-as')
                ? ($this->managementProfile === WebhookManagementProfile::Account && ++$this->accountLogins > 1
                    ? 'SYNTHETIC_PRIVATE_MANAGEMENT_ACCOUNT_TOKEN' : 'SYNTHETIC_PRIVATE_ACCOUNT_TOKEN')
                : ($this->defaultManagement && $this->managementProfile === WebhookManagementProfile::DefaultLogin
                    && ++$this->defaultLogins === 1
                    ? 'SYNTHETIC_PRIVATE_DEFAULT_TOKEN' : 'SYNTHETIC_PRIVATE_ORG_TOKEN');
            return self::json(['token' => $token,
                'expires_at' => '2099-01-01T00:00:00Z'], $this->defaultManagement ? 201 : 200);
        }
        if ($this->defaultManagement) {
            $expected = $path === '/api/v1/account' ? 'SYNTHETIC_PRIVATE_ACCOUNT_TOKEN'
                : (str_starts_with($path, '/api/v1/webhooks/')
                    ? ($this->managementProfile === WebhookManagementProfile::Account
                        ? 'SYNTHETIC_PRIVATE_MANAGEMENT_ACCOUNT_TOKEN' : 'SYNTHETIC_PRIVATE_DEFAULT_TOKEN')
                    : 'SYNTHETIC_PRIVATE_ORG_TOKEN');
            if ($request->getHeaderLine('Authorization') !== 'Bearer ' . $expected) {
                throw new RuntimeException('Synthetic isolated token role did not match.');
            }
        }
        if ($path === '/api/v1/account') {
            return self::json(['id' => 'SYNTHETIC_PRIVATE_ACCOUNT',
                'account_details' => ['legal_entity_id' => 'SYNTHETIC_PRIVATE_ENTITY']]);
        }
        if ($path === '/api/v1/webhooks/create') {
            $this->registration = $data + ['id' => 'SYNTHETIC_PRIVATE_WEBHOOK', 'secret' => 'SYNTHETIC_PRIVATE_SECRET'];
            return self::json($this->registration, 201);
        }
        if ($path === '/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete') {
            $this->deleted = true;
            return self::json(['id' => 'SYNTHETIC_PRIVATE_WEBHOOK', 'deleted' => true]);
        }
        if ($path === '/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK') {
            return self::json(
                $this->deleted ? ['code' => 'not_found'] : $this->registration,
                $this->deleted ? 404 : 200
            );
        }
        if ($path === '/api/v1/billing/billing_customers/create') {
            $this->customer = $data + ['id' => 'SYNTHETIC_PRIVATE_CUSTOMER'];
            return self::json($this->customer, 201);
        }
        if ($path === '/api/v1/billing/billing_customers/SYNTHETIC_PRIVATE_CUSTOMER') {
            return self::json($this->customer);
        }
        if ($path === '/api/v1/billing/products/create') {
            $this->product = $data + ['id' => 'SYNTHETIC_PRIVATE_PRODUCT'];
            return self::json($this->product, 201);
        }
        if ($path === '/api/v1/billing/products/SYNTHETIC_PRIVATE_PRODUCT/update') {
            $this->product['active'] = false;
            return self::json($this->product);
        }
        if ($path === '/api/v1/billing/products/SYNTHETIC_PRIVATE_PRODUCT') {
            return self::json($this->product);
        }
        if ($path === '/api/v1/billing/prices/create') {
            $this->price = $data + ['id' => 'SYNTHETIC_PRIVATE_PRICE'];
            return self::json($this->price, 201);
        }
        if ($path === '/api/v1/billing/prices/SYNTHETIC_PRIVATE_PRICE/update') {
            $this->price['active'] = false;
            return self::json($this->price);
        }
        if ($path === '/api/v1/billing/prices/SYNTHETIC_PRIVATE_PRICE') {
            return self::json($this->price);
        }
        if ($path === '/api/v1/billing/subscriptions/create') {
            $this->subscription = $data + ['id' => 'SYNTHETIC_PRIVATE_SUBSCRIPTION', 'status' => 'PENDING',
                'recurring' => ['period' => 1, 'period_unit' => 'MONTH'],
                'cancel_at_period_end' => false, 'enable_automatic_tax' => false, 'applied_discounts' => []];
            unset($this->subscription['items']);
            $this->runtime?->capture($this->subscription, 'subscription.created');
            return self::json($this->subscription, 201);
        }
        if ($path === '/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel') {
            if ($this->cancelEffective) {
                $this->subscription['status'] = 'CANCELLED';
            }
            if ($this->cancelledProjection !== null) {
                $this->subscription = ($this->cancelledProjection)($this->subscription);
            }
            $this->runtime?->capture($this->subscription, 'subscription.cancelled');
            return self::json($this->subscription);
        }
        if ($path === '/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION') {
            return self::json($this->subscription);
        }
        if ($path === '/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/items') {
            return self::json(['items' => [$this->item(++$this->itemPage)]] + $this->cursors);
        }
        if (
            preg_match(
                '~/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/items/ITEM_[1-3]$~D',
                $path
            ) === 1
        ) {
            return self::json($this->item($this->itemPage));
        }
        throw new RuntimeException('Synthetic provider refused an unexpected operation.');
    }

    /** @return array<string,mixed> */
    private function item(int $phase): array
    {
        return ['id' => 'ITEM_' . $phase, 'subscription_id' => 'SYNTHETIC_PRIVATE_SUBSCRIPTION', 'quantity' => 1,
            'applied_discounts' => [],
            'price' => $this->price + ['product' => $this->product]];
    }

    /**
     * @template T of array-key
     * @param array<T,mixed> $data
     * @return array<T,mixed>
     */
    private static function nativeIntegers(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::nativeIntegers($value);
            } elseif (in_array($key, ['period', 'days_until_due', 'quantity'], true) && is_string($value)) {
                $data[$key] = match ($value) {
                    '1' => 1, '30' => 30, default => $value
                };
            }
        }
        return $data;
    }

    /** @param array<array-key,mixed> $data */
    public static function json(array $data, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (new JsonCodec())->encode($data));
    }

    public function count(string $path, string $method = 'POST'): int
    {
        return count(array_filter($this->requests, static fn (RequestInterface $request): bool =>
            $request->getUri()->getPath() === $path && $request->getMethod() === $method));
    }
}
