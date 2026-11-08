<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\TransportException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Bounded sandbox observations. This class never accepts pre-existing provider resource IDs. */
final class PaymentsLifecycle
{
    private const string AMOUNT = '1.23';
    private const string CURRENCY = 'HKD';
    private const string RETURN_URL = 'https://example.test/sdk-sandbox-return';
    private string $stage = 'initialization';
    private ?string $observedState = null;
    /** @var array<string,array<string,mixed>> */
    private array $intents = [];
    /** @var array<string,true> */
    private array $uncertainIntents = [];
    /** @var array<string,true> */
    private array $uncertainConsents = [];
    /** @var array<string,true> */
    private array $refundsRequested = [];
    /** @var array<string,true> */
    private array $ambiguousActions = [];

    private function __construct(
        private readonly SandboxSession $session,
        private readonly RunLedger $ledger,
        private readonly Closure $sleep,
        private readonly int $readAttempts,
    ) {
    }

    /** @return array<string,mixed> */
    public static function execute(
        SandboxSession $session,
        RunLedger $ledger,
        ?Closure $sleep = null,
        int $readAttempts = 7,
    ): array {
        if ($session->isOrganization()) {
            throw new RuntimeException('Payments validation requires explicit account context.');
        }
        $ledger->assertContext($session->contextFingerprint());
        if ($readAttempts < 1 || $readAttempts > 7) {
            throw new RuntimeException('Sandbox observation attempts must be bounded from one to seven.');
        }
        $journey = new self(
            $session,
            $ledger,
            $sleep ?? static fn (int $seconds) => sleep($seconds),
            $readAttempts,
        );
        $steps = [];
        foreach (
            [
            'unconfirmed_cancellation' => $journey->unconfirmed(...),
            'alipay_saved_consent' => $journey->alipaySaved(...),
            'alipay_one_time' => $journey->alipayOneTime(...),
            'card_capture_refund' => $journey->cardCapture(...),
            'card_authorization_cancel' => $journey->cardCancel(...),
            ] as $name => $run
        ) {
            $steps[$name] = $journey->observe($run);
        }
        $cleanup = $journey->cleanup();
        $outcomes = array_column($steps, 'result');
        $outcomes = [...$outcomes, ...array_values($cleanup)];
        $result = in_array('fail', $outcomes, true) ? 'fail'
            : (in_array('pending', $outcomes, true) ? 'pending'
                : (in_array('blocked', $outcomes, true) ? 'blocked' : 'pass'));
        return [
            'scenario' => 'payments-lifecycle', 'api_version' => '2026-08-21', 'result' => $result,
            'steps' => $steps, 'cleanup' => $cleanup, 'created_counts' => $ledger->counts(),
            'run_id' => $ledger->runId(), 'login_requests' => $session->loginRequests(),
            'ambiguous_mutations' => count($journey->ambiguousActions),
            'reconciliation_required' => $journey->ambiguousActions !== [] || in_array('pending', $outcomes, true)
                || array_filter($cleanup, static fn (string $state): bool => $state !== 'pass') !== [],
            'retained' => 'Customer, payment and refund history remain; no accepted deletion operation exists.',
            ...$ledger->evidence(),
        ];
    }

    /**
     * @param Closure():array{result:string,stage?:string,reason?:string,observed_state?:string} $run
     * @return array{result:string,stage?:string,reason?:string,observed_state?:string,http_status?:int}
     */
    private function observe(Closure $run): array
    {
        $this->observedState = null;
        try {
            return $run();
        } catch (ApiException $error) {
            return [
                'result' => $error->httpStatus() === 403 ? 'blocked' : 'fail',
                'stage' => $this->stage, 'http_status' => $error->httpStatus(),
            ];
        } catch (Throwable) {
            return ['result' => 'fail', 'stage' => $this->stage, 'reason' => 'local_protocol_or_transport'];
        }
    }

    /** @return array{result:string,stage?:string,reason?:string,observed_state?:string} */
    private function unconfirmed(): array
    {
        $customer = $this->customer('basic');
        $this->consent('unverified', $customer);
        $id = $this->intent('unconfirmed', $customer);
        $this->cancel('unconfirmed', $id);
        return ['result' => 'pass'];
    }

