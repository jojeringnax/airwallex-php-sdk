<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingReconciliation;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;

require dirname(__DIR__) . '/vendor/autoload.php';

$result = ['phase' => 'reconcile_prior_subscription_create', 'result' => 'fail', 'api_version' => '2026-08-21'];
try {
    $options = getopt('', ['environment:', 'credentials-file:', 'ledger-file:']);
    $environment = $options['environment'] ?? null;
    $credentials = $options['credentials-file'] ?? null;
    $ledger = $options['ledger-file'] ?? null;
    if (!is_string($environment) || !is_string($credentials) || !is_string($ledger)) {
        throw new RuntimeException('Explicit sandbox and private state configuration is required.');
    }
    $configuration = Configuration::load($environment, $credentials);
    $session = new SandboxSession($configuration, 'organization');
    $references = BillingReconciliation::load($ledger, $session); // Context check precedes even authentication.
    $result = $references->inspect($session);
} catch (Throwable $error) {
    if ($error instanceof ApiException) {
        $result['http_status'] = $error->httpStatus();
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
