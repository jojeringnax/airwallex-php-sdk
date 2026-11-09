<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Closure;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;

final class BillingExamplesTest extends HttpTestCase
{
    public function testCatalogueExampleCreatesMonthlyAndQuantityPrices(): void
    {
        $http = $this->scenario([
            [201, '{"token":"fixture-token","expires_at":"2030-01-01T00:00:00Z"}'],
            [201, '{"id":"prd_fixture"}'],
            [201, '{"id":"price_monthly","flat_amount":19.90}'],
            [201, '{"id":"price_seat","unit_amount":2.50}'],
            [201, '{"id":"price_volume","pricing_model":"VOLUME"}'],
            [201, '{"id":"price_graduated","pricing_model":"GRADUATED"}'],
        ]);
        $example = require dirname(__DIR__, 3) . '/examples/billing-catalogue.php';
        self::assertInstanceOf(Closure::class, $example);
        $result = $example($this->client($http));
        self::assertIsArray($result);
        self::assertSame([
            'product' => ['id' => 'prd_fixture'],
            'monthly' => ['id' => 'price_monthly', 'flat_amount' => '19.90'],
            'per_seat' => ['id' => 'price_seat', 'unit_amount' => '2.50'],
            'volume' => ['id' => 'price_volume', 'pricing_model' => 'VOLUME'],
            'graduated' => ['id' => 'price_graduated', 'pricing_model' => 'GRADUATED'],
        ], $result);
        self::assertCount(6, $http->requests);
        self::assertStringContainsString('"flat_amount":19.90', (string) $http->requests[2]->getBody());
        self::assertStringContainsString('"unit_amount":2.50', (string) $http->requests[3]->getBody());
        self::assertSame(
            '{"request_id":"<persisted-volume-price-request-id>","product_id":"prd_fixture",'
            . '"currency":"EUR","pricing_model":"VOLUME","metered":false,'
            . '"recurring":{"period":1,"period_unit":"MONTH"},'
            . '"tiers":[{"upper_bound":50,"unit_amount":2.50},{"unit_amount":2.00}]}',
            (string) $http->requests[4]->getBody(),
        );
        self::assertSame(
            '{"request_id":"<persisted-graduated-price-request-id>","product_id":"prd_fixture",'
            . '"currency":"EUR","pricing_model":"GRADUATED","metered":false,"recurring":null,'
            . '"tiers":[{"upper_bound":50,"unit_amount":2.50,"flat_amount":0.00},'
            . '{"unit_amount":2.00,"flat_amount":5.00}]}',
            (string) $http->requests[5]->getBody(),
        );
    }

    public function testCustomerAndHostedSignupExampleUsesDistinctExplicitContext(): void
    {
        $http = $this->scenario([
            [201, '{"token":"fixture-token","expires_at":"2030-01-01T00:00:00Z"}'],
            [201, '{"id":"bcus_fixture"}'],
            [200, '{"id":"bcus_fixture","address":{"country_code":"IT"}}'],
            [200, '{"id":"bcus_fixture","address":{"country_code":"IT"}}'],
            [201, '{"id":"checkout_fixture","status":"ACTIVE","url":"https://example.test/checkout"}'],
        ]);
        $example = require dirname(__DIR__, 3) . '/examples/billing-customer-checkout.php';
        self::assertInstanceOf(Closure::class, $example);
        $result = $example($this->client($http));
        self::assertIsArray($result);
        self::assertSame([
            'customer' => ['id' => 'bcus_fixture', 'address' => ['country_code' => 'IT']],
            'checkout' => [
                'id' => 'checkout_fixture', 'status' => 'ACTIVE', 'url' => 'https://example.test/checkout',
            ],
        ], $result);
        self::assertCount(5, $http->requests);
        self::assertStringContainsString(
            '"billing_customer_id":"bcus_fixture"',
            (string) $http->requests[4]->getBody(),
        );
        self::assertStringContainsString('"currency":"EUR"', (string) $http->requests[4]->getBody());
        self::assertStringContainsString(
            '"subscription_data":{"days_until_due":0}',
            (string) $http->requests[4]->getBody(),
        );
        foreach ($http->requests as $request) {
            self::assertSame('', $request->getHeaderLine('x-on-behalf-of'));
        }
    }
}
