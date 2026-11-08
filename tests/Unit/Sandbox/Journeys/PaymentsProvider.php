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
use SensitiveParameter;

/** Deterministic remote state with no network access; financial values never enter PHP floats. */
final class PaymentsProvider implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    /** @var array<string,array<string,mixed>> */
    public array $resources = [];
    /** @var list<array<string,mixed>> */
    public array $mutationBodies = [];
    public ?Closure $intercept = null;
    public ?Closure $response = null;
    public ?Closure $beforeMutation = null;
    public bool $pendingRefunds = false;
    private int $sequence = 0;

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->intercept !== null) {
            $response = ($this->intercept)($request);
            if ($response instanceof ResponseInterface) {
                return $response;
            }
        }
        $path = $request->getUri()->getPath();
        if ($path === '/api/v1/authentication/login') {
            return new Response(201, [], '{"token":"synthetic-payments-token","expires_at":"2100-01-01T00:00:00Z"}');
        }
        $codec = new JsonCodec();
        $body = (string) $request->getBody();
        $decoded = $body === '' ? [] : $codec->decodeObject($body);
        $data = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException('Fixture requires an object request body.');
            }
            $data[$key] = $value;
        }
        if ($request->getMethod() === 'POST') {
            $this->mutationBodies[] = $data;
            if ($this->beforeMutation !== null) {
                ($this->beforeMutation)($request, $data);
            }
        }
        if (str_starts_with($path, '/api/v1/simulation/pa/shopper_actions/')) {
            $url = $data['url'] ?? null;
            if (!is_string($url)) {
                throw new RuntimeException('Simulation fixture requires its returned URL.');
            }
            $id = basename($url);
            $this->resources[$id]['status'] = str_ends_with($path, '/verify') ? 'VERIFIED' : 'SUCCEEDED';
            if (str_ends_with($path, '/pay')) {
                $this->resources[$id]['captured_amount'] = $this->resources[$id]['amount'];
            }
            unset($this->resources[$id]['next_action']);
            return new Response(200);
        }
        $segments = explode('/', $path);
        $kind = $segments[4] ?? '';
        $id = $segments[5] ?? '';
        $operation = $segments[6] ?? '';
        $created = $id === 'create';
        if ($created) {
            $id = 'synthetic-' . $kind . '-' . ++$this->sequence;
            unset($data['request_id']);
            $data['id'] = $id;
            if ($kind === 'refunds') {
                $parent = $data['payment_intent_id'] ?? null;
                if (!is_string($parent) || !is_string($this->resources[$parent]['currency'] ?? null)) {
                    throw new RuntimeException('Fixture refund requires its owned Intent currency.');
                }
                $data['currency'] = $this->resources[$parent]['currency'];
            }
            $data['status'] = match ($kind) {
                'payment_intents', 'payment_consents' => 'REQUIRES_PAYMENT_METHOD',
                'refunds' => $this->pendingRefunds ? 'RECEIVED' : 'ACCEPTED',
                default => 'ACTIVE',
            };
            $this->resources[$id] = $data;
        } elseif ($request->getMethod() === 'POST') {
            if ($operation === 'confirm') {
                $method = $data['payment_method'] ?? null;
                if (is_array($method) && ($method['type'] ?? null) === 'card') {
                    $this->resources[$id]['status'] = 'REQUIRES_CAPTURE';
                    $attempt = 'synthetic-attempt-' . ++$this->sequence;
                    $this->resources[$id]['latest_payment_attempt'] = ['id' => $attempt];
                    $this->resources[$attempt] = [
                        'id' => $attempt, 'payment_intent_id' => $id, 'status' => 'AUTHORIZED',
                        'amount' => $this->resources[$id]['amount'], 'currency' => $this->resources[$id]['currency'],
                    ];
                } elseif (isset($data['payment_consent_id'])) {
                    $this->resources[$id]['status'] = 'SUCCEEDED';
                    $this->resources[$id]['captured_amount'] = $this->resources[$id]['amount'];
                } else {
                    $this->resources[$id]['status'] = 'REQUIRES_CUSTOMER_ACTION';
                    $this->resources[$id]['next_action'] = [
                        'type' => 'redirect', 'url' => 'https://api.sandbox.airwallex.com/pa/sandbox/pay/' . $id,
                    ];
                }
            } elseif ($operation === 'verify') {
                $this->resources[$id]['status'] = 'REQUIRES_CUSTOMER_ACTION';
                $this->resources[$id]['next_action'] = [
                    'type' => 'redirect', 'url' => 'https://api.sandbox.airwallex.com/pa/sandbox/verify/' . $id,
                ];
            } elseif (in_array($operation, ['capture', 'cancel', 'disable'], true)) {
                $this->resources[$id]['status'] = match ($operation) {
                    'capture' => 'SUCCEEDED', 'cancel' => 'CANCELLED', default => 'DISABLED',
                };
                if ($operation === 'capture') {
                    $this->resources[$id]['captured_amount'] = $data['amount'];
                }
            } else {
                throw new RuntimeException('Unexpected fixture mutation.');
            }
        }
        $resource = $this->resources[$id] ?? throw new RuntimeException('Unknown fixture resource.');
        if ($this->response !== null) {
            $resource = ($this->response)($request, $resource);
        }
        if (!is_array($resource)) {
            throw new RuntimeException('Invalid fixture response transformation.');
        }
        $decimalPaths = [];
        foreach (['amount', 'captured_amount'] as $field) {
            if (
                is_string($resource[$field] ?? null)
                && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $resource[$field]) === 1
            ) {
                $decimalPaths[] = '/' . $field;
            }
        }
        return new Response($created ? 201 : 200, [], $codec->encode($resource, $decimalPaths));
    }
}
