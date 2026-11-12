<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Application authorizes the original Customer/Product/Meter, currency/cadence and financial actions first.
// Meter dimension_keys must include model; durable usage events keep their own merchant_event_id.
return [
    'create' => static fn (Client $client, string $productId, string $meterId, string $requestId): array =>
        $client->billing()->rateCards()->create([
            'request_id' => $requestId, 'name' => 'Usage pricing', 'product_id' => $productId, 'meter_id' => $meterId,
            'currency' => 'USD', 'recurring' => ['period' => 1, 'period_unit' => 'MONTH'],
            'dimension_keys' => ['model'],
            'items' => [
                ['dimension_values' => ['model' => 'standard'], 'pricing_model' => 'PER_UNIT',
                    'unit_amount' => '0.0000025000001'],
                ['dimension_values' => new stdClass(), 'pricing_model' => 'PER_UNIT', 'unit_amount' => '0.000001'],
            ],
        ]),
    'subscribe' => static fn (
        Client $client,
        string $customerId,
        string $rateCardId,
        int $version,
        string $requestId,
    ): array => $client->billing()->subscriptions()->create([
        'request_id' => $requestId, 'billing_customer_id' => $customerId, 'currency' => 'USD',
        'collection_method' => 'OUT_OF_BAND',
        'items' => [['rate_card_id' => $rateCardId, 'rate_card_version' => $version]],
    ]),
    // Verify the retained item belongs to this card and is the intended fallback before publishing.
    // Publishing does not update any subscription; moving an active pin can reset tier totals mid-cycle.
    'publish' => static fn (Client $client, string $cardId, string $fallbackItemId, string $requestId): array =>
        $client->billing()->rateCards()->createVersion($cardId, [
            'request_id' => $requestId,
            'items' => [
                ['dimension_values' => ['model' => 'standard'], 'pricing_model' => 'PER_UNIT',
                    'unit_amount' => '0.000003'],
                ['id' => $fallbackItemId],
            ],
        ]),
];
