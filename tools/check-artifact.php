<?php

declare(strict_types=1);

require __DIR__ . '/consumer-functions.php';
require __DIR__ . '/artifact-functions.php';

$root = dirname(__DIR__);
$options = getopt('', ['lowest', 'output:']);
$options = $options === false ? [] : $options;
$output = $options['output'] ?? null;
if ($output !== null && (!is_string($output) || !str_starts_with($output, '/') || file_exists($output))) {
    throw new RuntimeException('Artifact output must be a new absolute directory.');
}
$commit = capturePackageCommand(['git', 'rev-parse', 'HEAD'], $root);
if (preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1) {
    throw new RuntimeException('Package source commit is unavailable.');
}
$working = packageSourceContents(expectedPackageFiles($root));
$committed = committedPackageContents($root, $commit);
$clean = capturePackageCommand(['git', 'status', '--porcelain', '--untracked-files=normal'], $root) === ''
    && $working === $committed;
if ($output !== null && !$clean) {
    throw new RuntimeException('Retained candidates require a clean checkout with exact committed package bytes.');
}
$temporary = sys_get_temp_dir() . '/airwallex-artifact-' . bin2hex(random_bytes(8));
$destination = $output ?? $temporary . '/archive';
if (!mkdir($destination, 0700, true)) {
    throw new RuntimeException('Cannot create artifact output directory.');
}
$lowest = array_key_exists('lowest', $options);
try {
    // A candidate is checked against immutable Git blobs, including during Composer's archive operation.
    $expected = $clean ? $committed : $working;
    $filename = 'airwallex-php-sdk-' . substr($commit, 0, 12);
    runCommand([
        ...consumerComposerCommand(), 'archive', '--format=tar', '--dir=' . $destination,
        '--file=' . $filename, '--no-interaction', '--no-plugins', '--no-scripts',
    ], $root);
    $archive = $destination . '/' . $filename . '.tar';
    $contents = inspectPackageArchive($archive, $expected);
    $versions = installAndCheckConsumer($temporary . '/consumer', [
        'type' => 'package', 'package' => archivedPackageDefinition($archive),
    ], $lowest);
    if (
        capturePackageCommand(['git', 'rev-parse', 'HEAD'], $root) !== $commit
        || ($clean && (
            capturePackageCommand(['git', 'status', '--porcelain', '--untracked-files=normal'], $root) !== ''
            || packageSourceContents(expectedPackageFiles($root)) !== $committed
        ))
    ) {
        throw new RuntimeException('Package source changed during artifact validation.');
    }
    $manifest = [
        'intended_release' => '0.1.0', 'source_commit' => $commit, 'source_clean' => $clean,
        'api_version' => '2026-08-21', 'webhook_version' => '2026-08-21',
        'php_version' => PHP_VERSION, 'dependencies' => $lowest ? 'lowest' : 'latest',
        'composer_version' => capturePackageCommand([...consumerComposerCommand(), '--version', '--no-ansi'], $root),
        'consumer_dependencies' => $versions,
        'archive' => basename($archive), 'sha256' => hash_file('sha256', $archive),
        'package_validation' => 'pass', 'release_ready' => false,
        'required_evidence' => ['https://github.com/jojeringnax/airwallex-php-sdk/issues/42'],
        'decision_reviews' => [
            'https://github.com/jojeringnax/airwallex-php-sdk/issues/60',
            'https://github.com/jojeringnax/airwallex-php-sdk/issues/64',
            'https://github.com/jojeringnax/airwallex-php-sdk/issues/91',
            'https://github.com/jojeringnax/airwallex-php-sdk/issues/95',
            'https://github.com/jojeringnax/airwallex-php-sdk/issues/104',
            'https://github.com/jojeringnax/airwallex-php-sdk/issues/127',
        ],
        'contents' => $contents,
    ];
    file_put_contents($destination . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo 'Artifact archive inspection and clean archive consumer passed (' . count($contents) . " files).\n";
    if ($output !== null) {
        echo 'Prepared artifacts: ' . $output . "\n";
    }
} finally {
    removeDirectory($temporary);
}
