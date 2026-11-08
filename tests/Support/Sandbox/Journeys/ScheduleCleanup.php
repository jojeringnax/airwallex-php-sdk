<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use DateTimeImmutable;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use RuntimeException;
use SensitiveParameter;

/** A new bounded cleanup action for the separate one-Price schedule inventory; no old action is resumed. */
final class ScheduleCleanup
{
    private bool $used = false;
    private bool $ambiguous = false;
    private string $case = '';
    private string $entity = '';
    private string $phase = 'configuration';

    /** @param array{base_commit:string,sdk_digest:string,harness_digest:string} $priorSource */
    private function __construct(
        #[SensitiveParameter] private readonly string $path,
        #[SensitiveParameter] private readonly string $customer,
        #[SensitiveParameter] private readonly string $product,
        #[SensitiveParameter] private readonly string $price,
        #[SensitiveParameter] private readonly ?string $subscription,
        #[SensitiveParameter] private readonly ?string $item,
        #[SensitiveParameter] private readonly string $marker,
        private readonly string $fingerprint,
        private readonly string $digest,
        private readonly array $priorSource,
        private readonly bool $cancelMode,
        private readonly bool $priorCancelIntent,
    ) {
    }

    public static function load(
        #[SensitiveParameter] string $path,
        SandboxSession $session,
        #[SensitiveParameter] string $collector,
        bool $cancelMode = false,
    ): self {
        if (!$session->isOrganization() || !str_starts_with($path, '/') || is_link($path) || is_link(dirname($path))) {
            throw new RuntimeException('Schedule cleanup requires a private organization ledger.');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('The prior schedule ledger is unavailable.');
        }
        self::privateFile($resolved);
        $raw = @file_get_contents($resolved, length: 1048577);
        if ($raw === false || strlen($raw) > 1048576) {
            throw new RuntimeException('The prior schedule ledger is unavailable.');
        }
        $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $fingerprint = BillingScheduleEvidence::contextFingerprint($session, $collector);
        if (
            !is_array($state) || ($state['api_version'] ?? null) !== '2026-08-21'
            || !is_string($state['context_fingerprint'] ?? null)
            || !hash_equals($fingerprint, $state['context_fingerprint'])
            || !is_string($state['run_id'] ?? null)
            || preg_match('/^sdk-validation-[a-f0-9-]{36}$/D', $state['run_id']) !== 1
            || !is_array($state['resources'] ?? null) || !is_array($state['actions'] ?? null)
            || array_diff(array_keys($state['resources']), ['customers', 'products', 'prices', 'subscriptions']) !== []
        ) {
            throw new RuntimeException('The prior schedule version, context or inventory did not match.');
        }
        foreach (['create_customer', 'create_product', 'create_price', 'create_subscription'] as $action) {
            $intent = $state['actions'][$action] ?? null;
            if (
                !is_array($intent) || !is_string($intent['request_id'] ?? null)
                || preg_match('/^[a-f0-9-]{36}$/D', $intent['request_id']) !== 1
                || !is_string($intent['body_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $intent['body_hash']) !== 1
            ) {
                throw new RuntimeException('Schedule cleanup requires its original recorded create intents.');
            }
        }
        $customer = self::references($state['resources']['customers'] ?? null, 1, 1)[0];
        $product = self::references($state['resources']['products'] ?? null, 1, 1)[0];
        $price = self::references($state['resources']['prices'] ?? null, 1, 1)[0];
        $subscriptions = self::references($state['resources']['subscriptions'] ?? [], 0, 1);
        if (!$cancelMode && $subscriptions !== []) {
            throw new RuntimeException('Catalogue-only mode rejects every recorded Subscription.');
        }
        if ($cancelMode && $subscriptions === []) {
            throw new RuntimeException('Cancellation mode requires one recorded Subscription.');
        }
        $subscription = $subscriptions[0] ?? null;
        $item = null;
        if ($subscription !== null) {
            $observations = $state['observations'] ?? null;
            $observation = is_array($observations) ? ($observations['initial_item'] ?? null) : null;
            if (
                !is_array($observation) || ($observation['subscription_id'] ?? null) !== $subscription
                || ($observation['price_id'] ?? null) !== $price
            ) {
                throw new RuntimeException('The recorded schedule Item relationship is unavailable.');
            }
            $item = self::references([$observation['item_id'] ?? null], 1, 1)[0];
        }
        $source = self::source($state['source'] ?? null);
        return new self(
            $resolved,
            $customer,
            $product,
            $price,
            $subscription,
            $item,
            $state['run_id'],
            $fingerprint,
            hash('sha256', $raw),
            $source,
            $cancelMode,
            isset($state['actions']['cleanup_cancel']) || isset($state['actions']['case_cancel']),
        );
    }

    /** @return array{base_commit:string,sdk_digest:string,harness_digest:string} */
    private static function source(mixed $value): array
    {
        if (
            !is_array($value) || !is_string($value['base_commit'] ?? null)
            || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $value['base_commit']) !== 1
            || !is_string($value['sdk_digest'] ?? null) || !is_string($value['harness_digest'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $value['sdk_digest']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $value['harness_digest']) !== 1
        ) {
            throw new RuntimeException('Original source provenance is unavailable.');
        }
        return ['base_commit' => $value['base_commit'], 'sdk_digest' => $value['sdk_digest'],
            'harness_digest' => $value['harness_digest']];
    }

    private static function privateFile(string $path): void
    {
        for ($ancestor = dirname($path);; $ancestor = dirname($ancestor)) {
            if (file_exists($ancestor . '/.git') || is_link($ancestor . '/.git')) {
                throw new RuntimeException('Private schedule state must remain outside Git checkouts.');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
        clearstatcache(true, $path);
        $file = @fileperms($path);
        $directory = @fileperms(dirname($path));
        if (
            is_link($path) || is_link(dirname($path)) || !is_file($path)
            || (PHP_OS_FAMILY !== 'Windows' && ($file === false || $directory === false
                || ($file & 0077) !== 0 || ($directory & 0077) !== 0))
        ) {
            throw new RuntimeException('Schedule state requires private regular files and directories.');
        }
    }

    /** @return list<non-empty-string> */
    private static function references(mixed $value, int $minimum, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < $minimum || count($value) > $maximum) {
            throw new RuntimeException('The schedule inventory has an unsupported size.');
        }
        $result = [];
        foreach ($value as $id) {
            if (
                !is_string($id) || $id === '' || preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $id) !== 1
                || in_array($id, $result, true)
            ) {
                throw new RuntimeException('The schedule inventory has an unsupported reference.');
            }
            $result[] = $id;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function execute(
        SandboxSession $session,
        RunLedger $ledger,
        #[SensitiveParameter] string $collector,
        ?Closure $clock = null,
        ?Closure $wait = null,
        int $attempts = 7,
    ): array {
        $fingerprint = BillingScheduleEvidence::contextFingerprint($session, $collector);
        if (
            $this->used || !$session->isOrganization() || !hash_equals($this->fingerprint, $fingerprint)
            || $attempts < 1 || $attempts > 7
        ) {
            throw new RuntimeException('Schedule cleanup cannot be reused or cross owner contexts.');
        }
        $ledger->assertContext($fingerprint);
        $this->used = true;
        $clock ??= static fn (): int => time();
        $wait ??= static fn (int $seconds) => sleep($seconds);
        $safe = [
            'scenario' => 'billing-schedule-cleanup', 'api_version' => '2026-08-21', 'result' => 'fail',
            'cleanup_mode' => $this->cancelMode ? 'verify-pending-and-cancel' : 'catalogue-only',
            'prior_ledger_sha256' => $this->digest, 'prior_source' => $this->priorSource,
            'original_run_fingerprint' => hash('sha256', $this->marker),
            'run_id' => $ledger->runId(), 'catalogue_verified' => false, 'subscription_scope_verified' => false,
            'subscription_recorded' => $this->subscription !== null, 'subscription_cancel_sent' => false,
            'subscription_cancelled_verified' => false, 'pending_future_start_verified' => false,
            'price_archived' => false, 'price_already_inactive' => false,
            'product_archived' => false, 'product_already_inactive' => false,
            'prior_action_reconciliation_complete' => false, 'prior_ledger_unchanged' => false,
        ] + $ledger->evidence();
        try {
            $this->unchanged();
            $this->phase = 'account_configuration';
            $entity = $session->legalEntity();
            if ($entity === null) {
                throw new ProtocolException('The selected owner legal entity is unavailable.');
            }
            $this->entity = $entity;
            $billing = $session->client->billing();
            $this->phase = 'catalogue_preflight';
            $customer = $billing->customers()->retrieve($this->customer);
            $metadata = $customer['metadata'] ?? null;
            $case = is_array($metadata) ? ($metadata['sdk_validation_case'] ?? null) : null;
            if (!is_string($case) || !in_array($case, BillingScheduleEvidence::CASES, true)) {
                throw new ProtocolException('The prior case marker is unavailable.');
            }
            $this->case = $case;
            $this->verify($customer, 'customers');
            $product = $billing->products()->retrieve($this->product);
            $this->verify($product, 'products');
            $price = $billing->prices()->retrieve($this->price);
            $this->verify($price, 'prices');
            $safe['catalogue_verified'] = true;
            if ($this->subscription !== null) {
                $this->phase = 'subscription_preflight';
                $id = $this->subscription;
                $subscription = $billing->subscriptions()->retrieve($id);
                $this->verify($subscription, 'subscriptions');
                $state = $subscription['status'] ?? null;
                if ($state !== 'CANCELLED') {
                    $this->futurePending($subscription, $clock);
                    if ($this->priorCancelIntent) {
                        throw new ProtocolException('A prior cancellation intent cannot be repeated.');
                    }
                    if ($this->item === null) {
                        throw new ProtocolException('The owned Item is unavailable.');
                    }
                    $item = $billing->subscriptions()->retrieveItem($this->subscription, $this->item);
                    self::fields($item, ['id' => $this->item, 'subscription_id' => $this->subscription,
                        'price' => ['id' => $this->price, 'currency' => 'EUR']]);
                    if (!ExactDecimal::equalsLiteral($item['quantity'] ?? null, '1')) {
                        throw new ProtocolException('The owned Item quantity changed.');
                    }
                    $safe['subscription_scope_verified'] = true;
                    $this->phase = 'subscription_cancel';
                    $subscription = $billing->subscriptions()->retrieve($this->subscription);
                    $this->verify($subscription, 'subscriptions');
                    if (($subscription['status'] ?? null) !== 'CANCELLED') {
                        $this->futurePending($subscription, $clock);
                        $request = $this->reserve(
                            $ledger,
                            'cancel_subscription',
                            $this->subscription,
                            ['proration_behavior' => 'NONE']
                        );
                        $this->futurePending($subscription, $clock);
                        $safe['pending_future_start_verified'] = true;
                        $safe['subscription_cancel_sent'] = true;
                        $this->sent(function () use ($billing, $request, $id): void {
                            $response = $billing->subscriptions()->cancel(
                                $id,
                                ['proration_behavior' => 'NONE', 'request_id' => $request]
                            );
                            self::fields($response, ['id' => $this->subscription]);
                        });
                        $this->phase = 'subscription_cancel_readback';
                        for ($i = 0; $i < $attempts; ++$i) {
                            $subscription = $billing->subscriptions()->retrieve($this->subscription);
                            $this->verify($subscription, 'subscriptions');
                            if (($subscription['status'] ?? null) === 'CANCELLED') {
                                break;
                            }
                            if (($subscription['status'] ?? null) !== 'PENDING') {
                                throw new ProtocolException('The cancellation readback has an unsupported state.');
                            }
                            if ($i + 1 < $attempts) {
                                $wait([1, 2, 4, 8, 8, 7][$i]);
                            }
                        }
                    }
                } else {
                    $safe['subscription_scope_verified'] = true;
                }
                if (($subscription['status'] ?? null) !== 'CANCELLED') {
                    throw new ProtocolException('Cancellation remains unconfirmed within the read bound.');
                }
                $safe['subscription_cancelled_verified'] = true;
            }
            $this->phase = 'archive_price';
            $price = $billing->prices()->retrieve($this->price);
            $this->verify($price, 'prices');
            if ($price['active'] === false) {
                $safe['price_already_inactive'] = true;
            } else {
                $this->archive($session, $ledger, 'prices', $this->price);
                $safe['price_archived'] = true;
            }
            $this->phase = 'archive_product';
            $product = $billing->products()->retrieve($this->product);
            $this->verify($product, 'products');
            if ($product['active'] === false) {
                $safe['product_already_inactive'] = true;
            } else {
                $this->archive($session, $ledger, 'products', $this->product);
                $safe['product_archived'] = true;
            }
            $this->unchanged();
            $safe['prior_ledger_unchanged'] = true;
            $safe['result'] = 'pass';
        } catch (\Throwable $error) {
            if ($error instanceof ApiException) {
                $safe['http_status'] = $error->httpStatus();
                $safe['provider_code'] = in_array($error->providerCode(), ['validation_error',
                    'invalid_status_for_operation', 'unauthorized', 'resource_not_found', 'internal_error'], true)
                    ? $error->providerCode() : 'unlisted';
            }
        }
        return $safe + ['phase' => $this->phase, 'ambiguous_mutation' => $this->ambiguous,
            'reconciliation_required' => $safe['result'] !== 'pass',
            'global_subscription_absence_proven' => false];
    }

    /** @param array<array-key,mixed> $object */
    private function verify(#[SensitiveParameter] array $object, string $kind): void
    {
        $id = match ($kind) {
            'customers' => $this->customer, 'products' => $this->product,
            'prices' => $this->price, default => $this->subscription,
        };
        self::fields($object, ['id' => $id, 'metadata' => [
            'sdk_validation_run' => $this->marker, 'sdk_validation_case' => $this->case,
        ]]);
        if ($kind === 'customers') {
            self::fields($object, ['name' => $this->marker, 'email' => $this->marker . '@example.test',
                'default_billing_currency' => 'EUR', 'default_legal_entity_id' => $this->entity]);
        } elseif ($kind === 'products') {
            self::fields($object, ['name' => $this->marker]);
        } elseif ($kind === 'prices') {
            self::fields($object, ['product_id' => $this->product, 'currency' => 'EUR', 'pricing_model' => 'PER_UNIT',
                'billing_type' => 'IN_ADVANCE', 'recurring' => ['period' => 1,
                    'period_unit' => in_array($this->case, ['checkout-trial', 'immediate-oob-trial'], true)
                        ? 'MONTH' : 'DAY']]);
            if (!ExactDecimal::equalsLiteral($object['unit_amount'] ?? null, '1.23')) {
                throw new ProtocolException('The owned Price amount changed.');
            }
        } else {
            self::fields($object, ['billing_customer_id' => $this->customer, 'legal_entity_id' => $this->entity,
                'currency' => 'EUR', 'collection_method' => 'OUT_OF_BAND']);
            $source = $object['payment_source_id'] ?? null;
            if ($source !== null && $source !== '') {
                throw new ProtocolException('The owned Subscription has an unexpected payment source.');
            }
        }
        if (in_array($kind, ['products', 'prices'], true) && !is_bool($object['active'] ?? null)) {
            throw new ProtocolException('The catalogue active state is unavailable.');
        }
    }

    /**
     * @param array<array-key,mixed> $subscription
     * @param Closure():mixed $clock
     */
    private function futurePending(#[SensitiveParameter] array $subscription, Closure $clock): void
    {
        self::fields($subscription, ['days_until_due' => 30]);
        $now = $clock();
        $value = $subscription['starts_at'] ?? null;
        if (
            !is_int($now) || $now < 0 || ($subscription['status'] ?? null) !== 'PENDING'
            || !is_string($value) || strlen($value) > 128
            || preg_match(
                '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.[0-9]+)?(?:Z|[+-](\d{2}):?(\d{2}))$/D',
                $value,
                $parts
            ) !== 1 || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59
            || (int) ($parts[7] ?? '0') > 23 || (int) ($parts[8] ?? '0') > 59
        ) {
            throw new ProtocolException('Cleanup requires the exact owned future PENDING state.');
        }
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19) || $date->getTimestamp() <= $now
        ) {
            throw new ProtocolException('Cleanup requires an absolute future start.');
        }
    }

    /** @param array<string,mixed> $body */
    private function reserve(RunLedger $ledger, string $action, #[SensitiveParameter] string $id, array $body): string
    {
        $this->unchanged();
        return $ledger->requestId($action, ['prior_ledger_sha256' => $this->digest,
            'original_run_fingerprint' => hash('sha256', $this->marker), 'operation' => $action,
            'resource_id' => $id, 'body' => $body], $this->fingerprint);
    }

    private function sent(Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $error) {
            $this->ambiguous = true;
            throw $error;
        }
    }

    private function archive(SandboxSession $session, RunLedger $ledger, string $kind, string $id): void
    {
        $request = $this->reserve($ledger, 'archive_' . $kind, $id, ['active' => false]);
        $resources = $kind === 'prices' ? $session->client->billing()->prices()
            : $session->client->billing()->products();
        $this->sent(function () use ($resources, $id, $request, $kind): void {
            self::fields($resources->update($id, ['active' => false, 'request_id' => $request]), ['id' => $id]);
            $object = $resources->retrieve($id);
            $this->verify($object, $kind);
            self::fields($object, ['active' => false]);
        });
    }

    private function unchanged(): void
    {
        self::privateFile($this->path);
        $digest = @hash_file('sha256', $this->path);
        if (!is_string($digest) || !hash_equals($this->digest, $digest)) {
            throw new RuntimeException('The prior schedule ledger changed during cleanup.');
        }
    }

    /**
     * @param array<array-key,mixed> $actual
     * @param array<array-key,mixed> $expected
     */
    private static function fields(#[SensitiveParameter] array $actual, #[SensitiveParameter] array $expected): void
    {
        foreach ($expected as $key => $value) {
            if (is_array($value) && is_array($actual[$key] ?? null)) {
                self::fields($actual[$key], $value);
            } elseif (!array_key_exists($key, $actual) || $actual[$key] !== $value) {
                throw new ProtocolException('Cleanup readback did not match its recorded owner and configuration.');
            }
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['state' => '[private schedule cleanup references]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Schedule cleanup references cannot be serialized.');
    }
}
