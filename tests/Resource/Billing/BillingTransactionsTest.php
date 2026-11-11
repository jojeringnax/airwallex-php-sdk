<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BillingTransactionsTest extends HttpTestCase
{
    private const string LOGIN = '{"token":"fixture-token","expires_at":"2130-01-01T00:00:00Z"}';

    public function testRetrieveKeepsExactMoneyUnknownValuesAndOptionalLinkage(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200,
            '{"id":"btxn","amount":12345678901234567890.000000000001,"currency":"USD",'
            . '"status":"FUTURE_STATUS","type":"FUTURE_TYPE","extra":1.000000000001}']]);
        $value = $this->client($http)->billing()->transactions()->retrieve(id: 'btxn /+');
        self::assertSame('12345678901234567890.000000000001', $value['amount']);
        self::assertSame('1.000000000001', $value['extra']);
        self::assertSame('FUTURE_STATUS', $value['status']);
        self::assertSame('FUTURE_TYPE', $value['type']);
        self::assertArrayNotHasKey('invoice_id', $value);
        self::assertArrayNotHasKey('external_id', $value);
        self::assertArrayNotHasKey('out_of_band', $value);
        $request = $http->requests[1];
        self::assertSame(
            'https://api.sandbox.airwallex.com/api/v1/billing/billing_transactions/btxn%20%2F%2B',
            (string) $request->getUri()
        );
        self::assertSame('GET', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
        self::assertSame('2026-08-21', $request->getHeaderLine('x-api-version'));
        self::assertSame('', $request->getHeaderLine('x-on-behalf-of'));
    }

    public function testExplicitPagesAndRecipeRetainParentAndOpaqueCursor(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{"id":"btxn"}'],
            [200, '{"items":[{"id":"btxn","amount":0.000000000001,"invoice_id":"inv","type":"PAYMENT",'
                . '"status":"SUCCEEDED","out_of_band":false}],"page_after":"next /+","page_before":null}'],
            [200, '{"items":[],"page_after":null,"page_before":"previous"}']]);
        $client = $this->client($http);
        $recipe = require dirname(__DIR__, 3) . '/examples/billing-transactions.php';
        self::assertInstanceOf(\Closure::class, $recipe);
        $first = $recipe($client, 'inv', 'btxn');
        self::assertIsArray($first);
        self::assertIsArray($first['page']);
        self::assertIsArray($first['page']['items']);
        self::assertIsArray($first['page']['items'][0]);
        self::assertSame('0.000000000001', $first['page']['items'][0]['amount']);
        $last = $client->billing()->transactions()->list(query: [
            'invoice_id' => 'inv', 'page_size' => 20, 'page' => $first['page']['page_after'],
        ]);
        self::assertSame([], $last['items']);
        self::assertCount(4, $http->requests);
        self::assertStringEndsWith('?invoice_id=inv&page_size=20', (string) $http->requests[2]->getUri());
        self::assertStringEndsWith(
            '?invoice_id=inv&page_size=20&page=next%20%2F%2B',
            (string) $http->requests[3]->getUri()
        );
    }

    public function testCreditNoteParentIsAReadOnlyFilterWithoutCreatingCreditNotes(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{"items":[{"type":"REFUND","status":"CREATED",'
            . '"credit_note_id":"crn","external_id":"ref","amount":3.10}]}']]);
        $value = $this->client($http)->billing()->transactions()->list(query: ['credit_note_id' => 'crn']);
        self::assertIsArray($value['items']);
        self::assertIsArray($value['items'][0]);
        self::assertSame('3.10', $value['items'][0]['amount']);
        self::assertSame('REFUND', $value['items'][0]['type']);
        self::assertStringEndsWith('?credit_note_id=crn', (string) $http->requests[1]->getUri());
    }

    /** @param array<string,mixed> $query */
    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesFailBeforeAuthentication(array $query): void
    {
        $http = $this->scenario([]);
        try {
            $this->client($http)->billing()->transactions()->list(query: $query);
            self::fail('Invalid queries must fail.');
        } catch (ValidationException $error) {
            self::assertSame([], $http->requests);
            self::assertStringNotContainsString('private', $error->getMessage());
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidQueries(): iterable
    {
        yield 'missing parent' => [[]];
        yield 'two parents' => [['invoice_id' => 'private-invoice', 'credit_note_id' => 'private-note']];
        yield 'empty parent' => [['invoice_id' => '']];
        yield 'null parent' => [['invoice_id' => null]];
        yield 'control parent' => [['invoice_id' => "private\n"]];
        yield 'unsupported filter' => [['invoice_id' => 'private', 'billing_customer_id' => 'buyer']];
        yield 'out of range size' => [['invoice_id' => 'private', 'page_size' => 1001]];
        yield 'wrong size type' => [['invoice_id' => 'private', 'page_size' => '2']];
        yield 'wrong cursor type' => [['invoice_id' => 'private', 'page' => null]];
        yield 'malformed timestamp' => [['invoice_id' => 'private', 'from_created_at' => 'tomorrow']];
    }

    public function testBillingContextRejectsDelegationBeforeAuthentication(): void
    {
        $http = $this->scenario([]);
        $this->expectException(ConfigurationException::class);
        $this->client($http)->forConnectedAccount('account')->billing()->transactions()->retrieve('btxn');
    }

    public function testInvalidIdentifierAndMalformedResponseUseExistingExceptions(): void
    {
        $http = $this->scenario([]);
        try {
            $this->client($http)->billing()->transactions()->retrieve('..');
            self::fail('Invalid identifier must fail.');
        } catch (ValidationException) {
            self::assertSame([], $http->requests);
        }
        $http = $this->scenario([[201, self::LOGIN], [200, '[{"id":"btxn"}]']]);
        $this->expectException(ProtocolException::class);
        $this->client($http)->billing()->transactions()->retrieve('btxn');
    }

    public function testPageFailureDoesNotHideErrorOrLeakProviderPayload(): void
    {
        $http = $this->scenario([[201, self::LOGIN],
            [200, '{"items":[],"page_after":"next","page_before":null}'],
            [403, '{"code":"access_denied","message":"private-provider-profile","details":{"secret":"private"}}']]);
        $client = $this->client($http);
        $first = $client->billing()->transactions()->list(query: ['invoice_id' => 'inv']);
        try {
            $client->billing()->transactions()->list(query: ['invoice_id' => 'inv', 'page' => $first['page_after']]);
            self::fail('The next-page failure must surface.');
        } catch (ApiException $error) {
            self::assertSame(403, $error->httpStatus());
            self::assertSame('access_denied', $error->providerCode());
            self::assertStringNotContainsString('private', $error->getMessage());
            self::assertCount(3, $http->requests);
        }
    }
}
