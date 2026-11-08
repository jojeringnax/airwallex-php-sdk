<?php

declare(strict_types=1);

// Copy the actual SDK and runtime schemas; the consumer supplies its own concrete PSR implementation.
$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/airwallex-consumer-' . bin2hex(random_bytes(8));
$package = $temporary . '/package';
$consumer = $temporary . '/consumer';

require __DIR__ . '/consumer-functions.php';

try {
    copyDirectory($root . '/src', $package . '/src');
    copyDirectory($root . '/resources', $package . '/resources');
    copy($root . '/composer.json', $package . '/composer.json');
    copy($root . '/LICENSE', $package . '/LICENSE');
    installAndCheckConsumer($consumer, [
        'type' => 'path',
        'url' => $package,
        'options' => [
            'symlink' => false,
            'versions' => ['jojeringnax/airwallex-php-sdk' => 'dev-main'],
        ],
    ], in_array('--lowest', $argv ?? [], true));
} finally {
    removeDirectory($temporary);
}
