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
        'payment_intent' => [
            'cancelled',
            'created',
            'payment_failed',
            'pending',
            'pending_review',
            'requires_capture',
            'requires_customer_action',
            'requires_payment_method',
            'succeeded',
            'updated',
        ],
        'payment_attempt' => [
            'authentication_failed',
            'authentication_redirected',
            'authorization_failed',
            'authorized',
            'cancelled',
            'capture_failed',
            'capture_requested',
            'expired',
            'failed_to_process',
            'paid',
            'pending_authorization',
            'received',
            'risk_declined',
            'settled',
        ],
        'refund' => [
            'accepted',
            'failed',
            'received',
            'settled',
        ],
    ];

    private const array STATUSES = [
        'subscription' => ['PENDING', 'IN_TRIAL', 'ACTIVE', 'UNPAID', 'CANCELLED'],
        'billing_checkout' => ['ACTIVE', 'COMPLETED', 'CANCELLED', 'EXPIRED'],
        'invoice' => ['DRAFT', 'FINALIZED', 'VOIDED'],
        'payment_consent' => ['PENDING', 'VERIFIED', 'DISABLED', 'PAUSED',
            'REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION'],
        'payment_method' => ['CREATED', 'DISABLED'],
        'payment_intent' => [
            'REQUIRES_PAYMENT_METHOD',
            'REQUIRES_CUSTOMER_ACTION',
            'PENDING_REVIEW',
            'REQUIRES_CAPTURE',
            'PENDING',
            'SUCCEEDED',
            'CANCELLED',
        ],
        'payment_attempt' => [
            'RECEIVED',
            'AUTHENTICATION_REDIRECTED',
            'PENDING_AUTHORIZATION',
            'AUTHORIZED',
            'CAPTURE_REQUESTED',
            'EXPIRED',
            'CANCELLED',
            'FAILED',
            'SETTLED',
            'PAID',
        ],
        'refund' => [
            'RECEIVED',
            'ACCEPTED',
            'SETTLED',
            'FAILED',
        ],
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
        'payment_intent' => [
            '/additional_info/customer_activity_data/purchase_summaries/*/successful_purchase_count',
            '/additional_info/enhanced_scheme_data/products/*/quantity',
            '/additional_info/online_travel_agency_data/customer/loyalty_program_points',
            '/order/products/*/quantity',
            '/order/sellers/*/additional_info/sales_summary/sales_count',
            '/payment_consent/terms_of_use/billing_cycle_charge_day',
            '/payment_consent/terms_of_use/payment_schedule/period',
            '/payment_consent/terms_of_use/total_billing_cycles',
        ],
        'payment_attempt' => [
        ],
        'refund' => [
        ],
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
        $prefix = '/data/object';
        if (
            in_array($family, ['billing_checkout', 'subscription', 'invoice'], true)
            && is_array($data) && !array_is_list($data)
            && !array_key_exists('object', $data)
        ) {
            $resource = $data;
            $prefix = '/data';
        }
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
        return array_map(static fn (string $path): string => $prefix . $path, self::INTEGER_PATHS[$family]);
    }
}
