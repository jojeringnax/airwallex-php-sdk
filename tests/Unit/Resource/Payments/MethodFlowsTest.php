<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Resource\Payments;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MethodFlowsTest extends HttpTestCase
{
    /** @return iterable<string, array{string, array<string, mixed>, string, string}> */
    public static function methodFlows(): iterable
    {
        yield 'alipay-qrcode' => ['alipaycn',
             ['alipaycn' => ['flow' => 'qrcode']],
             'REQUIRES_CUSTOMER_ACTION',
             'redirect'];
        yield 'wechat-mini-program' => ['wechatpay',
             ['wechatpay' => ['flow' => 'mini_program',
             'open_id' => 'synthetic-open-id']],
             'REQUIRES_CUSTOMER_ACTION',
             'redirect'];
        yield 'apple-encrypted-token' => ['applepay', ['applepay' => [
            'payment_data_type' => 'encrypted_payment_token', 'encrypted_payment_token' => 'synthetic-encrypted-token',
        ]], 'REQUIRES_CUSTOMER_ACTION', 'redirect_iframe'];
        yield 'google-encrypted-token' => ['googlepay', ['googlepay' => [
            'payment_data_type' => 'encrypted_payment_token', 'encrypted_payment_token' => 'synthetic-encrypted-token',
        ]], 'PENDING', 'future_provider_action'];
        yield 'bank-redirect' => ['ideal', ['ideal' => new \stdClass()], 'REQUIRES_CUSTOMER_ACTION', 'redirect'];
        yield 'bacs-mandate' => ['bacs_direct_debit', ['id' => 'synthetic-method'], 'PENDING', 'notify_micro_deposits'];
        yield 'payto-mandate' => ['payto', ['id' => 'synthetic-method'], 'PENDING', 'redirect'];
        yield 'afterpay-authorized' => ['afterpay',
             ['afterpay' => ['shopper_email' => 'buyer@example.test']],
             'REQUIRES_CAPTURE',
             'capture'];
    }

    /** @param array<string, mixed> $details */
    #[DataProvider('methodFlows')]
    public function testMethodSpecificPayloadsAndNextActionsStayProviderShaped(
        string $type,
        array $details,
        string $state,
        string $nextAction,
    ): void {
        $http = $this->scenario([
            [201, '{"token":"synthetic-token","expires_at":"2030-01-01T00:00:00Z"}'],
            [200,
                 '{"id":"intent-A","status":"' . $state . '","next_action":{"type":"'
                    . $nextAction . '","url":"https://example.test/action"}}'],

        ]);
        $result = $this->client($http)->payments()->paymentIntents()->confirm('intent-A', [
            'request_id' => 'method-specific-confirm', 'triggered_by' => 'customer',
            'payment_method' => ['type' => $type] + $details,
        ]);
        self::assertSame($state, $result['status']);
        self::assertSame(['type' => $nextAction, 'url' => 'https://example.test/action'], $result['next_action']);
        self::assertCount(2, $http->requests);
        self::assertStringContainsString('"type":"' . $type . '"', (string) $http->requests[1]->getBody());
    }

    public function testAchVerificationBranchesAndStoredMethodReuseRemainValid(): void
    {
        $http = $this->scenario([
            [201, '{"token":"synthetic-token","expires_at":"2030-01-01T00:00:00Z"}'],
            [200, '{"status":"REQUIRES_CUSTOMER_ACTION","next_action":{"type":"redirect"}}'],
            [200, '{"status":"REQUIRES_CUSTOMER_ACTION","next_action":{"type":"notify_micro_deposits"}}'],
            [200, '{"status":"VERIFIED"}'],
        ]);
        $consents = $this->client($http)->payments()->paymentConsents();
        $consents->verify('consent-A', [
            'request_id' => 'plaid-action',
            'payment_method' => ['type' => 'ach_direct_debit',
                 'ach_direct_debit' => ['verification_method' => 'plaid']],

        ]);
        $consents->verify('consent-B', [
            'request_id' => 'micro-action',
            'payment_method' => ['type' => 'ach_direct_debit', 'ach_direct_debit' => [
                'verification_method' => 'micro_deposit', 'aba_routing_number' => '000000000',
                'account_number' => '000000000',
                     'owner_name' => 'Synthetic buyer',
                     'owner_email' => 'buyer@example.test',

            ]],
        ]);
        $stored = $consents->verify('consent-C', [
            'request_id' => 'stored-action',
                 'payment_method' => ['type' => 'ach_direct_debit',
                 'id' => 'existing-method'],

        ]);
        self::assertSame('VERIFIED', $stored['status']);
        self::assertStringNotContainsString('aba_routing_number', (string) $http->requests[1]->getBody());
        self::assertStringContainsString('aba_routing_number', (string) $http->requests[2]->getBody());
        self::assertStringNotContainsString('ach_direct_debit":{', (string) $http->requests[3]->getBody());
        self::assertCount(4, $http->requests);
    }

    public function testExplicitMerchantInitiatedCardConfirmationDoesNotRequireCvc(): void
    {
        $http = $this->scenario([
            [201, '{"token":"synthetic-token","expires_at":"2030-01-01T00:00:00Z"}'],
            [200, '{"status":"PENDING"}'],
        ]);
        $result = $this->client($http)->payments()->paymentIntents()->confirm('intent-A', [
            'request_id' => 'merchant-confirm', 'triggered_by' => 'merchant',
            'payment_method' => ['type' => 'card', 'card' => [
                'number' => 'synthetic-card-number', 'expiry_month' => '12', 'expiry_year' => '2030',
            ]],
        ]);
        self::assertSame('PENDING', $result['status']);
        self::assertStringNotContainsString('"cvc"', (string) $http->requests[1]->getBody());
    }

    public function testManualTerminalEntryDoesNotRequireMagneticTrackData(): void
    {
        $http = $this->scenario([
            [201, '{"token":"synthetic-token","expires_at":"2030-01-01T00:00:00Z"}'],
            [200, '{"status":"REQUIRES_CUSTOMER_ACTION","next_action":{"type":"request_pin"}}'],
        ]);
        $this->client($http)->payments()->paymentIntents()->confirm('intent-A', [
            'request_id' => 'manual-entry', 'payment_method' => ['type' => 'card_present', 'card_present' => [
                'cardholder_verification_method' => 'no_cvm', 'pan_entry_mode' => 'manual_entry',
                'number' => 'synthetic-card-number', 'expiry_month' => '12', 'expiry_year' => '2030',
                'terminal_info' => [
                    'pin_entry_capability' => 'unsupported', 'supported_pan_entry_modes' => ['manual_entry'],
                    'terminal_identifier' => 'TEST0001',
                ],
            ]],
        ]);
        self::assertCount(2, $http->requests);
        self::assertStringNotContainsString('"track2"', (string) $http->requests[1]->getBody());
    }

    public function testDateFiltersPreserveValidFractionalPrecisionAndTimezone(): void
    {
        $http = $this->scenario([
            [201, '{"token":"synthetic-token","expires_at":"2030-01-01T00:00:00Z"}'],
            [200, '{"items":[],"has_more":false}'],
        ]);
        $this->client($http)->payments()->customers()->list([
            'from_created_at' => '2026-11-08T11:22:33.123456789+01:00',
            'to_created_at' => '2026-11-09T00:00:00Z',
        ]);
        self::assertStringContainsString('11%3A22%3A33.123456789%2B01%3A00', (string) $http->requests[1]->getUri());
        self::assertCount(2, $http->requests);
    }

    public function testInvalidStateExcessRefundAndMethodDenialAreProviderFailuresWithoutExtraReads(): void
    {
        foreach (['invalid_status_for_operation', 'amount_above_limit', 'operation_not_supported'] as $code) {
            $http = $this->scenario([
                [201, '{"token":"synthetic-token","expires_at":"2030-01-01T00:00:00Z"}'],
                [400, '{"code":"' . $code . '"}'],
            ]);
            try {
                $this->client($http)->payments()->refunds()->create([
                    'request_id' => 'consumer-persisted-refund',
                         'payment_intent_id' => 'intent-A',
                         'amount' => '999999.99',

                ]);
                self::fail('Expected provider rejection.');
            } catch (ApiException $error) {
                self::assertSame(400, $error->httpStatus());
                self::assertSame($code, $error->providerCode());
                self::assertCount(2, $http->requests);
            }
        }
    }
}
