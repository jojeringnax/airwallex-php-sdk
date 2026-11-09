<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\HttpFactory;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Internal\Auth\TokenProvider;
use Jojeringnax\Airwallex\Internal\Http\HttpExchange;
use Jojeringnax\Airwallex\Internal\Http\ResponseDecoder;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;

/** Fresh selected management, independent account prerequisite and organization future-Subscription providers. */
final class SubscriptionWebhookApi
{
    public readonly WebhookDeliveryApi $management;
    public readonly WebhookDeliveryTransport $managementTransport;
    public readonly SubscriptionWebhookTransport $producerTransport;
    public readonly Client $producer;
    private readonly SubscriptionWebhookTransport $prerequisiteTransport;
    private readonly Configuration $configuration;

    /** @param (Closure():float)|null $clock Monotonic seconds. */
    public function __construct(
        #[SensitiveParameter] Configuration $configuration,
        ?ClientInterface $http = null,
        ?float $deadline = null,
        ?Closure $clock = null,
        /** @var array<string,string> */
        private readonly array $tools = [],
        /** @var array<string,string> */
        private readonly array $source = [],
        ?float $caseStarted = null,
        WebhookManagementProfile $managementProfile = WebhookManagementProfile::DefaultLogin,
    ) {
        $this->configuration = $configuration;
        $clock ??= static fn (): float => hrtime(true) / 1_000_000_000;
        if ($deadline !== null && $caseStarted === null) {
            throw new ProtocolException('An explicit Subscription window requires its original clock sample.');
        }
        $caseStarted ??= $clock();
        $deadline = SubscriptionWebhookTransport::caseDeadline($caseStarted, $deadline);
        $this->managementTransport = new WebhookDeliveryTransport(
            $configuration->accountId(),
            $http,
            clock: $clock,
            overallDeadline: $deadline,
            managementOnly: true,
            managementProfile: $managementProfile,
            eventPair: WebhookDeliveryEvents::Subscription,
            subscriptionStarted: $caseStarted,
        );
        $this->management = new WebhookDeliveryApi(
            $configuration,
            $this->managementTransport,
            WebhookDeliveryEvents::Subscription,
            $managementProfile,
        );
        $this->prerequisiteTransport = new SubscriptionWebhookTransport(
            $http,
            $clock,
            $deadline,
            $configuration->accountId(),
            $caseStarted
        );
        $this->producerTransport = new SubscriptionWebhookTransport(
            $http,
            $clock,
            $deadline,
            caseStarted: $caseStarted
        );
        $factory = new HttpFactory();
        $this->producer = new Client(
            Environment::Sandbox,
            $configuration->credentials(),
            $this->producerTransport,
            $factory,
            $factory,
            retryPolicy: new RetryPolicy(maxAttempts: 1)
        );
    }

    public function assertSource(RunLedger $ledger): void
    {
        if ($ledger->evidence()['source'] !== $this->source || $this->source !== SourceEvidence::capture()) {
            throw new ProtocolException('The original Subscription source changed before a mutation.');
        }
        $root = dirname(__DIR__, 4);
        foreach (
            ['entry' => '/tools/billing-subscription-webhook-probe.php',
            'receiver' => '/tools/webhooks/webhook-receiver.cjs',
            'supervisor' => '/tools/webhooks/webhook-supervisor.cjs'] as $name => $path
        ) {
            if (!isset($this->tools[$name]) || @hash_file('sha256', $root . $path) !== $this->tools[$name]) {
                throw new ProtocolException('The original Subscription tool source changed before a mutation.');
            }
        }
    }

    /** @return array{management:string,
        producer:string,
        collector:string,
        organization_reference:string,
        composite:string} */
    public static function contexts(
        #[SensitiveParameter] Configuration $configuration,
        WebhookManagementProfile $managementProfile = WebhookManagementProfile::DefaultLogin,
    ): array {
        $contexts = BillingWebhookApi::contexts($configuration, $managementProfile);
        $contexts['composite'] = hash('sha256', json_encode([$contexts, WebhookDeliveryEvents::Subscription->names(),
            '2026-08-21', 'billing-subscription-webhook-pair'], JSON_THROW_ON_ERROR));
        return $contexts;
    }

    public function legalEntity(): string
    {
        $factory = new HttpFactory();
        $exchange = new HttpExchange(Environment::Sandbox, $this->prerequisiteTransport, $factory, $factory);
        $decoder = new ResponseDecoder();
        $tokens = new TokenProvider(
            $exchange,
            $decoder,
            $this->configuration->credentials(),
            Environment::Sandbox,
            $this->configuration->accountId(),
            null,
            null,
            static fn (): int => time()
        );
        $data = $decoder->decode($exchange->send('GET', '/api/v1/account', [
            'Authorization' => 'Bearer ' . $tokens->token(), 'x-api-version' => '2026-08-21',
            'Accept' => 'application/json',
        ]), sensitiveValues: $tokens->sensitiveValues());
        $details = $data['account_details'] ?? null;
        $entity = is_array($details) ? ($details['legal_entity_id'] ?? null) : null;
        if (
            ($data['id'] ?? null) !== $this->configuration->accountId()
            || !is_string($entity) || !WebhookDeliveryTransport::validIdentity($entity)
        ) {
            throw new ProtocolException('The configured account prerequisite did not verify its own legal entity.');
        }
        return $entity;
    }

