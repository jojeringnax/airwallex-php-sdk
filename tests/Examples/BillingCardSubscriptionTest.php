<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Examples;

use Closure;
use InvalidArgumentException;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class BillingCardSubscriptionTest extends HttpTestCase
{
    private const string LOGIN = '{"token":"fixture-token","expires_at":"2030-01-01T00:00:00Z"}';
    private const string CONSENT = '{"id":"consent","status":"VERIFIED","customer_id":"payments-customer",'
        . '"next_triggered_by":"merchant","merchant_trigger_reason":"unscheduled",'
        . '"payment_method":{"id":"method","type":"card"}}';
    private const string SOURCE = '{"id":"source","billing_customer_id":"billing-customer",'
        . '"linked_payment_account_id":"collector","external_id":"method"}';

    private function runRecipe(Client $billing, Client $payments, string $sourceRequestId = 'source-action'): mixed
    {
        $recipe = require dirname(__DIR__, 2) . '/examples/billing-card-subscription.php';
        self::assertInstanceOf(Closure::class, $recipe);
        return $recipe(
            $billing,
            $payments,
            'consent',
            'payments-customer',
            'billing-customer',
            'collector',
            'entity',
            'recurring-price',
            $sourceRequestId,
            'subscription-action'
        );
    }

    public function testVerifiedCardUsesMethodThenReturnedSourceWithIndependentOwnerContexts(): void
    {
        $payments = $this->scenario([[201, self::LOGIN], [200, self::CONSENT]]);
        $billing = $this->scenario([
            [201, self::LOGIN], [201, self::SOURCE], [201, '{"id":"subscription","status":"IN_TRIAL"}'],
        ]);
        $result = $this->runRecipe($this->client($billing), $this->client($payments, loginAccountId: 'collector'));
        self::assertIsArray($result);
        self::assertSame(['id' => 'subscription', 'status' => 'IN_TRIAL'], $result['subscription']);
        self::assertStringEndsWith('/payment_consents/consent', (string) $payments->requests[1]->getUri());
        self::assertSame('collector', $payments->requests[0]->getHeaderLine('x-login-as'));
        self::assertSame('', $billing->requests[0]->getHeaderLine('x-login-as'));
        self::assertStringContainsString('"external_id":"method"', (string) $billing->requests[1]->getBody());
        self::assertStringNotContainsString('"external_id":"consent"', (string) $billing->requests[1]->getBody());
        self::assertStringContainsString('"request_id":"source-action"', (string) $billing->requests[1]->getBody());
        self::assertStringContainsString('"payment_source_id":"source"', (string) $billing->requests[2]->getBody());
        self::assertStringContainsString(
            '"request_id":"subscription-action"',
            (string) $billing->requests[2]->getBody()
        );
        foreach (array_slice($billing->requests, 1) as $request) {
            self::assertSame('', $request->getHeaderLine('x-on-behalf-of'));
            self::assertStringContainsString('"billing_customer_id":"billing-customer"', (string) $request->getBody());
            self::assertStringContainsString('"linked_payment_account_id":"collector"', (string) $request->getBody());
        }
        self::assertCount(2, $payments->requests);
        self::assertCount(3, $billing->requests);
        self::assertArrayNotHasKey('access_granted', $result);
    }

    #[DataProvider('unusableConsents')]
    public function testUnverifiedOrMismatchedConsentStopsBeforeAnyBillingRequest(string $response): void
    {
        $payments = $this->scenario([[201, self::LOGIN], [200, $response]]);
        $billing = $this->scenario([]);
        $this->expectException(RuntimeException::class);
        try {
            $this->runRecipe($this->client($billing), $this->client($payments, loginAccountId: 'collector'));
        } finally {
            self::assertCount(2, $payments->requests);
            self::assertCount(0, $billing->requests);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unusableConsents(): iterable
    {
        foreach (['PENDING', 'REQUIRES_CUSTOMER_ACTION', 'DISABLED', 'UNFAMILIAR'] as $status) {
            yield $status => [str_replace('VERIFIED', $status, self::CONSENT)];
        }
        yield 'customer-triggered' => [str_replace('"merchant"', '"customer"', self::CONSENT)];
        yield 'different-authorization' => [str_replace('unscheduled', 'scheduled', self::CONSENT)];
        yield 'wrong-buyer' => [str_replace('payments-customer', 'other-customer', self::CONSENT)];
        yield 'wrong-consent' => [str_replace('"id":"consent"', '"id":"other-consent"', self::CONSENT)];
        yield 'missing-method' => ['{"id":"consent","status":"VERIFIED","customer_id":"payments-customer"}'];
        yield 'empty-method-id' => [str_replace('"id":"method"', '"id":""', self::CONSENT)];
        yield 'malformed-method-id' => [str_replace('"id":"method"', '"id":[]', self::CONSENT)];
        yield 'different-method' => [str_replace('"type":"card"', '"type":"sepa_direct_debit"', self::CONSENT)];
    }

    #[DataProvider('unusableSources')]
    public function testMalformedOrMismatchedSourceStopsBeforeSubscription(string $response): void
    {
        $payments = $this->scenario([[201, self::LOGIN], [200, self::CONSENT]]);
        $billing = $this->scenario([[201, self::LOGIN], [201, $response]]);
        $this->expectException(RuntimeException::class);
        try {
            $this->runRecipe($this->client($billing), $this->client($payments, loginAccountId: 'collector'));
        } finally {
            self::assertCount(2, $payments->requests);
            self::assertCount(2, $billing->requests);
            self::assertStringEndsWith('/payment_sources/create', (string) $billing->requests[1]->getUri());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unusableSources(): iterable
    {
        yield 'missing-id' => ['{}'];
        yield 'empty-id' => [str_replace('"id":"source"', '"id":""', self::SOURCE)];
        yield 'malformed-id' => [str_replace('"id":"source"', '"id":[]', self::SOURCE)];
        yield 'wrong-buyer' => [str_replace('billing-customer', 'other-customer', self::SOURCE)];
        yield 'wrong-collector' => [str_replace('collector', 'other-collector', self::SOURCE)];
        yield 'wrong-instrument' => [str_replace('method', 'other-method', self::SOURCE)];
    }

    public function testMissingPersistedIdentityStopsBeforeAnyProviderRequest(): void
    {
        $billing = $this->scenario([]);
        $payments = $this->scenario([]);
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->runRecipe($this->client($billing), $this->client($payments), '');
        } finally {
            self::assertCount(0, $billing->requests);
            self::assertCount(0, $payments->requests);
        }
    }
}
