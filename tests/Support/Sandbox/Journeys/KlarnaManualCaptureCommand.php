<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;
use Throwable;

/** Fixed account/Sandbox fresh-run entry; no retained identities, URLs, context or host inputs. */
final class KlarnaManualCaptureCommand
{
    /** @param array<string,mixed> $options
     * @param (Closure(string,string):RunLedger)|null $ledgers Durability fixture only.
     * @param (Closure(int):void)|null $sleep Fixture only.
     * @return array<string,mixed>
     */
    public static function run(
        #[SensitiveParameter] array $options,
        ?ClientInterface $sdkHttp = null,
        ?ClientInterface $redirectHttp = null,
        ?Closure $ledgers = null,
        ?Closure $sleep = null,
        int $readAttempts = 7,
    ): array {
        $safe = ['scenario' => 'klarna-manual-capture', 'api_version' => '2026-08-21',
            'result' => 'fail', 'stage' => 'configuration'];
        $allowed = ['environment', 'credentials-file', 'state-dir', 'case', 'create-one-klarna', 'use-guide-amount'];
        if (
            array_diff(array_keys($options), $allowed) !== [] || ($options['environment'] ?? null) !== 'sandbox'
            || ($options['create-one-klarna'] ?? null) !== true
            || !in_array($options['case'] ?? null, ['capture-refund', 'authorized-cancel'], true)
            || (array_key_exists('use-guide-amount', $options) && !is_bool($options['use-guide-amount']))
        ) {
            return $safe;
        }
        $path = $options['credentials-file'] ?? null;
        $directory = $options['state-dir'] ?? null;
        if (!is_string($path) || $path === '' || !is_string($directory) || $directory === '') {
            return $safe;
        }
        $guide = ($options['use-guide-amount'] ?? false) === true;
        $amount = $guide ? '100.00' : '1.23';
        $safe['amount_choice'] = $guide ? 'guide' : 'default';
        $diagnostics = null;
        try {
            SimulationTransport::assertAvailable();
            $safe['source'] = SourceEvidence::capture();
            $hash = hash_file('sha256', dirname(__DIR__, 4) . '/tools/klarna-manual-capture.php');
            if ($hash === false) {
                return $safe;
            }
            $safe['tool_sha256'] = $hash;
            $diagnostics = new PaymentsDiagnostics(new KlarnaTransport($sdkHttp));
            $session = new SandboxSession(
                Configuration::load('sandbox', $path),
                'account',
                $diagnostics
            );
            $fingerprint = $session->contextFingerprint();
            $ledger = $ledgers === null ? RunLedger::create($fingerprint, $directory)
                : $ledgers($fingerprint, $directory);
            $ledger->requestId('klarna_preflight', ['case' => $options['case'], 'amount' => $amount,
                'amount_choice' => $safe['amount_choice'], 'tool_sha256' => $hash,
                'api_version' => '2026-08-21'], $fingerprint); // Durable even before the first SDK login.
            return (new KlarnaManualCapture($session, $ledger, $redirectHttp, $sleep, $readAttempts, $amount))
                ->run($options['case']) + ['tool_sha256' => $hash, 'amount_choice' => $safe['amount_choice'],
                    'diagnostics' => $diagnostics->report()];
        } catch (Throwable) {
            return $safe + ['diagnostics' => $diagnostics?->report() ?? ['entries' => [], 'entries_dropped' => 0]];
        }
    }
}
