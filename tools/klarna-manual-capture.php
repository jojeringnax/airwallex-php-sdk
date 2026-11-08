<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\KlarnaManualCaptureCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = [];
$valid = true;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if ($argument === '--create-one-klarna' && !array_key_exists('create-one-klarna', $options)) {
        $options['create-one-klarna'] = true;
    } elseif (
        preg_match('/^--(environment|credentials-file|state-dir|case)=(.+)$/D', $argument, $parts) === 1
        && !array_key_exists($parts[1], $options)
    ) {
        $options[$parts[1]] = $parts[2];
    } else {
        $valid = false;
        break;
    }
}
$result = KlarnaManualCaptureCommand::run($valid ? $options : []);
$result['tool_sha256'] = hash_file('sha256', __FILE__);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
