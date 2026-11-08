<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use DateTimeImmutable;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Account-managed subscription, organization-produced own SETUP; no collection or callback mutation. */
final class BillingWebhookProbe
{
    /**
     * @param array<string,mixed> $options
     * @param (Closure(array<string,mixed>):WebhookDeliveryRuntime)|null $runtimeFactory Local-only fixture seam.
     * @param (Closure():int)|null $clock Unix milliseconds, separate from process budgets.
     * @param (Closure(int):void)|null $wait Milliseconds, local-only seam.
     * @param (Closure():float)|null $elapsed Monotonic seconds, deterministic fixture seam.
     * @param (Closure(string,string):RunLedger)|null $ledgers
     * @param (Closure(string,RunLedger):WebhookDeliveryStore)|null $stores
     * @return array<string,mixed>
     */
    public static function run(
        #[SensitiveParameter] array $options,
        ?ClientInterface $http = null,
        ?Closure $runtimeFactory = null,
        ?Closure $clock = null,
        ?Closure $wait = null,
        ?Closure $ledgers = null,
        ?Closure $stores = null,
        ?Closure $elapsed = null,
    ): array {
        $safe = ['scenario' => 'billing-webhook-pair', 'api_version' => '2026-08-21',
            'management_context' => 'account', 'producer_context' => 'organization', 'result' => 'fail',
            'phase' => 'configuration', 'registration_created' => false, 'registration_deleted' => false,
            'registration_outcome_unknown' => false, 'customer_created' => false, 'checkout_created' => false,
            'sdk_active_verified' => false, 'sdk_cancelled_verified' => false, 'producer_outcome_unknown' => false,
            'reconciliation_required' => false, 'signed_resource_pair_observed' => false,
            'organization_comparison' => 'unavailable', 'typed_owner_verified' => false,
            'organization_manager_verified' => false, 'financial_completion_verified' => false];
        $runtime = null;
        $api = null;
        $registration = null;
        $store = null;
        $snapshotHash = null;
        $ledger = null;
        $fingerprint = null;
        $directory = null;
        $checkout = null;
        $expected = null;
        $cleanupEligible = false;
        $failed = false;
        $clock ??= static fn (): int => (int) (new DateTimeImmutable())->format('Uv');
        $wait ??= static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
        $elapsed ??= static fn (): float => hrtime(true) / 1_000_000_000;
        $deadline = $elapsed() + 300;
        try {
            self::validate($options);
            $credentials = self::option($options, 'credentials-file');
            self::external($credentials);
            $directory = self::option($options, 'state-dir');
            $resolvedDirectory = realpath($directory);
            if ($resolvedDirectory === false || is_link($directory)) {
                throw new RuntimeException('The private state directory is unavailable.');
            }
            $directory = $resolvedDirectory;
            WebhookDeliveryStore::directory($directory);
            $node = self::option($options, 'node-bin');
            $binary = self::option($options, 'cloudflared-bin');
            if (realpath($node) !== $node || !is_file($node) || is_link($node) || !is_executable($node)) {
                throw new RuntimeException('The explicit local Node runtime is unavailable.');
            }
            if ($runtimeFactory === null) {
                WebhookDeliverySupervisor::verifyBinary($binary);
            }
            WebhookDeliveryTransport::assertAvailable();
            $configuration = Configuration::load('sandbox', $credentials);
            $organization = $configuration->organizationId();
            $safe['organization_comparison'] = $organization === null ? 'unavailable' : 'available';
            $contexts = BillingWebhookApi::contexts($configuration);
            $fingerprint = $contexts['composite'];
            $safe['context_fingerprints'] = $contexts;
            $safe['phase'] = 'provenance';
            $ledger = $ledgers === null ? RunLedger::create($fingerprint, $directory)
                : $ledgers($fingerprint, $directory);
            $ledger->assertContext($fingerprint);
            $safe += $ledger->evidence();
            $tools = self::tools($node);
            $safe['tool_hashes'] = $tools;
            $source = hash('sha256', json_encode([$ledger->evidence(), $tools, $contexts], JSON_THROW_ON_ERROR));
            $ledger->requestId('billing_webhook_provenance', ['source_fingerprint' => $source,
                'contexts' => $contexts, 'events' => WebhookDeliveryEvents::BillingCheckout->names(),
                'api_version' => '2026-08-21',
                'organization_expectation_sha256' => $contexts['organization_reference']], $fingerprint);
            $store = $stores === null ? new WebhookDeliveryStore($directory, $ledger) : $stores($directory, $ledger);
            $api = new BillingWebhookApi($configuration, $http, $deadline, $elapsed);
            $safe['phase'] = 'account_prerequisite';
            $entity = $api->legalEntity();
            $started = $clock();
            if ($started < 1000 || $started > PHP_INT_MAX - 300000) {
                throw new ProtocolException('The receipt clock is unavailable.');
            }
            self::timeAvailable($deadline, $elapsed);
            $path = '/' . bin2hex(random_bytes(32));
            $config = ['folder' => $store->folder(), 'path' => $path, 'context' => $fingerprint,
                'source' => $source, 'duration_ms' => 300000, 'live' => true, 'binary' => $binary];
            $store->write('cloudflared.yml', '{}');
            $store->write('receiver-config.json', json_encode($config, JSON_THROW_ON_ERROR));
            $safe['phase'] = 'receiver_start';
            $runtime = $runtimeFactory === null ? new WebhookDeliverySupervisor($node, $config)
                : $runtimeFactory($config);
            $destination = $runtime->destination();
            if (!WebhookDeliveryApi::destination($destination) || !str_ends_with($destination, $path)) {
                throw new ProtocolException('The temporary destination does not match its own private path.');
            }
            $body = ['url' => $destination, 'version' => '2026-08-21',
                'events' => WebhookDeliveryEvents::BillingCheckout->names()];
            $request = $ledger->requestId('create_webhook', $body, $fingerprint);
            $store->write('destination.json', json_encode(['configuration' => $body, 'request_id' => $request,
                'context' => $fingerprint, 'source' => $source], JSON_THROW_ON_ERROR));
            self::wire(
                $ledger,
                $fingerprint,
                'registration_create',
                '/api/v1/webhooks/create',
                $body + ['request_id' => $request]
            );
            $safe['phase'] = 'registration_create';
            $created = $api->management->createWebhook($destination, $request);
            if (
                $created['url'] !== $destination || $created['version'] !== '2026-08-21'
                || $created['request_id'] !== $request
            ) {
                throw new ProtocolException('The returned registration differs from its own intent.');
            }
            $snapshotHash = $store->write('registration.json', json_encode(
                ['registration' => $created, 'context' => $fingerprint,
                    'source' => $source, 'provenance' => $ledger->evidence()],
                JSON_THROW_ON_ERROR
            ));
            $registration = $created;
            $safe['registration_created'] = true;
            $api->managementTransport->allowWebhook($created['id']);
            $ledger->recordCreated('webhooks', $created['id']);
            $safe['phase'] = 'registration_read';
            if (!WebhookDeliveryApi::same($created, $api->management->retrieveWebhook($created['id']))) {
                throw new ProtocolException('The registration read does not match its durable proof.');
            }
            $binding = hash('sha256', json_encode([$snapshotHash, $fingerprint, $source], JSON_THROW_ON_ERROR));
            $runtime->arm($binding);
            self::healthy($runtime);
            self::timeAvailable($deadline, $elapsed);
            $safe['phase'] = 'customer_create';
            $customerBody = ['name' => $ledger->runId(), 'email' => $ledger->runId() . '@example.test',
                'default_billing_currency' => 'EUR', 'default_legal_entity_id' => $entity,
                'metadata' => ['sdk_validation_run' => $ledger->runId()]];
            $customerBody['request_id'] = $ledger->requestId('create_delivery_customer', $customerBody, $fingerprint);
            self::wire(
                $ledger,
                $fingerprint,
                'customer_create',
                '/api/v1/billing/billing_customers/create',
                $customerBody
            );
            $customerData = $api->producer->billing()->customers()->create($customerBody);
            $customer = BillingWebhookApi::identity($customerData);
            $ledger->recordCreated('customers', $customer);
            $safe['customer_created'] = true;
            if (!BillingWebhookApi::customer($customerData, $customer, $entity, $ledger->runId())) {
                throw new ProtocolException('The created Customer differs from its own experiment.');
            }
            $api->producerTransport->allowCustomer($customer);
            $safe['phase'] = 'customer_read';
            if (
                !BillingWebhookApi::customer(
                    $api->producer->billing()->customers()->retrieve($customer),
                    $customer,
                    $entity,
                    $ledger->runId()
                )
            ) {
                throw new ProtocolException('The retrieved Customer differs from its own experiment.');
            }
            self::healthy($runtime);
            self::timeAvailable($deadline, $elapsed);
            $safe['phase'] = 'checkout_create';
            $expected = ['billing_customer_id' => $customer, 'legal_entity_id' => $entity,
                'linked_payment_account_id' => $configuration->accountId(), 'mode' => 'SETUP', 'ui_mode' => 'HOSTED',
                'currency' => 'EUR', 'metadata' => ['sdk_validation_run' => $ledger->runId()]];
            $body = $expected + ['success_url' => 'https://example.com', 'back_url' => 'https://example.com'];
            $body['request_id'] = $ledger->requestId('create_delivery_checkout', $body, $fingerprint);
            self::wire($ledger, $fingerprint, 'checkout_create', '/api/v1/billing/billing_checkouts/create', $body);
            $checkoutData = $api->producer->billing()->checkouts()->create($body);
            $checkout = BillingWebhookApi::identity($checkoutData);
            $ledger->recordCreated('checkouts', $checkout);
            $safe['checkout_created'] = true;
            if (!BillingWebhookApi::checkout($checkoutData, ['id' => $checkout, 'status' => 'ACTIVE'] + $expected)) {
                throw new ProtocolException('The created Checkout does not match its own experiment.');
            }
            $api->producerTransport->allowCheckout($checkout);
            $safe['phase'] = 'checkout_read';
            $active = $api->producer->billing()->checkouts()->retrieve($checkout);
            if (!BillingWebhookApi::checkout($active, ['id' => $checkout, 'status' => 'ACTIVE'] + $expected)) {
                throw new ProtocolException('The active Checkout ownership proof failed.');
            }
            $store->write('billing-active.json', json_encode(
                ['id' => $checkout, 'status' => 'ACTIVE'] + $expected,
                JSON_THROW_ON_ERROR
            ));
            $safe['sdk_active_verified'] = true;
            $cleanupEligible = true;
            $evidence = new BillingWebhookEvidence(
                $store,
                $created['secret'],
                $binding,
                $fingerprint,
                $source,
                $configuration->accountId(),
                $configuration->accountId(),
                ['id' => $checkout] + $expected,
                $started,
                $clock,
                $organization
            );
            $safe['phase'] = 'created_delivery_wait';
            $safe['delivery'] = self::collect(
                $runtime,
                $evidence,
                'matching_created_count',
                $wait,
                $deadline,
                $elapsed
            );
            $safe['phase'] = 'checkout_cancel_guard';
            $cleanupEligible = false; // Any owner mismatch at this fresh boundary forbids substitute cleanup.
            self::cancel($api, $ledger, $fingerprint, $checkout, $expected, $wait);
            $safe['sdk_cancelled_verified'] = true;
            $store->write('billing-cancelled.json', json_encode(
                ['id' => $checkout, 'status' => 'CANCELLED'] + $expected,
                JSON_THROW_ON_ERROR
            ));
            $safe['phase'] = 'cancelled_delivery_wait';
            $safe['delivery'] = self::collect(
                $runtime,
                $evidence,
                'matching_cancelled_count',
                $wait,
                $deadline,
                $elapsed
            );
        } catch (Throwable $error) {
            $failed = true;
            $safe['reason'] = 'observation_not_completed';
            if ($error instanceof ApiException) {
                $safe['http_status'] = $error->httpStatus();
                $code = $error->providerCode();
                $safe['code'] = in_array($code, ['field_required', 'invalid_argument', 'unauthorized', 'already_exists',
                    'validation_error', 'credentials_invalid', 'credentials_expired'], true) ? $code : 'unlisted';
            }
        } finally {
            if ($api !== null) {
                // Cleanup is separately authorized only after a full own ACTIVE SDK read and no prior cancel send.
                if (
                    $cleanupEligible && $checkout !== null && $expected !== null && $fingerprint !== null
                    && $directory !== null && !$api->producerTransport->sent('cancel_checkout')
                ) {
                    try {
                        self::cancel(
                            $api,
                            RunLedger::create($fingerprint, $directory),
                            $fingerprint,
                            $checkout,
                            $expected,
                            $wait
                        );
                        $safe['sdk_cancelled_verified'] = true;
                    } catch (Throwable) {
                        $safe['sdk_cancelled_verified'] = false;
                    }
                }
                $safe['registration_outcome_unknown'] = $api->managementTransport->sent('create_webhook')
                    && $registration === null;
                $safe['producer_outcome_unknown'] = ($api->producerTransport->sent('create_customer')
                    && !$safe['customer_created']) || ($api->producerTransport->sent('create_checkout')
                    && !$safe['checkout_created']) || ($api->producerTransport->sent('cancel_checkout')
                    && !$safe['sdk_cancelled_verified']);
                if (
                    $registration !== null && $store !== null && $snapshotHash !== null
                    && $directory !== null && $fingerprint !== null
                ) {
                    try {
                        $safe['registration_deleted'] = self::delete(
                            $api,
                            $registration,
                            $store,
                            $snapshotHash,
                            $directory,
                            $fingerprint
                        );
                    } catch (Throwable) {
                        $safe['registration_deleted'] = false;
                    }
                }
            }
            if ($runtime !== null) {
                try {
                    $safe['process_cleanup'] = $runtime->stop();
                } catch (Throwable) {
                    $safe['process_cleanup'] = ['receiver_closed' => false, 'tunnel_stopped' => false,
                        'logs_closed' => false, 'failed' => true];
                }
                $closed = $safe['process_cleanup'];
                $failed = $failed || $closed['failed'] || !$closed['receiver_closed']
                    || !$closed['tunnel_stopped'] || !$closed['logs_closed'];
            }
        }
        $safe['reconciliation_required'] = $safe['registration_outcome_unknown'] || $safe['producer_outcome_unknown']
            || ($safe['registration_created'] && !$safe['registration_deleted'])
            || ($safe['checkout_created'] && !$safe['sdk_cancelled_verified'])
            || (isset($safe['process_cleanup']) && ($safe['process_cleanup']['failed']
                || !$safe['process_cleanup']['receiver_closed'] || !$safe['process_cleanup']['tunnel_stopped']
                || !$safe['process_cleanup']['logs_closed']));
        $delivery = $safe['delivery'] ?? [];
        $safe['version_pair_verified'] = ($delivery['version_matching_created_count'] ?? 0) > 0
            && ($delivery['version_matching_cancelled_count'] ?? 0) > 0
            && ($delivery['conflicting_event_count'] ?? 0) === 0;
        $safe['signed_resource_pair_observed'] = ($delivery['matching_created_count'] ?? 0) > 0
            && ($delivery['matching_cancelled_count'] ?? 0) > 0 && ($delivery['conflicting_event_count'] ?? 0) === 0;
        $safe['server_lifecycle_verified'] = $safe['sdk_active_verified'] && $safe['sdk_cancelled_verified'];
        $safe['typed_owner_verified'] = ($delivery['owner_matching_created_count'] ?? 0) > 0
            && ($delivery['owner_matching_cancelled_count'] ?? 0) > 0
            && ($delivery['conflicting_event_count'] ?? 0) === 0;
        $safe['selected_envelope_pair_verified'] = ($delivery['contract_matching_created_count'] ?? 0) > 0
            && ($delivery['contract_matching_cancelled_count'] ?? 0) > 0
            && ($delivery['conflicting_event_count'] ?? 0) === 0;
        $complete = $safe['signed_resource_pair_observed'] && $safe['version_pair_verified']
            && $safe['typed_owner_verified'] && $safe['selected_envelope_pair_verified']
            && $safe['server_lifecycle_verified'] && $safe['registration_deleted'];
        $safe['result'] = $failed || $safe['reconciliation_required'] ? 'fail' : ($complete ? 'pass' : 'pending');
        return $safe;
    }

