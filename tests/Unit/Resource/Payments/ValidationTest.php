<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Resource\Payments;

use Closure;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\Resource\Payments;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

final class ValidationTest extends HttpTestCase
{
    /** @return iterable<string, array{Closure(Payments): array<array-key, mixed>}> */
    public static function invalidRequests(): iterable
    {
        yield 'missing-customer-local-id' => [
            static fn (Payments $p): array => $p->customers()->create(['request_id' => 'identity']),
        ];
        yield 'missing-consent-customer' => [static fn (Payments $p): array => $p->paymentConsents()->create([
            'request_id' => 'identity', 'next_triggered_by' => 'merchant',
        ])];
        yield 'unknown-consent-update-field' => [static fn (Payments $p): array => $p->paymentConsents()->update(
            'consent',
            [
            'request_id' => 'identity', 'next_triggered_by' => 'merchant',
            ]
        )];
        yield 'missing-mutation-identity' => [static fn (Payments $p): array => $p->paymentIntents()->capture(
            'intent',
            ['amount' => '10.00']
        )];
        yield 'empty-identity' => [static fn (Payments $p): array => $p->paymentConsents()->disable(
            'consent',
            ['request_id' => '']
        )];
        yield 'oversized-identity' => [static fn (Payments $p): array => $p->refunds()->create([
            'request_id' => str_repeat('x', 65), 'payment_intent_id' => 'intent',
        ])];
        yield 'float-amount' => [static fn (Payments $p): array => $p->paymentIntents()->create([
            'request_id' => 'identity', 'amount' => 19.90, 'currency' => 'EUR', 'merchant_order_id' => 'order',
        ])];
        yield 'integer-amount' => [static fn (Payments $p): array => $p->refunds()->create([
            'request_id' => 'identity', 'payment_intent_id' => 'intent', 'amount' => 5,
        ])];
        yield 'null-amount' => [static fn (Payments $p): array => $p->paymentIntents()->capture('intent', [
            'request_id' => 'identity', 'amount' => null,
        ])];
        yield 'float-microdeposit' => [static fn (Payments $p): array => $p->paymentConsents()->continueVerification(
            'consent',
            [
            'request_id' => 'identity', 'type' => 'verify_micro_deposits', 'micro_deposit' => ['amounts' => [0.12]],
            ]
        )];
        yield 'missing-microdeposit' => [static fn (Payments $p): array => $p->paymentConsents()->continueVerification(
            'consent',
            [
            'request_id' => 'identity', 'type' => 'verify_micro_deposits',
            ]
        )];
        yield 'missing-pin-payload' => [static fn (Payments $p): array => $p->paymentIntents()->continueConfirmation(
            'intent',
            [
            'request_id' => 'identity', 'type' => 'submit_card_pin_data',
            ]
        )];
        yield 'unknown-continuation' => [static fn (Payments $p): array => $p->paymentConsents()->continueVerification(
            'consent',
            [
            'request_id' => 'identity', 'type' => 'new-continuation',
            ]
        )];
        yield 'missing-refund-target' => [
            static fn (Payments $p): array => $p->refunds()->create(['request_id' => 'identity']),
        ];
        yield 'unknown-field' => [static fn (Payments $p): array => $p->paymentIntents()->create([
            'request_id' => 'identity', 'amount' => '19.90', 'currency' => 'EUR', 'merchant_order_id' => 'order',
            'unreviewed_decimal' => '1.20',
        ])];
        yield 'saved-method-without-trigger' => [static fn (Payments $p): array => $p->paymentIntents()->confirm(
            'intent',
            [
            'request_id' => 'identity', 'payment_method' => ['id' => 'method', 'type' => 'card'],
            ]
        )];
        yield 'preauth-with-auto-capture' => [static fn (Payments $p): array => $p->paymentIntents()->confirm(
            'intent',
            [
            'request_id' => 'identity', 'payment_consent_id' => 'consent',
            'payment_method_options' => ['card' => ['authorization_type' => 'pre_auth', 'auto_capture' => true]],
            ]
        )];
        yield 'fixed-agreement-missing-amount' => [static fn (Payments $p): array => $p->paymentConsents()->create([
            'request_id' => 'identity', 'customer_id' => 'customer', 'next_triggered_by' => 'merchant',
            'terms_of_use' => ['payment_amount_type' => 'FIXED'],
        ])];
        yield 'zero-verification-override' => [static fn (Payments $p): array => $p->paymentConsents()->verify(
            'consent',
            [
            'request_id' => 'identity', 'payment_method' => ['type' => 'card', 'id' => 'method'],
            'verification_options' => ['card' => ['currency' => 'USD', 'amount' => '0.00']],
            ]
        )];
        yield 'unsupported-funds-split' => [static fn (Payments $p): array => $p->paymentIntents()->capture('intent', [
            'request_id' => 'identity', 'funds_split_data' => [],
        ])];
        yield 'unsupported-wechat-funds-split' => [
            static fn (Payments $p): array => $p->paymentIntents()->confirm('intent', [
            'request_id' => 'identity',
                'payment_method_options' => ['wechatpay' => ['enable_funds_split' => true]],
            ]),
        ];
        yield 'populated-structured-stdclass' => [static fn (Payments $p): array => $p->paymentConsents()->create([
            'request_id' => 'identity', 'customer_id' => 'customer', 'next_triggered_by' => 'merchant',
            'terms_of_use' => (object) ['payment_amount_type' => 'FIXED'],
        ])];
        yield 'afterpay-missing-method-branch' => [static fn (Payments $p): array => $p->paymentIntents()->confirm(
            'intent',
            [
            'request_id' => 'identity', 'payment_method' => ['type' => 'afterpay'],
            ]
        )];
        yield 'wechat-mini-missing-owner-id' => [static fn (Payments $p): array => $p->paymentIntents()->confirm(
            'intent',
            [
            'request_id' => 'identity',
                 'payment_method' => ['type' => 'wechatpay',
                 'wechatpay' => ['flow' => 'mini_program']],

            ]
        )];
        yield 'ach-missing-microdeposit-inputs' => [static fn (Payments $p): array => $p->paymentConsents()->verify(
            'consent',
            [
            'request_id' => 'identity',
            'payment_method' => ['type' => 'ach_direct_debit',
                 'ach_direct_debit' => ['verification_method' => 'micro_deposit']],

            ]
        )];
        yield 'scheduled-unknown-period-unit' => [static fn (Payments $p): array => $p->paymentConsents()->create([
            'request_id' => 'identity', 'customer_id' => 'customer', 'next_triggered_by' => 'merchant',
            'merchant_trigger_reason' => 'scheduled', 'terms_of_use' => [
                'payment_amount_type' => 'VARIABLE', 'payment_schedule' => ['period' => 1, 'period_unit' => 'HOUR'],
            ],
        ])];
        yield 'invalid-created-time' => [
            static fn (Payments $p): array => $p->customers()->list(['from_created_at' => 'tomorrow']),
        ];
        yield 'invalid-created-timezone' => [static fn (Payments $p): array => $p->customers()->list([
            'to_created_at' => '2030-01-01T00:00:00+24:00',
        ])];
        yield 'customer-card-missing-cvc' => [
            static fn (Payments $p): array => $p->paymentIntents()->confirm('intent', [
            'request_id' => 'identity', 'triggered_by' => 'customer', 'payment_method' => ['type' => 'card', 'card' => [
                'number' => 'synthetic-card-number', 'expiry_month' => '12', 'expiry_year' => '2030',
            ]],
            ]),
        ];
        yield 'consent-verification-missing-cvc' => [
            static fn (Payments $p): array => $p->paymentConsents()->verify('consent', [
            'request_id' => 'identity', 'payment_method' => ['type' => 'card', 'card' => [
                'number' => 'synthetic-card-number', 'expiry_month' => '12', 'expiry_year' => '2030',
            ]],
            ]),
        ];
        yield 'magstripe-missing-track-data' => [
            static fn (Payments $p): array => $p->paymentIntents()->confirm('intent', [
            'request_id' => 'identity', 'payment_method' => ['type' => 'card_present', 'card_present' => [
                'cardholder_verification_method' => 'no_cvm', 'pan_entry_mode' => 'magstripe',
                'terminal_info' => [
                    'pin_entry_capability' => 'unsupported', 'supported_pan_entry_modes' => ['magstripe'],
                    'terminal_identifier' => 'TEST0001',
                ],
            ]],
            ]),
        ];
        yield 'customer-empty-card-object-missing-cvc' => [
            static fn (Payments $p): array => $p->paymentIntents()->confirm('intent', [
                'request_id' => 'identity', 'triggered_by' => 'customer',
                'payment_method' => ['type' => 'card', 'card' => new stdClass()],
            ]),
        ];
        yield 'unknown-wallet-field' => [static fn (Payments $p): array => $p->paymentIntents()->confirm('intent', [
            'request_id' => 'identity',
                 'payment_method' => ['type' => 'applepay',
                 'applepay' => ['unreviewed' => 'value']],

        ])];
        yield 'empty-resource-id' => [static fn (Payments $p): array => $p->paymentMethods()->retrieve('')];
    }