    /** @param array<array-key,mixed> $data */
    public static function identity(#[SensitiveParameter] array $data): string
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || !WebhookDeliveryTransport::validIdentity($id)) {
            throw new ProtocolException('The returned Billing resource has no bounded path identity.');
        }
        return $id;
    }

    /** @param array<array-key,mixed> $data */
    public static function customer(
        #[SensitiveParameter] array $data,
        #[SensitiveParameter] string $id,
        #[SensitiveParameter] string $entity,
        #[SensitiveParameter] string $marker,
    ): bool {
        $metadata = $data['metadata'] ?? null;
        return ($data['id'] ?? null) === $id && ($data['default_billing_currency'] ?? null) === 'EUR'
            && ($data['default_legal_entity_id'] ?? null) === $entity && is_array($metadata)
            && ($metadata['sdk_validation_run'] ?? null) === $marker;
    }

    /**
     * @param array<array-key,mixed> $data
     * @param array<string,mixed> $expected
     */
    public static function fields(#[SensitiveParameter] array $data, #[SensitiveParameter] array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $data) || $data[$key] !== $value) {
                return false;
            }
        }
        return true;
    }

    /** @param array<array-key,mixed> $data */
    public static function product(
        #[SensitiveParameter] array $data,
        #[SensitiveParameter] string $id,
        #[SensitiveParameter] string $marker,
        bool $active = true
    ): bool {
        return self::fields($data, ['id' => $id, 'name' => $marker, 'active' => $active,
            'metadata' => ['sdk_validation_run' => $marker]]);
    }

    /** @param array<array-key,mixed> $data */
    public static function price(array $data, string $id, string $product, string $marker, bool $active = true): bool
    {
        return self::fields($data, ['id' => $id, 'product_id' => $product, 'currency' => 'EUR',
            'pricing_model' => 'PER_UNIT', 'billing_type' => 'IN_ADVANCE', 'active' => $active,
            'metadata' => ['sdk_validation_run' => $marker]])
            && self::period($data['recurring'] ?? null)
            && ExactDecimal::equalsLiteral($data['unit_amount'] ?? null, '1.23');
    }

    /**
     * @param array<array-key,mixed> $data
     * @param array<string,mixed> $expected
     */
    public static function subscription(
        #[SensitiveParameter] array $data,
        #[SensitiveParameter] array $expected,
        string $status
    ): bool {
        $start = $expected['starts_at'] ?? null;
        unset($expected['starts_at']);
        if (
            !self::fields($data, $expected + ['status' => $status,
            'cancel_at_period_end' => false, 'enable_automatic_tax' => false])
            || !self::integer($data['days_until_due'] ?? null, 30)
            || !self::period($data['recurring'] ?? null) || !self::period($data['duration'] ?? null)
            || !is_string($start) || !is_string($data['starts_at'] ?? null)
            || !self::sameInstant($start, $data['starts_at'])
        ) {
            return false;
        }
        foreach (['payment_source_id', 'linked_payment_account_id', 'trial_starts_at', 'trial_ends_at'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                return false;
            }
        }
        return array_key_exists('applied_discounts', $data) && $data['applied_discounts'] === []
            && ($data['default_tax_percent'] ?? null) === null;
    }

    public static function integer(mixed $value, int $expected): bool
    {
        return $value === $expected || $value === (string) $expected;
    }

    private static function period(mixed $value): bool
    {
        return is_array($value) && count($value) === 2 && ($value['period_unit'] ?? null) === 'MONTH'
            && self::integer($value['period'] ?? null, 1);
    }

    public static function sameInstant(string $left, string $right): bool
    {
        try {
            foreach ([$left, $right] as $value) {
                if (
                    preg_match(
                        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})$/D',
                        $value
                    ) !== 1
                ) {
                    return false;
                }
                new \DateTimeImmutable($value);
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                    return false;
                }
            }
            return (new \DateTimeImmutable($left))->format('U.u') === (new \DateTimeImmutable($right))->format('U.u');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<array-key,mixed> $data */
    public static function item(
        array $data,
        string $id,
        string $subscription,
        string $price,
        string $product,
        string $marker
    ): bool {
        $nested = $data['price'] ?? null;
        $nestedProduct = is_array($nested) ? ($nested['product'] ?? null) : null;
        return self::fields($data, ['id' => $id, 'subscription_id' => $subscription])
            && self::integer($data['quantity'] ?? null, 1)
            && ($data['rate_card_version'] ?? null) === null
            && array_key_exists('applied_discounts', $data) && $data['applied_discounts'] === []
            && ($data['tax_percent'] ?? null) === null
            && is_array($nested) && self::price($nested, $price, $product, $marker)
            && is_array($nestedProduct) && self::product($nestedProduct, $product, $marker);
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['contexts' => '[isolated private roles]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Billing delivery clients cannot be serialized.');
    }
}
