<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingWebhookApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingCheckoutGuardTest extends TestCase
{
    private const array EXPECTED = [
        'id' => 'SYNTHETIC_PRIVATE_CHECKOUT', 'billing_customer_id' => 'SYNTHETIC_PRIVATE_CUSTOMER',
        'legal_entity_id' => 'SYNTHETIC_PRIVATE_ENTITY', 'linked_payment_account_id' => 'SYNTHETIC_PRIVATE_ACCOUNT',
        'mode' => 'SETUP', 'ui_mode' => 'HOSTED', 'currency' => 'EUR', 'status' => 'CANCELLED',
        'metadata' => ['sdk_validation_run' => 'SYNTHETIC_PRIVATE_MARKER'],
    ];

    /** @return iterable<string,array{bool,mixed,bool}> */
    public static function terminalUrls(): iterable
    {
        yield 'omitted' => [false, null, true];
        yield 'null compatibility' => [true, null, true];
        yield 'stale URL' => [true, 'https://checkout.sandbox.airwallex.com/pay?s=SYNTHETIC_PRIVATE_STALE', false];
        yield 'empty string' => [true, '', false];
        yield 'boolean' => [true, false, false];
        yield 'number' => [true, 0, false];
        yield 'array' => [true, [], false];
        yield 'object' => [true, new \stdClass(), false];
    }

    #[DataProvider('terminalUrls')]
    public function testCancelledCheckoutRequiresAnAbsentOrNullUrl(bool $present, mixed $url, bool $accepted): void
    {
        $checkout = self::EXPECTED;
        if ($present) {
            $checkout['url'] = $url;
        }
        self::assertSame($accepted, BillingWebhookApi::checkout($checkout, self::EXPECTED));
    }

    /** @return iterable<string,array{string,bool,mixed}> */
    public static function terminalFieldMismatches(): iterable
    {
        foreach (
            ['id' => 'SYNTHETIC_PRIVATE_FOREIGN', 'billing_customer_id' => 'SYNTHETIC_PRIVATE_FOREIGN',
            'legal_entity_id' => 'SYNTHETIC_PRIVATE_FOREIGN',
            'linked_payment_account_id' => 'SYNTHETIC_PRIVATE_FOREIGN', 'mode' => 'PAYMENT',
            'ui_mode' => 'ELEMENTS', 'currency' => 'HKD', 'metadata' => ['sdk_validation_run' => 'foreign'],
            'status' => 'COMPLETED'] as $field => $wrong
        ) {
            yield 'wrong ' . $field => [$field, true, $wrong];
            yield 'missing ' . $field => [$field, false, null];
        }
        yield 'still ACTIVE' => ['status', true, 'ACTIVE'];
        yield 'unknown state' => ['status', true, 'FUTURE'];
    }

    #[DataProvider('terminalFieldMismatches')]
    public function testEveryTerminalOwnerResourceAndStateFieldIsRequired(
        string $field,
        bool $present,
        mixed $value,
    ): void {
        $checkout = self::EXPECTED;
        if ($present) {
            $checkout[$field] = $value;
        } else {
            unset($checkout[$field]);
        }
        self::assertFalse(BillingWebhookApi::checkout($checkout, self::EXPECTED));
    }

    public function testActiveCheckoutRetainsItsExistingUrlAndFieldBehavior(): void
    {
        $expected = array_replace(self::EXPECTED, ['status' => 'ACTIVE']);
        foreach ([$expected, $expected + ['url' => null], $expected + ['url' => 'SYNTHETIC_PRIVATE_URL']] as $active) {
            self::assertTrue(BillingWebhookApi::checkout($active, $expected));
            foreach (array_keys($expected) as $field) {
                $missing = $active;
                unset($missing[$field]);
                self::assertFalse(BillingWebhookApi::checkout($missing, $expected));
            }
        }
    }
}