    /** @param Closure(Payments): array<array-key, mixed> $operation */
    #[DataProvider('invalidRequests')]
    public function testInvalidContractsFailBeforeAuthenticationWithoutEchoingInput(Closure $operation): void
    {
        $http = $this->scenario([]);
        try {
            $operation($this->client($http)->payments());
            self::fail('Expected local contract validation failure.');
        } catch (ValidationException $error) {
            self::assertSame([], $http->requests);
            self::assertStringNotContainsString('unreviewed', serialize($error));
        }
    }

    public function testExplicitMandateNullAndEmptyObjectShapeArePreserved(): void
    {
        $http = $this->scenario([
            [201, '{"token":"synthetic-token","expires_at":"2030-01-01T00:00:00Z"}'],
            [201, '{"id":"consent","terms_of_use":{"total_billing_cycles":null},"metadata":{}}'],
        ]);
        $result = $this->client($http)->payments()->paymentConsents()->create([
            'request_id' => 'identity', 'customer_id' => 'customer', 'next_triggered_by' => 'merchant',
            'terms_of_use' => ['payment_amount_type' => 'VARIABLE', 'total_billing_cycles' => null],
            'metadata' => new stdClass(),
        ]);
        self::assertIsArray($result['terms_of_use']);
        self::assertNull($result['terms_of_use']['total_billing_cycles']);
        self::assertInstanceOf(stdClass::class, $result['metadata']);
        self::assertStringContainsString('"total_billing_cycles":null', (string) $http->requests[1]->getBody());
        self::assertStringContainsString('"metadata":{}', (string) $http->requests[1]->getBody());
    }
}