    /** @param array<string,mixed> $options */
    private static function validate(#[SensitiveParameter] array $options): void
    {
        $allowed = ['environment', 'credentials-file', 'state-dir', 'node-bin', 'cloudflared-bin',
            'create-one-billing-webhook-and-checkout'];
        foreach ($options as $name => $value) {
            if (
                !in_array($name, $allowed, true) || ($name === 'create-one-billing-webhook-and-checkout'
                ? $value !== true : !is_string($value) || $value === '')
            ) {
                throw new RuntimeException('The explicit Billing delivery configuration is invalid.');
            }
        }
        if (
            ($options['environment'] ?? null) !== 'sandbox'
            || ($options['create-one-billing-webhook-and-checkout'] ?? null) !== true
        ) {
            throw new RuntimeException('Explicit Sandbox Billing delivery selection is required.');
        }
    }

    /** @param array<string,mixed> $options */
    private static function option(#[SensitiveParameter] array $options, string $key): string
    {
        $value = $options[$key] ?? null;
        return is_string($value) && str_starts_with($value, '/') ? $value
            : throw new RuntimeException('Explicit absolute local delivery paths are required.');
    }

    private static function external(#[SensitiveParameter] string $path): void
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || is_link($path)) {
            throw new RuntimeException('The external Sandbox credential file is unavailable.');
        }
        for ($folder = dirname($resolved);; $folder = dirname($folder)) {
            if (file_exists($folder . '/.git') || is_link($folder . '/.git')) {
                throw new RuntimeException('Credentials must remain outside Git checkouts.');
            }
            if (dirname($folder) === $folder) {
                return;
            }
        }
    }

    /** @return array<string,string> */
    private static function tools(string $node): array
    {
        $root = dirname(__DIR__, 4);
        $files = ['entry' => $root . '/tools/billing-webhook-delivery-probe.php',
            'receiver' => $root . '/tools/webhooks/webhook-receiver.cjs',
            'supervisor' => $root . '/tools/webhooks/webhook-supervisor.cjs', 'node_runtime' => $node];
        $hashes = [];
        foreach ($files as $name => $path) {
            $hash = @hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('The fixed delivery tool source is unavailable.');
            }
            $hashes[$name] = $hash;
        }
        $hashes['tunnel_runtime'] = '2136431d5eb467bf447d2ee514b713e192719b143aec1d9e09bf7dc290d5bb12';
        return $hashes;
    }

