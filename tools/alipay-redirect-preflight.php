<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayRedirectCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$arguments = $argv ?? [];
$options = getopt('', ['environment:', 'credentials-file:', 'state-dir:', 'create-one-alipay-hk'], $last);
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
        || !in_array($name, ['environment', 'credentials-file', 'state-dir', 'create-one-alipay-hk'], true)
        || ($name === 'create-one-alipay-hk' && $argument !== '--create-one-alipay-hk')
        || ($name !== 'create-one-alipay-hk' && !str_contains($argument, '='))
    ) {
        $valid = false;
    }
}
if ($valid && array_key_exists('create-one-alipay-hk', $options)) {
    $options['create-one-alipay-hk'] = $options['create-one-alipay-hk'] === false;
}
$result = AlipayRedirectCommand::run($valid ? $options : []);
$result['redirect_tool_sha256'] = hash_file('sha256', __FILE__);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['result'] ?? null) === 'pass' ? 0 : 1);
