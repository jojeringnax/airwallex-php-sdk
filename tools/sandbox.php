<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['environment:', 'credentials-file:', 'scenario:', 'context:', 'state-dir:']);
$environment = $options['environment'] ?? null;
$path = $options['credentials-file'] ?? null;
$scenario = $options['scenario'] ?? null;
$context = $options['context'] ?? null;
$stateDirectory = $options['state-dir'] ?? null;
if (
    $environment === null && $path === null && $scenario === null && $context === null
    && $stateDirectory === null
) {
    echo "SKIP: provide --environment=sandbox, --credentials-file, --scenario=authentication and --context.\n";
    exit(0);
}
$result = SandboxCommand::run($options);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
