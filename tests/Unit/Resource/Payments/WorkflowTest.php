<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Resource\Payments;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Exception\TransportException;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

final class WorkflowTest extends HttpTestCase
{
    private const string LOGIN = '{"token":"synthetic-token","expires_at":"2030-01-01T00:00:00Z"}';

    public function testCustomerConsentAndSavedMerchantChargeKeepNativeFieldsAndExactAmounts(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [201, '{"id":"customer-A","merchant_customer_id":"local-buyer"}'],
            [201, '{"id":"consent-A","customer_id":"customer-A","status":"REQUIRES_PAYMENT_METHOD"}'],
            [200, '{"id":"consent-A","status":"VERIFIED","payment_method":{"id":"method-A","type":"card"}}'],
            [201, '{"id":"intent-A","amount":19.90,"currency":"EUR","status":"REQUIRES_PAYMENT_METHOD"}'],
            [200, '{"id":"intent-A","amount":19.90,"status":"PENDING","next_action":null}'],
            [200, '{"id":"method-A","type":"card","status":"CREATED"}'],
        ]);
        $payments = $this->client($http)->forConnectedAccount('acct_A')->payments();
        $customer = $payments->customers()->create([
            'request_id' => 'customer-action', 'merchant_customer_id' => 'local-buyer', 'email' => 'buyer@example.test',
        ]);
        $consent = $payments->paymentConsents()->create([
            'request_id' => 'consent-action', 'customer_id' => $customer['id'],
            'next_triggered_by' => 'merchant', 'merchant_trigger_reason' => 'unscheduled',
            'metadata' => ['local_id' => '00123'],
        ]);
        self::assertIsString($consent['id']);
        $verified = $payments->paymentConsents()->verify($consent['id'], [
            'request_id' => 'verify-action', 'payment_method' => ['type' => 'card', 'id' => 'method-A'],
            'verification_options' => ['card' => ['currency' => 'EUR', 'amount' => '0.01']],
        ]);
        self::assertSame('VERIFIED', $verified['status']);
        $intent = $payments->paymentIntents()->create([
            'request_id' => 'intent-action', 'merchant_order_id' => 'order-A',
            'customer_id' => $customer['id'], 'amount' => '19.90', 'currency' => 'EUR',
            'metadata' => ['decimal_looking_string' => '19.90'],
        ]);
        self::assertIsString($intent['id']);
        $observed = $payments->paymentIntents()->confirm($intent['id'], [
            'request_id' => 'charge-action', 'payment_consent_id' => $consent['id'], 'triggered_by' => 'merchant',
        ]);
        self::assertSame('PENDING', $observed['status']);
        self::assertSame('19.90', $observed['amount']);
        self::assertNull($observed['next_action']);
        self::assertSame('method-A', $payments->paymentMethods()->retrieve('method-A')['id']);
        self::assertStringContainsString('"amount":19.90', (string) $http->requests[4]->getBody());
        self::assertStringContainsString('"decimal_looking_string":"19.90"', (string) $http->requests[4]->getBody());
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('acct_A', $request->getHeaderLine('x-on-behalf-of'));
        }
    }

    public function testManualCaptureAndPartialThenFullRefundRetainExplicitIntent(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [200, '{"id":"intent-A","status":"REQUIRES_CAPTURE"}'],
            [200, '{"id":"intent-A","status":"SUCCEEDED","captured_amount":19.90}'],
            [201, '{"id":"refund-part","amount":5.00,"status":"RECEIVED"}'],
            [201, '{"id":"refund-full","amount":14.90,"status":"ACCEPTED"}'],
            [200, '{"id":"refund-full","amount":14.90,"status":"SETTLED"}'],
        ]);
        $payments = $this->client($http)->payments();
        $authorization = $payments->paymentIntents()->confirm('intent-A', [
            'request_id' => 'authorize-action', 'payment_consent_id' => 'consent-A', 'triggered_by' => 'customer',
            'payment_method_options' => ['card' => ['auto_capture' => false, 'authorization_type' => 'pre_auth']],
        ]);
        self::assertSame('REQUIRES_CAPTURE', $authorization['status']);
        self::assertStringContainsString('"auto_capture":false', (string) $http->requests[1]->getBody());
        $captured = $payments->paymentIntents()->capture(
            'intent-A',
            ['request_id' => 'capture-action',
            'amount' => '19.90']
        );
        self::assertSame('19.90', $captured['captured_amount']);
        $part = $payments->refunds()->create([
            'request_id' => 'partial-refund-action', 'payment_intent_id' => 'intent-A',
            'amount' => '5.00', 'reason' => 'requested_by_customer',
        ]);
        self::assertSame('RECEIVED', $part['status']);
        $full = $payments->refunds()->create(['request_id' => 'remaining-refund-action',
             'payment_intent_id' => 'intent-A']);
        self::assertSame('ACCEPTED', $full['status']);
        self::assertStringNotContainsString('"amount"', (string) $http->requests[4]->getBody());
        self::assertIsString($full['id']);
        self::assertSame('SETTLED', $payments->refunds()->retrieve($full['id'])['status']);
    }

    /** @return iterable<string, array{string}> */
    public static function consentStates(): iterable
    {
        foreach (
            ['REQUIRES_PAYMENT_METHOD',
             'REQUIRES_CUSTOMER_ACTION',
             'PENDING',
             'PAUSED',
             'VERIFIED',
             'DISABLED',
             'FUTURE'] as $state
        ) {
            yield $state => [$state];
        }
    }

    #[DataProvider('consentStates')]
    public function testConsentStatesAndUnknownNextActionsRemainAvailableForConsumerInspection(string $state): void
    {
        $raw = '{"status":"' . $state . '","next_action":{"type":"future_action",'
            . '"remaining_attempts":2,"unknown_count":4}}';
        $http = $this->scenario([[201, self::LOGIN], [200, $raw]]);
        $observed = $this->client($http)->payments()->paymentConsents()->retrieve('consent-A');
        self::assertSame($state, $observed['status']);
        self::assertSame(
            ['type' => 'future_action',
             'remaining_attempts' => 2,
             'unknown_count' => '4'],
            $observed['next_action']
        );
    }

    public function testMicroDepositVerificationAndRetryMicroDebitKeepExactFinancialBytes(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [200,
                 '{"status":"REQUIRES_CUSTOMER_ACTION","next_action":{'
                    . '"type":"notify_micro_deposits","micro_deposit_count":2}}'],

            [200, '{"status":"VERIFIED"}'],
            [200, '{"status":"VERIFIED"}'],
        ]);
        $consents = $this->client($http)->payments()->paymentConsents();
        $pending = $consents->verify('consent-A', [
            'request_id' => 'ach-verify',
            'payment_method' => ['type' => 'ach_direct_debit', 'id' => 'existing-ach-method'],
        ]);
        self::assertIsArray($pending['next_action']);
        self::assertSame(2, $pending['next_action']['micro_deposit_count']);
        $verified = $consents->continueVerification('consent-A', [
            'request_id' => 'ach-continue', 'type' => 'verify_micro_deposits',
            'micro_deposit' => ['amounts' => ['0.12', '0.34']],
        ]);
        self::assertSame('VERIFIED', $verified['status']);
        self::assertStringContainsString('"amounts":[0.12,0.34]', (string) $http->requests[2]->getBody());
        $consents->continueVerification(
            'consent-A',
            ['request_id' => 'ach-retry-debit',
            'type' => 'retry_micro_debit']
        );
        self::assertStringNotContainsString('micro_deposit', (string) $http->requests[3]->getBody());
    }

    public function testDelegationSelectorsCannotBeCombinedButDirectPlatformFieldsRemainExplicit(): void
    {
        $blocked = $this->scenario([]);
        try {
            $this->client($blocked)->forConnectedAccount('acct_A')->payments()->paymentConsents()->create([
                'request_id' => 'identity', 'customer_id' => 'customer-A',
                'next_triggered_by' => 'merchant', 'connected_account_id' => 'acct_B',
            ]);
            self::fail('Conflicting delegation selectors were accepted.');
        } catch (ConfigurationException) {
            self::assertSame([], $blocked->requests);
        }
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"consent-A"}']]);
        $this->client($http)->payments()->paymentConsents()->create([
            'request_id' => 'identity', 'customer_id' => 'customer-A',
            'next_triggered_by' => 'merchant', 'connected_account_id' => 'acct_A',
        ]);
        self::assertSame('', $http->requests[1]->getHeaderLine('x-on-behalf-of'));
        self::assertStringContainsString('"connected_account_id":"acct_A"', (string) $http->requests[1]->getBody());
    }

    public function testDelegatedListFilterFailsWhileDirectPlatformFilterRemainsProviderShaped(): void
    {
        $http = $this->scenario([]);
        try {
            $this->client($http)->forConnectedAccount('acct_A')->payments()->paymentIntents()->list([
                'connected_account_id' => 'acct_A', 'page_num' => 0,
            ]);
            self::fail('Delegated list accepted a second selector.');
        } catch (ConfigurationException) {
            self::assertSame([], $http->requests);
        }
        $http = $this->scenario([[201, self::LOGIN], [200, '{"items":[],"has_more":false}']]);
        self::assertSame(
            ['items' => [],
             'has_more' => false],
            $this->client($http)->payments()->paymentIntents()->list([
            'connected_account_id' => 'acct_A', 'page_num' => 0,
            ])
        );
        self::assertStringEndsWith('?connected_account_id=acct_A&page_num=0', (string) $http->requests[1]->getUri());
        self::assertSame('', $http->requests[1]->getHeaderLine('x-on-behalf-of'));
    }

    public function testRemainingRefundCanReferenceAnAttemptAndPreservesNoAmountInput(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"refund","amount":10.00,"status":"RECEIVED"}']]);
        $result = $this->client($http)->payments()->refunds()->create([
            'request_id' => 'attempt-refund', 'payment_attempt_id' => 'attempt-A',
        ]);
        self::assertSame('10.00', $result['amount']);
        self::assertStringContainsString('"payment_attempt_id":"attempt-A"', (string) $http->requests[1]->getBody());
        self::assertStringNotContainsString('"amount"', (string) $http->requests[1]->getBody());
    }

    public function testWrongAccountPermissionFailureAndAmbiguousMutationNeverReplay(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [403, '{"code":"forbidden","message":"private buyer payload"}']]);
        try {
            $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3))->forConnectedAccount('acct_B')
                ->payments()->paymentIntents()->capture(
                    'intent-owned-by-A',
                    ['request_id' => 'stable-capture',
                    'amount' => '10.00']
                );
            self::fail('Expected account permission failure.');
        } catch (ApiException $error) {
            self::assertSame(403, $error->httpStatus());
            self::assertCount(2, $http->requests);
            self::assertStringNotContainsString('private buyer', serialize($error));
        }
        $ambiguous = new class ('private transport data') extends RuntimeException implements ClientExceptionInterface {
        };
        $http = $this->scenario([[201, self::LOGIN], $ambiguous]);
        try {
            $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3))->payments()->refunds()->create([
                'request_id' => 'stable-refund', 'payment_intent_id' => 'intent-A', 'amount' => '5.00',
            ]);
            self::fail('Expected ambiguous refund outcome.');
        } catch (TransportException $error) {
            self::assertCount(2, $http->requests);
            self::assertStringContainsString('"request_id":"stable-refund"', (string) $http->requests[1]->getBody());
            self::assertStringNotContainsString('private transport data', serialize($error));
        }
    }
}
