<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use DateTimeImmutable;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use RuntimeException;
use SensitiveParameter;

/** One fresh isolated schedule/trial/cancellation observation; no retained resource can be supplied. */
final class BillingScheduleEvidence
{
    public const array CASES = [
        'flag-one-day', 'flag-seven-days', 'finite-ends', 'finite-duration', 'finite-remaining',
        'clear-all-pending', 'cancel-all', 'cancel-prorated', 'checkout-trial',
        'remaining-active-only', 'flag-restore-end', 'immediate-oob-trial',
    ];
    private ?string $trialEnd = null;
    private bool $ambiguous = false;
    private bool $cancelAttempted = false;
    private bool $cancelVerified = false;
    private bool $allNullSent = false;
    private bool $capNotVisible = false;
    private string $customer = '';
    private string $product = '';
    private string $price = '';
    private string $subscription = '';
    private string $phase = 'configuration';
    /** @var array<string,mixed> */
    private array $scope = [];
    /** @var array<string,mixed> */
    private array $priceFields = [];
    /** @var array<string,mixed> */
    private array $productFields = [];
    /** @var array{period:int,period_unit:string}|null */
    private ?array $initialDuration = null;
    /** @var array<string,string|bool> */
    private array $observations = [];
    /** @var array<string,string> */
    private array $steps = [];

    private function __construct(
        private readonly SandboxSession $session,
        private readonly RunLedger $ledger,
        private readonly string $case,
        #[SensitiveParameter] private readonly string $entity,
        #[SensitiveParameter] private readonly string $collector,
        private readonly Closure $clock,
        private readonly Closure $wait,
        private readonly int $attempts,
    ) {
    }

    /** @return array<string,mixed> */
    public static function execute(
        SandboxSession $session,
        RunLedger $ledger,
        string $case,
        #[SensitiveParameter] string $entity,
        #[SensitiveParameter] string $collector,
        ?Closure $clock = null,
        ?Closure $wait = null,
        int $attempts = 7,
    ): array {
        if (
            !$session->isOrganization() || !in_array($case, self::CASES, true) || $entity === ''
            || $collector === '' || $attempts < 1 || $attempts > 7
        ) {
            throw new RuntimeException('A fresh explicit organization evidence case is required.');
        }
        $ledger->assertContext(self::contextFingerprint($session, $collector));
        $journey = new self(
            $session,
            $ledger,
            $case,
            $entity,
            $collector,
            $clock ?? static fn (): int => time(),
            $wait ?? static fn (int $seconds) => sleep($seconds),
            $attempts
        );
        $result = ['scenario' => 'billing-schedule-evidence', 'case' => $case,
            'api_version' => '2026-08-21', 'result' => 'pass'];
        try {
            $journey->run();
            if (in_array($case, ['finite-remaining', 'clear-all-pending', 'remaining-active-only'], true)) {
                $result['result'] = 'pending';
                $result['reason'] = 'request_and_visible_fields_only';
            }
        } catch (ApiException $error) {
            $result['result'] = 'fail';
            $result['reason'] = 'provider_rejection';
            $result['http_status'] = $error->httpStatus();
        } catch (\Throwable) {
            $result['result'] = ($journey->observations['read_bound_exhausted'] ?? false) && !$journey->ambiguous
                ? 'pending' : 'fail';
            $result['reason'] = $result['result'] === 'pending' ? 'observation_not_in_read_bound' : 'local_failure';
        } finally {
            $casePhase = $journey->phase;
            $cleanup = $journey->cleanup();
            if (in_array('fail', $cleanup, true) || $journey->ambiguous) {
                if ($result['result'] !== 'fail') {
                    $result['reason'] = 'cleanup_failed';
                }
                $result['result'] = 'fail';
            }
        }
        return $result + [
            'phase' => $casePhase, 'cleanup_phase' => $journey->phase,
            'steps' => $journey->steps, 'observations' => $journey->observations,
            'cleanup' => $cleanup, 'ambiguous_mutation' => $journey->ambiguous,
            'temporary_indefinite_possible' => ($journey->allNullSent || $journey->capNotVisible)
                && !$journey->cancelVerified,
            'cancellation_verified' => $journey->cancelVerified,
            'reconciliation_required' => $journey->ambiguous || in_array('fail', $cleanup, true),
            'funds_movement_unverified' => true, 'run_id' => $ledger->runId(),
            'created_counts' => $ledger->counts(),
        ] + $ledger->evidence();
    }

