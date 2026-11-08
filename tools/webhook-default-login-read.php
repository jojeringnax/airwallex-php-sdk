<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookConfigCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookConfigProfile;

require dirname(__DIR__) . '/vendor/autoload.php';

$arguments = $argv ?? [];
$options = getopt('', ['environment:', 'credentials-file:', 'state-dir:', 'read-one-page'], $last);
if (is_array($options)) {
    $valid = $last === count($arguments);
} else {
    $valid = false;
    $options = [];
}
foreach (array_slice($arguments, 1) as $argument) {
    $name = explode('=', substr($argument, 2), 2)[0];
    if (
        !str_starts_with($argument, '--')
        || ($name === 'read-one-page' ? $argument !== '--read-one-page'
            : (!str_contains($argument, '=')
                || !in_array($name, ['environment', 'credentials-file', 'state-dir'], true)))
    ) {
        $valid = false;
    }
}
if (($options['read-one-page'] ?? null) === false) {
    $options['read-one-page'] = true;
}
$result = WebhookConfigCommand::run($valid ? $options : [], profile: WebhookConfigProfile::DefaultLogin);
$result['tool_sha256'] = hash_file('sha256', __FILE__);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
