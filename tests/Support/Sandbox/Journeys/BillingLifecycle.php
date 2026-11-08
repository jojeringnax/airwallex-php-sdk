<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use DateTimeImmutable;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use RuntimeException;
use SensitiveParameter;

/** An owned, manually collected sandbox subscription; never collects or marks payment. */
final class BillingLifecycle
{
    private bool $ambiguous = false;
    private ?string $blocked = null;
    private bool $pending = false;
    /** @var array<string,string> */
    private array $steps = [];
    /** @var array<string,string|bool> */
    private array $observations = [];
    /** @var array<string,mixed> */
    private array $subscriptionFields = [];
    private string $customer = '';
    private string $subscription = '';
    private string $firstPrice = '';
    private string $secondPrice = '';

    private function __construct(
        private readonly SandboxSession $session,
        private readonly RunLedger $ledger,
        #[SensitiveParameter] private readonly string $legalEntity,
        private readonly Closure $wait,
        private readonly int $observationAttempts,
        private readonly bool $withTrial,
    ) {
    }

    /** @return array<string,mixed> */
    public static function execute(
        SandboxSession $session,
        RunLedger $ledger,
        #[SensitiveParameter] string $legalEntity,
        ?Closure $clock = null,
        ?Closure $wait = null,
        int $observationAttempts = 7,
        bool $withTrial = true,
    ): array {
        if (
            !$session->isOrganization() || $legalEntity === ''
            || $observationAttempts < 1 || $observationAttempts > 7
        ) {
            throw new RuntimeException('Billing lifecycle requires an organization and legal entity.');
        }
        $ledger->assertContext($session->contextFingerprint());
        $journey = new self(
            $session,
            $ledger,
            $legalEntity,
            $wait ?? static fn (int $seconds) => sleep($seconds),
            $observationAttempts,
            $withTrial,
        );
        $result = [
            'scenario' => $withTrial ? 'billing-lifecycle' : 'billing-lifecycle-no-trial',
            'api_version' => '2026-08-21', 'result' => 'pass', 'trial_requested' => $withTrial,
        ];
        try {
            $now = ($clock ?? static fn (): int => time())();
            if (!is_int($now) || $now < 0) {
                throw new RuntimeException('A scenario clock must return Unix seconds.');
            }
            $journey->catalogue();
            $journey->lifecycle($now);
        } catch (ApiException $error) {
            $result['result'] = 'fail';
            $result['http_status'] = $error->httpStatus();
            $result['provider_code'] = $error->providerCode();
            $result['provider_source'] = $error->providerSource();
        } catch (\Throwable) {
            $result['result'] = $journey->blocked !== null && !$journey->ambiguous
                ? ($journey->pending ? 'pending' : 'blocked') : 'fail';
            $result['reason'] = $journey->blocked ?? 'local_protocol_or_transport';
        } finally {
            $cleanup = $journey->cleanup();
            if (in_array('fail', $cleanup, true) || $journey->ambiguous) {
                $result['result'] = 'fail';
            }
        }
        return $result + [
            'steps' => $journey->steps, 'cleanup' => $cleanup, 'ambiguous_mutation' => $journey->ambiguous,
            'observations' => $journey->observations,
            'created_counts' => $ledger->counts(), 'run_id' => $ledger->runId(),
            'retained' => 'Created profiles, subscription/invoice records and catalogue history remain; '
                . 'inspect cleanup outcomes.',
        ] + $ledger->evidence();
    }