    /** @return array{result:string,stage?:string,reason?:string,observed_state?:string} */
    private function alipaySaved(): array
    {
        $customer = $this->customer('alipay');
        $consent = $this->consent('alipay', $customer);
        $this->stage = 'alipay_consent_verify';
        $verified = $this->mutate(
            'alipay_consent_verify',
            [
            'payment_method' => self::alipay(), 'return_url' => self::RETURN_URL,
            ],
            fn (array $body): array => $this->session->client->payments()->paymentConsents()->verify($consent, $body),
            expectedId: $consent,
            consent: $consent
        );
        $this->simulate('alipay_consent_verify', 'verify', $verified, consent: $consent);
        $ready = $this->poll(
            fn (): array => $this->session->client->payments()->paymentConsents()->retrieve($consent),
            $consent,
            ['VERIFIED'],
            ['customer_id' => $customer, 'next_triggered_by' => 'merchant', 'merchant_trigger_reason' => 'unscheduled'],
        );
        if ($ready === null) {
            return $this->pending();
        }
        $id = $this->intent('alipay_saved', $customer);
        $this->stage = 'alipay_saved_confirm';
        $confirmed = $this->mutate(
            'alipay_saved_confirm',
            ['payment_consent_id' => $consent],
            fn (array $body): array => $this->session->client->payments()->paymentIntents()->confirm($id, $body),
            expectedId: $id,
            intent: $id
        );
        $this->simulate('alipay_saved_pay', 'pay', $confirmed, intent: $id);
        return $this->refund('alipay_saved', $id);
    }

    /** @return array{result:string,stage?:string,reason?:string,observed_state?:string} */
    private function alipayOneTime(): array
    {
        $id = $this->intent('alipay_one_time');
        $this->stage = 'alipay_one_time_confirm';
        $confirmed = $this->mutate(
            'alipay_one_time_confirm',
            [
            'payment_method' => self::alipay(), 'return_url' => self::RETURN_URL,
            ],
            fn (array $body): array => $this->session->client->payments()->paymentIntents()->confirm($id, $body),
            expectedId: $id,
            intent: $id
        );
        $this->simulate('alipay_one_time_pay', 'pay', $confirmed, intent: $id);
        return $this->refund('alipay_one_time', $id);
    }

    /** @return array{result:string,stage?:string,reason?:string,observed_state?:string} */
    private function cardCapture(): array
    {
        $id = $this->authorize('card_capture');
        if ($id === null) {
            return $this->pending();
        }
        $this->stage = 'card_capture';
        $this->mutate(
            'card_capture',
            ['amount' => self::AMOUNT],
            fn (array $body): array => $this->session->client->payments()->paymentIntents()->capture($id, $body),
            expectedId: $id,
            intent: $id
        );
        return $this->refund('card_capture', $id);
    }

    /** @return array{result:string,stage?:string,reason?:string,observed_state?:string} */
    private function cardCancel(): array
    {
        $id = $this->authorize('card_cancel');
        if ($id === null) {
            return $this->pending();
        }
        $this->cancel('card_authorization', $id);
        return ['result' => 'pass'];
    }

    private function authorize(string $action): ?string
    {
        $id = $this->intent($action);
        $this->stage = $action . '_authorize';
        $this->mutate(
            $action . '_authorize',
            [
            // Official success-only sandbox PAN; no external card input is accepted.
            'payment_method' => ['type' => 'card', 'card' => [
                'number' => '4035501000000008', 'expiry_month' => '12', 'expiry_year' => '2030',
                'cvc' => '123', 'name' => 'SDK Sandbox Shopper',
            ]],
            'payment_method_options' => ['card' => ['auto_capture' => false]],
            'return_url' => self::RETURN_URL,
            ],
            fn (array $body): array => $this->session->client->payments()->paymentIntents()->confirm($id, $body),
            expectedId: $id,
            intent: $id
        );
        $intent = $this->pollIntent($id, ['REQUIRES_CAPTURE']);
        if ($intent === null) {
            // Do not guess browser challenges or an unexpected state into a successful authorization.
            return null;
        }
        $attempt = $intent['latest_payment_attempt'] ?? null;
        if (!is_array($attempt)) {
            throw new ProtocolException('Authorized sandbox payment did not identify its attempt.');
        }
        $attemptId = self::id($attempt);
        $this->stage = $action . '_attempt_retrieve';
        $observed = $this->session->client->payments()->paymentAttempts()->retrieve($attemptId);
        $this->uncertainIntents[$id] = true;
        self::same($observed, [
            'id' => $attemptId, 'payment_intent_id' => $id, 'status' => 'AUTHORIZED',
            'amount' => self::AMOUNT, 'currency' => self::CURRENCY,
        ]);
        unset($this->uncertainIntents[$id]);
        return $id;
    }

