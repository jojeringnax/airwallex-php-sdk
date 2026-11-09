<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Authorize the buyer and load owned Subscription/Item IDs and persisted action IDs before calling.
// Amounts are application-chosen decimal strings in the Subscription's billing currency.
return [
    'custom_adjustment' => static function (
        Client $billing,
        string $subscriptionId,
        string $itemId,
        int $quantity,
        string $chargeAmount,
        string $creditAmount,
        string $requestId,
    ): array {
        return $billing->billing()->subscriptions()->update($subscriptionId, [
            'request_id' => $requestId,
            'items' => [[
                'id' => $itemId,
                'quantity' => $quantity,
                'proration_mode' => 'CUSTOM',
                'proration_value' => ['charge_amount' => $chargeAmount, 'credit_amount' => $creditAmount],
            ]],
            'billing_action' => 'IMMEDIATE_CHARGE_AND_KEEP_CYCLE',
        ]);
    },
    'prorate_at' => static function (
        Client $billing,
        string $subscriptionId,
        string $itemId,
        int $quantity,
        string $prorationDate,
        string $requestId,
    ): array {
        // Choose a timestamp inside the current billing period; the provider checks current state.
        return $billing->billing()->subscriptions()->update($subscriptionId, [
            'request_id' => $requestId,
            'items' => [['id' => $itemId, 'quantity' => $quantity]],
            'default_proration_mode' => 'PRORATED',
            'proration_date' => $prorationDate,
            'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE',
        ]);
    },
];
