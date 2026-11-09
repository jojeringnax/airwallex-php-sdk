<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\SdkException;
use Jojeringnax\Airwallex\Exception\TransportException;

// These checks bound the finite restoration example to its observed ACTIVE EUR/OUT_OF_BAND owner context.
$ownsFiniteSubscription = static function (mixed $value, string $id, string $customer, string $entity): bool {
    return is_array($value) && ($value['id'] ?? null) === $id
        && ($value['billing_customer_id'] ?? null) === $customer && ($value['legal_entity_id'] ?? null) === $entity
        && ($value['status'] ?? null) === 'ACTIVE' && ($value['currency'] ?? null) === 'EUR'
        && ($value['collection_method'] ?? null) === 'OUT_OF_BAND' && ($value['payment_source_id'] ?? null) === null;
};
$instant = static function (mixed $value): ?array {
    if (
        !is_string($value) || preg_match(
            '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.([0-9]+))?(Z|[+-]\d{2}:\d{2})$/D',
            $value,
            $parts,
        ) !== 1
    ) {
        return null;
    }
    $zone = $parts[3] === 'Z' ? '+00:00' : $parts[3];
    if ((int) substr($zone, 1, 2) > 23 || (int) substr($zone, 4, 2) > 59) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $parts[1] . $zone);
    if ($date === false || $date->format('Y-m-d\TH:i:sP') !== $parts[1] . $zone) {
        return null;
    }
    return [$date->getTimestamp(), rtrim($parts[2], '0')];
};
$storedString = static function (mixed $value, string $key): string {
    if (!is_array($value) || !is_string($value[$key] ?? null) || $value[$key] === '') {
        throw new InvalidArgumentException('Load the durably saved finite restoration action first.');
    }
    return $value[$key];
};

// Recovery uses an already authorized organization client and the application's persisted buyer mapping.
$ownsRecoverySubscription = static function (
    array $value,
    string $id,
    string $customer,
    string $entity,
    string $collector,
): bool {
    return ($value['id'] ?? null) === $id && ($value['billing_customer_id'] ?? null) === $customer
        && ($value['legal_entity_id'] ?? null) === $entity
        && ($value['linked_payment_account_id'] ?? null) === $collector;
};
$recoveryError = static fn (SdkException $error): array => $error instanceof ApiException
    ? ['kind' => 'api_error', 'http_status' => $error->httpStatus(), 'provider_code' => $error->providerCode()]
    : ['kind' => 'unavailable']; // Never retain exception messages, bodies or request details.
$observeRecovery = static function (
    Client $billing,
    string $subscriptionId,
    string $customerId,
    string $legalEntityId,
    string $collectionAccountId,
) use (
    $ownsRecoverySubscription,
    $recoveryError,
): array {
    foreach ([$subscriptionId, $customerId, $legalEntityId, $collectionAccountId] as $identity) {
        if ($identity === '') {
            throw new InvalidArgumentException('Load the authorized recovery mapping first.');
        }
    }
    $result = ['subscription' => null, 'invoice' => null, 'read_error' => null];
    try {
        $subscription = $billing->billing()->subscriptions()->retrieve($subscriptionId);
    } catch (SdkException $error) {
        $result['read_error'] = ['stage' => 'subscription', ...$recoveryError($error)];
        return $result;
    }
    if (!$ownsRecoverySubscription($subscription, $subscriptionId, $customerId, $legalEntityId, $collectionAccountId)) {
        $result['read_error'] = ['stage' => 'subscription', 'kind' => 'ownership_not_established'];
        return $result;
    }
    $result['subscription'] = $subscription;
    $invoiceId = $subscription['latest_invoice_id'] ?? null;
    if (!is_string($invoiceId) || $invoiceId === '') {
        return $result; // The unchanged Subscription preserves whether this optional reference was absent or null.
    }
    try {
        $invoice = $billing->billing()->invoices()->retrieve($invoiceId);
    } catch (SdkException $error) {
        $result['read_error'] = ['stage' => 'invoice', ...$recoveryError($error)];
        return $result;
    }
    if (
        ($invoice['id'] ?? null) !== $invoiceId || ($invoice['billing_customer_id'] ?? null) !== $customerId
        || ($invoice['subscription_id'] ?? null) !== $subscriptionId
    ) {
        $result['read_error'] = ['stage' => 'invoice', 'kind' => 'ownership_not_established'];
        return $result;
    }
    $result['invoice'] = $invoice;
    return $result;
};

