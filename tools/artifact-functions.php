<?php

declare(strict_types=1);

/** @return array<string, string> Relative package path to its original regular file. */
function expectedPackageFiles(string $root): array
{
    $files = [];
    foreach (['composer.json', 'LICENSE', 'README.md', 'SECURITY.md'] as $name) {
        if (!is_file($root . '/' . $name) || is_link($root . '/' . $name)) {
            throw new RuntimeException('Required package metadata is unavailable.');
        }
        $files[$name] = $root . '/' . $name;
    }
    foreach (['src', 'resources', 'docs', 'examples'] as $directory) {
        $path = $root . '/' . $directory;
        if (!is_dir($path) || is_link($path)) {
            throw new RuntimeException('Required package content directory is unavailable.');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            if (!allowedPackagePath($relative)) {
                continue;
            }
            if ($entry->isLink()) {
                throw new RuntimeException('Package content must be regular local files.');
            }
            if (!$entry->isFile()) {
                continue;
            }
            $files[$relative] = $entry->getPathname();
        }
    }
    ksort($files);
    return $files;
}

function allowedPackagePath(string $path): bool
{
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')) {
        return false;
    }
    $segments = explode('/', $path);
    foreach ($segments as $segment) {
        if (
            $segment === '' || str_starts_with($segment, '.')
            || in_array($segment, [
                'AGENTS.md', 'CLAUDE.md', '.git', '.agents', '.claude', '.codex', 'vendor', 'node_modules',
                '.phpunit.cache', '.phpstan.cache', 'tests', 'tools', 'build',
            ], true)
        ) {
            return false;
        }
    }
    return in_array($path, ['composer.json', 'LICENSE', 'README.md', 'SECURITY.md'], true)
        || in_array($segments[0], ['src', 'resources', 'docs', 'examples'], true);
}

/**
 * @param array<string,string> $files
 * @return array<string,array{bytes:int,sha256:string}>
 */
function packageSourceContents(array $files): array
{
    $contents = [];
    foreach ($files as $path => $file) {
        $bytes = file_get_contents($file);
        if ($bytes === false) {
            throw new RuntimeException('Package source bytes are unavailable.');
        }
        $contents[$path] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }
    ksort($contents);
    return $contents;
}

/** @return array<string,array{bytes:int,sha256:string}> */
function committedPackageContents(string $root, string $commit): array
{
    $tree = capturePackageCommand([
        'git', '--no-replace-objects', 'ls-tree', '-r', '-z', '--full-tree', $commit,
    ], $root, false);
    $contents = [];
    foreach (explode("\0", $tree) as $entry) {
        if ($entry === '') {
            continue;
        }
        if (preg_match('/^([0-7]{6}) ([a-z]+) ([a-f0-9]+)\t(.+)$/sD', $entry, $fields) !== 1) {
            throw new RuntimeException('Cannot inspect committed package entries.');
        }
        $path = $fields[4];
        if (!allowedPackagePath($path)) {
            continue;
        }
        if (!in_array($fields[1], ['100644', '100755'], true) || $fields[2] !== 'blob') {
            throw new RuntimeException('Committed package content must be regular files.');
        }
        $blob = capturePackageCommand(['git', '--no-replace-objects', 'cat-file', 'blob', $fields[3]], $root, false);
        $contents[$path] = ['bytes' => strlen($blob), 'sha256' => hash('sha256', $blob)];
    }
    ksort($contents);
    return $contents;
}

/**
 * @param array<string, array{bytes:int,sha256:string}> $expected Immutable byte expectations.
 * @return array<string, array{bytes:int,sha256:string}>
 */
function inspectPackageArchive(string $archive, array $expected): array
{
    $container = new PharData($archive);
    $resolved = realpath($archive);
    if ($resolved === false) {
        throw new RuntimeException('Package archive is unavailable.');
    }
    $prefix = 'phar://' . str_replace('\\', '/', $resolved) . '/';
    $contents = [];
    foreach (new RecursiveIteratorIterator($container) as $entry) {
        if ($entry instanceof PharFileInfo && $entry->isLink()) {
            throw new RuntimeException('Package archive contains content outside the distribution.');
        }
        if (!$entry instanceof PharFileInfo || !$entry->isFile()) {
            continue;
        }
        $name = str_replace('\\', '/', $entry->getPathname());
        if (!str_starts_with($name, $prefix)) {
            throw new RuntimeException('Package archive has an unexpected structure.');
        }
        $relative = substr($name, strlen($prefix));
        if (!allowedPackagePath($relative) || !isset($expected[$relative])) {
            throw new RuntimeException('Package archive contains content outside the distribution.');
        }
        $hash = hash('sha256', $entry->getContent());
        $record = ['bytes' => $entry->getSize(), 'sha256' => $hash];
        if ($record !== $expected[$relative]) {
            throw new RuntimeException('Package archive content does not match its source.');
        }
        $contents[$relative] = $record;
    }
    ksort($contents);
    if (array_keys($contents) !== array_keys($expected)) {
        throw new RuntimeException('Package archive omits required source, schemas, documentation or examples.');
    }
    return $contents;
}

/** @return array<string, mixed> */
function archivedPackageDefinition(string $archive): array
{
    $container = new PharData($archive);
    $metadata = $container['composer.json'];
    /** @var array<string,mixed> $package */
    $package = json_decode($metadata->getContent(), true, flags: JSON_THROW_ON_ERROR);
    if (($package['name'] ?? null) !== 'jojeringnax/airwallex-php-sdk' || ($package['license'] ?? null) !== 'MIT') {
        throw new RuntimeException('Archived package identity or license is invalid.');
    }
    $requires = $package['require'] ?? null;
    if (!is_array($requires)) {
        throw new RuntimeException('Archived runtime requirements are unavailable.');
    }
    $runtimeNames = array_keys($requires);
    sort($runtimeNames);
    $approvedNames = [
        'php', 'php-64bit', 'psr/http-client', 'psr/http-factory', 'psr/http-message', 'psr/simple-cache',
    ];
    if ($runtimeNames !== $approvedNames) {
        throw new RuntimeException('Archived runtime dependencies differ from the accepted interface-only policy.');
    }
    // This local repository version is an installation fixture, not a tag or registry publication.
    $package['version'] = 'dev-main';
    $package['dist'] = ['type' => 'tar', 'url' => $archive];
    return $package;
}

/**
 * @param list<string> $command
 * @phpstan-impure Reads mutable process/filesystem state on every invocation.
 */
function capturePackageCommand(array $command, string $directory, bool $trim = true): string
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, $directory);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot inspect package source state.');
    }
    /** @var array{0:resource,1:resource} $pipes proc_open creates the requested stdin/stdout pipes. */
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || $output === false) {
        throw new RuntimeException('Cannot inspect package source state.');
    }
    return $trim ? trim($output) : $output;
}
