<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Synthetic provider only; its runtime writes local signed fixtures, never starts a tunnel. */
final class BillingWebhookFixtureProvider implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    public ?WebhookDeliveryFixtureRuntime $runtime = null;
    /** @var (Closure(RequestInterface):?ResponseInterface)|null */
    public ?Closure $intercept = null;
    /** @var array<string,mixed> */
    private array $registration = [];
    /** @var array<string,mixed> */
    private array $customer = [];
    /** @var array<string,mixed> */
    private array $checkout = [];
    private bool $deleted = false;
    private int $defaultLogins = 0;

    public function __construct(private readonly bool $defaultManagement = false)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->intercept !== null && ($response = ($this->intercept)($request)) !== null) {
            return $response;
        }
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
        if ($path === '/api/v1/authentication/login') {
            $token = $request->hasHeader('x-login-as') ? 'SYNTHETIC_PRIVATE_ACCOUNT_TOKEN'
                : ($this->defaultManagement && ++$this->defaultLogins === 1
                    ? 'SYNTHETIC_PRIVATE_DEFAULT_TOKEN' : 'SYNTHETIC_PRIVATE_ORG_TOKEN');
            return self::json(['token' => $token,
                'expires_at' => '2099-01-01T00:00:00Z'], $this->defaultManagement ? 201 : 200);
        }
        if ($this->defaultManagement) {
            $expected = $path === '/api/v1/account' ? 'SYNTHETIC_PRIVATE_ACCOUNT_TOKEN'
                : (str_starts_with($path, '/api/v1/webhooks/')
                    ? 'SYNTHETIC_PRIVATE_DEFAULT_TOKEN' : 'SYNTHETIC_PRIVATE_ORG_TOKEN');
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
        if ($path === '/api/v1/billing/billing_checkouts/create') {
            $this->checkout = $data + ['id' => 'SYNTHETIC_PRIVATE_CHECKOUT', 'status' => 'ACTIVE',
                'url' => 'https://checkout.sandbox.airwallex.com/pay?s=SYNTHETIC_PRIVATE_CAPABILITY'];
            $this->runtime?->capture($this->checkout, 'billing_checkout.created');
            return self::json($this->checkout, 201);
        }
        if ($path === '/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT/cancel') {
            $this->checkout['status'] = 'CANCELLED';
            $this->checkout['url'] = null;
            $this->runtime?->capture($this->checkout, 'billing_checkout.cancelled');
            return self::json($this->checkout);
        }
        if ($path === '/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT') {
            return self::json($this->checkout);
        }
        throw new RuntimeException('Synthetic provider refused an unexpected operation.');
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
