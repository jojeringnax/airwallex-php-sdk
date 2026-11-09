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

/** Separate account-management and organization-producer providers; no organization identifier is inferred. */
final class BillingWebhookApi
{
    public readonly WebhookDeliveryApi $management;
    public readonly WebhookDeliveryTransport $managementTransport;
    public readonly BillingWebhookTransport $producerTransport;
    public readonly Client $producer;
    private readonly BillingWebhookTransport $prerequisiteTransport;
    private readonly Configuration $configuration;

    /** @param (Closure():float)|null $clock Monotonic seconds. */
    public function __construct(
        #[SensitiveParameter] Configuration $configuration,
        ?ClientInterface $http = null,
        ?float $deadline = null,
        ?Closure $clock = null,
        WebhookManagementProfile $managementProfile = WebhookManagementProfile::Account,
    ) {
        $this->configuration = $configuration;
        $this->managementTransport = new WebhookDeliveryTransport(
            $configuration->accountId(),
            $http,
            clock: $clock,
            overallDeadline: $deadline,
            managementOnly: true,
            managementProfile: $managementProfile,
        );
        $this->management = new WebhookDeliveryApi(
            $configuration,
            $this->managementTransport,
            WebhookDeliveryEvents::BillingCheckout,
            $managementProfile,
        );
        $this->prerequisiteTransport = new BillingWebhookTransport(
            $configuration->accountId(),
            false,
            $http,
            clock: $clock,
            overallDeadline: $deadline
        );
        $this->producerTransport = new BillingWebhookTransport(
            $configuration->accountId(),
            true,
            $http,
            clock: $clock,
            overallDeadline: $deadline
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

    /** @return array{management:string,producer:string,collector:string,organization_reference:string,composite:string} */
    public static function contexts(
        #[SensitiveParameter] Configuration $configuration,
        WebhookManagementProfile $managementProfile = WebhookManagementProfile::Account,
    ): array {
        $role = static fn (string $context, ?string $login): string => hash('sha256', json_encode([
            'environment' => 'sandbox', 'credentials' => $configuration->credentials()->fingerprint(),
            'context' => $context, 'login_account' => $login,
        ], JSON_THROW_ON_ERROR));
        $account = $role('account', $configuration->accountId());
        $management = $managementProfile === WebhookManagementProfile::Account ? $account
            : $role('default-management', null);
        $organization = $role('organization', null);
        $collector = hash('sha256', $configuration->accountId());
        $organizationReference = hash('sha256', json_encode($configuration->organizationId(), JSON_THROW_ON_ERROR));
        return ['organization_reference' => $organizationReference,
            'management' => $management, 'producer' => $organization, 'collector' => $collector,
            'composite' => hash('sha256', json_encode([$management, $organization, $collector, $organizationReference,
                WebhookDeliveryEvents::BillingCheckout->names(), '2026-08-21',
                ...($managementProfile === WebhookManagementProfile::DefaultLogin ? ['DEFAULT'] : []),
            ], JSON_THROW_ON_ERROR))];
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
    public static function checkout(#[SensitiveParameter] array $data, #[SensitiveParameter] array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $data) || $data[$key] !== $value) {
                return false;
            }
        }
        return ($expected['status'] ?? null) !== 'CANCELLED'
            || (!array_key_exists('url', $data) || $data['url'] === null);
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
