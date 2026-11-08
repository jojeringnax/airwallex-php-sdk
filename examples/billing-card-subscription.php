<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Explicit application action: link a verified card instrument and create an AUTO_CHARGE subscription.
// Authorize the buyer first. Both clients must use the stored environment; $payments owns the expected collector,
// and $billing is independently authorized for the stored Billing organization/customer.
// Confirm collection-account/product eligibility separately; opaque IDs cannot establish it.
return static function (
    Client $billing,
    Client $payments,
    string $consentId,
    string $expectedPaymentsCustomerId,
    string $billingCustomerId,
    string $collectionAccountId,
    string $legalEntityId,
    string $priceId,
    string $sourceRequestId,
    string $subscriptionRequestId,
): array {
    foreach (
        [$consentId, $expectedPaymentsCustomerId, $billingCustomerId, $collectionAccountId,
        $legalEntityId, $priceId, $sourceRequestId, $subscriptionRequestId] as $identity
    ) {
        if ($identity === '') {
            throw new InvalidArgumentException('Load the authorized mapping and persisted action identities first.');
        }
    }
    $consent = $payments->payments()->paymentConsents()->retrieve($consentId);
    $method = $consent['payment_method'] ?? null;
    if (
        ($consent['id'] ?? null) !== $consentId
        || ($consent['status'] ?? null) !== 'VERIFIED'
        || ($consent['customer_id'] ?? null) !== $expectedPaymentsCustomerId
        || ($consent['next_triggered_by'] ?? null) !== 'merchant'
        || ($consent['merchant_trigger_reason'] ?? null) !== 'unscheduled'
        || !is_array($method) || ($method['type'] ?? null) !== 'card'
        || !is_string($method['id'] ?? null) || $method['id'] === ''
    ) {
        throw new RuntimeException('Inspect card verification and buyer ownership before linking or subscribing.');
    }
    // The selected source contract uses the Method ID, not the Consent ID.
    $source = $billing->billing()->paymentSources()->create([
        'request_id' => $sourceRequestId,
        'billing_customer_id' => $billingCustomerId,
        'external_id' => $method['id'],
        'linked_payment_account_id' => $collectionAccountId,
    ]);
    if (
        !is_string($source['id'] ?? null) || $source['id'] === ''
        || ($source['billing_customer_id'] ?? null) !== $billingCustomerId
        || ($source['linked_payment_account_id'] ?? null) !== $collectionAccountId
        || ($source['external_id'] ?? null) !== $method['id']
    ) {
        throw new RuntimeException('Inspect the returned source identity and ownership before subscribing.');
    }
    $subscription = $billing->billing()->subscriptions()->create([
        'request_id' => $subscriptionRequestId,
        'billing_customer_id' => $billingCustomerId,
        'collection_method' => 'AUTO_CHARGE',
        'payment_source_id' => $source['id'],
        'legal_entity_id' => $legalEntityId,
        'linked_payment_account_id' => $collectionAccountId,
        'items' => [['price_id' => $priceId, 'quantity' => 1]],
    ]);
    // Persist returned mappings in application storage; observed status does not grant access by itself.
    return ['source' => $source, 'subscription' => $subscription];
};
