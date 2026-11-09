<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Authorize the buyer and resolve these stored IDs within the owning Billing organization/environment first.
// Each call reads exactly the selected resource or one page; no mutations or automatic page traversal.
return static function (
    Client $billing,
    string $subscriptionId,
    string $subscriptionItemId,
    string $invoiceId,
    string $invoiceLineItemId,
): array {
    return [
        'subscription_item' => $billing->billing()->subscriptions()->retrieveItem(
            id: $subscriptionId,
            itemId: $subscriptionItemId,
        ),
        'subscription_items' => $billing->billing()->subscriptions()->listItems(
            id: $subscriptionId,
            query: ['page_size' => 20],
        ),
        'invoice_line_item' => $billing->billing()->invoices()->retrieveLineItem(
            id: $invoiceId,
            itemId: $invoiceLineItemId,
        ),
        'invoice_line_items' => $billing->billing()->invoices()->listLineItems(
            id: $invoiceId,
            query: ['page_size' => 20],
        ),
    ];
};
