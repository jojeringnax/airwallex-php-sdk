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

/** Stateful synthetic provider only; never opens a socket. */
final class BillingScheduleProvider implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    /** @var array<string,array<array-key,mixed>> */
    public array $objects = [];
    /** @var (Closure(RequestInterface,self):?ResponseInterface)|null */
    public ?Closure $intercept = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->intercept !== null) {
            $response = ($this->intercept)($request, $this);
            if ($response !== null) {
                return $response;
            }
        }
        $path = $request->getUri()->getPath();
        if ($path === '/api/v1/authentication/login') {
            return new Response(201, [], '{"token":"fixture_token","expires_at":"2100-01-01T00:00:00Z"}');
        }
        if ($path === '/api/v1/account') {
            return new Response(200, [], '{"id":"fixture_account",'
                . '"account_details":{"legal_entity_id":"fixture_entity"}}');
        }
        if ($request->getMethod() === 'GET') {
            if (str_ends_with($path, '/items')) {
                $parent = $this->objects[substr($path, 0, -6)];
                $items = $parent['items'];
                if (!is_array($items) || !is_array($items[0] ?? null)) {
                    throw new RuntimeException('Invalid synthetic initial Item.');
                }
                $price = $items[0]['price_id'];
                if (!is_string($price)) {
                    throw new RuntimeException('Invalid synthetic Price identity.');
                }
                return self::response(['items' => [['id' => 'fixture_item', 'subscription_id' => $parent['id'],
                    'quantity' => 1, 'price' => $this->objects['/api/v1/billing/prices/' . $price]]],
                    'page_after' => null]);
            }
            return self::response($this->objects[$path] ?? throw new RuntimeException('Unknown synthetic GET.'));
        }
        $body = self::body($request);
        unset($body['request_id']);
        if (str_ends_with($path, '/create')) {
            $base = substr($path, 0, -7);
            $id = 'fixture_' . count($this->objects);
            $object = $body + ['id' => $id];
            if ($base === '/api/v1/billing/subscriptions') {
                $object += ['status' => 'PENDING', 'cancel_at_period_end' => false];
            }
            $this->objects[$base . '/' . $id] = $object;
            return self::response(['id' => $id], 201);
        }
        $base = substr($path, 0, strrpos($path, '/') ?: 0);
        $object = $this->objects[$base] ?? throw new RuntimeException('Unknown synthetic mutation.');
        if (str_ends_with($path, '/cancel')) {
            $object['status'] = 'CANCELLED';
            unset($object['duration'], $object['ends_at']);
        } else {
            if (($body['starts_at'] ?? null) === 'NOW') {
                $body['starts_at'] = '2028-01-01T00:00:00Z';
                $body['status'] = isset($object['trial_ends_at']) ? 'IN_TRIAL' : 'ACTIVE';
                $body['current_period_starts_at'] = '2028-01-01T00:00:00Z';
                $body['current_period_ends_at'] = '2028-01-02T00:00:00Z';
                $body['ends_at'] = ($object['duration'] ?? null) === ['period' => 7, 'period_unit' => 'DAY']
                    ? '2028-01-08T00:00:00Z' : '2028-01-02T00:00:00Z';
            }
            $metadata = $object['metadata'] ?? null;
            if (
                is_array($metadata) && ($metadata['sdk_validation_case'] ?? null) === 'flag-restore-end'
                && ($body['cancel_at_period_end'] ?? null) === true
            ) {
                $body['ends_at'] = $object['current_period_ends_at'] ?? null;
            }
            // The selected Subscription schema does not promise a remaining_duration response field.
            unset($body['remaining_duration']);
            $object = array_replace($object, $body);
        }
        $this->objects[$base] = $object;
        return self::response(['id' => $object['id']]);
    }

    /** @return array<array-key,mixed> */
    public static function body(RequestInterface $request): array
    {
        return (new JsonCodec())->decodeObject((string) $request->getBody(), ['/unit_amount'], [
            '/recurring/period', '/duration/period', '/remaining_duration/period',
            '/items/*/quantity', '/days_until_due',
        ]);
    }

    /** @param array<array-key,mixed> $object */
    public static function response(array $object, int $status = 200): ResponseInterface
    {
        return new Response($status, [], (new JsonCodec())->encode($object, ['/unit_amount']));
    }

    /** @return list<RequestInterface> */
    public function mutations(): array
    {
        return array_values(array_filter($this->requests, static fn (RequestInterface $request): bool
            => $request->getMethod() === 'POST' && $request->getUri()->getPath() !== '/api/v1/authentication/login'));
    }
}
