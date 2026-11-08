<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\PaymentsDiagnosticCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['environment:', 'credentials-file:', 'state-dir:']);
$result = PaymentsDiagnosticCommand::run($options === false ? [] : $options);
$result['diagnostic_tool_sha256'] = hash_file('sha256', __FILE__);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
