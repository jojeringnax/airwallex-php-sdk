<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Invoke only after authorizing the buyer and confirming the organization's entity/collector mapping.
// Replace all placeholders, including each durably persisted action ID, before provider requests.
return static function (Client $client): array {
    $customer = $client->billing()->customers()->create([
        'request_id' => '<persisted-customer-request-id>',
        'email' => 'buyer@example.test',
        'default_legal_entity_id' => '<legal-entity-id>',
    ]);
    if (!isset($customer['id']) || !is_string($customer['id'])) {
        throw new RuntimeException('Expected a Billing Customer ID.');
    }
    // Persist the customer ID with local buyer, organization and environment in application storage.
    $client->billing()->customers()->update($customer['id'], [
        'address' => ['country_code' => 'IT', 'city' => 'Example city'],
    ]);
    $observed = $client->billing()->customers()->retrieve($customer['id']);
    $checkout = $client->billing()->checkouts()->create([
        'request_id' => '<persisted-checkout-request-id>',
        'billing_customer_id' => $customer['id'],
        'legal_entity_id' => '<legal-entity-id>',
        'linked_payment_account_id' => '<eligible-collection-account-id>',
        'mode' => 'SUBSCRIPTION',
        'ui_mode' => 'HOSTED',
        'currency' => 'EUR', // Match the recurring Price's currency.
        'subscription_data' => ['days_until_due' => 0],
        'line_items' => [['price_id' => '<recurring-price-id>', 'quantity' => 1]],
        'success_url' => 'https://example.test/billing/complete',
        'back_url' => 'https://example.test/billing',
    ]);
    // Redirect to the active provider URL; application reconciliation decides payment/access state.
    return ['customer' => $observed, 'checkout' => $checkout];
};
