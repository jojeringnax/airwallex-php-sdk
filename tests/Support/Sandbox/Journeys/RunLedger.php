<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use RuntimeException;
use SensitiveParameter;

/** Private test-run state may retain approved bounded resource JSON, never credentials or login tokens. */
final class RunLedger
{
    /**
     * @var array{run_id:string,api_version:string,context_fingerprint:string,
     * source:array{base_commit:string,sdk_digest:string,harness_digest:string},
     * actions:array<string,array{request_id:string,body_hash:string}>,
     * resources:array<string,list<string>>,observations:array<string,array<string,string>>}
     */
    private array $state;
    private bool $healthy = true;
    private ?string $durableHash = null;
    private ?int $completionMetadata = null;
    private bool $reservingCompletion = false;
    /** @var array{image_bytes:int,metadata_bytes:int,raw_bytes:int,pre_action_bytes:int} */
    private array $storage = ['image_bytes' => 0, 'metadata_bytes' => 0, 'raw_bytes' => 0, 'pre_action_bytes' => 0];
    private const array CLEANUP_RAW_PHASES = [
        'consent_cleanup_customer_initial_result' => true,
        'consent_cleanup_consent_initial_result' => true,
        'consent_cleanup_customer_guard_result' => true,
        'consent_cleanup_consent_guard_result' => true,
        'consent_cleanup_disable_result' => false,
        'consent_cleanup_consent_readback_result' => false,
    ];

    /** @param Closure(resource):bool $synchronize */
    private function __construct(
        private readonly string $path,
        private readonly Closure $identifiers,
        private readonly Closure $synchronize,
        string $runId,
        string $contextFingerprint,
        private readonly bool $cleanupBounded = false,
    ) {
        $this->state = [
            'run_id' => $runId, 'api_version' => '2026-08-21', 'context_fingerprint' => $contextFingerprint,
            'source' => SourceEvidence::capture(), 'actions' => [], 'resources' => [], 'observations' => [],
        ];
        $this->persist();
    }

    /** @param (Closure(resource):bool)|null $synchronize Test seam for failed durability guarantees. */
    public static function create(
        string $contextFingerprint,
        ?string $directory = null,
        ?Closure $identifiers = null,
        ?Closure $synchronize = null,
    ): self {
        return self::createState($contextFingerprint, $directory, $identifiers, $synchronize, false);
    }

    /** Fixed test-only retention profile; existing callers retain the original writer behavior.
     * @param (Closure(resource):bool)|null $synchronize Failed-durability seam only.
     */
    public static function createConsentCleanup(
        string $contextFingerprint,
        string $directory,
        ?Closure $identifiers = null,
        ?Closure $synchronize = null,
    ): self {
        return self::createState($contextFingerprint, $directory, $identifiers, $synchronize, true);
    }

