<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use SensitiveParameter;
use stdClass;
use Throwable;

/** One fresh original-URL Android verification and conditional Consent-reference charge. */
final class AlipayConsentReuse
{
    private const ROOT = '/api/v1/pa/';
    private const AMOUNT = '1.23';
    private string $stage = 'preflight';
    private string $state = 'UNAVAILABLE';
    private ?string $customer = null;
    private ?string $consent = null;
    private ?string $intent = null;
    /** @var array<string,mixed> */
    private array $intentExpected = [];
    private bool $uncertain = false;
    private bool $unused = false;
    private bool $refunded = false;
    private bool $verified = false;
    private bool $methodObject = false;
    private bool $methodTypePresent = false;
    private bool $methodTypeMatches = false;
    private bool $methodIdPresent = false;
    private bool $methodIdString = false;
    private bool $methodIdUsable = false;
    private bool $simulation = false;
    private bool $customerAction = false;
    private bool $confirmActionPresent = false;
    private bool $charged = false;
    private string $refundState = 'UNAVAILABLE';
    /** @var Closure(int):void */
    private readonly Closure $sleep;

    /** @param (Closure(int):void)|null $sleep Fixture seam; no external timing option. */
    public function __construct(
        private readonly SandboxSession $session,
        private readonly RunLedger $ledger,
        private readonly AlipayConsentReuseTransport $transport,
        ?Closure $sleep = null,
        private readonly int $attempts = 7,
    ) {
        if ($session->isOrganization() || $attempts < 1 || $attempts > 7) {
            throw new ProtocolException('Consent reuse requires one bounded explicit-account case.');
        }
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        $result = 'pending';
        $reason = 'required_state_not_observed';
        try {
            $this->execute();
            $result = $this->refunded ? 'pass' : 'pending';
        } catch (Throwable) {
            $result = 'fail';
            $reason = 'bounded_case_failed';
            $this->uncertain = true;
        }
        $stage = $this->stage;
        $state = $this->state;
        $cleanup = $this->cleanup();
        return ['scenario' => 'alipay-hk-consent-reuse', 'profile' => 'ACCOUNT', 'api_version' => '2026-08-21',
            'result' => $result, 'stage' => $stage, 'reason' => $result === 'pass' ? 'verified' : $reason,
            'observed_state' => $state, 'consent_verified' => $this->verified,
            'simulation_acknowledged' => $this->simulation, 'method_object_present' => $this->methodObject,
            'method_type_present' => $this->methodTypePresent, 'method_type_matches' => $this->methodTypeMatches,
            'method_id_present' => $this->methodIdPresent, 'method_id_native_string' => $this->methodIdString,
            'method_id_usable' => $this->methodIdUsable, 'source_mapping_performed' => false,
            'customer_action_required' => $this->customerAction, 'captured_exact_amount' => $this->charged,
            'confirm_action_present' => $this->confirmActionPresent,
            'refund_verified' => $this->refunded, 'refund_state' => $this->refundState,
            'financial_settlement_verified' => false, 'cleanup' => $cleanup,
            'reconciliation_required' => $this->uncertain || !$this->transport->healthy()
                || in_array('pending', $cleanup, true) || in_array('fail', $cleanup, true),
            'inventory_counts' => $this->ledger->counts(), ...$this->ledger->evidence(),
            ...$this->transport->report()];
    }

