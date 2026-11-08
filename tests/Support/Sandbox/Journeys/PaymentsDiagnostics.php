<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Optional test-only observations. Raw requests, responses and URLs never enter retained records. */
final class PaymentsDiagnostics implements ClientInterface
{
    private const int LIMIT = 96;
    /** Public codes from selected 2026-08-21 Intent/Consent and shopper simulation error tables. */
    private const array CODES = [
        'validation_error', 'duplicate_request', 'suspended_from_online_payments', 'currency_not_supported',
        'resource_not_found', 'unauthorized', 'not_found', 'internal_error', 'frequency_above_limit',
        'operation_above_limit', 'invalid_status_for_operation', 'provider_unavailable', 'configuration_error',
        'authentication_declined', 'risk_declined', 'resource_already_exists', 'provider_declined', 'quote_expired',
        'payment_method_not_allowed', 'card_brand_not_supported', 'issuer_declined', 'no_3ds_liability_shift',
        '3ds_not_supported', 'rejected_by_routing_rules', 'operation_not_supported',
    ];
    /** Exact public request field names, never dynamic metadata keys or values. */
    private const array FIELDS = [
        'request_id', 'customer_id', 'payment_consent_id', 'return_url', 'amount', 'currency', 'triggered_by',
        'next_triggered_by', 'merchant_trigger_reason', 'payment_method', 'payment_method.id', 'payment_method.type',
        'payment_method.card', 'payment_method.alipayhk', 'payment_method_options', 'payment_method_options.card',
        'payment_method.card.number', 'payment_method.card.cvc', 'payment_method.card.expiry_month',
        'payment_method.card.expiry_year', 'payment_method.alipayhk.flow', 'payment_method.alipayhk.os_type',
        'payment_method_options.card.auto_capture', 'verification_options', 'device_data',
        'payment_method_options.card.authorization_type', 'authorization_type', 'auto_capture',
    ];
    /** Documented public terminology only; a mention is not evidence that a condition is true. */
    private const array CONDITIONS = [
        'pci' => '/(?<![a-zA-Z0-9_.])PCI(?:[\s_-]*DSS)?(?![a-zA-Z0-9_]|\.[a-zA-Z0-9_])/i',
        'native_api' => '/(?<![a-zA-Z0-9_.])native[\s_-]+API(?![a-zA-Z0-9_]|\.[a-zA-Z0-9_])/i',
        'not_enabled' => '/(?<![a-zA-Z0-9_.])not[\s_-]+(?:auto[\s_-]+)?enabled(?![a-zA-Z0-9_]|\.[a-zA-Z0-9_])/i',
        'not_allowed' => '/(?<![a-zA-Z0-9_.])not[\s_-]+allowed(?![a-zA-Z0-9_]|\.[a-zA-Z0-9_])/i',
    ];
    /** @var list<array<string,mixed>> */
    private array $entries = [];
    private int $dropped = 0;

    public function __construct(private readonly ClientInterface $inner)
    {
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $operation = self::operation($request);
        try {
            $response = $this->inner->sendRequest($request);
        } catch (Throwable $error) {
            $this->record(['operation' => $operation, 'transport' => 'exception']);
            throw $error;
        }
        $status = $response->getStatusCode();
        $entry = ['operation' => $operation, 'http_status' => $status];
        // Authentication, Customer payloads and unclassified traffic are never inspected.
        if (in_array($operation, ['consent_verify', 'intent_confirm', 'shopper_simulation'], true)) {
            $object = self::object($response);
            if ($object === null) {
                $entry['body_observation'] = 'unavailable_or_not_object';
            } elseif ($status >= 400) {
                $code = $object['code'] ?? null;
                $entry['public_code'] = is_string($code) && in_array($code, self::CODES, true) ? $code : 'unlisted';
                $entry['validation_fields'] = self::fields($object, $operation);
                $entry['public_condition_terms'] = self::conditions($object);
            } else {
                $next = $object['next_action'] ?? null;
                if (is_array($next)) {
                    $type = $next['type'] ?? null;
                    $entry['next_action_kind'] = $type === 'redirect' ? 'redirect' : 'other';
                    $entry['url_shape'] = self::urlShape($next['url'] ?? null);
                } else {
                    $entry['next_action_kind'] = 'absent';
                }
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
        if (count($this->entries) < self::LIMIT) {
            $this->entries[] = $entry;
        } else {
            ++$this->dropped;
        }
    }

    private static function operation(#[SensitiveParameter] RequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        if ($path === '/api/v1/authentication/login') {
            return 'authentication';
        }
        foreach (['customers', 'payment_consents', 'payment_intents', 'payment_attempts', 'refunds'] as $resource) {
            $prefix = '/api/v1/pa/' . $resource;
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $suffix = basename($path);
                if ($resource === 'payment_consents' && $suffix === 'verify') {
                    return 'consent_verify';
                }
                if ($resource === 'payment_intents' && $suffix === 'confirm') {
                    return 'intent_confirm';
                }
                return $resource . ($request->getMethod() === 'GET' ? '_read' : '_write');
            }
        }
        return str_starts_with($path, '/api/v1/simulation/pa/shopper_actions/')
            ? 'shopper_simulation' : 'unclassified';
    }

