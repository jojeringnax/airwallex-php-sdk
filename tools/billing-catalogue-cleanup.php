<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingCatalogueCleanupCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['environment:', 'credentials-file:', 'prior-ledger:', 'state-dir:', 'mode:']);
$result = BillingCatalogueCleanupCommand::run($options === false ? [] : $options);
$result['cleanup_tool_sha256'] = hash_file('sha256', __FILE__);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
