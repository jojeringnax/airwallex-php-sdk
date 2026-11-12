<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Billing;

use Jojeringnax\Airwallex\Internal\Schema\Validator;
use SensitiveParameter;
use stdClass;

/** @internal Conditional requirements stated in the selected API's descriptions. */
final class Rules
{
    /**
     * @param array<string, mixed> $body
     */
    public static function validate(string $operation, #[SensitiveParameter] array $body): void
    {
        $body = self::normalizeForConditions($body);
        self::nestedControls($body);
        if (in_array($operation, ['createRateCard', 'createRateCardVersion'], true)) {
            self::recurring($body);
            foreach (self::itemObjects($body['items'] ?? []) as $item) {
                if ($operation === 'createRateCardVersion' && isset($item['id'])) {
                    if (count($item) !== 1) {
                        Validator::fail();
                    }
                } else {
                    self::requireKeys($item, ['dimension_values', 'pricing_model']);
                    if (!in_array($item['pricing_model'], ['PER_UNIT', 'VOLUME', 'GRADUATED'], true)) {
                        Validator::fail();
                    }
                    self::price($item, allowZeroFirstBound: true);
                }
            }
        }
        if (in_array($operation, ['createCoupon', 'updateCoupon'], true)) {
            self::coupon($body);
        }
        if ($operation === 'createCreditNote') {
            if (($body['reason'] ?? null) === 'OTHER') {
                self::requireKeys($body, ['custom_reason']);
            } elseif (isset($body['custom_reason'])) {
                Validator::fail();
            }
        }
        if ($operation === 'creditNotes_addLineItems') {
            foreach (self::itemObjects($body['line_items'] ?? []) as $item) {
                self::creditNoteItem($item);
            }
        }
        if ($operation === 'createMeter' && ($body['aggregation_method'] ?? null) !== 'COUNT') {
            self::requireKeys($body, ['aggregation_property']);
        }
        if ($operation === 'batchIngest') {
            $events = $body['events'] ?? null;
            if (!is_array($events) || count($events) > 10) {
                Validator::fail();
            }
        }
        if ($operation === 'invoices_addLineItems') {
            foreach (self::itemObjects($body['line_items'] ?? []) as $item) {
                self::itemPrice($item, true);
            }
        }
        if ($operation === 'createInvoice' && ($body['collection_method'] ?? null) === 'AUTO_CHARGE') {
            self::requireKeys($body, ['payment_source_id']);
        }
        if ($operation === 'createInvoice' || $operation === 'updateInvoice') {
            if (isset($body['days_until_due'], $body['due_at'])) {
                Validator::fail();
            }
        }
        if ($operation === 'createPrice') {
            self::price($body);
        }
        if ($operation === 'createBillingCheckout') {
            $mode = $body['mode'] ?? null;
            if ($mode === 'SETUP') {
                self::requireKeys($body, ['currency', 'billing_customer_id']);
                if (array_key_exists('line_items', $body) || array_key_exists('customer_data', $body)) {
                    Validator::fail();
                }
            } else {
                self::requireKeys($body, ['line_items']);
                if ($body['line_items'] === []) {
                    Validator::fail();
                }
            }
            if (
                (array_key_exists('subscription_data', $body) && $mode !== 'SUBSCRIPTION')
                || (array_key_exists('invoice_data', $body) && $mode !== 'PAYMENT')
                || (isset($body['billing_customer_id']) && array_key_exists('customer_data', $body))
            ) {
                Validator::fail();
            }
            if (
                isset($body['invoice_data']) && is_array($body['invoice_data'])
                && array_key_exists('days_until_due', $body['invoice_data'])
                && array_key_exists('due_at', $body['invoice_data'])
            ) {
                Validator::fail();
            }
            $items = self::itemObjects($body['line_items'] ?? []);
            foreach ($items as $item) {
                self::itemPrice($item, $mode === 'PAYMENT', $mode === 'SUBSCRIPTION');
            }
        }
        if ($operation === 'createSubscription') {
            if (($body['collection_method'] ?? null) === 'AUTO_CHARGE') {
                self::requireKeys($body, ['payment_source_id']);
            }
            if (isset($body['duration'], $body['ends_at']) || ($body['items'] ?? []) === []) {
                Validator::fail();
            }
            $items = self::itemObjects($body['items'] ?? []);
            foreach ($items as $item) {
                self::itemPrice($item, true, true);
            }
        }
        if ($operation === 'updateSubscription') {
            // Product documentation identifies the billing entity as immutable.
            if (array_key_exists('legal_entity_id', $body)) {
                Validator::fail();
            }
            $added = 0;
            $items = self::itemObjects($body['items'] ?? []);
            foreach ($items as $item) {
                self::prorationValue($item);
                if (array_key_exists('rate_card_version', $item)) {
                    Validator::validate($item['rate_card_version'], ['type' => 'integer', 'format' => 'int32'], []);
                }
                if (isset($item['id'])) {
                    if (array_key_exists('price_id', $item) || array_key_exists('rate_card_id', $item)) {
                        Validator::fail();
                    }
                } else {
                    self::itemPrice($item, false, true);
                    if (($item['deleted'] ?? false) === true) {
                        Validator::fail();
                    }
                    $added++;
                }
            }
            if ($added > 100) {
                Validator::fail();
            }
        }
        foreach (
            ['collection_method' => ['AUTO_CHARGE', 'CHARGE_ON_CHECKOUT', 'OUT_OF_BAND'],
            'proration_behavior' => ['ALL', 'PRORATED', 'NONE']] as $key => $allowed
        ) {
            if (isset($body[$key]) && !in_array($body[$key], $allowed, true)) {
                Validator::fail();
            }
        }
        foreach (['success_url', 'back_url', 'return_url'] as $key) {
            if (!isset($body[$key])) {
                continue;
            }
            $url = $body[$key];
            if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                Validator::fail();
            }
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
                Validator::fail();
            }
        }
        self::timestamps($body, $operation === 'updateSubscription');
    }

    /** @return list<array<string,mixed>> */
    private static function itemObjects(#[SensitiveParameter] mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items)) {
            Validator::fail();
        }
        $objects = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                Validator::fail();
            }
            $object = [];
            foreach ($item as $key => $value) {
                if (!is_string($key)) {
                    Validator::fail();
                }
                $object[$key] = $value;
            }
            $objects[] = $object;
        }
        return $objects;
    }

    /**
     * @template TKey of array-key
     * @param array<TKey,mixed> $value
     * @return array<TKey,mixed>
     */
    private static function normalizeForConditions(#[SensitiveParameter] array $value): array
    {
        // Validate supported object representations consistently; retain the original body on the wire.
        foreach ($value as $key => $child) {
            if ($child instanceof stdClass) {
                $child = get_object_vars($child);
            }
            if (is_array($child)) {
                $value[$key] = self::normalizeForConditions($child);
            }
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function price(#[SensitiveParameter] array $body, bool $allowZeroFirstBound = false): void
    {
        $model = $body['pricing_model'] ?? 'PER_UNIT';
        if (!in_array($model, ['FLAT', 'PER_UNIT', 'VOLUME', 'GRADUATED'], true)) {
            Validator::fail();
        }
        if (in_array($model, ['VOLUME', 'GRADUATED'], true)) {
            self::priceTiers($body, $allowZeroFirstBound);
        } else {
            self::requireKeys($body, [$model === 'FLAT' ? 'flat_amount' : 'unit_amount']);
            if (array_key_exists('tiers', $body)) {
                Validator::fail();
            }
        }
        if (isset($body['billing_type']) && !in_array($body['billing_type'], ['IN_ADVANCE', 'IN_ARREARS'], true)) {
            Validator::fail();
        }
        self::recurring($body);
    }

    /** @param array<string,mixed> $body */
    private static function recurring(#[SensitiveParameter] array $body): void
    {
        if (isset($body['recurring']) && is_array($body['recurring'])) {
            if (
                !in_array($body['recurring']['period_unit'] ?? null, ['DAY', 'WEEK', 'MONTH', 'YEAR'], true)
                || (isset($body['recurring']['period']) && $body['recurring']['period'] < 1)
            ) {
                Validator::fail();
            }
        }
    }

    /** @param array<string,mixed> $body */
    private static function priceTiers(#[SensitiveParameter] array $body, bool $allowZeroFirstBound): void
    {
        self::requireKeys($body, ['tiers']);
        $tiers = self::itemObjects($body['tiers']);
        if ($tiers === [] || array_key_exists('lot', $body)) {
            Validator::fail();
        }
        $lower = '0';
        foreach ($tiers as $index => $tier) {
            if ($index === count($tiers) - 1) {
                if (array_key_exists('upper_bound', $tier)) {
                    Validator::fail();
                }
            } else {
                $upper = $tier['upper_bound'] ?? null;
                if (!is_string($upper)) {
                    Validator::fail();
                }
                $comparison = Validator::compareDecimals($upper, $lower);
                if ($comparison < 0 || ($comparison === 0 && (!$allowZeroFirstBound || $index !== 0))) {
                    Validator::fail();
                }
                $lower = $upper;
            }
        }
    }

    /** @param array<string, mixed> $item */
    private static function prorationValue(#[SensitiveParameter] array $item): void
    {
        if (($item['proration_mode'] ?? null) !== 'CUSTOM') {
            if (array_key_exists('proration_value', $item)) {
                Validator::fail();
            }
            return;
        }
        $amounts = $item['proration_value'] ?? null;
        if (
            !is_array($amounts)
            || (!array_key_exists('charge_amount', $amounts) && !array_key_exists('credit_amount', $amounts))
        ) {
            Validator::fail();
        }
        foreach ($amounts as $amount) {
            // Decimal syntax validation only: the provider schema keeps these values as JSON strings.
            Validator::validate($amount, ['type' => 'number'], []);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function itemPrice(
        #[SensitiveParameter] array $item,
        bool $inlineAllowed,
        bool $rateCardAllowed = false,
    ): void {
        $sources = (int) isset($item['price_id']) + (int) isset($item['price']) + (int) isset($item['rate_card_id']);
        if ($sources !== 1) {
            Validator::fail();
        }
        if (isset($item['rate_card_id'])) {
            if (!$rateCardAllowed) {
                Validator::fail();
            }
            self::requireKeys($item, ['rate_card_version']);
        } elseif (array_key_exists('rate_card_version', $item)) {
            Validator::fail();
        }
        if (isset($item['price'])) {
            if (!$inlineAllowed || !is_array($item['price'])) {
                Validator::fail();
            }
            /** @var array<string,mixed> $price */
            $price = $item['price'];
            self::price($price);
            if (isset($price['product_id']) === isset($price['product'])) {
                Validator::fail();
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $keys
     */
    private static function requireKeys(#[SensitiveParameter] array $body, array $keys): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
                Validator::fail();
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function nestedControls(#[SensitiveParameter] array $value): void
    {
        foreach ($value as $key => $child) {
            if ($key === 'discounts' && $child !== null) {
                if (!is_array($child) || !array_is_list($child) || count($child) > 1) {
                    Validator::fail();
                }
                foreach (self::itemObjects($child) as $discount) {
                    if (($discount['type'] ?? 'COUPON') !== 'COUPON' || !is_array($discount['coupon'] ?? null)) {
                        Validator::fail();
                    }
                    $couponId = $discount['coupon']['id'] ?? null;
                    if (!is_string($couponId) || $couponId === '') {
                        Validator::fail();
                    }
                }
            }
            if (!in_array($key, ['metadata', 'properties', 'dimension_values'], true) && is_array($child)) {
                if (array_is_list($child)) {
                    foreach ($child as $item) {
                        if (is_array($item)) {
                            /** @var array<string, mixed> $item */
                            self::nestedControls($item);
                        }
                    }
                } else {
                    /** @var array<string, mixed> $child */
                    self::nestedControls($child);
                }
            }
        }
    }

    /** @param array<string,mixed> $body */
    private static function coupon(#[SensitiveParameter] array $body): void
    {
        if (($body['discount_model'] ?? null) === 'FLAT') {
            self::requireKeys($body, ['amount_off', 'currency']);
        } elseif (($body['discount_model'] ?? null) === 'PERCENTAGE') {
            self::requireKeys($body, ['percentage_off']);
        }
        if (isset($body['percentage_off'])) {
            Validator::validate($body['percentage_off'], ['type' => 'number', 'minimum' => 0, 'maximum' => 100], []);
        }
        if (isset($body['amount_off'])) {
            Validator::validate($body['amount_off'], ['type' => 'number', 'minimum' => 0], []);
        }
        if (($body['duration_type'] ?? null) === 'CUSTOM') {
            self::requireKeys($body, ['duration']);
        } elseif (isset($body['duration_type']) && isset($body['duration'])) {
            Validator::fail();
        }
        if (isset($body['expires_at'])) {
            if (!is_string($body['expires_at'])) {
                Validator::fail();
            }
            Validator::timestamp($body['expires_at']);
        }
    }

    /** @param array<string,mixed> $item */
    private static function creditNoteItem(#[SensitiveParameter] array $item): void
    {
        if (isset($item['invoice_line_item_group_id'])) {
            self::requireKeys($item, ['quantity']);
            if (isset($item['invoice_line_item_id']) || isset($item['amount']) || isset($item['unit_amount'])) {
                Validator::fail();
            }
            return;
        }
        if (isset($item['amount'])) {
            if (isset($item['quantity']) || isset($item['unit_amount'])) {
                Validator::fail();
            }
        } else {
            self::requireKeys($item, ['invoice_line_item_id', 'quantity']);
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function timestamps(#[SensitiveParameter] array $value, bool $allowNow): void
    {
        foreach (['starts_at', 'trial_ends_at', 'ends_at', 'billing_cycle_anchor_at', 'due_at'] as $key) {
            if (isset($value[$key])) {
                if (!is_string($value[$key])) {
                    Validator::fail();
                }
                if ($allowNow && in_array($key, ['starts_at', 'trial_ends_at'], true) && $value[$key] === 'NOW') {
                    continue;
                }
                Validator::timestamp($value[$key]);
            }
        }
        foreach (['duration', 'remaining_duration'] as $key) {
            if (
                isset($value[$key]) && is_array($value[$key])
                && !in_array($value[$key]['period_unit'] ?? null, ['DAY', 'WEEK', 'MONTH', 'YEAR'], true)
            ) {
                Validator::fail();
            }
        }
        foreach (['subscription_data', 'invoice_data'] as $key) {
            if (isset($value[$key]) && is_array($value[$key])) {
                /** @var array<string,mixed> $child */
                $child = $value[$key];
                self::timestamps($child, false);
            }
        }
        if (
            isset($value['starts_at'], $value['trial_ends_at']) && is_string($value['starts_at'])
            && is_string($value['trial_ends_at']) && $value['starts_at'] !== 'NOW' && $value['trial_ends_at'] !== 'NOW'
        ) {
            // Compare seconds and arbitrary-precision fractional text without changing the wire strings.
            $start = self::instant($value['starts_at']);
            $end = self::instant($value['trial_ends_at']);
            $precision = max(strlen($end[1]), strlen($start[1]));
            if (
                $end[0] < $start[0] || ($end[0] === $start[0]
                && strcmp(str_pad($end[1], $precision, '0'), str_pad($start[1], $precision, '0')) <= 0)
            ) {
                Validator::fail();
            }
        }
    }

    /** @return array{int,string} */
    private static function instant(#[SensitiveParameter] string $value): array
    {
        preg_match('/\.([0-9]+)/', $value, $parts);
        $seconds = new \DateTimeImmutable((string) preg_replace('/\.[0-9]+/', '', $value));
        return [$seconds->getTimestamp(), $parts[1] ?? ''];
    }
}
