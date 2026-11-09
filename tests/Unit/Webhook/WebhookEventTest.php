<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Webhook;

use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\WebhookVerificationException;
use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use Jojeringnax\Airwallex\Webhook\WebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class WebhookEventTest extends TestCase
{
    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function catalogueEvents(): iterable
    {
        $directory = dirname(__DIR__, 3) . '/docs/fixtures/webhooks/2026-08-21';
        foreach (['subscription', 'billing_checkout', 'invoice', 'payment_consent', 'payment_method'] as $family) {
            $file = file_get_contents($directory . '/' . $family . '.json');
            self::assertIsString($file);
            $fixtures = json_decode($file, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($fixtures);
            foreach ($fixtures as $fixture) {
                self::assertIsArray($fixture);
                self::assertIsString($fixture['name']);
                yield $fixture['name'] => [json_encode($fixture, JSON_THROW_ON_ERROR), $fixture];
            }
        }
    }

    /** @param array<array-key, mixed> $fixture */
    #[DataProvider('catalogueEvents')]
    public function testAllSyntheticCatalogueEventsVerifyAndDecode(string $raw, array $fixture): void
    {
        $timestamp = '1794132000000';
        $signature = hash_hmac('sha256', $timestamp . $raw, 'synthetic-secret');
        $verifier = new WebhookVerifier('synthetic-secret', 1000, static fn (): int => 1794132000000);
        $verifier->verify($raw, $timestamp, $signature);
        $result = WebhookEvent::fromJson($raw)->toArray();
        self::assertSame($fixture['name'], $result['name']);
        self::assertSame($fixture['version'], $result['version']);
        $source = self::resource($fixture);
        $resource = self::resource($result);
        self::assertSame($source['id'], $resource['id']);
        self::assertIsString($fixture['name']);
        if (str_starts_with($fixture['name'], 'subscription.')) {
            self::assertIsArray($resource['recurring']);
            self::assertSame(1, $resource['recurring']['period']);
            self::assertSame(0, $resource['days_until_due']);
        } elseif (str_starts_with($fixture['name'], 'billing_checkout.')) {
            self::assertIsArray($resource['line_items']);
            self::assertIsArray($resource['line_items'][0]);
            self::assertSame(1, $resource['line_items'][0]['quantity']);
        } elseif (str_starts_with($fixture['name'], 'invoice.')) {
            self::assertIsString($resource['amount_due']);
            self::assertIsString($resource['total_amount']);
            if (array_key_exists('past_payment_attempt_count', $source)) {
                self::assertIsInt($resource['past_payment_attempt_count']);
            } else {
                self::assertArrayNotHasKey('past_payment_attempt_count', $resource);
            }
        }
    }

    public function testUnrecognizedEnvelopePreservesFieldsNumbersAndEmptyShapes(): void
    {
        $raw = '{"name":"new.event","version":"2099-01-01","accountId":"opaque",'
            . '"account_id":"conflicting","data":{"object":{"amount":19.9000,"count":3,"status":"NEW"}},'
            . '"unknown":1e999,"empty_object":{},"empty_array":[],"nullable":null,"id":"00123"}';
        $result = WebhookEvent::fromJson($raw)->toArray();
        self::assertSame('new.event', $result['name']);
        self::assertSame('2099-01-01', $result['version']);
        self::assertSame('opaque', $result['accountId']);
        self::assertSame('conflicting', $result['account_id']);
        self::assertSame(['amount' => '19.9000', 'count' => '3', 'status' => 'NEW'], self::resource($result));
        self::assertSame('1e999', $result['unknown']);
        self::assertInstanceOf(stdClass::class, $result['empty_object']);
        self::assertSame([], $result['empty_array']);
        self::assertNull($result['nullable']);
        self::assertSame('00123', $result['id']);
        self::assertSame([], WebhookEvent::fromJson('{}')->toArray());
    }

    public function testUnsupportedOrConflictingEventsDoNotUseSelectedSchema(): void
    {
        $base = ['id' => 'evt_example', 'name' => 'subscription.modified', 'version' => '2026-08-21',
            'org_id' => 'org_example', 'created_at' => '2026-11-08T10:00:00Z',
            'data' => ['object' => ['status' => 'ACTIVE', 'days_until_due' => 3]]];
        foreach (['version', 'name', 'org_id', 'created_at', 'id', 'data'] as $field) {
            $copy = $base;
            unset($copy[$field]);
            $decoded = WebhookEvent::fromJson(json_encode($copy, JSON_THROW_ON_ERROR))->toArray();
            self::assertArrayNotHasKey($field, $decoded);
            if ($field !== 'data') {
                self::assertSame('3', self::resource($decoded)['days_until_due']);
            }
        }
        foreach (
            [['version' => '2025-01-01'], ['name' => 'subscription.future'], ['account_id' => 'acct_other'],
            ['data' => ['object' => ['status' => 'FUTURE', 'days_until_due' => 3]]]] as $change
        ) {
            $copy = array_replace($base, $change);
            $decoded = WebhookEvent::fromJson(json_encode($copy, JSON_THROW_ON_ERROR))->toArray();
            self::assertSame('3', self::resource($decoded)['days_until_due']);
        }
    }

    public function testExactFinancialValuesUnknownNumbersAndIntegerOverflow(): void
    {
        $raw = '{"id":"evt_example","name":"invoice.updated","version":"2026-08-21",'
            . '"org_id":"org_example","created_at":"2026-11-08T10:00:00Z","data":{"object":{'
            . '"status":"FINALIZED","amount_due":0,"total_amount":100000000000000000000000.000001,'
            . '"past_payment_attempt_count":2,"remaining_payment_attempt_count":9223372036854775808,'
            . '"added_numeric_field":123.4567890123456789}}}';
        $result = self::resource(WebhookEvent::fromJson($raw)->toArray());
        self::assertSame('0', $result['amount_due']);
        self::assertSame('100000000000000000000000.000001', $result['total_amount']);
        self::assertSame(2, $result['past_payment_attempt_count']);
        self::assertSame('9223372036854775808', $result['remaining_payment_attempt_count']);
        self::assertSame('123.4567890123456789', $result['added_numeric_field']);
    }

    public function testConsentCountsUseVerifiedSchemaOnlyWithinMatchingAccount(): void
    {
        $envelope = ['id' => 'evt_example', 'name' => 'payment_consent.requires_customer_action',
            'version' => '2026-08-21', 'account_id' => 'acct_example', 'created_at' => '2026-11-08T10:00:00Z',
            'data' => ['object' => ['status' => 'REQUIRES_CUSTOMER_ACTION', 'connected_account_id' => 'acct_example',
                'next_action' => ['remaining_attempts' => 2, 'micro_deposit_count' => 3, 'unknown_count' => 4],
                'terms_of_use' => ['total_billing_cycles' => 12]]]];
        $resource = self::resource(WebhookEvent::fromJson(json_encode($envelope, JSON_THROW_ON_ERROR))->toArray());
        self::assertSame(
            ['remaining_attempts' => 2, 'micro_deposit_count' => 3, 'unknown_count' => '4'],
            $resource['next_action']
        );
        self::assertSame(['total_billing_cycles' => 12], $resource['terms_of_use']);
        $envelope['data']['object']['connected_account_id'] = 'acct_other';
        $resource = self::resource(WebhookEvent::fromJson(json_encode($envelope, JSON_THROW_ON_ERROR))->toArray());
        self::assertSame(
            ['remaining_attempts' => '2', 'micro_deposit_count' => '3', 'unknown_count' => '4'],
            $resource['next_action']
        );
        self::assertSame(['total_billing_cycles' => '12'], $resource['terms_of_use']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidObjects(): iterable
    {
        foreach (['', '[]', 'null', 'false', '1', '"object"', '{"invalid":}', '{"bad":"\\uD800"}'] as $raw) {
            yield $raw => [$raw];
        }
    }

    #[DataProvider('invalidObjects')]
    public function testRejectsMalformedAndNonObjectJson(string $raw): void
    {
        $this->expectException(ProtocolException::class);
        WebhookEvent::fromJson($raw);
    }

    public function testTamperingFailsBeforeDecoding(): void
    {
        $raw = '{"unknown":{}}';
        $timestamp = '1794132000000';
        $signature = hash_hmac('sha256', $timestamp . $raw, 'synthetic-secret');
        $this->expectException(WebhookVerificationException::class);
        (new WebhookVerifier('synthetic-secret', 0, static fn (): int => 1794132000000))
            ->verify($raw . ' ', $timestamp, $signature);
        WebhookEvent::fromJson($raw);
    }

    /** @return iterable<string,array{string,string,bool,bool}> */
    public static function checkoutRepresentations(): iterable
    {
        foreach (['created', 'completed', 'cancelled'] as $event) {
            foreach (['ACTIVE', 'COMPLETED', 'CANCELLED', 'EXPIRED'] as $status) {
                foreach ([false, true] as $generic) {
                    foreach ([false, true] as $subscription) {
                        yield implode('-', [$event, $status, (int) $generic, (int) $subscription])
                            => [$event, $status, $generic, $subscription];
                    }
                }
            }
        }
    }

    #[DataProvider('checkoutRepresentations')]
    public function testCheckoutNumbersKeepTheirOriginalDirectOrGenericShape(
        string $event,
        string $status,
        bool $generic,
        bool $subscription,
    ): void {
        $timing = $subscription
            ? '"subscription_data":{"days_until_due":4,"duration":{"period":6},"recurring":{"period":1}}'
            : '"invoice_data":{"days_until_due":3}';
        $resource = '{"id":"checkout_example","status":"' . $status . '","mode":"'
            . ($subscription ? 'SUBSCRIPTION' : 'PAYMENT') . '","line_items":['
            . '{"quantity":2,"rate_card_version":7,"amount":12.340000000000000001},'
            . '{"quantity":5,"rate_card_version":9}],"unknown_count":11,' . $timing . '}';
        $decoded = WebhookEvent::fromJson(self::checkoutRaw($resource, $generic, $event))->toArray();
        self::assertIsArray($decoded['data']);
        self::assertSame($generic, array_key_exists('object', $decoded['data']));
        $object = $generic ? $decoded['data']['object'] : $decoded['data'];
        self::assertIsArray($object);
        self::assertSame($status, $object['status']);
        self::assertSame('11', $object['unknown_count']);
        self::assertSame([
            ['quantity' => 2, 'rate_card_version' => 7, 'amount' => '12.340000000000000001'],
            ['quantity' => 5, 'rate_card_version' => 9],
        ], $object['line_items']);
        self::assertSame(
            $subscription ? ['days_until_due' => 4, 'duration' => ['period' => 6], 'recurring' => ['period' => 1]]
                : ['days_until_due' => 3],
            $object[$subscription ? 'subscription_data' : 'invoice_data']
        );
    }

    /** @return iterable<string,array{string,int|string}> */
    public static function checkoutIntegerTokens(): iterable
    {
        yield 'zero' => ['0', 0];
        yield 'negative' => ['-7', -7];
        yield 'maximum' => ['9223372036854775807', PHP_INT_MAX];
        yield 'minimum' => ['-9223372036854775808', PHP_INT_MIN];
        yield 'positive overflow' => ['9223372036854775808', '9223372036854775808'];
        yield 'negative overflow' => ['-9223372036854775809', '-9223372036854775809'];
        yield 'decimal form' => ['1.00', '1.00'];
        yield 'exponent form' => ['1e2', '1e2'];
        yield 'quoted identifier-like value' => ['"0007"', '0007'];
    }

    #[DataProvider('checkoutIntegerTokens')]
    public function testDirectAndGenericCheckoutKeepExactIntegerBoundaryRules(string $token, int|string $expected): void
    {
        foreach ([false, true] as $generic) {
            $raw = self::checkoutRaw('{"status":"ACTIVE","line_items":[{"quantity":' . $token . '}]}', $generic);
            $decoded = WebhookEvent::fromJson($raw)->toArray();
            self::assertIsArray($decoded['data']);
            $resource = $generic ? $decoded['data']['object'] : $decoded['data'];
            self::assertIsArray($resource);
            self::assertSame([['quantity' => $expected]], $resource['line_items']);
        }
    }

    public function testExistingGenericSelectionWinsAndEveryCompetingObjectPreventsDirectFallback(): void
    {
        $object = '{"status":"ACTIVE","line_items":[{"quantity":3}]}';
        foreach (['null', 'false', '2', '[]', '{}', $object] as $candidate) {
            $raw = self::checkoutRaw('{"id":"wrapper","status":"ACTIVE","line_items":[{"quantity":2}],'
                . '"object":' . $candidate . ',"extra":19.9000}');
            $decoded = WebhookEvent::fromJson($raw)->toArray();
            self::assertIsArray($decoded['data']);
            self::assertSame([['quantity' => '2']], $decoded['data']['line_items']);
            self::assertSame('19.9000', $decoded['data']['extra']);
            if ($candidate === $object) {
                self::assertIsArray($decoded['data']['object']);
                self::assertSame([['quantity' => 3]], $decoded['data']['object']['line_items']);
            }
        }
        $raw = self::checkoutRaw('{"status":"ACTIVE","line_items":[{"quantity":2}],"\\u006fbject":null}');
        $decoded = WebhookEvent::fromJson($raw)->toArray();
        self::assertIsArray($decoded['data']);
        self::assertSame([['quantity' => '2']], $decoded['data']['line_items']);
    }

    public function testDirectCheckoutRetainsExistingDecodedIdentityAndLastWinsSemantics(): void
    {
        $raw = self::checkoutRaw('{"status":"ACTIVE","line_items":[{"quantity":1,"quantity":2}]}');
        $raw = str_replace('"id":"evt_example"', '"id":123', $raw);
        $raw = str_replace('"org_id":"org_example"', '"org_id":456', $raw);
        $decoded = WebhookEvent::fromJson($raw)->toArray();
        self::assertSame('123', $decoded['id']);
        self::assertSame('456', $decoded['org_id']);
        self::assertIsArray($decoded['data']);
        self::assertSame([['quantity' => 2]], $decoded['data']['line_items']);
    }

    public function testUnselectedDirectEnvelopesKeepQuantityAsText(): void
    {
        $base = self::checkoutRaw('{"status":"ACTIVE","line_items":[{"quantity":2}]}');
        foreach (['id', 'name', 'created_at', 'version', 'org_id'] as $field) {
            $decoded = json_decode($base, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            unset($decoded[$field]);
            $event = WebhookEvent::fromJson(json_encode($decoded, JSON_THROW_ON_ERROR))->toArray();
            self::assertIsArray($event['data']);
            self::assertSame([['quantity' => '2']], $event['data']['line_items']);
        }
        foreach (
            [
            ['2026-08-21', '2025-01-01'], ['billing_checkout.created', 'billing_checkout.future'],
            ['billing_checkout.created', 'subscription.created'], ['billing_checkout.created', 'invoice.created'],
            ['"ACTIVE"', '"FUTURE"'], ['"org_example"', '""'],
            ['"org_id":"org_example"', '"org_id":"org_example","account_id":"conflict"'],
            ['"org_id":"org_example"', '"org_id":"org_example","accountId":"conflict"'],
            ] as [$from, $to]
        ) {
            $event = WebhookEvent::fromJson(str_replace($from, $to, $base))->toArray();
            self::assertIsArray($event['data']);
            self::assertSame([['quantity' => '2']], $event['data']['line_items']);
        }
        foreach (['null', 'false', '[]', '{}', '[{"status":"ACTIVE","quantity":2}]'] as $data) {
            $event = WebhookEvent::fromJson(self::checkoutRaw($data))->toArray();
            self::assertArrayHasKey('data', $event);
        }
    }

    public function testOtherDirectFamiliesKeepTheirOwnKnownIntegerPositionsAsText(): void
    {
        foreach (
            [
            ['subscription.created', '"org_id":"org_example"',
                '{"status":"ACTIVE","recurring":{"period":2},"days_until_due":3}',
                ['status' => 'ACTIVE', 'recurring' => ['period' => '2'], 'days_until_due' => '3']],
            ['invoice.created', '"org_id":"org_example"',
                '{"status":"DRAFT","days_until_due":3,"past_payment_attempt_count":2}',
                ['status' => 'DRAFT', 'days_until_due' => '3', 'past_payment_attempt_count' => '2']],
            ['payment_intent.created', '"account_id":"account_example"',
                '{"status":"REQUIRES_PAYMENT_METHOD","order":{"products":[{"quantity":3}]}}',
                ['status' => 'REQUIRES_PAYMENT_METHOD', 'order' => ['products' => [['quantity' => '3']]]]],
            ] as [$name, $owner, $data, $expected]
        ) {
            $raw = '{"id":"event_example","created_at":"2026-11-09T00:00:00Z","version":"2026-08-21",'
                . '"name":"' . $name . '",' . $owner . ',"data":' . $data . '}';
            self::assertSame($expected, WebhookEvent::fromJson($raw)->toArray()['data']);
        }
    }

    private static function checkoutRaw(string $resource, bool $generic = false, string $event = 'created'): string
    {
        return '{"id":"evt_example","name":"billing_checkout.' . $event . '","version":"2026-08-21",'
            . '"org_id":"org_example","created_at":"2026-11-09T00:00:00Z","data":'
            . ($generic ? '{"object":' . $resource . '}' : $resource) . '}';
    }

    /**
     * @param array<array-key, mixed> $envelope
     * @return array<array-key, mixed>
     */
    private static function resource(array $envelope): array
    {
        self::assertIsArray($envelope['data']);
        self::assertIsArray($envelope['data']['object']);

        return $envelope['data']['object'];
    }
}
