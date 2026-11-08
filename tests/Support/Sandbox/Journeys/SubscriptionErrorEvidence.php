<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use SensitiveParameter;

/** Literal allowlists from createSubscription, API 2026-08-21. No provider text is returned. */
final class SubscriptionErrorEvidence
{
    private const array CODES = [
        'validation_error', 'duplicate_request_id', 'suspended_from_online_payments', 'currency_not_supported',
        'provider_unavailable', 'resource_not_found', 'frequency_above_limit', 'configuration_error',
        'authentication_declined', 'risk_declined', 'provider_declined', 'quote_expired', 'issuer_declined',
        'no_3ds_liability_shift', 'rejected_by_routing_rules', 'unauthorized', 'internal_error',
    ];
    private const array FIELDS = [
        'billing_customer_id', 'collection_method', 'currency', 'days_until_due', 'duration', 'period', 'period_unit',
        'items', 'price_id', 'quantity', 'legal_entity_id', 'linked_payment_account_id', 'metadata',
        'payment_source_id', 'starts_at', 'trial_ends_at', 'request_id',
    ];

    /** @return array{phase:string,http_status:int,code_recognized:bool,provider_code:?string,mentioned_fields:list<string>} */
    public static function inspect(int $status, #[SensitiveParameter] string $body): array
    {
        if (strlen($body) > 262144) {
            return [
                'phase' => 'fresh_subscription_create', 'http_status' => $status,
                'code_recognized' => false, 'provider_code' => null, 'mentioned_fields' => [],
            ];
        }
        $data = json_decode($body, true, 32);
        $code = is_array($data) ? ($data['code'] ?? null) : null;
        $knownCode = is_string($code) && in_array($code, self::CODES, true) ? $code : null;
        $fields = [];
        foreach (self::FIELDS as $field) {
            if (preg_match('/(?<![A-Za-z0-9_])' . preg_quote($field, '/') . '(?![A-Za-z0-9_])/', $body) === 1) {
                $fields[] = $field;
            }
        }
        return [
            'phase' => 'fresh_subscription_create', 'http_status' => $status,
            'code_recognized' => $knownCode !== null, 'provider_code' => $knownCode, 'mentioned_fields' => $fields,
        ];
    }
}
