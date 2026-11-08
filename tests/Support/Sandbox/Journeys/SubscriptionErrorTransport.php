<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameter;

/** Passive inspection of one fixed route; preserves the response stream and stores only whitelisted evidence. */
final class SubscriptionErrorTransport implements ClientInterface
{
    /** @var array<string,mixed> */
    private array $evidence = [];

    public function __construct(private readonly ClientInterface $inner)
    {
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $target = $request->getMethod() === 'POST'
            && $request->getUri()->getHost() === 'api.sandbox.airwallex.com'
            && $request->getUri()->getPath() === '/api/v1/billing/subscriptions/create';
        if ($target) {
            $this->evidence = [];
        }
        $response = $this->inner->sendRequest($request);
        if (!$target || $response->getStatusCode() < 400) {
            return $response;
        }
        $this->evidence = [
            'phase' => 'fresh_subscription_create', 'http_status' => $response->getStatusCode(),
            'body_inspected' => false,
        ];
        $stream = $response->getBody();
        if (!$stream->isSeekable()) {
            return $response;
        }
        $position = null;
        try {
            $position = $stream->tell();
            $stream->rewind();
            $body = $stream->read(262145);
            if (strlen($body) <= 262144 && $stream->eof()) {
                $this->evidence = SubscriptionErrorEvidence::inspect($response->getStatusCode(), $body)
                    + ['body_inspected' => true];
            }
        } catch (\Throwable) {
            // The normal SDK decoder owns unreadable-response failure handling; no raw error escapes here.
        } finally {
            if ($position !== null) {
                $stream->seek($position);
            }
        }
        return $response;
    }

    /** @return array<string,mixed> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return $this->evidence;
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Diagnostic transports cannot be serialized.');
    }
}
