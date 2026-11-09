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

/** Separate DEFAULT-management future-Subscription observer; captures never drive mutations. */
final class SubscriptionWebhookProbe
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
        $managementProfile = WebhookManagementProfile::DefaultLogin;
        $safe = ['scenario' => 'billing-subscription-webhook-pair', 'api_version' => '2026-08-21',
            'management_context' => $managementProfile->context(),
            'producer_context' => 'organization', 'result' => 'fail',
            'phase' => 'configuration', 'registration_created' => false, 'registration_deleted' => false,
            'registration_outcome_unknown' => false, 'customer_created' => false, 'subscription_created' => false,
            'sdk_pending_verified' => false, 'sdk_cancelled_verified' => false, 'producer_outcome_unknown' => false,
            'reconciliation_required' => false, 'signed_resource_pair_observed' => false,
            'organization_comparison' => 'unavailable', 'typed_owner_verified' => false,
            'organization_manager_verified' => false, 'financial_completion_verified' => false,
            'cancel_reserved' => false, 'cancel_action_outcome' => 'not_attempted',
            'registration_delete_outcome' => 'not_attempted',
            'product_archived' => false, 'price_archived' => false, 'inventory_completeness' => 'unknown'];
        $safe['management_profile'] = 'DEFAULT';
        $safe['collector_context'] = 'account';
        $runtime = null;
        $api = null;
        $registration = null;
        $store = null;
        $snapshotHash = null;
        $ledger = null;
        $fingerprint = null;
        $directory = null;
        $subscription = null;
        $expected = null;
        $cleanupEligible = false;
        $product = null;
        $price = null;
        $failed = false;
        $evidence = null;
        $clock ??= static fn (): int => (int) (new DateTimeImmutable())->format('Uv');
        $wait ??= static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
        $elapsed ??= static fn (): float => hrtime(true) / 1_000_000_000;
        $deadline = NAN;
        try {
            self::validate($options);
            $caseStarted = $elapsed();
            $started = self::wall($clock);
            $deadline = SubscriptionWebhookTransport::caseDeadline($caseStarted);
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
            $contexts = SubscriptionWebhookApi::contexts($configuration);
            $fingerprint = $contexts['composite'];
            $safe['context_fingerprints'] = $contexts;
            $safe['phase'] = 'provenance';
            $ledger = $ledgers === null ? RunLedger::create($fingerprint, $directory)
                : $ledgers($fingerprint, $directory);
            $ledger->assertContext($fingerprint);
            $safe += $ledger->evidence();
            $tools = self::tools($node, $managementProfile);
            $safe['tool_hashes'] = $tools;
            $source = hash('sha256', json_encode([$ledger->evidence(), $tools, $contexts], JSON_THROW_ON_ERROR));
            $ledger->requestId('billing_webhook_provenance', ['source_fingerprint' => $source,
                'contexts' => $contexts, 'events' => WebhookDeliveryEvents::Subscription->names(),
                'api_version' => '2026-08-21',
                'organization_expectation_sha256' => $contexts['organization_reference'],
                'management_profile' => 'DEFAULT',
            ], $fingerprint);
            $store = $stores === null ? new WebhookDeliveryStore($directory, $ledger) : $stores($directory, $ledger);
            $api = new SubscriptionWebhookApi(
                $configuration,
                $http,
                $deadline,
                $elapsed,
                $tools,
                $ledger->evidence()['source'],
                $caseStarted
            );
            $safe['phase'] = 'account_prerequisite';
            $entity = $api->legalEntity();
            self::timeAvailable($deadline, $elapsed);
            $path = '/' . bin2hex(random_bytes(32));
            $config = ['folder' => $store->folder(), 'path' => $path, 'context' => $fingerprint,
                'source' => $source,
                    'duration_ms' => max(
                        1,
                        min(
                            300000,
                            (int) floor(($deadline - $elapsed()) * 1000)
                        )
                    ),
                    'live' => true,
                    'binary' => $binary];
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
                'events' => WebhookDeliveryEvents::Subscription->names()];
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
            $api->assertSource($ledger);
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
            $customerData = self::mutate(
                $api,
                $ledger,
                $fingerprint,
                'create_customer',
                $customerBody,
                fn (array $body): array => $api->producer->billing()->customers()->create($body),
                static function () use ($runtime, $deadline, $elapsed): void {
                    self::healthy($runtime);
                    self::timeAvailable($deadline, $elapsed);
                }
            );
            $customer = SubscriptionWebhookApi::identity($customerData);
            $ledger->recordCreated('customers', $customer);
            $safe['customer_created'] = true;
            $api->producerTransport->bind('customer', $customer);
            if (
                !SubscriptionWebhookApi::customer($customerData, $customer, $entity, $ledger->runId())
                || !SubscriptionWebhookApi::customer(
                    $api->producer->billing()->customers()->retrieve($customer),
                    $customer,
                    $entity,
                    $ledger->runId()
                )
            ) {
                throw new ProtocolException('The own Customer proof is unavailable.');
            }
            $safe['phase'] = 'catalogue';
            $data = self::mutate(
                $api,
                $ledger,
                $fingerprint,
                'create_product',
                ['name' => $ledger->runId(),
                    'active' => true,
                    'metadata' => ['sdk_validation_run' => $ledger->runId()]],
                fn (array $body): array => $api->producer->billing()->products()->create($body),
                static function () use ($runtime, $deadline, $elapsed): void {
                    self::healthy($runtime);
                    self::timeAvailable($deadline, $elapsed);
                }
            );
            $product = SubscriptionWebhookApi::identity($data);
            $ledger->recordCreated('products', $product);
            $api->producerTransport->bind('product', $product);
            if (
                !SubscriptionWebhookApi::product($data, $product, $ledger->runId())
                || !SubscriptionWebhookApi::product(
                    $api->producer->billing()->products()->retrieve($product),
                    $product,
                    $ledger->runId()
                )
            ) {
                throw new ProtocolException('The own Product proof is unavailable.');
            }
            $data = self::mutate(
                $api,
                $ledger,
                $fingerprint,
                'create_price',
                ['product_id' => $product, 'active' => true, 'currency' => 'EUR', 'unit_amount' => '1.23',
                    'pricing_model' => 'PER_UNIT', 'billing_type' => 'IN_ADVANCE',
                    'recurring' => ['period' => 1, 'period_unit' => 'MONTH'],
                    'metadata' => ['sdk_validation_run' => $ledger->runId()]],
                fn (array $body): array => $api->producer->billing()->prices()->create($body),
                static function () use ($runtime, $deadline, $elapsed): void {
                    self::healthy($runtime);
                    self::timeAvailable($deadline, $elapsed);
                }
            );
            $price = SubscriptionWebhookApi::identity($data);
            $ledger->recordCreated('prices', $price);
            $api->producerTransport->bind('price', $price);
            if (
                !SubscriptionWebhookApi::price($data, $price, $product, $ledger->runId())
                || !SubscriptionWebhookApi::price(
                    $api->producer->billing()->prices()->retrieve($price),
                    $price,
                    $product,
                    $ledger->runId()
                )
            ) {
                throw new ProtocolException('The own Price proof is unavailable.');
            }
            self::healthy($runtime);
            self::timeAvailable($deadline, $elapsed);
            $safe['phase'] = 'subscription_create';
            $start = gmdate('Y-m-d\TH:i:s\Z', intdiv(self::wall($clock), 1000) + 86400);
            $expected = ['billing_customer_id' => $customer, 'legal_entity_id' => $entity,
                'collection_method' => 'OUT_OF_BAND', 'currency' => 'EUR', 'starts_at' => $start,
                'metadata' => ['sdk_validation_run' => $ledger->runId()]];
            $body = $expected + ['items' => [['price_id' => $price, 'quantity' => 1]],
                'days_until_due' => 30, 'duration' => ['period' => 1, 'period_unit' => 'MONTH']];
            $data = self::mutate(
                $api,
                $ledger,
                $fingerprint,
                'create_subscription',
                $body,
                fn (array $wire): array => $api->producer->billing()->subscriptions()->create($wire),
                static function () use ($start, $clock, $runtime, $deadline, $elapsed): void {
                    self::healthy($runtime);
                    self::timeAvailable($deadline, $elapsed);
                    if ((new DateTimeImmutable($start))->getTimestamp() <= intdiv(self::wall($clock), 1000)) {
                        throw new ProtocolException('The original future start elapsed during durability.');
                    }
                }
            );
            $subscription = SubscriptionWebhookApi::identity($data);
            $ledger->recordCreated('subscriptions', $subscription);
            $safe['subscription_created'] = true;
            $api->producerTransport->bind('subscription', $subscription);
            $expected = ['id' => $subscription] + $expected;
            if (!SubscriptionWebhookApi::subscription($data, $expected, 'PENDING')) {
                throw new ProtocolException('The created future Subscription differs from its original terms.');
            }
            self::pending($api, $expected, $clock);
            $safe['pagination'] = self::item($api, $subscription, $price, $product, $ledger->runId());
            $store->write(
                'subscription-pending.json',
                json_encode(
                    $expected + ['status' => 'PENDING'],
                    JSON_THROW_ON_ERROR
                )
            );
            $safe['sdk_pending_verified'] = true;
            $cleanupEligible = true;
            $evidence = new SubscriptionWebhookEvidence(
                $store,
                $created['secret'],
                $binding,
                $fingerprint,
                $source,
                $configuration->accountId(),
                $configuration->accountId(),
                $expected,
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
            $safe['phase'] = 'subscription_cancel_guard';
            $cleanupEligible = false; // A failed current guard cannot authorize substitute cleanup.
            self::cancel($api, $ledger, $fingerprint, $expected, $price, $product, $clock, $wait, $safe);
            $failed = $safe['cancel_action_outcome'] !== 'accepted';
            $store->write(
                'subscription-cancelled.json',
                json_encode(
                    $expected + ['status' => 'CANCELLED'],
                    JSON_THROW_ON_ERROR
                )
            );
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
                if (
                    $cleanupEligible && $subscription !== null && $expected !== null && $fingerprint !== null
                    && $ledger !== null && $price !== null && $product !== null && !$safe['cancel_reserved']
                ) {
                    $cleanupEligible = false;
                    try {
                        self::cancel($api, $ledger, $fingerprint, $expected, $price, $product, $clock, $wait, $safe);
                    } catch (Throwable) {
                        $safe['sdk_cancelled_verified'] = false;
                    }
                }
                // An unknown Subscription or Price create forbids catalogue adoption/cleanup.
                if (
                    $ledger !== null && $fingerprint !== null
                    && (!$api->producerTransport->sent('create_subscription') || $safe['sdk_cancelled_verified'])
                    && (!$api->producerTransport->sent('create_price') || $price !== null)
                ) {
                    try {
                        self::archive($api, $ledger, $fingerprint, $price, $product, $safe);
                    } catch (Throwable) {
                        $failed = true;
                    }
                }
                $safe['registration_outcome_unknown'] = $api->managementTransport->sent('create_webhook')
                    && $registration === null;
                $safe['producer_outcome_unknown'] = ($api->producerTransport->sent('create_customer')
                    && !$safe['customer_created']) || ($api->producerTransport->sent('create_subscription')
                    && !$safe['subscription_created']) || ($api->producerTransport->sent('cancel_subscription')
                    && !$safe['sdk_cancelled_verified'])
                    || ($api->producerTransport->sent('create_product') && $product === null)
                    || ($api->producerTransport->sent('create_price') && $price === null);
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
                            $fingerprint,
                            $ledgers,
                            $safe
                        );
                        $failed = $failed || $safe['registration_delete_outcome'] !== 'accepted';
                    } catch (Throwable) {
                        $safe['registration_deleted'] = false;
                    }
                }
            }
            if ($runtime !== null) {
                try {
                    $processCleanup = $runtime->stop();
                } catch (Throwable) {
                    $processCleanup = ['receiver_closed' => false, 'tunnel_stopped' => false,
                        'logs_closed' => false, 'failed' => true];
                }
                $safe['process_cleanup'] = $processCleanup;
                $closed = $processCleanup;
                $failed = $failed || $closed['failed'] || !$closed['receiver_closed']
                    || !$closed['tunnel_stopped'] || !$closed['logs_closed'];
            }
        }
        if ($evidence !== null) {
            try {
                $safe['delivery'] = $evidence->collect();
            } catch (Throwable) {
                $failed = true;
                unset($safe['delivery']);
            }
        }
        $processCleanup = is_array($safe['process_cleanup'] ?? null) ? $safe['process_cleanup'] : null;
        $safe['reconciliation_required'] = $safe['registration_outcome_unknown'] || $safe['producer_outcome_unknown']
            || ($safe['registration_created'] && !$safe['registration_deleted'])
            || ($safe['subscription_created'] && !$safe['sdk_cancelled_verified'])
            || ($product !== null && !$safe['product_archived']) || ($price !== null && !$safe['price_archived'])
            || ($processCleanup !== null && (($processCleanup['failed'] ?? true)
                || !($processCleanup['receiver_closed'] ?? false) || !($processCleanup['tunnel_stopped'] ?? false)
                || !($processCleanup['logs_closed'] ?? false)));
        $delivery = is_array($safe['delivery'] ?? null) ? $safe['delivery'] : [];
        $closed = is_array($safe['process_cleanup'] ?? null) ? $safe['process_cleanup'] : [];
        $safe['version_pair_verified'] = ($delivery['version_matching_created_count'] ?? 0) > 0
            && ($delivery['version_matching_cancelled_count'] ?? 0) > 0
            && ($delivery['conflicting_event_count'] ?? 0) === 0;
        $safe['signed_resource_pair_observed'] = ($delivery['matching_created_count'] ?? 0) > 0
            && ($delivery['matching_cancelled_count'] ?? 0) > 0 && ($delivery['conflicting_event_count'] ?? 0) === 0;
        $safe['server_lifecycle_verified'] = $safe['sdk_pending_verified'] && $safe['sdk_cancelled_verified'];
        $safe['typed_owner_verified'] = ($delivery['owner_matching_created_count'] ?? 0) > 0
            && ($delivery['owner_matching_cancelled_count'] ?? 0) > 0
            && ($delivery['conflicting_event_count'] ?? 0) === 0;
        $safe['selected_envelope_pair_verified'] = ($delivery['contract_matching_created_count'] ?? 0) > 0
            && ($delivery['contract_matching_cancelled_count'] ?? 0) > 0
            && ($delivery['conflicting_event_count'] ?? 0) === 0;
        $complete = $safe['signed_resource_pair_observed'] && $safe['version_pair_verified']
            && $safe['typed_owner_verified'] && $safe['selected_envelope_pair_verified']
            && ($delivery['fresh_contract_matching_created_count'] ?? 0) > 0
            && ($delivery['fresh_contract_matching_cancelled_count'] ?? 0) > 0
            && $safe['server_lifecycle_verified'] && $safe['registration_deleted']
            && $safe['product_archived'] && $safe['price_archived'];
        $safe['result'] = $failed || $safe['reconciliation_required'] ? 'fail' : ($complete ? 'pass' : 'pending');
        return $safe;
    }

    /** @param array<string,mixed> $options */
    private static function validate(#[SensitiveParameter] array $options): void
    {
        $allowed = ['environment', 'credentials-file', 'state-dir', 'node-bin', 'cloudflared-bin',
            'create-one-subscription-webhook-pair'];
        foreach ($options as $name => $value) {
            if (
                !in_array($name, $allowed, true) || ($name === 'create-one-subscription-webhook-pair'
                ? $value !== true : !is_string($value) || $value === '')
            ) {
                throw new RuntimeException('The explicit Billing delivery configuration is invalid.');
            }
        }
        if (
            ($options['environment'] ?? null) !== 'sandbox'
            || ($options['create-one-subscription-webhook-pair'] ?? null) !== true
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
    private static function tools(string $node, WebhookManagementProfile $managementProfile): array
    {
        $root = dirname(__DIR__, 4);
        $files = ['entry' => $root . '/tools/billing-subscription-webhook-probe.php',
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
        SubscriptionWebhookEvidence $evidence,
        string $field,
        Closure $wait,
        float $deadline,
        Closure $elapsed
    ): array {
        $until = min($deadline - 120, $elapsed() + 30);
        if (!is_finite($until)) {
            throw new ProtocolException('The original collection deadline is unavailable.');
        }
        $observed = [];
        for ($attempt = 0; $attempt < 60; ++$attempt) {
            if ($attempt > 0 && $elapsed() >= $until) {
                return $observed;
            }
            $observed = $evidence->collect();
            self::healthy($runtime);
            if (($observed[$field] ?? 0) > 0 || $elapsed() >= $until) {
                return $observed;
            }
            $wait(max(0, min(500, (int) floor(($until - $elapsed()) * 1000))));
        }
        return $observed;
    }

    /** @param Closure():int $clock */
    private static function wall(Closure $clock): int
    {
        $now = $clock();
        if ($now < 1000 || $now > min(PHP_INT_MAX - 86700000, 253402214399000)) {
            throw new ProtocolException('The receipt and future-start clock is unavailable.');
        }
        return $now;
    }

    /** @param array<string,mixed> $expected @param Closure():int $clock */
    private static function pending(SubscriptionWebhookApi $api, array $expected, Closure $clock): void
    {
        $id = SubscriptionWebhookApi::identity($expected);
        $read = $api->producer->billing()->subscriptions()->retrieve($id);
        $start = $expected['starts_at'] ?? null;
        if (
            !SubscriptionWebhookApi::subscription($read, $expected, 'PENDING') || !is_string($start)
            || (new DateTimeImmutable($start))->getTimestamp() <= intdiv(self::wall($clock), 1000)
        ) {
            throw new ProtocolException('The fresh owned Subscription is not still future PENDING.');
        }
    }

    /** @return array<string,string> */
    private static function item(
        SubscriptionWebhookApi $api,
        string $subscription,
        string $price,
        string $product,
        string $marker
    ): array {
        $resources = $api->producer->billing()->subscriptions();
        $page = $resources->listItems($subscription, ['page_size' => 20]);
        $items = $page['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) !== 1 || !is_array($items[0])) {
            throw new ProtocolException('The one bounded original Item is unavailable or ambiguous.');
        }
        $id = SubscriptionWebhookApi::identity($items[0]);
        if (!SubscriptionWebhookApi::item($items[0], $id, $subscription, $price, $product, $marker)) {
            throw new ProtocolException('The page Item differs from the original catalogue and Subscription.');
        }
        $api->producerTransport->bindItem($id);
        if (
            !SubscriptionWebhookApi::item(
                $resources->retrieveItem(
                    $subscription,
                    $id
                ),
                $id,
                $subscription,
                $price,
                $product,
                $marker
            )
        ) {
            throw new ProtocolException('The current page-proven Item ownership or terms differ.');
        }
        return $api->producerTransport->pagination();
    }

    /**
     * @param array<string,mixed> $body
     * @param Closure(array<string,mixed>):array<array-key,mixed> $send
     * @param (Closure():void)|null $postGuard
     * @return array<array-key,mixed>
     */
    private static function mutate(
        SubscriptionWebhookApi $api,
        RunLedger $ledger,
        string $context,
        string $action,
        array $body,
        Closure $send,
        ?Closure $postGuard = null
    ): array {
        $body['request_id'] = $ledger->requestId($action, $body, $context);
        $api->producerTransport->reserve($action, static function (\Psr\Http\Message\RequestInterface $request) use (
            $api,
            $ledger,
            $context,
            $action,
            $postGuard
        ): void {
            $api->assertSource($ledger);
            $stream = $request->getBody();
            $size = $stream->getSize();
            if (!$stream->isSeekable() || $stream->tell() !== 0 || $size === null || $size < 1 || $size > 65536) {
                throw new ProtocolException('The prepared Subscription wire body has no exact bounded origin.');
            }
            try {
                $bytes = $stream->read($size + 1);
                if (strlen($bytes) !== $size || !$stream->eof()) {
                    throw new ProtocolException('Prepared Subscription bytes differ from their declared size.');
                }
            } finally {
                $stream->rewind();
            }
            $ledger->recordObservation($action . '_wire', [
                'wire_sha256' => hash('sha256', $bytes), 'target_sha256' => hash('sha256', (string) $request->getUri()),
                'headers_sha256' => hash('sha256', json_encode($request->getHeaders(), JSON_THROW_ON_ERROR)),
                'method' => $request->getMethod(), 'protocol' => $request->getProtocolVersion(),
            ], $context);
            if ($postGuard !== null) {
                $postGuard();
            }
            $ledger->assertContext($context);
            $api->assertSource($ledger);
        });
        return $send($body);
    }

    /** @param array<string,mixed> $expected @param Closure():int $clock @param Closure(int):void $wait
     * @param array<string,mixed> $safe */
    private static function cancel(
        SubscriptionWebhookApi $api,
        RunLedger $ledger,
        string $context,
        array $expected,
        string $price,
        string $product,
        Closure $clock,
        Closure $wait,
        array &$safe
    ): void {
        $customer = $expected['billing_customer_id'] ?? null;
        $entity = $expected['legal_entity_id'] ?? null;
        if (
            !is_string($customer) || !is_string($entity)
            || !SubscriptionWebhookApi::customer(
                $api->producer->billing()->customers()->retrieve($customer),
                $customer,
                $entity,
                $ledger->runId()
            )
        ) {
            throw new ProtocolException('Fresh Customer proof is required before cancellation.');
        }
        $id = SubscriptionWebhookApi::identity($expected);
        self::pending($api, $expected, $clock);
        $safe['pagination'] = self::item($api, $id, $price, $product, $ledger->runId());
        $safe['cancel_reserved'] = true;
        try {
            $response = self::mutate(
                $api,
                $ledger,
                $context,
                'cancel_subscription',
                ['proration_behavior' => 'NONE'],
                fn (array $body): array => $api->producer->billing()->subscriptions()->cancel($id, $body),
                static function () use ($api, $expected, $clock, $id, $price, $product, $ledger, &$safe): void {
                    self::pending($api, $expected, $clock);
                    $safe['pagination'] = self::item($api, $id, $price, $product, $ledger->runId());
                }
            );
            if (!SubscriptionWebhookApi::subscription($response, $expected, 'CANCELLED')) {
                throw new ProtocolException('The cancellation response has no exact own terminal proof.');
            }
            $safe['cancel_action_outcome'] = 'accepted';
        } catch (Throwable) {
            $safe['cancel_action_outcome'] = 'unknown';
            if (!$api->producerTransport->sent('cancel_subscription')) {
                throw new ProtocolException('Cancellation was withheld before any possible send.');
            }
        }
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $read = $api->producer->billing()->subscriptions()->retrieve($id);
            if (SubscriptionWebhookApi::subscription($read, $expected, 'CANCELLED')) {
                $safe['sdk_cancelled_verified'] = true;
                $ledger->recordObservation(
                    'subscription_cancelled',
                    ['target_sha256' => hash(
                        'sha256',
                        $id
                    )],
                    $context
                );
                return;
            }
            if (!SubscriptionWebhookApi::subscription($read, $expected, 'PENDING')) {
                throw new ProtocolException('Cancellation readback has unrecognized ownership, state or terms.');
            }
            if ($attempt < 3) {
                $wait(1000);
            }
        }
        throw new ProtocolException('Cancellation was not verified inside its four-read bound.');
    }

    /** @param array<string,mixed> $safe */
    private static function archive(
        SubscriptionWebhookApi $api,
        RunLedger $ledger,
        string $context,
        ?string $price,
        ?string $product,
        array &$safe
    ): void {
        if ($product === null) {
            return;
        }
        $billing = $api->producer->billing();
        if ($price !== null) {
            $updated = self::mutate(
                $api,
                $ledger,
                $context,
                'archive_price',
                ['active' => false],
                fn (array $body): array => $billing->prices()->update($price, $body),
                static function () use ($billing, $price, $product, $ledger): void {
                    if (
                        !SubscriptionWebhookApi::price(
                            $billing->prices()->retrieve($price),
                            $price,
                            $product,
                            $ledger->runId()
                        )
                    ) {
                        throw new ProtocolException('Price archive requires its post-reservation own original proof.');
                    }
                }
            );
            if (!SubscriptionWebhookApi::price($updated, $price, $product, $ledger->runId(), false)) {
                throw new ProtocolException('Price archive response has no exact own inactive proof.');
            }
            if (
                !SubscriptionWebhookApi::price(
                    $billing->prices()->retrieve($price),
                    $price,
                    $product,
                    $ledger->runId(),
                    false
                )
            ) {
                throw new ProtocolException('Own Price archive was not verified.');
            }
            $safe['price_archived'] = true;
        }
        $updated = self::mutate(
            $api,
            $ledger,
            $context,
            'archive_product',
            ['active' => false],
            fn (array $body): array => $billing->products()->update($product, $body),
            static function () use ($billing, $product, $ledger): void {
                if (
                    !SubscriptionWebhookApi::product(
                        $billing->products()->retrieve($product),
                        $product,
                        $ledger->runId()
                    )
                ) {
                    throw new ProtocolException('Product archive requires its post-reservation own original proof.');
                }
            }
        );
        if (!SubscriptionWebhookApi::product($updated, $product, $ledger->runId(), false)) {
            throw new ProtocolException('Product archive response has no exact own inactive proof.');
        }
        if (
            !SubscriptionWebhookApi::product(
                $billing->products()->retrieve($product),
                $product,
                $ledger->runId(),
                false
            )
        ) {
            throw new ProtocolException('Own Product archive was not verified.');
        }
        $safe['product_archived'] = true;
    }

    private static function snapshot(WebhookDeliveryStore $store, string $hash): void
    {
        if (!hash_equals($hash, hash('sha256', $store->read('registration.json')))) {
            throw new ProtocolException('The private registration proof changed.');
        }
    }

    /**
     * @param array{
     * id:string,url:string,secret:string,version:string,events:list<string>,request_id:string
     * } $registration
     * @param (Closure(string,string):RunLedger)|null $ledgers
     * @param array<string,mixed> $safe
     */
    private static function delete(
        SubscriptionWebhookApi $api,
        #[SensitiveParameter] array $registration,
        WebhookDeliveryStore $store,
        string $hash,
        string $directory,
        string $context,
        ?Closure $ledgers,
        array &$safe
    ): bool {
        self::snapshot($store, $hash);
        $cleanup = $ledgers === null ? RunLedger::create($context, $directory) : $ledgers($context, $directory);
        $path = '/api/v1/webhooks/' . rawurlencode($registration['id']) . '/delete';
        $cleanup->requestId('delete_own_webhook', ['method' => 'POST', 'target_sha256' => hash('sha256', $path),
            'snapshot_sha256' => $hash, 'wire_sha256' => hash('sha256', ''), 'local_identity_only' => true], $context);
        self::wire($cleanup, $context, 'registration_delete', $path, null);
        if (!WebhookDeliveryApi::same($registration, $api->management->retrieveWebhook($registration['id']))) {
            throw new ProtocolException('Deletion requires the exact durable own registration.');
        }
        self::snapshot($store, $hash);
        $api->assertSource($cleanup);
        try {
            $accepted = $api->management->deleteWebhook($registration['id']);
            $safe['registration_delete_outcome'] = $accepted ? 'accepted' : 'unknown';
        } catch (Throwable) {
            $safe['registration_delete_outcome'] = 'unknown';
        }
        if (!$api->management->webhookGone($registration['id'])) {
            return false;
        }
        $cleanup->recordObservation('registration_deleted', ['snapshot_sha256' => $hash], $context);
        return true;
    }
}
