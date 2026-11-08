<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\AuthenticationScenario;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use RuntimeException;

/** The CLI and deterministic fixtures execute the same sanitized command boundary. */
final class SandboxCommand
{
    /**
     * @param array<string,mixed> $options
     * @param (Closure(Configuration,string):SandboxSession)|null $sessions Fixture-only transport injection.
     * @return array<string,mixed>
     */
    public static function run(array $options, ?Closure $sessions = null): array
    {
        $scenario = $options['scenario'] ?? null;
        $label = in_array($scenario, ['authentication', 'billing-catalogue'], true) ? $scenario : 'configuration';
        $result = ['scenario' => $label, 'api_version' => '2026-08-21', 'result' => 'fail'];
        $session = null;
        try {
            $environment = $options['environment'] ?? null;
            $path = $options['credentials-file'] ?? null;
            $context = $options['context'] ?? null;
            $directory = $options['state-dir'] ?? null;
            if (
                !is_string($environment) || !is_string($path) || $path === ''
                || !in_array($scenario, ['authentication', 'billing-catalogue'], true)
                || !is_string($context) || !in_array($context, ['organization', 'account'], true)
                || ($directory !== null && !is_string($directory))
            ) {
                throw new RuntimeException('Explicit sandbox scenario configuration is required.');
            }
            $result['source'] = SourceEvidence::capture();
            $configuration = Configuration::load($environment, $path);
            $sessions ??= static fn (Configuration $config, string $owner): SandboxSession
                => new SandboxSession($config, $owner);
            $session = $sessions($configuration, $context);
            $result['context'] = $context;
            $result['context_fingerprint'] = $session->contextFingerprint();
            if ($scenario === 'authentication') {
                return array_replace($result, AuthenticationScenario::run($configuration, $context));
            }
            if (!$session->isOrganization()) {
                throw new RuntimeException('Billing catalogue validation requires organization context.');
            }
            $legalEntity = $session->legalEntity();
            if ($legalEntity === null) {
                $result['result'] = 'blocked';
                $result['reason'] = 'legal_entity_configuration_absent';
            } else {
                $ledger = RunLedger::create($session->contextFingerprint(), $directory);
                $result = array_replace($result, BillingCatalogue::execute($session, $ledger, $legalEntity));
            }
        } catch (\Throwable $error) {
            // Preserve available safe evidence; provider bodies, IDs, messages and traces never enter it.
            $result['result'] = 'fail';
            if ($error instanceof ApiException) {
                $result['http_status'] = $error->httpStatus();
            }
        }
        if ($session !== null && $scenario === 'billing-catalogue') {
            $result['login_requests'] = $session->loginRequests();
        }
        return $result;
    }
}
