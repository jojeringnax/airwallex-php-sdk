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
        self::unsupportedControls($body);
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
                self::itemPrice($item, $mode === 'PAYMENT');
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
                self::itemPrice($item, true);
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
                if (isset($item['id'])) {
                    if (array_key_exists('price_id', $item)) {
                        Validator::fail();
                    }
                } else {
                    self::requireKeys($item, ['price_id']);
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
    private static function price(#[SensitiveParameter] array $body): void
    {
        $model = $body['pricing_model'] ?? 'PER_UNIT';
        if (!in_array($model, ['FLAT', 'PER_UNIT', 'VOLUME', 'GRADUATED'], true)) {
            Validator::fail();
        }
        if (in_array($model, ['VOLUME', 'GRADUATED'], true)) {
            self::priceTiers($body);
        } else {
            self::requireKeys($body, [$model === 'FLAT' ? 'flat_amount' : 'unit_amount']);
            if (array_key_exists('tiers', $body)) {
                Validator::fail();
            }
        }
        if (isset($body['billing_type']) && !in_array($body['billing_type'], ['IN_ADVANCE', 'IN_ARREARS'], true)) {
            Validator::fail();
        }
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
    private static function priceTiers(#[SensitiveParameter] array $body): void
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
                if (!is_string($upper) || Validator::compareDecimals($upper, $lower) <= 0) {
                    Validator::fail();
                }
                $lower = $upper;
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function itemPrice(#[SensitiveParameter] array $item, bool $inlineAllowed): void
    {
        if (isset($item['price_id']) === isset($item['price'])) {
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
    private static function unsupportedControls(#[SensitiveParameter] array $value): void
    {
        foreach ($value as $key => $child) {
            if (
                ($key === 'metered' && $child !== false)
                || in_array($key, ['discounts', 'meter_id', 'rate_card_id', 'rate_card_version',
                'enable_automatic_tax', 'default_tax_percent', 'tax_percent'], true)
            ) {
                Validator::fail();
            }
            if ($key !== 'metadata' && is_array($child)) {
                if (array_is_list($child)) {
                    foreach ($child as $item) {
                        if (is_array($item)) {
                            /** @var array<string, mixed> $item */
                            self::unsupportedControls($item);
                        }
                    }
                } else {
                    /** @var array<string, mixed> $child */
                    self::unsupportedControls($child);
                }
            }
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
