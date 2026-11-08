<?php

declare(strict_types=1);

// Exercise a copied package so local credentials, dependencies and tooling stay outside the fixture.
$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/airwallex-consumer-' . bin2hex(random_bytes(8));
$package = $temporary . '/package';
$consumer = $temporary . '/consumer';

require __DIR__ . '/consumer-functions.php';

try {
    copyDirectory($root . '/src', $package . '/src');
    copy($root . '/composer.json', $package . '/composer.json');
    copy($root . '/LICENSE', $package . '/LICENSE');
    mkdir($consumer, 0700, true);
    // The temporary class proves the published PSR-4 mapping without adding SDK public API.
    file_put_contents($package . '/src/BootstrapAutoloadProbe.php', <<<'PHP'
<?php
namespace Jojeringnax\Airwallex;
final class BootstrapAutoloadProbe {}
PHP);
    $manifest = [
        'name' => 'airwallex-test/consumer',
        'require' => ['jojeringnax/airwallex-php-sdk' => 'dev-main'],
        'repositories' => [[
            'type' => 'path',
            'url' => $package,
            'options' => [
                'symlink' => false,
                'versions' => ['jojeringnax/airwallex-php-sdk' => 'dev-main'],
            ],
        ]],
        'config' => ['allow-plugins' => (object) []],
    ];
    file_put_contents($consumer . '/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $composer = getenv('COMPOSER_BINARY');
    $composerCommand = $composer ? [PHP_BINARY, $composer] : ['composer'];
    $command = [
        ...$composerCommand, 'update', '--no-dev', '--no-interaction', '--no-plugins', '--no-scripts', '--prefer-dist',
    ];
    if (in_array('--lowest', $argv, true)) {
        $command[] = '--prefer-lowest';
    }
    runCommand($command, $consumer);
    runCommand([...$composerCommand, 'check-platform-reqs', '--no-dev'], $consumer);
    file_put_contents($consumer . '/smoke.php', <<<'PHP'
<?php
$loader = require __DIR__ . '/vendor/autoload.php';
foreach ([
    'Jojeringnax\\Airwallex\\BootstrapAutoloadProbe',
    'Psr\\Http\\Client\\ClientInterface',
    'Psr\\Http\\Message\\RequestFactoryInterface',
    'Psr\\Http\\Message\\RequestInterface',
    'Psr\\SimpleCache\\CacheInterface',
] as $symbol) {
    if (!class_exists($symbol) && !interface_exists($symbol)) {
        throw new RuntimeException('Missing installed symbol: ' . $symbol);
    }
}
if (class_exists('PHPUnit\\Framework\\TestCase')) {
    throw new RuntimeException('Development dependencies leaked into consumer.');
}
echo "Clean consumer installation and autoloading passed.\n";
PHP);
    runCommand([PHP_BINARY, $consumer . '/smoke.php'], $consumer);
} finally {
    removeDirectory($temporary);
}
