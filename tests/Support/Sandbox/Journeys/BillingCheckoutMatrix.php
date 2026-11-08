<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use DateTimeImmutable;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use SensitiveParameter;

/** Server-side mode/UI validation; never completes a purchase or collects an instrument. */
final class BillingCheckoutMatrix
{
    /** @return array<string,mixed> */
    public static function execute(
        SandboxSession $session,
        RunLedger $ledger,
        #[SensitiveParameter] string $legalEntity,
        #[SensitiveParameter] string $collector,
    ): array {
        if (!$session->isOrganization()) {
            throw new \RuntimeException('Billing checkout validation requires organization context.');
        }
        $fingerprint = $session->contextFingerprint();
        $ledger->assertContext($fingerprint);
        $billing = $session->client->billing();
        $result = ['scenario' => 'billing-checkouts', 'api_version' => '2026-08-21', 'result' => 'pass'];
        $steps = [];
        $cancelAttempted = [];
        try {
            $body = [
                'name' => $ledger->runId(), 'email' => $ledger->runId() . '@example.test',
                'default_billing_currency' => 'EUR', 'default_legal_entity_id' => $legalEntity,
                'metadata' => ['sdk_validation_run' => $ledger->runId()],
            ];
            $body['request_id'] = $ledger->requestId('customer', $body, $fingerprint);
            $customer = self::id($billing->customers()->create($body));
            $ledger->recordCreated('customers', $customer);
            $body = ['name' => $ledger->runId(), 'metadata' => ['sdk_validation_run' => $ledger->runId()]];
            $body['request_id'] = $ledger->requestId('product', $body, $fingerprint);
            $product = self::id($billing->products()->create($body));
            $ledger->recordCreated('products', $product);
            $prices = [];
            foreach (['PAYMENT', 'SUBSCRIPTION'] as $mode) {
                $body = [
                    'currency' => 'EUR', 'product_id' => $product, 'pricing_model' => 'PER_UNIT',
                    'unit_amount' => '1.23', 'metadata' => ['sdk_validation_run' => $ledger->runId()],
                ];
                if ($mode === 'SUBSCRIPTION') {
                    $body['recurring'] = ['period' => 1, 'period_unit' => 'MONTH'];
                }
                $body['request_id'] = $ledger->requestId('price_' . $mode, $body, $fingerprint);
                $prices[$mode] = self::id($billing->prices()->create($body));
                $ledger->recordCreated('prices', $prices[$mode]);
            }
            foreach (['SETUP', 'PAYMENT', 'SUBSCRIPTION'] as $mode) {
                foreach (['HOSTED', 'EMBEDDED', 'ELEMENTS'] as $ui) {
                    $key = strtolower($mode . '_' . $ui);
                    try {
                        $expected = [
                            'billing_customer_id' => $customer, 'legal_entity_id' => $legalEntity,
                            'linked_payment_account_id' => $collector, 'mode' => $mode, 'ui_mode' => $ui,
                            'metadata' => ['sdk_validation_run' => $ledger->runId()],
                        ];
                        $body = $expected;
                        if ($ui === 'HOSTED') {
                            $body['success_url'] = 'https://example.com';
                            $body['back_url'] = 'https://example.com';
                        } else {
                            $body['return_url'] = 'https://example.com';
                        }
                        if ($mode === 'SETUP') {
                            $body['currency'] = 'EUR';
                        } else {
                            $body['line_items'] = [['price_id' => $prices[$mode], 'quantity' => 1]];
                        }
                        $body['request_id'] = $ledger->requestId('checkout_' . $key, $body, $fingerprint);
                        $id = self::id($billing->checkouts()->create($body));
                        $ledger->recordCreated('checkouts', $id);
                        $observed = $billing->checkouts()->retrieve($id);
                        self::matches($observed, ['id' => $id, 'status' => 'ACTIVE', 'currency' => 'EUR'] + $expected);
                        self::activePresentation($observed, $ui);
                        if ($mode !== 'SETUP') {
                            $items = $observed['line_items'] ?? null;
                            $item = is_array($items) && array_is_list($items) ? ($items[0] ?? null) : null;
                            if (
                                !is_array($items) || count($items) !== 1 || !is_array($item)
                                || ($item['price_id'] ?? null) !== $prices[$mode]
                                || ($item['quantity'] ?? null) !== 1
                            ) {
                                throw new ProtocolException('Checkout items differ from the intended purchase.');
                            }
                        }
                        // Update/cancel have no provider request identity; record local intent only.
                        $update = ['metadata' => ['sdk_validation_run' => $ledger->runId(), 'inspected' => 'true']];
                        $ledger->requestId('update_' . $key, $update, $fingerprint);
                        $billing->checkouts()->update($id, $update);
                        self::matches($billing->checkouts()->retrieve($id), ['id' => $id] + $update);
                        $ledger->requestId('cancel_' . $key, [], $fingerprint);
                        $cancelAttempted[$id] = true;
                        $billing->checkouts()->cancel($id);
                        $cancelled = $billing->checkouts()->retrieve($id);
                        self::matches($cancelled, ['id' => $id, 'status' => 'CANCELLED']);
                        self::cancelledPresentation($cancelled);
                        $steps[$key] = 'pass';
                    } catch (ApiException $error) {
                        $steps[$key] = [
                            'result' => 'fail', 'http_status' => $error->httpStatus(),
                            'provider_code' => $error->providerCode(), 'provider_source' => $error->providerSource(),
                        ];
                        $result['result'] = 'fail';
                    } catch (\Throwable) {
                        $steps[$key] = ['result' => 'fail', 'failure' => 'local_protocol_or_transport'];
                        $result['result'] = 'fail';
                    }
                }
            }
        } catch (ApiException $error) {
            $result['result'] = 'fail';
            $result['http_status'] = $error->httpStatus();
            $result['provider_code'] = $error->providerCode();
            $result['provider_source'] = $error->providerSource();
        } catch (\Throwable) {
            $result['result'] = 'fail';
            $result['failure'] = 'local_protocol_or_transport';
        } finally {
            $cleanup = self::cleanup($session, $ledger, $cancelAttempted);
            if (in_array('fail', $cleanup, true)) {
                $result['result'] = 'fail';
            }
        }
        return $result + [
            'steps' => $steps, 'cleanup' => $cleanup, 'created_counts' => $ledger->counts(),
            'run_id' => $ledger->runId(),
            'retained' => 'Customer and archived catalogue history remain; no checkout was completed.',
        ] + $ledger->evidence();
    }

