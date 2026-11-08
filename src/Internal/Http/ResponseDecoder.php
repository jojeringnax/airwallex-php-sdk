<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Internal\Http;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/** @internal */
final readonly class ResponseDecoder
{
    public function __construct(private JsonCodec $codec = new JsonCodec())
    {
    }

    /**
     * @param list<string> $decimalPaths
     * @param list<string> $integerPaths
     * @param list<string> $sensitiveValues
     * @return array<array-key, mixed>
     */
    public function decode(
        #[SensitiveParameter] ResponseInterface $response,
        array $decimalPaths = [],
        array $integerPaths = [],
        #[SensitiveParameter] array $sensitiveValues = [],
    ): array {
        $status = $response->getStatusCode();
        try {
            $raw = (string) $response->getBody();
        } catch (\Throwable) {
            if ($status >= 200 && $status < 300) {
                throw new ProtocolException('Airwallex response body could not be read.');
            }
            throw new ApiException($status);
        }
        if ($status < 200 || $status >= 300) {
            try {
                $error = $this->codec->decodeObject($raw);
            } catch (ProtocolException) {
                throw new ApiException($status);
            }
            $code = $this->safeField($error['code'] ?? null, '/^[a-z][a-z0-9_]{0,127}$/D', $sensitiveValues);
            $source = $this->safeField(
                $error['source'] ?? null,
                '/^[a-z][a-z0-9_]{0,127}$/D',
                $sensitiveValues,
            );
            $safeRoots = [
                'request_id', 'amount', 'currency', 'merchant_order_id', 'billing_customer_id',
                'customer_id', 'email', 'name', 'default_legal_entity_id', 'linked_payment_account_id',
                'legal_entity_id', 'payment_source_id', 'payment_intent_id', 'payment_method',
                'payment_consent_id', 'items', 'line_items', 'page', 'page_num', 'page_size',
                'from_created_at', 'to_created_at', 'status', 'active', 'reason',
            ];
            throw new ApiException($status, $code, in_array($source, $safeRoots, true) ? $source : null);
        }
        return $this->codec->decodeObject($raw, $decimalPaths, $integerPaths);
    }

    /** @param list<string> $sensitiveValues */
    private function safeField(
        #[SensitiveParameter] mixed $value,
        string $pattern,
        #[SensitiveParameter] array $sensitiveValues
    ): ?string {
        if (!is_string($value) || strlen($value) > 128 || preg_match($pattern, $value) !== 1) {
            return null;
        }
        foreach ($sensitiveValues as $secret) {
            if ($secret !== '' && str_contains($value, $secret)) {
                return null;
            }
        }
        return $value;
    }
}
