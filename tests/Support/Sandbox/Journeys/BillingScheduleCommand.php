<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;

/** One explicit fresh organization-owned schedule case. No resume or arbitrary target/root options. */
final class BillingScheduleCommand
{
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run(
        #[SensitiveParameter] array $options,
        ?ClientInterface $httpClient = null,
        ?Closure $clock = null,
        ?Closure $wait = null,
    ): array {
        $result = ['scenario' => 'billing-schedule-evidence', 'api_version' => '2026-08-21', 'result' => 'fail'];
        $allowed = ['environment', 'context', 'credentials-file', 'state-dir', 'case'];
        foreach ($options as $name => $value) {
            if (!in_array($name, $allowed, true) || !is_string($value) || $value === '') {
                return $result;
            }
        }
        $credentials = $options['credentials-file'] ?? null;
        $directory = $options['state-dir'] ?? null;
        if (
            ($options['environment'] ?? null) !== 'sandbox' || ($options['context'] ?? null) !== 'organization'
            || !in_array($options['case'] ?? null, BillingScheduleEvidence::CASES, true)
            || !is_string($credentials) || !is_string($directory)
        ) {
            return $result;
        }
        $diagnostics = new BillingScheduleDiagnostics($httpClient ?? new Client([
            'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
            'timeout' => 30, 'connect_timeout' => 10,
        ]));
        try {
            $result['source'] = SourceEvidence::capture();
            $configuration = Configuration::load('sandbox', $credentials);
            $session = new SandboxSession($configuration, 'organization', $diagnostics, $clock);
            $result['case'] = $options['case'];
            $result['context'] = 'organization';
            $fingerprint = BillingScheduleEvidence::contextFingerprint($session, $configuration->accountId());
            $result['context_fingerprint'] = $fingerprint;
            // Persist and validate the external state directory before any authentication or provider request.
            $ledger = RunLedger::create($fingerprint, $directory);
            $result += ['run_id' => $ledger->runId()];
            $entity = $session->legalEntity();
            if ($entity === null) {
                $result['result'] = 'blocked';
                $result['reason'] = 'legal_entity_configuration_absent';
            } else {
                $result = array_replace($result, BillingScheduleEvidence::execute(
                    $session,
                    $ledger,
                    $options['case'],
                    $entity,
                    $configuration->accountId(),
                    $clock,
                    $wait,
                ));
            }
        } catch (\Throwable $error) {
            $result['result'] = 'fail';
            if ($error instanceof ApiException) {
                $result['http_status'] = $error->httpStatus();
            }
        }
        $result['diagnostics'] = $diagnostics->evidence();
        return $result;
    }
}
