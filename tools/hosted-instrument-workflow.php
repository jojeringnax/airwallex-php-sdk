<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InstrumentWorkflow;

require dirname(__DIR__) . '/vendor/autoload.php';

$arguments = $argv ?? [];
$options = getopt('', ['environment:', 'credentials-file:', 'state-dir:', 'node-bin:', 'playwright-module:',
    'collect-one-synthetic-hosted-instrument'], $lastArgument);
$allowed = ['environment', 'credentials-file', 'state-dir', 'node-bin', 'playwright-module',
    'collect-one-synthetic-hosted-instrument'];
$valid = is_array($options) && $lastArgument === count($arguments);
$seen = [];
foreach (array_slice($arguments, 1) as $argument) {
    $name = explode('=', substr($argument, 2), 2)[0];
    if (
        !str_starts_with($argument, '--') || !in_array($name, $allowed, true) || isset($seen[$name])
        || ($name === 'collect-one-synthetic-hosted-instrument' ? $argument !== '--'
            . $name : !str_contains($argument, '='))
    ) {
        $valid = false;
    }
    $seen[$name] = true;
}
if ($valid) {
    $flag = 'collect-one-synthetic-hosted-instrument';
    $options[$flag] = ($options[$flag] ?? null) === false;
    $result = InstrumentWorkflow::run($options);
} else {
    $result = ['scenario' => 'hosted-instrument-workflow', 'api_version' => '2026-08-21',
        'result' => 'fail', 'phase' => 'configuration'];
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit($result['result'] === 'pass' ? 0 : 1);