    /** @param (Closure(resource):bool)|null $synchronize */
    private static function createState(
        string $contextFingerprint,
        ?string $directory,
        ?Closure $identifiers,
        ?Closure $synchronize,
        bool $cleanupBounded,
    ): self {
        if (preg_match('/^[a-f0-9]{64}$/D', $contextFingerprint) !== 1) {
            throw new RuntimeException('Run state requires a hashed account context.');
        }
        $directory ??= sys_get_temp_dir();
        if (!str_starts_with($directory, '/') && preg_match('/^[a-zA-Z]:[\\\\\/]/D', $directory) !== 1) {
            throw new RuntimeException('Run state requires an absolute local directory.');
        }
        $base = realpath($directory);
        if ($base === false || !is_dir($base)) {
            throw new RuntimeException('Run state directory is unavailable.');
        }
        for ($ancestor = $base;; $ancestor = dirname($ancestor)) {
            if (file_exists($ancestor . '/.git') || is_link($ancestor . '/.git')) {
                throw new RuntimeException('Private run state must remain outside Git checkouts.');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
        $identifiers ??= self::uuid(...);
        $synchronize ??= self::synchronize(...);
        $runId = 'sdk-validation-' . self::identifier($identifiers);
        $folder = $base . '/' . $runId;
        if (!@mkdir($folder, 0700)) {
            throw new RuntimeException('Cannot create a private run state directory.');
        }
        self::syncDirectory($base, $synchronize);
        return new self(
            $folder . '/ledger.json',
            $identifiers,
            $synchronize,
            $runId,
            $contextFingerprint,
            $cleanupBounded
        );
    }

    public function runId(): string
    {
        return $this->state['run_id'];
    }

    public function assertContext(string $contextFingerprint): void
    {
        if (!$this->healthy || !hash_equals($this->state['context_fingerprint'], $contextFingerprint)) {
            throw new RuntimeException('Run state is unavailable or belongs to another account context.');
        }
    }

    /** @return array{source:array{base_commit:string,sdk_digest:string,harness_digest:string},context_fingerprint:string} */
    public function evidence(): array
    {
        return ['source' => $this->state['source'], 'context_fingerprint' => $this->state['context_fingerprint']];
    }

    /** @param array<array-key,mixed> $body Body excludes the request_id which this consumer supplies. */
    public function requestId(string $action, #[SensitiveParameter] array $body, string $contextFingerprint): string
    {
        $this->assertContext($contextFingerprint);
        $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
        if (isset($this->state['actions'][$action])) {
            if ($this->state['actions'][$action]['body_hash'] !== $hash) {
                throw new RuntimeException('A test mutation identity cannot be reused for a different action.');
            }
            return $this->state['actions'][$action]['request_id'];
        }
        $id = self::identifier($this->identifiers);
        foreach ($this->state['actions'] as $existing) {
            if ($existing['request_id'] === $id) {
                throw new RuntimeException('Different test actions require different mutation identifiers.');
            }
        }
        $this->state['actions'][$action] = ['request_id' => $id, 'body_hash' => $hash];
        $this->persist(); // Persist before a consumer sends a mutation.
        return $id;
    }

    public function recordCreated(string $kind, #[SensitiveParameter] string $id): void
    {
        $this->assertContext($this->state['context_fingerprint']);
        if ($this->cleanupBounded) {
            $this->healthy = false;
            throw new RuntimeException('Original cleanup resources cannot become new inventory.');
        }
        if ($id === '') {
            throw new RuntimeException('A created resource identifier is missing.');
        }
        if (!in_array($id, $this->state['resources'][$kind] ?? [], true)) {
            $this->state['resources'][$kind][] = $id;
            $this->persist();
        }
    }

    /** @param array<string,string> $references Private observed identities; never included in evidence(). */
    public function recordObservation(
        string $phase,
        #[SensitiveParameter] array $references,
        string $contextFingerprint,
    ): void {
        $this->assertContext($contextFingerprint);
        if ($phase === '' || $references === []) {
            throw new RuntimeException('An observation requires a phase and private references.');
        }
        foreach ($references as $reference) {
            if ($reference === '') {
                throw new RuntimeException('An observed reference is missing.');
            }
        }
        if (isset($this->state['observations'][$phase])) {
            if ($this->state['observations'][$phase] !== $references) {
                throw new RuntimeException('An observation phase cannot replace its original private references.');
            }
            return;
        }
        $this->state['observations'][$phase] = $references;
        $this->persist();
    }

    /** @return list<string> */
    public function created(string $kind): array
    {
        return $this->state['resources'][$kind] ?? [];
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        return array_map(count(...), $this->state['resources']);
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return $this->cleanupBounded ? ['state' => '[private bounded original Consent cleanup]']
            : ['run_id' => $this->runId(), 'state' => '[private]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Private run state cannot be serialized.');
    }

    private function persist(): void
    {
        try {
            if ($this->cleanupBounded && $this->durableHash !== null) {
                $this->assertCleanupDurable($this->state['context_fingerprint']);
            }
            // The old writer still encodes after opening/chmod, preserving its failure order.
            $checked = $this->cleanupBounded ? $this->cleanupImage() : null;
            if (
                $this->cleanupBounded && (is_link($this->path)
                || (file_exists($this->path) && (!is_file($this->path) || filesize($this->path) > 1000000)))
            ) {
                throw new RuntimeException('The existing cleanup image is unavailable.');
            }
            $temporary = $this->path . '.writing';
            $stream = @fopen($temporary, 'xb');
            if ($stream === false) {
                throw new RuntimeException('Cannot persist private run state.');
            }
            try {
                if (!@chmod($temporary, 0600)) {
                    throw new RuntimeException('Cannot protect private run state.');
                }
                $json = $checked ?? json_encode($this->state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                if (
                    @fwrite($stream, $json) !== strlen($json) || !@fflush($stream)
                    || ($this->synchronize)($stream) !== true
                ) {
                    throw new RuntimeException('Cannot persist private run state.');
                }
            } finally {
                fclose($stream);
            }
            if (!@rename($temporary, $this->path)) {
                throw new RuntimeException('Cannot finalize private run state.');
            }
            self::syncDirectory(dirname($this->path), $this->synchronize);
            if ($this->cleanupBounded) {
                $this->durableHash = hash('sha256', $json);
                $this->assertCleanupDurable($this->state['context_fingerprint']);
            }
        } catch (\Throwable) {
            // Never return an in-memory identity after any part of its durable write failed.
            $this->healthy = false;
            throw new RuntimeException('Private run state could not be durably persisted.');
        }
    }

    /** @return array{image_bytes:int,metadata_bytes:int,raw_bytes:int,pre_action_bytes:int} */
    public function cleanupStorage(): array
    {
        $this->assertCleanupDurable($this->state['context_fingerprint']);
        return $this->storage;
    }

    public function assertCleanupDurable(string $context): void
    {
        $this->assertContext($context);
        clearstatcache();
        $stat = @lstat($this->path);
        $parent = dirname($this->path);
        $directory = @lstat($parent);
        if (
            !$this->cleanupBounded || $this->durableHash === null || is_link($this->path)
            || $stat === false || ($stat['mode'] & 0177777) !== 0100600
            || $stat['size'] > 1000000 || $directory === false
            || ($directory['mode'] & 0177777) !== 0040700 || realpath($parent) !== $parent
            || file_exists($this->path . '.writing') || is_link($this->path . '.writing')
            || @hash_file('sha256', $this->path) !== $this->durableHash
        ) {
            $this->healthy = false;
            throw new RuntimeException('The durable cleanup image changed.');
        }
    }

    public function assertCleanupActionBinding(
        string $context,
        string $uuid,
        string $claimHash,
        string $claimIdentity
    ): void {
        $this->assertCleanupDurable($context);
        $binding = $this->state['observations']['consent_cleanup_claim_binding'] ?? [];
        if (
            ($this->state['actions']['consent_disable_cleanup']['request_id'] ?? null) !== $uuid
            || ($binding['request_id'] ?? null) !== $uuid || ($binding['claim_sha256'] ?? null) !== $claimHash
            || ($binding['claim_identity_sha256'] ?? null) !== $claimIdentity
            || ($binding['run_id'] ?? null) !== $this->runId() || ($binding['context'] ?? null) !== $context
        ) {
            $this->healthy = false;
            throw new RuntimeException('The durable UUID-to-claim binding changed.');
        }
    }

    /** Reserve all remaining fixed metadata and both 64KiB post-action raw slots before POST. */
    public function reserveCleanupCompletion(string $context): void
    {
        $this->assertCleanupDurable($context);
        if ($this->completionMetadata !== null) {
            throw new RuntimeException('Cleanup completion can be reserved only once.');
        }
        // Validate the full reservation image before retaining it, including its own metadata.
        $this->reservingCompletion = true;
        try {
            $this->recordObservation('consent_cleanup_storage_reservation', [
                'post_action_raw_bytes' => '131072', 'remaining_metadata_bytes' => '16384',
            ], $context);
        } finally {
            $this->reservingCompletion = false;
        }
        $this->completionMetadata = $this->storage['metadata_bytes'];
    }

    private function cleanupImage(): string
    {
        if ($this->state['resources'] !== []) {
            throw new RuntimeException('Cleanup inventory must remain empty.');
        }
        $sizing = $this->state;
        $raw = 0;
        $pre = 0;
        foreach ($sizing['observations'] as $phase => &$references) {
            if (!array_key_exists('private_json', $references)) {
                continue;
            }
            if (!array_key_exists($phase, self::CLEANUP_RAW_PHASES)) {
                throw new RuntimeException('An unrecognized raw cleanup slot was selected.');
            }
            $body = $references['private_json'];
            if (strlen($body) > 65536 || !json_decode($body, false, 64, JSON_THROW_ON_ERROR) instanceof \stdClass) {
                throw new RuntimeException('A native bounded resource object is required.');
            }
            $raw += strlen($body);
            if (self::CLEANUP_RAW_PHASES[$phase]) {
                $pre += strlen($body);
            } elseif ($this->completionMetadata === null) {
                throw new RuntimeException('Post-action raw storage was not reserved.');
            }
            $references['private_json'] = '';
        }
        unset($references);
        $metadata = strlen(json_encode($sizing, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        if (
            $raw > 262144 || $pre > 131072 || $metadata > 65536
            || ($this->reservingCompletion && $metadata > 65536 - 16384)
            || ($this->completionMetadata !== null && $metadata > $this->completionMetadata + 16384)
        ) {
            throw new RuntimeException('The cleanup retention allocation is exhausted.');
        }
        $json = self::boundedCleanupImage(json_encode($this->state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->storage = ['image_bytes' => strlen($json), 'metadata_bytes' => $metadata,
            'raw_bytes' => $raw, 'pre_action_bytes' => $pre];
        return $json;
    }

    private static function boundedCleanupImage(string $json): string
    {
        if (strlen($json) > 1000000) {
            throw new RuntimeException('The cleanup image exceeds its pre-retention bound.');
        }
        return $json;
    }

    /** @param Closure(resource):bool $synchronize */
    private static function syncDirectory(string $directory, Closure $synchronize): void
    {
        $stream = @fopen($directory, 'r');
        if ($stream === false) {
            throw new RuntimeException('Cannot synchronize private run state directory.');
        }
        try {
            if ($synchronize($stream) !== true) {
                throw new RuntimeException('Cannot synchronize private run state directory.');
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private static function synchronize($stream): bool
    {
        return @fsync($stream);
    }

    private static function identifier(Closure $generator): string
    {
        $id = $generator();
        if (!is_string($id) || preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $id) !== 1) {
            throw new RuntimeException('Run identifiers must be UUID strings.');
        }
        return $id;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
