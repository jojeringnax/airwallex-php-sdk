<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;
use Throwable;

/** Fixed fresh account entry; no old state, arbitrary target, amount, platform or wallet selector. */
final class AlipayConsentReuseCommand
{
    /** @param array<string,mixed> $options
     * @param (Closure(string,string):RunLedger)|null $ledgers Durability fixtures only.
     * @param (Closure(int):void)|null $sleep Clock-free poll fixture only.
     * @param (Closure():float)|null $clock Monotonic fixture only.
     * @return array<string,mixed>
     */
    public static function run(
        #[SensitiveParameter] array $options,
        ?ClientInterface $http = null,
        ?Closure $ledgers = null,
        ?Closure $sleep = null,
        ?Closure $clock = null,
        int $attempts = 7,
    ): array {
        $safe = ['scenario' => 'alipay-hk-consent-reuse', 'profile' => 'ACCOUNT', 'api_version' => '2026-08-21',
            'result' => 'fail', 'stage' => 'configuration'];
        if (
            array_diff(array_keys($options), ['environment', 'credentials-file', 'state-dir',
            'verify-and-charge-one-alipay-hk-consent']) !== [] || ($options['environment'] ?? null) !== 'sandbox'
            || ($options['verify-and-charge-one-alipay-hk-consent'] ?? null) !== true
        ) {
            return $safe;
        }
        $file = $options['credentials-file'] ?? null;
        $directory = $options['state-dir'] ?? null;
        if (!is_string($file) || !is_string($directory) || $attempts < 1 || $attempts > 7) {
            return $safe;
        }
        $diagnostics = null;
        try {
            $caseClock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
            $started = $caseClock();
            SimulationTransport::assertAvailable();
            self::privatePath($file, false);
            self::privatePath($directory, true);
            $configuration = Configuration::load('sandbox', $file);
            $source = SourceEvidence::capture();
            $tool = dirname(__DIR__, 4) . '/tools/alipay-hk-consent-reuse.php';
            $hash = hash_file('sha256', $tool);
            if ($hash === false) {
                return $safe;
            }
            $safe['tool_sha256'] = $hash;
            $transport = new AlipayConsentReuseTransport($configuration->accountId(), $http, $caseClock, $started);
            $diagnostics = new PaymentsDiagnostics($transport);
            $session = new SandboxSession($configuration, 'account', $diagnostics);
            $context = $session->contextFingerprint();
            $ledger = $ledgers === null ? RunLedger::create($context, $directory) : $ledgers($context, $directory);
            if ($source !== $ledger->evidence()['source']) {
                return $safe + ['reconciliation_required' => true, 'reason' => 'source_changed'];
            }
            $transport->bind($ledger, $context, $hash);
            $ledger->requestId('consent_reuse_preflight', ['profile' => 'ACCOUNT', 'amount' => '1.23',
                'currency' => 'HKD', 'os' => 'android', 'simulation' => 'original_verify_url_only',
                'tool_sha256' => $hash, 'api_version' => '2026-08-21'], $context);
            $result = (new AlipayConsentReuse($session, $ledger, $transport, $sleep, $attempts))->run();
            if ($source !== SourceEvidence::capture() || $hash !== hash_file('sha256', $tool)) {
                return $safe + ['reconciliation_required' => true, 'reason' => 'source_changed'];
            }
            return $result + ['tool_sha256' => $hash, 'diagnostics' => $diagnostics->report()];
        } catch (Throwable) {
            return $safe + ['reconciliation_required' => true,
                'diagnostics' => $diagnostics?->report() ?? ['entries' => [], 'entries_dropped' => 0]];
        }
    }

    private static function privatePath(#[SensitiveParameter] string $path, bool $directory): void
    {
        $resolved = realpath($path);
        if (
            $path === '' || $resolved !== $path || is_link($path)
            || ($directory ? !is_dir($path) : !is_file($path))
            || (fileperms($path) & 0777) !== ($directory ? 0700 : 0600)
        ) {
            throw new \RuntimeException('External private configuration and state require canonical protected paths.');
        }
        for ($parent = $directory ? $path : dirname($path);; $parent = dirname($parent)) {
            if (file_exists($parent . '/.git') || is_link($parent . '/.git')) {
                throw new \RuntimeException('Private Consent configuration and state must remain outside Git.');
            }
            if ($parent === dirname($parent)) {
                return;
            }
        }
    }
}
