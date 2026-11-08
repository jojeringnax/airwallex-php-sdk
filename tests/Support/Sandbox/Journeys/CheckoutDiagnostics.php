<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Passive test-only observer: only Checkout create errors and fixed public labels are retained. */
final class CheckoutDiagnostics implements ClientInterface
{
    private const int MAX_ENTRIES = 32;
    private const int MAX_BODY = 65536;
    private const int MAX_TEXT = 8192;
    private const int MAX_FIELDS = 32;
    /** Exact create-error codes in the selected 2026-08-21 Billing Checkout contract. */
    private const array CODES = ['validation_error', 'duplicate_request_id', 'unauthorized', 'internal_error'];
    /** Named create-request fields/paths; dynamic additionalProperties metadata keys are excluded. */
    private const array FIELDS = [
        'back_url', 'billing_customer_id', 'currency', 'customer_data', 'customer_data.email',
        'customer_data.name', 'customer_data.type', 'customer_data_collection',
        'customer_data_collection.billing_address', 'customer_data_collection.tax_id', 'discounts',
        'discounts.coupon', 'discounts.coupon.id', 'discounts.type', 'enable_automatic_tax',
        'hosted_completion_page', 'hosted_completion_page.display', 'invoice_data',
        'invoice_data.days_until_due', 'invoice_data.default_tax_percent', 'invoice_data.due_at',
        'invoice_data.memo', 'invoice_data.metadata', 'legal_entity_id', 'line_items',
        'line_items.discounts', 'line_items.discounts.coupon', 'line_items.discounts.coupon.id',
        'line_items.discounts.type', 'line_items.price', 'line_items.price.currency',
        'line_items.price.description', 'line_items.price.flat_amount', 'line_items.price.metadata',
        'line_items.price.pricing_model', 'line_items.price.product',
        'line_items.price.product.description', 'line_items.price.product.metadata',
        'line_items.price.product.name', 'line_items.price.product.tax_code',
        'line_items.price.product.unit', 'line_items.price.product_id', 'line_items.price.tax_included',
        'line_items.price.unit_amount', 'line_items.price_id', 'line_items.quantity',
        'line_items.rate_card_id', 'line_items.rate_card_version', 'line_items.tax_percent',
        'linked_payment_account_id', 'locale', 'metadata', 'mode', 'payment_options',
        'payment_options.pay_by_invoice_enabled', 'payment_options.payment_method_save',
        'payment_options.payment_method_save.mode', 'payment_options.payment_method_save.next_triggered_by',
        'payment_options.payment_method_types', 'request_id', 'return_url', 'subscription_data',
        'subscription_data.days_until_due', 'subscription_data.default_invoice_template',
        'subscription_data.default_invoice_template.invoice_memo', 'subscription_data.default_tax_percent',
        'subscription_data.duration', 'subscription_data.duration.period',
        'subscription_data.duration.period_unit', 'subscription_data.metadata',
        'subscription_data.starts_at', 'subscription_data.trial_ends_at', 'success_url', 'ui_options',
        'ui_options.theme_id', 'ui_mode', 'email', 'name', 'type', 'billing_address', 'tax_id', 'coupon',
        'id', 'display', 'days_until_due', 'default_tax_percent', 'due_at', 'memo', 'price', 'description',
        'flat_amount', 'pricing_model', 'product', 'tax_code', 'unit', 'product_id', 'tax_included',
        'unit_amount', 'price_id', 'quantity', 'rate_card_id', 'rate_card_version', 'tax_percent',
        'pay_by_invoice_enabled', 'payment_method_save', 'next_triggered_by', 'payment_method_types',
        'default_invoice_template', 'invoice_memo', 'duration', 'period', 'period_unit', 'starts_at',
        'trial_ends_at', 'theme_id',
    ];
    /** @var list<array<string,mixed>> */
    private array $entries = [];
    private int $dropped = 0;

