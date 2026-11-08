<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Internal\Auth\TokenProvider;
use Jojeringnax\Airwallex\Internal\Http\HttpExchange;
use Jojeringnax\Airwallex\Internal\Http\ResponseDecoder;
use RuntimeException;
use SensitiveParameter;

final class AuthenticationScenario
{
    /** @return array<string, string|int> */
    public static function run(#[SensitiveParameter] Configuration $configuration, string $context): array
    {
        if (!in_array($context, ['organization', 'account'], true)) {
            throw new RuntimeException('Select organization or account context explicitly.');
        }
        $transport = new GuardedTransport(new Client([
            'verify' => true,
            'allow_redirects' => false,
            'http_errors' => false,
            'connect_timeout' => 10,
            'timeout' => 30,
        ]));
        $factory = new HttpFactory();
        $provider = new TokenProvider(
            new HttpExchange(Environment::Sandbox, $transport, $factory, $factory),
            new ResponseDecoder(),
            $configuration->credentials(),
            Environment::Sandbox,
            $context === 'account' ? $configuration->accountId() : null,
            null,
            null,
            static fn (): int => time(),
        );
        $firstToken = $provider->token();
        $reusedToken = $provider->token();
        if ($firstToken !== $reusedToken || $transport->loginRequests !== 1) {
            throw new RuntimeException('Sandbox token reuse validation failed.');
        }
        return [
            'scenario' => 'authentication',
            'context' => $context,
            'api_version' => '2026-08-21',
            'result' => 'pass',
            'login_requests' => $transport->loginRequests,
        ];
    }
}
