<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliation;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliationCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = [];
$valid = true;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if ($argument === '--read-own-alipay-hk-consent' && !array_key_exists('read-own-alipay-hk-consent', $options)) {
        $options['read-own-alipay-hk-consent'] = true;
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
$result = $valid ? AlipayConsentReconciliationCommand::run($options) : AlipayConsentReconciliation::initial();
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(1); // Even a current observation PASS does not resolve the historical Verify/financial disposition.
