<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Authorize the customer, Price and collection context first; persist both action IDs before use.
// Creating a reusable template is separate from redemption on the resulting Subscription.
return static function (
    Client $client,
    string $customerId,
    string $priceId,
    string $couponRequestId,
    string $subscriptionRequestId,
): array {
    $coupon = $client->billing()->coupons()->create([
        'request_id' => $couponRequestId,
        'name' => 'Welcome discount',
        'discount_model' => 'PERCENTAGE',
        'percentage_off' => '20.000000000001',
        'duration_type' => 'CUSTOM',
        'duration' => ['period' => 3, 'period_unit' => 'MONTH'],
    ]);
    if (!is_string($coupon['id'] ?? null) || $coupon['id'] === '') {
        throw new RuntimeException('Coupon creation requires reconciliation before subscription creation.');
    }
    $subscription = $client->billing()->subscriptions()->create([
        'request_id' => $subscriptionRequestId,
        'billing_customer_id' => $customerId,
        'collection_method' => 'OUT_OF_BAND',
        'currency' => 'USD',
        'items' => [['price_id' => $priceId, 'quantity' => 1]],
        'discounts' => [['type' => 'COUPON', 'coupon' => ['id' => $coupon['id']]]],
    ]);
    if (!is_string($subscription['id'] ?? null) || $subscription['id'] === '') {
        throw new RuntimeException('Subscription creation requires reconciliation using its original identity.');
    }

    return ['coupon' => $coupon, 'subscription' => $client->billing()->subscriptions()->retrieve($subscription['id'])];
};
