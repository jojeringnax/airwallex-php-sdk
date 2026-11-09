<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Replace placeholders with durably associated action IDs before invoking with your configured client.
return static function (Client $client): array {
    $product = $client->billing()->products()->create([
        'request_id' => '<persisted-product-request-id>',
        'name' => 'Example service',
    ]);
    if (!isset($product['id']) || !is_string($product['id'])) {
        throw new RuntimeException('Expected a Product ID.');
    }
    $monthly = $client->billing()->prices()->create([
        'request_id' => '<persisted-monthly-price-request-id>',
        'product_id' => $product['id'],
        'currency' => 'EUR',
        'pricing_model' => 'FLAT',
        'flat_amount' => '19.90',
        'recurring' => ['period' => 1, 'period_unit' => 'MONTH'],
    ]);
    $perSeat = $client->billing()->prices()->create([
        'request_id' => '<persisted-seat-price-request-id>',
        'product_id' => $product['id'],
        'currency' => 'EUR',
        'pricing_model' => 'PER_UNIT',
        'unit_amount' => '2.50',
        'recurring' => ['period' => 1, 'period_unit' => 'MONTH'],
    ]);
    // VOLUME applies the matching tier's rate to the entire quantity.
    $volume = $client->billing()->prices()->create([
        'request_id' => '<persisted-volume-price-request-id>',
        'product_id' => $product['id'],
        'currency' => 'EUR',
        'pricing_model' => 'VOLUME',
        'metered' => false,
        'recurring' => ['period' => 1, 'period_unit' => 'MONTH'],
        'tiers' => [
            ['upper_bound' => '50', 'unit_amount' => '2.50'],
            ['unit_amount' => '2.00'],
        ],
    ]);
    // GRADUATED charges each portion at its tier's rate; the final tier has no upper_bound.
    $graduated = $client->billing()->prices()->create([
        'request_id' => '<persisted-graduated-price-request-id>',
        'product_id' => $product['id'],
        'currency' => 'EUR',
        'pricing_model' => 'GRADUATED',
        'metered' => false,
        'recurring' => null,
        'tiers' => [
            ['upper_bound' => '50', 'unit_amount' => '2.50', 'flat_amount' => '0.00'],
            ['unit_amount' => '2.00', 'flat_amount' => '5.00'],
        ],
    ]);
    return [
        'product' => $product, 'monthly' => $monthly, 'per_seat' => $perSeat,
        'volume' => $volume, 'graduated' => $graduated,
    ];
};
