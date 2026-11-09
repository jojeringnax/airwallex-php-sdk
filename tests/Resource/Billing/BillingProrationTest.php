<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\Internal\Schema\Endpoint;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

final class BillingProrationTest extends HttpTestCase
{
    private const LOGIN = '{"token":"fixture-token","expires_at":"2030-01-01T00:00:00Z"}';

    /** @param array<string,mixed> $item */
    #[DataProvider('customItems')]
    public function testCustomAmountsRemainStringsForEveryBillingAction(array $item, string $action): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{"id":"sub_test","unknown_amount":1.23400}']]);
        $body = ['request_id' => 'persisted-custom-action', 'items' => [$item], 'billing_action' => $action];
        $result = $this->client($http)->billing()->subscriptions()->update('sub_test', $body);
        self::assertSame('1.23400', $result['unknown_amount']);
        self::assertCount(2, $http->requests);
        self::assertSame(json_encode($body, JSON_THROW_ON_ERROR), (string) $http->requests[1]->getBody());
        self::assertSame('2026-08-21', $http->requests[1]->getHeaderLine('x-api-version'));
        self::assertSame('', $http->requests[1]->getHeaderLine('x-on-behalf-of'));
        self::assertSame('POST', $http->requests[1]->getMethod());
        self::assertStringEndsWith('/subscriptions/sub_test/update', (string) $http->requests[1]->getUri());
        $paths = Endpoint::load('billing', 'subscriptions', 'updateSubscription')->operation(['id' => 'sub_test']);
        self::assertNotContains('/items/*/proration_value/charge_amount', $paths->requestDecimalPaths);
        self::assertNotContains('/items/*/proration_value/credit_amount', $paths->requestDecimalPaths);
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function customItems(): iterable
    {
        $items = [
            'update-both' => ['id' => 'item_test', 'quantity' => 3, 'proration_value' => [
                'charge_amount' => '12345678901234567890.123456789000', 'credit_amount' => '0.000000000000000001',
            ]],
            'add-charge' => ['price_id' => 'price_test', 'quantity' => 1,
                'proration_value' => ['charge_amount' => '0.00']],
            // The selected schema supplies no sign or scale bounds; provider business rules remain observable.
            'delete-credit' => ['id' => 'item_old', 'deleted' => true,
                'proration_value' => ['credit_amount' => '-1.230000000000000000']],
        ];
        foreach ($items as $name => $item) {
            foreach (
                ['DEFER_CHARGE_AND_KEEP_CYCLE', 'IMMEDIATE_CHARGE_AND_KEEP_CYCLE',
                'IMMEDIATE_CHARGE_AND_RESET_CYCLE'] as $action
            ) {
                yield $name . '-' . $action => [$item + ['proration_mode' => 'CUSTOM'], $action];
            }
        }
    }

