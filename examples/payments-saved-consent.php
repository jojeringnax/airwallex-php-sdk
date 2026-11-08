<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Application recipe: call only after authorizing the buyer and the merchant-initiated charge.
// $requestIds must already be durably associated with this intended charge in application storage.
return static function (
    Client $payments,
    #[SensitiveParameter] string $consentId,
    #[SensitiveParameter] array $requestIds,
): array {
    $consent = $payments->payments()->paymentConsents()->retrieve($consentId);
    if (($consent['status'] ?? null) !== 'VERIFIED' || !is_string($consent['customer_id'] ?? null)) {
        throw new RuntimeException('Complete and inspect instrument verification before charging.');
    }
    $intent = $payments->payments()->paymentIntents()->create([
        'request_id' => $requestIds['create'],
        'merchant_order_id' => '<application-order-id>',
        'customer_id' => $consent['customer_id'],
        'amount' => '19.90',
        'currency' => 'EUR',
    ]);
    if (!is_string($intent['id'] ?? null)) {
        throw new RuntimeException('Inspect the returned payment identity before confirmation.');
    }
    return $payments->payments()->paymentIntents()->confirm($intent['id'], [
        'request_id' => $requestIds['confirm'],
        'payment_consent_id' => $consentId,
        'triggered_by' => 'merchant',
    ]);
    // The caller inspects status/next_action; a successful call does not imply settlement.
};
