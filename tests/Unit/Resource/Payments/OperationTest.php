<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Resource\Payments;

use Closure;
use Jojeringnax\Airwallex\Resource\Payments;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class OperationTest extends HttpTestCase
{
    /** @return iterable<string, array{Closure(Payments): array<array-key, mixed>, string, string}> */
    public static function operations(): iterable
    {
        yield 'customer-create' => [static fn (Payments $p): array => $p->customers()->create([
            'request_id' => 'customer-create', 'merchant_customer_id' => 'local-buyer',
        ]), 'POST', '/pa/customers/create'];
        yield 'customer-retrieve' => [static fn (Payments $p): array => $p->customers()->retrieve('resource/id'),
             'GET',
             '/pa/customers/resource%2Fid'];
        yield 'customer-list' => [static fn (Payments $p): array => $p->customers()->list(), 'GET', '/pa/customers'];
        yield 'customer-update' => [static fn (Payments $p): array => $p->customers()->update('resource', [
            'request_id' => 'customer-update', 'email' => 'buyer@example.test',
        ]), 'POST', '/pa/customers/resource/update'];
        yield 'consent-create' => [static fn (Payments $p): array => $p->paymentConsents()->create([
            'request_id' => 'consent-create', 'customer_id' => 'customer', 'next_triggered_by' => 'merchant',
        ]), 'POST', '/pa/payment_consents/create'];
        yield 'consent-retrieve' => [static fn (Payments $p): array => $p->paymentConsents()->retrieve('resource'),
             'GET',
             '/pa/payment_consents/resource'];
        yield 'consent-list' => [static fn (Payments $p): array => $p->paymentConsents()->list(),
             'GET',
             '/pa/payment_consents'];
        yield 'consent-update' => [static fn (Payments $p): array => $p->paymentConsents()->update('resource', [
            'request_id' => 'consent-update', 'metadata' => ['local_id' => '00123'],
        ]), 'POST', '/pa/payment_consents/resource/update'];
        yield 'consent-verify' => [static fn (Payments $p): array => $p->paymentConsents()->verify('resource', [
            'request_id' => 'consent-verify', 'payment_method' => ['id' => 'method', 'type' => 'card'],
        ]), 'POST', '/pa/payment_consents/resource/verify'];
        yield 'consent-continue' => [static fn (Payments $p): array => $p->paymentConsents()->continueVerification(
            'resource',
            [
            'request_id' => 'consent-continue', 'type' => 'verify_micro_deposits',
            'micro_deposit' => ['amounts' => ['0.12', '0.34']],
            ]
        ), 'POST', '/pa/payment_consents/resource/verify_continue'];
        yield 'consent-disable' => [static fn (Payments $p): array => $p->paymentConsents()->disable('resource', [
            'request_id' => 'consent-disable',
        ]), 'POST', '/pa/payment_consents/resource/disable'];
        yield 'method-retrieve' => [static fn (Payments $p): array => $p->paymentMethods()->retrieve('resource'),
             'GET',
             '/pa/payment_methods/resource'];
        yield 'method-list' => [static fn (Payments $p): array => $p->paymentMethods()->list(),
             'GET',
             '/pa/payment_methods'];
        yield 'intent-create' => [static fn (Payments $p): array => $p->paymentIntents()->create([
            'request_id' => 'intent-create', 'amount' => '19.90', 'currency' => 'EUR', 'merchant_order_id' => 'order',
        ]), 'POST', '/pa/payment_intents/create'];
        yield 'intent-retrieve' => [static fn (Payments $p): array => $p->paymentIntents()->retrieve('resource'),
             'GET',
             '/pa/payment_intents/resource'];
        yield 'intent-list' => [static fn (Payments $p): array => $p->paymentIntents()->list(),
             'GET',
             '/pa/payment_intents'];
        yield 'intent-confirm' => [static fn (Payments $p): array => $p->paymentIntents()->confirm('resource', [
            'request_id' => 'intent-confirm', 'payment_consent_id' => 'consent', 'triggered_by' => 'merchant',
        ]), 'POST', '/pa/payment_intents/resource/confirm'];
        yield 'intent-continue' => [static fn (Payments $p): array => $p->paymentIntents()->continueConfirmation(
            'resource',
            [
            'request_id' => 'intent-continue', 'type' => 'verify_micro_deposits',
            'micro_deposit' => ['amounts' => ['0.12', '0.34']],
            ]
        ), 'POST', '/pa/payment_intents/resource/confirm_continue'];
        yield 'intent-capture' => [static fn (Payments $p): array => $p->paymentIntents()->capture('resource', [
            'request_id' => 'intent-capture', 'amount' => '19.90',
        ]), 'POST', '/pa/payment_intents/resource/capture'];
        yield 'intent-cancel' => [static fn (Payments $p): array => $p->paymentIntents()->cancel('resource', [
            'request_id' => 'intent-cancel', 'cancellation_reason' => 'requested_by_customer',
        ]), 'POST', '/pa/payment_intents/resource/cancel'];
        yield 'attempt-retrieve' => [static fn (Payments $p): array => $p->paymentAttempts()->retrieve('resource'),
             'GET',
             '/pa/payment_attempts/resource'];
        yield 'attempt-list' => [static fn (Payments $p): array => $p->paymentAttempts()->list(),
             'GET',
             '/pa/payment_attempts'];
        yield 'refund-create' => [static fn (Payments $p): array => $p->refunds()->create([
            'request_id' => 'refund-create', 'payment_intent_id' => 'intent', 'amount' => '5.00',
        ]), 'POST', '/pa/refunds/create'];
        yield 'refund-retrieve' => [static fn (Payments $p): array => $p->refunds()->retrieve('resource'),
             'GET',
             '/pa/refunds/resource'];
        yield 'refund-list' => [static fn (Payments $p): array => $p->refunds()->list(), 'GET', '/pa/refunds'];
    }

    /** @param Closure(Payments): array<array-key, mixed> $operation */
    #[DataProvider('operations')]
    public function testAcceptedOperationsBindToSelectedEndpointAndAccount(
        Closure $operation,
        string $method,
        string $path
    ): void {
        $http = $this->scenario([
            [201, '{"token":"synthetic-token","expires_at":"2030-01-01T00:00:00Z"}'],
            [200, '{"id":"resource","extra_numeric":1.2300,"unknown_status":"FUTURE"}'],
        ]);
        $result = $operation($this->client($http)->forConnectedAccount('acct_A')->payments());
        self::assertSame(['id' => 'resource', 'extra_numeric' => '1.2300', 'unknown_status' => 'FUTURE'], $result);
        self::assertCount(2, $http->requests);
        $request = $http->requests[1];
        self::assertSame($method, $request->getMethod());
        self::assertSame('https://api.sandbox.airwallex.com/api/v1' . $path, (string) $request->getUri());
        self::assertSame('acct_A', $request->getHeaderLine('x-on-behalf-of'));
        self::assertSame('2026-08-21', $request->getHeaderLine('x-api-version'));
        if ($method === 'POST') {
            self::assertStringContainsString('"request_id":', (string) $request->getBody());
            self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        } else {
            self::assertSame('', (string) $request->getBody());
        }
    }
}
