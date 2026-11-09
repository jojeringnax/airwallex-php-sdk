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

/** Test-only original producer/current-read fixture; quoted numeric IDs remain strings. */
final class AlipayConsentReconciliationProvider implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    /** @var array<array-key,array<string,mixed>> */
    public array $resources = [];
    /** @var (Closure(RequestInterface):?ResponseInterface)|null */
    public ?Closure $intercept = null;
    /** @var (Closure(RequestInterface,array<string,mixed>):array<string,mixed>)|null */
    public ?Closure $response = null;
    private int $created = 0;
    private bool $verifyBlocked = true;

    public function __construct(private readonly bool $numeric = false)
    {
    }

    public function allowReads(): void
    {
        $this->requests = [];
        $this->verifyBlocked = false;
    }

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
        if ($path === '/api/v1/authentication/login') {
            return new Response(
                201,
                [],
                '{"token":"synthetic-payments-token","expires_at":"2100-01-01T00:00:00Z"}'
            );
        }
        if (str_ends_with($path, '/verify') && $this->verifyBlocked) {
            return new Response(400, [], '{"code":"payment_method_not_allowed"}');
        }
        if ($request->getMethod() === 'POST' && str_ends_with($path, '/create')) {
            $body = (new JsonCodec())->decodeObject((string) $request->getBody());
            $resource = [];
            foreach ($body as $key => $value) {
                if (!is_string($key)) {
                    throw new RuntimeException('Synthetic object key required.');
                }
                $resource[$key] = $value;
            }
            unset($resource['request_id']);
            ++$this->created;
            $id = $this->numeric ? ($this->created === 1 ? '123' : '456')
                : 'PRIVATE_RESOURCE_' . $this->created;
            $resource += ['id' => $id];
            if ($this->created === 2) {
                $resource['status'] = 'REQUIRES_PAYMENT_METHOD';
            }
            $this->resources[$id] = $resource;
            return new Response(201, [], (new JsonCodec())->encode($resource));
        }
        if ($request->getMethod() !== 'GET' || !isset($this->resources[basename($path)])) {
            throw new RuntimeException('Unexpected synthetic role.');
        }
        $resource = $this->resources[basename($path)];
        if ($this->response !== null) {
            $resource = ($this->response)($request, $resource);
        }
        return new Response(200, [], (new JsonCodec())->encode($resource));
    }
}
