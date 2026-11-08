<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use RuntimeException;
use SensitiveParameter;

/** A permanent local once-only fence; a partial claim is never reclaimed or replayed. */
final class KlarnaReconciliationClaim
{
    private bool $used = false;
    private readonly string $registry;
    private readonly string $path;
    /** @var Closure(resource):bool */
    private readonly Closure $synchronize;
    /** @var Closure(resource,string):(int|false) */
    private readonly Closure $write;

    /**
     * @param (Closure(resource):bool)|null $synchronize Fixture-only durability seam.
     * @param (Closure(resource,string):(int|false))|null $write Fixture-only short-write seam.
     */
    public function __construct(
        #[SensitiveParameter] string $stateRoot,
        #[SensitiveParameter] string $context,
        #[SensitiveParameter] string $marker,
        #[SensitiveParameter] string $intent,
        ?Closure $synchronize = null,
        ?Closure $write = null,
    ) {
        $this->registry = $stateRoot . '/klarna-cancellation-claims';
        $this->path = $this->registry . '/' . hash('sha256', $context . "\n" . $marker . "\n" . $intent) . '.json';
        $this->write = $write ?? static fn ($stream, string $bytes): int|false
            => is_resource($stream) ? @fwrite($stream, $bytes) : false;
        $this->synchronize = $synchronize ?? static fn ($stream): bool => is_resource($stream) && @fsync($stream);
    }

    public function reserved(): bool
    {
        clearstatcache(true, $this->path);
        return $this->used || file_exists($this->path) || is_link($this->path)
            || (file_exists($this->registry) && !$this->privateRegistry());
    }

    /** @param array<string,string> $binding Private provenance, never a provider request body. */
    public function acquire(#[SensitiveParameter] array $binding): bool
    {
        if ($this->reserved()) {
            return false;
        }
        $this->used = true;
        try {
            if (!is_dir($this->registry)) {
                if (!@mkdir($this->registry, 0700) && !is_dir($this->registry)) {
                    throw new RuntimeException('Cancellation claim registry is unavailable.');
                }
            }
            if (!$this->privateRegistry()) {
                throw new RuntimeException('Cancellation claim registry is not private.');
            }
            $stream = @fopen($this->path, 'xb');
            if ($stream === false) {
                return false; // A concurrent or prior claimant wins permanently.
            }
            try {
                if (!@chmod($this->path, 0600)) {
                    throw new RuntimeException('Cancellation claim could not be protected.');
                }
                $bytes = json_encode(['kind' => 'once-only-klarna-cancel', 'binding' => $binding], JSON_THROW_ON_ERROR);
                $written = 0;
                do {
                    $count = ($this->write)($stream, substr($bytes, $written));
                    if ($count === false || $count < 1 || $count > strlen($bytes) - $written) {
                        throw new RuntimeException('Cancellation claim made no durable progress.');
                    }
                    $written += $count;
                } while ($written < strlen($bytes));
                if (!@fflush($stream) || ($this->synchronize)($stream) !== true) {
                    throw new RuntimeException('Cancellation claim is not durable.');
                }
            } finally {
                fclose($stream);
            }
            $this->syncDirectory($this->registry);
            $this->syncDirectory(dirname($this->registry));
            return true;
        } catch (\Throwable) {
            // Keep even an empty/partial claim. There is no automatic reset, repair or unlink.
            return false;
        }
    }

    private function privateRegistry(): bool
    {
        clearstatcache(true, $this->registry);
        $permissions = @fileperms($this->registry);
        return !is_link($this->registry) && is_dir($this->registry) && $permissions !== false
            && (PHP_OS_FAMILY === 'Windows' || ($permissions & 0777) === 0700);
    }

    private function syncDirectory(string $path): void
    {
        $stream = @fopen($path, 'r');
        if ($stream === false) {
            throw new RuntimeException('Cancellation claim directory is unavailable.');
        }
        try {
            if (($this->synchronize)($stream) !== true) {
                throw new RuntimeException('Cancellation claim directory is not durable.');
            }
        } finally {
            fclose($stream);
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['claim' => '[private once-only cancellation fence]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Private cancellation claims cannot be serialized.');
    }
}