    public static function contextFingerprint(SandboxSession $session, #[SensitiveParameter] string $collector): string
    {
        return hash('sha256', $session->contextFingerprint() . "\0" . $collector);
    }

    private function run(): void
    {
        $now = ($this->clock)();
        if (
            !is_int($now) || $now < 0
            || ($this->case === 'immediate-oob-trial' && $now > PHP_INT_MAX - 172800)
        ) {
            throw new RuntimeException('A case requires a Unix-second clock.');
        }
        $this->catalogue();
        if ($this->case === 'immediate-oob-trial') {
            $this->immediateTrial($now);
            return;
        }
        $trial = $this->case === 'checkout-trial';
        $start = gmdate('Y-m-d\TH:i:s\Z', $now + ($this->case === 'clear-all-pending' || $trial ? 86400 : 3600));
        $this->scope = [
            'billing_customer_id' => $this->customer, 'legal_entity_id' => $this->entity, 'currency' => 'EUR',
            'collection_method' => $trial ? 'CHARGE_ON_CHECKOUT' : 'OUT_OF_BAND',
            'metadata' => $this->metadata(),
        ];
        if ($trial) {
            $this->scope['linked_payment_account_id'] = $this->collector;
        }
        $body = $this->scope + ['days_until_due' => 30, 'starts_at' => $start,
            'items' => [['price_id' => $this->price, 'quantity' => 1]]];
        if ($this->case === 'finite-ends') {
            $body['ends_at'] = gmdate('Y-m-d\TH:i:s\Z', $now + 3 * 86400);
        } else {
            $body['duration'] = match ($this->case) {
                'flag-one-day' => ['period' => 1, 'period_unit' => 'DAY'],
                'flag-seven-days', 'remaining-active-only', 'flag-restore-end'
                    => ['period' => 7, 'period_unit' => 'DAY'],
                default => ['period' => 1, 'period_unit' => 'MONTH'],
            };
        }
        if ($trial) {
            $body['trial_ends_at'] = gmdate('Y-m-d\TH:i:s\Z', $now + 2 * 86400);
        }
        $this->initialDuration = $body['duration'] ?? null;
        $created = $this->mutate(
            'create_subscription',
            'subscriptions',
            null,
            $body,
            fn (array $wire): array => $this->session->client->billing()->subscriptions()->create($wire)
        );
        $this->subscription = self::id($created);
        $this->ledger->recordCreated('subscriptions', $this->subscription);
        $pending = $this->readState('created_pending', 'PENDING');
        self::fields($pending, ['days_until_due' => 30]);
        if (!self::sameInstant($pending['starts_at'] ?? null, $start)) {
            throw new ProtocolException('The requested future start was not observed.');
        }
        if (isset($body['duration'])) {
            self::fields($pending, ['duration' => $body['duration']]);
        }
        if ($this->case === 'finite-ends') {
            if (!self::sameInstant($pending['ends_at'] ?? null, gmdate('Y-m-d\TH:i:s\Z', $now + 3 * 86400))) {
                throw new ProtocolException('The finite create end was not observed.');
            }
        }
        $this->initialItem();
        if ($this->case === 'clear-all-pending') {
            $this->clearPending($start);
        } elseif (str_starts_with($this->case, 'finite-')) {
            $this->finiteControl($now, $pending);
        } else {
            $this->update('activate', ['starts_at' => 'NOW']);
            if ($trial) {
                $observed = $this->readState('trial_observed', 'IN_TRIAL');
                if (!self::sameInstant($observed['trial_ends_at'] ?? null, $body['trial_ends_at'])) {
                    throw new ProtocolException('The requested trial end was not observed.');
                }
                $this->observations['trial_observed_without_payment_action'] = true;
            } else {
                $active = $this->readState('active_observed', 'ACTIVE');
                if ($this->case === 'remaining-active-only') {
                    $this->remainingActive($active);
                } elseif ($this->case === 'flag-restore-end') {
                    $this->restoreFlagEnd($active);
                } elseif (str_starts_with($this->case, 'flag-')) {
                    $this->flags($active);
                } else {
                    $this->cancel($this->case === 'cancel-all' ? 'ALL' : 'PRORATED', 'case_cancel');
                }
            }
        }
    }

    private function immediateTrial(int $now): void
    {
        $this->trialEnd = gmdate('Y-m-d\TH:i:s\Z', $now + 172800);
        $this->scope = ['billing_customer_id' => $this->customer, 'legal_entity_id' => $this->entity,
            'currency' => 'EUR', 'collection_method' => 'OUT_OF_BAND', 'metadata' => $this->metadata()];
        $body = $this->scope + ['days_until_due' => 30, 'duration' => ['period' => 1, 'period_unit' => 'MONTH'],
            'trial_ends_at' => $this->trialEnd, 'items' => [['price_id' => $this->price, 'quantity' => 1]]];
        $this->observations['trial_requested_without_starts_at'] = true;
        $created = $this->mutate(
            'create_subscription',
            'subscriptions',
            null,
            $body,
            fn (array $wire): array => $this->session->client->billing()->subscriptions()->create($wire)
        );
        $this->subscription = self::id($created);
        $this->ledger->recordCreated('subscriptions', $this->subscription);
        $observed = $this->readState('immediate_trial_observed', 'IN_TRIAL');
        $this->guardImmediateTrial($observed);
        $this->observations['trial_observed_without_payment_action'] = true;
        $this->cancelImmediateTrial();
    }

    /** @param array<array-key,mixed> $resource */
    private function guardImmediateTrial(array $resource): void
    {
        $this->ownSubscription($resource);
        self::fields($resource, ['status' => 'IN_TRIAL', 'days_until_due' => 30,
            'duration' => ['period' => 1, 'period_unit' => 'MONTH']]);
        $now = ($this->clock)();
        $start = $resource['starts_at'] ?? null;
        if (
            !is_string($start) || $this->trialEnd === null || !is_int($now) || $now < 0
            || !self::sameInstant($resource['trial_ends_at'] ?? null, $this->trialEnd)
            || self::compareInstants($start, $this->trialEnd) >= 0
            || self::seconds($this->trialEnd) <= $now
        ) {
            throw new ProtocolException('The owned immediate trial interval is not safe for cancellation.');
        }
        $this->observations['trial_end_matches_requested'] = true;
        $this->observations['trial_start_before_end_verified'] = true;
    }

    private function cancelImmediateTrial(): void
    {
        $this->phase = 'immediate_trial_cancel_guard';
        $subscriptions = $this->session->client->billing()->subscriptions();
        $this->guardImmediateTrial($subscriptions->retrieve($this->subscription));
        $this->initialItem();
        $this->guardImmediateTrial($subscriptions->retrieve($this->subscription));
        $this->observations['trial_cancel_before_end_guard'] = true;
        $this->cancel('NONE', 'cleanup_cancel');
    }

    private function initialItem(): void
    {
        $this->phase = 'initial_item';
        $delays = [1, 2, 4, 8, 8, 7];
        for ($attempt = 0; $attempt < $this->attempts; ++$attempt) {
            $page = $this->session->client->billing()->subscriptions()->listItems(
                $this->subscription,
                ['page_size' => 20],
            );
            $items = $page['items'] ?? null;
            if (
                !is_array($items) || !array_is_list($items) || count($items) > 1
                || ($page['page_after'] ?? null) !== null
            ) {
                throw new ProtocolException('The initial Item page is not a unique bounded result.');
            }
            if ($items !== []) {
                $item = $items[0];
                if (!is_array($item)) {
                    throw new ProtocolException('The initial Item is unavailable.');
                }
                self::fields($item, ['subscription_id' => $this->subscription,
                    'price' => ['id' => $this->price, 'currency' => 'EUR']]);
                $id = self::id($item);
                if (!ExactDecimal::equalsLiteral($item['quantity'] ?? null, '1')) {
                    throw new ProtocolException('The initial Item quantity did not match.');
                }
                $this->ledger->recordObservation(
                    'initial_item',
                    ['subscription_id' => $this->subscription,
                    'item_id' => $id, 'price_id' => $this->price],
                    self::contextFingerprint($this->session, $this->collector)
                );
                $this->observations['initial_items_page_complete'] = array_key_exists('page_after', $page)
                    && $page['page_after'] === null;
                $this->steps['initial_item'] = 'pass';
                return;
            }
            if ($attempt + 1 < $this->attempts) {
                ($this->wait)($delays[$attempt]);
            }
        }
        $this->observations['read_bound_exhausted'] = true;
        throw new RuntimeException('The initial Item was not observed in the read bound.');
    }

    /** @return array<string,string> */
    private function metadata(): array
    {
        return ['sdk_validation_run' => $this->ledger->runId(), 'sdk_validation_case' => $this->case];
    }

    private function catalogue(): void
    {
        $billing = $this->session->client->billing();
        $body = ['name' => $this->ledger->runId(), 'email' => $this->ledger->runId() . '@example.test',
            'default_billing_currency' => 'EUR', 'default_legal_entity_id' => $this->entity,
            'metadata' => $this->metadata()];
        $created = $this->mutate(
            'create_customer',
            'customers',
            null,
            $body,
            fn (array $wire): array => $billing->customers()->create($wire)
        );
        $this->customer = self::id($created);
        $this->ledger->recordCreated('customers', $this->customer);
        self::fields($billing->customers()->retrieve($this->customer), $body + ['id' => $this->customer]);
        $body = ['name' => $this->ledger->runId(), 'metadata' => $this->metadata(), 'active' => true];
        $created = $this->mutate(
            'create_product',
            'products',
            null,
            $body,
            fn (array $wire): array => $billing->products()->create($wire)
        );
        $this->product = self::id($created);
        $this->ledger->recordCreated('products', $this->product);
        $this->productFields = $body + ['id' => $this->product];
        unset($this->productFields['active']);
        self::fields($billing->products()->retrieve($this->product), $body + ['id' => $this->product]);
        $body = ['product_id' => $this->product, 'currency' => 'EUR', 'pricing_model' => 'PER_UNIT',
            'unit_amount' => '1.23', 'billing_type' => 'IN_ADVANCE', 'active' => true,
            'recurring' => ['period' => 1, 'period_unit' => in_array(
                $this->case,
                ['checkout-trial', 'immediate-oob-trial'],
                true
            ) ? 'MONTH' : 'DAY'],
            'metadata' => $this->metadata()];
        $created = $this->mutate(
            'create_price',
            'prices',
            null,
            $body,
            fn (array $wire): array => $billing->prices()->create($wire)
        );
        $this->price = self::id($created);
        $this->ledger->recordCreated('prices', $this->price);
        unset($body['unit_amount']);
        $this->priceFields = $body + ['id' => $this->price];
        unset($this->priceFields['active']);
        $price = $billing->prices()->retrieve($this->price);
        self::fields($price, $body + ['id' => $this->price]);
        if (!ExactDecimal::equalsLiteral($price['unit_amount'] ?? null, '1.23')) {
            throw new ProtocolException('The exact owned Price amount did not match.');
        }
        $this->steps['catalogue_verified'] = 'pass';
    }

    /** @param array<array-key,mixed> $active */
    private function flags(array $active): void
    {
        $this->recordSchedule('before_flag', $active);
        $this->update('schedule_true', ['cancel_at_period_end' => true]);
        $this->readState('flag_true_observed', 'ACTIVE', ['cancel_at_period_end' => true]);
        $this->observations['flag_true_observed'] = true;
        $this->update('schedule_false', ['cancel_at_period_end' => false]);
        $cleared = $this->readState('flag_false_observed', 'ACTIVE', ['cancel_at_period_end' => false]);
        $this->observations['flag_false_observed'] = true;
        $this->recordSchedule('after_flag', $cleared);
        $this->observations['indefinite_renewal_unverified'] = true;
    }

    /** @param array<array-key,mixed> $active */
    private function originalFiniteEnd(array $active): string
    {
        $this->phase = 'save_original_finite_end';
        self::fields($active, ['duration' => ['period' => 7, 'period_unit' => 'DAY']]);
        $end = $active['ends_at'] ?? null;
        $now = ($this->clock)();
        if (!is_string($end) || !is_int($now) || $now < 0 || $now > PHP_INT_MAX - 8 * 86400) {
            throw new ProtocolException('The fresh seven-day control has no safe visible future end.');
        }
        if (
            self::compareInstants($end, gmdate('Y-m-d\TH:i:s\Z', $now)) <= 0
            || self::compareInstants($end, gmdate('Y-m-d\TH:i:s\Z', $now + 8 * 86400)) > 0
        ) {
            throw new ProtocolException('The fresh seven-day control has no safe visible future end.');
        }
        $this->ledger->recordObservation('supplemental_original_end', [
            'subscription_id' => $this->subscription, 'ends_at' => $end,
        ], self::contextFingerprint($this->session, $this->collector));
        $this->observations['original_finite_end_saved'] = true;
        return $end;
    }

    /** @param array<array-key,mixed> $resource */
    private function boundedEnd(array $resource, string $original): bool
    {
        $end = $resource['ends_at'] ?? null;
        $now = ($this->clock)();
        return is_string($end) && is_int($now) && $now >= 0
            && self::compareInstants($end, gmdate('Y-m-d\TH:i:s\Z', $now)) > 0
            && self::compareInstants($end, $original) <= 0;
    }

    /** @param array<array-key,mixed> $active */
    private function remainingActive(array $active): void
    {
        $end = $this->originalFiniteEnd($active);
        $this->recordSchedule('before_active_remaining', $active);
        $this->update('update_remaining_active_only', [
            'remaining_duration' => ['period' => 1, 'period_unit' => 'DAY'],
        ]);
        $observed = $this->observe('active_remaining_finite_observed', fn (array $resource): bool
            => ($resource['status'] ?? null) === 'ACTIVE' && $this->boundedEnd($resource, $end));
        $this->recordSchedule('after_active_remaining', $observed);
        $this->observations['remaining_visible_finite_end'] = true;
        $this->observations['remaining_timing_unverified'] = true;
    }

    /** @param array<array-key,mixed> $active */
    private function restoreFlagEnd(array $active): void
    {
        $end = $this->originalFiniteEnd($active);
        $period = $active['current_period_ends_at'] ?? null;
        if (!is_string($period) || self::compareInstants($end, $period) <= 0) {
            throw new ProtocolException('The saved end must extend beyond the current daily billing period.');
        }
        $body = ['cancel_at_period_end' => false, 'ends_at' => $end];
        // Prepare the exact finite restoration before scheduling the earlier period-end cancellation.
        $this->phase = 'prepare_finite_restore';
        $this->reserve('restore_finite_end', 'subscription_update', $this->subscription, $body);
        $this->recordSchedule('before_restore', $active);
        $this->update('restore_schedule_true', ['cancel_at_period_end' => true]);
        $scheduled = $this->observe('restore_true_observed', fn (array $resource): bool
            => ($resource['status'] ?? null) === 'ACTIVE' && ($resource['cancel_at_period_end'] ?? null) === true
                && $this->boundedEnd($resource, $end));
        $this->recordSchedule('scheduled_restore', $scheduled);
        $this->update('restore_finite_end', $body);
        $restored = $this->observe('restored_flag_and_end', fn (array $resource): bool
            => ($resource['status'] ?? null) === 'ACTIVE' && ($resource['cancel_at_period_end'] ?? null) === false
                && $this->boundedEnd($resource, $end) && self::sameInstant($resource['ends_at'] ?? null, $end));
        $this->recordSchedule('after_restore', $restored);
        $this->observations['finite_flag_restoration_observed'] = true;
        $this->observations['indefinite_renewal_unverified'] = true;
    }

    /** @param array<array-key,mixed> $pending */
    private function finiteControl(int $now, array $pending): void
    {
        $this->recordSchedule('before_control', $pending);
        if ($this->case === 'finite-duration') {
            $duration = ['period' => 7, 'period_unit' => 'DAY'];
            $this->update('update_duration', ['duration' => $duration]);
            $observed = $this->readState('duration_observed', 'PENDING', ['duration' => $duration]);
        } elseif ($this->case === 'finite-ends') {
            $end = gmdate('Y-m-d\TH:i:s\Z', $now + 2 * 86400);
            $this->update('update_ends', ['ends_at' => $end]);
            $observed = $this->observe('finite_end_observed', function (array $resource) use ($end): bool {
                return ($resource['status'] ?? null) === 'PENDING'
                    && self::sameInstant($resource['ends_at'] ?? null, $end);
            });
        } else {
            $this->update('update_remaining', ['duration' => null, 'ends_at' => null,
                'remaining_duration' => ['period' => 1, 'period_unit' => 'DAY']]);
            // The response schema has no remaining_duration echo or exact mapping to another field.
            $observed = $this->readState('remaining_request_observed', 'PENDING');
            $this->observations['remaining_timing_unverified'] = true;
        }
        $this->recordSchedule('after_control', $observed);
    }

    private function clearPending(string $start): void
    {
        // Reserve the one cleanup identity before deliberately removing the lifetime bound.
        $this->reserve('cleanup_cancel', 'subscription_cancel', $this->subscription, ['proration_behavior' => 'NONE']);
        $guard = $this->readState('future_start_guard', 'PENDING');
        if (
            !self::sameInstant($guard['starts_at'] ?? null, $start)
            || self::seconds($start) <= ($this->clock)()
        ) {
            throw new ProtocolException('The future-start guard is not established.');
        }
        $this->observations['future_start_guard'] = true;
        $this->update('clear_all_schedule', ['duration' => null, 'remaining_duration' => null, 'ends_at' => null]);
        $observed = $this->observe('visible_schedule_unset', function (array $resource) use ($start): bool {
            return ($resource['status'] ?? null) === 'PENDING'
                && self::sameInstant($resource['starts_at'] ?? null, $start)
                && ($resource['duration'] ?? null) === null && ($resource['ends_at'] ?? null) === null;
        });
        $this->recordSchedule('cleared_control', $observed);
        $this->observations['visible_schedule_unset'] = true;
        $this->observations['indefinite_renewal_unverified'] = true;
        $this->cancel('NONE', 'cleanup_cancel');
    }

    /** @param array<array-key,mixed> $resource */
    private function recordSchedule(string $prefix, array $resource): void
    {
        $this->observations[$prefix . '_duration_matches_created'] = $this->initialDuration !== null
            && self::contains($resource['duration'] ?? null, $this->initialDuration);
        foreach (['duration', 'ends_at', 'current_period_starts_at', 'current_period_ends_at'] as $field) {
            $value = $resource[$field] ?? null;
            $this->observations[$prefix . '_' . $field] = !array_key_exists($field, $resource)
                ? 'missing' : ($value === null ? 'null' : 'set');
            if ($field !== 'duration' && $value !== null) {
                self::seconds($value);
            }
        }
        $end = $resource['ends_at'] ?? null;
        $period = $resource['current_period_ends_at'] ?? null;
        $available = is_string($end) && is_string($period);
        $this->observations[$prefix . '_end_comparison_available'] = $available;
        if ($available) {
            $this->observations[$prefix . '_end_after_current_period'] = self::compareInstants($end, $period) > 0;
            $this->observations[$prefix . '_end_matches_current_period'] = self::sameInstant($end, $period);
        }
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<array-key,mixed>
     */
    private function readState(string $step, string $state, array $extra = []): array
    {
        return $this->observe($step, static fn (array $resource): bool => self::contains(
            $resource,
            ['status' => $state] + $extra
        ));
    }

    /**
     * @param Closure(array<array-key,mixed>):bool $matches
     * @return array<array-key,mixed>
     */
    private function observe(string $step, Closure $matches): array
    {
        $this->phase = $step;
        $delays = [1, 2, 4, 8, 8, 7];
        for ($attempt = 0; $attempt < $this->attempts; ++$attempt) {
            $resource = $this->session->client->billing()->subscriptions()->retrieve($this->subscription);
            $this->ownSubscription($resource);
            $state = $resource['status'] ?? null;
            $this->observations['last_status'] = is_string($state)
                && in_array($state, ['PENDING', 'IN_TRIAL', 'ACTIVE', 'UNPAID', 'CANCELLED'], true)
                ? $state : 'UNKNOWN';
            if ($matches($resource)) {
                $this->steps[$step] = 'pass';
                return $resource;
            }
            if ($attempt + 1 < $this->attempts) {
                ($this->wait)($delays[$attempt]);
            }
        }
        $this->observations['read_bound_exhausted'] = true;
        throw new RuntimeException('The required observation is not available in the read bound.');
    }

    /** @param array<array-key,mixed> $resource */
    private function ownSubscription(array $resource): void
    {
        self::fields($resource, $this->scope + ['id' => $this->subscription]);
        $source = $resource['payment_source_id'] ?? null;
        if ($source !== null && $source !== '') {
            $this->observations['unexpected_payment_source_present'] = true;
            throw new ProtocolException('An unexpected payment instrument appeared on the fresh Subscription.');
        }
        if (
            ($resource['status'] ?? null) !== 'CANCELLED'
            && ($resource['duration'] ?? null) === null && ($resource['ends_at'] ?? null) === null
        ) {
            $this->capNotVisible = true;
            $this->observations['finite_cap_not_visible'] = true;
        }
    }

    /** @param array<string,mixed> $body */
    private function update(string $action, array $body): void
    {
        $this->mutate(
            $action,
            'subscription_update',
            $this->subscription,
            $body,
            fn (array $wire): array => $this->session->client->billing()->subscriptions()->update(
                $this->subscription,
                $wire,
            )
        );
    }

    private function cancel(string $mode, string $action): void
    {
        if ($this->cancelAttempted) {
            throw new RuntimeException('A cancellation must not be replayed.');
        }
        $this->mutate(
            $action,
            'subscription_cancel',
            $this->subscription,
            ['proration_behavior' => $mode],
            fn (array $wire): array => $this->session->client->billing()->subscriptions()->cancel(
                $this->subscription,
                $wire,
            )
        );
        $this->readState('cancelled_observed', 'CANCELLED');
        $this->cancelVerified = true;
    }

    /** @param array<string,mixed> $body */
    private function reserve(string $action, string $kind, ?string $id, array $body): string
    {
        return $this->ledger->requestId(
            $action,
            ['resource_kind' => $kind, 'resource_id' => $id, 'body' => $body],
            self::contextFingerprint($this->session, $this->collector)
        );
    }

    /**
     * @param array<string,mixed> $body
     * @param Closure(array<string,mixed>):array<array-key,mixed> $send
     * @return array<array-key,mixed>
     */
    private function mutate(string $action, string $kind, ?string $id, array $body, Closure $send): array
    {
        $this->phase = $action;
        $body['request_id'] = $this->reserve($action, $kind, $id, $body);
        if ($kind === 'subscription_cancel') {
            $this->cancelAttempted = true;
        }
        if ($action === 'clear_all_schedule') {
            $this->allNullSent = true;
        }
        try {
            $response = $send($body);
            $returned = self::id($response);
            if ($id !== null && $returned !== $id) {
                throw new ProtocolException('The mutation acknowledgement identity did not match.');
            }
            $this->steps[$action] = 'pass';
            return $response;
        } catch (\Throwable $error) {
            $this->ambiguous = true;
            throw $error;
        }
    }

    /** @return array<string,string> */
    private function cleanup(): array
    {
        if ($this->ambiguous) {
            return ['writes' => 'skipped_ambiguous'];
        }
        $result = [];
        try {
            $this->ledger->assertContext(self::contextFingerprint($this->session, $this->collector));
            if ($this->subscription !== '') {
                $this->phase = 'cleanup_subscription_read';
                $resource = $this->session->client->billing()->subscriptions()->retrieve($this->subscription);
                $this->ownSubscription($resource);
                if (($resource['status'] ?? null) === 'CANCELLED') {
                    $this->cancelVerified = true;
                } elseif ($this->cancelAttempted) {
                    throw new ProtocolException('An attempted cancellation is not yet confirmed.');
                } elseif ($this->case === 'immediate-oob-trial') {
                    $this->cancelImmediateTrial();
                } elseif (in_array($resource['status'] ?? null, ['PENDING', 'IN_TRIAL', 'ACTIVE', 'UNPAID'], true)) {
                    $this->cancel('NONE', 'cleanup_cancel');
                } else {
                    throw new ProtocolException('Cleanup cannot cancel an unknown Subscription state.');
                }
                $result['subscription'] = 'pass';
            }
            foreach (['prices' => $this->price, 'products' => $this->product] as $kind => $id) {
                if ($id === '') {
                    continue;
                }
                $resources = $kind === 'prices' ? $this->session->client->billing()->prices()
                    : $this->session->client->billing()->products();
                $this->phase = 'cleanup_' . $kind . '_read';
                $observed = $resources->retrieve($id);
                $expected = $kind === 'prices' ? $this->priceFields : $this->productFields;
                self::fields($observed, $expected);
                if ($kind === 'prices' && !ExactDecimal::equalsLiteral($observed['unit_amount'] ?? null, '1.23')) {
                    throw new ProtocolException('The owned cleanup Price amount changed.');
                }
                if (!is_bool($observed['active'] ?? null)) {
                    throw new ProtocolException('The catalogue state is not available.');
                }
                if ($observed['active']) {
                    $this->mutate(
                        'archive_' . $kind,
                        $kind,
                        $id,
                        ['active' => false],
                        static fn (array $wire): array => $resources->update($id, $wire)
                    );
                    $observed = $resources->retrieve($id);
                    self::fields($observed, $expected + ['active' => false]);
                    if ($kind === 'prices' && !ExactDecimal::equalsLiteral($observed['unit_amount'] ?? null, '1.23')) {
                        throw new ProtocolException('The archived Price amount changed.');
                    }
                }
                $result[$kind] = 'pass';
            }
        } catch (\Throwable) {
            $result['guard'] = 'fail';
        }
        return $result;
    }

    /**
     * @param array<array-key,mixed> $resource
     * @param array<string,mixed> $expected
     */
    private static function fields(array $resource, array $expected): void
    {
        if (!self::contains($resource, $expected)) {
            throw new ProtocolException('The returned resource does not match the owned case.');
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

    /** @param array<array-key,mixed> $resource */
    private static function id(array $resource): string
    {
        $id = $resource['id'] ?? null;
        if (
            !is_string($id) || trim($id) === '' || $id === '.' || $id === '..'
            || preg_match('/[\x00-\x1f\x7f]/', $id) === 1
        ) {
            throw new ProtocolException('A created resource identity is unavailable.');
        }
        return $id;
    }

    private static function seconds(mixed $value): int
    {
        if (
            !is_string($value) || preg_match(
                '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.[0-9]+)?(?:Z|[+-](\d{2}):?(\d{2}))$/D',
                $value,
                $parts,
            ) !== 1 || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59
            || (int) ($parts[7] ?? '0') > 23 || (int) ($parts[8] ?? '0') > 59
        ) {
            throw new ProtocolException('A schedule timestamp is unavailable.');
        }
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)
        ) {
            throw new ProtocolException('A schedule timestamp changed calendar or time values.');
        }
        return $date->getTimestamp();
    }

    private static function sameInstant(mixed $value, string $expected): bool
    {
        $seconds = self::seconds($value);
        preg_match('/\.([0-9]+)/', is_string($value) ? $value : '', $fraction);
        preg_match('/\.([0-9]+)/', $expected, $expectedFraction);
        return $seconds === self::seconds($expected)
            && rtrim($fraction[1] ?? '', '0') === rtrim($expectedFraction[1] ?? '', '0');
    }

    private static function compareInstants(string $left, string $right): int
    {
        $seconds = self::seconds($left) <=> self::seconds($right);
        if ($seconds !== 0) {
            return $seconds;
        }
        preg_match('/\.([0-9]+)/', $left, $leftFraction);
        preg_match('/\.([0-9]+)/', $right, $rightFraction);
        $a = $leftFraction[1] ?? '';
        $b = $rightFraction[1] ?? '';
        $length = max(strlen($a), strlen($b));
        return strcmp(str_pad($a, $length, '0'), str_pad($b, $length, '0'));
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['case' => $this->case, 'state' => '[private evidence references]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Private evidence references cannot be serialized.');
    }
}
