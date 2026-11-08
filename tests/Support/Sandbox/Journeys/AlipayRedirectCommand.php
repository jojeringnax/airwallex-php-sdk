<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;

/** Fixed-root/account development entry; no URL, pre-existing identity or context override input. */
final class AlipayRedirectCommand
{
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run(
        #[SensitiveParameter] array $options,
        ?ClientInterface $sdkHttp = null,
        ?ClientInterface $redirectHttp = null,
    ): array {
        $safe = ['scenario' => 'alipay-redirect-preflight', 'api_version' => '2026-08-21',
            'result' => 'fail', 'stage' => 'configuration'];
        try {
            $allowed = ['environment', 'credentials-file', 'state-dir', 'create-one-alipay-hk',
                'read-one-sandbox-mock', 'simulate-one-returned-sandbox-mock'];
            $path = $options['credentials-file'] ?? null;
            $directory = $options['state-dir'] ?? null;
            if (
                array_diff(array_keys($options), $allowed) !== [] || ($options['environment'] ?? null) !== 'sandbox'
                || ($options['create-one-alipay-hk'] ?? null) !== true || !is_string($path) || $path === ''
                || !is_string($directory) || $directory === ''
                || (array_key_exists('read-one-sandbox-mock', $options) && !is_bool($options['read-one-sandbox-mock']))
                || (array_key_exists('simulate-one-returned-sandbox-mock', $options)
                    && !is_bool($options['simulate-one-returned-sandbox-mock']))
                || (($options['read-one-sandbox-mock'] ?? false) === true
                    && ($options['simulate-one-returned-sandbox-mock'] ?? false) === true)
            ) {
                return $safe;
            }
            if (($options['simulate-one-returned-sandbox-mock'] ?? false) === true) {
                SimulationTransport::assertAvailable();
            }
            $safe['source'] = SourceEvidence::capture();
            $config = Configuration::load('sandbox', $path);
            $session = new SandboxSession($config, 'account', $sdkHttp);
            $ledger = RunLedger::create($session->contextFingerprint(), $directory);
            return AlipayRedirectPreflight::execute(
                $session,
                $ledger,
                new AlipayRedirect($redirectHttp),
                readOneSandboxMock: ($options['read-one-sandbox-mock'] ?? false) === true,
                simulateReturnedSandboxMock: ($options['simulate-one-returned-sandbox-mock'] ?? false) === true
            );
        } catch (\Throwable) {
            return $safe;
        }
    }
}
