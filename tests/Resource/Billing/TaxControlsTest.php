<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TaxControlsTest extends HttpTestCase
{
    private const string LOGIN = '{"token":"fixture-token","expires_at":"2130-01-01T00:00:00Z"}';

    public function testManualInvoiceRecipePreservesExactRateAndProviderTaxBreakdown(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"invoice"}'], [200, '{}'],
            [200, '{"id":"invoice","total_tax_amount":0.000000000001,"total_amount":100.000000000001}']]);
        $recipes = require dirname(__DIR__, 3) . '/examples/billing-tax.php';
        self::assertIsArray($recipes);
        self::assertInstanceOf(\Closure::class, $recipes['manual_invoice']);
        $value = $recipes['manual_invoice'](
            $this->client($http),
            'customer',
            'price',
            '7.125000000001',
            'create',
            'items'
        );
        self::assertIsArray($value);
        self::assertSame('0.000000000001', $value['total_tax_amount']);
        self::assertStringContainsString(
            '"default_tax_percent":7.125000000001',
            (string) $http->requests[1]->getBody()
        );
        self::assertStringContainsString('"enable_automatic_tax":false', (string) $http->requests[1]->getBody());
    }

    public function testAutomaticSubscriptionRecipeAndHostedCheckoutExposeUnverifiedCalculationStatus(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"sub","automatic_tax_status":"FUTURE_STATUS"}'],
            [201, '{"id":"checkout","enable_automatic_tax":true}']]);
        $client = $this->client($http);
        $recipes = require dirname(__DIR__, 3) . '/examples/billing-tax.php';
        self::assertIsArray($recipes);
        self::assertInstanceOf(\Closure::class, $recipes['automatic_subscription']);
        $value = $recipes['automatic_subscription']($client, 'customer', 'price', 'create');
        self::assertIsArray($value);
        self::assertSame('FUTURE_STATUS', $value['automatic_tax_status']);
        $client->billing()->checkouts()->create(['request_id' => 'checkout', 'mode' => 'SUBSCRIPTION',
            'billing_customer_id' => 'customer', 'ui_mode' => 'HOSTED', 'enable_automatic_tax' => true,
            'line_items' => [['price_id' => 'price', 'quantity' => 1]]]);
        self::assertStringContainsString('"enable_automatic_tax":true', (string) $http->requests[2]->getBody());
        self::assertSame('', $http->requests[1]->getHeaderLine('x-on-behalf-of'));
    }

    public function testItemOverridesAndPermittedUpdateClearingRetainExactWireTypes(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{}'], [200, '{}'], [200, '{}']]);
        $client = $this->client($http);
        $client->billing()->subscriptions()->update('sub', ['request_id' => 'update',
            'enable_automatic_tax' => false, 'default_tax_percent' => '0.00',
            'items' => [['id' => 'item', 'tax_percent' => '100.000000000000']]]);
        $client->billing()->invoices()->update('invoice', ['default_tax_percent' => null]);
        $client->billing()->invoices()->updateLineItems('invoice', ['line_items' => [[
            'id' => 'item', 'tax_percent' => '8.750000000001',
        ]]]);
        self::assertStringContainsString('"tax_percent":100.000000000000', (string) $http->requests[1]->getBody());
        self::assertSame('{"default_tax_percent":null}', (string) $http->requests[2]->getBody());
        self::assertStringContainsString('"tax_percent":8.750000000001', (string) $http->requests[3]->getBody());
    }

    /** @param mixed $value */
    #[DataProvider('invalidRates')]
    public function testInvalidManualRatesFailBeforeAuthentication(mixed $value): void
    {
        $http = $this->scenario([]);
        try {
            $this->client($http)->billing()->subscriptions()->update('sub', [
                'request_id' => 'original', 'items' => [['id' => 'item', 'tax_percent' => $value]],
            ]);
            self::fail('Invalid manual tax accepted.');
        } catch (ValidationException) {
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidRates(): iterable
    {
        yield 'float' => [7.5];
        yield 'negative' => ['-0.000000000001'];
        yield 'over100' => ['100.000000000001'];
        yield 'exponent' => ['1e1'];
    }

    public function testProviderTaxContextFailureDoesNotFallbackToManualOrReplay(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [400, '{"code":"validation_error"}']]);
        try {
            $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3))->billing()->invoices()->create([
                'request_id' => 'original', 'billing_customer_id' => 'customer', 'currency' => 'USD',
                'collection_method' => 'OUT_OF_BAND', 'enable_automatic_tax' => true,
            ]);
            self::fail('Expected missing/unsupported tax context to fail.');
        } catch (ApiException $error) {
            self::assertSame(400, $error->httpStatus());
            self::assertCount(2, $http->requests);
            self::assertStringContainsString('"enable_automatic_tax":true', (string) $http->requests[1]->getBody());
        }
    }

    public function testTaxContextFieldsAreExplicitWithoutHiddenLookupOrCalculation(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"customer"}'],
            [201, '{"id":"product"}'], [201, '{"id":"price","tax_included":true}']]);
        $billing = $this->client($http)->billing();
        $billing->customers()->create(['request_id' => 'customer', 'tax_identification_number' => 'SYNTHETIC-TAX-ID',
            'address' => ['country_code' => 'GB', 'postcode' => 'SYNTHETIC']]);
        $billing->products()->create([
            'request_id' => 'product', 'name' => 'Synthetic', 'tax_code' => 'VERIFIED-CATEGORY',
        ]);
        $billing->prices()->create(['request_id' => 'price', 'product_id' => 'product', 'currency' => 'USD',
            'pricing_model' => 'PER_UNIT', 'unit_amount' => '100.00', 'tax_included' => true]);
        self::assertCount(4, $http->requests);
        self::assertStringContainsString('"tax_code":"VERIFIED-CATEGORY"', (string) $http->requests[2]->getBody());
        self::assertStringContainsString('"tax_included":true', (string) $http->requests[3]->getBody());
    }
}
