<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use RuntimeException;
use SensitiveParameter;

/**
 * One fresh organization-owned SETUP, private browser read only, then owned cancellation.
 * @phpstan-type Expectation array{checkout_id:string,mode:string,currency:string}
 */
final class HostedSetupPreflight
{
    /**
     * @param array<string,mixed> $options
     * @param (Closure(Configuration,string):SandboxSession)|null $sessions
     * @param (Closure(string,Expectation,bool,bool,bool,bool,?HostedRiskJournal):array<string,mixed>)|null $browser
     * Fixture-only private handoff.
     * @return array<string,mixed>
     */
    public static function run(array $options, ?Closure $sessions = null, ?Closure $browser = null): array
    {
        $safe = ['scenario' => 'hosted-setup-preflight', 'api_version' => '2026-08-21', 'result' => 'fail'];
        $session = null;
        $ledger = null;
        $expected = null;
        $phase = 'configuration';
        try {
            $allowed = ['environment', 'credentials-file', 'state-dir', 'node-bin',
                'playwright-module', 'create-one-hosted-setup', 'render-owned-data',
                'observe-one-method-config', 'observe-normal-reads', 'observe-risk-bootstrap'];
            if (array_diff(array_keys($options), $allowed) !== [] || ($options['environment'] ?? null) !== 'sandbox') {
                throw new RuntimeException('Explicit sandbox preflight configuration is required.');
            }
            $strings = [];
            foreach (['credentials-file', 'state-dir', 'node-bin', 'playwright-module'] as $key) {
                $value = $options[$key] ?? null;
                if (!is_string($value) || $value === '') {
                    throw new RuntimeException('Explicit sandbox preflight configuration is required.');
                }
                $strings[$key] = $value;
            }
            if (($options['create-one-hosted-setup'] ?? null) !== true) {
                throw new RuntimeException('One fresh preflight must be explicitly selected.');
            }
            $render = $options['render-owned-data'] ?? false;
            if (!is_bool($render)) {
                throw new RuntimeException('Explicit rendering configuration is invalid.');
            }
            $observeConfig = $options['observe-one-method-config'] ?? false;
            if (!is_bool($observeConfig) || ($observeConfig && !$render)) {
                throw new RuntimeException('Method observation requires explicit rendering.');
            }
            $observeNormal = $options['observe-normal-reads'] ?? false;
            if (!is_bool($observeNormal) || ($observeNormal && !$observeConfig)) {
                throw new RuntimeException('Normal reads require explicit method observation.');
            }
            $safe['normal_reads_requested'] = $observeNormal;
            $observeRisk = $options['observe-risk-bootstrap'] ?? false;
            if (!is_bool($observeRisk) || ($observeRisk && !$observeNormal)) {
                throw new RuntimeException('Risk bootstrap requires explicit normal reads.');
            }
            if ($observeRisk) {
                $safe['risk_bootstrap_requested'] = true;
            }
            $safe['render_requested'] = $render;
            $safe['method_config_requested'] = $observeConfig;
            $bridge = new HostedBrowser($strings['node-bin'], $strings['playwright-module']);
            $browser ??= $bridge->inspect(...);
            $safe['source'] = SourceEvidence::capture();
            $safe['browser_tool_hashes'] = HostedBrowser::sourceHashes();
            $configuration = Configuration::load('sandbox', $strings['credentials-file']);
            $sessions ??= static fn (Configuration $config, string $context): SandboxSession
                => new SandboxSession($config, $context);
            $session = $sessions($configuration, 'organization');
            if (!$session->isOrganization()) {
                throw new RuntimeException('Hosted Billing preflight requires organization context.');
            }
            $safe['context_fingerprint'] = $session->contextFingerprint();
            // Establish private durable state before even the prerequisite account read.
            $ledger = RunLedger::create($session->contextFingerprint(), $strings['state-dir']);
            $safe['run_id'] = $ledger->runId();
            if ($observeRisk) {
                $ledger->requestId('preflight_risk_bootstrap', [
                    'profile' => 'partial_first_party_bws_only', 'visitor_limit' => 1, 'event_limit' => 8,
                    'preflight_limit' => 1, 'body_limit_bytes' => 65536, 'event_total_limit_bytes' => 262144,
                    'observation_window_max_ms' => 8000, 'browser_tools' => $safe['browser_tool_hashes'],
                    'financial_authority' => false,
                ], $session->contextFingerprint());
            }
            $phase = 'account_prerequisite';
            $entity = $session->legalEntity();
            if ($entity === null) {
                throw new RuntimeException('Selected account has no legal entity.');
            }
            $fingerprint = $session->contextFingerprint();
            $billing = $session->client->billing();
            $phase = 'customer_create';
            $body = [
                'name' => $ledger->runId(), 'email' => $ledger->runId() . '@example.test',
                'default_billing_currency' => 'EUR', 'default_legal_entity_id' => $entity,
                'metadata' => ['sdk_validation_run' => $ledger->runId()],
            ];
            $body['request_id'] = $ledger->requestId('preflight_customer', $body, $fingerprint);
            $customer = self::id($billing->customers()->create($body));
            $ledger->recordCreated('customers', $customer);
            $expected = [
                'billing_customer_id' => $customer, 'legal_entity_id' => $entity,
                'linked_payment_account_id' => $configuration->accountId(), 'mode' => 'SETUP', 'ui_mode' => 'HOSTED',
                'currency' => 'EUR', 'metadata' => ['sdk_validation_run' => $ledger->runId()],
            ];
            $body = $expected + ['success_url' => 'https://example.com', 'back_url' => 'https://example.com'];
            $body['request_id'] = $ledger->requestId('preflight_checkout', $body, $fingerprint);
            $phase = 'checkout_create';
            $checkout = self::id($billing->checkouts()->create($body));
            $ledger->recordCreated('checkouts', $checkout);
            $phase = 'checkout_read';
            $observed = $billing->checkouts()->retrieve($checkout);
            self::matches($observed, ['id' => $checkout, 'status' => 'ACTIVE'] + $expected);
            $url = $observed['url'] ?? null;
            if (!is_string($url) || !HostedBrowser::candidate($url)) {
                throw new RuntimeException('Owned checkout has no recognized candidate URL.');
            }
            $browserExpected = ['checkout_id' => $checkout, 'mode' => 'SETUP', 'currency' => 'EUR'];
            $ledger->requestId('preflight_payer_read', $browserExpected + [
                'method' => 'GET', 'path' => '/billing/payer-web-api/checkout',
            ], $fingerprint);
            if ($observeConfig) {
                $ledger->requestId('preflight_method_config_read', $browserExpected + [
                    'method' => 'GET', 'path' => '/api/v1/pa/config/payment_method_types',
                    'query' => ['page_num' => 0, 'page_size' => 200, 'active' => true,
                        'transaction_currency' => 'EUR', '__verification_methods' => true],
                    'browser_api_version' => '2024-06-14', 'scope' => 'verified_own_dropin_frame',
                ], $fingerprint);
            }
            if ($observeNormal) {
                foreach (
                    ['customer', 'consent_list', 'country', 'checkout_status_0',
                    'checkout_status_1', 'checkout_status_2'] as $role
                ) {
                    // Future wire IDs/secrets/headers are unknown here: reserve generic finite slots only.
                    $ledger->requestId('preflight_normal_' . $role, $browserExpected + [
                        'method' => 'GET', 'role' => $role, 'scope' => 'fresh_payer_and_exact_original_frame',
                        'target_data' => 'bound_in_private_browser_memory', 'observation_window_max_ms' => 8000,
                    ], $fingerprint);
                }
            }
            $phase = 'browser_read_only';
            $riskJournal = $observeRisk
                ? new HostedRiskJournal($ledger, $fingerprint, $browserExpected, $safe['browser_tool_hashes']) : null;
            $safe['browser'] = HostedBrowser::sanitize(
                $browser($url, $browserExpected, $render, $observeConfig, $observeNormal, $observeRisk, $riskJournal),
                $render,
                $observeConfig,
                $observeNormal,
                $observeRisk,
            );
            $safe['result'] = $safe['browser']['result'];
            if ($safe['browser_tool_hashes'] !== HostedBrowser::sourceHashes()) {
                throw new RuntimeException('Browser tooling changed during the preflight.');
            }
        } catch (\Throwable $error) {
            $safe['result'] = 'fail';
            if ($error instanceof ApiException) {
                $safe['http_status'] = $error->httpStatus();
            }
        } finally {
            $safe['phase'] = $phase;
            if ($session !== null && $ledger !== null) {
                $safe['cleanup'] = self::cleanup($session, $ledger, $expected);
                $safe['created_counts'] = $ledger->counts();
                $safe['login_requests'] = $session->loginRequests();
                if (in_array('fail', $safe['cleanup'], true)) {
                    $safe['result'] = 'fail';
                }
            }
        }
        return $safe;
    }

