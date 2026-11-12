<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

final class PriceTiersTest extends HttpTestCase
{
    private const LOGIN = '{"token":"fixture-token","expires_at":"2030-01-01T00:00:00Z"}';

    #[DataProvider('models')]
    public function testTieredCreationPreservesExactAmountsBoundsAndProviderFields(string $model): void
    {
        $tiers = '[{"unit_amount":2.500000000000000001,"upper_bound":50.000000000000000001,'
            . '"flat_amount":0.00,"lot":{"size":5,"rounding_mode":"UP"}},'
            . '{"flat_amount":1.2300,"unit_amount":0.00}]';
        $response = '{"id":"pri_test","pricing_model":"' . $model . '","tiers":'
            . str_replace('[{', '[{"level":1,', $tiers) . ',"future_number":1e100}';
        $http = $this->scenario([[201, self::LOGIN], [201, $response]]);
        $price = $this->client($http)->billing()->prices()->create(self::body($model) + [
            'metered' => false,
            'recurring' => ['period' => 1, 'period_unit' => 'MONTH'],
            'tiers' => [
                ['unit_amount' => '2.500000000000000001', 'upper_bound' => '50.000000000000000001',
                    'flat_amount' => '0.00', 'lot' => ['size' => 5, 'rounding_mode' => 'UP']],
                ['flat_amount' => '1.2300', 'unit_amount' => '0.00'],
            ],
        ]);
        self::assertSame([
            ['level' => 1, 'unit_amount' => '2.500000000000000001', 'upper_bound' => '50.000000000000000001',
                'flat_amount' => '0.00', 'lot' => ['size' => 5, 'rounding_mode' => 'UP']],
            ['flat_amount' => '1.2300', 'unit_amount' => '0.00'],
        ], $price['tiers']);
        self::assertSame('1e100', $price['future_number']);
        self::assertCount(2, $http->requests);
        $request = $http->requests[1];
        self::assertStringContainsString('"tiers":' . $tiers, (string) $request->getBody());
        self::assertStringContainsString('"metered":false', (string) $request->getBody());
        self::assertSame('2026-08-21', $request->getHeaderLine('x-api-version'));
        self::assertSame('', $request->getHeaderLine('x-on-behalf-of'));
    }

    /** @return iterable<string,array{string}> */
    public static function models(): iterable
    {
        yield 'volume' => ['VOLUME'];
        yield 'graduated' => ['GRADUATED'];
    }

