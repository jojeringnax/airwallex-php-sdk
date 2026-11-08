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

/** One explicit temporary registration and one own guest Intent; no callback financial actions. */
final class WebhookDeliveryProbe
{
    /**
     * @param array<string,mixed> $options
     * @param (Closure(array<string,mixed>):WebhookDeliveryRuntime)|null $runtimeFactory Synthetic-only process seam.
     * @param (Closure():int)|null $clock Unix milliseconds, independent of monotonic process/request budgets.
     * @param (Closure(int):void)|null $wait Milliseconds; deterministic fixture seam.
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
    ): array {
        $safe = ['scenario' => 'webhook-delivery-probe', 'api_version' => '2026-08-21', 'context' => 'account',
            'result' => 'fail', 'phase' => 'configuration', 'registration_created' => false,
            'registration_deleted' => false, 'registration_outcome_unknown' => false,
            'intent_created' => false, 'intent_cancelled' => false, 'intent_outcome_unknown' => false,
            'reconciliation_required' => false, 'raw_delivery_observed' => false,
            'signatures_verified' => false, 'version_owner_verified' => false,
            'funds_movement_verified' => false, 'organization_manager_verified' => false];
        $runtime = null;
        $ledger = null;
        $api = null;
        $transport = null;
        $registration = null;
        $store = null;
        $fingerprint = null;
        $directory = null;
        $snapshotHash = null;
        $failed = false;
        $clock ??= self::now(...);
        $wait ??= static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
        $monotonic = hrtime(true) / 1000000000;
        try {
            foreach ($options as $name => $value) {
                if (
                    !in_array($name, ['environment', 'credentials-file', 'state-dir', 'node-bin',
                    'cloudflared-bin', 'create-one-webhook-and-intent'], true)
                    || ($name === 'create-one-webhook-and-intent' ? $value !== true
                        : !is_string($value) || $value === '')
                ) {
                    throw new RuntimeException('Explicit delivery configuration is invalid.');
                }
            }
            if (
                ($options['environment'] ?? null) !== 'sandbox'
                || ($options['create-one-webhook-and-intent'] ?? null) !== true
            ) {
                throw new RuntimeException('The delivery probe requires its explicit Sandbox creation flag.');
            }
            $credentials = self::option($options, 'credentials-file');
            $directory = self::option($options, 'state-dir');
            $node = self::option($options, 'node-bin');
            $binary = self::option($options, 'cloudflared-bin');
            self::external($credentials);
            $resolvedDirectory = realpath($directory);
            if ($resolvedDirectory === false || is_link($directory)) {
                throw new RuntimeException('Private delivery state is unavailable.');
            }
            $directory = $resolvedDirectory;
            WebhookDeliveryStore::directory($directory);
            if (realpath($node) !== $node || !is_file($node) || !is_executable($node) || is_link($node)) {
                throw new RuntimeException('The explicit Node runtime is unavailable.');
            }
            if ($runtimeFactory === null) {
                WebhookDeliverySupervisor::verifyBinary($binary);
            }
            WebhookDeliveryTransport::assertAvailable();
            $configuration = Configuration::load('sandbox', $credentials);
            $fingerprint = WebhookConfigObservation::contextFingerprint($configuration);
            $safe['context_fingerprint'] = $fingerprint;
            $safe['phase'] = 'provenance';
            $ledger = $ledgers === null
                ? RunLedger::create($fingerprint, $directory) : $ledgers($fingerprint, $directory);
            $ledger->assertContext($fingerprint);
            $safe += $ledger->evidence();
            $tools = self::tools($node);
            $safe['tool_hashes'] = $tools;
            $source = hash('sha256', json_encode([$ledger->evidence(), $tools], JSON_THROW_ON_ERROR));
            $ledger->requestId(
                'webhook_delivery_provenance',
                ['source_fingerprint' => $source,
                'api_version' => '2026-08-21', 'events' => ['payment_intent.created', 'payment_intent.cancelled']],
                $fingerprint
            );
            $store = $stores === null ? new WebhookDeliveryStore($directory, $ledger) : $stores($directory, $ledger);
            $path = '/' . bin2hex(random_bytes(32));
            $started = $clock();
            if ($started < 1000 || $started > PHP_INT_MAX - 300000) {
                throw new RuntimeException('The private receipt clock is invalid.');
            }
            $config = ['folder' => $store->folder(), 'path' => $path, 'context' => $fingerprint,
                'source' => $source, 'duration_ms' => 300000, 'live' => true, 'binary' => $binary];
            $store->write('cloudflared.yml', '{}');
            $store->write('receiver-config.json', json_encode($config, JSON_THROW_ON_ERROR));
            $transport = new WebhookDeliveryTransport(
                $configuration->accountId(),
                $http,
                overallDeadline: $monotonic + 300
            );
            $api = new WebhookDeliveryApi($configuration, $transport);
            $safe['phase'] = 'receiver_start';
            $runtime = $runtimeFactory === null
                ? new WebhookDeliverySupervisor($node, $config) : $runtimeFactory($config);
            $destination = $runtime->destination();
            if (!WebhookDeliveryApi::destination($destination) || !str_ends_with($destination, $path)) {
                throw new ProtocolException('The private receiver destination does not match its own path.');
            }
            $body = ['url' => $destination, 'version' => '2026-08-21',
                'events' => ['payment_intent.created', 'payment_intent.cancelled']];
            $requestId = $ledger->requestId('create_webhook', $body, $fingerprint);
            $store->write('destination.json', json_encode(['configuration' => $body, 'request_id' => $requestId,
                'context' => $fingerprint, 'source' => $source], JSON_THROW_ON_ERROR));
            $safe['phase'] = 'authentication';
            $api->authenticate();
            $ledger->recordObservation('registration_create_wire', [
                'wire_sha256' => hash('sha256', json_encode($body + ['request_id' => $requestId], JSON_THROW_ON_ERROR)),
                'target_sha256' => hash('sha256', '/api/v1/webhooks/create'),
            ], $fingerprint);
            $safe['phase'] = 'registration_create';
            $created = $api->createWebhook($destination, $requestId);
            if (
                $created['url'] !== $destination || $created['version'] !== '2026-08-21'
                || $created['request_id'] !== $requestId
            ) {
                throw new ProtocolException('The returned registration does not match this run\'s creation.');
            }
            $snapshot = ['registration' => $created, 'context' => $fingerprint, 'source' => $source,
                'api_version' => '2026-08-21', 'provenance' => $ledger->evidence()];
            $snapshotHash = $store->write('registration.json', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $registration = $created; // Durable proof precedes dependent triggers.
            $safe['registration_created'] = true;
            $transport->allowWebhook($created['id']);
            $ledger->recordCreated('webhooks', $created['id']);
            $ledger->recordObservation('webhook_configuration', ['snapshot_sha256' => $snapshotHash], $fingerprint);
            $safe['phase'] = 'registration_read';
            if (!WebhookDeliveryApi::same($created, $api->retrieveWebhook($created['id']))) {
                throw new ProtocolException('The retrieved registration differs from its own durable configuration.');
            }
            $registrationFingerprint = hash(
                'sha256',
                json_encode([$snapshotHash, $fingerprint, $source], JSON_THROW_ON_ERROR)
            );
            $runtime->arm($registrationFingerprint);
            $safe['phase'] = 'intent_create';
            $order = substr($ledger->runId(), -36) . '-webhook';
            $intentBody = ['amount' => '1.23', 'currency' => 'HKD', 'merchant_order_id' => $order,
                'metadata' => ['sdk_validation_run' => $ledger->runId()]];
            $intentBody['request_id'] = $ledger->requestId('create_delivery_intent', $intentBody, $fingerprint);
            $ledger->recordObservation('intent_create_wire', [
                'wire_sha256' => hash('sha256', (new JsonCodec())->encode($intentBody, ['/amount'])),
                'target_sha256' => hash('sha256', '/api/v1/pa/payment_intents/create'),
            ], $fingerprint);
            $intent = $api->client->payments()->paymentIntents()->create($intentBody);
            $id = $intent['id'] ?? null;
            if (!is_string($id) || !WebhookDeliveryTransport::validIdentity($id)) {
                throw new ProtocolException('The own guest Intent has no bounded returned identity.');
            }
            self::guardIntent(
                $intent,
                $id,
                $order,
                $ledger->runId(),
                $configuration->accountId(),
                'REQUIRES_PAYMENT_METHOD'
            );
            $ledger->recordCreated('payment_intents', $id);
            $safe['intent_created'] = true;
            $transport->allowIntent($id);
            $safe['phase'] = 'intent_read';
            $read = $api->client->payments()->paymentIntents()->retrieve($id);
            self::guardIntent(
                $read,
                $id,
                $order,
                $ledger->runId(),
                $configuration->accountId(),
                'REQUIRES_PAYMENT_METHOD'
            );
            $evidence = new WebhookDeliveryEvidence(
                $store,
                $created['secret'],
                $registrationFingerprint,
                $fingerprint,
                $source,
                $configuration->accountId(),
                $id,
                $order,
                $ledger->runId(),
                $started,
                $clock
            );
            $safe['phase'] = 'created_delivery_wait';
            $counts = self::collect($runtime, $evidence, 'matching_created_count', $wait, $monotonic);
            $safe = array_replace($safe, $counts);
            $ledger->recordObservation(
                'created_capture_checkpoint',
                ['count' => (string) ($counts['matching_created_count'] ?? 0), 'source_fingerprint' => $source],
                $fingerprint
            );
            $safe['phase'] = 'intent_cancel_guard';
            $guard = $api->client->payments()->paymentIntents()->retrieve($id);
            self::guardIntent(
                $guard,
                $id,
                $order,
                $ledger->runId(),
                $configuration->accountId(),
                'REQUIRES_PAYMENT_METHOD'
            );
            $cancel = ['request_id' => $ledger->requestId(
                'cancel_delivery_intent',
                ['intent_id_hash' => hash('sha256', $id), 'merchant_order_id' => $order],
                $fingerprint
            )];
            $ledger->recordObservation('intent_cancel_wire', [
                'wire_sha256' => hash('sha256', (new JsonCodec())->encode($cancel)),
                'target_sha256' => hash('sha256', '/api/v1/pa/payment_intents/' . rawurlencode($id) . '/cancel'),
            ], $fingerprint);
            $safe['phase'] = 'intent_cancel';
            $cancelled = $api->client->payments()->paymentIntents()->cancel($id, $cancel);
            $cancelStatus = $cancelled['status'] ?? null;
            if (!is_string($cancelStatus) || !in_array($cancelStatus, ['REQUIRES_PAYMENT_METHOD', 'CANCELLED'], true)) {
                throw new ProtocolException('The cancellation returned an unrecognized own resource state.');
            }
            self::guardIntent($cancelled, $id, $order, $ledger->runId(), $configuration->accountId(), $cancelStatus);
            $observed = null;
            $delays = [1000, 2000, 4000, 8000, 8000, 7000];
            for ($attempt = 0; $attempt < 7; ++$attempt) {
                $read = $api->client->payments()->paymentIntents()->retrieve($id);
                $status = $read['status'] ?? null;
                if ($status === 'CANCELLED') {
                    self::guardIntent($read, $id, $order, $ledger->runId(), $configuration->accountId(), 'CANCELLED');
                    $observed = $read;
                    break;
                }
                self::guardIntent(
                    $read,
                    $id,
                    $order,
                    $ledger->runId(),
                    $configuration->accountId(),
                    'REQUIRES_PAYMENT_METHOD'
                );
                if ($attempt < 6) {
                    $wait($delays[$attempt]);
                }
            }
            if ($observed === null) {
                throw new ProtocolException('The own unconfirmed Intent cancellation was not verified.');
            }
            $ledger->recordObservation(
                'delivery_intent_cancelled',
                ['intent_id_hash' => hash('sha256', $id)],
                $fingerprint
            );
            $safe['intent_cancelled'] = true;
            $safe['phase'] = 'cancelled_delivery_wait';
            $counts = self::collect($runtime, $evidence, 'matching_cancelled_count', $wait, $monotonic);
            $safe = array_replace($safe, $counts);
            $ledger->recordObservation(
                'cancelled_capture_checkpoint',
                ['count' => (string) ($counts['matching_cancelled_count'] ?? 0), 'source_fingerprint' => $source],
                $fingerprint
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
            if ($transport !== null) {
                $safe['registration_outcome_unknown'] = $transport->sent('create_webhook') && $registration === null;
                $safe['intent_outcome_unknown'] = ($transport->sent('create_intent') && !$safe['intent_created'])
                    || ($transport->sent('cancel_intent') && !$safe['intent_cancelled']);
            }
            if (
                $api !== null && $registration !== null && $store !== null && is_string($fingerprint)
                && is_string($directory) && is_string($snapshotHash)
            ) {
                try {
                    $safe['registration_deleted'] = self::cleanupRegistration(
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
                if (!$safe['registration_deleted']) {
                    $failed = true;
                }
            }
            if ($runtime !== null) {
                try {
                    $closed = $runtime->stop();
                    $safe['process_cleanup'] = $closed;
                    $failed = $failed || $closed['failed'] || !$closed['receiver_closed']
                        || !$closed['tunnel_stopped'] || !$closed['logs_closed'];
                } catch (Throwable) {
                    $failed = true;
                    $safe['process_cleanup'] = ['receiver_closed' => false, 'tunnel_stopped' => false,
                        'logs_closed' => false, 'failed' => true];
                }
            }
        }
        $safe['reconciliation_required'] = $safe['registration_outcome_unknown'] || $safe['intent_outcome_unknown']
            || ($safe['registration_created'] && !$safe['registration_deleted'])
            || ($safe['intent_created'] && !$safe['intent_cancelled'])
            || (isset($safe['process_cleanup']) && is_array($safe['process_cleanup'])
                && ($safe['process_cleanup']['failed'] ?? true));
        $matching = ($safe['matching_created_count'] ?? 0) > 0 && ($safe['matching_cancelled_count'] ?? 0) > 0;
        $safe['raw_delivery_observed'] = $matching;
        $safe['signatures_verified'] = $matching;
        $safe['version_owner_verified'] = $matching;
        $safe['result'] = $failed ? 'fail' : ($matching && $safe['intent_cancelled']
            && $safe['registration_deleted'] ? 'pass' : 'pending');
        return $safe;
    }

    /** @param array<string,mixed> $options */
    private static function option(#[SensitiveParameter] array $options, string $key): string
    {
        $value = $options[$key] ?? null;
        if (!is_string($value) || $value === '' || !str_starts_with($value, '/')) {
            throw new RuntimeException('Delivery requires explicit absolute local configuration.');
        }
        return $value;
    }

    private static function external(#[SensitiveParameter] string $path): void
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('External Sandbox credentials are unavailable.');
        }
        for ($ancestor = dirname($resolved);; $ancestor = dirname($ancestor)) {
            if (file_exists($ancestor . '/.git') || is_link($ancestor . '/.git')) {
                throw new RuntimeException('Sandbox credentials must remain outside Git checkouts.');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
    }

    /** @return array<string,string> */
    private static function tools(string $node): array
    {
        $root = dirname(__DIR__, 4);
        $result = [];
        foreach (
            ['entry' => $root . '/tools/webhook-delivery-probe.php',
            'receiver' => $root . '/tools/webhooks/webhook-receiver.cjs',
            'supervisor' => $root . '/tools/webhooks/webhook-supervisor.cjs', 'node_runtime' => $node] as $key => $path
        ) {
            $hash = @hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('Delivery tool provenance is unavailable.');
            }
            $result[$key] = $hash;
        }
        $result['tunnel_runtime'] = '2136431d5eb467bf447d2ee514b713e192719b143aec1d9e09bf7dc290d5bb12';
        return $result;
    }

    private static function now(): int
    {
        $value = filter_var((new DateTimeImmutable())->format('Uv'), FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new RuntimeException('The private receipt clock is unavailable.');
        }
        return $value;
    }

    /** @param array<array-key,mixed> $value */
    private static function guardIntent(
        #[SensitiveParameter] array $value,
        #[SensitiveParameter] string $id,
        #[SensitiveParameter] string $order,
        #[SensitiveParameter] string $marker,
        #[SensitiveParameter] string $account,
        string $status,
    ): void {
        if (
            !WebhookDeliveryEvidence::ownIntent($value, $id, $order, $marker, $status)
            || (array_key_exists('account_id', $value) && $value['account_id'] !== $account)
        ) {
            throw new ProtocolException('The current own guest Intent does not match its fixed account experiment.');
        }
    }

    /**
     * @param Closure(int):void $wait
     * @return array<string,int>
     */
    private static function collect(
        WebhookDeliveryRuntime $runtime,
        WebhookDeliveryEvidence $evidence,
        string $field,
        Closure $wait,
        float $monotonic,
    ): array {
        for ($attempt = 0; $attempt < 60; ++$attempt) {
            $counts = $evidence->collect();
            $state = $runtime->poll();
            foreach (
                ['stream_failure', 'persistence_failure', 'limit_failure', 'closed',
                'supervisor_failed', 'log_limit_failure', 'tunnel_exited'] as $name
            ) {
                if (($state[$name] ?? true) !== false) {
                    throw new ProtocolException('The private receiver observation failed or exhausted a boundary.');
                }
            }
            if (($counts[$field] ?? 0) > 0 || hrtime(true) / 1000000000 - $monotonic > 150) {
                return $counts;
            }
            $wait(500);
        }
        return $evidence->collect();
    }

    /** @param array{id:string,url:string,secret:string,version:string,events:list<string>,request_id:string} $registration */
    private static function cleanupRegistration(
        WebhookDeliveryApi $api,
        #[SensitiveParameter] array $registration,
        WebhookDeliveryStore $store,
        string $snapshotHash,
        string $directory,
        string $fingerprint,
    ): bool {
        if (!hash_equals($snapshotHash, hash('sha256', $store->read('registration.json')))) {
            throw new ProtocolException('The private registration proof changed.');
        }
        $cleanup = RunLedger::create($fingerprint, $directory); // Independent from a poisoned primary journal.
        if (!WebhookDeliveryApi::same($registration, $api->retrieveWebhook($registration['id']))) {
            throw new ProtocolException('Cleanup cannot identify the exact own temporary registration.');
        }
        $cleanup->requestId('delete_own_webhook', ['method' => 'POST',
            'target_sha256' => hash('sha256', $registration['id']), 'snapshot_sha256' => $snapshotHash,
            'wire_sha256' => hash('sha256', '')], $fingerprint);
        if (!$api->deleteWebhook($registration['id']) || !$api->webhookGone($registration['id'])) {
            return false;
        }
        $cleanup->recordObservation(
            'registration_deleted',
            ['target_sha256' => hash('sha256', $registration['id']), 'snapshot_sha256' => $snapshotHash],
            $fingerprint
        );
        return true;
    }
}
