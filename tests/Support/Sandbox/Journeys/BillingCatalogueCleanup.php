<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use RuntimeException;
use SensitiveParameter;

/** Archives only a failed lifecycle run's recorded catalogue; it never resumes its financial action. */
final class BillingCatalogueCleanup
{
    private bool $used = false;
    private bool $ambiguous = false;

    /**
     * @param list<string> $prices
     * @param list<string> $subscriptions
     */
    private function __construct(
        #[SensitiveParameter] private readonly string $customer,
        #[SensitiveParameter] private readonly string $product,
        #[SensitiveParameter] private readonly array $prices,
        #[SensitiveParameter] private readonly string $marker,
        private readonly string $contextFingerprint,
        private readonly string $originalDigest,
        #[SensitiveParameter] private readonly array $subscriptions,
        private readonly bool $verifyCancelledSubscriptions,
    ) {
    }

    public static function load(
        #[SensitiveParameter] string $path,
        SandboxSession $session,
        bool $verifyCancelledSubscriptions = false,
    ): self {
        if (!$session->isOrganization() || !str_starts_with($path, '/')) {
            throw new RuntimeException('Cleanup requires organization context and an absolute private ledger.');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('Original private state is unavailable.');
        }
        $fileMode = @fileperms($resolved);
        $directoryMode = @fileperms(dirname($resolved));
        if (
            PHP_OS_FAMILY !== 'Windows'
            && ($fileMode === false || $directoryMode === false || ($fileMode & 0077) !== 0
                || ($directoryMode & 0077) !== 0)
        ) {
            throw new RuntimeException('Original state requires private file and directory permissions.');
        }
        $raw = @file_get_contents($resolved, length: 1048577);
        if ($raw === false || strlen($raw) > 1048576) {
            throw new RuntimeException('Original private state is unavailable.');
        }
        $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (
            !is_array($state) || ($state['api_version'] ?? null) !== '2026-08-21'
            || !is_string($state['context_fingerprint'] ?? null)
            || !hash_equals($session->contextFingerprint(), $state['context_fingerprint'])
        ) {
            throw new RuntimeException('Original state does not match the selected version and owner.');
        }
        $marker = $state['run_id'] ?? null;
        $resources = $state['resources'] ?? null;
        $actions = $state['actions'] ?? null;
        if (
            !is_string($marker) || preg_match('/^sdk-validation-[a-f0-9-]{36}$/D', $marker) !== 1
            || !is_array($resources) || !is_array($actions)
            || (!is_array($actions['create_future_trial'] ?? null)
                && !is_array($actions['create_future_start'] ?? null))
        ) {
            throw new RuntimeException('Cleanup requires a recorded lifecycle create and valid run inventory.');
        }
        $subscriptions = self::references($resources['subscriptions'] ?? []);
        if (!$verifyCancelledSubscriptions && $subscriptions !== []) {
            throw new RuntimeException('Default cleanup cannot handle recorded Subscriptions.');
        }
        return new self(
            self::references($resources['customers'] ?? null, 1)[0],
            self::references($resources['products'] ?? null, 1)[0],
            self::references($resources['prices'] ?? null, 2),
            $marker,
            $session->contextFingerprint(),
            hash('sha256', $raw),
            $subscriptions,
            $verifyCancelledSubscriptions,
        );
    }

