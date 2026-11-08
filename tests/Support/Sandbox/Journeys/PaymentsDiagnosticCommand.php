<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use GuzzleHttp\Client;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;

/** Opt-in development command; fixtures inject their own deterministic PSR transport. */
final class PaymentsDiagnosticCommand
{
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run(#[SensitiveParameter] array $options, ?ClientInterface $httpClient = null): array
    {
        $failure = ['scenario' => 'payments-diagnostic', 'api_version' => '2026-08-21', 'result' => 'fail'];
        $credentials = $options['credentials-file'] ?? null;
        $directory = $options['state-dir'] ?? null;
        if (
            ($options['environment'] ?? null) !== 'sandbox' || !is_string($credentials)
            || $credentials === '' || ($directory !== null && !is_string($directory))
        ) {
            return $failure;
        }
        try {
            $diagnostics = new PaymentsDiagnostics($httpClient ?? new Client([
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                'timeout' => 30, 'connect_timeout' => 10,
            ]));
            $result = SandboxCommand::run([
                'scenario' => 'payments-lifecycle', 'environment' => 'sandbox', 'context' => 'account',
                'credentials-file' => $credentials, 'state-dir' => $directory,
            ], static fn (Configuration $config, string $context): SandboxSession
                => new SandboxSession($config, $context, $diagnostics));
            $result['diagnostics'] = $diagnostics->report();
            return $result;
        } catch (\Throwable) {
            return $failure;
        }
    }
}
