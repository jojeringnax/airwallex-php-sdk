<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Application recipes: the client, customer, Method, Consent, Intent and Refund must share the owning account.
// Authorize the buyer/action and durably persist each request ID before calling a mutation.
// Choose one recipe for the current provider state; do not run every action as a sequence.
return [
    'prepare_customer_consent' => static function (
        Client $payments,
        string $merchantCustomerId,
        string $customerRequestId,
        string $consentRequestId,
    ): array {
        $customer = $payments->payments()->customers()->create([
            'request_id' => $customerRequestId,
            'merchant_customer_id' => $merchantCustomerId,
        ]);
        if (!is_string($customer['id'] ?? null) || $customer['id'] === '') {
            throw new RuntimeException('Inspect the returned Payments Customer identity.');
        }
        $consent = $payments->payments()->paymentConsents()->create([
            'request_id' => $consentRequestId,
            'customer_id' => $customer['id'],
            'next_triggered_by' => 'merchant',
            'merchant_trigger_reason' => 'unscheduled',
        ]);
        // Persist both IDs. Collect an instrument through the approved browser/provider flow next.
        return ['customer' => $customer, 'consent' => $consent];
    },
    'verify_card_consent' => static function (
        Client $payments,
        string $consentId,
        string $methodId,
        string $requestId,
    ): array {
        // The Method comes from approved collection for this buyer/account; no raw card data is supplied here.
        // Follow returned next_action in the browser where required, then retrieve current Consent state.
        return $payments->payments()->paymentConsents()->verify($consentId, [
            'request_id' => $requestId,
            'payment_method' => ['type' => 'card', 'id' => $methodId],
        ]);
    },
    'one_time_card_payment' => static function (
        Client $payments,
        string $customerId,
        string $methodId,
        string $orderId,
        string $createRequestId,
        string $confirmRequestId,
    ): array {
        $intent = $payments->payments()->paymentIntents()->create([
            'request_id' => $createRequestId,
            'merchant_order_id' => $orderId,
            'customer_id' => $customerId,
            'amount' => '19.90',
            'currency' => 'EUR',
        ]);
        if (!is_string($intent['id'] ?? null) || $intent['id'] === '') {
            throw new RuntimeException('Inspect the returned Payment Intent identity.');
        }
        return $payments->payments()->paymentIntents()->confirm($intent['id'], [
            'request_id' => $confirmRequestId,
            'triggered_by' => 'customer',
            'payment_method' => ['type' => 'card', 'id' => $methodId],
            'payment_method_options' => ['card' => ['auto_capture' => true]],
        ]);
    },
    'authorize_card' => static function (
        Client $payments,
        string $intentId,
        string $consentId,
        string $requestId,
    ): array {
        // This is a separate authorization choice for an existing Intent, not a follow-up to automatic capture.
        return $payments->payments()->paymentIntents()->confirm($intentId, [
            'request_id' => $requestId,
            'payment_consent_id' => $consentId,
            'payment_method_options' => ['card' => ['auto_capture' => false, 'authorization_type' => 'pre_auth']],
        ]);
    },
    'capture' => static function (Client $payments, string $intentId, string $requestId, string $amount): array {
        // The application has inspected a capturable authorization and selected the amount explicitly.
        return $payments->payments()->paymentIntents()->capture($intentId, [
            'request_id' => $requestId,
            'amount' => $amount,
        ]);
    },
    'cancel' => static function (Client $payments, string $intentId, string $requestId): array {
        // Cancellation is an alternative state-dependent action; the provider checks whether it is still allowed.
        return $payments->payments()->paymentIntents()->cancel($intentId, ['request_id' => $requestId]);
    },
    'refund' => static function (
        Client $payments,
        string $intentId,
        string $requestId,
        ?string $amount = null,
    ): array {
        $body = ['request_id' => $requestId, 'payment_intent_id' => $intentId, 'reason' => 'requested_by_customer'];
        if ($amount !== null) {
            $body['amount'] = $amount;
        }
        // Omit amount for the remaining refundable amount; this does not assume the Refund is settled.
        return $payments->payments()->refunds()->create($body);
    },
    'read_payment' => static function (Client $payments, string $intentId): array {
        return $payments->payments()->paymentIntents()->retrieve($intentId);
    },
    'read_refund' => static function (Client $payments, string $refundId): array {
        return $payments->payments()->refunds()->retrieve($refundId);
    },
];