    private function execute(): void
    {
        $customerBody = ['merchant_customer_id' => $this->ledger->runId(),
            'metadata' => ['sdk_validation_run' => $this->ledger->runId()]];
        $this->customer = $this->create('customer_create', 'customers', $customerBody);
        $this->read('customer_read', 'customers', $this->customer, $customerBody);
        $this->uncertain = false;
        $consentBody = ['customer_id' => $this->customer, 'next_triggered_by' => 'merchant',
            'merchant_trigger_reason' => 'unscheduled', 'metadata' => ['sdk_validation_run' => $this->ledger->runId()]];
        $this->consent = $this->create('consent_create', 'payment_consents', $consentBody);
        $initial = $this->readConsent();
        if (($initial['status'] ?? null) === 'VERIFIED') {
            // The already-verified branch still needs the complete same-run Method/type proof below.
            $this->uncertain = false;
        } else {
            if (($initial['status'] ?? null) !== 'REQUIRES_PAYMENT_METHOD') {
                return;
            }
            $verified = $this->mutate('consent_verify', self::ROOT . 'payment_consents/' . $this->consent . '/verify', [
                'payment_method' => ['type' => 'alipayhk',
                    'alipayhk' => ['flow' => 'mobile_web', 'os_type' => 'android']],
                'return_url' => 'https://example.com/',
            ], fn (array $body): array => $this->session->client->payments()->paymentConsents()
                ->verify($this->consentId(), $body));
            self::same($verified, ['id' => $this->consent, ...$consentBody]);
            if (($verified['status'] ?? null) !== 'VERIFIED') {
                if (
                    !in_array(
                        $verified['status'] ?? null,
                        ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION'],
                        true
                    )
                ) {
                    return;
                }
                $next = $verified['next_action'] ?? null;
                if (!is_array($next) || ($next['type'] ?? null) !== 'redirect' || !is_string($next['url'] ?? null)) {
                    return;
                }
                $url = $next['url']; // Original authenticated Verify URL; never followed or replaced.
                $fresh = $this->readConsent();
                if (($fresh['status'] ?? null) !== 'VERIFIED') {
                    if (
                        !in_array(
                            $fresh['status'] ?? null,
                            ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION'],
                            true
                        )
                    ) {
                        return;
                    }
                    if (isset($fresh['next_action']) && $fresh['next_action'] !== $next) {
                        throw new ProtocolException('The fresh Consent conflicts with its original redirect.');
                    }
                    $this->stage = 'consent_simulation';
                    $this->ledger->requestId('consent_simulation', ['url' => $url], $this->context());
                    $this->transport->arm(
                        'consent_simulation',
                        '/api/v1/simulation/pa/shopper_actions/verify',
                        ['url' => $url]
                    );
                    $this->uncertain = true;
                    $this->session->simulateShopperAction('verify', $url);
                    $this->simulation = true;
                }
            }
        }
        $ready = $this->poll(fn (): array => $this->readConsent(), ['VERIFIED']);
        if ($ready === null || !$this->method($ready)) {
            return;
        }
        $this->verified = true;
        $this->uncertain = false;
        $this->intentExpected = ['amount' => self::AMOUNT, 'currency' => 'HKD', 'customer_id' => $this->customer,
            'merchant_order_id' => substr($this->ledger->runId(), -36) . '-consent-reuse',
            'metadata' => ['sdk_validation_run' => $this->ledger->runId()]];
        $this->intent = $this->create('intent_create', 'payment_intents', $this->intentExpected);
        $new = $this->readIntent();
        if (($new['status'] ?? null) !== 'REQUIRES_PAYMENT_METHOD') {
            return;
        }
        self::amount($new, 'captured_amount', '0');
        $this->unused = true;
        $this->uncertain = false;
        if (!$this->method($this->readConsent())) {
            throw new ProtocolException('Consent reuse lost its freshly verified Method proof.');
        }
        $this->unused = false;
        $confirmed = $this->mutate(
            'intent_confirm',
            self::ROOT . 'payment_intents/' . $this->intent . '/confirm',
            ['payment_consent_id' => $this->consent, 'triggered_by' => 'merchant'],
            fn (array $body): array => $this->session->client->payments()->paymentIntents()
            ->confirm($this->intentId(), $body)
        );
        $this->intentProof($confirmed);
        $this->confirmActionPresent = array_key_exists('next_action', $confirmed) && $confirmed['next_action'] !== null;
        $this->customerAction = ($confirmed['status'] ?? null) === 'REQUIRES_CUSTOMER_ACTION';
        if ($this->customerAction || $this->confirmActionPresent) {
            if (in_array($confirmed['status'] ?? null, ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION'], true)) {
                $fresh = $this->readIntent();
                if (in_array($fresh['status'] ?? null, ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION'], true)) {
                    self::amount($fresh, 'captured_amount', '0');
                    $this->unused = true;
                    $this->uncertain = false;
                }
            }
            return; // No PAY, browser, capability GET or substitute capture.
        }
        $captured = $this->poll(fn (): array => $this->readIntent(), ['SUCCEEDED']);
        if ($captured === null) {
            return;
        }
        self::amount($captured, 'captured_amount', self::AMOUNT);
        $this->charged = true;
        $this->uncertain = false;
        $refund = $this->create('refund_create', 'refunds', ['payment_intent_id' => $this->intent,
            'amount' => self::AMOUNT, 'reason' => 'SDK sandbox validation']);
        $proof = $this->poll(
            fn (): array => $this->read(
                'refund_read',
                'refunds',
                $refund,
                ['payment_intent_id' => $this->intent, 'amount' => self::AMOUNT, 'currency' => 'HKD']
            ),
            ['ACCEPTED', 'SETTLED']
        );
        if ($proof !== null) {
            $this->refundState = $proof['status'] === 'SETTLED' ? 'SETTLED' : 'ACCEPTED';
            $this->refunded = true;
            $this->uncertain = false;
        }
    }

    /** @param array<string,mixed> $body */
    private function create(string $action, string $kind, array $body): string
    {
        $resource = $this->mutate(
            $action,
            self::ROOT . $kind . '/create',
            $body,
            function (array $wire) use ($kind): array {
                $payments = $this->session->client->payments();
                return match ($kind) {
                    'customers' => $payments->customers()->create($wire),
                    'payment_consents' => $payments->paymentConsents()->create($wire),
                    'payment_intents' => $payments->paymentIntents()->create($wire),
                    'refunds' => $payments->refunds()->create($wire),
                    default => throw new ProtocolException('Unknown Consent creation role.'),
                };
            }
        );
        $id = AlipayConsentReuseTransport::identity($resource['id'] ?? null);
        $this->ledger->recordCreated(match ($kind) {
            'customers' => 'payment_customers', 'refunds' => 'payment_refunds', default => $kind,
        }, $id);
        $this->transport->register($kind, $id);
        return $id;
    }

    /** @param array<string,mixed> $body
     * @param Closure(array<string,mixed>):array<array-key,mixed> $send
     * @return array<array-key,mixed>
     */
    private function mutate(string $action, string $path, #[SensitiveParameter] array $body, Closure $send): array
    {
        $this->stage = $action;
        $body['request_id'] = $this->ledger->requestId(
            $action,
            ['path' => $path, 'payload' => $body],
            $this->context()
        );
        $this->transport->arm($action, $path, $body);
        $this->uncertain = true;
        return $send($body);
    }

    /** @param array<string,mixed> $expected
     * @return array<array-key,mixed>
     */
    private function read(string $stage, string $kind, string $id, array $expected): array
    {
        $this->stage = $stage;
        $payments = $this->session->client->payments();
        $data = match ($kind) {
            'customers' => $payments->customers()->retrieve($id),
            'payment_consents' => $payments->paymentConsents()->retrieve($id),
            'payment_intents' => $payments->paymentIntents()->retrieve($id),
            'refunds' => $payments->refunds()->retrieve($id),
            default => throw new ProtocolException('Unknown Consent read role.'),
        };
        $this->transport->native(self::ROOT . $kind . '/' . $id);
        self::same($data, ['id' => $id, ...$expected]);
        $known = ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION', 'PENDING', 'PENDING_REVIEW',
            'REQUIRES_CAPTURE', 'SUCCEEDED', 'CANCELLED', 'VERIFIED', 'DISABLED', 'RECEIVED', 'ACCEPTED',
            'SETTLED', 'FAILED'];
        $status = $data['status'] ?? null;
        $this->state = is_string($status) && in_array($status, $known, true) ? $status : 'UNKNOWN';
        return $data;
    }

    /** @return array<array-key,mixed> */
    private function readConsent(): array
    {
        return $this->read(
            'consent_read',
            'payment_consents',
            $this->consentId(),
            ['customer_id' => $this->customer, 'next_triggered_by' => 'merchant',
            'merchant_trigger_reason' => 'unscheduled',
            'metadata' => ['sdk_validation_run' => $this->ledger->runId()]]
        );
    }

    /** @return array<array-key,mixed> */
    private function readIntent(): array
    {
        $data = $this->read('intent_read', 'payment_intents', $this->intentId(), $this->intentExpected);
        $this->intentProof($data);
        return $data;
    }

    /** @param array<array-key,mixed> $data */
    private function intentProof(array $data): void
    {
        self::same($data, ['id' => $this->intent, ...$this->intentExpected]);
        if (array_key_exists('captured_amount', $data)) {
            if (($data['status'] ?? null) === 'SUCCEEDED') {
                self::amount($data, 'captured_amount', self::AMOUNT);
            } elseif (
                in_array(
                    $data['status'] ?? null,
                    ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION', 'CANCELLED'],
                    true
                )
            ) {
                self::amount($data, 'captured_amount', '0');
            }
        }
        if (array_key_exists('payment_consent_id', $data) && $data['payment_consent_id'] !== $this->consent) {
            throw new ProtocolException('The Intent reports a conflicting Consent linkage.');
        }
        if (isset($data['payment_consent'])) {
            if (!is_array($data['payment_consent']) || ($data['payment_consent']['id'] ?? null) !== $this->consent) {
                throw new ProtocolException('The Intent reports an unverified embedded Consent linkage.');
            }
        }
        if (isset($data['latest_payment_attempt'])) {
            $attempt = $data['latest_payment_attempt'];
            if (!is_array($attempt)) {
                throw new ProtocolException('The embedded payment attempt proof is malformed.');
            }
            AlipayConsentReuseTransport::identity($attempt['id'] ?? null);
            foreach (
                ['payment_intent_id' => $this->intent, 'payment_consent_id' => $this->consent,
                'merchant_order_id' => $this->intentExpected['merchant_order_id'], 'currency' => 'HKD',
                'amount' => self::AMOUNT] as $key => $expected
            ) {
                if (array_key_exists($key, $attempt)) {
                    self::same($attempt, [$key => $expected]);
                }
            }
            if (array_key_exists('captured_amount', $attempt)) {
                if (
                    !is_string($data['captured_amount'] ?? null)
                    || !ExactDecimal::equalsLiteral($attempt['captured_amount'], $data['captured_amount'])
                ) {
                    throw new ProtocolException('The embedded attempt contradicts the captured amount.');
                }
            }
        }
    }

    /** @param array<array-key,mixed> $data */
    private function method(array $data): bool
    {
        $native = $this->transport->native(self::ROOT . 'payment_consents/' . $this->consentId());
        $this->methodObject = isset($native->payment_method) && $native->payment_method instanceof stdClass;
        $method = $this->methodObject ? $native->payment_method : null;
        $this->methodTypePresent = $method instanceof stdClass && property_exists($method, 'type');
        $this->methodTypeMatches = $method instanceof stdClass && ($method->type ?? null) === 'alipayhk';
        $this->methodIdPresent = $method instanceof stdClass && property_exists($method, 'id');
        $this->methodIdString = $method instanceof stdClass && is_string($method->id ?? null);
        $this->methodIdUsable = false;
        if ($this->methodIdString && $method instanceof stdClass) {
            try {
                AlipayConsentReuseTransport::identity($method->id);
                $this->methodIdUsable = true;
            } catch (Throwable) {
                // This optional reference never authorizes Method/Source calls in this case.
            }
        }
        return ($data['status'] ?? null) === 'VERIFIED' && $this->methodTypeMatches;
    }

    /** @param Closure():array<array-key,mixed> $read
     * @param list<string> $states
     * @return array<array-key,mixed>|null
     */
    private function poll(Closure $read, array $states): ?array
    {
        for ($i = 0; $i < $this->attempts; ++$i) {
            $resource = $read();
            if (in_array($resource['status'] ?? null, $states, true)) {
                return $resource;
            }
            if (
                !in_array($resource['status'] ?? null, ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION',
                'PENDING', 'PENDING_REVIEW', 'RECEIVED'], true)
            ) {
                return null;
            }
            if ($i + 1 < $this->attempts) {
                $seconds = [1, 2, 4, 8, 8, 7][$i];
                if ($this->transport->remaining() <= $seconds) {
                    return null;
                }
                ($this->sleep)($seconds);
            }
        }
        return null;
    }

    /** @return array{intent:string,consent:string,customer:string} */
    private function cleanup(): array
    {
        $result = ['intent' => $this->intent === null ? 'not_recorded' : 'pending',
            'consent' => $this->consent === null ? 'not_recorded' : 'pending',
            'customer' => $this->customer === null ? 'not_recorded' : 'retained'];
        if ($this->uncertain || !$this->transport->healthy() || (!$this->refunded && !$this->unused)) {
            return $result;
        }
        try {
            $this->transport->beginCleanup();
            if (!$this->refunded) {
                $fresh = $this->readIntent();
                self::amount($fresh, 'captured_amount', '0');
                if (($fresh['status'] ?? null) !== 'CANCELLED') {
                    if (
                        !in_array(
                            $fresh['status'] ?? null,
                            ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION'],
                            true
                        )
                    ) {
                        return $result;
                    }
                    $cancelled = $this->mutate('intent_cancel', self::ROOT . 'payment_intents/' . $this->intentId()
                        . '/cancel', [], fn (array $wire): array => $this->session->client->payments()
                        ->paymentIntents()->cancel($this->intentId(), $wire));
                    $this->intentProof($cancelled);
                    $done = $this->poll(fn (): array => $this->readIntent(), ['CANCELLED']);
                    if ($done === null) {
                        return $result;
                    }
                    self::amount($done, 'captured_amount', '0');
                    $this->uncertain = false;
                }
            }
            $result['intent'] = 'pass';
            $consent = $this->readConsent();
            if (($consent['status'] ?? null) === 'DISABLED') {
                $result['consent'] = 'pass';
                return $result;
            }
            if (!$this->method($consent)) {
                return $result;
            }
            $disabled = $this->mutate('consent_disable', self::ROOT . 'payment_consents/' . $this->consentId()
                . '/disable', [], fn (array $wire): array => $this->session->client->payments()
                ->paymentConsents()->disable($this->consentId(), $wire));
            self::same($disabled, ['id' => $this->consent, 'customer_id' => $this->customer]);
            if ($this->poll(fn (): array => $this->readConsent(), ['DISABLED']) !== null) {
                $this->uncertain = false;
                $result['consent'] = 'pass';
            }
        } catch (Throwable) {
            $this->uncertain = true;
            $result['consent'] = 'fail';
        }
        return $result;
    }

    private function context(): string
    {
        return $this->session->contextFingerprint();
    }

    private function consentId(): string
    {
        return $this->consent ?? throw new ProtocolException('An owned Consent is unavailable.');
    }

    private function intentId(): string
    {
        return $this->intent ?? throw new ProtocolException('An owned Intent is unavailable.');
    }

    /** @param array<array-key,mixed> $actual
     * @param array<array-key,mixed> $expected
     */
    private static function same(#[SensitiveParameter] array $actual, #[SensitiveParameter] array $expected): void
    {
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)) {
                throw new ProtocolException('The owned readback omitted an expected field.');
            }
            if ($key === 'amount' && is_string($value)) {
                self::amount($actual, 'amount', $value);
            } elseif (is_array($value) && is_array($actual[$key])) {
                self::same($actual[$key], $value);
            } elseif ($actual[$key] !== $value) {
                throw new ProtocolException('The owned readback changed a required field.');
            }
        }
    }

    /** @param array<array-key,mixed> $resource */
    private static function amount(array $resource, string $field, string $expected): void
    {
        if (!ExactDecimal::equalsLiteral($resource[$field] ?? null, $expected)) {
            throw new ProtocolException('The exact owned monetary proof is unavailable.');
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['case' => '[private]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Consent case state cannot be serialized.');
    }
}
