<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SubscriptionWebhookProbe;

require dirname(__DIR__) . '/vendor/autoload.php';
$arguments = $argv ?? [];
$options = getopt('', ['environment:', 'credentials-file:', 'state-dir:', 'node-bin:', 'cloudflared-bin:',
    'create-one-subscription-webhook-pair', 'management-profile:'], $last);
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
            'create-one-subscription-webhook-pair', 'management-profile'], true)
        || ($name === 'create-one-subscription-webhook-pair'
            ? $argument !== '--create-one-subscription-webhook-pair'
            : !str_contains($argument, '='))
    ) {
        $valid = false;
    }
    $seen[$name] = true;
}
if (array_key_exists('create-one-subscription-webhook-pair', $options)) {
    $options['create-one-subscription-webhook-pair'] = $options['create-one-subscription-webhook-pair'] === false;
}
$result = SubscriptionWebhookProbe::run($valid ? $options : []);
$result['entry_sha256'] = hash_file('sha256', __FILE__);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
