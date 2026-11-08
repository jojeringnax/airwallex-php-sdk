<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use RuntimeException;
use SensitiveParameter;

/** Separate sensitive receiver/configuration state; raw requests never enter RunLedger. */
final class WebhookDeliveryStore
{
    private readonly string $folder;
    /** @var array{dev:int,ino:int} */
    private readonly array $identity;
    /** @var Closure(resource):bool */
    private readonly Closure $synchronize;

    /** @param (Closure(resource):bool)|null $synchronize */
    public function __construct(string $directory, RunLedger $ledger, ?Closure $synchronize = null)
    {
        $base = realpath($directory);
        $path = $base === false ? false : realpath($base . '/' . $ledger->runId());
        if ($path === false || is_link($base . '/' . $ledger->runId())) {
            throw new RuntimeException('Private delivery state is unavailable.');
        }
        self::directory($path);
        $stat = @stat($path);
        if ($stat === false) {
            throw new RuntimeException('Private delivery identity is unavailable.');
        }
        $this->identity = ['dev' => $stat['dev'], 'ino' => $stat['ino']];
        $this->folder = $path;
        $this->synchronize = $synchronize ?? self::sync(...);
    }

    public function folder(): string
    {
        clearstatcache(true, $this->folder);
        self::directory($this->folder);
        $stat = @stat($this->folder);
        if ($stat === false || $stat['dev'] !== $this->identity['dev'] || $stat['ino'] !== $this->identity['ino']) {
            throw new RuntimeException('Private delivery identity changed.');
        }
        return $this->folder;
    }

    public static function directory(string $path): void
    {
        $mode = @fileperms($path);
        if (
            realpath($path) !== $path || !is_dir($path) || is_link($path)
            || $mode === false || ($mode & 0777) !== 0700
        ) {
            throw new RuntimeException('Delivery state requires a private regular directory.');
        }
        for ($ancestor = $path;; $ancestor = dirname($ancestor)) {
            if (file_exists($ancestor . '/.git') || is_link($ancestor . '/.git')) {
                throw new RuntimeException('Delivery state must remain outside Git checkouts.');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
    }

    public function write(string $name, #[SensitiveParameter] string $bytes): string
    {
        if (
            !in_array($name, ['cloudflared.yml', 'registration.json', 'destination.json', 'receiver-config.json',
                'billing-active.json', 'billing-cancelled.json'], true)
            || strlen($bytes) > 65536
        ) {
            throw new RuntimeException('Private delivery retention is outside its bounded contract.');
        }
        $path = $this->folder() . '/' . $name;
        $temporary = $path . '.writing';
        $stream = @fopen($temporary, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Private delivery retention cannot start.');
        }
        try {
            if (
                !@chmod($temporary, 0600) || @fwrite($stream, $bytes) !== strlen($bytes)
                || !@fflush($stream) || ($this->synchronize)($stream) !== true
            ) {
                throw new RuntimeException('Private delivery retention cannot synchronize.');
            }
        } finally {
            fclose($stream);
        }
        if (!@link($temporary, $path) || !@unlink($temporary)) {
            throw new RuntimeException('Private delivery retention cannot finalize exclusively.');
        }
        $parent = @fopen($this->folder(), 'r');
        if ($parent === false) {
            throw new RuntimeException('Private delivery parent is unavailable.');
        }
        try {
            if (($this->synchronize)($parent) !== true) {
                throw new RuntimeException('Private delivery parent cannot synchronize.');
            }
        } finally {
            fclose($parent);
        }
        return hash('sha256', $bytes);
    }

    public function read(string $name, int $limit = 65536): string
    {
        if (
            preg_match(
                '/^(?:registration\.json|billing-(?:active|cancelled)\.json|receiver-stats\.json'
                    . '|capture-\d{6}\.(?:body|json))$/D',
                $name
            ) !== 1
            || $limit < 1 || $limit > 65536
        ) {
            throw new RuntimeException('Private delivery read is outside its bounded contract.');
        }
        $path = $this->folder() . '/' . $name;
        $mode = @fileperms($path);
        $size = @filesize($path);
        if (
            realpath($path) !== $path || is_link($path) || !is_file($path)
            || $mode === false || ($mode & 0777) !== 0600 || $size === false || $size > $limit
        ) {
            throw new RuntimeException('Private delivery evidence is unavailable.');
        }
        $bytes = @file_get_contents($path, length: $limit + 1);
        if ($bytes === false || strlen($bytes) !== $size || strlen($bytes) > $limit) {
            throw new RuntimeException('Private delivery evidence changed or exceeded its boundary.');
        }
        return $bytes;
    }

    /** @param resource $stream */
    private static function sync($stream): bool
    {
        return @fsync($stream);
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['state' => '[private]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Private delivery state cannot be serialized.');
    }
}
