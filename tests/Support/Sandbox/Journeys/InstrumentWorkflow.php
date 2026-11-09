<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;

/** One fresh SETUP and one native synthetic instrument opportunity under the reviewed service policy. */
final class InstrumentWorkflow
{
    /**
     * @param array<string,mixed> $options
     * @param (Closure(array<string,mixed>,string):array<string,mixed>)|null $browser Private offline fixture seam.
     * @param (Closure():int)|null $profileClock Fixture-only source-expiry clock.
     * @return array<string,mixed>
     */
    public static function run(
        array $options,
        ?ClientInterface $http = null,
        ?Closure $browser = null,
        ?Closure $profileClock = null,
    ): array {
        $safe = ['scenario' => 'hosted-instrument-workflow', 'api_version' => '2026-08-21', 'result' => 'fail',
            'instrument_verified' => false, 'reconciliation_required' => false, 'security_graph_complete' => false];
        $phase = 'configuration';
        $transport = null;
        $ledger = null;
        $expected = null;
        $checkout = null;
        $journal = null;
        $inventory = null;
        $browserStarted = false;
        try {
            if (
                array_diff(array_keys($options), ['environment', 'credentials-file', 'state-dir', 'node-bin',
                'playwright-module', 'collect-one-synthetic-hosted-instrument']) !== []
                || ($options['environment'] ?? null) !== 'sandbox'
                || ($options['collect-one-synthetic-hosted-instrument'] ?? null) !== true
            ) {
                throw new RuntimeException('The explicit fresh Sandbox instrument profile is required.');
            }
            $strings = [];
            foreach (['credentials-file', 'state-dir', 'node-bin', 'playwright-module'] as $key) {
                if (!is_string($options[$key] ?? null) || $options[$key] === '') {
                    throw new RuntimeException('An explicit local instrument path is required.');
                }
                $strings[$key] = $options[$key];
            }
            InstrumentBrowser::privatePath($strings['credentials-file'], false);
            InstrumentBrowser::privatePath($strings['state-dir'], true);
            $runtime = new InstrumentBrowser($strings['node-bin'], $strings['playwright-module']);
            if (($profileClock ?? time(...))() >= strtotime('2026-11-15T00:00:00Z')) {
                throw new RuntimeException('The reviewed public source window expired.');
            }
            $source = SourceEvidence::capture();
            $book = InstrumentBrowser::sourceBook();
            $credentialHash = hash_file('sha256', $strings['credentials-file']);
            $configuration = Configuration::load('sandbox', $strings['credentials-file']);
            $transport = new InstrumentApiTransport($configuration, $http);
            $organization = new SandboxSession($configuration, 'organization', $transport);
            $account = new SandboxSession($configuration, 'account', $transport);
            $context = $organization->contextFingerprint();
            $ledger = RunLedger::create($context, $strings['state-dir']);
            $safe += ['run_id' => $ledger->runId(), 'source' => $source, 'browser_tool_hashes' => $book,
                'context_fingerprint' => $context];
            $gate = static function () use ($source, $book, $credentialHash, $strings): void {
                if (
                    $source !== SourceEvidence::capture() || $book !== InstrumentBrowser::sourceBook()
                    || $credentialHash === false || $credentialHash !== hash_file(
                        'sha256',
                        $strings['credentials-file']
                    )
                ) {
                    throw new RuntimeException('The original instrument source or credentials changed.');
                }
                InstrumentBrowser::privatePath($strings['credentials-file'], false);
            };
            $transport->bind($ledger, $context, $gate);
            $phase = 'account_prerequisite';
            $entity = InstrumentApiTransport::identity($organization->legalEntity());
            $billing = $organization->client->billing();
            $buyer = ['name' => 'SDK Sandbox Buyer', 'email' => $ledger->runId() . '@example.test',
                'type' => 'INDIVIDUAL',
                'default_billing_currency' => 'EUR', 'default_legal_entity_id' => $entity,
                'metadata' => ['sdk_validation_run' => $ledger->runId()]];
            $phase = 'customer_create';
            $body = $buyer + ['request_id' => $ledger->requestId('instrument_customer', $buyer, $context)];
            $transport->arm('customer_create', '/api/v1/billing/billing_customers/create', $body);
            $created = $billing->customers()->create($body);
            $customer = InstrumentApiTransport::identity($created['id'] ?? null);
            $ledger->recordCreated('customers', $customer);
            $transport->read('billing_customer', $customer);
            self::matches($billing->customers()->retrieve($customer), ['id' => $customer] + $buyer);
            $expected = ['billing_customer_id' => $customer, 'legal_entity_id' => $entity,
                'linked_payment_account_id' => $configuration->accountId(), 'mode' => 'SETUP', 'ui_mode' => 'HOSTED',
                'currency' => 'EUR', 'metadata' => ['sdk_validation_run' => $ledger->runId()]];
            $body = $expected + ['success_url' => 'https://example.com', 'back_url' => 'https://example.com'];
            $body['request_id'] = $ledger->requestId('instrument_checkout', $body, $context);
            $phase = 'checkout_create';
            $transport->arm('checkout_create', '/api/v1/billing/billing_checkouts/create', $body);
            $created = $billing->checkouts()->create($body);
            $checkout = InstrumentApiTransport::identity($created['id'] ?? null);
            $ledger->recordCreated('checkouts', $checkout);
            $transport->read('checkout', $checkout);
            $phase = 'checkout_read';
            $observed = $billing->checkouts()->retrieve($checkout);
            self::matches($observed, ['id' => $checkout, 'status' => 'ACTIVE'] + $expected);
            $url = $observed['url'] ?? null;
            if (
                !HostedBrowser::candidate($url) || !is_string($url)
                || parse_url($url, PHP_URL_HOST) !== 'checkout.sandbox.airwallex.com'
            ) {
                throw new RuntimeException('The fresh owned Checkout has no Sandbox capability.');
            }
            $folder = $strings['state-dir'] . '/' . $ledger->runId();
            $journal = $folder . '/browser-journal';
            if (!mkdir($journal, 0700)) {
                throw new RuntimeException('The private browser journal could not be created.');
            }
            $packet = ['url' => $url, 'expected' => ['checkout_id' => $checkout, 'mode' => 'SETUP',
                'currency' => 'EUR'],
                'buyer_email' => $buyer['email'], 'context_sha256' => $context,
                'ownership_sha256' => hash('sha256', json_encode(
                    ['id' => $checkout] + $expected + $buyer,
                    JSON_THROW_ON_ERROR
                )),
                'source_hashes' => $book, 'journal_directory' => $journal];
            $provenance = ['profile' => 'bounded_hosted_instrument_v1', 'context_sha256' => $context,
                'ownership_sha256' => $packet['ownership_sha256'], 'source_hashes' => $book,
                    'expected' => $packet['expected']];
            $ledger->recordObservation('instrument_browser_profile', ['profile' => 'bounded_hosted_instrument_v1',
                'ownership_sha256' => $packet['ownership_sha256'], 'source_sha256' => hash(
                    'sha256',
                    json_encode($book, JSON_THROW_ON_ERROR)
                ),
                'case_max_ms' => '180000', 'admission_max_ms' => '150000', 'release_max_ms' => '170000'], $context);
            $gate();
            $phase = 'native_browser';
            $browserStarted = true;
            try {
                $receipt = $browser !== null ? $browser($packet, $folder) : $runtime->collect($packet, $folder);
                $safe['browser'] = InstrumentBrowser::sanitize($receipt);
                $safe['browser_process_reaped'] = ($receipt['process_reaped'] ?? null) === true;
            } finally {
                $inventory = InstrumentBrowser::inventory($journal, $provenance);
                $safe['journal'] = array_diff_key($inventory, ['identities' => true]);
                foreach ($inventory['identities'] as $kind => $id) {
                    $ledger->recordCreated('browser_' . $kind, $id);
                }
            }
            $gate();
            $transport->proof();
            $phase = 'sdk_reconciliation';
            $final = $billing->checkouts()->retrieve($checkout);
            self::matches($final, ['id' => $checkout] + $expected);
            if (($final['status'] ?? null) === 'COMPLETED') {
                self::verify(
                    $final,
                    $expected,
                    $inventory['identities'],
                    $buyer['email'],
                    $organization,
                    $account,
                    $transport,
                    $ledger
                );
                $safe['instrument_verified'] = true;
                if (
                    ($safe['browser']['result'] ?? null) === 'completed' && $safe['browser_process_reaped'] === true
                    && $inventory['unresolved_actions'] === 0
                    && ($safe['browser']['journal_records'] ?? null) === $inventory['journal_records']
                    && ($safe['browser']['possible_financial_send'] ?? null) === $inventory['possible_financial_send']
                ) {
                    $safe['result'] = 'pass';
                    $phase = 'complete';
                }
            } elseif (
                ($final['status'] ?? null) === 'ACTIVE' && !$inventory['possible_financial_send']
                && $inventory['unresolved_actions'] === 0 && ($safe['browser']['cleanup_complete'] ?? null) === true
                && $safe['browser_process_reaped'] === true
            ) {
                $phase = 'owned_active_cleanup';
                $ledger->requestId('instrument_cancel', [], $context);
                $transport->arm('checkout_cancel', '/api/v1/billing/billing_checkouts/' . $checkout . '/cancel', null);
                $billing->checkouts()->cancel($checkout);
                $cancelled = $billing->checkouts()->retrieve($checkout);
                self::matches($cancelled, ['id' => $checkout, 'status' => 'CANCELLED'] + $expected);
                if (($cancelled['url'] ?? null) !== null) {
                    throw new RuntimeException('The owned cancellation is unconfirmed.');
                }
                $safe['owned_active_cancelled'] = true;
            } else {
                $safe['reconciliation_required'] = true;
            }
        } catch (\Throwable) {
            $safe['result'] = 'fail';
            $safe['reconciliation_required'] = $browserStarted || $checkout !== null || $phase === 'checkout_create';
        } finally {
            $safe['phase'] = $phase;
            if ($transport !== null) {
                $safe['sdk_transport'] = $transport->evidence();
            }
            if ($ledger !== null) {
                $safe['created_counts'] = $ledger->counts();
            }
        }
        return $safe;
    }

