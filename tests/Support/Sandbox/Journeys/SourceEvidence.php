<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/** Identifies the effective SDK and integration code, including uncommitted changes. */
final class SourceEvidence
{
    /** @return array{base_commit:string,sdk_digest:string,harness_digest:string} */
    public static function capture(): array
    {
        $root = dirname(__DIR__, 4);
        $process = @proc_open(
            ['git', '-C', $root, 'rev-parse', '--verify', 'HEAD'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot identify integration source revision.');
        }
        [$input, $output, $errors] = self::pipes($pipes);
        fclose($input);
        $contents = stream_get_contents($output);
        $commit = is_string($contents) ? trim($contents) : '';
        fclose($output);
        stream_get_contents($errors);
        fclose($errors);
        if (proc_close($process) !== 0 || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $commit) !== 1) {
            throw new RuntimeException('Cannot identify integration source revision.');
        }
        return [
            'base_commit' => $commit,
            'sdk_digest' => self::digest($root, ['src', 'resources/contracts'], ['composer.json']),
            'harness_digest' => self::digest($root, ['tests/Support/Sandbox'], ['tools/sandbox.php']),
        ];
    }

    /** @return array{resource,resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (!is_array($pipes)) {
            throw new RuntimeException('Cannot identify integration source revision.');
        }
        $input = $pipes[0] ?? null;
        $output = $pipes[1] ?? null;
        $errors = $pipes[2] ?? null;
        if (!is_resource($input) || !is_resource($output) || !is_resource($errors)) {
            throw new RuntimeException('Cannot identify integration source revision.');
        }
        return [$input, $output, $errors];
    }

    /**
     * @param list<string> $directories
     * @param list<string> $files
     */
    private static function digest(string $root, array $directories, array $files): string
    {
        foreach ($directories as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo) {
                    throw new RuntimeException('Cannot identify effective integration source.');
                }
                if ($file->isLink()) {
                    throw new RuntimeException('Integration source must contain regular local files.');
                }
                if ($file->isFile()) {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($files, SORT_STRING);
        $digest = hash_init('sha256');
        foreach ($files as $name) {
            $path = $root . '/' . $name;
            $hash = is_link($path) ? false : @hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('Cannot identify effective integration source.');
            }
            hash_update($digest, $name . "\0" . $hash . "\n");
        }
        return hash_final($digest);
    }
}