    /** @param array<string,mixed>|null $body */
    private static function wire(
        RunLedger $ledger,
        string $context,
        string $action,
        string $path,
        #[SensitiveParameter] ?array $body
    ): void {
        $ledger->recordObservation($action . '_wire', ['wire_sha256' => hash(
            'sha256',
            $body === null ? '' : ($action === 'registration_create'
                ? json_encode($body, JSON_THROW_ON_ERROR) : (new JsonCodec())->encode($body))
        ), 'target_sha256' => hash('sha256', $path)], $context);
    }

    private static function healthy(WebhookDeliveryRuntime $runtime): void
    {
        $state = $runtime->poll();
        foreach (
            ['stream_failure', 'persistence_failure', 'limit_failure', 'closed',
            'supervisor_failed', 'log_limit_failure', 'tunnel_exited'] as $field
        ) {
            if (($state[$field] ?? true) !== false) {
                throw new ProtocolException('The bounded receiver is not healthy.');
            }
        }
    }

    /** @param Closure():float $elapsed */
    private static function timeAvailable(float $deadline, Closure $elapsed): void
    {
        if (!is_finite($elapsed()) || $elapsed() + 90 >= $deadline) {
            throw new ProtocolException('Insufficient bounded time remains for producer work and cleanup.');
        }
    }

