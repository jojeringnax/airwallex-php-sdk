<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use GuzzleHttp\Client;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;

/** Fresh-run development command; it accepts neither an old ledger nor an alternate SDK root. */
final class BillingDiagnosticCommand
{
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run(#[SensitiveParameter] array $options, ?ClientInterface $httpClient = null): array
    {
        $failure = ['scenario' => 'billing-diagnostic', 'api_version' => '2026-08-21', 'result' => 'fail'];
        $credentials = $options['credentials-file'] ?? null;
        $directory = $options['state-dir'] ?? null;
        $scenario = $options['scenario'] ?? 'billing-lifecycle';
        if (
            ($options['environment'] ?? null) !== 'sandbox' || !is_string($credentials) || $credentials === ''
            || ($directory !== null && !is_string($directory))
            || !in_array($scenario, ['billing-lifecycle', 'billing-lifecycle-no-trial'], true)
        ) {
            return $failure;
        }
        try {
            $diagnostics = new SubscriptionErrorTransport($httpClient ?? new Client([
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                'timeout' => 30, 'connect_timeout' => 10,
            ]));
            $result = SandboxCommand::run([
                'scenario' => $scenario, 'environment' => 'sandbox', 'context' => 'organization',
                'credentials-file' => $credentials, 'state-dir' => $directory,
            ], static fn (Configuration $configuration, string $context): SandboxSession
                => new SandboxSession($configuration, $context, $diagnostics));
            // Diagnostic output permits exact public codes only through the dedicated inspector.
            unset($result['provider_code'], $result['provider_source']);
            $result['diagnostics'] = $diagnostics->evidence();
            return $result;
        } catch (\Throwable) {
            return $failure;
        }
    }
}
