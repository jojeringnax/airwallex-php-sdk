<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Closure;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BillingItemReadsTest extends HttpTestCase
{
    private const string LOGIN = '{"token":"fixture-token","expires_at":"2030-01-01T00:00:00Z"}';

    public function testConsumerRecipeExecutesAllFourAcceptedNamedArgumentCalls(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [200, '{"id":"si /+?#%","subscription_id":"sub /+?#%","quantity":3.000000000000000001}'],
            [200, '{"items":[],"page_after":"next /+","page_before":null}'],
            [200, '{"id":"li /+?#%","invoice_id":"inv /+?#%","amount":19.900000000000000001}'],
            [200, '{"items":[],"page_after":null,"page_before":null}'],
        ]);
        $recipe = require dirname(__DIR__, 3) . '/examples/billing-item-reads.php';
        self::assertInstanceOf(Closure::class, $recipe);
        $result = $recipe($this->client($http), 'sub /+?#%', 'si /+?#%', 'inv /+?#%', 'li /+?#%');
        self::assertSame([
            'subscription_item' => [
                'id' => 'si /+?#%', 'subscription_id' => 'sub /+?#%', 'quantity' => '3.000000000000000001',
            ],
            'subscription_items' => ['items' => [], 'page_after' => 'next /+', 'page_before' => null],
            'invoice_line_item' => [
                'id' => 'li /+?#%', 'invoice_id' => 'inv /+?#%', 'amount' => '19.900000000000000001',
            ],
            'invoice_line_items' => ['items' => [], 'page_after' => null, 'page_before' => null],
        ], $result);
        self::assertCount(5, $http->requests);
        $paths = [
            'subscriptions/sub%20%2F%2B%3F%23%25/items/si%20%2F%2B%3F%23%25',
            'subscriptions/sub%20%2F%2B%3F%23%25/items?page_size=20',
            'invoices/inv%20%2F%2B%3F%23%25/line_items/li%20%2F%2B%3F%23%25',
            'invoices/inv%20%2F%2B%3F%23%25/line_items?page_size=20',
        ];
        foreach ($paths as $index => $path) {
            $request = $http->requests[$index + 1];
            self::assertSame('https://api.sandbox.airwallex.com/api/v1/billing/' . $path, (string) $request->getUri());
            self::assertSame('GET', $request->getMethod());
            self::assertSame('', (string) $request->getBody());
            self::assertSame('Bearer fixture-token', $request->getHeaderLine('Authorization'));
            self::assertSame('', $request->getHeaderLine('x-on-behalf-of'));
            self::assertSame('2026-08-21', $request->getHeaderLine('x-api-version'));
        }
    }

    /** @param Closure(Client,string,string):array<array-key,mixed> $read */
    #[DataProvider('invalidIdentifiers')]
    public function testInvalidParentOrChildIdFailsBeforeAuthentication(Closure $read, string $id, string $itemId): void
    {
        $http = $this->scenario([]);
        try {
            $read($this->client($http), $id, $itemId);
            self::fail('Expected invalid identifier rejection.');
        } catch (ValidationException) {
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string,array{Closure(Client,string,string):array<array-key,mixed>,string,string}> */
    public static function invalidIdentifiers(): iterable
    {
        foreach (self::reads() as $name => [$read]) {
            foreach (['', '.', '..', "bad\x00id", "bad\nid", "bad\x7fid"] as $index => $invalid) {
                yield $name . '-parent-' . $index => [$read, $invalid, 'item'];
                if (str_contains($name, 'retrieve')) {
                    yield $name . '-child-' . $index => [$read, 'parent', $invalid];
                }
            }
        }
    }

    /** @param Closure(Client,string,string):array<array-key,mixed> $read */
    #[DataProvider('reads')]
    public function testDelegatedPaymentsContextCannotReadBillingItems(Closure $read): void
    {
        $http = $this->scenario([]);
        try {
            $read($this->client($http)->forConnectedAccount('merchant-A'), 'parent', 'item');
            self::fail('Expected Billing account-context rejection.');
        } catch (ConfigurationException) {
            self::assertSame([], $http->requests);
        }
    }

    /** @param Closure(Client,string,string):array<array-key,mixed> $read */
    #[DataProvider('providerFailures')]
    public function testProviderFailuresPreserveStatusWithoutHiddenReads(Closure $read, int $status): void
    {
        $http = $this->scenario([[201, self::LOGIN], [$status, '{"code":"fixture_rejection"}']]);
        try {
            $read($this->client($http), 'parent', 'item');
            self::fail('Expected provider failure.');
        } catch (ApiException $error) {
            self::assertSame($status, $error->httpStatus());
            self::assertSame('fixture_rejection', $error->providerCode());
            self::assertCount(2, $http->requests);
        }
    }

    /** @return iterable<string,array{Closure(Client,string,string):array<array-key,mixed>,int}> */
    public static function providerFailures(): iterable
    {
        foreach (self::reads() as $name => [$read]) {
            foreach ([400, 403, 404] as $status) {
                yield $name . '-' . $status => [$read, $status];
            }
        }
    }

    /** @return iterable<string,array{Closure(Client,string,string):array<array-key,mixed>}> */
    public static function reads(): iterable
    {
        yield 'subscriptions-retrieve' => [static fn (Client $client, string $id, string $itemId): array =>
            $client->billing()->subscriptions()->retrieveItem(id: $id, itemId: $itemId)];
        yield 'subscriptions-list' => [static fn (Client $client, string $id, string $itemId): array =>
            $client->billing()->subscriptions()->listItems(id: $id)];
        yield 'invoices-retrieve' => [static fn (Client $client, string $id, string $itemId): array =>
            $client->billing()->invoices()->retrieveLineItem(id: $id, itemId: $itemId)];
        yield 'invoices-list' => [static fn (Client $client, string $id, string $itemId): array =>
            $client->billing()->invoices()->listLineItems(id: $id)];
    }

    /** @param Closure(Client,string,array<string,mixed>):array<array-key,mixed> $list */
    #[DataProvider('lists')]
    public function testExplicitPagesRetainParentQueryAndTokenAndSurfaceNextPageFailure(Closure $list): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [200, '{"items":[{"id":"item"}],"page_after":"next /+","page_before":null}'],
            [200, '{"items":[],"page_after":null,"page_before":"previous /+"}'],
            [403, '{"code":"access_denied"}'],
        ]);
        $client = $this->client($http);
        $query = ['page_size' => 1];
        $first = $list($client, 'parent /+', $query);
        self::assertSame(['items' => [['id' => 'item']], 'page_after' => 'next /+', 'page_before' => null], $first);
        self::assertCount(2, $http->requests);
        $nextQuery = $query + ['page' => $first['page_after']];
        $last = $list($client, 'parent /+', $nextQuery);
        self::assertSame(['items' => [], 'page_after' => null, 'page_before' => 'previous /+'], $last);
        self::assertCount(3, $http->requests);
        self::assertSame(['page_size' => 1], $query);
        $firstUri = (string) $http->requests[1]->getUri();
        self::assertStringContainsString('/parent%20%2F%2B/', $firstUri);
        self::assertStringEndsWith('?page_size=1', $firstUri);
        self::assertSame($firstUri . '&page=next%20%2F%2B', (string) $http->requests[2]->getUri());
        try {
            $list($client, 'parent /+', $query + ['page' => $last['page_before']]);
            self::fail('Expected failed explicit previous page.');
        } catch (ApiException $error) {
            self::assertSame(403, $error->httpStatus());
            self::assertSame('access_denied', $error->providerCode());
            self::assertCount(4, $http->requests);
            self::assertSame($firstUri . '&page=previous%20%2F%2B', (string) $http->requests[3]->getUri());
        }
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('Bearer fixture-token', $request->getHeaderLine('Authorization'));
            self::assertSame('', $request->getHeaderLine('x-on-behalf-of'));
        }
    }

    /**
     * @param Closure(Client,string,array<string,mixed>):array<array-key,mixed> $list
     * @param array<string,mixed> $query
     */
    #[DataProvider('invalidQueries')]
    public function testInvalidPaginationOrUnsupportedFiltersFailBeforeAuthentication(Closure $list, array $query): void
    {
        $http = $this->scenario([]);
        try {
            $list($this->client($http), 'parent', $query);
            self::fail('Expected invalid list query rejection.');
        } catch (ValidationException) {
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string,array{Closure(Client,string,array<string,mixed>):array<array-key,mixed>,array<string,mixed>}> */
    public static function invalidQueries(): iterable
    {
        foreach (self::lists() as $name => [$list]) {
            foreach (
                [['page_size' => 0], ['page_size' => 1001], ['page_size' => '1'], ['page' => null],
                ['status' => 'ACTIVE']] as $index => $query
            ) {
                yield $name . '-' . $index => [$list, $query];
            }
        }
    }

    /** @return iterable<string,array{Closure(Client,string,array<string,mixed>):array<array-key,mixed>}> */
    public static function lists(): iterable
    {
        yield 'subscriptions' => [static fn (Client $client, string $id, array $query): array =>
            $client->billing()->subscriptions()->listItems(id: $id, query: $query)];
        yield 'invoices' => [static fn (Client $client, string $id, array $query): array =>
            $client->billing()->invoices()->listLineItems(id: $id, query: $query)];
    }
}
