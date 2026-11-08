<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use Jojeringnax\Airwallex\Webhook\WebhookVerifier;
use SensitiveParameter;
use Throwable;
use stdClass;

/** Verify immutable private captures before parsing; never dispatch a callback mutation. */
final class WebhookDeliveryEvidence
{
    /** @var array<string,bool> */
    private array $processed = [];
    /** @var array<string,string> */
    private array $events = [];
    /** @var array<string,int> */
    private array $counts = ['captured_count' => 0, 'mac_verified_count' => 0,
        'receipt_freshness_verified_count' => 0, 'current_freshness_verified_count' => 0,
        'invalid_signature_count' => 0, 'unrecognized_or_foreign_count' => 0,
        'matching_created_count' => 0, 'matching_cancelled_count' => 0, 'duplicate_matching_count' => 0];

    /** @param Closure():int $clock */
    public function __construct(
        private readonly WebhookDeliveryStore $store,
        #[SensitiveParameter] private readonly string $secret,
        private readonly string $registration,
        private readonly string $context,
        private readonly string $source,
        #[SensitiveParameter] private readonly string $account,
        #[SensitiveParameter] private readonly string $intent,
        #[SensitiveParameter] private readonly string $order,
        #[SensitiveParameter] private readonly string $marker,
        private readonly int $started,
        private readonly Closure $clock,
    ) {
    }

    /** @return array<string,int> */
    public function collect(): array
    {
        $names = @scandir($this->store->folder());
        if ($names === false) {
            throw new ProtocolException('Private capture inventory is unavailable.');
        }
        $metadata = array_values(array_filter(
            $names,
            static fn (string $name): bool => preg_match('/^capture-\d{6}\.json$/D', $name) === 1
        ));
        if (count($metadata) > 16) {
            throw new ProtocolException('Private capture inventory exceeds its boundary.');
        }
        foreach ($metadata as $name) {
            if (isset($this->processed[$name])) {
                continue;
            }
            $ordinal = substr($name, 8, 6);
            if (!ctype_digit($ordinal) || (int) $ordinal < 1 || (int) $ordinal > 16) {
                throw new ProtocolException('Private capture identity is outside its boundary.');
            }
            $value = json_decode($this->store->read($name, 4096), true, depth: 16, flags: JSON_THROW_ON_ERROR);
            if (!is_array($value) || array_is_list($value)) {
                throw new ProtocolException('Private capture metadata is unavailable.');
            }
            $body = $this->store->read(substr($name, 0, -5) . '.body');
            $timestamp = $value['timestamp'] ?? null;
            $signature = $value['signature'] ?? null;
            $received = $value['received_at_ms'] ?? null;
            $now = ($this->clock)();
            if (
                ($value['format'] ?? null) !== 1 || !is_string($timestamp) || strlen($timestamp) > 64
                || !is_string($signature) || strlen($signature) > 256 || !is_int($received) || $received < 0
                || $received < $this->started - 1000 || $now < 0 || $now > PHP_INT_MAX - 1000
                || $received > $now + 1000
                || ($value['context'] ?? null) !== $this->context || ($value['source'] ?? null) !== $this->source
                || ($value['registration'] ?? null) !== $this->registration
                || ($value['length'] ?? null) !== strlen($body)
                || ($value['sha256'] ?? null) !== hash('sha256', $body)
            ) {
                throw new ProtocolException('Private capture provenance or original bytes do not match.');
            }
            $this->processed[$name] = true;
            ++$this->counts['captured_count'];
            if (
                preg_match('/^[a-fA-F0-9]{64}$/D', $signature) !== 1
                || !hash_equals(hash_hmac('sha256', $timestamp . $body, $this->secret), strtolower($signature))
            ) {
                ++$this->counts['invalid_signature_count'];
                continue;
            }
            ++$this->counts['mac_verified_count'];
            try {
                (new WebhookVerifier($this->secret, 300000, static fn (): int => $received))
                    ->verify($body, $timestamp, $signature);
            } catch (Throwable) {
                ++$this->counts['invalid_signature_count'];
                continue;
            }
            ++$this->counts['receipt_freshness_verified_count'];
            try {
                (new WebhookVerifier($this->secret, 300000, $this->clock))->verify($body, $timestamp, $signature);
                ++$this->counts['current_freshness_verified_count'];
            } catch (Throwable) {
                // A later freshness failure never changes the trusted original receipt clock.
            }
            try {
                $literal = WebhookDeliveryApi::assertUnambiguousObject($body);
                $event = WebhookEvent::fromJson($body)->toArray();
            } catch (Throwable) {
                ++$this->counts['unrecognized_or_foreign_count'];
                continue;
            }
            $nameValue = $literal->name ?? null;
            $id = $literal->id ?? null;
            $data = $event['data'] ?? null;
            $object = is_array($data) ? ($data['object'] ?? null) : null;
            $literalData = $literal->data ?? null;
            $literalObject = $literalData instanceof stdClass ? ($literalData->object ?? null) : null;
            $status = $nameValue === 'payment_intent.created' ? 'REQUIRES_PAYMENT_METHOD' : 'CANCELLED';
            if (
                !is_string($id) || $id === '' || strlen($id) > 1024
                || !is_string($literal->created_at ?? null) || $literal->created_at === ''
                || !in_array($nameValue, ['payment_intent.created', 'payment_intent.cancelled'], true)
                || ($literal->version ?? null) !== '2026-08-21' || ($literal->account_id ?? null) !== $this->account
                || ($literal->org_id ?? null) !== null
                || !in_array($literal->accountId ?? null, [null, ''], true) || !is_array($object)
                || !($literalObject instanceof stdClass) || !is_string($literalObject->id ?? null)
                || (property_exists($literalObject, 'account_id') && $literalObject->account_id !== $this->account)
                || !self::ownIntent($object, $this->intent, $this->order, $this->marker, $status)
            ) {
                ++$this->counts['unrecognized_or_foreign_count'];
                continue;
            }
            if (isset($this->events[$id])) {
                if (!hash_equals($this->events[$id], hash('sha256', $body))) {
                    ++$this->counts['unrecognized_or_foreign_count'];
                    continue;
                }
                ++$this->counts['duplicate_matching_count'];
            }
            $this->events[$id] = hash('sha256', $body);
            $count = $nameValue === 'payment_intent.created' ? 'matching_created_count' : 'matching_cancelled_count';
            ++$this->counts[$count];
        }
        return $this->counts;
    }

    /** @param array<array-key,mixed> $value */
    public static function ownIntent(
        #[SensitiveParameter] array $value,
        #[SensitiveParameter] string $id,
        #[SensitiveParameter] string $order,
        #[SensitiveParameter] string $marker,
        string $status,
    ): bool {
        $metadata = $value['metadata'] ?? null;
        return ($value['id'] ?? null) === $id && ($value['merchant_order_id'] ?? null) === $order
            && ($value['currency'] ?? null) === 'HKD' && ExactDecimal::equalsLiteral($value['amount'] ?? null, '1.23')
            && is_array($metadata) && ($metadata['sdk_validation_run'] ?? null) === $marker
            && ($value['status'] ?? null) === $status && ($value['customer_id'] ?? null) === null
            && ($value['connected_account_id'] ?? null) === null
            && (!array_key_exists('captured_amount', $value)
                || ExactDecimal::equalsLiteral($value['captured_amount'], '0'));
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['captures' => '[private]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Private capture evidence cannot be serialized.');
    }
}