    private function catalogue(): void
    {
        $billing = $this->session->client->billing();
        $marker = ['sdk_validation_run' => $this->ledger->runId()];
        $body = [
            'name' => $this->ledger->runId(), 'email' => $this->ledger->runId() . '@example.test',
            'default_billing_currency' => 'EUR', 'default_legal_entity_id' => $this->legalEntity, 'metadata' => $marker,
        ];
        $created = $this->mutate(
            'create_customer',
            $body,
            static fn (array $wire): array => $billing->customers()->create($wire),
            createdKind: 'customers',
        );
        $this->customer = self::id($created);
        self::fields($billing->customers()->retrieve($this->customer), $body + ['id' => $this->customer]);
        $body = ['name' => $this->ledger->runId(), 'metadata' => $marker, 'active' => true];
        $created = $this->mutate(
            'create_product',
            $body,
            static fn (array $wire): array => $billing->products()->create($wire),
            createdKind: 'products',
        );
        $product = self::id($created);
        self::fields($billing->products()->retrieve($product), $body + ['id' => $product]);
        foreach (['first' => '1.23', 'second' => '2.34'] as $name => $amount) {
            $body = [
                'currency' => 'EUR', 'product_id' => $product, 'pricing_model' => 'PER_UNIT', 'unit_amount' => $amount,
                'recurring' => ['period' => 1, 'period_unit' => 'MONTH'], 'billing_type' => 'IN_ADVANCE',
                'metadata' => $marker, 'active' => true,
            ];
            $created = $this->mutate(
                'create_' . $name . '_price',
                $body,
                static fn (array $wire): array => $billing->prices()->create($wire),
                createdKind: 'prices',
            );
            $id = self::id($created);
            $observed = $billing->prices()->retrieve($id);
            unset($body['unit_amount']);
            self::fields($observed, $body + ['id' => $id]);
            if (!ExactDecimal::equalsLiteral($observed['unit_amount'] ?? null, $amount)) {
                throw new ProtocolException('Scenario price amount did not match.');
            }
            if ($name === 'first') {
                $this->firstPrice = $id;
            } else {
                $this->secondPrice = $id;
            }
        }
        $this->steps['fresh_catalogue'] = 'pass';
    }

    private function lifecycle(int $now): void
    {
        $subscriptions = $this->session->client->billing()->subscriptions();
        $start = gmdate('Y-m-d\TH:i:s\Z', $now + 86400);
        $trial = gmdate('Y-m-d\TH:i:s\Z', $now + 172800);
        $timing = ['starts_at' => $start];
        if ($this->withTrial) {
            $timing['trial_ends_at'] = $trial;
        }
        $this->subscriptionFields = [
            'billing_customer_id' => $this->customer, 'legal_entity_id' => $this->legalEntity,
            'collection_method' => 'OUT_OF_BAND', 'currency' => 'EUR', 'days_until_due' => 30,
            'duration' => ['period' => 1, 'period_unit' => 'MONTH'],
            'metadata' => ['sdk_validation_run' => $this->ledger->runId()],
        ];
        $created = $this->mutate(
            $this->withTrial ? 'create_future_trial' : 'create_future_start',
            $this->subscriptionFields + $timing + ['items' => [['price_id' => $this->firstPrice, 'quantity' => 1]]],
            static fn (array $wire): array => $subscriptions->create($wire),
            createdKind: 'subscriptions',
        );
        $this->subscription = self::id($created);
        $pending = $this->subscriptionState('future_start', 'PENDING');
        if (
            !self::sameInstant($pending['starts_at'] ?? null, $start)
            || ($this->withTrial && !self::sameInstant($pending['trial_ends_at'] ?? null, $trial))
        ) {
            throw new ProtocolException('Future start or trial timestamps differ from the requested times.');
        }
        $this->update('start_now', ['starts_at' => 'NOW']);
        if ($this->withTrial) {
            $this->subscriptionState('trial_started', 'IN_TRIAL');
            $this->update('end_trial_now', ['trial_ends_at' => 'NOW']);
            $active = $this->subscriptionState('trial_ended', 'ACTIVE', requireCycle: true);
        } else {
            $active = $this->subscriptionState('activated_without_trial', 'ACTIVE', requireCycle: true);
        }
        $anchor = self::timestamp($active['billing_cycle_anchor_at'] ?? null);
        self::timestamp($active['current_period_starts_at'] ?? null);
        self::timestamp($active['current_period_ends_at'] ?? null);
        $item = $this->item('initial_item', $this->firstPrice, '1');
        $itemId = self::id($item);
        $this->ledger->recordObservation('initial_subscription_item', [
            'subscription_id' => $this->subscription, 'item_id' => $itemId, 'price_id' => $this->firstPrice,
        ], $this->session->contextFingerprint());
        $this->update('quantity_change', [
            'items' => [['id' => $itemId, 'quantity' => 2]],
            'default_proration_mode' => 'PRORATED', 'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE',
        ]);
        $updated = $this->item('quantity_observed', $this->firstPrice, '2');
        $updatedId = self::id($updated);
        $this->ledger->recordObservation('quantity_subscription_item', [
            'subscription_id' => $this->subscription, 'item_id' => $updatedId, 'price_id' => $this->firstPrice,
        ], $this->session->contextFingerprint());
        $this->observations['quantity_item_identity_retained'] = $updatedId === $itemId;
        $itemId = $updatedId;
        $this->keepAnchor('quantity_cycle_kept', $anchor);
        $this->update('plan_change', [
            'items' => [['id' => $itemId, 'deleted' => true], ['price_id' => $this->secondPrice, 'quantity' => 1]],
            'default_proration_mode' => 'NONE', 'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE',
        ]);
        $this->item('replacement_observed', $this->secondPrice, '1', absentId: $itemId);
        $this->keepAnchor('plan_cycle_kept', $anchor);
        $this->update('schedule_ending', ['cancel_at_period_end' => true]);
        $this->subscriptionState('ending_scheduled', 'ACTIVE', ['cancel_at_period_end' => true]);
        $this->update('reverse_scheduled_ending', ['cancel_at_period_end' => false]);
        $observed = $this->subscriptionState('period_end_flag_cleared', 'ACTIVE', ['cancel_at_period_end' => false]);
        $this->invoices($observed);
    }

