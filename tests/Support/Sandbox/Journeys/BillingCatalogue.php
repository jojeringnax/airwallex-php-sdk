<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use SensitiveParameter;

final class BillingCatalogue
{
    /** @return array<string,mixed> */
    public static function execute(
        SandboxSession $session,
        RunLedger $ledger,
        #[SensitiveParameter] ?string $legalEntity,
    ): array {
        if (!$session->isOrganization()) {
            throw new \RuntimeException('Billing sandbox journeys require organization context.');
        }
        $fingerprint = $session->contextFingerprint();
        $ledger->assertContext($fingerprint);
        $client = $session->client;
        $steps = [];
        $result = ['scenario' => 'billing-catalogue', 'api_version' => '2026-08-21', 'result' => 'pass'];
        try {
            $body = [
                'name' => $ledger->runId(), 'email' => $ledger->runId() . '@example.test',
                'default_billing_currency' => 'EUR', 'metadata' => ['sdk_validation_run' => $ledger->runId()],
            ];
            if ($legalEntity !== null) {
                $body['default_legal_entity_id'] = $legalEntity;
            }
            $body['request_id'] = $ledger->requestId('create_billing_customer', $body, $fingerprint);
            $customer = $client->billing()->customers()->create($body);
            $customerId = self::id($customer);
            $ledger->recordCreated('customers', $customerId);
            $steps['customer_create'] = 'pass';
            $update = ['description' => 'SDK sandbox validation: ' . $ledger->runId()];
            $expectedCustomer = $body;
            unset($expectedCustomer['request_id']);
            $expectedCustomer = [...$expectedCustomer, ...$update];
            // This endpoint has no provider request_id; preserve local intent without inventing a wire field.
            $ledger->requestId('update_customer_without_provider_identity', $update, $fingerprint);
            $client->billing()->customers()->update($customerId, $update);
            $observedCustomer = $client->billing()->customers()->retrieve($customerId);
            self::sameId($observedCustomer, $customerId);
            self::sameFields($observedCustomer, $expectedCustomer);
            $steps['customer_update_retrieve'] = 'pass';
            $body = ['name' => $ledger->runId(), 'metadata' => ['sdk_validation_run' => $ledger->runId()]];
            $body['request_id'] = $ledger->requestId('create_product', $body, $fingerprint);
            $product = $client->billing()->products()->create($body);
            $productId = self::id($product);
            $ledger->recordCreated('products', $productId);
            $steps['product_create'] = 'pass';
            foreach (['monthly' => '1.23', 'replacement' => '2.34'] as $name => $amount) {
                $body = [
                    'currency' => 'EUR', 'product_id' => $productId, 'pricing_model' => 'PER_UNIT',
                    'unit_amount' => $amount, 'recurring' => ['period' => 1, 'period_unit' => 'MONTH'],
                    'metadata' => ['sdk_validation_run' => $ledger->runId()],
                ];
                $body['request_id'] = $ledger->requestId('create_' . $name . '_price', $body, $fingerprint);
                $price = $client->billing()->prices()->create($body);
                $priceId = self::id($price);
                $ledger->recordCreated('prices', $priceId);
                if (!is_string($price['unit_amount'] ?? null)) {
                    throw new ProtocolException('A known decimal response did not retain its string representation.');
                }
                $observedPrice = $client->billing()->prices()->retrieve($priceId);
                self::sameId($observedPrice, $priceId);
                self::sameFields($observedPrice, [
                    'currency' => 'EUR', 'product_id' => $productId, 'pricing_model' => 'PER_UNIT',
                    'recurring' => ['period' => 1, 'period_unit' => 'MONTH'], 'active' => true,
                    'metadata' => ['sdk_validation_run' => $ledger->runId()],
                ]);
                if (!ExactDecimal::equalsLiteral($observedPrice['unit_amount'] ?? null, $amount)) {
                    throw new ProtocolException('Retrieved sandbox price differs from the requested amount.');
                }
                $steps[$name . '_price_create_retrieve'] = 'pass';
            }
            $observedProduct = $client->billing()->products()->retrieve($productId);
            self::sameId($observedProduct, $productId);
            self::sameFields($observedProduct, [
                'name' => $ledger->runId(), 'metadata' => ['sdk_validation_run' => $ledger->runId()], 'active' => true,
            ]);
            $steps['product_retrieve'] = 'pass';
        } catch (ApiException $error) {
            $result['result'] = 'fail';
            $result['http_status'] = $error->httpStatus();
            $result['provider_code'] = $error->providerCode();
            $result['provider_source'] = $error->providerSource();
        } catch (\Throwable) {
            $result['result'] = 'fail';
            $result['failure'] = 'local_protocol_or_transport';
        } finally {
            $cleanup = self::archiveCreated($session, $ledger);
            if (in_array('fail', $cleanup, true)) {
                $result['result'] = 'fail';
            }
        }
        $result['steps'] = $steps;
        $result['cleanup'] = $cleanup;
        $result['created_counts'] = $ledger->counts();
        $result['run_id'] = $ledger->runId();
        $result += $ledger->evidence();
        $result['retained'] = 'Created customer profiles and archived catalogue history remain; '
            . 'no accepted deletion operation exists.';
        return $result;
    }

    /** @return array<string,string> */
    private static function archiveCreated(#[SensitiveParameter] SandboxSession $session, RunLedger $ledger): array
    {
        $result = [];
        // Only identifiers returned by this run's successful create calls enter these lists.
        foreach (['prices', 'products'] as $kind) {
            foreach ($ledger->created($kind) as $index => $id) {
                try {
                    $body = ['active' => false];
                    $body['request_id'] = $ledger->requestId(
                        'archive_' . $kind . '_' . $index,
                        $body,
                        $session->contextFingerprint(),
                    );
                    $resource = $kind === 'prices'
                        ? $session->client->billing()->prices() : $session->client->billing()->products();
                    $response = $resource->update($id, $body);
                    self::sameId($response, $id);
                    $result[$kind . '_' . $index] = ($response['active'] ?? null) === false ? 'pass' : 'fail';
                } catch (\Throwable) {
                    $result[$kind . '_' . $index] = 'fail';
                }
            }
        }
        return $result;
    }

    /** @param array<array-key,mixed> $resource */
    private static function id(#[SensitiveParameter] array $resource): string
    {
        $id = $resource['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new ProtocolException('A created sandbox resource identifier is missing.');
        }
        return $id;
    }

    /** @param array<array-key,mixed> $resource */
    private static function sameId(#[SensitiveParameter] array $resource, #[SensitiveParameter] string $id): void
    {
        if (self::id($resource) !== $id) {
            throw new ProtocolException('Sandbox resource identity changed unexpectedly.');
        }
    }

    /**
     * @param array<array-key,mixed> $resource
     * @param array<string,mixed> $expected
     */
    private static function sameFields(
        #[SensitiveParameter] array $resource,
        #[SensitiveParameter] array $expected,
    ): void {
        if (!self::containsValues($resource, $expected)) {
            throw new ProtocolException('Retrieved sandbox resource differs from the requested values.');
        }
    }

    private static function containsValues(mixed $actual, mixed $expected): bool
    {
        if (is_array($expected) && is_array($actual)) {
            foreach ($expected as $key => $value) {
                if (!array_key_exists($key, $actual) || !self::containsValues($actual[$key], $value)) {
                    return false;
                }
            }
            return true;
        }
        return $actual === $expected;
    }
}
