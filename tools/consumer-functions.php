<?php

declare(strict_types=1);

function copyDirectory(string $source, string $destination): void
{
    if (!mkdir($destination, 0700, true) && !is_dir($destination)) {
        throw new RuntimeException('Cannot create consumer fixture directory.');
    }
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot() || $entry->isLink()) {
            continue;
        }
        $target = $destination . '/' . $entry->getFilename();
        if ($entry->isDir()) {
            copyDirectory($entry->getPathname(), $target);
        } elseif (!copy($entry->getPathname(), $target)) {
            throw new RuntimeException('Cannot copy package source.');
        }
    }
}

function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isDir() && !$entry->isLink()) {
            removeDirectory($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}

/** @param list<string> $command */
function runCommand(array $command, string $directory): void
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $directory);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Consumer fixture command failed.');
    }
}

/** @return list<string> */
function consumerComposerCommand(): array
{
    $composer = getenv('COMPOSER_BINARY');
    return $composer ? [PHP_BINARY, $composer] : ['composer'];
}

/**
 * @param array<string,mixed> $repository
 * @return array<string,string>
 */
function installAndCheckConsumer(string $consumer, array $repository, bool $lowest): array
{
    if (!is_dir($consumer) && !mkdir($consumer, 0700, true)) {
        throw new RuntimeException('Cannot create clean consumer directory.');
    }
    $manifest = [
        'name' => 'airwallex-test/consumer',
        'require' => ['jojeringnax/airwallex-php-sdk' => 'dev-main', 'guzzlehttp/guzzle' => '^7.10'],
        'repositories' => [$repository],
        'config' => ['allow-plugins' => (object) []],
    ];
    file_put_contents($consumer . '/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $command = [
        ...consumerComposerCommand(), 'update', '--no-dev', '--no-interaction',
        '--no-plugins', '--no-scripts', '--prefer-dist',
    ];
    if ($lowest) {
        $command[] = '--prefer-lowest';
    }
    runCommand($command, $consumer);
    runCommand([...consumerComposerCommand(), 'check-platform-reqs', '--no-dev'], $consumer);
    if (!copy(__DIR__ . '/fixtures/consumer-smoke.php', $consumer . '/smoke.php')) {
        throw new RuntimeException('Cannot prepare consumer behavior check.');
    }
    runCommand([PHP_BINARY, $consumer . '/smoke.php'], $consumer);
    $lock = file_get_contents($consumer . '/composer.lock');
    if ($lock === false) {
        throw new RuntimeException('Clean consumer dependency evidence is unavailable.');
    }
    /** @var array{packages:list<array{name:string,version:string}>} $resolved */
    $resolved = json_decode($lock, true, flags: JSON_THROW_ON_ERROR);
    $versions = [];
    foreach ($resolved['packages'] as $package) {
        $versions[$package['name']] = $package['version'];
    }
    ksort($versions);
    return $versions;
}
