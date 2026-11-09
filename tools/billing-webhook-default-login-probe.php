<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingWebhookProbe;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookManagementProfile;

require dirname(__DIR__) . '/vendor/autoload.php';
$arguments = $argv ?? [];
$options = getopt('', ['environment:', 'credentials-file:', 'state-dir:', 'node-bin:', 'cloudflared-bin:',
    'create-one-billing-webhook-and-checkout'], $last);
$valid = is_array($options) && $last === count($arguments);
if (!is_array($options)) {
    $options = [];
}
$seen = [];
foreach (array_slice($arguments, 1) as $argument) {
    $name = explode('=', substr($argument, 2), 2)[0];
    if (
        !str_starts_with($argument, '--') || isset($seen[$name])
        || !in_array($name, ['environment', 'credentials-file', 'state-dir', 'node-bin', 'cloudflared-bin',
            'create-one-billing-webhook-and-checkout'], true)
        || ($name === 'create-one-billing-webhook-and-checkout'
            ? $argument !== '--create-one-billing-webhook-and-checkout'
            : !str_contains($argument, '='))
    ) {
        $valid = false;
    }
    $seen[$name] = true;
}
if (array_key_exists('create-one-billing-webhook-and-checkout', $options)) {
    $options['create-one-billing-webhook-and-checkout'] = $options['create-one-billing-webhook-and-checkout'] === false;
}
$result = BillingWebhookProbe::run($valid ? $options : [], managementProfile: WebhookManagementProfile::DefaultLogin);
$result['entry_sha256'] = hash_file('sha256', __FILE__);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
