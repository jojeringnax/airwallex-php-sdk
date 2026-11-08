<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Resource\Payments;

use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use Jojeringnax\Airwallex\Webhook\WebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function selectedEvents(): iterable
    {
        $directory = dirname(__DIR__, 4) . '/docs/fixtures/webhooks/2026-08-21';
        foreach (['payment_intent', 'payment_attempt', 'refund'] as $family) {
            $file = file_get_contents($directory . '/' . $family . '.json');
            self::assertIsString($file);
            $fixtures = json_decode($file, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($fixtures);
            foreach ($fixtures as $fixture) {
                self::assertIsArray($fixture);
                self::assertIsString($fixture['name']);
                yield $fixture['name'] => [json_encode($fixture, JSON_THROW_ON_ERROR), $family];
            }
        }
    }

    #[DataProvider('selectedEvents')]
    public function testEveryNewCatalogueFixtureAuthenticatesAndDecodesWithoutStateInference(
        string $raw,
        string $family
    ): void {
        $timestamp = '1794132000000';
        (new WebhookVerifier('synthetic-secret', 0, static fn (): int => 1794132000000))->verify(
            $raw,
            $timestamp,
            hash_hmac('sha256', $timestamp . $raw, 'synthetic-secret'),
        );
        $event = WebhookEvent::fromJson($raw)->toArray();
        self::assertIsString($event['name']);
        self::assertStringStartsWith($family . '.', $event['name']);
        self::assertSame('2026-08-21', $event['version']);
        self::assertSame('account_synthetic', $event['account_id']);
        self::assertIsArray($event['data']);
        self::assertIsArray($event['data']['object']);
        $object = $event['data']['object'];
        self::assertIsString($object['amount']);
        self::assertIsString($object['status']);
        if ($family === 'payment_intent') {
            self::assertIsArray($object['order']);
            self::assertIsArray($object['order']['products']);
            self::assertIsArray($object['order']['products'][0]);
            self::assertSame(2, $object['order']['products'][0]['quantity']);
        }
    }

    public function testEverySelectedIntentIntegerPathAndExactFinancialText(): void
    {
        $raw = '{"id":"event","name":"payment_intent.updated","version":"2026-08-21",'
            . '"created_at":"2026-11-08T10:00:00Z","account_id":"account-A","data":{"object":{'
            . '"status":"SUCCEEDED","amount":123456789012345678901234.9000,"captured_amount":0,'
            . '"order":{"products":[{"quantity":2}],'
            . '"sellers":[{"additional_info":{"sales_summary":{"sales_count":6}}}]},'
            . '"additional_info":{"customer_activity_data":{"purchase_summaries":[{"successful_purchase_count":3}]},'
            . '"enhanced_scheme_data":{"products":[{"quantity":4}]},'
            . '"online_travel_agency_data":{"customer":{"loyalty_program_points":5}}},'
            . '"payment_consent":{"terms_of_use":{"billing_cycle_charge_day":7,"payment_schedule":{"period":8},'
            . '"total_billing_cycles":9}},"future_numeric":1.2300}}}';
        $result = WebhookEvent::fromJson($raw)->toArray();
        self::assertIsArray($result['data']);
        self::assertIsArray($result['data']['object']);
        $object = $result['data']['object'];
        self::assertSame('123456789012345678901234.9000', $object['amount']);
        self::assertSame('0', $object['captured_amount']);
        self::assertSame('1.2300', $object['future_numeric']);
        self::assertSame([
            'products' => [['quantity' => 2]],
            'sellers' => [['additional_info' => ['sales_summary' => ['sales_count' => 6]]]],
        ], $object['order']);
        self::assertSame([
            'customer_activity_data' => ['purchase_summaries' => [['successful_purchase_count' => 3]]],
            'enhanced_scheme_data' => ['products' => [['quantity' => 4]]],
            'online_travel_agency_data' => ['customer' => ['loyalty_program_points' => 5]],
        ], $object['additional_info']);
        self::assertSame(['terms_of_use' => [
            'billing_cycle_charge_day' => 7, 'payment_schedule' => ['period' => 8], 'total_billing_cycles' => 9,
        ]], $object['payment_consent']);
        $unknown = WebhookEvent::fromJson(str_replace('2026-08-21', '2099-01-01', $raw))->toArray();
        self::assertIsArray($unknown['data']);
        self::assertIsArray($unknown['data']['object']);
        self::assertSame(['terms_of_use' => [
            'billing_cycle_charge_day' => '7', 'payment_schedule' => ['period' => '8'], 'total_billing_cycles' => '9',
        ]], $unknown['data']['object']['payment_consent']);
    }
}
