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
final class BillingWebhookEvidence
{
    /** @var array<string,bool> */
    private array $processed = [];
    /** @var array<string,string> */
    private array $events = [];
    /** @var array<string,int> */
    private array $counts = ['captured_count' => 0, 'mac_verified_count' => 0,
        'receipt_freshness_verified_count' => 0, 'current_freshness_verified_count' => 0,
        'invalid_signature_count' => 0, 'unrecognized_or_foreign_count' => 0,
        'matching_created_count' => 0, 'matching_cancelled_count' => 0, 'duplicate_matching_count' => 0,
        'owner_matching_created_count' => 0, 'owner_matching_cancelled_count' => 0,
        'contract_matching_created_count' => 0, 'contract_matching_cancelled_count' => 0,
        'conflicting_event_count' => 0, 'version_matching_created_count' => 0, 'version_matching_cancelled_count' => 0];
    /** @var list<array<string,string|bool>> */
    private array $observations = [];

    /** @param Closure():int $clock */
    public function __construct(
        private readonly WebhookDeliveryStore $store,
        #[SensitiveParameter] private readonly string $secret,
        private readonly string $registration,
        private readonly string $context,
        private readonly string $source,
        #[SensitiveParameter] private readonly string $account,
        #[SensitiveParameter] private readonly string $collector,
        /** @var array<string,mixed> */
        #[SensitiveParameter] private readonly array $expected,
        private readonly int $started,
        private readonly Closure $clock,
        #[SensitiveParameter] private readonly ?string $organization = null,
    ) {
    }

