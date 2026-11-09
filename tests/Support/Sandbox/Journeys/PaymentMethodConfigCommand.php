<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Fixed external private intake; no account, query, target or timing selectors. */
final class PaymentMethodConfigCommand
{
    /**
     * @param array<string,mixed> $options
     * @param (Closure(string,string):RunLedger)|null $ledgers Failed-durability seam only.
     * @param (Closure():float)|null $clock Original monotonic case clock seam only.
     * @param (Closure():int)|null $unixClock Token-expiration seam only.
     * @return array<string,mixed>
     */
    public static function run(
        #[SensitiveParameter] array $options,
        ?ClientInterface $http = null,
        ?Closure $ledgers = null,
        ?Closure $clock = null,
        ?Closure $unixClock = null,
    ): array {
        $safe = PaymentMethodConfigObservation::initial();
        $transport = null;
        if (
            array_diff(array_keys($options), ['environment', 'credentials-file', 'state-dir',
            'read-one-alipay-hk-recurring-config']) !== [] || ($options['environment'] ?? null) !== 'sandbox'
            || ($options['read-one-alipay-hk-recurring-config'] ?? null) !== true
        ) {
            return $safe;
        }
        $path = $options['credentials-file'] ?? null;
        $directory = $options['state-dir'] ?? null;
        if (!is_string($path) || !is_string($directory)) {
            return $safe;
        }
        try {
            if (ini_get('allow_url_fopen') !== '1' || !in_array('https', stream_get_wrappers(), true)) {
                throw new RuntimeException('The native verified HTTPS runtime is unavailable.');
            }
            $caseClock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
            $started = $caseClock();
            $credentialProof = self::privatePath($path, false);
            $stateProof = self::privatePath($directory, true);
            $configuration = Configuration::load('sandbox', $path);
            $source = SourceEvidence::capture();
            $context = PaymentMethodConfigObservation::contextFingerprint($configuration);
            $tool = dirname(__DIR__, 4) . '/tools/payment-method-config-observation.php';
            $hash = @hash_file('sha256', $tool);
            if ($hash === false) {
                throw new RuntimeException('The fixed entry source is unavailable.');
            }
            $safe['phase'] = 'journal_create';
            $ledger = $ledgers === null ? RunLedger::create($context, $directory) : $ledgers($context, $directory);
            $folder = $directory . '/' . $ledger->runId();
            $folderProof = self::privatePath($folder, true);
            $gate = static function () use (
                $path,
                $directory,
                $folder,
                $credentialProof,
                $stateProof,
                $folderProof,
                $source,
                $context,
                $configuration,
                $tool,
                $hash,
            ): void {
                if (
                    self::privatePath($path, false) !== $credentialProof
                    || self::privatePath($directory, true) !== $stateProof
                    || self::privatePath($folder, true) !== $folderProof
                    || SourceEvidence::capture() !== $source || @hash_file('sha256', $tool) !== $hash
                    || PaymentMethodConfigObservation::contextFingerprint($configuration) !== $context
                ) {
                    throw new RuntimeException('The configured private source or context changed.');
                }
            };
            $transport = new PaymentMethodConfigTransport($configuration, $http, $caseClock, $started, $unixClock);
            $result = PaymentMethodConfigObservation::execute(
                $configuration,
                $ledger,
                $transport,
                $hash,
                $gate,
                $unixClock
            );
            $safe['phase'] = 'final_source_proof';
            $gate();
            $transport->assertTime();
            return $result;
        } catch (Throwable) {
            $transport?->poison();
            return $safe + ($transport?->report() ?? []);
        }
    }

    /** @return array{device:int,inode:int,mode:int,sha256?:string} */
    private static function privatePath(#[SensitiveParameter] string $path, bool $directory): array
    {
        clearstatcache();
        $stat = @stat($path);
        if (
            realpath($path) !== $path || is_link($path) || $stat === false
            || ($directory ? !is_dir($path) : !is_file($path))
            || ($stat['mode'] & 0777) !== ($directory ? 0700 : 0600)
        ) {
            throw new RuntimeException('Canonical private external paths are required.');
        }
        for ($parent = $directory ? $path : dirname($path);; $parent = dirname($parent)) {
            if (file_exists($parent . '/.git') || is_link($parent . '/.git')) {
                throw new RuntimeException('Private observations must remain outside Git.');
            }
            if ($parent === dirname($parent)) {
                break;
            }
        }
        $proof = ['device' => $stat['dev'], 'inode' => $stat['ino'], 'mode' => $stat['mode']];
        if (!$directory) {
            $hash = @hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('The credential source is unavailable.');
            }
            $proof['sha256'] = $hash;
        }
        return $proof;
    }
}
