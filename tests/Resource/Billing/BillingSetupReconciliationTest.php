<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Closure;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class BillingSetupReconciliationTest extends HttpTestCase
{
    private const LOGIN = '{"token":"fixture-token","expires_at":"2030-01-01T00:00:00Z"}';

    public function testCompletedHostedSetupRetrievesSourceWithTheSameOwningClient(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [200, '{"id":"checkout_test","mode":"SETUP","ui_mode":"HOSTED","status":"COMPLETED",'
                . '"billing_customer_id":"bcus_test","linked_payment_account_id":"acct_test",'
                . '"payment_source_id":"source_test"}'],
            [200, '{"id":"source_test","billing_customer_id":"bcus_test",'
                . '"linked_payment_account_id":"acct_test","external_id":"method_test"}'],
        ]);
        $example = require dirname(__DIR__, 3) . '/examples/billing-reconcile-setup.php';
        self::assertInstanceOf(Closure::class, $example);
        $result = $example($this->client($http), 'checkout_test', 'bcus_test', 'acct_test');
        self::assertIsArray($result);
        self::assertIsArray($result['payment_source']);
        self::assertSame('source_test', $result['payment_source']['id']);
        self::assertArrayNotHasKey('paid', $result);
        self::assertCount(3, $http->requests);
        self::assertStringEndsWith('/billing/billing_checkouts/checkout_test', (string) $http->requests[1]->getUri());
        self::assertStringEndsWith('/billing/payment_sources/source_test', (string) $http->requests[2]->getUri());
        self::assertSame(
            $http->requests[1]->getHeaderLine('Authorization'),
            $http->requests[2]->getHeaderLine('Authorization'),
        );
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
            self::assertSame('', $request->getHeaderLine('x-on-behalf-of'));
        }
    }

    #[DataProvider('incompleteStatuses')]
    public function testNonCompletedSetupNeverRetrievesOrUsesAnInstrument(string $status): void
    {
        $checkout = [
            'id' => 'checkout_test', 'mode' => 'SETUP', 'status' => $status,
            'billing_customer_id' => 'bcus_test', 'linked_payment_account_id' => 'acct_test',
            'payment_source_id' => 'untrusted-before-completion',
        ];
        $http = $this->scenario([[201, self::LOGIN], [200, json_encode($checkout, JSON_THROW_ON_ERROR)]]);
        $example = require dirname(__DIR__, 3) . '/examples/billing-reconcile-setup.php';
        self::assertInstanceOf(Closure::class, $example);
        $result = $example($this->client($http), 'checkout_test', 'bcus_test', 'acct_test');
        self::assertIsArray($result);
        self::assertNull($result['payment_source']);
        self::assertSame($checkout, $result['checkout']);
        self::assertCount(2, $http->requests);
    }

    /** @return iterable<string,array{string}> */
    public static function incompleteStatuses(): iterable
    {
        foreach (['ACTIVE', 'CANCELLED', 'EXPIRED', 'FUTURE_PROVIDER_STATUS'] as $status) {
            yield $status => [$status];
        }
    }

    public function testRecipeRejectsBuyerContextMismatchBeforeFollowingSourceReference(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [200, '{"id":"checkout_test","mode":"SETUP","status":"COMPLETED",'
                . '"billing_customer_id":"other_buyer","linked_payment_account_id":"acct_test",'
                . '"payment_source_id":"source_other_buyer"}'],
        ]);
        $example = require dirname(__DIR__, 3) . '/examples/billing-reconcile-setup.php';
        self::assertInstanceOf(Closure::class, $example);
        $this->expectException(RuntimeException::class);
        try {
            $example($this->client($http), 'checkout_test', 'bcus_test', 'acct_test');
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    public function testBodylessCancellationReturnsCancelledAndPreservesInvalidStateErrors(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [200, '{"id":"checkout_test","status":"CANCELLED","url":null}'],
            [400, '{"code":"validation_error"}'],
        ]);
        $checkouts = $this->client($http)->billing()->checkouts();
        self::assertSame('CANCELLED', $checkouts->cancel('checkout_test')['status']);
        try {
            $checkouts->cancel('checkout_test');
            self::fail('Expected provider current-state rejection.');
        } catch (ApiException $error) {
            self::assertSame(400, $error->httpStatus());
            self::assertSame('validation_error', $error->providerCode());
        }
        self::assertCount(3, $http->requests);
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('POST', $request->getMethod());
            self::assertSame('', (string) $request->getBody());
            self::assertSame('', $request->getHeaderLine('Content-Type'));
        }
    }
}