    /**
     * @param array<string,mixed>|null $expected
     * @return array<string,string>
     */
    private static function cleanup(SandboxSession $session, RunLedger $ledger, ?array $expected): array
    {
        $safe = [];
        foreach ($ledger->created('checkouts') as $index => $id) {
            try {
                $data = $session->client->billing()->checkouts()->retrieve($id);
                if ($expected === null) {
                    throw new RuntimeException('Created checkout needs independent reconciliation.');
                }
                self::matches($data, ['id' => $id, 'status' => 'ACTIVE'] + $expected);
                $ledger->requestId('preflight_cancel_' . $index, [], $session->contextFingerprint());
                // Exactly one cancel attempt, including ambiguous failures. No mutation is retried.
                $session->client->billing()->checkouts()->cancel($id);
                $data = $session->client->billing()->checkouts()->retrieve($id);
                self::matches($data, ['id' => $id, 'status' => 'CANCELLED'] + $expected);
                if (($data['url'] ?? null) !== null) {
                    throw new RuntimeException('Created checkout cancellation is unconfirmed.');
                }
                $safe['checkout_' . $index] = 'pass';
            } catch (\Throwable) {
                $safe['checkout_' . $index] = 'fail';
            }
        }
        return $safe;
    }

    /**
     * @param array<array-key,mixed> $data
     * @param array<string,mixed> $expected
     */
    private static function matches(#[SensitiveParameter] array $data, #[SensitiveParameter] array $expected): void
    {
        foreach ($expected as $key => $value) {
            if (($data[$key] ?? null) !== $value) {
                throw new RuntimeException('Checkout read did not match the owned SETUP.');
            }
        }
    }

    /** @param array<array-key,mixed> $data */
    private static function id(#[SensitiveParameter] array $data): string
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('Created preflight resource has no identity.');
        }
        return $id;
    }
}
