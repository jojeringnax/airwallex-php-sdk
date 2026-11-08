<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Examples;

use Closure;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Exception\WebhookVerificationException;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

final class ConsumerRecipesTest extends HttpTestCase
{
    private const string LOGIN = '{"token":"fixture-token","expires_at":"2030-01-01T00:00:00Z"}';

    private function recipe(string $file, ?string $name = null): Closure
    {
        $value = require dirname(__DIR__, 2) . '/examples/' . $file;
        if ($name !== null) {
            self::assertIsArray($value);
            $value = $value[$name];
        }
        self::assertInstanceOf(Closure::class, $value);
        return $value;
    }

    public function testConcreteInitializationConstructsBothExplicitEnvironmentsWithoutCallingProvider(): void
    {
        $initialize = $this->recipe('initialize-client.php');
        $credentials = new Credentials('synthetic-client-id', 'synthetic-api-key');
        self::assertInstanceOf(Client::class, $initialize(Environment::Sandbox, $credentials));
        self::assertInstanceOf(Client::class, $initialize(Environment::Production, $credentials, 'synthetic-account'));
    }

    public function testTrialRecipeRetainsExplicitOwnerSourceAndFractionalTimestamps(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"sub","status":"PENDING"}']]);
        $recipe = $this->recipe('billing-lifecycle.php', 'start_trial');
        $result = $recipe(
            $this->client($http),
            'buyer',
            'source',
            'entity',
            'collector',
            'price',
            'persisted-create',
            '2028-01-01T00:00:00.123456789+01:00',
            '2028-02-01T00:00:00.000000001+01:00'
        );
        self::assertIsArray($result);
        self::assertSame('PENDING', $result['status']);
        $wire = (string) $http->requests[1]->getBody();
        self::assertStringContainsString('"linked_payment_account_id":"collector"', $wire);
        self::assertStringContainsString('"payment_source_id":"source"', $wire);
        self::assertStringContainsString('2028-01-01T00:00:00.123456789+01:00', $wire);
        self::assertSame('', $http->requests[1]->getHeaderLine('x-on-behalf-of'));
    }

    public function testQuantityAndReplacementExamplesRetainItemIdentityAndExplicitFinancialControls(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{}'], [200, '{}']]);
        $client = $this->client($http);
        $change = $this->recipe('billing-lifecycle.php', 'change_quantity');
        $change($client, 'sub', 'old-item', 3, 'persisted-quantity');
        $replace = $this->recipe('billing-lifecycle.php', 'replace_price');
        $replace($client, 'sub', 'old-item', 'new-price', 2, 'persisted-plan');
        self::assertStringContainsString(
            '"items":[{"id":"old-item","quantity":3}]',
            (string) $http->requests[1]->getBody()
        );
        $wire = (string) $http->requests[2]->getBody();
        self::assertStringContainsString('"id":"old-item","deleted":true', $wire);
        self::assertStringContainsString('"price_id":"new-price","quantity":2', $wire);
        self::assertStringContainsString('"default_proration_mode":"NONE"', $wire);
        self::assertStringContainsString('"billing_action":"DEFER_CHARGE_AND_KEEP_CYCLE"', $wire);
    }

    #[DataProvider('cancellationRecipes')]
    public function testCancellationRecipesAreSeparateActions(string $name, string $state, string $fragment): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{"status":"' . $state . '"}']]);
        $result = ($this->recipe('billing-lifecycle.php', $name))($this->client($http), 'sub', 'persisted-ending');
        self::assertIsArray($result);
        self::assertSame($state, $result['status']);
        self::assertStringContainsString($fragment, (string) $http->requests[1]->getBody());
        self::assertCount(2, $http->requests);
    }

    /** @return iterable<string, array{string,string,string}> */
    public static function cancellationRecipes(): iterable
    {
        yield 'scheduled' => ['schedule_end', 'ACTIVE', '"cancel_at_period_end":true'];
        yield 'immediate' => ['cancel_now', 'CANCELLED', '"proration_behavior":"NONE"'];
    }

    public function testRecoveryMakesOneExplicitSourceChangeThenObservesSubscriptionAndInvoice(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN], [200, '{"status":"UNPAID"}'],
            [200, '{"id":"sub","status":"ACTIVE","latest_invoice_id":"invoice"}'],
            [200, '{"id":"invoice","payment_status":"PAID","amount_due":0,"pdf_url":null}'],
        ]);
        $result = ($this->recipe('billing-lifecycle.php', 'replace_source_and_observe'))(
            $this->client($http),
            'sub',
            'new-source',
            'persisted-recovery'
        );
        self::assertIsArray($result);
        self::assertIsArray($result['invoice']);
        self::assertSame('0', $result['invoice']['amount_due']);
        self::assertNull($result['invoice']['pdf_url']);
        self::assertSame('POST', $http->requests[1]->getMethod());
        self::assertSame('GET', $http->requests[2]->getMethod());
        self::assertSame('GET', $http->requests[3]->getMethod());
        self::assertStringContainsString(
            '"request_id":"persisted-recovery"',
            (string) $http->requests[1]->getBody()
        );
        self::assertArrayNotHasKey('access_granted', $result);
    }

    public function testInvoiceRecipePreservesUnavailableAndExpiredLinksWithoutRenewalRequests(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [200, '{"id":"draft","pdf_url":null,"hosted_url":null}'],
            [200, '{"id":"final","pdf_url":"https://example.test/expired","hosted_url":"https://example.test/view"}'],
        ]);
        $client = $this->client($http);
        $read = $this->recipe('billing-lifecycle.php', 'read_invoice');
        $draft = $read($client, 'draft');
        $final = $read($client, 'final');
        self::assertIsArray($draft);
        self::assertIsArray($final);
        self::assertNull($draft['pdf_url']);
        self::assertSame('https://example.test/expired', $final['pdf_url']);
        self::assertCount(3, $http->requests);
    }

    public function testPaymentsPreparationAndVerificationRemainSeparateFromACharge(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN], [201, '{"id":"customer"}'],
            [201, '{"id":"consent","status":"REQUIRES_PAYMENT_METHOD"}'],
            [200, '{"id":"consent","status":"REQUIRES_CUSTOMER_ACTION","next_action":{"type":"redirect"}}'],
        ]);
        $client = $this->client($http)->forConnectedAccount('account-A');
        $prepare = $this->recipe('payments-lifecycle.php', 'prepare_customer_consent');
        $prepared = $prepare($client, 'local-buyer', 'persisted-customer', 'persisted-consent');
        self::assertIsArray($prepared);
        self::assertIsArray($prepared['consent']);
        self::assertSame('REQUIRES_PAYMENT_METHOD', $prepared['consent']['status']);
        $verify = $this->recipe('payments-lifecycle.php', 'verify_card_consent');
        $verified = $verify($client, 'consent', 'method', 'persisted-verification');
        self::assertIsArray($verified);
        self::assertSame('REQUIRES_CUSTOMER_ACTION', $verified['status']);
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('account-A', $request->getHeaderLine('x-on-behalf-of'));
            self::assertStringNotContainsString('/payment_intents', (string) $request->getUri());
        }
        self::assertStringContainsString('"next_triggered_by":"merchant"', (string) $http->requests[2]->getBody());
    }

    public function testOneTimePaymentKeepsCustomerIntentAndReturnsPendingBrowserAction(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN], [201, '{"id":"intent","amount":19.90}'],
            [200, '{"id":"intent","status":"REQUIRES_CUSTOMER_ACTION","next_action":{"type":"redirect"}}'],
        ]);
        $result = ($this->recipe('payments-lifecycle.php', 'one_time_card_payment'))(
            $this->client($http),
            'customer',
            'method',
            'order',
            'persisted-intent',
            'persisted-confirm'
        );
        self::assertIsArray($result);
        self::assertSame('REQUIRES_CUSTOMER_ACTION', $result['status']);
        self::assertStringContainsString('"amount":19.90', (string) $http->requests[1]->getBody());
        self::assertStringContainsString('"triggered_by":"customer"', (string) $http->requests[2]->getBody());
        self::assertStringContainsString('"auto_capture":true', (string) $http->requests[2]->getBody());
        self::assertCount(3, $http->requests);
    }

    #[DataProvider('invalidIdentityRecipes')]
    public function testInvalidReturnedIdentityStopsFinancialContinuation(string $name, string $response): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, $response]]);
        $recipe = $this->recipe('payments-lifecycle.php', $name);
        $this->expectException(RuntimeException::class);
        try {
            if ($name === 'prepare_customer_consent') {
                $recipe($this->client($http), 'local-buyer', 'customer-action', 'consent-action');
            } else {
                $recipe($this->client($http), 'customer', 'method', 'order', 'create-action', 'confirm-action');
            }
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    /** @return iterable<string, array{string,string}> */
    public static function invalidIdentityRecipes(): iterable
    {
        foreach (['prepare_customer_consent', 'one_time_card_payment'] as $name) {
            foreach (['{}', '{"id":""}'] as $index => $response) {
                yield $name . '-' . $index => [$name, $response];
            }
        }
    }

    public function testAuthorizationCaptureAndCancellationAreSeparateChoices(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN], [200, '{"status":"REQUIRES_CAPTURE"}'],
            [200, '{"status":"SUCCEEDED","captured_amount":19.90}'], [200, '{"status":"CANCELLED"}'],
        ]);
        $client = $this->client($http);
        $authorize = $this->recipe('payments-lifecycle.php', 'authorize_card');
        $authorized = $authorize($client, 'intent-A', 'consent', 'auth');
        $captured = ($this->recipe('payments-lifecycle.php', 'capture'))($client, 'intent-A', 'capture', '19.90');
        $cancelled = ($this->recipe('payments-lifecycle.php', 'cancel'))($client, 'other-intent', 'cancel');
        self::assertIsArray($authorized);
        self::assertIsArray($captured);
        self::assertIsArray($cancelled);
        self::assertSame('REQUIRES_CAPTURE', $authorized['status']);
        self::assertSame('19.90', $captured['captured_amount']);
        self::assertSame('CANCELLED', $cancelled['status']);
        self::assertStringContainsString('"auto_capture":false', (string) $http->requests[1]->getBody());
        self::assertStringContainsString('"authorization_type":"pre_auth"', (string) $http->requests[1]->getBody());
        self::assertStringEndsWith('/other-intent/cancel', (string) $http->requests[3]->getUri());
    }

    public function testPartialAndRemainingRefundRecipesPreserveAmountsAndObservedStatus(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN], [201, '{"id":"refund-A","status":"RECEIVED","amount":5.00}'],
            [201, '{"id":"refund-B","status":"ACCEPTED","amount":14.90}'],
            [200, '{"id":"refund-B","status":"SETTLED","amount":14.90}'],
            [200, '{"id":"intent","status":"SUCCEEDED"}'],
        ]);
        $client = $this->client($http);
        $refund = $this->recipe('payments-lifecycle.php', 'refund');
        $partial = $refund($client, 'intent', 'persisted-partial', '5.00');
        $remaining = $refund($client, 'intent', 'persisted-remaining');
        self::assertIsArray($partial);
        self::assertIsArray($remaining);
        self::assertSame('RECEIVED', $partial['status']);
        self::assertSame('ACCEPTED', $remaining['status']);
        self::assertStringContainsString('"amount":5.00', (string) $http->requests[1]->getBody());
        self::assertStringNotContainsString('"amount"', (string) $http->requests[2]->getBody());
        $observedRefund = ($this->recipe('payments-lifecycle.php', 'read_refund'))($client, 'refund-B');
        $observedPayment = ($this->recipe('payments-lifecycle.php', 'read_payment'))($client, 'intent');
        self::assertIsArray($observedRefund);
        self::assertIsArray($observedPayment);
        self::assertSame('SETTLED', $observedRefund['status']);
        self::assertSame('SUCCEEDED', $observedPayment['status']);
    }

    public function testWebhookRecipeVerifiesBeforeLosslessDecodingAndPreservesUnknownValues(): void
    {
        $recipe = $this->recipe('verify-webhook.php');
        $raw = '{"version":"2099-01-01","name":"future.event","data":{"object":{"amount":19.9000}},'
            . '"empty_object":{},"empty_array":[]}';
        $timestamp = '1794132000000';
        $signature = hash_hmac('sha256', $timestamp . $raw, 'synthetic-secret');
        $result = $recipe('synthetic-secret', $raw, $timestamp, $signature, 0, static fn (): int => 1794132000000);
        self::assertIsArray($result);
        self::assertSame('future.event', $result['name']);
        self::assertIsArray($result['data']);
        self::assertIsArray($result['data']['object']);
        self::assertSame('19.9000', $result['data']['object']['amount']);
        self::assertInstanceOf(stdClass::class, $result['empty_object']);
        self::assertSame([], $result['empty_array']);
        $this->expectException(WebhookVerificationException::class);
        $recipe('synthetic-secret', $raw . ' ', $timestamp, $signature, 0, static fn (): int => 1794132000000);
    }
}
