<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\AuthenticationScenario;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['environment:', 'credentials-file:', 'scenario:', 'context:']);
$environment = $options['environment'] ?? null;
$path = $options['credentials-file'] ?? null;
$scenario = $options['scenario'] ?? null;
$context = $options['context'] ?? null;
if ($environment === null && $path === null && $scenario === null && $context === null) {
    echo "SKIP: provide --environment=sandbox, --credentials-file, --scenario=authentication and --context.\n";
    exit(0);
}
try {
    if (
        !is_string($environment) || !is_string($path) || $path === ''
        || $scenario !== 'authentication' || !is_string($context)
    ) {
        throw new RuntimeException('Explicit sandbox scenario configuration is required.');
    }
    $configuration = Configuration::load($environment, $path);
    $result = AuthenticationScenario::run($configuration, $context);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    // Only this fixed evidence envelope reaches stdout; no exception/body/trace is printed.
    $result = ['scenario' => 'authentication', 'result' => 'fail'];
    if ($error instanceof ApiException) {
        $result['http_status'] = $error->httpStatus();
    }
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
