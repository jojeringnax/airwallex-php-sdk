<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;

/** No caller identity or old-operation resumption; default is fresh GET-only reconciliation. */
final class KlarnaIntentReconciliationCommand
{
    /**
     * @param array<string,mixed> $options
     * @param (Closure(string,string):RunLedger)|null $ledgers Fixture-only durability seam.
     * @param (Closure(int):void)|null $wait Fixture only.
     * @return array<string,mixed>
     */
    public static function run(
        #[SensitiveParameter] array $options,
        ?ClientInterface $http = null,
        ?Closure $ledgers = null,
        ?Closure $wait = null,
        int $attempts = 7,
    ): array {
        $safe = ['scenario' => 'klarna-intent-reconciliation', 'api_version' => '2026-08-21',
            'result' => 'fail', 'phase' => 'configuration', 'reconciliation_required' => true];
        if (
            array_diff(array_keys($options), ['environment', 'credentials-file', 'prior-ledger', 'state-dir',
            'cancel-unconfirmed']) !== [] || ($options['environment'] ?? null) !== 'sandbox'
            || (array_key_exists('cancel-unconfirmed', $options) && !is_bool($options['cancel-unconfirmed']))
        ) {
            return $safe;
        }
        $credentials = $options['credentials-file'] ?? null;
        $prior = $options['prior-ledger'] ?? null;
        $directory = $options['state-dir'] ?? null;
        if (!is_string($credentials) || !is_string($prior) || !is_string($directory)) {
            return $safe;
        }
        try {
            SimulationTransport::assertAvailable();
            $configuration = Configuration::load('sandbox', $credentials);
            $transport = new KlarnaReconciliationTransport($configuration->accountId(), $http);
            $session = new SandboxSession($configuration, 'account', $transport);
            $safe['phase'] = 'prior_validation';
            $reconciliation = KlarnaIntentReconciliation::load($prior, $directory, $session);
            $reconciliation->bind($transport);
            $ledger = $ledgers === null ? RunLedger::create($session->contextFingerprint(), $directory)
                : $ledgers($session->contextFingerprint(), $directory);
            return $reconciliation->execute(
                $session,
                $ledger,
                $transport,
                ($options['cancel-unconfirmed'] ?? false) === true,
                $wait,
                $attempts
            );
        } catch (\Throwable) {
            return $safe;
        }
    }
}
