<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoiceAccessCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = [];
$valid = true;
$flags = ['read-one-pdf-prefix', 'read-one-payer-invoice', 'follow-one-pdf-redirect'];
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (in_array($argument, array_map(static fn (string $name): string => '--' . $name, $flags), true)) {
        $name = substr($argument, 2);
        if (array_key_exists($name, $options)) {
            $valid = false;
            break;
        }
        $options[$name] = true;
    } elseif (
        preg_match('/^--(environment|credentials-file|prior-ledger|state-dir)=(.+)$/D', $argument, $parts) === 1
        && !array_key_exists($parts[1], $options)
    ) {
        $options[$parts[1]] = $parts[2];
    } else {
        $valid = false;
        break;
    }
}
$result = InvoiceAccessCommand::run($valid ? $options : []);
$result['access_tool_sha256'] = hash_file('sha256', __FILE__);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
