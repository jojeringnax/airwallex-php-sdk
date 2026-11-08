<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use GuzzleHttp\Client;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;

/** Fresh organization run only; no alternate SDK root, old ledger, URL or scenario is accepted. */
final class CheckoutDiagnosticCommand
{
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run(#[SensitiveParameter] array $options, ?ClientInterface $httpClient = null): array
    {
        $failure = ['scenario' => 'checkout-diagnostic', 'api_version' => '2026-08-21', 'result' => 'fail'];
        $credentials = $options['credentials-file'] ?? null;
        $directory = $options['state-dir'] ?? null;
        if (
            ($options['environment'] ?? null) !== 'sandbox' || !is_string($credentials) || $credentials === ''
            || ($directory !== null && (!is_string($directory) || $directory === ''))
            || array_diff(array_keys($options), ['environment', 'credentials-file', 'state-dir']) !== []
        ) {
            return $failure;
        }
        try {
            $diagnostics = new CheckoutDiagnostics($httpClient ?? new Client([
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                'timeout' => 30, 'connect_timeout' => 10,
            ]));
            $result = SandboxCommand::run([
                'scenario' => 'billing-checkouts', 'environment' => 'sandbox', 'context' => 'organization',
                'credentials-file' => $credentials, 'state-dir' => $directory,
            ], static fn (Configuration $config, string $context): SandboxSession
                => new SandboxSession($config, $context, $diagnostics));
            // The original safe SDK fields can contain unlisted codes; only this observer emits literal codes.
            unset($result['provider_code'], $result['provider_source']);
            $steps = $result['steps'] ?? null;
            if (is_array($steps)) {
                foreach ($steps as $name => $step) {
                    if (is_array($step)) {
                        unset($step['provider_code'], $step['provider_source']);
                        $steps[$name] = $step;
                    }
                }
                $result['steps'] = $steps;
            }
            $result['diagnostics'] = $diagnostics->report();
            return $result;
        } catch (\Throwable) {
            return $failure;
        }
    }
}
