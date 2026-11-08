<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Jojeringnax\Airwallex\Exception\ProtocolException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameter;

/** Passive operation-scoped API 2026-08-21 diagnostics; never returns provider text or values. */
final class BillingScheduleDiagnostics implements ClientInterface
{
    private const array CODES = [
        'update' => ['validation_error', 'invalid_status_for_operation', 'resource_not_found',
            'duplicate_request_id', 'unauthorized', 'internal_error'],
        'cancel' => ['validation_error', 'invalid_status_for_operation', 'unauthorized',
            'resource_not_found', 'internal_error'],
    ];
    private const array FIELDS = [
        'request_id', 'duration', 'remaining_duration', 'ends_at', 'period', 'period_unit',
        'cancel_at_period_end', 'starts_at', 'trial_ends_at', 'proration_behavior',
        'collection_method', 'linked_payment_account_id', 'legal_entity_id',
    ];
    /** @var list<array<string,mixed>> */
    private array $records = [];

    public function __construct(private readonly ClientInterface $inner)
    {
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $operation = $path === '/api/v1/billing/subscriptions/create' ? 'create' : null;
        if (preg_match('~^/api/v1/billing/subscriptions/[^/]+/(update|cancel)$~D', $path, $match) === 1) {
            $operation = $match[1];
        }
        $target = $request->getMethod() === 'POST' && $request->getUri()->getScheme() === 'https'
            && $request->getUri()->getHost() === 'api.sandbox.airwallex.com' && $operation !== null;
        $response = $this->inner->sendRequest($request);
        if (!$target || $response->getStatusCode() < 400 || count($this->records) >= 32) {
            return $response;
        }
        $record = ['operation' => $operation, 'http_status' => $response->getStatusCode(), 'body_inspected' => false];
        $stream = $response->getBody();
        if (!$stream->isSeekable()) {
            $this->records[] = $record;
            return $response;
        }
        $position = null;
        try {
            $position = $stream->tell();
            $stream->rewind();
            $body = $stream->read(65537);
            if (strlen($body) <= 65536 && $stream->eof()) {
                $data = json_decode($body, true, 32);
                $data = is_array($data) ? $data : [];
                if ($operation === 'create') {
                    $evidence = SubscriptionErrorEvidence::inspect($response->getStatusCode(), $body);
                    $code = $evidence['provider_code'];
                } else {
                    $value = $data['code'] ?? null;
                    $code = is_string($value) && in_array($value, self::CODES[$operation], true) ? $value : null;
                }
                $fields = [];
                foreach (['message', 'source'] as $key) {
                    $text = $data[$key] ?? null;
                    if (!is_string($text) || strlen($text) > 8192) {
                        continue;
                    }
                    foreach (self::FIELDS as $field) {
                        if (
                            preg_match('/(?<![A-Za-z0-9_.])' . preg_quote($field, '/')
                            . '(?![A-Za-z0-9_])/', $text) === 1
                        ) {
                            $fields[$field] = true;
                        }
                    }
                }
                $record += ['code_recognized' => $code !== null, 'provider_code' => $code,
                    'mentioned_fields' => array_keys($fields)];
                $record['body_inspected'] = true;
            }
        } catch (\Throwable) {
            // The SDK decoder owns unreadable payloads; no exception text is retained.
        } finally {
            if ($position !== null) {
                try {
                    $stream->seek($position);
                } catch (\Throwable) {
                    throw new ProtocolException('A diagnostic response position could not be restored.');
                }
            }
        }
        $this->records[] = $record;
        return $response;
    }

    /** @return list<array<string,mixed>> */
    public function evidence(): array
    {
        return $this->records;
    }

    /** @return array<string,int> */
    public function __debugInfo(): array
    {
        return ['record_count' => count($this->records)];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Diagnostic transports cannot be serialized.');
    }
}
