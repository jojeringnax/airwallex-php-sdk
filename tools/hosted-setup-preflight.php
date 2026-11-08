<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedSetupPreflight;

require dirname(__DIR__) . '/vendor/autoload.php';

$arguments = $argv ?? [];
$options = getopt('', [
    'environment:', 'credentials-file:', 'state-dir:', 'node-bin:', 'playwright-module:',
    'create-one-hosted-setup', 'render-owned-data', 'observe-one-method-config', 'observe-normal-reads',
    'observe-risk-bootstrap',
], $lastArgument);
$flags = ['create-one-hosted-setup', 'render-owned-data', 'observe-one-method-config', 'observe-normal-reads',
    'observe-risk-bootstrap'];
$allowed = ['environment', 'credentials-file', 'state-dir', 'node-bin', 'playwright-module', ...$flags];
if (is_array($options)) {
    $valid = $lastArgument === count($arguments);
} else {
    $options = [];
    $valid = false;
}
$seen = [];
foreach (array_slice($arguments, 1) as $argument) {
    $name = explode('=', substr($argument, 2), 2)[0];
    if (!str_starts_with($argument, '--') || !in_array($name, $allowed, true) || isset($seen[$name])) {
        $valid = false;
    }
    $seen[$name] = true;
    if (
        (in_array($name, $flags, true) && $argument !== '--' . $name)
        || (!in_array($name, $flags, true) && !str_contains($argument, '='))
    ) {
        $valid = false;
    }
}
if ($valid) {
    foreach ($flags as $flag) {
        if (array_key_exists($flag, $options)) {
            $options[$flag] = $options[$flag] === false;
        }
    }
    $result = HostedSetupPreflight::run($options);
} else {
    $result = ['scenario' => 'hosted-setup-preflight', 'api_version' => '2026-08-21',
        'result' => 'fail', 'phase' => 'configuration'];
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
// A preflight never proves completion or enables card entry, so it cannot report a passed journey.
exit(1);
