<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;

/** Fixed-root organization cleanup; no old operation is replayed and no target is discovered. */
final class ScheduleCleanupCommand
{
    /**
     * @param array<string,mixed> $options
     * @param (Closure(string,string):RunLedger)|null $ledgers Fixture-only durability seam.
     * @return array<string,mixed>
     */
    public static function run(
        #[SensitiveParameter] array $options,
        ?ClientInterface $http = null,
        ?Closure $clock = null,
        ?Closure $wait = null,
        ?Closure $ledgers = null,
    ): array {
        $safe = ['scenario' => 'billing-schedule-cleanup', 'api_version' => '2026-08-21',
            'result' => 'fail', 'phase' => 'configuration'];
        $allowed = ['environment', 'credentials-file', 'prior-ledger', 'state-dir', 'mode'];
        foreach ($options as $name => $value) {
            if (!in_array($name, $allowed, true) || !is_string($value) || $value === '') {
                return $safe;
            }
        }
        $credentials = $options['credentials-file'] ?? null;
        $prior = $options['prior-ledger'] ?? null;
        $directory = $options['state-dir'] ?? null;
        $mode = $options['mode'] ?? 'catalogue-only';
        if (
            ($options['environment'] ?? null) !== 'sandbox' || !is_string($credentials)
            || !is_string($prior) || !is_string($directory)
            || !in_array($mode, ['catalogue-only', 'verify-pending-and-cancel'], true)
        ) {
            return $safe;
        }
        try {
            $safe['source'] = SourceEvidence::capture();
            $configuration = Configuration::load('sandbox', $credentials);
            $session = new SandboxSession($configuration, 'organization', $http, $clock);
            $collector = $configuration->accountId();
            $cleanup = ScheduleCleanup::load($prior, $session, $collector, $mode === 'verify-pending-and-cancel');
            // Both original owner validation and new durable state precede the first authentication request.
            $fingerprint = BillingScheduleEvidence::contextFingerprint($session, $collector);
            $ledger = $ledgers === null ? RunLedger::create($fingerprint, $directory)
                : $ledgers($fingerprint, $directory);
            $safe = array_replace($safe, $cleanup->execute($session, $ledger, $collector, $clock, $wait));
        } catch (\Throwable $error) {
            if ($error instanceof ApiException) {
                $safe['http_status'] = $error->httpStatus();
            }
        }
        return $safe;
    }
}