    /** @return array<array-key,mixed>|null */
    private static function object(#[SensitiveParameter] ResponseInterface $response): ?array
    {
        $stream = $response->getBody();
        if (!$stream->isSeekable()) {
            return null; // Preserve non-seekable streams for the SDK rather than consume them.
        }
        $offset = null;
        try {
            $offset = $stream->tell();
            $stream->seek(0);
            $bytes = $stream->read(65537);
            if (strlen($bytes) > 65536) {
                return null;
            }
            return (new JsonCodec())->decodeObject($bytes);
        } catch (Throwable) {
            return null;
        } finally {
            if ($offset !== null) {
                try {
                    $stream->seek($offset);
                } catch (Throwable) {
                    // No diagnostic failure text or payload may escape into the evidence envelope.
                }
            }
        }
    }

    /**
     * @param array<array-key,mixed> $object
     * @return list<string>
     */
    private static function fields(#[SensitiveParameter] array $object, string $operation): array
    {
        $result = [];
        $fields = $operation === 'shopper_simulation' ? ['url'] : self::FIELDS;
        foreach (['source', 'message'] as $name) {
            $text = $object[$name] ?? null;
            if (!is_string($text) || strlen($text) > 8192) {
                continue;
            }
            foreach ($fields as $field) {
                if (preg_match('/(?<![a-zA-Z0-9_.])' . preg_quote($field, '/') . '(?![a-zA-Z0-9_.])/i', $text) === 1) {
                    $result[$field] = true;
                }
            }
        }
        ksort($result);
        return array_keys($result);
    }

    /**
     * @param array<array-key,mixed> $object
     * @return list<string>
     */
    private static function conditions(#[SensitiveParameter] array $object): array
    {
        $message = $object['message'] ?? null;
        if (!is_string($message) || strlen($message) > 8192) {
            return [];
        }
        $result = [];
        foreach (self::CONDITIONS as $term => $pattern) {
            if (preg_match($pattern, $message) === 1) {
                $result[] = $term;
            }
        }
        return $result;
    }

    /** @return array<string,string|bool> */
    private static function urlShape(#[SensitiveParameter] mixed $url): array
    {
        if (!is_string($url) || strlen($url) > 16384) {
            return ['representation' => 'missing_or_oversized'];
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return ['representation' => 'unparseable'];
        }
        $host = $parts['host'] ?? null;
        $origin = match ($host) {
            'api.sandbox.airwallex.com' => 'sandbox_api',
            'api-demo.airwallex.com' => 'legacy_named_origin_unverified',
            'checkout.sandbox.airwallex.com' => 'documented_sandbox_checkout',
            default => 'other_origin',
        };
        return [
            'representation' => 'url', 'origin_category' => $origin,
            'scheme_category' => in_array($parts['scheme'] ?? null, ['https', 'http'], true)
                ? $parts['scheme'] : 'other_or_absent',
            'has_path' => ($parts['path'] ?? '') !== '', 'has_query' => isset($parts['query']),
            'has_fragment' => isset($parts['fragment']),
            'has_userinfo' => isset($parts['user']) || isset($parts['pass']),
            'has_nondefault_port' => isset($parts['port']) && $parts['port'] !== 443,
        ];
    }
}