    /**
     * @param array<array-key,mixed> $checkout
     * @param array<string,mixed> $expected
     * @param array<string,string> $ids
     */
    private static function verify(
        #[SensitiveParameter] array $checkout,
        #[SensitiveParameter] array $expected,
        #[SensitiveParameter] array $ids,
        string $email,
        SandboxSession $organization,
        SandboxSession $account,
        InstrumentApiTransport $transport,
        RunLedger $ledger,
    ): void {
        $sourceId = InstrumentApiTransport::identity($checkout['payment_source_id'] ?? null);
        $ledger->recordCreated('payment_sources', $sourceId);
        $transport->read('source', $sourceId);
        $source = $organization->client->billing()->paymentSources()->retrieve($sourceId);
        self::matches($source, ['id' => $sourceId, 'billing_customer_id' => $expected['billing_customer_id'],
            'linked_payment_account_id' => $expected['linked_payment_account_id']]);
        $method = InstrumentApiTransport::identity($source['external_id'] ?? null);
        $customer = InstrumentApiTransport::identity($ids['payments_customer_id'] ?? null);
        $consentId = InstrumentApiTransport::identity($ids['consent_id'] ?? null);
        $intentId = InstrumentApiTransport::identity($ids['initial_intent_id'] ?? null);
        if (($ids['method_id'] ?? null) !== $method) {
            throw new RuntimeException('The independent Source and Consent Method mappings differ.');
        }
        foreach (
            ['method' => $method, 'payments_customer' => $customer, 'consent' => $consentId,
            'intent' => $intentId] as $kind => $id
        ) {
            $ledger->recordCreated($kind, $id);
            $transport->read($kind, $id);
        }
        $payments = $account->client->payments();
        $ownedCustomer = $payments->customers()->retrieve($customer);
        self::matches($ownedCustomer, ['id' => $customer, 'email' => $email]);
        $ownedMethod = $payments->paymentMethods()->retrieve($method);
        self::matches($ownedMethod, ['id' => $method, 'type' => 'card', 'customer_id' => $customer]);
        $card = $ownedMethod['card'] ?? null;
        if (!is_array($card) || ($card['last4'] ?? null) !== '0008') {
            throw new RuntimeException('The independently retrieved synthetic card is unavailable.');
        }
        $consent = $payments->paymentConsents()->retrieve($consentId);
        self::matches($consent, ['id' => $consentId, 'customer_id' => $customer, 'status' => 'VERIFIED',
            'next_triggered_by' => 'customer', 'initial_payment_intent_id' => $intentId]);
        if (isset($consent['merchant_trigger_reason'])) {
            throw new RuntimeException('The independent Consent agreement changed.');
        }
        $consentMethod = $consent['payment_method'] ?? null;
        if (
            !is_array($consentMethod) || ($consentMethod['id'] ?? null) !== $method
                || ($consentMethod['type'] ?? null) !== 'card'
            || !in_array($consent['next_action'] ?? null, [null, []], true)
        ) {
            throw new RuntimeException('The independent Consent and Method relationship is unconfirmed.');
        }
        $intent = $payments->paymentIntents()->retrieve($intentId);
        self::matches($intent, ['id' => $intentId, 'customer_id' => $customer, 'status' => 'SUCCEEDED']);
        if (
            !ExactDecimal::equalsLiteral($intent['captured_amount'] ?? null, '0')
            || !is_string($intent['amount'] ?? null) || strlen($intent['amount']) > 1000
            || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $intent['amount']) !== 1
            || !is_string($intent['currency'] ?? null)
            || preg_match('/^[A-Z]{3}$/D', $intent['currency']) !== 1
            || (isset($intent['payment_consent_id']) && $intent['payment_consent_id'] !== $consentId)
            || (isset($intent['payment_method_id']) && $intent['payment_method_id'] !== $method)
        ) {
            throw new RuntimeException('The verification Intent has unconfirmed ownership or captured funds.');
        }
        if (isset($intent['latest_payment_attempt'])) {
            $attempt = $intent['latest_payment_attempt'];
            if (!is_array($attempt)) {
                throw new RuntimeException('The verification attempt is unavailable.');
            }
            foreach (
                ['payment_intent_id' => $intentId, 'customer_id' => $customer,
                'payment_consent_id' => $consentId] as $key => $id
            ) {
                if (isset($attempt[$key]) && $attempt[$key] !== $id) {
                    throw new RuntimeException('The independent verification attempt relationship changed.');
                }
            }
            if (isset($attempt['payment_method'])) {
                $attemptMethod = $attempt['payment_method'];
                if (!is_array($attemptMethod) && !$attemptMethod instanceof \stdClass) {
                    throw new RuntimeException('The verification attempt Method is unavailable.');
                }
                foreach ((array) $attemptMethod as $key => $value) {
                    $known = ['id' => $method, 'type' => 'card', 'customer_id' => $customer];
                    if (array_key_exists($key, $known) && $value !== $known[$key]) {
                        throw new RuntimeException('The independent attempt Method echo changed.');
                    }
                }
            }
        }
        if (isset($intent['payment_consent'])) {
            $intentConsent = $intent['payment_consent'];
            if (!is_array($intentConsent)) {
                throw new RuntimeException('The verification Consent is unavailable.');
            }
            self::matches($intentConsent, ['next_triggered_by' => 'customer']);
            if (
                isset($intentConsent['merchant_trigger_reason'])
                || (isset($intentConsent['terms_of_use']) && !is_array($intentConsent['terms_of_use'])
                    && !$intentConsent['terms_of_use'] instanceof \stdClass)
            ) {
                throw new RuntimeException('The verification agreement options changed.');
            }
        }
    }

    /**
     * @param array<array-key,mixed> $data
     * @param array<string,mixed> $expected
     */
    private static function matches(#[SensitiveParameter] array $data, #[SensitiveParameter] array $expected): void
    {
        foreach ($expected as $key => $value) {
            if (($data[$key] ?? null) !== $value) {
                throw new RuntimeException('The independent SDK resource did not match the original owned scope.');
            }
        }
    }
}
