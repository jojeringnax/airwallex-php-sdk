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

/** Deterministic provider/state only. Never installed in a real tool invocation. */
final class WebhookDeliveryFixtureProvider implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    public ?WebhookDeliveryFixtureRuntime $runtime = null;
    public bool $deleted = false;
    /** @var array<array-key,mixed> */
    public array $registration = [];
    /** @var array<array-key,mixed> */
    public array $intent = [];
    /** @var (Closure(RequestInterface):(ResponseInterface|null))|null */
    public ?Closure $intercept = null;
    public const string WEBHOOK = 'SYNTHETIC_PRIVATE_WEBHOOK';
    public const string INTENT = 'SYNTHETIC_PRIVATE_INTENT';

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->intercept !== null) {
            $response = ($this->intercept)($request);
            if ($response !== null) {
                return $response;
            }
        }
        $path = $request->getUri()->getPath();
        $body = (string) $request->getBody();
        $wire = $body === '' ? [] : (new JsonCodec())->decodeObject($body);
        if ($path === '/api/v1/authentication/login') {
            return new Response(201, [], '{"token":"SYNTHETIC_PRIVATE_TOKEN","expires_at":"2100-01-01T00:00:00Z"}');
        }
        if ($path === '/api/v1/webhooks/create') {
            $this->registration = ['id' => self::WEBHOOK, 'secret' => 'SYNTHETIC_PRIVATE_SECRET', ...$wire];
            return self::response(201, $this->registration);
        }
        if ($path === '/api/v1/webhooks/' . self::WEBHOOK) {
            return $this->deleted ? new Response(404, [], '{}') : self::response(200, $this->registration);
        }
        if ($path === '/api/v1/webhooks/' . self::WEBHOOK . '/delete') {
            $this->deleted = true;
            return self::response(200, ['id' => self::WEBHOOK, 'deleted' => true]);
        }
        if ($path === '/api/v1/pa/payment_intents/create') {
            $this->intent = ['id' => self::INTENT, 'status' => 'REQUIRES_PAYMENT_METHOD',
                'captured_amount' => '0', ...$wire];
            $this->runtime?->capture($this->intent, 'payment_intent.created');
            return self::response(201, $this->intent);
        }
        if ($path === '/api/v1/pa/payment_intents/' . self::INTENT) {
            return self::response(200, $this->intent);
        }
        if ($path === '/api/v1/pa/payment_intents/' . self::INTENT . '/cancel') {
            $this->intent['status'] = 'CANCELLED';
            $this->runtime?->capture($this->intent, 'payment_intent.cancelled');
            return self::response(200, $this->intent);
        }
        throw new RuntimeException('Unexpected synthetic route.');
    }

    /** @param array<array-key,mixed> $value */
    public static function response(int $status, array $value): Response
    {
        return new Response($status, [], (new JsonCodec())->encode($value, ['/amount', '/captured_amount']));
    }

    public function sends(string $path): int
    {
        return count(array_filter(
            $this->requests,
            static fn (RequestInterface $request): bool => $request->getUri()->getPath() === $path
        ));
    }
}
