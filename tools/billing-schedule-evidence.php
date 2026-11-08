<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingScheduleCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = [];
$valid = true;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (
        preg_match('/^--(environment|context|credentials-file|state-dir|case)=(.+)$/D', $argument, $parts) !== 1
        || array_key_exists($parts[1], $options)
    ) {
        $valid = false;
        break;
    }
    $options[$parts[1]] = $parts[2];
}
$result = BillingScheduleCommand::run($valid ? $options : []);
$result['diagnostic_tool_sha256'] = hash_file('sha256', __FILE__);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
