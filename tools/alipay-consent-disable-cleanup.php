<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentDisableCleanup;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentDisableCleanupCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = [];
$valid = true;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (
        $argument === '--disable-own-alipay-hk-consent-once'
        && !array_key_exists('disable-own-alipay-hk-consent-once', $options)
    ) {
        $options['disable-own-alipay-hk-consent-once'] = true;
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
$result = $valid ? AlipayConsentDisableCleanupCommand::run($options) : AlipayConsentDisableCleanup::initial();
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(1); // Cleanup never resolves the original Verify/financial disposition.
