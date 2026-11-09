<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Separate fixed cleanup intake; the old GET-only command cannot acquire this profile. */
final class AlipayConsentDisableCleanupCommand
{
    /**
     * @param array<string,mixed> $options
     *
     * @param (Closure(string,string):RunLedger)|null $ledgers Bounded durability fixtures only.
     *
     * @param (Closure():float)|null $clock
     *
     * @param (Closure():int)|null $unixClock
     *
     * @param (Closure(resource):bool)|null $claimSynchronize
     *
     * @return array<string,mixed>
     */
    public static function run(
        #[SensitiveParameter] array $options,
        ?ClientInterface $http = null,
        ?Closure $ledgers = null,
        ?Closure $clock = null,
        ?Closure $unixClock = null,
        ?Closure $claimSynchronize = null,
    ): array {
        $safe = AlipayConsentDisableCleanup::initial();
        $transport = null;
        $result = null;
        if (
            array_diff(array_keys($options), ['environment', 'credentials-file', 'state-dir', 'prior-ledger',
            'disable-own-alipay-hk-consent-once']) !== [] || ($options['environment'] ?? null) !== 'sandbox'
            || ($options['disable-own-alipay-hk-consent-once'] ?? null) !== true
        ) {
            return $safe;
        }
        $credentials = $options['credentials-file'] ?? null;
        $directory = $options['state-dir'] ?? null;
        $priorPath = $options['prior-ledger'] ?? null;
        if (!is_string($credentials) || !is_string($directory) || !is_string($priorPath)) {
            return $safe;
        }
        try {
            $rawClock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
            $highWater = null;
            $caseClock = static function () use ($rawClock, &$highWater): float {
                $now = $rawClock();
                if (!is_finite($now) || ($highWater !== null && $now < $highWater)) {
                    throw new RuntimeException('The original cleanup clock changed.');
                }
                $highWater = $now;
                return $now;
            };
            $started = $caseClock();
            if (
                !is_finite($started) || ini_get('allow_url_fopen') !== '1'
                || !in_array('https', stream_get_wrappers(), true)
            ) {
                throw new RuntimeException('The original cleanup clock or native HTTPS runtime is unavailable.');
            }
            $credentialProof = self::pathProof($credentials, false);
            $rootProof = self::pathProof($directory, true);
            $configuration = Configuration::load('sandbox', $credentials);
            $source = SourceEvidence::capture();
            $tool = dirname(__DIR__, 4) . '/tools/alipay-consent-disable-cleanup.php';
            $toolHash = @hash_file('sha256', $tool);
            if ($toolHash === false) {
                throw new RuntimeException('The fixed cleanup entry is unavailable.');
            }
            // The unbound session is lazy; original proof/registry validation precedes authentication.
            $intakeTransport = new AlipayConsentReconciliationTransport(
                $configuration->accountId(),
                $http,
                $caseClock,
                $started
            );
            $intakeSession = new SandboxSession($configuration, 'account', $intakeTransport, $unixClock);
            $safe['phase'] = 'prior_validation';
            $prior = AlipayConsentReconciliation::loadForCleanup($priorPath, $directory, $intakeSession);
            $safe['phase'] = 'claim_precheck';
            $claimPath = $prior->cleanupClaimPath();
            if (file_exists($claimPath) || is_link($claimPath)) {
                throw new RuntimeException('The original registry is already claimed.');
            }
            $transport = AlipayConsentReconciliationTransport::forCleanup(
                $configuration,
                $prior,
                $http,
                $caseClock,
                $started,
                $unixClock
            );
            $session = new SandboxSession($configuration, 'account', $transport, $unixClock);
            $externalProof = static function () use (
                $credentials,
                $directory,
                $credentialProof,
                $rootProof,
                $source,
                $tool,
                $toolHash
): void {
                if (
                    self::pathProof($credentials, false) !== $credentialProof
                    || self::pathProof($directory, true) !== $rootProof || SourceEvidence::capture() !== $source
                    || @hash_file('sha256', $tool) !== $toolHash
                ) {
                    throw new RuntimeException('The configured cleanup source or private root changed.');
                }
            };
            $context = $prior->cleanupContext($toolHash);
            $safe['phase'] = 'journal_create';
            $ledger = $ledgers === null ? RunLedger::createConsentCleanup($context, $directory)
                : $ledgers($context, $directory);
            $externalProof();
            $cleanup = new AlipayConsentDisableCleanup(
                $prior,
                $session,
                $ledger,
                $transport,
                $toolHash,
                $externalProof,
                $claimSynchronize
            );
            $result = $cleanup->execute();
            $safe['phase'] = 'final_source_proof';
            $externalProof();
            $transport->assertTime();
            return $result;
        } catch (Throwable) {
            if ($result !== null) {
                return array_replace($result, ['result' => 'fail', 'cleanup_observation_result' => 'fail',
                    'phase' => $safe['phase']]);
            }
            return $safe + ($transport?->report() ?? []) + ($transport?->cleanupReport() ?? []);
        }
    }

    /**
     * @return array{dev:int,ino:int,mode:int,sha256?:string} */
    private static function pathProof(#[SensitiveParameter] string $path, bool $directory): array
    {
        clearstatcache();
        $stat = @lstat($path);
        if (
            realpath($path) !== $path || is_link($path) || $stat === false
            || ($directory ? !is_dir($path) : !is_file($path))
            || ($stat['mode'] & 0777) !== ($directory ? 0700 : 0600)
        ) {
            throw new RuntimeException('Canonical private cleanup paths are required.');
        }
        for ($parent = $directory ? $path : dirname($path);; $parent = dirname($parent)) {
            if (file_exists($parent . '/.git') || is_link($parent . '/.git')) {
                throw new RuntimeException('Private cleanup state must remain outside Git.');
            }
            if ($parent === dirname($parent)) {
                break;
            }
        }
        $proof = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'mode' => $stat['mode']];
        if (!$directory) {
            $hash = @hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('The original credentials changed.');
            }
            $proof['sha256'] = $hash;
        }
        return $proof;
    }
}
