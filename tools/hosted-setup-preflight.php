<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedSetupPreflight;

require dirname(__DIR__) . '/vendor/autoload.php';

$arguments = $argv ?? [];
$options = getopt('', [
    'environment:', 'credentials-file:', 'state-dir:', 'node-bin:', 'playwright-module:', 'create-one-hosted-setup',
], $lastArgument);
$allowed = ['environment', 'credentials-file', 'state-dir', 'node-bin', 'playwright-module', 'create-one-hosted-setup'];
if (is_array($options)) {
    $valid = $lastArgument === count($arguments);
} else {
    $options = [];
    $valid = false;
}
foreach (array_slice($arguments, 1) as $argument) {
    $name = explode('=', substr($argument, 2), 2)[0];
    if (!str_starts_with($argument, '--') || !in_array($name, $allowed, true)) {
        $valid = false;
    }
    if (
        ($name === 'create-one-hosted-setup' && $argument !== '--create-one-hosted-setup')
        || ($name !== 'create-one-hosted-setup' && !str_contains($argument, '='))
    ) {
        $valid = false;
    }
}
if ($valid) {
    if (array_key_exists('create-one-hosted-setup', $options)) {
        $options['create-one-hosted-setup'] = $options['create-one-hosted-setup'] === false;
    }
    $result = HostedSetupPreflight::run($options);
} else {
    $result = ['scenario' => 'hosted-setup-preflight', 'api_version' => '2026-08-21',
        'result' => 'fail', 'phase' => 'configuration'];
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
// A preflight never proves completion or enables card entry, so it cannot report a passed journey.
exit(1);
