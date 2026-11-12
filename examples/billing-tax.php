<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Application approves the mode/rate, verifies owned IDs and prepares account tax registrations,
// Customer location/tax identity, Product tax category and Price tax-inclusion metadata first.
return [
    'manual_invoice' => static function (
        Client $client,
        string $customerId,
        string $priceId,
        string $rate,
        string $createRequestId,
        string $itemsRequestId,
    ): array {
        $invoice = $client->billing()->invoices()->create([
            'request_id' => $createRequestId, 'billing_customer_id' => $customerId, 'currency' => 'USD',
            'collection_method' => 'OUT_OF_BAND', 'enable_automatic_tax' => false, 'default_tax_percent' => $rate,
        ]);
        if (!is_string($invoice['id'] ?? null) || $invoice['id'] === '') {
            throw new RuntimeException('Reconcile the original Invoice command before adding items.');
        }
        $client->billing()->invoices()->addLineItems($invoice['id'], [
            'request_id' => $itemsRequestId, 'line_items' => [['price_id' => $priceId, 'quantity' => 1]],
        ]);

        return $client->billing()->invoices()->retrieve($invoice['id']);
    },
    'automatic_subscription' => static function (
        Client $client,
        string $customerId,
        string $priceId,
        string $requestId,
    ): array {
        // This flag requests calculation; inspect provider status/results instead of assuming success.
        return $client->billing()->subscriptions()->create([
            'request_id' => $requestId, 'billing_customer_id' => $customerId, 'currency' => 'USD',
            'collection_method' => 'OUT_OF_BAND', 'enable_automatic_tax' => true,
            'items' => [['price_id' => $priceId, 'quantity' => 1]],
        ]);
    },
];
