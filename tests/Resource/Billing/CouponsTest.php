<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CouponsTest extends HttpTestCase
{
    private const string LOGIN = '{"token":"fixture-token","expires_at":"2130-01-01T00:00:00Z"}';

    public function testSignupRecipeSeparatesTemplateFromAppliedDiscountAndKeepsExactAmounts(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"coupon","percentage_off":20.000000000001}'],
            [201, '{"id":"sub"}'], [200, '{"id":"sub","applied_discounts":[{"source":"COUPON",'
                . '"source_id":"coupon","percentage_off":20.000000000001,"amount_off":0.000000000001}]}']]);
        $recipe = require dirname(__DIR__, 3) . '/examples/billing-coupons.php';
        self::assertInstanceOf(\Closure::class, $recipe);
        $value = $recipe($this->client($http), 'customer', 'price', 'template-command', 'signup-command');
        self::assertIsArray($value);
        self::assertIsArray($value['coupon']);
        self::assertIsArray($value['subscription']);
        self::assertIsArray($value['subscription']['applied_discounts']);
        self::assertIsArray($value['subscription']['applied_discounts'][0]);
        self::assertSame('20.000000000001', $value['coupon']['percentage_off']);
        self::assertSame('0.000000000001', $value['subscription']['applied_discounts'][0]['amount_off']);
        self::assertStringContainsString('"percentage_off":20.000000000001', (string) $http->requests[1]->getBody());
        self::assertStringContainsString('"request_id":"signup-command"', (string) $http->requests[2]->getBody());
        self::assertStringContainsString(
            '"discounts":[{"type":"COUPON","coupon":{"id":"coupon"}}]',
            (string) $http->requests[2]->getBody()
        );
        self::assertSame('', $http->requests[2]->getHeaderLine('x-on-behalf-of'));
        self::assertSame('2026-08-21', $http->requests[1]->getHeaderLine('x-api-version'));
    }

    public function testFlatCouponInvoiceItemsReplacementAndBodylessFinalization(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"coupon","amount_off":10.000000000001}'],
            [201, '{"id":"invoice","status":"DRAFT"}'], [200, '{}'], [200, '{}'], [200, '{}'],
            [200, '{"id":"invoice","status":"FINALIZED","total_discount_amounts":[{"amount":10.000000000001}]}']]);
        $billing = $this->client($http)->billing();
        $coupon = $billing->coupons()->create(['request_id' => 'template', 'name' => 'Exact flat',
            'discount_model' => 'FLAT', 'amount_off' => '10.000000000001', 'currency' => 'USD',
            'duration_type' => 'ONCE', 'duration' => null]);
        self::assertSame('10.000000000001', $coupon['amount_off']);
        $discount = ['type' => 'COUPON', 'coupon' => ['id' => 'coupon']];
        $billing->invoices()->create(['request_id' => 'invoice-command', 'billing_customer_id' => 'customer',
            'currency' => 'USD', 'collection_method' => 'OUT_OF_BAND', 'discounts' => [$discount]]);
        $billing->invoices()->addLineItems('invoice /+', ['request_id' => 'line-command',
            'line_items' => [['price_id' => 'price', 'quantity' => 2, 'discounts' => [$discount]]]]);
        $billing->invoices()->update('invoice /+', ['discounts' => null]);
        $billing->invoices()->updateLineItems('invoice /+', ['line_items' => [['id' => 'item', 'discounts' => []]]]);
        $value = $billing->invoices()->finalize('invoice /+');
        self::assertIsArray($value['total_discount_amounts']);
        self::assertIsArray($value['total_discount_amounts'][0]);
        self::assertSame('10.000000000001', $value['total_discount_amounts'][0]['amount']);
        self::assertStringEndsWith('/invoice%20%2F%2B/finalize', (string) $http->requests[6]->getUri());
        self::assertSame('', (string) $http->requests[6]->getBody());
        self::assertSame('{"discounts":null}', (string) $http->requests[4]->getBody());
        self::assertStringContainsString('"request_id":"line-command"', (string) $http->requests[3]->getBody());
    }

    public function testArchiveRetrieveAndExplicitPagesKeepOpaqueCouponIdentity(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{"id":"coupon","active":false}'],
            [200, '{"id":"coupon","active":false,"duration_type":"INDEFINITELY"}'],
            [200, '{"items":[{"id":"coupon"}],"page_after":"next /+"}'], [200, '{"items":[],"page_after":null}']]);
        $coupons = $this->client($http)->billing()->coupons();
        $coupons->update('coupon /+', ['active' => false]);
        self::assertFalse($coupons->retrieve('coupon /+')['active']);
        $page = $coupons->list(['active' => false, 'page_size' => 1]);
        $last = $coupons->list(['active' => false, 'page_size' => 1, 'page' => $page['page_after']]);
        self::assertSame([], $last['items']);
        self::assertStringEndsWith('/coupon%20%2F%2B/update', (string) $http->requests[1]->getUri());
        self::assertStringEndsWith(
            'active=false&page_size=1&page=next%20%2F%2B',
            (string) $http->requests[4]->getUri()
        );
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidCoupons')]
    public function testInvalidCouponControlsFailBeforeAuthentication(array $changes): void
    {
        $http = $this->scenario([]);
        try {
            $this->client($http)->billing()->coupons()->create(array_replace([
                'request_id' => 'stable', 'name' => 'Coupon', 'discount_model' => 'PERCENTAGE',
                'percentage_off' => '20', 'duration_type' => 'ONCE',
            ], $changes));
            self::fail('Invalid coupon accepted.');
        } catch (ValidationException) {
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidCoupons(): iterable
    {
        yield 'float money' => [['percentage_off' => 20.0]];
        yield 'negative percent' => [['percentage_off' => '-0.000000000001']];
        yield 'over hundred' => [['percentage_off' => '100.000000000001']];
        yield 'flat without currency' => [['discount_model' => 'FLAT', 'amount_off' => '10']];
        yield 'custom missing duration' => [['duration_type' => 'CUSTOM']];
        yield 'custom null duration' => [['duration_type' => 'CUSTOM', 'duration' => null]];
        yield 'once with duration' => [['duration' => ['period' => 1, 'period_unit' => 'MONTH']]];
        yield 'zero duration' => [[
            'duration_type' => 'CUSTOM', 'duration' => ['period' => 0, 'period_unit' => 'MONTH'],
        ]];
        yield 'bad expiry' => [['expires_at' => '2026-02-30T00:00:00Z']];
    }

    public function testCheckoutAndSubscriptionItemDiscountsValidatePerLevelAndAllowRemoval(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"checkout"}'], [200, '{"id":"sub"}']]);
        $client = $this->client($http);
        $discount = ['type' => 'COUPON', 'coupon' => ['id' => 'coupon']];
        $client->billing()->checkouts()->create(['request_id' => 'checkout', 'mode' => 'SUBSCRIPTION',
            'billing_customer_id' => 'customer', 'discounts' => [$discount],
            'line_items' => [['price_id' => 'price', 'quantity' => 1, 'discounts' => [$discount]]]]);
        $client->billing()->subscriptions()->update('sub', ['request_id' => 'remove', 'discounts' => [],
            'items' => [['id' => 'item', 'discounts' => [$discount]]]]);
        self::assertStringContainsString('"discounts":[]', (string) $http->requests[2]->getBody());
        $bad = $this->scenario([]);
        try {
            $this->client($bad)->billing()->subscriptions()->create(['request_id' => 'two',
                'billing_customer_id' => 'customer', 'collection_method' => 'OUT_OF_BAND',
                'items' => [['price_id' => 'price', 'quantity' => 1, 'discounts' => [$discount, $discount]]]]);
            self::fail('Multiple coupons on one item accepted.');
        } catch (ValidationException) {
            self::assertSame([], $bad->requests);
        }
    }

    #[DataProvider('providerRejections')]
    public function testProviderEligibilityFailuresAndUnavailableWritesAreNotReplayed(int $status, string $code): void
    {
        $http = $this->scenario([[201, self::LOGIN], [$status, '{"code":"' . $code . '","message":"private-detail"}']]);
        try {
            $client = $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3));
            $subscriptions = $client->billing()->subscriptions();
            $subscriptions->update('sub', [
                'request_id' => 'original', 'discounts' => [['type' => 'COUPON', 'coupon' => ['id' => 'coupon']]],
            ]);
            self::fail('Expected provider rejection.');
        } catch (ApiException $error) {
            self::assertSame($status, $error->httpStatus());
            self::assertCount(2, $http->requests);
            self::assertStringContainsString('"request_id":"original"', (string) $http->requests[1]->getBody());
            self::assertStringNotContainsString('private-detail', $error->getMessage());
        }
    }

    /** @return iterable<string,array{int,string}> */
    public static function providerRejections(): iterable
    {
        yield 'currency mismatch' => [400, 'currency_mismatch'];
        yield 'expired coupon' => [400, 'coupon_expired'];
        yield 'inactive coupon' => [400, 'coupon_inactive'];
        yield 'service unavailable' => [503, 'service_unavailable'];
    }

    public function testDelegatedBillingContextFailsBeforeAuthentication(): void
    {
        $http = $this->scenario([]);
        $this->expectException(ConfigurationException::class);
        $this->client($http)->forConnectedAccount('account')->billing()->coupons()->retrieve('coupon');
    }

    public function testInvoiceCreationRejectsNullDiscountsBeforeAuthentication(): void
    {
        $http = $this->scenario([]);
        try {
            $this->client($http)->billing()->invoices()->create([
                'request_id' => 'original', 'billing_customer_id' => 'customer', 'currency' => 'USD',
                'discounts' => null,
            ]);
            self::fail('Create accepted a null discount array.');
        } catch (ValidationException) {
            self::assertSame([], $http->requests);
        }
    }
}
