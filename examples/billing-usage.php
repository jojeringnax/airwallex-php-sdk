<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Authorize the owned Customer and persist each merchant_event_id, timestamp and properties before submission.
// Ingestion/void acceptance is not completed aggregation; retain async failures and original event identities.
return [
    'configure_meter' => static fn (Client $client, string $requestId): array => $client->billing()->meters()->create([
        'request_id' => $requestId, 'name' => 'Units consumed', 'event_name' => 'units_consumed',
        'aggregation_method' => 'SUM', 'aggregation_property' => 'quantity',
    ]),
    'record' => static fn (
        Client $client,
        string $customerId,
        string $merchantEventId,
        string $happenedAt,
        string $quantity,
    ): array => $client->billing()->usageEvents()->ingest([
        'billing_customer_id' => $customerId, 'event_name' => 'units_consumed',
        'merchant_event_id' => $merchantEventId, 'happened_at' => $happenedAt,
        'properties' => ['quantity' => $quantity],
    ]),
    // Separate explicit correction, never an automatic compensation for an uncertain ingest.
    'void' => static fn (Client $client, string $merchantEventId): array => $client->billing()->usageEvents()->void([
        'merchant_event_id' => $merchantEventId,
    ]),
];
