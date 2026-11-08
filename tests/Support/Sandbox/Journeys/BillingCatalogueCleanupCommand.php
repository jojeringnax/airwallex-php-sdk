<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;

final class BillingCatalogueCleanupCommand
{
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run(#[SensitiveParameter] array $options, ?ClientInterface $httpClient = null): array
    {
        $result = ['scenario' => 'billing-catalogue-cleanup', 'api_version' => '2026-08-21', 'result' => 'fail'];
        $credentials = $options['credentials-file'] ?? null;
        $previous = $options['prior-ledger'] ?? null;
        $directory = $options['state-dir'] ?? null;
        if (
            ($options['environment'] ?? null) !== 'sandbox' || !is_string($credentials) || $credentials === ''
            || !is_string($previous) || $previous === '' || !is_string($directory) || $directory === ''
        ) {
            return $result;
        }
        try {
            $result['source'] = SourceEvidence::capture();
            $session = new SandboxSession(Configuration::load('sandbox', $credentials), 'organization', $httpClient);
            $result['context'] = 'organization';
            $result['context_fingerprint'] = $session->contextFingerprint();
            $cleanup = BillingCatalogueCleanup::load($previous, $session); // Version/context precede authentication.
            $ledger = RunLedger::create($session->contextFingerprint(), $directory);
            $result = array_replace($result, $cleanup->execute($session, $ledger));
        } catch (\Throwable $error) {
            if ($error instanceof ApiException) {
                $result['http_status'] = $error->httpStatus();
            }
        }
        return $result;
    }
}
