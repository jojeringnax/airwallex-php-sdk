<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Payments;

use Jojeringnax\Airwallex\Internal\Schema\Validator;
use SensitiveParameter;
use stdClass;

/** @internal Conditional requirements stated in the selected operation descriptions. */
final class Rules
{
    /** @var array<string, array{endpoints:array<string,array<string,mixed>>,schemas:array<string,array<string,mixed>>}> */
    private static array $contracts = [];

    /** @param array<string, mixed> $body */
    public static function validate(string $operation, #[SensitiveParameter] array $body, string $resource): void
    {
        $contract = self::contract($resource);
        /** @var array{content:array<string,array{schema:array<string,mixed>}>} $request */
        $request = $contract['endpoints'][$operation]['requestBody'];
        self::conditionalObjects($body, $request['content']['application/json']['schema'], $contract['schemas']);
        if (isset($body['payment_method']) && is_array($body['payment_method'])) {
            self::methodConditions($operation, $body['payment_method'], $body);
        }
        $identity = $body['request_id'] ?? null;
        if (
            !is_string($identity) || $identity === ''
            || ($operation !== 'verifyContinue' && self::length($identity) > 64)
        ) {
            Validator::fail();
        }
        if (array_key_exists('funds_split_data', $body)) {
            Validator::fail();
        }
        if (isset($body['metadata'])) {
            self::metadata($body['metadata']);
        }
        if (isset($body['customer_id'], $body['customer']) && is_array($body['customer'])) {
            foreach (array_keys($body['customer']) as $field) {
                if ($field !== 'additional_info') {
                    Validator::fail();
                }
            }
        }
        if (
            $operation === 'createRefund' && !isset($body['payment_intent_id'])
            && !isset($body['payment_attempt_id'])
        ) {
            Validator::fail();
        }
        if ($operation === 'verifyContinue' || $operation === 'confirmContinue') {
            if (($body['type'] ?? null) === 'verify_micro_deposits') {
                $micro = $body['micro_deposit'] ?? null;
                if (!is_array($micro) || !isset($micro['amounts']) || $micro['amounts'] === []) {
                    Validator::fail();
                }
            }
            if (($body['type'] ?? null) === 'submit_card_pin_data' && !isset($body['card_pin_data'])) {
                Validator::fail();
            }
        }
        $method = $body['payment_method'] ?? null;
        if (
            is_array($method) && isset($method['id'])
            && in_array($operation, ['createPaymentIntent', 'paymentIntents_confirm'], true)
        ) {
            if (!isset($body['payment_consent']) && !isset($body['triggered_by'])) {
                Validator::fail();
            }
        }
        $options = $body['payment_method_options'] ?? null;
        if (is_array($options) && is_array($options['wechatpay'] ?? null)) {
            if (($options['wechatpay']['enable_funds_split'] ?? false) === true) {
                Validator::fail();
            }
        }
        if (is_array($options) && is_array($options['card'] ?? null)) {
            $card = $options['card'];
            if (($card['authorization_type'] ?? null) === 'pre_auth' && ($card['auto_capture'] ?? null) !== false) {
                Validator::fail();
            }
        }
        foreach (['payment_consent', 'external_recurring_data'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                self::agreement($body[$key]);
            }
        }
        if ($operation === 'createPaymentConsent') {
            self::agreement($body);
        }
        self::verificationAmounts($body);
    }

    /** @param array<array-key, mixed> $agreement */
    private static function agreement(#[SensitiveParameter] array $agreement): void
    {
        if (
            isset($agreement['next_triggered_by'])
            && !in_array($agreement['next_triggered_by'], ['merchant', 'customer'], true)
        ) {
            Validator::fail();
        }
        $terms = $agreement['terms_of_use'] ?? null;
        if (!is_array($terms)) {
            return;
        }
        if (!in_array($terms['payment_amount_type'] ?? null, ['FIXED', 'VARIABLE'], true)) {
            Validator::fail();
        }
        if ($terms['payment_amount_type'] === 'FIXED' && !isset($terms['fixed_payment_amount'])) {
            Validator::fail();
        }
        if (($agreement['merchant_trigger_reason'] ?? null) === 'scheduled') {
            $schedule = $terms['payment_schedule'] ?? null;
            if (!is_array($schedule) || !isset($schedule['period'], $schedule['period_unit'])) {
                Validator::fail();
            }
        }
        $schedule = $terms['payment_schedule'] ?? null;
        if (is_array($schedule) && isset($schedule['period_unit'])) {
            if (!in_array($schedule['period_unit'], ['DAY', 'WEEK', 'MONTH', 'YEAR'], true)) {
                Validator::fail();
            }
        }
        if (is_array($schedule) && in_array($schedule['period_unit'] ?? null, ['WEEK', 'MONTH', 'YEAR'], true)) {
            if (!isset($terms['billing_cycle_charge_day'])) {
                Validator::fail();
            }
        }
    }

    private static function metadata(#[SensitiveParameter] mixed $metadata): void
    {
        $values = $metadata instanceof stdClass ? get_object_vars($metadata) : $metadata;
        if (!is_array($values) || count($values) > 50) {
            Validator::fail();
        }
        foreach ($values as $key => $value) {
            if (self::length((string) $key) > 50 || !is_string($value) || self::length($value) > 500) {
                Validator::fail();
            }
        }
    }

    /** @param array<string, mixed> $body */
    private static function verificationAmounts(#[SensitiveParameter] array $body): void
    {
        $options = $body['verification_options'] ?? null;
        if (!is_array($options)) {
            return;
        }
        foreach (['card', 'applepay', 'googlepay'] as $method) {
            $settings = $options[$method] ?? null;
            if (is_array($settings) && isset($settings['amount'])) {
                $amount = $settings['amount'];
                if (
                    !is_string($amount) || str_starts_with($amount, '-')
                    || trim(str_replace('.', '', $amount), '0') === ''
                ) {
                    Validator::fail();
                }
            }
        }
    }

    /** @param array<string, mixed> $query */
    public static function validateQuery(#[SensitiveParameter] array $query): void
    {
        foreach (['from_created_at', 'to_created_at'] as $field) {
            if (!array_key_exists($field, $query)) {
                continue;
            }
            $timestamp = $query[$field];
            $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?'
                . '(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)$/D';
            if (!is_string($timestamp) || preg_match($pattern, $timestamp) !== 1) {
                Validator::fail();
            }
            try {
                $wholeSeconds = preg_replace('/\.\d+(?=Z|[+-])/', '', $timestamp);
                if ($wholeSeconds === null) {
                    Validator::fail();
                }
                new \DateTimeImmutable($wholeSeconds);
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                    Validator::fail();
                }
            } catch (\Exception) {
                Validator::fail();
            }
        }
    }

    /**
     * @return array{endpoints:array<string,array<string,mixed>>,schemas:array<string,array<string,mixed>>}
     */
    private static function contract(string $resource): array
    {
        if (!isset(self::$contracts[$resource])) {
            $raw = file_get_contents(dirname(__DIR__, 3) . '/resources/contracts/payments/' . $resource . '.json');
            if ($raw === false) {
                throw new \LogicException('Bundled Payments contract is unavailable.');
            }
            /** @var array{endpoints:array<string,array<string,mixed>>,schemas:array<string,array<string,mixed>>} $data */
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            self::$contracts[$resource] = $data;
        }
        return self::$contracts[$resource];
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, array<string, mixed>> $definitions
     */
    private static function conditionalObjects(
        #[SensitiveParameter] mixed $value,
        array $schema,
        array $definitions,
        string $path = '',
    ): void {
        $schema = Validator::resolve($schema, $definitions);
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return;
        }
        /** @var array<string, array<string,mixed>> $properties */
        $properties = $schema['properties'] ?? [];
        if ($path === '/payment_method' && isset($value['id'])) {
            // A stored Method ID replaces full credentials; retain the operation's schema checks.
            return;
        }
        foreach (['type', 'payment_data_type'] as $discriminator) {
            $branch = $value[$discriminator] ?? null;
            if (is_string($branch) && isset($properties[$branch]) && !array_key_exists($branch, $value)) {
                Validator::fail();
            }
        }
        foreach ($properties as $key => $childSchema) {
            /** @var array{all:list<array{field:string,values:list<string>,negated:bool}>}|null $condition */
            $condition = $childSchema['sdkRequiredWhen'] ?? null;
            $required = $condition !== null;
            foreach ($condition['all'] ?? [] as $clause) {
                $matches = in_array($value[$clause['field']] ?? null, $clause['values'], true);
                if (
                    !array_key_exists($clause['field'], $value)
                    || ($clause['negated'] ? $matches : !$matches)
                ) {
                    $required = false;
                }
            }
            if ($required && !array_key_exists($key, $value)) {
                Validator::fail();
            }
            if (array_key_exists($key, $value)) {
                self::conditionalObjects($value[$key], $childSchema, $definitions, $path . '/' . $key);
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            /** @var array<string,mixed> $items */
            $items = $schema['items'];
            foreach ($value as $item) {
                self::conditionalObjects($item, $items, $definitions, $path . '/*');
            }
        }
    }

    /**
     * @param array<array-key, mixed> $method
     * @param array<string, mixed> $body
     */
    private static function methodConditions(
        string $operation,
        #[SensitiveParameter] array $method,
        #[SensitiveParameter] array $body,
    ): void {
        if (isset($method['id'])) {
            return;
        }
        foreach (['ach_direct_debit', 'bacs_direct_debit'] as $type) {
            $details = $method[$type] ?? null;
            $details = $details instanceof stdClass ? get_object_vars($details) : $details;
            if (!is_array($details)) {
                continue;
            }
            $verification = $details['verification_method'] ?? 'micro_deposit';
            if ($verification === 'micro_deposit') {
                $required = $type === 'ach_direct_debit'
                    ? ['aba_routing_number', 'account_number', 'owner_email', 'owner_name']
                    : ['sort_code', 'account_number', 'owner_email', 'owner_name', 'address'];
                self::requireKeys($details, $required);
                if ($type === 'bacs_direct_debit' && array_key_exists('bank_name', $details)) {
                    Validator::fail();
                }
            } elseif ($verification === 'plaid' && $type === 'ach_direct_debit') {
                if (isset($details['aba_routing_number']) || isset($details['account_number'])) {
                    Validator::fail();
                }
            } elseif ($verification === 'truelayer' && $type === 'bacs_direct_debit') {
                self::requireKeys($details, ['bank_name']);
                if (isset($details['account_number']) || isset($details['sort_code'])) {
                    Validator::fail();
                }
            } else {
                Validator::fail();
            }
        }
        $wechat = $method['wechatpay'] ?? null;
        if (is_array($wechat) && ($wechat['flow'] ?? null) === 'mini_program') {
            self::requireKeys($wechat, ['open_id']);
        }
        $card = $method['card'] ?? null;
        $card = $card instanceof stdClass ? get_object_vars($card) : $card;
        if (is_array($card)) {
            $external = $body['external_recurring_data'] ?? null;
            $merchantInitiated = in_array($operation, ['createPaymentIntent', 'paymentIntents_confirm'], true)
                && (($body['triggered_by'] ?? null) === 'merchant'
                    || (is_array($external) && ($external['triggered_by'] ?? null) === 'merchant'));
            if (($card['number_type'] ?? 'PAN') === 'EXTERNAL_NETWORK_TOKEN') {
                self::requireKeys($card, ['cryptogram']);
            } elseif (!$merchantInitiated) {
                self::requireKeys($card, ['cvc']);
            }
        }
    }

    /**
     * @param array<array-key,mixed> $values
     * @param list<string> $keys
     */
    private static function requireKeys(#[SensitiveParameter] array $values, array $keys): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $values) || $values[$key] === null) {
                Validator::fail();
            }
        }
    }

    private static function length(#[SensitiveParameter] string $value): int
    {
        $length = preg_match_all('/./us', $value);
        return $length === false ? PHP_INT_MAX : $length;
    }
}