    private function customer(string $action): string
    {
        $this->stage = $action . '_customer_create_retrieve';
        $body = [
            'merchant_customer_id' => $this->ledger->runId() . '-' . $action,
            'first_name' => 'SDK', 'last_name' => 'Sandbox', 'email' => $this->ledger->runId() . '@example.test',
            'metadata' => ['sdk_validation_run' => $this->ledger->runId()],
        ];
        $created = $this->mutate(
            $action . '_customer',
            $body,
            fn (array $wire): array => $this->session->client->payments()->customers()->create($wire)
        );
        $id = self::id($created);
        $this->ledger->recordCreated('payment_customers', $id);
        self::same($this->session->client->payments()->customers()->retrieve($id), ['id' => $id, ...$body]);
        return $id;
    }

    private function consent(string $action, #[SensitiveParameter] string $customer): string
    {
        $this->stage = $action . '_consent_create_retrieve';
        $body = [
            'customer_id' => $customer, 'next_triggered_by' => 'merchant', 'merchant_trigger_reason' => 'unscheduled',
            'metadata' => ['sdk_validation_run' => $this->ledger->runId()],
        ];
        $created = $this->mutate(
            $action . '_consent',
            $body,
            fn (array $wire): array => $this->session->client->payments()->paymentConsents()->create($wire)
        );
        $id = self::id($created);
        $this->ledger->recordCreated('payment_consents', $id);
        self::same($this->session->client->payments()->paymentConsents()->retrieve($id), [
            'id' => $id, 'status' => 'REQUIRES_PAYMENT_METHOD', ...$body,
        ]);
        return $id;
    }

    private function intent(string $action, #[SensitiveParameter] ?string $customer = null): string
    {
        $this->stage = $action . '_intent_create_retrieve';
        $body = [
            'amount' => self::AMOUNT, 'currency' => self::CURRENCY,
            'merchant_order_id' => substr($this->ledger->runId(), -36) . '-' . $action,
            'metadata' => ['sdk_validation_run' => $this->ledger->runId()],
        ];
        if ($customer !== null) {
            $body['customer_id'] = $customer;
        }
        $created = $this->mutate(
            $action . '_intent',
            $body,
            fn (array $wire): array => $this->session->client->payments()->paymentIntents()->create($wire)
        );
        $id = self::id($created);
        $this->ledger->recordCreated('payment_intents', $id);
        $this->intents[$id] = $body;
        if ($this->pollIntent($id, ['REQUIRES_PAYMENT_METHOD']) === null) {
            throw new ProtocolException('New sandbox intent did not retain its unconfirmed state.');
        }
        return $id;
    }

    private function cancel(string $action, #[SensitiveParameter] string $id): void
    {
        $this->assertOwnIntent($id);
        $this->stage = $action . '_cancel';
        $this->mutate(
            $action . '_cancel',
            ['cancellation_reason' => 'SDK sandbox validation cleanup'],
            fn (array $wire): array => $this->session->client->payments()->paymentIntents()->cancel($id, $wire),
            expectedId: $id,
            intent: $id
        );
        if ($this->pollIntent($id, ['CANCELLED']) === null) {
            throw new ProtocolException('Sandbox cancellation could not be confirmed.');
        }
    }

    /** @return array{result:string,stage?:string,reason?:string,observed_state?:string} */
    private function refund(string $action, #[SensitiveParameter] string $id): array
    {
        $this->assertOwnIntent($id);
        $this->stage = $action . '_captured_readback';
        $captured = $this->pollIntent($id, ['SUCCEEDED'], requireCaptured: true);
        if ($captured === null) {
            return [
                ...$this->pending(), 'reason' => 'required_captured_amount_not_observed',
            ];
        }
        $this->stage = $action . '_refund_create_retrieve';
        $created = $this->mutate($action . '_refund', [
            'payment_intent_id' => $id, 'amount' => self::AMOUNT, 'reason' => 'SDK sandbox validation',
        ], fn (array $wire): array => $this->session->client->payments()->refunds()->create($wire), intent: $id);
        $refund = self::id($created);
        $this->ledger->recordCreated('payment_refunds', $refund);
        $this->refundsRequested[$id] = true;
        $this->uncertainIntents[$id] = true;
        $observed = $this->poll(
            fn (): array => $this->session->client->payments()->refunds()->retrieve($refund),
            $refund,
            ['ACCEPTED', 'SETTLED'],
            ['payment_intent_id' => $id, 'amount' => self::AMOUNT, 'currency' => self::CURRENCY],
        );
        if ($observed !== null) {
            unset($this->uncertainIntents[$id]);
        }
        return $observed === null ? $this->pending() : ['result' => 'pass'];
    }

    /** @param array<array-key,mixed> $resource */
    private function simulate(
        string $action,
        string $operation,
        #[SensitiveParameter] array $resource,
        #[SensitiveParameter] ?string $intent = null,
        #[SensitiveParameter] ?string $consent = null,
    ): void {
        $next = $resource['next_action'] ?? null;
        if ($next === null) {
            return; // A synchronous outcome is verified with Retrieve; no redirect is invented.
        }
        if (!is_array($next) || ($next['type'] ?? null) !== 'redirect' || !is_string($next['url'] ?? null)) {
            return; // Unknown/non-redirect actions remain pending when the required state cannot be read.
        }
        $this->stage = $action . '_simulation';
        $this->ledger->requestId(
            $action . '_simulation',
            ['url' => $next['url']],
            $this->session->contextFingerprint(),
        );
        try {
            $this->session->simulateShopperAction($operation, $next['url']);
        } catch (Throwable $error) {
            $this->uncertain($action, $error, $intent, $consent);
            throw $error;
        }
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
        #[SensitiveParameter] ?string $expectedId = null,
        #[SensitiveParameter] ?string $intent = null,
        #[SensitiveParameter] ?string $consent = null,
    ): array {
        $body['request_id'] = $this->ledger->requestId($action, $body, $this->session->contextFingerprint());
        try {
            $response = $send($body);
            $id = self::id($response);
            if ($expectedId !== null && $id !== $expectedId) {
                throw new ProtocolException('Sandbox mutation returned a different resource identity.');
            }
            return $response;
        } catch (Throwable $error) {
            $this->uncertain($action, $error, $intent, $consent);
            throw $error;
        }
    }

    private function uncertain(
        string $action,
        #[SensitiveParameter] Throwable $error,
        #[SensitiveParameter] ?string $intent,
        #[SensitiveParameter] ?string $consent,
    ): void {
        if (
            $error instanceof TransportException || $error instanceof ProtocolException
            || ($error instanceof ApiException && $error->httpStatus() >= 500)
        ) {
            $this->ambiguousActions[$action] = true;
            if ($intent !== null) {
                $this->uncertainIntents[$intent] = true;
            }
            if ($consent !== null) {
                $this->uncertainConsents[$consent] = true;
            }
        }
    }

    /**
     * @param list<string> $states
     * @return array<array-key,mixed>|null
     */
    private function pollIntent(
        #[SensitiveParameter] string $id,
        array $states,
        bool $requireCaptured = false,
    ): ?array {
        $this->assertOwnIntent($id);
        return $this->poll(
            fn (): array => $this->session->client->payments()->paymentIntents()->retrieve($id),
            $id,
            $states,
            $this->intents[$id],
            $requireCaptured ? static fn (array $observed): bool
                => ExactDecimal::equalsLiteral($observed['captured_amount'] ?? null, self::AMOUNT) : null,
        );
    }

    /**
     * @param Closure():array<array-key,mixed> $retrieve
     * @param list<string> $states
     * @param array<string,mixed> $expected
     * @param (Closure(array<array-key,mixed>):bool)|null $ready
     * @return array<array-key,mixed>|null
     */
    private function poll(
        Closure $retrieve,
        #[SensitiveParameter] string $id,
        array $states,
        array $expected,
        ?Closure $ready = null,
    ): ?array {
        for ($attempt = 0; $attempt < $this->readAttempts; ++$attempt) {
            $observed = $retrieve();
            self::same($observed, ['id' => $id, ...$expected]);
            $known = [
                'REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION', 'REQUIRES_CAPTURE',
                'PENDING', 'PENDING_REVIEW', 'SUCCEEDED', 'CANCELLED', 'VERIFIED', 'DISABLED',
                'PAUSED', 'RECEIVED', 'ACCEPTED', 'SETTLED', 'FAILED',
            ];
            $status = $observed['status'] ?? null;
            $this->observedState = is_string($status) && in_array($status, $known, true) ? $status : 'UNKNOWN';
            if (in_array($observed['status'] ?? null, $states, true) && ($ready === null || $ready($observed))) {
                return $observed;
            }
            if (($observed['status'] ?? null) === 'FAILED') {
                throw new ProtocolException('Sandbox resource reached a failed state.');
            }
            if ($attempt + 1 < $this->readAttempts) {
                ($this->sleep)([1, 2, 4, 8, 8, 7][$attempt]);
            }
        }
        return null;
    }

    /** @return array<string,string> */
    private function cleanup(): array
    {
        $result = [];
        foreach ($this->ledger->created('payment_intents') as $index => $id) {
            if (isset($this->uncertainIntents[$id])) {
                $result['intent_' . $index] = 'pending';
                continue;
            }
            $outcome = $this->observe(function () use ($index, $id): array {
                $this->stage = 'cleanup_intent';
                $observed = $this->session->client->payments()->paymentIntents()->retrieve($id);
                self::same($observed, ['id' => $id, ...$this->intents[$id]]);
                $status = $observed['status'] ?? null;
                $cancellable = ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION', 'REQUIRES_CAPTURE'];
                if (in_array($status, $cancellable, true)) {
                    $this->cancel('cleanup_' . $index, $id);
                    return ['result' => 'pass'];
                }
                $finished = $status === 'CANCELLED' || isset($this->refundsRequested[$id]);
                return ['result' => $finished ? 'pass' : 'pending'];
            });
            $result['intent_' . $index] = $outcome['result'] === 'blocked' ? 'fail' : $outcome['result'];
        }
        foreach ($this->ledger->created('payment_consents') as $index => $id) {
            if (isset($this->uncertainConsents[$id])) {
                $result['consent_' . $index] = 'pending';
                continue;
            }
            $outcome = $this->observe(function () use ($index, $id): array {
                $this->stage = 'cleanup_consent';
                $this->mutate(
                    'cleanup_consent_' . $index,
                    [],
                    function (array $body) use ($id): array {
                        return $this->session->client->payments()->paymentConsents()->disable($id, $body);
                    },
                    expectedId: $id,
                    consent: $id
                );
                $observed = $this->poll(
                    fn (): array => $this->session->client->payments()->paymentConsents()->retrieve($id),
                    $id,
                    ['DISABLED'],
                    [],
                );
                return $observed === null ? $this->pending() : ['result' => 'pass'];
            });
            $result['consent_' . $index] = $outcome['result'] === 'blocked' ? 'fail' : $outcome['result'];
        }
        return $result;
    }

    private function assertOwnIntent(#[SensitiveParameter] string $id): void
    {
        $this->ledger->assertContext($this->session->contextFingerprint());
        if (!isset($this->intents[$id]) || !in_array($id, $this->ledger->created('payment_intents'), true)) {
            throw new RuntimeException('Payments actions require an intent created by this run.');
        }
    }

    /** @return array{result:string,stage?:string,reason?:string,observed_state?:string} */
    private function pending(): array
    {
        return [
            'result' => 'pending', 'stage' => $this->stage, 'reason' => 'required_state_not_observed',
            'observed_state' => $this->observedState ?? 'UNAVAILABLE',
        ];
    }

    /** @return array<string,mixed> */
    private static function alipay(): array
    {
        return ['type' => 'alipayhk', 'alipayhk' => ['flow' => 'mobile_web', 'os_type' => 'ios']];
    }

    /** @param array<array-key,mixed> $resource */
    private static function id(#[SensitiveParameter] array $resource): string
    {
        $id = $resource['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new ProtocolException('Sandbox response did not identify its resource.');
        }
        return $id;
    }

    /**
     * @param array<array-key,mixed> $actual
     * @param array<array-key,mixed> $expected
     */
    private static function same(#[SensitiveParameter] array $actual, #[SensitiveParameter] array $expected): void
    {
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $actual)) {
                throw new ProtocolException('Sandbox readback omitted an expected field.');
            }
            if (in_array($field, ['amount'], true)) {
                if (!is_string($value) || !ExactDecimal::equalsLiteral($actual[$field], $value)) {
                    throw new ProtocolException('Sandbox readback changed an exact amount.');
                }
            } elseif (is_array($value) && is_array($actual[$field])) {
                self::same($actual[$field], $value);
            } elseif ($actual[$field] !== $value) {
                throw new ProtocolException('Sandbox readback differs from the requested values.');
            }
        }
    }
}
