<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameter;

final class GuardedTransport implements ClientInterface
{
    public int $loginRequests = 0;

    public function __construct(private readonly ClientInterface $transport)
    {
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        if (
            $uri->getScheme() !== 'https' || $uri->getHost() !== 'api.sandbox.airwallex.com'
            || ($uri->getPort() !== null && $uri->getPort() !== 443)
            || $uri->getUserInfo() !== '' || !str_starts_with($uri->getPath(), '/api/v1/')
        ) {
            throw new RuntimeException('Integration request rejected outside the sandbox API.');
        }
        if ($uri->getPath() === '/api/v1/authentication/login') {
            ++$this->loginRequests;
        }
        return $this->transport->sendRequest($request);
    }
}
