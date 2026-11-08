<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Webhook;

/** @internal Numeric representation for the catalogue at API/webhook 2026-08-21; no state transitions. */
final class WebhookSchema
{
    private const array EVENTS = [
        'subscription' => ['created', 'in_trial', 'active', 'unpaid', 'modified', 'cancelled'],
        'billing_checkout' => ['created', 'completed', 'cancelled'],
        'invoice' => ['created', 'finalized', 'updated', 'payment.paid', 'voided'],
        'payment_consent' => ['created', 'updated', 'pending', 'verified', 'disabled', 'paused',
            'requires_payment_method', 'requires_customer_action', 'verification_failed'],
        'payment_method' => ['created', 'updated', 'attached', 'detached', 'disabled'],
    ];

    private const array STATUSES = [
        'subscription' => ['PENDING', 'IN_TRIAL', 'ACTIVE', 'UNPAID', 'CANCELLED'],
        'billing_checkout' => ['ACTIVE', 'COMPLETED', 'CANCELLED', 'EXPIRED'],
        'invoice' => ['DRAFT', 'FINALIZED', 'VOIDED'],
        'payment_consent' => ['PENDING', 'VERIFIED', 'DISABLED', 'PAUSED',
            'REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION'],
        'payment_method' => ['CREATED', 'DISABLED'],
    ];

    private const array INTEGER_PATHS = [
        'subscription' => ['/applied_discounts/*/duration/period', '/days_until_due',
            '/duration/period', '/recurring/period'],
        'invoice' => ['/applied_discounts/*/duration/period', '/days_until_due',
            '/past_payment_attempt_count', '/remaining_payment_attempt_count'],
        'billing_checkout' => ['/invoice_data/days_until_due', '/line_items/*/quantity',
            '/line_items/*/rate_card_version', '/subscription_data/days_until_due',
            '/subscription_data/duration/period', '/subscription_data/recurring/period'],
        'payment_consent' => ['/next_action/micro_deposit_count', '/next_action/remaining_attempts',
            '/terms_of_use/billing_cycle_charge_day', '/terms_of_use/payment_schedule/period',
            '/terms_of_use/total_billing_cycles'],
        'payment_method' => [],
    ];

    /**
     * @param array<array-key, mixed> $envelope
     * @return list<string>
     */
    public static function integerPaths(#[\SensitiveParameter] array $envelope): array
    {
        if (
            ($envelope['version'] ?? null) !== '2026-08-21' || !is_string($envelope['name'] ?? null)
            || !is_string($envelope['id'] ?? null) || $envelope['id'] === ''
            || !is_string($envelope['created_at'] ?? null) || $envelope['created_at'] === ''
        ) {
            return [];
        }
        [$family, $event] = array_pad(explode('.', $envelope['name'], 2), 2, '');
        if (!isset(self::EVENTS[$family]) || !in_array($event, self::EVENTS[$family], true)) {
            return [];
        }
        $data = $envelope['data'] ?? null;
        $resource = is_array($data) ? ($data['object'] ?? null) : null;
        if (!is_array($resource) || !in_array($resource['status'] ?? null, self::STATUSES[$family], true)) {
            return [];
        }
        $owner = in_array($family, ['subscription', 'billing_checkout', 'invoice'], true) ? 'org_id' : 'account_id';
        $other = $owner === 'org_id' ? 'account_id' : 'org_id';
        if (
            !is_string($envelope[$owner] ?? null) || $envelope[$owner] === ''
            || !in_array($envelope[$other] ?? null, [null, ''], true)
            || !in_array($envelope['accountId'] ?? null, [null, ''], true)
        ) {
            return [];
        }
        if (
            $family === 'payment_consent' && isset($resource['connected_account_id'])
            && $resource['connected_account_id'] !== $envelope[$owner]
        ) {
            return [];
        }

        return array_map(static fn (string $path): string => '/data/object' . $path, self::INTEGER_PATHS[$family]);
    }
}
