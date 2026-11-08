<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Run after authorizing the buyer and loading its stored organization/customer/collector mapping.
// Browser return alone is insufficient; retrieve provider state with the same owning Billing client.
return static function (
    Client $client,
    string $checkoutId,
    string $expectedBillingCustomerId,
    string $expectedCollectionAccountId,
): array {
    $checkout = $client->billing()->checkouts()->retrieve($checkoutId);
    if (
        ($checkout['mode'] ?? null) !== 'SETUP'
        || ($checkout['billing_customer_id'] ?? null) !== $expectedBillingCustomerId
        || ($checkout['linked_payment_account_id'] ?? null) !== $expectedCollectionAccountId
    ) {
        throw new RuntimeException('Checkout does not match the authorized buyer mapping.');
    }
    if (($checkout['status'] ?? null) !== 'COMPLETED') {
        return ['checkout' => $checkout, 'payment_source' => null];
    }
    $sourceId = $checkout['payment_source_id'] ?? null;
    if (!is_string($sourceId) || $sourceId === '') {
        throw new RuntimeException('Completed setup has no observed Billing Payment Source.');
    }
    $source = $client->billing()->paymentSources()->retrieve($sourceId);
    if (
        ($source['billing_customer_id'] ?? null) !== $expectedBillingCustomerId
        || ($source['linked_payment_account_id'] ?? null) !== $expectedCollectionAccountId
    ) {
        throw new RuntimeException('Payment Source does not match the authorized buyer mapping.');
    }
    // Persist mappings in application storage after review. Setup completion does not mean paid.
    // This recipe makes no charge, subscription, invoice, or access-entitlement decision.
    return ['checkout' => $checkout, 'payment_source' => $source];
};
