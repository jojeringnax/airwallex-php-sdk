<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Fixed ACCOUNT GET-only entry; no original pipeline, transport or action can resume. */
final class AlipayConsentReconciliationCommand
{
    /**
     * @param array<string,mixed> $options
     * @param (Closure(string,string):RunLedger)|null $ledgers Durability fixture only.
     * @param (Closure():float)|null $clock Monotonic fixture only.
     * @return array<string,mixed>
     */
    public static function run(
        #[SensitiveParameter] array $options,
        ?ClientInterface $http = null,
        ?Closure $ledgers = null,
        ?Closure $clock = null,
    ): array {
        $safe = AlipayConsentReconciliation::initial();
        $transport = null;
        if (
            array_diff(array_keys($options), ['environment', 'credentials-file', 'prior-ledger', 'state-dir',
                'read-own-alipay-hk-consent']) !== [] || ($options['environment'] ?? null) !== 'sandbox'
            || ($options['read-own-alipay-hk-consent'] ?? null) !== true
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
            $caseClock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
            $started = $caseClock();
            $credentialsHash = self::credentials($credentials);
            $configuration = Configuration::load('sandbox', $credentials);
            $source = SourceEvidence::capture();
            $tool = dirname(__DIR__, 4) . '/tools/alipay-consent-reconciliation.php';
            $toolHash = @hash_file('sha256', $tool);
            if ($toolHash === false || !in_array('https', stream_get_wrappers(), true)) {
                throw new RuntimeException('The selected entry and native HTTPS runtime must be available.');
            }
            $externalProof = static function () use ($credentials, $credentialsHash, $source, $tool, $toolHash): void {
                if (
                    self::credentials($credentials) !== $credentialsHash || SourceEvidence::capture() !== $source
                    || @hash_file('sha256', $tool) !== $toolHash
                ) {
                    throw new RuntimeException('The configured private source changed.');
                }
            };
            $transport = new AlipayConsentReconciliationTransport(
                $configuration->accountId(),
                $http,
                $caseClock,
                $started
            );
            $session = new SandboxSession($configuration, 'account', $transport);
            $safe['phase'] = 'prior_validation';
            $reconciliation = AlipayConsentReconciliation::load($prior, $directory, $session);
            $transport->assertTime();
            $externalProof();
            $safe['phase'] = 'journal_create';
            $context = $session->contextFingerprint();
            $ledger = $ledgers === null ? RunLedger::create($context, $directory) : $ledgers($context, $directory);
            $result = $reconciliation->execute($session, $ledger, $transport, $toolHash, $externalProof);
            $safe['phase'] = 'final_source_proof';
            $externalProof();
            $transport->assertTime();
            return $result;
        } catch (Throwable) {
            return $safe + ($transport?->report() ?? []);
        }
    }

    private static function credentials(#[SensitiveParameter] string $path): string
    {
        clearstatcache();
        $permissions = @fileperms($path);
        if (
            realpath($path) !== $path || !is_file($path) || is_link($path)
            || $permissions === false || ($permissions & 0777) !== 0600
        ) {
            throw new RuntimeException('Canonical external private credentials are required.');
        }
        for ($parent = dirname($path);; $parent = dirname($parent)) {
            if (file_exists($parent . '/.git') || is_link($parent . '/.git')) {
                throw new RuntimeException('Private credentials must remain outside Git.');
            }
            if ($parent === dirname($parent)) {
                break;
            }
        }
        $hash = @hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('The original credential source is unavailable.');
        }
        return $hash;
    }
}
