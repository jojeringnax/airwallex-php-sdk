<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Examples;

use Closure;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExplicitPagesTest extends HttpTestCase
{
    private const string LOGIN = '{"token":"fixture-token","expires_at":"2030-01-01T00:00:00Z"}';

    private function recipe(string $name): Closure
    {
        $recipes = require dirname(__DIR__, 2) . '/examples/explicit-pages.php';
        self::assertIsArray($recipes);
        self::assertInstanceOf(Closure::class, $recipes[$name]);
        return $recipes[$name];
    }

    public function testPublicBillingPagesRetainAllNonPageFiltersAndNativeBookmarks(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [200, '{"items":[{"id":"buyer"}],"page_after":"next /+","page_before":null}'],
            [200, '{"items":[],"page_after":null,"page_before":"previous"}'],
        ]);
        $client = $this->client($http);
        $filters = ['page_size' => 2, 'email' => 'buyer+tag@example.test',
            'from_created_at' => '2028-01-01T00:00:00.123456Z'];
        $original = $filters;
        $fetch = $this->recipe('billing_customers');
        $first = $fetch($client, $filters);
        self::assertIsArray($first);
        self::assertSame('next /+', $first['page_after']);
        self::assertNull($first['page_before']);
        self::assertCount(2, $http->requests); // No implicit follow-up to a nonterminal page.
        $next = $fetch($client, $filters, $first['page_after']);
        self::assertSame(['items' => [], 'page_after' => null, 'page_before' => 'previous'], $next);
        self::assertSame($original, $filters);
        $base = '?page_size=2&email=buyer%2Btag%40example.test&from_created_at=2028-01-01T00%3A00%3A00.123456Z';
        self::assertStringEndsWith($base, (string) $http->requests[1]->getUri());
        self::assertStringEndsWith($base . '&page=next%20%2F%2B', (string) $http->requests[2]->getUri());
        self::assertSame('', $http->requests[2]->getHeaderLine('x-on-behalf-of'));
        self::assertSame(
            $http->requests[1]->getHeaderLine('Authorization'),
            $http->requests[2]->getHeaderLine('Authorization')
        );
        self::assertCount(3, $http->requests);
    }

    public function testPublicPaymentsPagesRetainZeroPageAccountFiltersAndTerminalFlag(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN], [200, '{"items":[{"id":"intent","amount":19.90}],"has_more":true}'],
            [200, '{"items":[],"has_more":false}'],
        ]);
        $client = $this->client($http)->forConnectedAccount('account-A');
        $filters = ['page_size' => 10, 'currency' => 'EUR', 'merchant_order_id' => 'order +/', 'status' => 'SUCCEEDED'];
        $original = $filters;
        $fetch = $this->recipe('payment_intents');
        $first = $fetch($client, $filters, 0);
        self::assertIsArray($first);
        self::assertTrue($first['has_more']);
        self::assertCount(2, $http->requests);
        self::assertSame(['items' => [], 'has_more' => false], $fetch($client, $filters, 1));
        self::assertSame($original, $filters);
        $base = '?page_size=10&currency=EUR&merchant_order_id=order%20%2B%2F&status=SUCCEEDED&page_num=';
        self::assertStringEndsWith($base . '0', (string) $http->requests[1]->getUri());
        self::assertStringEndsWith($base . '1', (string) $http->requests[2]->getUri());
        self::assertSame('account-A', $http->requests[1]->getHeaderLine('x-on-behalf-of'));
        self::assertSame('account-A', $http->requests[2]->getHeaderLine('x-on-behalf-of'));
        self::assertCount(3, $http->requests);
    }

    #[DataProvider('nextPageFailures')]
    public function testFailedExplicitNextPageSurfacesWithoutExtraRequests(string $family, int $status): void
    {
        $firstBody = $family === 'billing_customers'
            ? '{"items":[],"page_after":"next","page_before":null}'
            : '{"items":[],"has_more":true}';
        $http = $this->scenario([
            [201, self::LOGIN], [200, $firstBody], [$status, '{"code":"validation_error"}'],
        ]);
        $client = $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3));
        if ($family === 'payment_intents') {
            $client = $client->forConnectedAccount('account-A');
        }
        $fetch = $this->recipe($family);
        $fetch($client, ['page_size' => 2]);
        self::assertCount(2, $http->requests);
        try {
            $fetch($client, ['page_size' => 2], $family === 'billing_customers' ? 'next' : 1);
            self::fail('Expected next-page failure.');
        } catch (ApiException $error) {
            self::assertSame($status, $error->httpStatus());
            self::assertCount(3, $http->requests);
            self::assertStringEndsWith(
                $family === 'billing_customers' ? '&page=next' : '&page_num=1',
                (string) $http->requests[2]->getUri()
            );
        }
    }

    /** @return iterable<string,array{string,int}> */
    public static function nextPageFailures(): iterable
    {
        foreach (['billing_customers', 'payment_intents'] as $family) {
            foreach ([400, 403] as $status) {
                yield $family . '-' . $status => [$family, $status];
            }
        }
    }
}
