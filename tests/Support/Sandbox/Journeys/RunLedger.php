<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use RuntimeException;
use SensitiveParameter;

/** Private test-run state. No credentials or provider payloads are stored. */
final class RunLedger
{
    /**
     * @var array{run_id:string,api_version:string,context_fingerprint:string,
     * source:array{base_commit:string,sdk_digest:string,harness_digest:string},
     * actions:array<string,array{request_id:string,body_hash:string}>,
     * resources:array<string,list<string>>}
     */
    private array $state;
    private bool $healthy = true;

    /** @param Closure(resource):bool $synchronize */
    private function __construct(
        private readonly string $path,
        private readonly Closure $identifiers,
        private readonly Closure $synchronize,
        string $runId,
        string $contextFingerprint,
    ) {
        $this->state = [
            'run_id' => $runId, 'api_version' => '2026-08-21', 'context_fingerprint' => $contextFingerprint,
            'source' => SourceEvidence::capture(), 'actions' => [], 'resources' => [],
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
        return new self($folder . '/ledger.json', $identifiers, $synchronize, $runId, $contextFingerprint);
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
        if ($id === '') {
            throw new RuntimeException('A created resource identifier is missing.');
        }
        if (!in_array($id, $this->state['resources'][$kind] ?? [], true)) {
            $this->state['resources'][$kind][] = $id;
            $this->persist();
        }
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
        return ['run_id' => $this->runId(), 'state' => '[private]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Private run state cannot be serialized.');
    }

    private function persist(): void
    {
        try {
            $temporary = $this->path . '.writing';
            $stream = @fopen($temporary, 'xb');
            if ($stream === false) {
                throw new RuntimeException('Cannot persist private run state.');
            }
            try {
                if (!@chmod($temporary, 0600)) {
                    throw new RuntimeException('Cannot protect private run state.');
                }
                $json = json_encode($this->state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
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
        } catch (\Throwable) {
            // Never return an in-memory identity after any part of its durable write failed.
            $this->healthy = false;
            throw new RuntimeException('Private run state could not be durably persisted.');
        }
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
