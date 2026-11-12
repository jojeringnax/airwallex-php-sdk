<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RateCardsTest extends HttpTestCase
{
    private const string LOGIN = '{"token":"fixture-token","expires_at":"2130-01-01T00:00:00Z"}';

    public function testRecipeCreatesOrderedRowsPinsVersionAndPublishesWithoutMigratingSubscribers(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"card"}'], [201, '{"id":"sub"}'],
            [201, '{"rate_card_id":"card","version":2}'],
            [200, '{"id":"invoice","currency":"USD","total_amount":0.0000025000001}'],
            [200, '{"items":[{"id":"line","rate_card_item_id":"row","amount":0.0000025000001}]}']]);
        $client = $this->client($http);
        $recipes = require dirname(__DIR__, 3) . '/examples/billing-rate-cards.php';
        self::assertIsArray($recipes);
        self::assertInstanceOf(\Closure::class, $recipes['create']);
        self::assertInstanceOf(\Closure::class, $recipes['subscribe']);
        self::assertInstanceOf(\Closure::class, $recipes['publish']);
        $recipes['create']($client, 'product', 'meter', 'create-card');
        $recipes['subscribe']($client, 'customer', 'card', 1, 'create-subscription');
        $version = $recipes['publish']($client, 'card', 'old-fallback', 'publish-version');
        self::assertIsArray($version);
        self::assertSame(2, $version['version']);
        self::assertStringContainsString('"unit_amount":0.0000025000001', (string) $http->requests[1]->getBody());
        self::assertStringContainsString('"dimension_values":{}', (string) $http->requests[1]->getBody());
        self::assertStringContainsString('"rate_card_version":1', (string) $http->requests[2]->getBody());
        self::assertSame('0.0000025000001', $client->billing()->invoices()->retrieve('invoice')['total_amount']);
        $page = $client->billing()->invoices()->listLineItems('invoice');
        self::assertIsArray($page['items']);
        self::assertIsArray($page['items'][0]);
        self::assertSame('0.0000025000001', $page['items'][0]['amount']);
        self::assertCount(6, $http->requests);
    }

    public function testVersionReadsAndOrderedItemPagesRetainExplicitCardAndEscapedFilters(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{"rate_card_id":"card","version":1}'],
            [200, '{"items":[{"version":1}],"page_after":"next /+"}'], [200, '{"items":[]}'],
            [200, '{"items":[{"id":"row","unit_amount":0.000000000001}],"page_after":"items-next"}'],
            [200, '{"id":"row","rate_card_id":"card","unit_amount":0.000000000001}'],
            [200, '{"items":[]}'], [200, '{"id":"card"}'], [200, '{}'], [200, '{"items":[]}']]);
        $cards = $this->client($http)->billing()->rateCards();
        self::assertSame(1, $cards->retrieveVersion('card /+', 1)['version']);
        $page = $cards->listVersions('card /+', ['page_size' => 1]);
        $cards->listVersions('card /+', ['page_size' => 1, 'page' => $page['page_after']]);
        $items = $cards->listVersionItems('card /+', 1, ['dimension_filters' => ['model' => 'a&b'], 'page_size' => 1]);
        self::assertSame('0.000000000001', $cards->retrieveVersionItem('card /+', 1, 'row /+')['unit_amount']);
        $cards->listVersionItems('card /+', 1, ['page' => $items['page_after'], 'page_size' => 1]);
        $cards->retrieve('card');
        $cards->update('card', ['active' => false]);
        $cards->list(['active' => false]);
        self::assertStringContainsString('/card%20%2F%2B/versions/1/items?', (string) $http->requests[4]->getUri());
        self::assertStringContainsString('dimension_filters%5Bmodel%5D=a%26b', (string) $http->requests[4]->getUri());
        self::assertStringEndsWith('/versions/1/items/row%20%2F%2B', (string) $http->requests[5]->getUri());
    }

    public function testMeteredPriceAndExplicitExistingVersionChangeUseTheSelectedFields(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"price","metered":true}'],
            [200, '{"id":"sub"}'], [201, '{"id":"checkout"}']]);
        $billing = $this->client($http)->billing();
        $billing->prices()->create(['request_id' => 'price', 'product_id' => 'product', 'currency' => 'USD',
            'pricing_model' => 'PER_UNIT', 'unit_amount' => '0.000001', 'metered' => true, 'meter_id' => 'meter',
            'recurring' => ['period' => 1, 'period_unit' => 'MONTH'], 'billing_type' => 'IN_ARREARS']);
        $billing->subscriptions()->update('sub', ['request_id' => 'version-command',
            'items' => [['id' => 'subscription-item', 'rate_card_version' => 2]],
            'default_proration_mode' => 'NONE', 'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE']);
        $billing->checkouts()->create(['request_id' => 'checkout', 'mode' => 'SUBSCRIPTION',
            'billing_customer_id' => 'customer',
            'line_items' => [['rate_card_id' => 'card', 'rate_card_version' => 1]]]);
        self::assertStringContainsString(
            '"metered":true,"meter_id":"meter"',
            (string) $http->requests[1]->getBody()
        );
        self::assertStringContainsString('"rate_card_version":2', (string) $http->requests[2]->getBody());
        self::assertStringContainsString('"rate_card_version":1', (string) $http->requests[3]->getBody());
    }

    /** @param array<string,mixed> $item */
    #[DataProvider('invalidRows')]
    public function testInvalidNewVersionRowsFailBeforeAuthentication(array $item): void
    {
        $http = $this->scenario([]);
        try {
            $this->client($http)->billing()->rateCards()->createVersion('card', [
                'request_id' => 'original', 'items' => [$item],
            ]);
            self::fail('Invalid Rate Card row accepted.');
        } catch (ValidationException) {
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidRows(): iterable
    {
        yield 'missing row definition' => [[]];
        yield 'reference mixed with definition' => [['id' => 'old', 'unit_amount' => '1']];
        yield 'float unit amount' => [[
            'dimension_values' => new \stdClass(), 'pricing_model' => 'PER_UNIT', 'unit_amount' => 1.5,
        ]];
        yield 'flat not available' => [['dimension_values' => new \stdClass(), 'pricing_model' => 'FLAT']];
        yield 'missing tiers' => [['dimension_values' => new \stdClass(), 'pricing_model' => 'VOLUME']];
        yield 'bounded last tier' => [['dimension_values' => new \stdClass(), 'pricing_model' => 'GRADUATED',
            'tiers' => [['unit_amount' => '1', 'upper_bound' => '10']]]];
    }

    /** @param array<string,mixed> $item */
    #[DataProvider('invalidSubscriptionItems')]
    public function testPinsCannotBeMissingMixedOrCleared(array $item): void
    {
        $http = $this->scenario([]);
        try {
            $this->client($http)->billing()->subscriptions()->update('sub', [
                'request_id' => 'original', 'items' => [$item],
            ]);
            self::fail('Invalid pricing source or pin accepted.');
        } catch (ValidationException) {
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidSubscriptionItems(): iterable
    {
        yield 'no version' => [['rate_card_id' => 'card']];
        yield 'two sources' => [['price_id' => 'price', 'rate_card_id' => 'card', 'rate_card_version' => 1]];
        yield 'existing card replacement' => [['id' => 'item', 'rate_card_id' => 'card', 'rate_card_version' => 1]];
        yield 'cleared pin' => [['id' => 'item', 'rate_card_version' => null]];
    }

    public function testProviderVersionOrUsageRefusalIsNotReplayed(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [400, '{"code":"validation_error"}']]);
        try {
            $client = $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3));
            $client->billing()->subscriptions()->update('sub', [
                'request_id' => 'original', 'items' => [['id' => 'item', 'rate_card_version' => 999]],
            ]);
            self::fail('Expected missing/foreign version refusal.');
        } catch (ApiException $error) {
            self::assertSame(400, $error->httpStatus());
            self::assertCount(2, $http->requests);
        }
    }

    public function testRateCardFirstTierMayHaveZeroBoundWithoutChangingPriceRules(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"version":2}']]);
        $tiers = [['upper_bound' => '0', 'flat_amount' => '1'], ['unit_amount' => '2']];
        $this->client($http)->billing()->rateCards()->createVersion('card', ['request_id' => 'version', 'items' => [[
            'dimension_values' => new \stdClass(), 'pricing_model' => 'GRADUATED', 'tiers' => $tiers,
        ]]]);
        self::assertStringContainsString('"upper_bound":0', (string) $http->requests[1]->getBody());
        $bad = $this->scenario([]);
        try {
            $this->client($bad)->billing()->prices()->create(['request_id' => 'price', 'product_id' => 'product',
                'currency' => 'USD', 'pricing_model' => 'GRADUATED', 'tiers' => $tiers]);
            self::fail('Existing Price bound rule changed.');
        } catch (ValidationException) {
            self::assertSame([], $bad->requests);
        }
    }
}
