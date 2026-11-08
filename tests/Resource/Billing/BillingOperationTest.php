<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Closure;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BillingOperationTest extends HttpTestCase
{
    /** @param Closure(Client):array<array-key,mixed> $call */
    #[DataProvider('operations')]
    public function testEveryAcceptedOperationUsesItsVerifiedRouteAndContext(
        Closure $call,
        string $path,
        string $method,
        ?string $expectedBody,
    ): void {
        $http = $this->scenario(
            [
                [
                    201,
                    '{"token":"fixture-token","expires_at":"2030-01-01T00:00:00Z"}',
                ],
                [200, '{"id":"fixture-resource","future_field":"preserved"}'],
            ]
        );
        self::assertSame(
            ['id' => 'fixture-resource', 'future_field' => 'preserved'],
            $call($this->client($http))
        );
        self::assertCount(2, $http->requests);
        $request = $http->requests[1];
        self::assertSame(
            'https://api.sandbox.airwallex.com/api/v1/billing/' . $path,
            (string) $request->getUri()
        );
        self::assertSame($method, $request->getMethod());
        self::assertSame($expectedBody ?? '', (string) $request->getBody());
        self::assertSame(
            $expectedBody === null ? '' : 'application/json',
            $request->getHeaderLine('Content-Type')
        );
        self::assertSame('', $request->getHeaderLine('x-on-behalf-of'));
        self::assertSame('2026-08-21', $request->getHeaderLine('x-api-version'));
    }
    /** @return iterable<string,array{Closure(Client):array<array-key,mixed>,string,string,?string}> */
    public static function operations(): iterable
    {
        yield 'customers-retrieve' => [
            static fn(Client $client): array => $client->billing()->customers()->retrieve('res/encoded'),
            'billing_customers/res%2Fencoded',
            'GET',
            null,
        ];
        yield 'customers-list' => [
            static fn(Client $client): array =>
                $client->billing()->customers()->list(['page_size' => 2, 'page' => 'a+b /']),
            'billing_customers?page_size=2&page=a%2Bb%20%2F',
            'GET',
            null,
        ];
        yield 'customers-create' => [
            static fn(Client $client): array => $client->billing()->customers()->create(
                [
                    'request_id' => 'stable',
                    'email' => 'buyer@example.test',
                    'default_legal_entity_id' => 'le_test',
                ]
            ),
            'billing_customers/create',
            'POST',
            (new JsonCodec())->encode(
                [
                    'request_id' => 'stable',
                    'email' => 'buyer@example.test',
                    'default_legal_entity_id' => 'le_test',
                ],
                ['/unit_amount']
            ),
        ];
        yield 'customers-update' => [
            static fn(Client $client): array =>
                $client->billing()->customers()->update('res_test', ['email' => null, 'name' => '']),
            'billing_customers/res_test/update',
            'POST',
            (new JsonCodec())->encode(['email' => null, 'name' => ''], ['/unit_amount']),
        ];
        yield 'products-retrieve' => [
            static fn(Client $client): array => $client->billing()->products()->retrieve('res/encoded'),
            'products/res%2Fencoded',
            'GET',
            null,
        ];
        yield 'products-list' => [
            static fn(Client $client): array =>
                $client->billing()->products()->list(['page_size' => 2, 'page' => 'a+b /']),
            'products?page_size=2&page=a%2Bb%20%2F',
            'GET',
            null,
        ];
        yield 'products-create' => [
            static fn(Client $client): array =>
                $client->billing()->products()->create(['request_id' => 'stable', 'name' => 'Example service']),
            'products/create',
            'POST',
            (new JsonCodec())->encode(
                ['request_id' => 'stable', 'name' => 'Example service'],
                ['/unit_amount']
            ),
        ];
        yield 'products-update' => [
            static fn(Client $client): array => $client->billing()->products()->update('res_test', ['active' => false]),
            'products/res_test/update',
            'POST',
            (new JsonCodec())->encode(['active' => false], ['/unit_amount']),
        ];
        yield 'prices-retrieve' => [
            static fn(Client $client): array => $client->billing()->prices()->retrieve('res/encoded'),
            'prices/res%2Fencoded',
            'GET',
            null,
        ];
        yield 'prices-list' => [
            static fn(Client $client): array =>
                $client->billing()->prices()->list(['page_size' => 2, 'page' => 'a+b /']),
            'prices?page_size=2&page=a%2Bb%20%2F',
            'GET',
            null,
        ];
        yield 'prices-create' => [
            static fn(Client $client): array => $client->billing()->prices()->create(
                [
                    'request_id' => 'stable',
                    'product_id' => 'prd_test',
                    'currency' => 'EUR',
                    'unit_amount' => '19.90',
                    'recurring' => ['period_unit' => 'MONTH', 'period' => 1],
                ]
            ),
            'prices/create',
            'POST',
            (new JsonCodec())->encode(
                [
                    'request_id' => 'stable',
                    'product_id' => 'prd_test',
                    'currency' => 'EUR',
                    'unit_amount' => '19.90',
                    'recurring' => ['period_unit' => 'MONTH', 'period' => 1],
                ],
                ['/unit_amount']
            ),
        ];
        yield 'prices-update' => [
            static fn(Client $client): array =>
                $client->billing()->prices()->update('res_test', ['active' => false, 'description' => null]),
            'prices/res_test/update',
            'POST',
            (new JsonCodec())->encode(['active' => false, 'description' => null], ['/unit_amount']),
        ];
        yield 'paymentSources-retrieve' => [
            static fn(Client $client): array => $client->billing()->paymentSources()->retrieve('res/encoded'),
            'payment_sources/res%2Fencoded',
            'GET',
            null,
        ];
        yield 'paymentSources-list' => [
            static fn(Client $client): array =>
                $client->billing()->paymentSources()->list(['page_size' => 2, 'page' => 'a+b /']),
            'payment_sources?page_size=2&page=a%2Bb%20%2F',
            'GET',
            null,
        ];
        yield 'paymentSources-create' => [
            static fn(Client $client): array => $client->billing()->paymentSources()->create(
                [
                    'request_id' => 'stable',
                    'billing_customer_id' => 'bcus_test',
                    'external_id' => 'method_test',
                    'linked_payment_account_id' => 'acct_test',
                ]
            ),
            'payment_sources/create',
            'POST',
            (new JsonCodec())->encode(
                [
                    'request_id' => 'stable',
                    'billing_customer_id' => 'bcus_test',
                    'external_id' => 'method_test',
                    'linked_payment_account_id' => 'acct_test',
                ],
                ['/unit_amount']
            ),
        ];
        yield 'checkouts-retrieve' => [
            static fn(Client $client): array => $client->billing()->checkouts()->retrieve('res/encoded'),
            'billing_checkouts/res%2Fencoded',
            'GET',
            null,
        ];
        yield 'checkouts-create' => [
            static fn(Client $client): array => $client->billing()->checkouts()->create(
                [
                    'request_id' => 'stable',
                    'mode' => 'SETUP',
                    'currency' => 'EUR',
                    'billing_customer_id' => 'bcus_test',
                    'ui_mode' => 'ELEMENTS',
                ]
            ),
            'billing_checkouts/create',
            'POST',
            (new JsonCodec())->encode(
                [
                    'request_id' => 'stable',
                    'mode' => 'SETUP',
                    'currency' => 'EUR',
                    'billing_customer_id' => 'bcus_test',
                    'ui_mode' => 'ELEMENTS',
                ],
                ['/unit_amount']
            ),
        ];
        yield 'checkouts-update' => [
            static fn(Client $client): array =>
                $client->billing()->checkouts()->update('res_test', ['metadata' => ['order' => '0019.90']]),
            'billing_checkouts/res_test/update',
            'POST',
            (new JsonCodec())->encode(['metadata' => ['order' => '0019.90']], ['/unit_amount']),
        ];
        yield 'checkouts-cancel' => [
            static fn(Client $client): array => $client->billing()->checkouts()->cancel('res_test'),
            'billing_checkouts/res_test/cancel',
            'POST',
            null,
        ];
        yield 'subscriptions-retrieve' => [
            static fn(Client $client): array => $client->billing()->subscriptions()->retrieve('res/encoded'),
            'subscriptions/res%2Fencoded',
            'GET',
            null,
        ];
        yield 'subscriptions-list' => [
            static fn(Client $client): array =>
                $client->billing()->subscriptions()->list(['page_size' => 2, 'page' => 'a+b /']),
            'subscriptions?page_size=2&page=a%2Bb%20%2F',
            'GET',
            null,
        ];
        yield 'subscriptions-create' => [
            static fn(Client $client): array => $client->billing()->subscriptions()->create(
                [
                    'request_id' => 'stable',
                    'billing_customer_id' => 'bcus_test',
                    'collection_method' => 'AUTO_CHARGE',
                    'payment_source_id' => 'source_test',
                    'items' => [['price_id' => 'pri_test', 'quantity' => 1]],
                ]
            ),
            'subscriptions/create',
            'POST',
            (new JsonCodec())->encode(
                [
                    'request_id' => 'stable',
                    'billing_customer_id' => 'bcus_test',
                    'collection_method' => 'AUTO_CHARGE',
                    'payment_source_id' => 'source_test',
                    'items' => [['price_id' => 'pri_test', 'quantity' => 1]],
                ],
                ['/unit_amount']
            ),
        ];
        yield 'subscriptions-update' => [
            static fn(Client $client): array => $client->billing()->subscriptions()->update(
                'res_test',
                [
                    'request_id' => 'stable',
                    'items' => [['id' => 'item_test', 'quantity' => 3]],
                    'default_proration_mode' => 'PRORATED',
                ]
            ),
            'subscriptions/res_test/update',
            'POST',
            (new JsonCodec())->encode(
                [
                    'request_id' => 'stable',
                    'items' => [['id' => 'item_test', 'quantity' => 3]],
                    'default_proration_mode' => 'PRORATED',
                ],
                ['/unit_amount']
            ),
        ];
        yield 'subscriptions-cancel' => [
            static fn(Client $client): array => $client->billing()->subscriptions()->cancel(
                'res_test',
                ['request_id' => 'stable', 'proration_behavior' => 'NONE']
            ),
            'subscriptions/res_test/cancel',
            'POST',
            (new JsonCodec())->encode(
                ['request_id' => 'stable', 'proration_behavior' => 'NONE'],
                ['/unit_amount']
            ),
        ];
        yield 'subscriptions-retrieveItem' => [
            static fn(Client $client): array =>
                $client->billing()->subscriptions()->retrieveItem('res/encoded', 'item/encoded'),
            'subscriptions/res%2Fencoded/items/item%2Fencoded',
            'GET',
            null,
        ];
        yield 'subscriptions-listItems' => [
            static fn(Client $client): array =>
                $client->billing()->subscriptions()->listItems('res_test', ['page_size' => 1]),
            'subscriptions/res_test/items?page_size=1',
            'GET',
            null,
        ];
        yield 'invoices-retrieve' => [
            static fn(Client $client): array => $client->billing()->invoices()->retrieve('res/encoded'),
            'invoices/res%2Fencoded',
            'GET',
            null,
        ];
        yield 'invoices-list' => [
            static fn(Client $client): array =>
                $client->billing()->invoices()->list(['page_size' => 2, 'page' => 'a+b /']),
            'invoices?page_size=2&page=a%2Bb%20%2F',
            'GET',
            null,
        ];
        yield 'invoices-retrieveLineItem' => [
            static fn(Client $client): array =>
                $client->billing()->invoices()->retrieveLineItem('inv/encoded', 'item/encoded'),
            'invoices/inv%2Fencoded/line_items/item%2Fencoded',
            'GET',
            null,
        ];
        yield 'invoices-listLineItems' => [
            static fn(Client $client): array =>
                $client->billing()->invoices()->listLineItems('inv_test', ['page_size' => 1]),
            'invoices/inv_test/line_items?page_size=1',
            'GET',
            null,
        ];
    }
}
