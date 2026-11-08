<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\CheckoutDiagnosticCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$knownOptions = true;
$arguments = $argv ?? [];
foreach (array_slice($arguments, 1) as $argument) {
    if (
        str_starts_with($argument, '--')
        && preg_match('/^--(?:environment|credentials-file|state-dir)(?:=|$)/D', $argument) !== 1
    ) {
        $knownOptions = false;
    }
}
$index = 0;
$options = $knownOptions ? getopt('', ['environment:', 'credentials-file:', 'state-dir:'], $index) : [];
if (!$knownOptions || $index !== count($arguments)) {
    $options = [];
}
$result = CheckoutDiagnosticCommand::run($options === false ? [] : $options);
$result['diagnostic_tool_sha256'] = hash_file('sha256', __FILE__);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