    /** @param array<string,mixed> $body */
    private function update(string $action, array $body): void
    {
        $subscriptions = $this->session->client->billing()->subscriptions();
        $this->mutate(
            $action,
            $body,
            fn (array $wire): array => $subscriptions->update($this->subscription, $wire),
            expectedId: $this->subscription,
        );
    }

    /**
     * @param array<string,mixed> $body
     * @param Closure(array<string,mixed>):array<array-key,mixed> $send
     * @return array<array-key,mixed>
     */
    private function mutate(
        string $action,
        #[SensitiveParameter] array $body,
        Closure $send,
        ?string $createdKind = null,
        #[SensitiveParameter] ?string $expectedId = null,
    ): array {
        $body['request_id'] = $this->ledger->requestId($action, $body, $this->session->contextFingerprint());
        try {
            $response = $send($body);
            if ($createdKind !== null) {
                $this->ledger->recordCreated($createdKind, self::id($response));
            }
            if ($expectedId !== null) {
                self::fields($response, ['id' => $expectedId]);
            }
            return $response;
        } catch (ValidationException | ConfigurationException $error) {
            throw $error; // Local rejection proves this mutation was not sent.
        } catch (\Throwable $error) {
            $this->ambiguous = true; // Even an HTTP rejection is not treated as permission for another mutation.
            throw $error;
        }
    }