    /** @param Closure(int):void $wait
     * @param Closure():float $elapsed
     * @return array<string,mixed>
     */
    private static function collect(
        WebhookDeliveryRuntime $runtime,
        BillingWebhookEvidence $evidence,
        string $field,
        Closure $wait,
        float $deadline,
        Closure $elapsed
    ): array {
        for ($attempt = 0; $attempt < 60; ++$attempt) {
            $observed = $evidence->collect();
            self::healthy($runtime);
            if (($observed[$field] ?? 0) > 0 || $elapsed() + 120 >= $deadline) {
                return $observed;
            }
            $wait(500);
        }
        return $evidence->collect();
    }

    /** @param array<string,mixed> $expected
     * @param Closure(int):void $wait
     */
    private static function cancel(
        BillingWebhookApi $api,
        RunLedger $ledger,
        string $context,
        #[SensitiveParameter] string $id,
        #[SensitiveParameter] array $expected,
        Closure $wait
    ): void {
        $current = $api->producer->billing()->checkouts()->retrieve($id);
        if (!BillingWebhookApi::checkout($current, ['id' => $id, 'status' => 'ACTIVE'] + $expected)) {
            throw new ProtocolException('Cancellation requires a fresh full own ACTIVE Checkout.');
        }
        $path = '/api/v1/billing/billing_checkouts/' . rawurlencode($id) . '/cancel';
        $ledger->requestId('cancel_delivery_checkout', ['method' => 'POST', 'target_sha256' => hash('sha256', $path),
            'wire_sha256' => hash('sha256', ''), 'local_identity_only' => true], $context);
        self::wire($ledger, $context, 'checkout_cancel', $path, null);
        try {
            $api->producer->billing()->checkouts()->cancel($id);
        } catch (Throwable) {
            // A potentially effective cancellation permits only own GET reconciliation, never another send.
        }
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $read = $api->producer->billing()->checkouts()->retrieve($id);
            if (BillingWebhookApi::checkout($read, ['id' => $id, 'status' => 'CANCELLED'] + $expected)) {
                $ledger->recordObservation(
                    'delivery_checkout_cancelled',
                    ['target_sha256' => hash('sha256', $id)],
                    $context
                );
                return;
            }
            if (!BillingWebhookApi::checkout($read, ['id' => $id, 'status' => 'ACTIVE'] + $expected)) {
                throw new ProtocolException('Cancellation readback ownership or state is unrecognized.');
            }
            if ($attempt < 3) {
                $wait(1000);
            }
        }
        throw new ProtocolException('The own cancellation was not verified inside its read bound.');
    }

    /** @param array{id:string,url:string,secret:string,version:string,events:list<string>,request_id:string} $registration */
    private static function delete(
        BillingWebhookApi $api,
        #[SensitiveParameter] array $registration,
        WebhookDeliveryStore $store,
        string $hash,
        string $directory,
        string $context
    ): bool {
        if (!hash_equals($hash, hash('sha256', $store->read('registration.json')))) {
            throw new ProtocolException('The private registration proof changed.');
        }
        $cleanup = RunLedger::create($context, $directory);
        if (!WebhookDeliveryApi::same($registration, $api->management->retrieveWebhook($registration['id']))) {
            throw new ProtocolException('Deletion requires the exact durable own registration.');
        }
        $path = '/api/v1/webhooks/' . rawurlencode($registration['id']) . '/delete';
        $cleanup->requestId('delete_own_webhook', ['method' => 'POST', 'target_sha256' => hash('sha256', $path),
            'snapshot_sha256' => $hash, 'wire_sha256' => hash('sha256', ''), 'local_identity_only' => true], $context);
        self::wire($cleanup, $context, 'registration_delete', $path, null);
        if (
            !$api->management->deleteWebhook($registration['id'])
            || !$api->management->webhookGone($registration['id'])
        ) {
            return false;
        }
        $cleanup->recordObservation('registration_deleted', ['snapshot_sha256' => $hash], $context);
        return true;
    }
}
