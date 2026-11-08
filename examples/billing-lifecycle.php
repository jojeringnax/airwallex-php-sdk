<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Application recipes: load the stored buyer/organization/collector mapping and authorize each action first.
// Choose one named recipe for the intended action; these are alternatives, not an automatic sequence.
// Every request ID must already be durably associated with that action in application storage.
return [
    'start_trial' => static function (
        Client $billing,
        string $customerId,
        string $sourceId,
        string $legalEntityId,
        string $collectionAccountId,
        string $priceId,
        string $requestId,
        string $startsAt,
        string $trialEndsAt,
    ): array {
        return $billing->billing()->subscriptions()->create([
            'request_id' => $requestId,
            'billing_customer_id' => $customerId,
            'payment_source_id' => $sourceId,
            'legal_entity_id' => $legalEntityId,
            'linked_payment_account_id' => $collectionAccountId,
            'collection_method' => 'AUTO_CHARGE',
            'items' => [['price_id' => $priceId, 'quantity' => 1]],
            'starts_at' => $startsAt,
            'trial_ends_at' => $trialEndsAt,
        ]);
    },
    'change_quantity' => static function (
        Client $billing,
        string $subscriptionId,
        string $itemId,
        int $quantity,
        string $requestId,
    ): array {
        return $billing->billing()->subscriptions()->update($subscriptionId, [
            'request_id' => $requestId,
            'items' => [['id' => $itemId, 'quantity' => $quantity]],
            'default_proration_mode' => 'PRORATED',
            'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE',
        ]);
    },
    'replace_price' => static function (
        Client $billing,
        string $subscriptionId,
        string $oldItemId,
        string $newPriceId,
        int $quantity,
        string $requestId,
    ): array {
        return $billing->billing()->subscriptions()->update($subscriptionId, [
            'request_id' => $requestId,
            'items' => [
                ['id' => $oldItemId, 'deleted' => true],
                ['price_id' => $newPriceId, 'quantity' => $quantity],
            ],
            'default_proration_mode' => 'NONE',
            'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE',
        ]);
    },
    'schedule_end' => static function (Client $billing, string $subscriptionId, string $requestId): array {
        return $billing->billing()->subscriptions()->update($subscriptionId, [
            'request_id' => $requestId,
            'cancel_at_period_end' => true,
        ]);
    },
    'cancel_now' => static function (Client $billing, string $subscriptionId, string $requestId): array {
        // NONE explicitly selects no unused-time refund. Other proration choices require deliberate selection.
        return $billing->billing()->subscriptions()->cancel($subscriptionId, [
            'request_id' => $requestId,
            'proration_behavior' => 'NONE',
        ]);
    },
    'replace_source_and_observe' => static function (
        Client $billing,
        string $subscriptionId,
        string $replacementSourceId,
        string $requestId,
    ): array {
        // Verify source/customer/collector ownership first: changing this field can retry a failed invoice now.
        $billing->billing()->subscriptions()->update($subscriptionId, [
            'request_id' => $requestId,
            'payment_source_id' => $replacementSourceId,
        ]);
        $subscription = $billing->billing()->subscriptions()->retrieve($subscriptionId);
        $invoiceId = $subscription['latest_invoice_id'] ?? null;
        $invoice = is_string($invoiceId) && $invoiceId !== ''
            ? $billing->billing()->invoices()->retrieve($invoiceId)
            : null;
        return ['subscription' => $subscription, 'invoice' => $invoice];
    },
    'read_invoice' => static function (Client $billing, string $invoiceId): array {
        // Authorize the buyer before returning any PDF/hosted link. Null links are valid observations.
        // Download, storage, delivery and expired-link recovery belong to the application.
        return $billing->billing()->invoices()->retrieve($invoiceId);
    },
];
