<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoiceLinkCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = [];
$valid = true;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (
        preg_match('/^--(environment|credentials-file|prior-ledger)=(.+)$/D', $argument, $parts) !== 1
        || array_key_exists($parts[1], $options)
    ) {
        $valid = false;
        break;
    }
    $options[$parts[1]] = $parts[2];
}
$result = InvoiceLinkCommand::run($valid ? $options : []);
$result['observation_tool_sha256'] = hash_file('sha256', __FILE__);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
