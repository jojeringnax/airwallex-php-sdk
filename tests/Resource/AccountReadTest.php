<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource;

use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use Psr\Http\Message\RequestInterface;

final class AccountReadTest extends HttpTestCase
{
    public function testAccountReadsPreserveNumericPrecisionAndUseSelectedRoutes(): void
    {
        $http = $this->scenario([
            [200, '{"token":"session-token","expires_at":"2099-01-01T00:00:00Z"}'],
            [200, '{"id":"dst_1","amount":10.20}'],
            [200, '{"items":[{"amount":1.23}],"page_after":"abc"}'],
            [200, '{"id":"dep_1","amount":0.10}'],
            [200, '{"items":[{"amount":2000.005}],"has_more":true}'],
            [200, '{"settlement_id":"batch","amount":942.10,"fee":10.03}'],
            [200, '{"items":[{"settlement_id":"batch","amount":942.10}],"has_more":false}'],
            [200, '{"items":[{"transaction_id":"tx_1","exchange_rate":1.024566778899001,'
                . '"net_amount":-10.00,"fee_details_list":[{"settlement_total_amount":0.33}]}],"has_more":false}'],
            [200, '{"items":[{"amount":-945.33,"balance":1024.345}],"has_more":false}'],
            [200, '{"id":"payout_1","amount_payer_pays":945.33,"conversion":{"rate":1.010001}}'],
        ]);
        $sdk = $this->client($http, loginAccountId: 'merchant-account');
        $settlementQuery = [
            'currency' => 'EUR',
            'from_settled_at' => '2026-11-01',
            'to_settled_at' => '2026-11-02',
            'status' => 'SETTLED',
            'page_num' => 1,
        ];
        self::assertSame('10.20', $sdk->payments()->paymentDisputes()->retrieve('dst_1')['amount']);
        $disputes = $sdk->payments()->paymentDisputes()->list(['page' => 'a+b']);
        self::assertSame('1.23', self::firstRow($disputes)['amount']);
        self::assertSame('0.10', $sdk->deposits()->retrieve('dep_1')['amount']);
        self::assertSame('2000.005', self::firstRow($sdk->deposits()->list(['page_num' => 2]))['amount']);
        self::assertSame('10.03', $sdk->finance()->settlements()->retrieve('batch')['fee']);
        self::assertSame('942.10', self::firstRow($sdk->finance()->settlements()->list($settlementQuery))['amount']);
        $row = self::firstRow($sdk->payments()->settlementRecords()->list(['settlement_id' => 'batch']));
        self::assertSame('1.024566778899001', $row['exchange_rate']);
        self::assertSame('-10.00', $row['net_amount']);
        $fees = $row['fee_details_list'];
        self::assertIsArray($fees);
        self::assertIsArray($fees[0]);
        self::assertSame('0.33', $fees[0]['settlement_total_amount']);
        $wallet = self::firstRow($sdk->finance()->balanceHistory()->list(['page' => '0', 'page_size' => 2000]));
        self::assertSame('-945.33', $wallet['amount']);
        self::assertSame('1024.345', $wallet['balance']);
        $conversion = $sdk->finance()->transfers()->retrieve('payout_1')['conversion'];
        self::assertIsArray($conversion);
        self::assertSame('1.010001', $conversion['rate']);
        self::assertSame('merchant-account', $http->requests[0]->getHeaderLine('x-login-as'));
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
            self::assertSame('2026-08-21', $request->getHeaderLine('x-api-version'));
            self::assertSame('', $request->getHeaderLine('x-on-behalf-of'));
        }
        self::assertSame([
            '/api/v1/pa/payment_disputes/dst_1',
            '/api/v1/pa/payment_disputes?page=a%2Bb',
            '/api/v1/deposits/dep_1',
            '/api/v1/deposits?page_num=2&page_size=100',
            '/api/v1/pa/financial/settlements/batch',
            '/api/v1/pa/financial/settlements?currency=EUR&from_settled_at=2026-11-01'
                . '&to_settled_at=2026-11-02&status=SETTLED&page_num=1',
            '/api/v1/pa/settlement_records?settlement_id=batch',
            '/api/v1/balances/history?page=0&page_size=2000',
            '/api/v1/transfers/payout_1',
        ], array_map(
            static fn (RequestInterface $request): string =>
                substr((string) $request->getUri(), strlen('https://api.sandbox.airwallex.com')),
            array_slice($http->requests, 1),
        ));
    }

    /**
     * @param array<array-key, mixed> $response
     * @return array<array-key, mixed>
     */
    private static function firstRow(array $response): array
    {
        $items = $response['items'];
        self::assertIsArray($items);
        self::assertIsArray($items[0]);
        return $items[0];
    }

    public function testRejectsIncompatiblePaginationAndUnboundedSettlementRecordsBeforeLogin(): void
    {
        $http = $this->scenario([]);
        $sdk = $this->client($http);
        foreach (
            [
            fn () => $sdk->deposits()->list(['page_num' => -1]),
            fn () => $sdk->deposits()->list(['account_id' => 'different-account']),
            fn () => $sdk->payments()->paymentDisputes()->list(['from_updated_at' => 'invalid']),
            fn () => $sdk->payments()->settlementRecords()->list(),
            fn () => $sdk->payments()->settlementRecords()->list(['settlement_id' => 'batch', 'page_size' => 51]),
            fn () => $sdk->finance()->balanceHistory()->list(['page' => '0', 'page_num' => 0]),
            fn () => $sdk->finance()->settlements()->list([]),
            ] as $invalid
        ) {
            try {
                $invalid();
                self::fail('Read must reject invalid query before login.');
            } catch (ValidationException) {
                self::assertSame([], $http->requests);
            }
        }
    }
}
