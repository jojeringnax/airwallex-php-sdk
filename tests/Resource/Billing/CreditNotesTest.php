<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CreditNotesTest extends HttpTestCase
{
    private const string LOGIN = '{"token":"fixture-token","expires_at":"2130-01-01T00:00:00Z"}';

    public function testPrepaymentRecipeAdjustsOriginalInvoiceWithoutInventingARefund(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"credit","invoice_id":"invoice","status":"DRAFT"}'],
            [200, '{}'], [200, '{"id":"credit","status":"FINALIZED"}'],
            [200, '{"id":"credit","invoice_id":"invoice","type":"BEFORE_PAYMENT",'
                . '"adjustment_amount":25.000000000001,"refund_amount":null,"refund_status":null}'],
            [200, '{"items":[]}']]);
        $recipe = require dirname(__DIR__, 3) . '/examples/billing-credit-note.php';
        self::assertInstanceOf(\Closure::class, $recipe);
        $value = $recipe($this->client($http), 'invoice', '25.000000000001', 'create-command', 'items-command');
        self::assertIsArray($value);
        self::assertIsArray($value['credit_note']);
        self::assertSame('invoice', $value['credit_note']['invoice_id']);
        self::assertSame('25.000000000001', $value['credit_note']['adjustment_amount']);
        self::assertNull($value['credit_note']['refund_status']);
        self::assertSame('{}', (string) $http->requests[3]->getBody());
        self::assertStringContainsString('"amount":25.000000000001', (string) $http->requests[2]->getBody());
        self::assertStringContainsString('"request_id":"items-command"', (string) $http->requests[2]->getBody());
        self::assertStringEndsWith('billing_transactions?credit_note_id=credit', (string) $http->requests[5]->getUri());
        self::assertSame('2026-08-21', $http->requests[3]->getHeaderLine('x-api-version'));
        self::assertSame('', $http->requests[3]->getHeaderLine('x-on-behalf-of'));
    }

    public function testPostpaymentAllocationKeepsRefundStatusAndAmountsDistinct(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"credit","type":"AFTER_PAYMENT"}'],
            [200, '{}'], [200, '{"id":"credit","status":"FINALIZED","refund_status":"CREATED",'
                . '"refund_amount":12.000000000001,"out_of_band_amount":8.00}'],
            [200, '{"id":"credit","refund_status":"FAILED","refund_amount":12.000000000001}']]);
        $notes = $this->client($http)->billing()->creditNotes();
        $notes->create(['request_id' => 'create', 'invoice_id' => 'invoice', 'type' => 'AFTER_PAYMENT',
            'reason' => 'OTHER', 'custom_reason' => 'Reviewed adjustment']);
        $notes->addLineItems('credit', ['request_id' => 'items', 'line_items' => [[
            'invoice_line_item_id' => 'item', 'quantity' => 2, 'unit_amount' => '10.0000000000005',
        ]]]);
        $pending = $notes->finalize('credit', ['refund_amount' => '12.000000000001', 'out_of_band_amount' => '8.00']);
        self::assertSame('CREATED', $pending['refund_status']);
        self::assertSame('12.000000000001', $pending['refund_amount']);
        self::assertSame('8.00', $pending['out_of_band_amount']);
        self::assertSame('FAILED', $notes->retrieve('credit')['refund_status']);
        self::assertSame(
            '{"refund_amount":12.000000000001,"out_of_band_amount":8.00}',
            (string) $http->requests[3]->getBody()
        );
        self::assertCount(5, $http->requests);
    }

    public function testGroupAndQuantityDefaultsListPagesAndVoidRetainOriginalParents(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{}'], [200, '{"items":[],"page_after":"next /+"}'],
            [200, '{"items":[],"page_after":null}'], [200, '{"id":"credit","status":"VOIDED"}'],
            [200, '{"id":"item","credit_note_id":"credit","amount":0.000000000001}'],
            [200, '{"items":[],"page_after":null}']]);
        $notes = $this->client($http)->billing()->creditNotes();
        $notes->addLineItems('credit /+', ['request_id' => 'items', 'line_items' => [
            ['invoice_line_item_group_id' => 'group', 'quantity' => 2, 'invoice_line_item_id' => null,
                'amount' => null, 'unit_amount' => null],
            ['invoice_line_item_id' => 'another-item', 'quantity' => 1],
        ]]);
        $page = $notes->list(['invoice_id' => 'invoice', 'page_size' => 1]);
        $notes->list(['invoice_id' => 'invoice', 'page_size' => 1, 'page' => $page['page_after']]);
        self::assertSame('VOIDED', $notes->void('credit /+')['status']);
        self::assertSame('0.000000000001', $notes->retrieveLineItem('credit /+', 'item /+')['amount']);
        self::assertSame([], $notes->listLineItems('credit /+', ['page_size' => 1])['items']);
        self::assertSame('', (string) $http->requests[4]->getBody());
        self::assertStringEndsWith('/credit%20%2F%2B/void', (string) $http->requests[4]->getUri());
        self::assertStringEndsWith('/credit%20%2F%2B/line_items/item%20%2F%2B', (string) $http->requests[5]->getUri());
        self::assertStringEndsWith(
            'invoice_id=invoice&page_size=1&page=next%20%2F%2B',
            (string) $http->requests[3]->getUri()
        );
    }

    /** @param array<string,mixed> $item */
    #[DataProvider('invalidItems')]
    public function testInvalidCreditItemsFailBeforeAuthentication(array $item): void
    {
        $http = $this->scenario([]);
        try {
            $this->client($http)->billing()->creditNotes()->addLineItems('credit', [
                'request_id' => 'original', 'line_items' => [$item],
            ]);
            self::fail('Invalid credit line accepted.');
        } catch (ValidationException) {
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidItems(): iterable
    {
        yield 'float' => [['amount' => 1.5]];
        yield 'negative' => [['amount' => '-0.000000000001']];
        yield 'above limit' => [['amount' => '9999999999.000000000001']];
        yield 'missing amount and quantity' => [[]];
        yield 'both forms' => [['amount' => '2', 'quantity' => 1, 'unit_amount' => '2']];
        yield 'quantity without item' => [['quantity' => 1]];
        yield 'group and amount' => [['invoice_line_item_group_id' => 'group', 'quantity' => 1, 'amount' => '2']];
        yield 'group and item' => [[
            'invoice_line_item_group_id' => 'group', 'invoice_line_item_id' => 'item', 'quantity' => 1,
        ]];
    }

    public function testOtherReasonRequiresExplanationAndOrdinaryReasonAllowsNullOnly(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"credit"}']]);
        $this->client($http)->billing()->creditNotes()->create([
            'request_id' => 'create', 'invoice_id' => 'invoice', 'type' => 'BEFORE_PAYMENT',
            'reason' => 'BILLING_ERROR', 'custom_reason' => null,
        ]);
        foreach ([['reason' => 'OTHER'], ['reason' => 'BILLING_ERROR', 'custom_reason' => 'unexpected']] as $changes) {
            $denied = $this->scenario([]);
            try {
                $this->client($denied)->billing()->creditNotes()->create(array_replace([
                    'request_id' => 'original', 'invoice_id' => 'invoice', 'type' => 'BEFORE_PAYMENT',
                ], $changes));
                self::fail('Invalid reason combination accepted.');
            } catch (ValidationException) {
                self::assertSame([], $denied->requests);
            }
        }
    }

    #[DataProvider('providerFailures')]
    public function testProviderCreditAndStateFailuresAreNotReplayed(int $status): void
    {
        $http = $this->scenario([[201, self::LOGIN], [$status, '{"code":"validation_error"}']]);
        $notes = $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3))->billing()->creditNotes();
        try {
            $notes->finalize('credit', ['refund_amount' => '100.00']);
            self::fail('Expected provider refusal.');
        } catch (ApiException $error) {
            self::assertSame($status, $error->httpStatus());
            self::assertCount(2, $http->requests);
        }
    }

    /** @return iterable<string,array{int}> */
    public static function providerFailures(): iterable
    {
        yield 'invalid invoice or excessive credit' => [400];
        yield 'missing original resource' => [404];
        yield 'ambiguous service failure' => [503];
    }

    public function testDelegationIsRejectedBeforeAuthentication(): void
    {
        $http = $this->scenario([]);
        $this->expectException(ConfigurationException::class);
        $this->client($http)->forConnectedAccount('account')->billing()->creditNotes()->void('credit');
    }
}
