<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\PaymentMethodConfigCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\PaymentMethodConfigObservation;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = [];
$valid = true;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (
        $argument === '--read-one-alipay-hk-recurring-config'
        && !array_key_exists('read-one-alipay-hk-recurring-config', $options)
    ) {
        $options['read-one-alipay-hk-recurring-config'] = true;
    } elseif (
        preg_match('/^--(environment|credentials-file|state-dir)=(.+)$/D', $argument, $parts) === 1
        && !array_key_exists($parts[1], $options)
    ) {
        $options[$parts[1]] = $parts[2];
    } else {
        $valid = false;
        break;
    }
}
$result = $valid ? PaymentMethodConfigCommand::run($options) : PaymentMethodConfigObservation::initial();
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
