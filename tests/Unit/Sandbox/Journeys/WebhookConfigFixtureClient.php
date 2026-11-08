<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/** Synthetic HTTP only; never used by the real observation command. */
final class WebhookConfigFixtureClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    /** @var (Closure(RequestInterface):void)|null */
    public ?Closure $intercept = null;

    /** @param list<ResponseInterface|Throwable> $responses */
    public function __construct(private array $responses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->intercept !== null) {
            ($this->intercept)($request);
        }
        $next = array_shift($this->responses);
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next ?? throw new RuntimeException('No synthetic response remains.');
    }
}