    /**
     * @param array<string,true> $cancelAttempted
     * @return array<string,string>
     */
    private static function cleanup(SandboxSession $session, RunLedger $ledger, array $cancelAttempted): array
    {
        $result = [];
        $fingerprint = $session->contextFingerprint();
        foreach ($ledger->created('checkouts') as $index => $id) {
            try {
                $observed = $session->client->billing()->checkouts()->retrieve($id);
                self::matches($observed, ['id' => $id]);
                if (($observed['status'] ?? null) === 'ACTIVE') {
                    if (isset($cancelAttempted[$id])) {
                        throw new ProtocolException('Reconcile the original cancellation before any new mutation.');
                    }
                    $ledger->requestId('cleanup_checkout_' . $index, [], $fingerprint);
                    $session->client->billing()->checkouts()->cancel($id);
                    $observed = $session->client->billing()->checkouts()->retrieve($id);
                }
                self::matches($observed, ['id' => $id, 'status' => 'CANCELLED']);
                self::cancelledPresentation($observed);
                $result['checkout_' . $index] = 'pass';
            } catch (\Throwable) {
                $result['checkout_' . $index] = 'fail';
            }
        }
        foreach (['prices', 'products'] as $kind) {
            foreach ($ledger->created($kind) as $index => $id) {
                try {
                    $body = ['active' => false];
                    $body['request_id'] = $ledger->requestId('archive_' . $kind . '_' . $index, $body, $fingerprint);
                    $resource = $kind === 'prices'
                        ? $session->client->billing()->prices() : $session->client->billing()->products();
                    self::matches($resource->update($id, $body), ['id' => $id, 'active' => false]);
                    $result[$kind . '_' . $index] = 'pass';
                } catch (\Throwable) {
                    $result[$kind . '_' . $index] = 'fail';
                }
            }
        }
        return $result;
    }

    /** @param array<array-key,mixed> $data */
    private static function activePresentation(#[SensitiveParameter] array $data, string $ui): void
    {
        $secret = $data['client_secret'] ?? null;
        if (($ui === 'HOSTED' && $secret !== null) || ($ui !== 'HOSTED' && (!is_string($secret) || $secret === ''))) {
            throw new ProtocolException('Checkout presentation does not match the selected UI.');
        }
        if ($ui === 'HOSTED' && (!is_string($data['url'] ?? null) || $data['url'] === '')) {
            throw new ProtocolException('Active hosted checkout URL is missing.');
        }
        if (!is_string($data['created_at'] ?? null) || !is_string($data['expires_at'] ?? null)) {
            throw new ProtocolException('Checkout expiration evidence is missing.');
        }
        $interval = self::timestamp($data['expires_at']) - self::timestamp($data['created_at']);
        if ($interval < 3599 || $interval > 3601) {
            throw new ProtocolException('Checkout expiry differs from the documented one-hour interval.');
        }
    }

    private static function timestamp(string $value): int
    {
        if (
            preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
                . '(?:\.[0-9]+)?(?:Z|[+-](?:[01][0-9]|2[0-3]):?[0-5][0-9])$/D',
                $value,
            ) !== 1
        ) {
            throw new ProtocolException('Checkout timestamp is not an absolute ISO-8601 instant.');
        }
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)
        ) {
            throw new ProtocolException('Checkout timestamp contains invalid calendar or time fields.');
        }
        return $date->getTimestamp();
    }

    /** @param array<array-key,mixed> $data */
    private static function cancelledPresentation(#[SensitiveParameter] array $data): void
    {
        if (($data['url'] ?? null) !== null) {
            throw new ProtocolException('Cancelled checkout still exposes an active checkout URL.');
        }
    }

    /** @param array<array-key,mixed> $data */
    private static function id(#[SensitiveParameter] array $data): string
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new ProtocolException('Created checkout scenario resource has no identity.');
        }
        return $id;
    }

    /**
     * @param array<array-key,mixed> $data
     * @param array<array-key,mixed> $expected
     */
    private static function matches(#[SensitiveParameter] array $data, #[SensitiveParameter] array $expected): void
    {
        foreach ($expected as $key => $value) {
            $actual = $data[$key] ?? null;
            if (is_array($value) && is_array($actual)) {
                self::matches($actual, $value);
            } elseif ($actual !== $value) {
                throw new ProtocolException('Checkout scenario readback differs from the submitted values.');
            }
        }
    }
}
