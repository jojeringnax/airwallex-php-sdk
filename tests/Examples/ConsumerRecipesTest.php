<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Examples;

use Closure;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Exception\WebhookVerificationException;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\RetryPolicy;
use Throwable;
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

    public function testQuantityAndReplacementExamplesRefreshOwnedItemIdentityAndKeepExplicitFinancialControls(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN], [200, '{}'],
            [200, '{"items":[{"id":"refreshed-item","subscription_id":"sub",'
                . '"price":{"id":"current-price","currency":"EUR"},"quantity":3}],"page_after":null}'],
            [200, '{}'],
        ]);
        $client = $this->client($http);
        $change = $this->recipe('billing-lifecycle.php', 'change_quantity');
        $change($client, 'sub', 'old-item', 3, 'persisted-quantity');
        // This fixture has one candidate matching the known action/owner/Price/quantity; ambiguous lists must stop.
        $page = $client->billing()->subscriptions()->listItems('sub', ['page_size' => 20]);
        self::assertIsArray($page['items']);
        self::assertCount(1, $page['items']);
        $item = $page['items'][0];
        self::assertIsArray($item);
        self::assertSame('sub', $item['subscription_id']);
        self::assertSame(['id' => 'current-price', 'currency' => 'EUR'], $item['price']);
        self::assertSame('3', $item['quantity']);
        self::assertIsString($item['id']);
        $replace = $this->recipe('billing-lifecycle.php', 'replace_price');
        $replace($client, 'sub', $item['id'], 'new-price', 2, 'persisted-plan');
        self::assertStringContainsString(
            '"items":[{"id":"old-item","quantity":3}]',
            (string) $http->requests[1]->getBody()
        );
        self::assertSame('GET', $http->requests[2]->getMethod());
        $wire = (string) $http->requests[3]->getBody();
        self::assertStringContainsString('"id":"refreshed-item","deleted":true', $wire);
        self::assertStringNotContainsString('old-item', $wire);
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

    /** @param array<string,mixed> $changes */
    private static function recoverySubscription(array $changes = []): string
    {
        return json_encode(array_replace([
            'id' => 'sub', 'billing_customer_id' => 'buyer', 'legal_entity_id' => 'entity',
            'linked_payment_account_id' => 'collector', 'payment_source_id' => 'old-source',
            'status' => 'UNPAID', 'latest_invoice_id' => 'invoice',
        ], $changes), JSON_THROW_ON_ERROR);
    }

    private const string RECOVERY_SOURCE = '{"id":"new-source","billing_customer_id":"buyer",'
        . '"linked_payment_account_id":"collector","external_id":"authorized-method"}';
    private const string RECOVERY_INVOICE = '{"id":"invoice","billing_customer_id":"buyer",'
        . '"subscription_id":"sub","payment_status":"PAID","amount_due":19.900000000000000001,'
        . '"pdf_url":null,"future_number":1e100,"future_flag":false}';

    /** @return array<array-key,mixed> */
    private function recover(Client $client): array
    {
        $result = ($this->recipe('billing-lifecycle.php', 'replace_source_and_observe'))(
            $client,
            'sub',
            'new-source',
            'buyer',
            'entity',
            'collector',
            'persisted-recovery'
        );
        self::assertIsArray($result);
        return $result;
    }

    public function testRecoveryVerifiesBothOwnersMakesOneExplicitChangeThenPreservesObservedFacts(): void
    {
        $observed = self::recoverySubscription(['payment_source_id' => 'new-source', 'status' => 'ACTIVE']);
        $http = $this->scenario([
            [201, self::LOGIN], [200, self::recoverySubscription()], [200, self::RECOVERY_SOURCE],
            [200, $observed], [200, $observed], [200, self::RECOVERY_INVOICE],
        ]);
        $result = $this->recover($this->client($http));
        self::assertNull($result['update_error']);
        self::assertNull($result['read_error']);
        self::assertIsArray($result['invoice']);
        self::assertSame('19.900000000000000001', $result['invoice']['amount_due']);
        self::assertSame('1e100', $result['invoice']['future_number']);
        self::assertFalse($result['invoice']['future_flag']);
        self::assertNull($result['invoice']['pdf_url']);
        $methods = array_map(static fn ($r): string => $r->getMethod(), $http->requests);
        self::assertSame(['POST', 'GET', 'GET', 'POST', 'GET', 'GET'], $methods);
        self::assertStringEndsWith('/payment_sources/new-source', (string) $http->requests[2]->getUri());
        self::assertSame(
            '{"request_id":"persisted-recovery","payment_source_id":"new-source"}',
            (string) $http->requests[3]->getBody()
        );
        self::assertArrayNotHasKey('access_granted', $result);
        self::assertArrayNotHasKey('financial_success', $result);
    }

    /** @return iterable<string,array{string,string,int}> */
    public static function recoveryOwnerMismatches(): iterable
    {
        foreach (['id', 'billing_customer_id', 'legal_entity_id', 'linked_payment_account_id'] as $key) {
            yield 'subscription ' . $key => [self::recoverySubscription([$key => 'SYNTHETIC_PRIVATE']),
                self::RECOVERY_SOURCE, 2];
        }
        yield 'optional entity absent' => [str_replace('"legal_entity_id":"entity",', '', self::recoverySubscription()),
            self::RECOVERY_SOURCE, 2];
        foreach (['id', 'billing_customer_id', 'linked_payment_account_id'] as $key) {
            $source = json_decode(self::RECOVERY_SOURCE, true, flags: JSON_THROW_ON_ERROR);
            $source[$key] = 'SYNTHETIC_PRIVATE';
            yield 'source ' . $key => [self::recoverySubscription(), json_encode($source, JSON_THROW_ON_ERROR), 3];
        }
    }

    #[DataProvider('recoveryOwnerMismatches')]
    public function testRecoveryOwnershipMismatchStopsBeforeTheSourceUpdate(
        string $subscription,
        string $source,
        int $calls,
    ): void {
        $http = $this->scenario([[201, self::LOGIN], [200, $subscription], [200, $source]]);
        $this->expectException(RuntimeException::class);
        try {
            $this->recover($this->client($http));
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $error->getMessage());
            throw $error;
        } finally {
            self::assertCount($calls, $http->requests);
            foreach (array_slice($http->requests, 1) as $request) {
                self::assertSame('GET', $request->getMethod());
            }
        }
    }

    public function testUnavailableReplacementSourcePreservesSafeProviderErrorAndMakesNoUpdate(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, self::recoverySubscription()],
            [404, '{"code":"resource_not_found","message":"SYNTHETIC_PRIVATE"}']]);
        try {
            $this->recover($this->client($http));
            self::fail('An unavailable Source cannot authorize an update.');
        } catch (ApiException $error) {
            self::assertSame(404, $error->httpStatus());
            self::assertSame('resource_not_found', $error->providerCode());
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', (string) $error);
        }
        self::assertCount(3, $http->requests);
    }

    /** @return iterable<string,array{array{int,string}|Throwable,string,?int}> */
    public static function failedRecoveryUpdates(): iterable
    {
        yield 'decline' => [[400, '{"code":"issuer_declined","message":"SYNTHETIC_PRIVATE"}'], 'api_error', 400];
        yield 'server uncertainty' => [[503, '{"code":"internal_error","message":"SYNTHETIC_PRIVATE"}'],
            'api_error', 503];
        yield 'transport uncertainty' => [new RuntimeException('SYNTHETIC_PRIVATE'), 'unconfirmed', null];
        yield 'invalid success body' => [[200, '{'], 'unconfirmed', null];
    }

    /** @param array{int,string}|Throwable $failure */
    #[DataProvider('failedRecoveryUpdates')]
    public function testFailedOrUnconfirmedUpdateNeverReplaysAndOnlyReadsObservedState(
        array | Throwable $failure,
        string $kind,
        ?int $status,
    ): void {
        $observed = self::recoverySubscription(['status' => 'FUTURE_UNKNOWN', 'payment_source_id' => 'new-source']);
        $http = $this->scenario([[201, self::LOGIN], [200, self::recoverySubscription()],
            [200, self::RECOVERY_SOURCE], $failure, [200, $observed], [200, self::RECOVERY_INVOICE]]);
        $result = $this->recover($this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3)));
        self::assertNull($result['update_response']);
        self::assertIsArray($result['update_error']);
        self::assertSame($kind, $result['update_error']['kind']);
        self::assertSame($status, $result['update_error']['http_status'] ?? null);
        self::assertIsArray($result['subscription']);
        self::assertSame('FUTURE_UNKNOWN', $result['subscription']['status']);
        self::assertSame('new-source', $result['subscription']['payment_source_id']);
        self::assertCount(6, $http->requests);
        self::assertSame('POST', $http->requests[3]->getMethod());
        self::assertSame('GET', $http->requests[4]->getMethod());
        self::assertSame('GET', $http->requests[5]->getMethod());
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testReadbackFailureRetainsUpdateResponseAndDoesNotInventRecovery(): void
    {
        $updated = self::recoverySubscription(['payment_source_id' => 'new-source']);
        $http = $this->scenario([[201, self::LOGIN], [200, self::recoverySubscription()],
            [200, self::RECOVERY_SOURCE], [200, $updated],
            [503, '{"code":"internal_error","message":"SYNTHETIC_PRIVATE"}']]);
        $result = $this->recover($this->client($http));
        self::assertSame(json_decode($updated, true, flags: JSON_THROW_ON_ERROR), $result['update_response']);
        self::assertNull($result['subscription']);
        self::assertNull($result['invoice']);
        self::assertIsArray($result['read_error']);
        self::assertSame('subscription', $result['read_error']['stage']);
        self::assertCount(5, $http->requests);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testUnprovedUpdateOrReadOwnerNeverBecomesAnOwnedResourceFact(): void
    {
        foreach (['update', 'read'] as $phase) {
            $owned = self::recoverySubscription(['payment_source_id' => 'new-source']);
            $foreign = self::recoverySubscription(['billing_customer_id' => 'SYNTHETIC_PRIVATE']);
            $http = $this->scenario([[201, self::LOGIN], [200, self::recoverySubscription()],
                [200, self::RECOVERY_SOURCE], [200, $phase === 'update' ? $foreign : $owned],
                [200, $phase === 'read' ? $foreign : $owned], [200, self::RECOVERY_INVOICE]]);
            $result = $this->recover($this->client($http));
            $error = $result[$phase === 'update' ? 'update_error' : 'read_error'];
            self::assertIsArray($error);
            self::assertSame('ownership_not_established', $error['kind']);
            self::assertNull($result[$phase === 'update' ? 'update_response' : 'subscription']);
            self::assertCount($phase === 'update' ? 6 : 5, $http->requests);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    public function testMissingPersistedRecoveryIdentityStopsBeforeAnyRequest(): void
    {
        $http = $this->scenario([]);
        $this->expectException(\InvalidArgumentException::class);
        try {
            ($this->recipe('billing-lifecycle.php', 'replace_source_and_observe'))(
                $this->client($http),
                'sub',
                'new-source',
                'buyer',
                'entity',
                'collector',
                ''
            );
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function testReadOnlyRecoveryKeepsUnknownNonterminalAndReorderedStatesWithoutSourceChanges(): void
    {
        $states = ['PENDING', 'FUTURE_UNKNOWN', 'ACTIVE', 'UNPAID', 'UNPAID'];
        $responses = [[201, self::LOGIN]];
        foreach ($states as $state) {
            $responses[] = [200, self::recoverySubscription(['status' => $state, 'latest_invoice_id' => null])];
        }
        $http = $this->scenario($responses);
        $client = $this->client($http);
        $observe = $this->recipe('billing-lifecycle.php', 'observe_recovery');
        foreach ($states as $state) {
            $result = $observe($client, 'sub', 'buyer', 'entity', 'collector');
            self::assertIsArray($result);
            self::assertIsArray($result['subscription']);
            self::assertSame($state, $result['subscription']['status']);
            self::assertArrayHasKey('latest_invoice_id', $result['subscription']);
            self::assertNull($result['subscription']['latest_invoice_id']);
            self::assertNull($result['invoice']);
        }
        self::assertCount(6, $http->requests);
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    public function testInvoiceFailureOrUnprovedOwnerWithholdsInvoiceButKeepsOwnedSubscription(): void
    {
        $responses = [[404, '{"code":"resource_not_found"}'],
            [200, '{"id":"invoice","billing_customer_id":"other"}']];
        foreach ($responses as $response) {
            $http = $this->scenario([[201, self::LOGIN], [200, self::recoverySubscription()], $response]);
            $result = ($this->recipe('billing-lifecycle.php', 'observe_recovery'))(
                $this->client($http),
                'sub',
                'buyer',
                'entity',
                'collector'
            );
            self::assertIsArray($result);
            self::assertIsArray($result['subscription']);
            self::assertNull($result['invoice']);
            self::assertIsArray($result['read_error']);
            self::assertSame('invoice', $result['read_error']['stage']);
            self::assertCount(3, $http->requests);
        }
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

    public function testWebhookRecipeRetainsDirectCheckoutShapeAndExactNumbersAfterVerification(): void
    {
        $recipe = $this->recipe('verify-webhook.php');
        $raw = '{"id":"event_example","name":"billing_checkout.created","version":"2026-08-21",'
            . '"created_at":"2026-11-09T00:00:00Z","org_id":"org_example","data":{'
            . '"id":"checkout_example","status":"ACTIVE","line_items":[{"quantity":2}],'
            . '"amount":19.900000000000000001,"extra":9223372036854775808}}';
        $timestamp = '1794132000000';
        $signature = hash_hmac('sha256', $timestamp . $raw, 'synthetic-secret');
        $event = $recipe('synthetic-secret', $raw, $timestamp, $signature, 0, static fn (): int => 1794132000000);
        self::assertIsArray($event);
        self::assertIsArray($event['data']);
        self::assertArrayNotHasKey('object', $event['data']);
        self::assertSame([['quantity' => 2]], $event['data']['line_items']);
        self::assertSame('19.900000000000000001', $event['data']['amount']);
        self::assertSame('9223372036854775808', $event['data']['extra']);
        $this->expectException(WebhookVerificationException::class);
        $recipe(
            'synthetic-secret',
            $raw . 'invalid json',
            $timestamp,
            $signature,
            0,
            static fn (): int => 1794132000000
        );
    }
}
