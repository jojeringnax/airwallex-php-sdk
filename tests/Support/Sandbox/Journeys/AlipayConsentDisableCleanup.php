<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/** One original-owned cleanup observation; it cannot resolve historical financial disposition. */
final class AlipayConsentDisableCleanup
{
    /** @var array{dev:int,ino:int,mode:int,sha256:string}|null */
    private ?array $claimProof = null;
    private ?string $uuid = null;
    private bool $bindingDurable = false;
    /** @var array{base_commit:string,sdk_digest:string,harness_digest:string} */
    private readonly array $source;
    private readonly string $context;

    /**
     * @param Closure():void $externalProof
     *
     * @param (Closure(resource):bool)|null $synchronize Claim durability fixture only.
     */
    public function __construct(
        private readonly AlipayConsentReconciliation $prior,
        private readonly SandboxSession $session,
        private readonly RunLedger $ledger,
        private readonly AlipayConsentReconciliationTransport $transport,
        private readonly string $toolHash,
        private readonly Closure $externalProof,
        private readonly ?Closure $synchronize = null,
    ) {
        $this->source = SourceEvidence::capture();
        $this->context = $prior->cleanupContext($toolHash);
        $this->proof();
        $prior->bindCleanupTransport($transport, $ledger, $this->context, $this->proof(...));
    }

    /**
     * @return array<string,mixed> */
    public static function initial(): array
    {
        return ['scenario' => 'alipay-consent-disable-original-once', 'profile' => 'ACCOUNT',
            'api_version' => '2026-08-21', 'result' => 'fail', 'cleanup_observation_result' => 'unavailable',
            'phase' => 'configuration', 'current_consent_state' => 'UNAVAILABLE',
            'consent_cleanup' => 'pending_not_attempted', 'claim_durability' => 'not_attempted',
            'action_uuid_durable' => false, 'claim_binding_durable' => false,
            'historical_reconciliation_required' => true,
            'historical_verification_or_financial_disposition_unresolved' => true,
            'original_pipeline_result' => 'fail', 'original_pipeline_rewritten' => false,
            'customer_cleanup' => 'retained', 'financial_mutation_sent' => false,
            'prior_bytes_unchanged' => false, 'owner_chain_matched' => false];
    }

    /**
     * @return array<string,mixed> */
    public function execute(): array
    {
        $safe = self::initial();
        try {
            $safe['phase'] = 'initial_owner_reads';
            $this->proof();
            $this->prior->readCleanupCustomer($this->session);
            $this->prior->readCleanupConsent($this->session);
            $safe['owner_chain_matched'] = true;
            $state = $this->state($this->transport->native('consent_initial'));
            $safe['current_consent_state'] = $state;
            if ($state === 'DISABLED') {
                return $this->complete($safe, 'already_disabled_current_observation');
            }
            if ($state !== 'REQUIRES_PAYMENT_METHOD') {
                $safe['result'] = $safe['cleanup_observation_result'] = 'pending';
                $this->proof();
                return $this->finish($safe);
            }
            $safe['phase'] = 'claim';
            $safe['claim_durability'] = 'unestablished';
            $this->createClaim();
            $safe['claim_durability'] = 'durable';
            $this->proof();
            $safe['phase'] = 'action_uuid';
            $uuid = $this->ledger->requestId('consent_disable_cleanup', [
                'profile' => 'original_consent_disable_once',
                'claim_sha256' => $this->claimProof['sha256'] ?? '',
            ], $this->context);
            $this->uuid = $uuid;
            $safe['action_uuid_durable'] = true;
            $this->proof();
            $safe['phase'] = 'claim_binding';
            $this->ledger->recordObservation('consent_cleanup_claim_binding', [
                'request_id' => $uuid, 'claim_sha256' => $this->claimProof['sha256'] ?? '',
                'run_id' => $this->ledger->runId(), 'context' => $this->context,
                'claim_identity_sha256' => $this->claimIdentity(),
            ], $this->context);
            $this->bindingDurable = true;
            $safe['claim_binding_durable'] = true;
            $safe['phase'] = 'final_owner_reads';
            $this->proof();
            $this->prior->readCleanupCustomer($this->session);
            $this->prior->readCleanupConsent($this->session);
            $state = $this->state($this->transport->native('consent_guard'));
            $safe['current_consent_state'] = $state;
            if ($state === 'DISABLED') {
                return $this->complete($safe, 'already_disabled_current_observation');
            }
            if ($state !== 'REQUIRES_PAYMENT_METHOD') {
                $safe['result'] = $safe['cleanup_observation_result'] = 'pending';
                $this->proof();
                return $this->finish($safe);
            }
            $safe['phase'] = 'disable';
            $safe['consent_cleanup'] = 'pending_unverified';
            $this->proof();
            $this->transport->armCleanupDisable($uuid);
            $this->prior->disableCleanupConsent($this->session, $this->transport, $uuid);
            $safe['current_consent_state'] = $this->state($this->transport->native('disable'));
            $safe['phase'] = 'readback';
            $this->prior->readCleanupConsent($this->session);
            $safe['current_consent_state'] = $this->state($this->transport->native('consent_readback'));
            return $this->complete($safe, 'disabled_and_readback_verified');
        } catch (Throwable) {
            $safe['result'] = 'fail';
            $safe['cleanup_observation_result'] = 'fail';
            return $this->finish($safe);
        }
    }