    /** @param list<array<string,mixed>|stdClass> $tiers */
    #[DataProvider('validTiers')]
    public function testValidTierBoundariesDoNotRoundOrRequireMetering(array $tiers): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{}']]);
        $this->client($http)->billing()->prices()->create(self::body('VOLUME') + [
            'recurring' => null, 'tiers' => $tiers,
        ]);
        self::assertCount(2, $http->requests);
        self::assertStringContainsString('"recurring":null', (string) $http->requests[1]->getBody());
        self::assertStringNotContainsString('"metered"', (string) $http->requests[1]->getBody());
    }

    /** @return iterable<string,array{list<array<string,mixed>|stdClass>}> */
    public static function validTiers(): iterable
    {
        yield 'single-unbounded-free-tier' => [[['flat_amount' => '0.00']]];
        yield 'omitted-optional-amounts' => [[new stdClass()]];
        yield 'lot-with-optional-unit-amount-omitted' => [[
            ['flat_amount' => '1', 'lot' => ['size' => 2, 'rounding_mode' => 'UP']],
        ]];
        yield 'flat-only-and-unit-only' => [[
            ['flat_amount' => '1.00', 'upper_bound' => '10'], ['unit_amount' => '0'],
        ]];
        yield 'bounds-below-float-resolution' => [[
            ['unit_amount' => '0', 'upper_bound' => '0.000000000000000000000001'],
            ['unit_amount' => '0', 'upper_bound' => '0.000000000000000000000002'],
            ['unit_amount' => '1'],
        ]];
        yield 'large-adjacent-bounds' => [[
            ['unit_amount' => '1', 'upper_bound' => '999999999999999999999999.1'],
            ['unit_amount' => '1', 'upper_bound' => '999999999999999999999999.2'],
            ['unit_amount' => '1'],
        ]];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidPrices')]
    public function testInvalidTierConfigurationsFailBeforeAuthentication(array $changes): void
    {
        $http = $this->scenario([]);
        $this->expectException(ValidationException::class);
        try {
            $this->client($http)->billing()->prices()->create(array_replace(self::body('GRADUATED'), $changes));
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidPrices(): iterable
    {
        yield 'missing-tiers' => [[]];
        foreach (
            [null, [], ['unit_amount' => '1'], [null], [[]], [['unit_amount' => '1', 'level' => 1]]] as $i => $tiers
        ) {
            yield 'invalid-tier-shape-' . $i => [['tiers' => $tiers]];
        }
        yield 'final-bound-present' => [['tiers' => [['unit_amount' => '1', 'upper_bound' => '10']]]];
        yield 'final-bound-null' => [['tiers' => [['unit_amount' => '1', 'upper_bound' => null]]]];
        yield 'nonfinal-bound-missing' => [['tiers' => [
            ['unit_amount' => '1'], ['flat_amount' => '1'],
        ]]];
        foreach (['0', '-0.00', '-1', '', 'INF', '1e2', 1, 0.1, null] as $i => $bound) {
            yield 'invalid-bound-' . $i => [['tiers' => [
                ['unit_amount' => '1', 'upper_bound' => $bound], ['unit_amount' => '1'],
            ]]];
        }
        foreach (['10.00', '9.999999999999999999999999'] as $i => $bound) {
            yield 'nonincreasing-bound-' . $i => [['tiers' => [
                ['unit_amount' => '1', 'upper_bound' => '10'],
                ['unit_amount' => '1', 'upper_bound' => $bound], ['unit_amount' => '1'],
            ]]];
        }
        foreach (['flat_amount', 'unit_amount'] as $field) {
            foreach (['-0.01', '1e2', 1, 0.1, null] as $i => $amount) {
                yield $field . '-invalid-' . $i => [['tiers' => [[$field => $amount]]]];
            }
        }
        foreach (
            [['size' => 1, 'rounding_mode' => 'UP'], ['size' => 2, 'rounding_mode' => 'DOWN'],
            ['size' => '2', 'rounding_mode' => 'UP'], ['size' => 2147483648, 'rounding_mode' => 'UP'],
            ['size' => 2]] as $i => $lot
        ) {
            yield 'invalid-lot-' . $i => [['tiers' => [['unit_amount' => '1', 'lot' => $lot]]]];
        }
        yield 'top-level-lot' => [['tiers' => [['unit_amount' => '1']],
            'lot' => ['size' => 2, 'rounding_mode' => 'UP']]];
        foreach (['FLAT', 'PER_UNIT'] as $model) {
            yield 'tiers-on-' . $model => [['pricing_model' => $model, 'flat_amount' => '1',
                'unit_amount' => '1', 'tiers' => [['unit_amount' => '1']]]];
        }
        yield 'unknown-model' => [['pricing_model' => 'FUTURE', 'tiers' => [['unit_amount' => '1']]]];
        yield 'invalid metered flag' => [['metered' => 'true', 'tiers' => [['unit_amount' => '1']]]];
        yield 'invalid meter-id' => [['meter_id' => 123, 'tiers' => [['unit_amount' => '1']]]];
        yield 'invalid-recurrence' => [['recurring' => ['period_unit' => 'MONTH', 'period' => 0],
            'tiers' => [['unit_amount' => '1']]]];
    }

    #[DataProvider('models')]
    public function testTieredPriceCanBeArchivedWithoutChangingItsSchedule(string $model): void
    {
        $response = '{"id":"pri_test","active":false,"pricing_model":"' . $model
            . '","tiers":[{"level":1,"unit_amount":1.2300}]}';
        $http = $this->scenario([[201, self::LOGIN], [200, $response], [200, $response],
            [200, '{"items":[' . $response . ']}']]);
        $prices = $this->client($http)->billing()->prices();
        $archived = $prices->update('pri_test', ['active' => false, 'description' => null,
            'metadata' => ['sku' => '001.00'], 'request_id' => 'archive-tiered']);
        self::assertSame([['level' => 1, 'unit_amount' => '1.2300']], $archived['tiers']);
        self::assertSame($archived, $prices->retrieve('pri_test'));
        self::assertSame(['items' => [$archived]], $prices->list(['active' => false]));
        self::assertSame('{"active":false,"description":null,"metadata":{"sku":"001.00"},'
            . '"request_id":"archive-tiered"}', (string) $http->requests[1]->getBody());
    }

    #[DataProvider('immutableFields')]
    public function testTierScheduleUpdatesFailBeforeAuthentication(string $field): void
    {
        $http = $this->scenario([]);
        $this->expectException(ValidationException::class);
        try {
            $this->client($http)->billing()->prices()->update('pri_test', [$field => null]);
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function immutableFields(): iterable
    {
        foreach (
            ['tiers', 'pricing_model', 'unit_amount', 'flat_amount', 'lot', 'currency', 'recurring',
            'product_id', 'metered'] as $field
        ) {
            yield $field => [$field];
        }
    }

    public function testTieredCreationProviderFailureIsNotReplayed(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [400, '{"code":"validation_error"}']]);
        $this->expectException(ApiException::class);
        try {
            $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3))->billing()->prices()->create(
                self::body('VOLUME') + ['tiers' => [['unit_amount' => '1.00']]],
            );
        } finally {
            self::assertCount(2, $http->requests);
            self::assertStringContainsString(
                '"request_id":"persisted-tiered-price"',
                (string) $http->requests[1]->getBody(),
            );
        }
    }

    /** @return array<string,mixed> */
    private static function body(string $model): array
    {
        return ['request_id' => 'persisted-tiered-price', 'product_id' => 'prd_test',
            'currency' => 'EUR', 'pricing_model' => $model];
    }
}