    /** @return array<string,mixed> */
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
            $literalEnvelope = get_object_vars($literal);
            $nameValue = $literal->name ?? null;
            $kind = match ($nameValue) {
                'billing_checkout.created' => 'created',
                'billing_checkout.cancelled' => 'cancelled',
                default => 'unlisted',
            };
            $id = $literal->id ?? null;
            $data = $event['data'] ?? null;
            $shape = !is_array($data) || array_is_list($data) ? 'unrecognized'
                : (array_key_exists('object', $data)
                    ? (array_key_exists('id', $data) ? 'ambiguous' : 'generic') : 'direct');
            $object = null;
            if (is_array($data)) {
                if ($shape === 'generic') {
                    $object = $data['object'];
                } elseif ($shape === 'direct') {
                    $object = $data;
                }
            }
            $literalData = $literal->data ?? null;
            $literalObject = $shape === 'generic' && $literalData instanceof stdClass
                ? ($literalData->object ?? null) : ($shape === 'direct' ? $literalData : null);
            $literalResource = $literalObject instanceof stdClass ? get_object_vars($literalObject) : null;
            $version = self::comparison($literalEnvelope, 'version', '2026-08-21');
            $projection = $this->expected + ['status' => $kind === 'created' ? 'ACTIVE' : 'CANCELLED'];
            $stringMatches = [];
            foreach (
                ['id', 'billing_customer_id', 'legal_entity_id', 'linked_payment_account_id',
                'mode', 'ui_mode', 'currency', 'status'] as $field
            ) {
                $value = is_array($literalResource) ? ($literalResource[$field] ?? null) : null;
                $stringMatches[$field] = is_string($value) && $value === ($projection[$field] ?? null);
            }
            $resource = $kind !== 'unlisted' && is_array($object)
                && !in_array(false, $stringMatches, true) && BillingWebhookApi::checkout($object, $projection);
            $org = self::comparison($literalEnvelope, 'org_id', $this->organization);
            $account = self::comparison($literalEnvelope, 'account_id', $this->account);
            $collector = self::comparison($literalEnvelope, 'account_id', $this->collector);
            $objectOrg = is_array($literalResource)
                ? self::comparison($literalResource, 'org_id', $this->organization) : 'unavailable';
            $objectAccount = is_array($literalResource)
                ? self::comparison($literalResource, 'account_id', $this->account) : 'unavailable';
            // Use the SDK's owner-field shape rule plus local literal JSON type gates; never coerce numeric owners.
            $otherOwnerNeutral = in_array($literalEnvelope['account_id'] ?? null, [null, ''], true);
            $legacyOwnerNeutral = in_array($literalEnvelope['accountId'] ?? null, [null, ''], true);
            $owner = $org === 'match' && $otherOwnerNeutral && $legacyOwnerNeutral
                && in_array($objectOrg, ['absent', 'match'], true)
                && in_array($objectAccount, ['absent', 'match'], true);
            $observation = ['event' => $kind, 'shape' => $shape, 'version' => $version,
                'resource_matches' => $resource, 'typed_owner_verified' => $owner,
                'org_id' => $org,
                'account_id_configured_manager' => $account,
                'account_id_collector' => $collector,
                'object_account_id_configured_manager' => $objectAccount,
                'object_org_id' => $objectOrg,
                'legacy_accountId' => self::comparison($literalEnvelope, 'accountId', null),
                'sdk_other_owner_neutral' => $otherOwnerNeutral, 'sdk_legacy_owner_neutral' => $legacyOwnerNeutral];
            foreach (
                ['id', 'billing_customer_id', 'legal_entity_id', 'linked_payment_account_id',
                'mode', 'ui_mode', 'currency', 'metadata', 'status'] as $field
            ) {
                $observation[$field . '_matches'] = $field === 'metadata'
                    ? is_array($object) && array_key_exists($field, $object)
                        && array_key_exists($field, $projection) && $object[$field] === $projection[$field]
                    : $stringMatches[$field];
            }
            if (!is_string($id) || $id === '' || strlen($id) > 1024) {
                ++$this->counts['unrecognized_or_foreign_count'];
                $this->observations[] = $observation;
                continue;
            }
            $hash = hash('sha256', $body);
            if (isset($this->events[$id])) {
                if (!hash_equals($this->events[$id], $hash)) {
                    ++$this->counts['conflicting_event_count'];
                } elseif ($resource) {
                    ++$this->counts['duplicate_matching_count'];
                }
                $this->observations[] = $observation;
                continue;
            }
            $this->events[$id] = $hash;
            if ($resource) {
                ++$this->counts[$kind === 'created' ? 'matching_created_count' : 'matching_cancelled_count'];
                if ($owner) {
                    ++$this->counts[$kind === 'created'
                        ? 'owner_matching_created_count' : 'owner_matching_cancelled_count'];
                }
                if (
                    $owner && $version === 'match' && $shape === 'generic'
                    && is_string($literal->created_at ?? null) && $literal->created_at !== ''
                ) {
                    ++$this->counts[$kind === 'created'
                        ? 'contract_matching_created_count' : 'contract_matching_cancelled_count'];
                }
                if ($version === 'match') {
                    ++$this->counts[$kind === 'created'
                        ? 'version_matching_created_count' : 'version_matching_cancelled_count'];
                }
            } else {
                ++$this->counts['unrecognized_or_foreign_count'];
            }
            $this->observations[] = $observation;
        }
        return $this->counts + ['events' => $this->observations,
            'organization_comparison' => $this->organization === null ? 'unavailable' : 'available',
            'typed_owner_verified' => $this->counts['owner_matching_created_count'] > 0
                && $this->counts['owner_matching_cancelled_count'] > 0
                && $this->counts['conflicting_event_count'] === 0];
    }

    /** @param array<array-key,mixed> $event */
    private static function comparison(
        #[SensitiveParameter] array $event,
        string $key,
        #[SensitiveParameter] ?string $expected,
    ): string {
        if (!array_key_exists($key, $event)) {
            return 'absent';
        }
        $value = $event[$key];
        if (!is_string($value)) {
            return 'wrong_type';
        }
        if ($value === '') {
            return 'empty';
        }
        return $expected === null ? 'expectation_unavailable' : ($value === $expected ? 'match' : 'mismatch');
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
