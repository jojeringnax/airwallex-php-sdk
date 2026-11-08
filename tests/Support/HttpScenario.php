<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** @internal Deterministic request capture shared by core and resource tests. */
final class HttpScenario
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<array{int, string}|\Throwable> $responses */
    public function __construct(
        public array $responses,
        public readonly ClientInterface $client,
        public readonly RequestFactoryInterface $requestFactory,
        public readonly StreamFactoryInterface $streamFactory,
    ) {
    }
}