    #[DataProvider('timestamps')]
    public function testCalculationTimestampPreservesItsTextWithoutHiddenReads(string $timestamp): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{"id":"sub_test","status":"ACTIVE"}']]);
        $body = ['request_id' => 'persisted-timestamp-action', 'proration_date' => $timestamp,
            'items' => [['id' => 'item_test', 'quantity' => 3]]];
        $this->client($http)->billing()->subscriptions()->update('sub_test', $body);
        self::assertCount(2, $http->requests);
        self::assertSame(json_encode($body, JSON_THROW_ON_ERROR), (string) $http->requests[1]->getBody());
    }

    /** @return iterable<string,array{string}> */
    public static function timestamps(): iterable
    {
        yield 'utc' => ['2028-02-29T10:15:30Z'];
        yield 'positive-offset' => ['2028-02-29T10:15:30.123456789012345678900+05:30'];
        yield 'negative-offset' => ['2028-02-29T10:15:30.000000001-04:00'];
        yield 'compact-offset' => ['2028-02-29T10:15:30.123400+0000'];
    }

    public function testExplicitNullCalculationTimestampRetainsUpdateClearingSemantics(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{}']]);
        $this->client($http)->billing()->subscriptions()->update('sub_test', [
            'request_id' => 'clear-override', 'proration_date' => null,
        ]);
        self::assertCount(2, $http->requests);
        self::assertSame(
            '{"request_id":"clear-override","proration_date":null}',
            (string) $http->requests[1]->getBody(),
        );
    }

    public function testCustomAndAutomaticItemsCanShareAnIndependentTimestampOverride(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{}']]);
        $body = ['request_id' => 'mixed-items', 'default_proration_mode' => 'NONE',
            'proration_date' => '2028-02-29T10:15:30.0000000000001+01:00', 'items' => [
                ['id' => 'custom', 'quantity' => 3, 'proration_mode' => 'CUSTOM',
                    'proration_value' => ['charge_amount' => '25.0000']],
                ['id' => 'automatic', 'quantity' => 2, 'proration_mode' => 'PRORATED'],
                ['id' => 'inherited', 'quantity' => 1],
            ]];
        $this->client($http)->billing()->subscriptions()->update('sub_test', $body);
        self::assertCount(2, $http->requests);
        self::assertSame(json_encode($body, JSON_THROW_ON_ERROR), (string) $http->requests[1]->getBody());
    }

    /** @param array<string,mixed> $addition */
    #[DataProvider('invalidControls')]
    public function testInvalidControlsFailBeforeAuthentication(array $addition): void
    {
        $http = $this->scenario([]);
        try {
            $this->client($http)->billing()->subscriptions()->update(
                'sub_test',
                ['request_id' => 'stable'] + $addition,
            );
            self::fail('Expected local validation failure.');
        } catch (ValidationException $error) {
            self::assertStringNotContainsString('PRIVATE', (string) $error);
        }
        self::assertSame([], $http->requests);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidControls(): iterable
    {
        yield 'global-custom' => [['default_proration_mode' => 'CUSTOM']];
        yield 'global-value' => [['proration_value' => ['charge_amount' => '1.00']]];
        yield 'custom-missing-value' => [['items' => [['id' => 'item', 'proration_mode' => 'CUSTOM']]]];
        foreach (
            [null, [], new stdClass(), '', 'PRIVATE', ['unknown' => '1'],
            ['charge_amount' => '1', 'unknown' => '2'], (object) ['charge_amount' => '1']] as $i => $value
        ) {
            yield 'invalid-value-object-' . $i => [['items' => [[
                'id' => 'item', 'proration_mode' => 'CUSTOM', 'proration_value' => $value,
            ]]]];
        }
        foreach (['NONE', 'PRORATED', 'ALL', null] as $mode) {
            foreach ([null, ['charge_amount' => '1']] as $i => $value) {
                yield 'value-without-custom-' . (string) $mode . '-' . $i => [['items' => [[
                    'id' => 'item', 'proration_mode' => $mode, 'proration_value' => $value,
                ]]]];
            }
        }
        yield 'value-with-omitted-mode' => [['items' => [[
            'id' => 'item', 'proration_value' => ['credit_amount' => '1.00'],
        ]]]];
        foreach (['charge_amount', 'credit_amount'] as $key) {
            foreach ([null, '', 1, 0.1, true, [], '1e2', '+1', '01.20', ' 1', '1.', 'PRIVATE'] as $i => $amount) {
                yield $key . '-invalid-' . $i => [['items' => [[
                    'id' => 'item', 'proration_mode' => 'CUSTOM',
                    'proration_value' => [
                        $key => $amount, $key === 'charge_amount' ? 'credit_amount' : 'charge_amount' => '1',
                    ],
                ]]]];
            }
        }
        foreach (
            ['', 'NOW', 123, false, [], '2028-02-30T00:00:00Z', '2028-02-29',
            '2028-02-29T10:15:30', '2028-02-29T10:15:30+24:00', '2028-02-29T10:15:30.Z',
            "2028-02-29T10:15:30Z\n", 'PRIVATE'] as $i => $timestamp
        ) {
            yield 'invalid-timestamp-' . $i => [['proration_date' => $timestamp]];
        }
    }

    public function testNewControlsRemainOutsideCreateAndCancellation(): void
    {
        $http = $this->scenario([]);
        $subscriptions = $this->client($http)->billing()->subscriptions();
        $calls = [
            static fn (): array => $subscriptions->cancel(
                'sub',
                ['request_id' => 'stable', 'proration_behavior' => 'CUSTOM'],
            ),
            static fn (): array => $subscriptions->cancel('sub', [
                'request_id' => 'stable', 'proration_behavior' => 'NONE', 'proration_date' => '2028-02-29T00:00:00Z',
            ]),
            static fn (): array => $subscriptions->create([
                'request_id' => 'stable', 'billing_customer_id' => 'customer', 'collection_method' => 'OUT_OF_BAND',
                'items' => [['price_id' => 'price']], 'proration_date' => '2028-02-29T00:00:00Z',
            ]),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Expected local validation failure.');
            } catch (ValidationException) {
                self::assertSame([], $http->requests);
            }
        }
    }

    #[DataProvider('failures')]
    public function testProviderFailurePreservesActionWithoutReplay(int $status, string $code): void
    {
        // Synthetic selected endpoint codes: no claim that a specific input always maps to this error.
        $http = $this->scenario([[201, self::LOGIN], [$status, json_encode([
            'code' => $code, 'message' => 'PRIVATE provider detail',
        ], JSON_THROW_ON_ERROR)]]);
        $body = ['request_id' => 'persisted-proration-action',
            'proration_date' => '2028-02-29T10:15:30.123456789+01:00', 'items' => [[
                'id' => 'item', 'quantity' => 3, 'proration_mode' => 'CUSTOM',
                'proration_value' => ['charge_amount' => '30.000000000000000001', 'credit_amount' => '1.00'],
            ]]];
        try {
            $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3))
                ->billing()->subscriptions()->update('sub_test', $body);
            self::fail('Expected provider failure.');
        } catch (ApiException $error) {
            self::assertSame($status, $error->httpStatus());
            self::assertSame($code, $error->providerCode());
            self::assertStringNotContainsString('PRIVATE', (string) $error);
        }
        self::assertCount(2, $http->requests);
        self::assertSame(json_encode($body, JSON_THROW_ON_ERROR), (string) $http->requests[1]->getBody());
    }

    /** @return iterable<string,array{int,string}> */
    public static function failures(): iterable
    {
        yield 'calculation-input-rejected' => [400, 'validation_error'];
        yield 'state-rejected' => [400, 'invalid_status_for_operation'];
        yield 'duplicate-action' => [400, 'duplicate_request_id'];
        yield 'server-ambiguity' => [500, 'internal_error'];
    }
}
