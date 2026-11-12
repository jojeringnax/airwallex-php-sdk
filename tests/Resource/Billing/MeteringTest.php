<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;

final class MeteringTest extends HttpTestCase
{
    private const string LOGIN = '{"token":"fixture-token","expires_at":"2130-01-01T00:00:00Z"}';

    public function testUsageRecipePreservesMerchantIdentityTimestampAndExactStringProperties(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"meter"}'], [201, '{"id":"event"}'], [202, '']]);
        $client = $this->client($http);
        $recipes = require dirname(__DIR__, 3) . '/examples/billing-usage.php';
        self::assertIsArray($recipes);
        self::assertInstanceOf(\Closure::class, $recipes['configure_meter']);
        self::assertInstanceOf(\Closure::class, $recipes['record']);
        self::assertInstanceOf(\Closure::class, $recipes['void']);
        $recipes['configure_meter']($client, 'meter-command');
        $recipes['record'](
            $client,
            'customer',
            'original-event',
            '2026-11-12T00:00:00.123456789Z',
            '9007199254740993.000001'
        );
        self::assertSame([], $recipes['void']($client, 'original-event'));
        $wire = (string) $http->requests[2]->getBody();
        self::assertStringContainsString('"merchant_event_id":"original-event"', $wire);
        self::assertStringContainsString('"quantity":"9007199254740993.000001"', $wire);
        self::assertStringContainsString('2026-11-12T00:00:00.123456789Z', $wire);
        self::assertSame('{"merchant_event_id":"original-event"}', (string) $http->requests[3]->getBody());
    }

    public function testBatchAcceptsTenEventsWithoutTreatingAcceptanceAsPerEventSuccess(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [202, '']]);
        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = ['billing_customer_id' => 'customer', 'event_name' => 'units',
                'merchant_event_id' => 'event-' . $i,
                'properties' => ['quantity' => '1.000000000001', 'meter_id' => 'opaque', 'discounts' => 'opaque']];
        }
        self::assertSame([], $this->client($http)->billing()->usageEvents()->batchIngest(['events' => $events]));
        self::assertStringContainsString('"merchant_event_id":"event-9"', (string) $http->requests[1]->getBody());
        $bad = $this->scenario([]);
        try {
            $this->client($bad)->billing()->usageEvents()->batchIngest(['events' => [...$events, $events[0]]]);
            self::fail('Batch limit was ignored.');
        } catch (ValidationException) {
            self::assertSame([], $bad->requests);
        }
    }

    public function testDuplicateOrUnknownMeterFailureRetainsOriginalEventWithoutReplay(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [400, '{"code":"validation_error"}']]);
        try {
            $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3))->billing()->usageEvents()->ingest([
                'billing_customer_id' => 'customer', 'event_name' => 'units', 'merchant_event_id' => 'original-event',
                'happened_at' => '2026-11-12T00:00:00Z', 'properties' => ['quantity' => '2'],
            ]);
            self::fail('Expected provider rejection.');
        } catch (ApiException $error) {
            self::assertSame(400, $error->httpStatus());
            self::assertCount(2, $http->requests);
            self::assertStringContainsString(
                '"merchant_event_id":"original-event"',
                (string) $http->requests[1]->getBody()
            );
        }
    }

    public function testSummaryQueriesUseRepeatedDimensionsDeepFiltersAndOriginalPageContext(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{"items":[{"value":9007199254740993.000001,'
            . '"event_count":2}],'
            . '"page_after":"next /+"}'], [200, '{"items":[]}']]);
        $meters = $this->client($http)->billing()->meters();
        $query = ['billing_customer_id' => 'customer', 'from_happened_at' => '2026-11-01T00:00:00Z',
            'to_happened_at' => '2026-11-02T00:00:00Z', 'grouped_by_dimensions' => ['model', 'kind'],
            'dimension_filters' => ['model' => 'a&other=true'], 'page_size' => 1];
        $page = $meters->listSummaries('meter /+', $query);
        self::assertIsArray($page['items']);
        self::assertIsArray($page['items'][0]);
        self::assertSame('9007199254740993.000001', $page['items'][0]['value']);
        self::assertSame(2, $page['items'][0]['event_count']);
        $meters->listSummaries('meter /+', [...$query, 'page' => $page['page_after']]);
        self::assertStringContainsString(
            'grouped_by_dimensions=model&grouped_by_dimensions=kind',
            (string) $http->requests[1]->getUri()
        );
        self::assertStringContainsString(
            'dimension_filters%5Bmodel%5D=a%26other%3Dtrue',
            (string) $http->requests[1]->getUri()
        );
        self::assertStringEndsWith('&page=next%20%2F%2B', (string) $http->requests[2]->getUri());
        self::assertStringContainsString('/meter%20%2F%2B/summaries?', (string) $http->requests[1]->getUri());
    }

    public function testMeterCoreLogicIsImmutableAndArchiveRestoreAreBodyless(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"meter"}'], [200, '{}'], [200, '{}'], [200, '{}'],
            [200, '{"id":"meter"}'], [200, '{"items":[]}']]);
        $meters = $this->client($http)->billing()->meters();
        $meters->create([
            'request_id' => 'count', 'name' => 'Count', 'event_name' => 'units', 'aggregation_method' => 'COUNT',
        ]);
        $meters->update('meter', ['name' => 'Renamed', 'dimension_keys' => ['model']]);
        $meters->archive('meter');
        $meters->restore('meter');
        self::assertSame('meter', $meters->retrieve('meter')['id']);
        self::assertSame([], $meters->list(['archived' => false])['items']);
        self::assertSame('', (string) $http->requests[3]->getBody());
        self::assertSame('', (string) $http->requests[4]->getBody());
        $bad = $this->scenario([]);
        try {
            $this->client($bad)->billing()->meters()->update('meter', ['aggregation_method' => 'SUM']);
            self::fail('Immutable aggregation changed.');
        } catch (ValidationException) {
            self::assertSame([], $bad->requests);
        }
    }

    public function testEmptyAcknowledgementDoesNotRelaxOtherResponseContracts(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [202, '']]);
        $this->expectException(ProtocolException::class);
        $this->client($http)->billing()->customers()->retrieve('customer');
    }

    public function testUsagePropertiesRequireStringsAndTimestampRequiresTimezone(): void
    {
        foreach ([['properties' => ['quantity' => 1.5]], ['happened_at' => '2026-11-12T00:00:00']] as $changes) {
            $http = $this->scenario([]);
            try {
                $this->client($http)->billing()->usageEvents()->ingest(array_replace([
                    'billing_customer_id' => 'customer', 'event_name' => 'units', 'merchant_event_id' => 'event',
                    'properties' => ['quantity' => '1'],
                ], $changes));
                self::fail('Invalid usage input accepted.');
            } catch (ValidationException) {
                self::assertSame([], $http->requests);
            }
        }
    }
}