    /** @return list<non-empty-string> */
    private static function references(mixed $value, ?int $count = null): array
    {
        if (!is_array($value) || !array_is_list($value) || ($count !== null && count($value) !== $count)) {
            throw new RuntimeException('Original state has an unexpected catalogue inventory.');
        }
        $result = [];
        foreach ($value as $id) {
            if (!is_string($id) || $id === '' || in_array($id, $result, true)) {
                throw new RuntimeException('Original state contains an invalid or repeated reference.');
            }
            $result[] = $id;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function execute(SandboxSession $session, RunLedger $ledger): array
    {
        if (
            $this->used || !$session->isOrganization()
            || !hash_equals($this->contextFingerprint, $session->contextFingerprint())
        ) {
            throw new RuntimeException('Cleanup references cannot be reused or cross contexts.');
        }
        $ledger->assertContext($session->contextFingerprint());
        $this->used = true;
        $result = [
            'scenario' => 'billing-catalogue-cleanup', 'api_version' => '2026-08-21', 'result' => 'fail',
            'original_run_fingerprint' => hash('sha256', $this->marker), 'catalogue_verified' => false,
            'prices_archived' => 0, 'prices_already_inactive' => 0,
            'product_archived' => false, 'product_already_inactive' => false,
            'subscription_mutation_sent' => false, 'run_id' => $ledger->runId(),
            'cleanup_mode' => $this->verifyCancelledSubscriptions
                ? 'verify-cancelled-subscriptions' : 'catalogue-only',
            'cancelled_subscriptions_verified' => 0,
        ] + $ledger->evidence();
        $stage = 'catalogue_preflight';
        try {
            $billing = $session->client->billing();
            $this->verify($billing->customers()->retrieve($this->customer), $this->customer, 'customers');
            foreach ($this->subscriptions as $id) {
                $subscription = $billing->subscriptions()->retrieve($id);
                $this->verify($subscription, $id, 'subscriptions');
                if (
                    ($subscription['billing_customer_id'] ?? null) !== $this->customer
                    || ($subscription['status'] ?? null) !== 'CANCELLED'
                ) {
                    throw new ProtocolException('Cleanup requires an owned recorded Subscription already cancelled.');
                }
                ++$result['cancelled_subscriptions_verified'];
            }
            $product = $billing->products()->retrieve($this->product);
            $this->verify($product, $this->product, 'products');
            $prices = [];
            foreach ($this->prices as $index => $id) {
                $price = $billing->prices()->retrieve($id);
                $this->verify($price, $id, 'prices', $index);
                $prices[] = $price;
            }
            // Every target and relationship is verified before the first mutation identity is generated.
            $result['catalogue_verified'] = true;
            foreach ($this->prices as $index => $id) {
                if ($prices[$index]['active'] === false) {
                    ++$result['prices_already_inactive'];
                    continue;
                }
                $stage = 'archive_price_' . $index;
                $this->archive($session, $ledger, 'prices', $id, $index);
                ++$result['prices_archived'];
            }
            if ($product['active'] === false) {
                $result['product_already_inactive'] = true;
            } else {
                $stage = 'archive_product';
                $this->archive($session, $ledger, 'products', $this->product);
                $result['product_archived'] = true;
            }
            $result['result'] = 'pass';
        } catch (\Throwable $error) {
            if ($error instanceof ApiException) {
                $result['http_status'] = $error->httpStatus();
            }
        }
        return $result + [
            'phase' => $stage, 'ambiguous_mutation' => $this->ambiguous,
            'reconciliation_required' => $result['result'] !== 'pass',
            'subscription_reconciliation_complete' => false,
        ];
    }

    /** @param array<array-key,mixed> $object */
    private function verify(array $object, string $id, string $kind, int $index = 0): void
    {
        $metadata = $object['metadata'] ?? null;
        if (
            ($object['id'] ?? null) !== $id || !is_array($metadata)
            || ($metadata['sdk_validation_run'] ?? null) !== $this->marker
            || (in_array($kind, ['prices', 'products'], true) && !is_bool($object['active'] ?? null))
        ) {
            throw new ProtocolException('Cleanup resource ownership or state did not match.');
        }
        if ($kind === 'prices') {
            $recurring = $object['recurring'] ?? null;
            if (
                ($object['product_id'] ?? null) !== $this->product || ($object['currency'] ?? null) !== 'EUR'
                || ($object['pricing_model'] ?? null) !== 'PER_UNIT' || !is_array($recurring)
                || ($recurring['period'] ?? null) !== 1 || ($recurring['period_unit'] ?? null) !== 'MONTH'
                || ($object['billing_type'] ?? null) !== 'IN_ADVANCE'
                || !ExactDecimal::equalsLiteral($object['unit_amount'] ?? null, $index === 0 ? '1.23' : '2.34')
            ) {
                throw new ProtocolException('Cleanup Price catalogue relationship or configuration did not match.');
            }
        }
    }

    private function archive(SandboxSession $session, RunLedger $ledger, string $kind, string $id, int $index = 0): void
    {
        $request = $ledger->requestId('cleanup_' . $this->marker . '_' . $kind . '_' . $index, [
            'original_run' => $this->marker, 'original_ledger_sha256' => $this->originalDigest,
            'resource_kind' => $kind, 'resource_id' => $id, 'active' => false,
        ], $session->contextFingerprint());
        try {
            $billing = $session->client->billing();
            $resource = $kind === 'prices' ? $billing->prices() : $billing->products();
            $response = $resource->update($id, ['active' => false, 'request_id' => $request]);
            if (($response['id'] ?? null) !== $id || ($response['active'] ?? null) !== false) {
                throw new ProtocolException('Cleanup archive acknowledgement did not match.');
            }
            $observed = $resource->retrieve($id);
            $this->verify($observed, $id, $kind, $index);
            if ($observed['active'] !== false) {
                throw new ProtocolException('Cleanup archive was not observed.');
            }
        } catch (\Throwable $error) {
            $this->ambiguous = true;
            throw $error;
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['state' => '[private catalogue cleanup references]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Private cleanup references cannot be serialized.');
    }
}