    public function __construct(#[SensitiveParameter] private readonly ClientInterface $inner)
    {
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        if (
            $request->getMethod() !== 'POST'
            || $request->getUri()->getPath() !== '/api/v1/billing/billing_checkouts/create'
        ) {
            return $this->inner->sendRequest($request);
        }
        $case = self::caseLabel(self::object($request->getBody()));
        try {
            $response = $this->inner->sendRequest($request);
        } catch (Throwable $error) {
            $this->record(['case' => $case, 'transport' => 'exception']);
            throw $error;
        }
        $status = $response->getStatusCode();
        $entry = ['case' => $case, 'http_status' => $status];
        if ($status >= 400) {
            $object = self::object($response->getBody());
            if ($object === null) {
                $entry['body_observation'] = 'unavailable_or_not_object';
            } else {
                $code = $object['code'] ?? null;
                $entry['public_code'] = is_string($code) && in_array($code, self::CODES, true) ? $code : 'unlisted';
                $fields = self::fields($object);
                $entry['validation_fields'] = array_slice($fields, 0, self::MAX_FIELDS);
                $entry['validation_fields_truncated'] = count($fields) > self::MAX_FIELDS;
            }
        }
        $this->record($entry);
        return $response;
    }

    /** @return array{entries:list<array<string,mixed>>,entries_dropped:int} */
    public function report(): array
    {
        return ['entries' => $this->entries, 'entries_dropped' => $this->dropped];
    }

    /** @return array<string,string|int> */
    public function __debugInfo(): array
    {
        return ['diagnostics' => '[allowlisted]', 'entries' => count($this->entries)];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Diagnostic transports cannot be serialized.');
    }

    /** @param array<string,mixed> $entry */
    private function record(array $entry): void
    {
        if (count($this->entries) < self::MAX_ENTRIES) {
            $this->entries[] = $entry;
        } else {
            ++$this->dropped;
        }
    }

    /** @param array<array-key,mixed>|null $request */
    private static function caseLabel(#[SensitiveParameter] ?array $request): string
    {
        $mode = $request['mode'] ?? null;
        $ui = $request['ui_mode'] ?? null;
        return is_string($mode) && is_string($ui)
            && in_array($mode, ['SETUP', 'PAYMENT', 'SUBSCRIPTION'], true)
            && in_array($ui, ['HOSTED', 'EMBEDDED', 'ELEMENTS'], true)
            ? strtolower($mode . '_' . $ui) : 'unclassified';
    }

    /** @return array<array-key,mixed>|null */
    private static function object(#[SensitiveParameter] StreamInterface $stream): ?array
    {
        if (!$stream->isSeekable()) {
            return null;
        }
        $offset = null;
        try {
            $size = $stream->getSize();
            if ($size !== null && $size > self::MAX_BODY) {
                return null;
            }
            $offset = $stream->tell();
            $stream->seek(0);
            $bytes = $stream->read(self::MAX_BODY + 1);
            if (strlen($bytes) > self::MAX_BODY || !str_starts_with(ltrim($bytes), '{')) {
                return null;
            }
            $object = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            return is_array($object) ? $object : null;
        } catch (Throwable) {
            return null;
        } finally {
            if ($offset !== null) {
                try {
                    $stream->seek($offset);
                } catch (Throwable) {
                    // Never continue with a request/response body left at a different stream position.
                    throw new RuntimeException('Diagnostic inspection could not preserve the stream.');
                }
            }
        }
    }

    /**
     * @param array<array-key,mixed> $object
     * @return list<string>
     */
    private static function fields(#[SensitiveParameter] array $object): array
    {
        $fields = [];
        foreach (['source', 'message'] as $name) {
            $text = $object[$name] ?? null;
            if (!is_string($text) || strlen($text) > self::MAX_TEXT) {
                continue;
            }
            // Numeric array positions are not retained; unknown/dynamic dotted paths remain unmatched.
            $normalized = preg_replace('/\[[0-9]{1,10}\]/', '', $text);
            if ($normalized === null) {
                continue;
            }
            foreach (self::FIELDS as $field) {
                $pattern = '/(?<![a-zA-Z0-9_.])' . preg_quote($field, '/') . '(?![a-zA-Z0-9_.])/';
                if (preg_match($pattern, $normalized) === 1) {
                    $fields[$field] = true;
                }
            }
        }
        ksort($fields);
        return array_keys($fields);
    }
}
