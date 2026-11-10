<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Webhook;

use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use Jojeringnax\Airwallex\Webhook\WebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentConsentEventTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, string|null>}> */
    public static function relatedAccounts(): iterable
    {
        yield 'different from owner' => ['"connected_account_id":"related_merchant",',
            ['connected_account_id' => 'related_merchant']];
        yield 'equal to owner' => ['"connected_account_id":"platform_owner",',
            ['connected_account_id' => 'platform_owner']];
        yield 'absent' => ['', []];
        yield 'null' => ['"connected_account_id":null,', ['connected_account_id' => null]];
    }

    /** @param array<string, string|null> $relatedAccount */
    #[DataProvider('relatedAccounts')]
    public function testRelatedAccountDoesNotChangeSelectedIntegerPaths(string $fields, array $relatedAccount): void
    {
        $raw = self::raw(relatedAccountFields: $fields);
        $timestamp = '1794304800000';
        $signature = hash_hmac('sha256', $timestamp . $raw, 'synthetic-secret');
        $verifier = new WebhookVerifier('synthetic-secret', 1000, static fn (): int => 1794304800000);
        $verifier->verify($raw, $timestamp, $signature);

        self::assertSame([
            'id' => 'evt_synthetic',
            'name' => 'payment_consent.requires_customer_action',
            'version' => '2026-08-21',
            'created_at' => '2026-11-10T10:00:00Z',
            'account_id' => 'platform_owner',
            'data' => ['object' => [
                'id' => 'cst_synthetic',
                'status' => 'REQUIRES_CUSTOMER_ACTION',
                ...$relatedAccount,
                'next_action' => ['micro_deposit_count' => 2, 'remaining_attempts' => 2, 'unknown_count' => '23'],
                'terms_of_use' => [
                    'payment_amount_type' => 'VARIABLE', 'billing_cycle_charge_day' => 2,
                    'payment_schedule' => ['period' => 2, 'period_unit' => 'MONTH'], 'total_billing_cycles' => 2,
                    'first_payment_amount' => '0', 'max_payment_amount' => '19.900000000000000001',
                ],
            ]],
        ], WebhookEvent::fromJson($raw)->toArray());
    }

    /** @return iterable<string, array{string, int|string}> */
    public static function integerTokens(): iterable
    {
        yield 'zero' => ['0', 0];
        yield 'negative' => ['-7', -7];
        yield 'maximum' => ['9223372036854775807', PHP_INT_MAX];
        yield 'minimum' => ['-9223372036854775808', PHP_INT_MIN];
        yield 'positive overflow' => ['9223372036854775808', '9223372036854775808'];
        yield 'negative overflow' => ['-9223372036854775809', '-9223372036854775809'];
        yield 'decimal form' => ['1.00', '1.00'];
        yield 'exponent form' => ['1e2', '1e2'];
        yield 'quoted numeric' => ['"2"', '2'];
        yield 'quoted leading zeroes' => ['"0007"', '0007'];
    }

    #[DataProvider('integerTokens')]
    public function testPlatformOwnedConsentPreservesNumericRepresentations(string $token, int|string $expected): void
    {
        $event = WebhookEvent::fromJson(self::raw($token))->toArray();
        self::assertIsArray($event['data']);
        self::assertIsArray($event['data']['object']);
        $resource = $event['data']['object'];
        self::assertSame('related_merchant', $resource['connected_account_id']);
        self::assertSame([
            'micro_deposit_count' => $expected, 'remaining_attempts' => $expected, 'unknown_count' => '23',
        ], $resource['next_action']);
        self::assertSame([
            'payment_amount_type' => 'VARIABLE', 'billing_cycle_charge_day' => $expected,
            'payment_schedule' => ['period' => $expected, 'period_unit' => 'MONTH'],
            'total_billing_cycles' => $expected,
            'first_payment_amount' => '0', 'max_payment_amount' => '19.900000000000000001',
        ], $resource['terms_of_use']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unselectedEnvelopes(): iterable
    {
        foreach (['id', 'name', 'version', 'created_at', 'account_id'] as $field) {
            $base = json_decode(self::raw(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($base);
            self::assertIsString($base[$field]);
            $pair = '"' . $field . '":"' . $base[$field] . '"';
            yield 'missing ' . $field => [$pair . ',', ''];
            yield 'empty ' . $field => [$pair, '"' . $field . '":""'];
        }
        yield 'unsupported version' => ['2026-08-21', '2025-01-01'];
        yield 'unknown name' => ['payment_consent.requires_customer_action', 'payment_consent.future'];
        yield 'unknown status' => ['REQUIRES_CUSTOMER_ACTION', 'FUTURE'];
        yield 'missing status' => ['"status":"REQUIRES_CUSTOMER_ACTION",', ''];
        yield 'conflicting organization owner' => ['"account_id":', '"org_id":"org_other","account_id":'];
        yield 'conflicting account alias' => ['"account_id":', '"accountId":"acct_other","account_id":'];
    }

    #[DataProvider('unselectedEnvelopes')]
    public function testUnsupportedOrConflictingEnvelopesRetainNumericText(string $search, string $replacement): void
    {
        $raw = str_replace($search, $replacement, self::raw());
        $event = WebhookEvent::fromJson($raw)->toArray();
        self::assertIsArray($event['data']);
        self::assertIsArray($event['data']['object']);
        $resource = $event['data']['object'];
        self::assertSame('related_merchant', $resource['connected_account_id']);
        self::assertSame([
            'micro_deposit_count' => '2', 'remaining_attempts' => '2', 'unknown_count' => '23',
        ], $resource['next_action']);
        self::assertSame([
            'payment_amount_type' => 'VARIABLE', 'billing_cycle_charge_day' => '2',
            'payment_schedule' => ['period' => '2', 'period_unit' => 'MONTH'], 'total_billing_cycles' => '2',
            'first_payment_amount' => '0', 'max_payment_amount' => '19.900000000000000001',
        ], $resource['terms_of_use']);
    }

    private static function raw(
        string $token = '2',
        string $relatedAccountFields = '"connected_account_id":"related_merchant",',
    ): string {
        return '{"id":"evt_synthetic","name":"payment_consent.requires_customer_action","version":"2026-08-21",'
            . '"created_at":"2026-11-10T10:00:00Z","account_id":"platform_owner","data":{"object":{'
            . '"id":"cst_synthetic","status":"REQUIRES_CUSTOMER_ACTION",' . $relatedAccountFields
            . '"next_action":{"micro_deposit_count":' . $token . ',"remaining_attempts":' . $token
            . ',"unknown_count":23},"terms_of_use":{"payment_amount_type":"VARIABLE","billing_cycle_charge_day":'
            . $token . ',"payment_schedule":{"period":' . $token . ',"period_unit":"MONTH"},"total_billing_cycles":'
            . $token . ',"first_payment_amount":0,"max_payment_amount":19.900000000000000001}}}}';
    }
}