    /**
     * @param Closure():array<array-key,mixed> $read
     * @param Closure(array<array-key,mixed>):bool $matches
     * @return array<array-key,mixed>
     */
    private function observe(string $step, Closure $read, Closure $matches): array
    {
        $delays = [1, 2, 4, 8, 8, 7];
        for ($attempt = 0; $attempt < $this->observationAttempts; ++$attempt) {
            $resource = $read();
            if ($matches($resource)) {
                $this->steps[$step] = 'pass';
                return $resource;
            }
            if ($attempt + 1 < $this->observationAttempts) {
                ($this->wait)($delays[$attempt]);
            }
        }
        $this->blocked = $step . '_not_observed_within_read_bound';
        $this->pending = true;
        throw new RuntimeException('Required provider observation is not available within the read bound.');
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<array-key,mixed>
     */
    private function subscriptionState(
        string $step,
        string $state,
        array $extra = [],
        ?string $resourceId = null,
        bool $requireCycle = false,
    ): array {
        $id = $resourceId ?? $this->subscription;
        return $this->observe(
            $step,
            fn (): array => $this->session->client->billing()->subscriptions()->retrieve($id),
            function (array $resource) use ($state, $extra, $id, $requireCycle): bool {
                self::fields($resource, $state === 'CANCELLED'
                    ? $this->ownershipFields($id) : $this->subscriptionFields + ['id' => $id]);
                if ($requireCycle) {
                    $cycleFields = ['billing_cycle_anchor_at', 'current_period_starts_at', 'current_period_ends_at'];
                    foreach ($cycleFields as $field) {
                        if (!isset($resource[$field]) || $resource[$field] === '') {
                            return false;
                        }
                        self::timestamp($resource[$field]);
                    }
                }
                return self::contains($resource, ['status' => $state] + $extra);
            },
        );
    }

    private function keepAnchor(string $step, string $anchor): void
    {
        $resource = $this->subscriptionState($step, 'ACTIVE', requireCycle: true);
        if (!self::sameInstant($resource['billing_cycle_anchor_at'] ?? null, $anchor)) {
            throw new ProtocolException('A keep-cycle action changed its billing cycle anchor.');
        }
    }

    /** @return array<array-key,mixed> */
    private function item(
        string $step,
        string $price,
        string $quantity,
        ?string $absentId = null,
    ): array {
        $found = [];
        $this->observe(
            $step,
            fn (): array => $this->session->client->billing()->subscriptions()->listItems(
                $this->subscription,
                ['page_size' => 20],
            ),
            function (array $page) use ($price, $quantity, $absentId, &$found): bool {
                $found = [];
                $items = self::items($page);
                if (count($items) !== 1 || ($page['page_after'] ?? null) !== null) {
                    return false;
                }
                foreach ($items as $resource) {
                    self::fields($resource, ['subscription_id' => $this->subscription]);
                    $id = self::id($resource);
                    if ($id === $absentId) {
                        return false;
                    }
                    if (
                        self::contains($resource, ['price' => ['id' => $price, 'currency' => 'EUR']])
                        && ExactDecimal::equalsLiteral($resource['quantity'] ?? null, $quantity)
                    ) {
                        $found = $resource;
                    }
                }
                return $found !== [];
            },
        );
        return $found;
    }

    /** @param array<array-key,mixed> $subscription */
    private function invoices(#[SensitiveParameter] array $subscription): void
    {
        $invoices = $this->session->client->billing()->invoices();
        $latest = $subscription['latest_invoice_id'] ?? null;
        if (is_string($latest) && $latest !== '') {
            $invoice = $invoices->retrieve($latest);
        } else {
            $page = $this->observe(
                'invoice_available',
                fn (): array => $invoices->list([
                    'billing_customer_id' => $this->customer,
                    'subscription_id' => $this->subscription, 'page_size' => 20,
                ]),
                static fn (array $page): bool => self::items($page) !== [],
            );
            $items = self::items($page);
            foreach ($items as $listed) {
                self::fields($listed, [
                    'billing_customer_id' => $this->customer, 'subscription_id' => $this->subscription,
                ]);
            }
            $invoice = $invoices->retrieve(self::id($items[0]));
        }
        self::fields($invoice, [
            'billing_customer_id' => $this->customer, 'subscription_id' => $this->subscription,
            'currency' => 'EUR', 'collection_method' => 'OUT_OF_BAND',
        ]);
        self::decimal($invoice['amount_due'] ?? null);
        self::decimal($invoice['total_amount'] ?? null);
        $invoiceStatus = $invoice['status'] ?? null;
        $paymentStatus = $invoice['payment_status'] ?? null;
        if (
            !is_string($invoiceStatus) || !in_array($invoiceStatus, ['DRAFT', 'FINALIZED', 'VOIDED'], true)
            || !is_string($paymentStatus) || !in_array($paymentStatus, ['UNPAID', 'PAID'], true)
        ) {
            $this->blocked = 'invoice_state_unfamiliar';
            throw new RuntimeException('An unfamiliar invoice state needs inspection.');
        }
        foreach (['pdf_url', 'hosted_url'] as $field) {
            if (isset($invoice[$field]) && !is_string($invoice[$field])) {
                throw new ProtocolException('Invoice document field has an unexpected type.');
            }
            $this->observations[$field . '_present'] = array_key_exists($field, $invoice);
            $this->observations[$field . '_nonempty'] = is_string($invoice[$field] ?? null) && $invoice[$field] !== '';
        }
        $this->observations['invoice_status'] = $invoiceStatus;
        $this->observations['invoice_payment_status'] = $paymentStatus;
        $invoiceId = self::id($invoice);
        $page = $invoices->listLineItems($invoiceId, ['page_size' => 20]);
        $items = self::items($page);
        if ($items === [] || ($page['page_after'] ?? null) !== null) {
            $this->blocked = 'invoice_line_items_not_available_within_page_bound';
            throw new RuntimeException('Invoice line items require another bounded observation.');
        }
        foreach ($items as $line) {
            self::id($line);
            self::fields($line, ['invoice_id' => $invoiceId]);
            self::decimal($line['amount'] ?? null);
            if (!is_int($line['quantity'] ?? null)) {
                throw new ProtocolException('Invoice quantity did not retain its documented integer representation.');
            }
        }
        $this->steps['invoice_and_document_fields_read'] = 'pass';
    }

    /** @return array<string,string> */
    private function cleanup(): array
    {
        if ($this->ambiguous) {
            return ['writes' => 'skipped_ambiguous_mutation'];
        }
        $result = [];
        foreach ($this->ledger->created('subscriptions') as $index => $id) {
            try {
                $this->ledger->assertContext($this->session->contextFingerprint());
                $resource = $this->session->client->billing()->subscriptions()->retrieve($id);
                self::fields($resource, ($resource['status'] ?? null) === 'CANCELLED'
                    ? $this->ownershipFields($id) : $this->subscriptionFields + ['id' => $id]);
                if (($resource['status'] ?? null) !== 'CANCELLED') {
                    if (!in_array($resource['status'] ?? null, ['PENDING', 'IN_TRIAL', 'ACTIVE', 'UNPAID'], true)) {
                        throw new ProtocolException('Cleanup cannot mutate an unfamiliar subscription state.');
                    }
                    $this->mutate(
                        'cleanup_cancel_subscription_' . $index,
                        ['proration_behavior' => 'NONE'],
                        fn (array $wire): array
                            => $this->session->client->billing()->subscriptions()->cancel($id, $wire),
                        expectedId: $id,
                    );
                    $this->subscriptionState('cleanup_cancelled', 'CANCELLED', resourceId: $id);
                }
                $result['subscriptions_' . $index] = 'pass';
            } catch (\Throwable) {
                $result['subscriptions_' . $index] = 'fail';
                return $result; // Cancellation must be confirmed before further cleanup mutations.
            }
        }
        foreach (['prices', 'products'] as $kind) {
            foreach ($this->ledger->created($kind) as $index => $id) {
                try {
                    $resources = $kind === 'prices'
                        ? $this->session->client->billing()->prices() : $this->session->client->billing()->products();
                    $response = $this->mutate(
                        'cleanup_archive_' . $kind . '_' . $index,
                        ['active' => false],
                        static fn (array $wire): array => $resources->update($id, $wire),
                        expectedId: $id,
                    );
                    self::fields($response, ['active' => false]);
                    $result[$kind . '_' . $index] = 'pass';
                } catch (\Throwable) {
                    $result[$kind . '_' . $index] = 'fail';
                    return $result;
                }
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function ownershipFields(string $id): array
    {
        return array_intersect_key($this->subscriptionFields, array_fill_keys([
            'billing_customer_id', 'legal_entity_id', 'currency', 'collection_method', 'metadata',
        ], true)) + ['id' => $id];
    }

    /** @param array<array-key,mixed> $resource */
    private static function id(#[SensitiveParameter] array $resource): string
    {
        $id = $resource['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new ProtocolException('A scenario resource identifier is unavailable.');
        }
        return $id;
    }

    /**
     * @param array<array-key,mixed> $resource
     * @param array<string,mixed> $expected
     */
    private static function fields(#[SensitiveParameter] array $resource, #[SensitiveParameter] array $expected): void
    {
        if (!self::contains($resource, $expected)) {
            throw new ProtocolException('A scenario resource differs from its expected fields.');
        }
    }

    private static function contains(mixed $resource, mixed $expected): bool
    {
        if (is_array($resource) && is_array($expected)) {
            foreach ($expected as $key => $value) {
                if (!array_key_exists($key, $resource) || !self::contains($resource[$key], $value)) {
                    return false;
                }
            }
            return true;
        }
        return $resource === $expected;
    }

    /**
     * @param array<array-key,mixed> $page
     * @return list<array<array-key,mixed>>
     */
    private static function items(#[SensitiveParameter] array $page): array
    {
        $items = $page['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new ProtocolException('A scenario list envelope is unavailable.');
        }
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new ProtocolException('A scenario list item is unavailable.');
            }
            $result[] = $item;
        }
        return $result;
    }

    private static function decimal(#[SensitiveParameter] mixed $value): void
    {
        if (
            !is_string($value)
            || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $value) !== 1
        ) {
            throw new ProtocolException('A scenario decimal did not retain exact numeric text.');
        }
    }

    private static function timestamp(#[SensitiveParameter] mixed $value): string
    {
        if (
            !is_string($value)
            || preg_match(
                '/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})'
                . '(?:\.[0-9]+)?(?:Z|[+-]([0-9]{2}):?([0-9]{2}))$/D',
                $value,
                $parts,
            ) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59
            || (int) ($parts[7] ?? '0') > 23 || (int) ($parts[8] ?? '0') > 59
        ) {
            throw new ProtocolException('A scenario timestamp is unavailable.');
        }
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)
        ) {
            throw new ProtocolException('A scenario timestamp did not retain its calendar/time values.');
        }
        return $value;
    }

    private static function sameInstant(#[SensitiveParameter] mixed $value, string $expected): bool
    {
        $actual = self::timestamp($value);
        preg_match('/\.([0-9]+)/', $actual, $actualFraction);
        preg_match('/\.([0-9]+)/', $expected, $expectedFraction);
        return (new DateTimeImmutable($actual))->getTimestamp() === (new DateTimeImmutable($expected))->getTimestamp()
            && rtrim($actualFraction[1] ?? '', '0') === rtrim($expectedFraction[1] ?? '', '0');
    }
}