// Application recipes: load the stored buyer/organization/collector mapping and authorize each action first.
// Choose one named recipe for the intended action; these are alternatives, not an automatic sequence.
// Every request ID must already be durably associated with that action in application storage.
// Item IDs are observed provider references. Refresh/reconcile items before a later mutation;
// select the intended owned item unambiguously instead of assuming its previous ID stayed stable.
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
        // oldItemId must be freshly selected for this action. NONE schedules the fee change for the next cycle;
        // a subsequent list may still contain the prior entry and does not identify active/ended items.
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
    'schedule_finite_end' => static function (
        Client $billing,
        string $subscriptionId,
        string $customerId,
        string $legalEntityId,
        string $scheduleRequestId,
        string $restoreRequestId,
        Closure $persist,
    ) use (
        $ownsFiniteSubscription,
        $instant
): array {
        foreach ([$subscriptionId, $customerId, $legalEntityId, $scheduleRequestId, $restoreRequestId] as $identity) {
            if ($identity === '') {
                throw new InvalidArgumentException('Load the authorized mapping and distinct action identities first.');
            }
        }
        if ($scheduleRequestId === $restoreRequestId) {
            throw new InvalidArgumentException('Scheduling and restoration need distinct persisted action identities.');
        }
        $before = $billing->billing()->subscriptions()->retrieve($subscriptionId);
        $end = $instant($before['ends_at'] ?? null);
        if (
            !$ownsFiniteSubscription($before, $subscriptionId, $customerId, $legalEntityId)
            || ($before['cancel_at_period_end'] ?? null) !== false || $end === null || $end[0] <= time()
        ) {
            throw new RuntimeException('Verify the owned ACTIVE finite schedule before requesting an ending flag.');
        }
        // The application callback must commit this PRIVATE snapshot durably, or throw, before the first POST.
        // Never reconstruct the old end from a response after true: that operation may shorten the visible cap.
        $persist([
            'subscription_id' => $subscriptionId, 'billing_customer_id' => $customerId,
            'legal_entity_id' => $legalEntityId, 'ends_at' => $before['ends_at'],
            'schedule_request_id' => $scheduleRequestId, 'restore_request_id' => $restoreRequestId,
        ]);
        return $billing->billing()->subscriptions()->update($subscriptionId, [
            'request_id' => $scheduleRequestId,
            'cancel_at_period_end' => true,
        ]);
    },
    'restore_finite_end' => static function (
        Client $billing,
        array $stored
    ) use (
        $ownsFiniteSubscription,
        $instant,
        $storedString,
    ): array {
        // Load only the application's authorized, durably saved pre-scheduling snapshot; never accept browser values.
        $id = $storedString($stored, 'subscription_id');
        $customer = $storedString($stored, 'billing_customer_id');
        $entity = $storedString($stored, 'legal_entity_id');
        $rawEnd = $storedString($stored, 'ends_at');
        $requestId = $storedString($stored, 'restore_request_id');
        $end = $instant($rawEnd);
        if ($requestId === $storedString($stored, 'schedule_request_id') || $end === null || $end[0] <= time()) {
            throw new InvalidArgumentException('The saved finite end and separate restoration action must be valid.');
        }
        $current = $billing->billing()->subscriptions()->retrieve($id);
        if (
            !$ownsFiniteSubscription($current, $id, $customer, $entity)
            || ($current['cancel_at_period_end'] ?? null) !== true
        ) {
            throw new RuntimeException('Reconcile the owned ACTIVE scheduled state before a restoration action.');
        }
        $billing->billing()->subscriptions()->update($id, [
            'request_id' => $requestId,
            'cancel_at_period_end' => false,
            'ends_at' => $rawEnd,
        ]);
        $observed = $billing->billing()->subscriptions()->retrieve($id);
        if (
            !$ownsFiniteSubscription($observed, $id, $customer, $entity)
            || ($observed['cancel_at_period_end'] ?? null) !== false
            || $instant($observed['ends_at'] ?? null) !== $end
        ) {
            // One observation only. Reconcile later with GETs; do not replay this recipe after an uncertain result.
            throw new RuntimeException('Finite restoration is not yet observed; reconcile before another action.');
        }
        return $observed;
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
        string $customerId,
        string $legalEntityId,
        string $collectionAccountId,
        string $requestId,
    ) use (
        $ownsRecoverySubscription,
        $observeRecovery,
        $recoveryError,
    ): array {
        // The application knows the old instrument expired and has separately authorized this replacement.
        // No PaymentSource expiry field or collector eligibility is inferred from these references.
        $identities = [$subscriptionId, $replacementSourceId, $customerId, $legalEntityId,
            $collectionAccountId, $requestId];
        foreach ($identities as $identity) {
            if ($identity === '') {
                throw new InvalidArgumentException('Load the authorized mapping and persisted recovery action first.');
            }
        }
        $before = $billing->billing()->subscriptions()->retrieve($subscriptionId);
        if (!$ownsRecoverySubscription($before, $subscriptionId, $customerId, $legalEntityId, $collectionAccountId)) {
            throw new RuntimeException('Subscription ownership is not established for this recovery action.');
        }
        $source = $billing->billing()->paymentSources()->retrieve($replacementSourceId);
        if (
            ($source['id'] ?? null) !== $replacementSourceId || ($source['billing_customer_id'] ?? null) !== $customerId
            || ($source['linked_payment_account_id'] ?? null) !== $collectionAccountId
        ) {
            throw new RuntimeException('Replacement Source ownership is not established for this recovery action.');
        }
        $response = null;
        $failure = null;
        try {
            // One explicit action. The selected contract says this can retry the last failed invoice immediately.
            $response = $billing->billing()->subscriptions()->update($subscriptionId, [
                'request_id' => $requestId,
                'payment_source_id' => $replacementSourceId,
            ]);
            if (
                !$ownsRecoverySubscription(
                    $response,
                    $subscriptionId,
                    $customerId,
                    $legalEntityId,
                    $collectionAccountId
                )
            ) {
                $response = null;
                $failure = ['kind' => 'ownership_not_established'];
            }
        } catch (ApiException $error) {
            $failure = $recoveryError($error);
        } catch (TransportException | ProtocolException $error) {
            $failure = ['kind' => 'unconfirmed'];
        }
        // A rejection, lost response or read failure never causes another update. Continue with observe_recovery.
        $observed = $observeRecovery($billing, $subscriptionId, $customerId, $legalEntityId, $collectionAccountId);
        return ['update_response' => $response, 'update_error' => $failure, ...$observed];
    },
    'observe_recovery' => $observeRecovery,
    'read_invoice' => static function (Client $billing, string $invoiceId): array {
        // Authorize the buyer before returning any PDF/hosted link. Null links are valid observations.
        // Download, storage, delivery and expired-link recovery belong to the application.
        return $billing->billing()->invoices()->retrieve($invoiceId);
    },
];