    /**
     * @param array<string,mixed> $safe
     * @return array<string,mixed> */
    private function complete(array $safe, string $outcome): array
    {
        $this->proof();
        $this->ledger->recordObservation('consent_cleanup_complete', ['outcome' => $outcome], $this->context);
        $this->proof();
        $safe['result'] = 'pending';
        $safe['cleanup_observation_result'] = 'pass';
        $safe['consent_cleanup'] = $outcome;
        $safe['phase'] = 'observed';
        return $this->finish($safe);
    }

    /**
     * @param array<string,mixed> $safe
     * @return array<string,mixed> */
    private function finish(array $safe): array
    {
        try {
            $this->prior->assertCleanupOriginal($this->session);
            $safe['prior_bytes_unchanged'] = true;
        } catch (Throwable) {
            $safe['result'] = $safe['cleanup_observation_result'] = 'fail';
            $safe['owner_chain_matched'] = false;
        }
        return $safe + ['source' => $this->source, 'tool_sha256' => $this->toolHash,
            ...$this->transport->report(), ...$this->transport->cleanupReport()];
    }

    private function state(stdClass $native): string
    {
        $state = $native->status ?? null;
        if (!is_string($state)) {
            throw new RuntimeException('The native Consent state is unavailable.');
        }
        return in_array($state, ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION',
            'PENDING', 'PAUSED', 'VERIFIED', 'DISABLED'], true) ? $state : 'UNLISTED';
    }

    private function proof(): void
    {
        $this->transport->assertTime();
        ($this->externalProof)();
        $this->prior->assertCleanupOriginal($this->session);
        $this->ledger->assertCleanupDurable($this->context);
        if (SourceEvidence::capture() !== $this->source || $this->ledger->evidence()['source'] !== $this->source) {
            throw new RuntimeException('The current cleanup source changed.');
        }
        if ($this->claimProof !== null) {
            $this->assertClaim();
        }
        $this->transport->assertTime();
        if ($this->bindingDurable && $this->uuid !== null) {
            $this->ledger->assertCleanupActionBinding(
                $this->context,
                $this->uuid,
                $this->claimProof['sha256'] ?? '',
                $this->claimIdentity()
            );
        }
    }

    private function claimIdentity(): string
    {
        if ($this->claimProof === null) {
            throw new RuntimeException('The original claim identity is unavailable.');
        }
        return hash('sha256', json_encode(array_intersect_key(
            $this->claimProof,
            ['dev' => true, 'ino' => true, 'mode' => true]
        ), JSON_THROW_ON_ERROR));
    }

    private function createClaim(): void
    {
        $this->proof();
        $path = $this->prior->cleanupClaimPath();
        $bytes = self::boundedClaimBytes(json_encode(
            $this->prior->cleanupClaim($this->ledger, $this->toolHash),
            JSON_THROW_ON_ERROR
        ));
        $stream = @fopen($path, 'x+b'); // Exclusive creation also refuses an existing symlink.
        if ($stream === false) {
            throw new RuntimeException('The original cleanup registry is already claimed or unavailable.');
        }
        try {
            if (!@chmod($path, 0600)) {
                throw new RuntimeException('The private claim mode is unavailable.');
            }
            $opened = @fstat($stream);
            if (
                $opened === false || ($opened['mode'] & 0170000) !== 0100000
                || @fwrite($stream, $bytes) !== strlen($bytes) || !@fflush($stream)
                || !$this->synchronize($stream)
            ) {
                throw new RuntimeException('The immutable claim could not be synchronized.');
            }
        } finally {
            $closed = is_resource($stream) && @fclose($stream);
        }
        if (!$closed) {
            throw new RuntimeException('The claim stream did not close.');
        }
        $directory = @fopen(dirname($path), 'r');
        if ($directory === false) {
            throw new RuntimeException('The claim registry is unavailable.');
        }
        try {
            if (!$this->synchronize($directory)) {
                throw new RuntimeException('The claim parent could not be synchronized.');
            }
        } finally {
            $closed = is_resource($directory) && @fclose($directory);
        }
        if (!$closed) {
            throw new RuntimeException('The claim parent did not close.');
        }
        $this->claimProof = ['dev' => $opened['dev'], 'ino' => $opened['ino'],
            'mode' => $opened['mode'], 'sha256' => hash('sha256', $bytes)];
        $this->assertClaim();
    }

    private static function boundedClaimBytes(string $bytes): string
    {
        if (strlen($bytes) > 4096) {
            throw new RuntimeException('The immutable claim exceeded its pre-retention bound.');
        }
        return $bytes;
    }

    /**
     * @param resource $stream */
    private function synchronize($stream): bool
    {
        return $this->synchronize === null ? @fsync($stream) : ($this->synchronize)($stream);
    }

    private function assertClaim(): void
    {
        $path = $this->prior->cleanupClaimPath();
        clearstatcache();
        $stat = @lstat($path);
        if (
            $stat === false || $this->claimProof === null || is_link($path)
            || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600
            || $stat['size'] > 4096 || $stat['dev'] !== $this->claimProof['dev']
            || $stat['ino'] !== $this->claimProof['ino'] || $stat['mode'] !== $this->claimProof['mode']
            || @hash_file('sha256', $path) !== $this->claimProof['sha256']
        ) {
            throw new RuntimeException('The immutable original-registry claim changed.');
        }
    }

    /**
     * @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['cleanup' => '[private one-use original Consent cleanup]'];
    }

    /**
     * @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Private cleanup state cannot be serialized.');
    }
}
