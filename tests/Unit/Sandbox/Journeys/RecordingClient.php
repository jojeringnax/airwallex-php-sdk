<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/** Fixture-only request inspection; the live harness does not install this client. */
final class RecordingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(private readonly ClientInterface $inner)
    {
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        return $this->inner->sendRequest($request);
    }
}
