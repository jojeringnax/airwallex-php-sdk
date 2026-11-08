<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;

/** Fixed account configuration read; no destination/identity/paging/root/context overrides. */
final class WebhookConfigCommand
{
    /**
     * @param array<string,mixed> $options
     * @param (Closure(string,string):RunLedger)|null $ledgers Test-only failed-provenance seam.
     * @param (Closure(resource):bool)|null $synchronize Private snapshot durability seam.
     * @return array<string,mixed>
     */
    public static function run(
        #[SensitiveParameter] array $options,
        ?ClientInterface $http = null,
        ?Closure $ledgers = null,
        ?Closure $synchronize = null,
    ): array {
        $safe = ['scenario' => 'webhook-config-observation', 'api_version' => '2026-08-21',
            'result' => 'fail', 'phase' => 'configuration', 'context' => 'account',
            'mutation_sent' => false, 'receiver_followed' => false, 'raw_delivery_observed' => false,
            'signatures_verified' => false, 'global_absence_proven' => false];
        foreach ($options as $name => $value) {
            if (
                !in_array($name, ['environment', 'credentials-file', 'state-dir'], true)
                || !is_string($value) || $value === ''
            ) {
                return $safe;
            }
        }
        $path = $options['credentials-file'] ?? null;
        $directory = $options['state-dir'] ?? null;
        if (($options['environment'] ?? null) !== 'sandbox' || !is_string($path) || !is_string($directory)) {
            return $safe;
        }
        try {
            WebhookConfigTransport::assertAvailable();
            self::externalCredentials($path);
            $safe['source'] = SourceEvidence::capture();
            $configuration = Configuration::load('sandbox', $path);
            $fingerprint = WebhookConfigObservation::contextFingerprint($configuration);
            $safe['context_fingerprint'] = $fingerprint;
            $safe['phase'] = 'read_provenance';
            $ledger = $ledgers === null
                ? RunLedger::create($fingerprint, $directory) : $ledgers($fingerprint, $directory);
            return WebhookConfigObservation::execute($configuration, $ledger, $directory, $http, $synchronize);
        } catch (\Throwable) {
            return $safe;
        }
    }

    private static function externalCredentials(#[SensitiveParameter] string $path): void
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('External Sandbox credentials are unavailable.');
        }
        for ($ancestor = dirname($resolved);; $ancestor = dirname($ancestor)) {
            if (file_exists($ancestor . '/.git') || is_link($ancestor . '/.git')) {
                throw new RuntimeException('External credentials must remain outside Git checkouts.');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
    }
}
