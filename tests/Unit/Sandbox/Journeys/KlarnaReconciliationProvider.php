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

/** Only synthetic reconciliation operations; no create/Confirm/simulation/capture/refund mutation. */
final class KlarnaReconciliationProvider implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    /** @var array<array-key,mixed> */
    public array $intent;
    /** @var array<array-key,mixed> */
    public array $refunds = ['items' => [], 'has_more' => false];
    /** @var (Closure(RequestInterface):void)|null */
    public ?Closure $before = null;
    /** @var (Closure(RequestInterface,ResponseInterface):ResponseInterface)|null */
    public ?Closure $alter = null;
    public bool $cancel = true;
    public string $target = 'PRIVATE_INTENT';

    /** @param array<array-key,mixed> $create */
    public function __construct(array $create)
    {
        $this->intent = $create + ['id' => 'PRIVATE_INTENT', 'status' => 'REQUIRES_CUSTOMER_ACTION',
            'captured_amount' => '0'];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        ($this->before ?? static function (): void {
        })($request);
        $path = $request->getUri()->getPath();
        $data = match ($path) {
            '/api/v1/authentication/login' => ['token' => 'PRIVATE_TOKEN', 'expires_at' => '2100-01-01T00:00:00Z'],
            '/api/v1/pa/payment_intents/' . $this->target => $this->intent,
            '/api/v1/pa/refunds' => $this->refunds,
            '/api/v1/pa/payment_intents/' . $this->target . '/cancel' => $this->cancelResponse($request),
            default => throw new RuntimeException('Unplanned synthetic reconciliation operation.'),
        };
        $response = new Response(200, ['Content-Type' => 'application/json'], (new JsonCodec())->encode(
            $data,
            ['/amount', '/captured_amount', '/order/products/*/unit_price']
        ));
        return $this->alter === null ? $response : ($this->alter)($request, $response);
    }

    /** @return array<array-key,mixed> */
    private function cancelResponse(RequestInterface $request): array
    {
        if ($request->getMethod() !== 'POST') {
            throw new RuntimeException('Synthetic cancellation requires POST.');
        }
        if ($this->cancel) {
            $this->intent['status'] = 'CANCELLED';
        }
        return $this->intent;
    }
}
