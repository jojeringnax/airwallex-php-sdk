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

/** Synthetic selected-schema states; no claim that a provider account permits these transitions. */
final class KlarnaProvider implements ClientInterface
{
    public const string REDIRECT = 'https://api.sandbox.airwallex.com/pa/redirect/hk/PRIVATE_attempt?checksum=PRIVATE';
    public const string MOCK = 'https://api.sandbox.airwallex.com/pa/sandbox/sg/mck_PRIVATE?checksum=PRIVATE';
    /** @var list<RequestInterface> */
    public array $requests = [];
    /** @var array<array-key,mixed> */
    public array $intent = [];
    /** @var array<array-key,mixed> */
    public array $refund = [];
    /** @var (Closure(RequestInterface,ResponseInterface):ResponseInterface)|null */
    public ?Closure $alter = null;
    /** @var (Closure(RequestInterface):void)|null */
    public ?Closure $before = null;
    public bool $authorize = true;
    public bool $capture = true;
    public bool $cancel = true;
    public string $refundState = 'ACCEPTED';

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->before !== null) {
            ($this->before)($request);
        }
        $path = $request->getUri()->getPath();
        $body = (string) $request->getBody();
        $data = $body === '' ? [] : (new JsonCodec())->decodeObject($body);
        if ($path === '/api/v1/authentication/login') {
            $response = self::response(['token' => 'PRIVATE_TOKEN', 'expires_at' => '2100-01-01T00:00:00Z']);
        } elseif ($path === '/api/v1/pa/payment_intents/create') {
            unset($data['request_id']);
            $this->intent = $data + ['id' => 'PRIVATE_INTENT', 'status' => 'REQUIRES_PAYMENT_METHOD',
                'captured_amount' => '0'];
            $response = self::response($this->intent);
        } elseif ($path === '/api/v1/pa/payment_intents/PRIVATE_INTENT/confirm') {
            $this->intent['status'] = 'REQUIRES_CUSTOMER_ACTION';
            $response = self::response($this->intent + ['next_action' => ['type' => 'redirect', 'method' => 'GET',
                'url' => self::REDIRECT]]);
        } elseif ($path === '/api/v1/simulation/pa/shopper_actions/pay') {
            if ($data !== ['url' => self::MOCK]) {
                throw new RuntimeException('Synthetic simulation target changed.');
            }
            $this->intent['status'] = $this->authorize ? 'REQUIRES_CAPTURE' : 'PENDING';
            $response = new Response(200);
        } elseif ($path === '/api/v1/pa/payment_intents/PRIVATE_INTENT/capture') {
            if ($this->capture) {
                $this->intent['status'] = 'SUCCEEDED';
                $this->intent['captured_amount'] = $data['amount'] ?? null;
            }
            $response = self::response($this->intent);
        } elseif ($path === '/api/v1/pa/payment_intents/PRIVATE_INTENT/cancel') {
            if ($this->cancel) {
                $this->intent['status'] = 'CANCELLED';
            }
            $response = self::response($this->intent);
        } elseif ($path === '/api/v1/pa/refunds/create') {
            $this->refund = ['id' => 'PRIVATE_REFUND', 'payment_intent_id' => $data['payment_intent_id'] ?? null,
                'amount' => $data['amount'] ?? null, 'currency' => 'EUR', 'status' => $this->refundState];
            $response = self::response($this->refund);
        } elseif ($path === '/api/v1/pa/payment_intents/PRIVATE_INTENT' && $request->getMethod() === 'GET') {
            $response = self::response($this->intent);
        } elseif ($path === '/api/v1/pa/refunds/PRIVATE_REFUND' && $request->getMethod() === 'GET') {
            $response = self::response($this->refund);
        } else {
            throw new RuntimeException('The fixture received an unplanned operation.');
        }
        return $this->alter === null ? $response : ($this->alter)($request, $response);
    }

    /** @param array<array-key,mixed> $data */
    public static function response(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (new JsonCodec())->encode(
            $data,
            ['/amount', '/captured_amount', '/order/products/*/quantity', '/order/products/*/unit_price']
        ));
    }
}
