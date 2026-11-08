<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Fresh guest Klarna manual capture experiment; a sent unfinished action is never replaced. */
final class KlarnaManualCapture
{
    private ?string $intent = null;
    private bool $used = false;
    private bool $uncertain = false;
    private bool $cleanCancelled = false;
    private string $stage = 'configuration';
    private string $state = 'UNAVAILABLE';
    /** @var array<string,mixed> */
    private array $expected = [];
    /** @var array<string,bool|int|string> */
    private array $safe = ['result' => 'blocked', 'reason' => 'not_started', 'simulation_attempted' => false,
        'simulation_acknowledged' => false, 'authorization_verified' => false, 'capture_sent' => false,
        'capture_verified' => false, 'refund_sent' => false, 'refund_accepted' => false,
        'authorized_cancel_sent' => false, 'authorized_cancel_verified' => false,
        'redirect_attempted' => false, 'reconciliation_required' => false];
    /** @var Closure(int):void */
    private readonly Closure $sleep;

    /** @param (Closure(int):void)|null $sleep */
    public function __construct(
        private readonly SandboxSession $session,
        private readonly RunLedger $ledger,
        private readonly ?ClientInterface $redirectHttp = null,
        ?Closure $sleep = null,
        private readonly int $readAttempts = 7,
    ) {
        if ($session->isOrganization() || $readAttempts < 1 || $readAttempts > 7) {
            throw new RuntimeException('Klarna observations require account context and bounded reads.');
        }
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /** @return array<string,mixed> */
    public function run(string $case): array
    {
        if (
            $this->used || !in_array($case, ['capture-refund', 'authorized-cancel'], true)
            || $this->ledger->created('payment_intents') !== []
        ) {
            return ['result' => 'fail', 'stage' => 'fresh_run_required'];
        }
        $this->used = true;
        $this->safe['case'] = $case;
        try {
            $this->create();
            $id = $this->ownId();
            $this->stage = 'confirm';
            $confirmed = $this->mutate(
                'klarna_confirm',
                [
                'payment_method' => ['type' => 'klarna', 'klarna' => ['country_code' => 'DE', 'language' => 'en']],
                'payment_method_options' => ['klarna' => ['auto_capture' => false]],
                ],
                fn (array $body): array => $this->session->client->payments()->paymentIntents()->confirm($id, $body),
                $id
            );
            $observed = $this->readOwn();
            if (!in_array($observed['status'] ?? null, ['REQUIRES_CUSTOMER_ACTION', 'REQUIRES_CAPTURE'], true)) {
                throw new ProtocolException('The confirmed Intent is outside this manual-capture experiment.');
            }
            $this->zero($observed);
            $this->uncertain = false;
            if ($observed['status'] === 'REQUIRES_CUSTOMER_ACTION') {
                $this->redirect($confirmed);
                if (!$this->safe['simulation_acknowledged']) {
                    if ($this->safe['result'] !== 'fail') {
                        $this->safe['reason'] = 'canonical_simulation_target_unavailable';
                    }
                    return $this->finish();
                }
            }
            if (!$this->poll('REQUIRES_CAPTURE', '0')) {
                $this->uncertain = true;
                $this->safe['reason'] = 'authorization_pending';
                return $this->finish();
            }
            $this->safe['authorization_verified'] = true;
            $this->uncertain = false;
            $this->ledger->recordObservation(
                'klarna_authorized',
                ['intent_id' => $id,
                'status' => 'REQUIRES_CAPTURE', 'amount' => '1.23', 'currency' => 'EUR', 'captured_amount' => '0'],
                $this->session->contextFingerprint()
            );
            $this->requireState('REQUIRES_CAPTURE', '0'); // Fresh proof immediately before disposition.
            if ($case === 'authorized-cancel') {
                $this->stage = 'authorized_cancel';
                $this->mutate(
                    'klarna_authorized_cancel',
                    ['cancellation_reason' => 'SDK sandbox authorization test'],
                    fn (array $body): array => $this->session->client->payments()->paymentIntents()->cancel($id, $body),
                    $id
                );
                $this->safe['authorized_cancel_verified'] = $this->poll('CANCELLED', '0');
                if ($this->safe['authorized_cancel_verified']) {
                    $this->uncertain = false;
                    $this->safe['result'] = 'pass';
                    $this->safe['reason'] = 'authorized_cancellation_verified';
                } else {
                    $this->safe['reason'] = 'authorized_cancellation_pending';
                }
            } else {
                $this->stage = 'capture';
                $this->mutate(
                    'klarna_capture',
                    ['amount' => '1.23'],
                    fn (array $body): array => $this->session->client->payments()
                        ->paymentIntents()->capture($id, $body),
                    $id
                );
                $this->safe['capture_verified'] = $this->poll('SUCCEEDED', '1.23');
                if (!$this->safe['capture_verified']) {
                    $this->safe['reason'] = 'capture_pending';
                    return $this->finish();
                }
                $this->uncertain = false;
                $this->requireState('SUCCEEDED', '1.23');
                $this->refund();
            }
        } catch (ApiException $error) {
            $this->safe['result'] = 'fail';
            $this->safe['reason'] = 'api_request_rejected';
            $this->safe['http_status'] = $error->httpStatus();
            $code = $error->providerCode();
            $this->safe['public_code'] = in_array($code, ['validation_error', 'payment_method_not_allowed',
                'operation_not_supported', 'internal_error', 'invalid_status_for_operation',
            ], true) ? $code : 'unlisted';
        } catch (Throwable) {
            $this->safe['result'] = 'fail';
            $this->safe['reason'] = 'local_protocol_or_transport';
        }
        return $this->finish();
    }

    private function create(): void
    {
        $this->stage = 'create';
        $this->expected = ['amount' => '1.23', 'currency' => 'EUR',
            'merchant_order_id' => substr($this->ledger->runId(), -36) . '-klarna',
            'metadata' => ['sdk_validation_run' => $this->ledger->runId()],
            'return_url' => 'https://example.invalid/sdk-sandbox-klarna-return',
            'order' => ['products' => [['name' => 'SDK sandbox test product',
                'quantity' => 1, 'unit_price' => '1.23']]]];
        $created = $this->mutate(
            'klarna_create',
            $this->expected,
            fn (array $body): array => $this->session->client->payments()->paymentIntents()->create($body)
        );
        $this->intent = self::id($created);
        $this->ledger->recordCreated('payment_intents', $this->intent);
        $this->requireState('REQUIRES_PAYMENT_METHOD', '0');
        $this->uncertain = false;
    }

    /** @param array<array-key,mixed> $confirmed */
    private function redirect(#[SensitiveParameter] array $confirmed): void
    {
        $next = $confirmed['next_action'] ?? null;
        if (
            !is_array($next) || ($next['type'] ?? null) !== 'redirect' || ($next['method'] ?? null) !== 'GET'
            || !is_string($next['url'] ?? null)
        ) {
            return;
        }
        $url = $next['url'];
        $this->stage = 'redirect';
        $transport = new KlarnaTransport($this->redirectHttp, $url);
        $this->ledger->requestId(
            'klarna_redirect_read',
            ['method' => 'GET', 'url_sha256' => hash('sha256', $url)],
            $this->session->contextFingerprint()
        );
        $this->safe['redirect_attempted'] = true;
        $this->uncertain = true; // A sent customer GET can have an outcome even when its response is unreadable.
        $navigation = (new AlipayRedirect($transport))->inspect($url, withVerifiedMock: function (
            #[SensitiveParameter] string $mock,
        ): void {
            $this->stage = 'simulation_preflight';
            $this->requireState('REQUIRES_CUSTOMER_ACTION', '0');
            $body = ['url' => $mock];
            $fingerprint = $this->session->contextFingerprint();
            $this->ledger->requestId('klarna_simulation', $body, $fingerprint);
            $this->ledger->recordObservation('klarna_simulation_target', ['intent_id' => $this->ownId(),
                'target_sha256' => hash('sha256', $mock), 'method' => 'POST',
                'path' => '/api/v1/simulation/pa/shopper_actions/pay'], $fingerprint);
            $this->stage = 'simulation';
            $this->safe['simulation_attempted'] = true;
            try {
                $this->session->simulateShopperAction('pay', $mock);
            } catch (ApiException $error) {
                $this->safe['simulation_http_status'] = $error->httpStatus();
                $code = $error->providerCode();
                $this->safe['simulation_public_code'] = in_array($code, ['validation_error',
                    'operation_not_supported', 'internal_error'], true) ? $code : 'unlisted';
                throw $error;
            }
            $this->ledger->recordObservation(
                'klarna_simulation_ack',
                ['http_status' => '200', 'body' => 'empty'],
                $fingerprint
            );
            $this->safe['simulation_acknowledged'] = true;
        });
        if (!is_int($navigation['http_status']) || !is_bool($navigation['mock_action_invoked'])) {
            throw new ProtocolException('The redirect observation has an invalid fixed shape.');
        }
        $this->safe['redirect_http_status'] = $navigation['http_status'];
        $this->safe['canonical_target_returned'] = $navigation['mock_action_invoked'];
        if ($navigation['http_status'] >= 400) {
            $this->safe['result'] = 'fail';
            $this->safe['reason'] = 'customer_redirect_rejected';
        }
        if ($navigation['mock_action_callback_failed'] === true) {
            throw new ProtocolException('The once-only simulation callback did not complete.');
        }
        if ($navigation['http_status'] < 400 && !$this->safe['simulation_attempted']) {
            $this->uncertain = false; // Only a complete read without a financial simulation was observed.
        }
    }

    private function refund(): void
    {
        $this->stage = 'refund';
        $id = $this->ownId();
        $created = $this->mutate(
            'klarna_refund',
            ['payment_intent_id' => $id, 'amount' => '1.23',
            'reason' => 'SDK sandbox exact Klarna refund'],
            fn (array $body): array => $this->session->client->payments()->refunds()->create($body)
        );
        $refund = self::id($created);
        $this->ledger->recordCreated('payment_refunds', $refund);
        for ($index = 0; $index < $this->readAttempts; ++$index) {
            $observed = $this->session->client->payments()->refunds()->retrieve($refund);
            self::same($observed, ['id' => $refund, 'payment_intent_id' => $id,
                'amount' => '1.23', 'currency' => 'EUR']);
            if (in_array($observed['status'] ?? null, ['ACCEPTED', 'SETTLED'], true)) {
                $this->ledger->recordObservation('klarna_refund_accepted', ['refund_id' => $refund,
                    'intent_id' => $id, 'status' => $observed['status']], $this->session->contextFingerprint());
                $this->safe['refund_accepted'] = true;
                $this->safe['result'] = 'pass';
                $this->safe['reason'] = 'manual_capture_and_refund_accepted';
                $this->uncertain = false;
                return;
            }
            if (($observed['status'] ?? null) !== 'RECEIVED') {
                throw new ProtocolException('The Refund state is outside the bounded acceptance observation.');
            }
            $this->pause($index);
        }
        $this->safe['reason'] = 'refund_pending';
    }

    /** @return array<array-key,mixed> */
    private function readOwn(): array
    {
        try {
            $observed = $this->session->client->payments()->paymentIntents()->retrieve($this->ownId());
            self::same($observed, ['id' => $this->ownId(), ...$this->expected]);
            $state = $observed['status'] ?? null;
            $known = ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION', 'REQUIRES_CAPTURE', 'PENDING',
                'PENDING_REVIEW', 'SUCCEEDED', 'CANCELLED'];
            if (!is_string($state) || !in_array($state, $known, true)) {
                throw new ProtocolException('Own Intent state is unknown.');
            }
            $this->state = $state;
            return $observed;
        } catch (Throwable $error) {
            $this->uncertain = true;
            throw $error;
        }
    }

    /** @return array<array-key,mixed> */
    private function requireState(string $state, string $captured): array
    {
        $observed = $this->readOwn();
        if (
            ($observed['status'] ?? null) !== $state
            || !ExactDecimal::equalsLiteral($observed['captured_amount'] ?? null, $captured)
        ) {
            $this->uncertain = true;
            throw new ProtocolException('The own Intent state or observed captured amount does not match.');
        }
        return $observed;
    }

    /** @param array<array-key,mixed> $observed */
    private function zero(#[SensitiveParameter] array $observed): void
    {
        if (!ExactDecimal::equalsLiteral($observed['captured_amount'] ?? null, '0')) {
            $this->uncertain = true;
            throw new ProtocolException('The manual authorization requires observed zero captured amount.');
        }
    }

    private function poll(string $state, string $captured): bool
    {
        for ($index = 0; $index < $this->readAttempts; ++$index) {
            $observed = $this->readOwn();
            if (($observed['status'] ?? null) === $state) {
                if (!ExactDecimal::equalsLiteral($observed['captured_amount'] ?? null, $captured)) {
                    $this->uncertain = true;
                    throw new ProtocolException('The observed captured amount does not match the action.');
                }
                return true;
            }
            $this->pause($index);
        }
        return false;
    }

    private function pause(int $index): void
    {
        if ($index + 1 < $this->readAttempts) {
            ($this->sleep)([1, 2, 4, 8, 8, 7][$index]);
        }
    }

    /** @param array<string,mixed> $body
     * @param Closure(array<string,mixed>):array<array-key,mixed> $send
     * @return array<array-key,mixed>
     */
    private function mutate(string $action, #[SensitiveParameter] array $body, Closure $send, ?string $id = null): array
    {
        if ($id !== null) {
            $this->ledger->recordObservation(
                $action . '_target',
                ['intent_id' => $id],
                $this->session->contextFingerprint()
            );
        }
        $body['request_id'] = $this->ledger->requestId($action, $body, $this->session->contextFingerprint());
        $this->uncertain = true;
        foreach (
            ['klarna_capture' => 'capture_sent', 'klarna_refund' => 'refund_sent',
            'klarna_authorized_cancel' => 'authorized_cancel_sent'] as $operation => $field
        ) {
            if ($action === $operation) {
                $this->safe[$field] = true;
            }
        }
        $response = $send($body);
        $returned = self::id($response);
        if ($id !== null && $returned !== $id) {
            throw new ProtocolException('The action returned an unexpected identity.');
        }
        $this->ledger->recordObservation(
            $action . '_response',
            ['id' => $returned],
            $this->session->contextFingerprint()
        );
        return $response;
    }

    /** @return array<string,mixed> */
    private function finish(): array
    {
        $stage = $this->stage;
        $this->cleanCancel();
        $this->safe['reconciliation_required'] = $this->uncertain || ($this->intent !== null && !$this->cleanCancelled
            && !in_array($this->safe['reason'], ['manual_capture_and_refund_accepted',
                'authorized_cancellation_verified'], true));
        return $this->safe + ['scenario' => 'klarna-manual-capture', 'api_version' => '2026-08-21',
            'stage' => $stage, 'observed_state' => $this->state,
            'created_counts' => $this->ledger->counts(), 'login_requests' => $this->session->loginRequests(),
            'financial_settlement_verified' => false] + $this->ledger->evidence();
    }

    private function cleanCancel(): void
    {
        $this->safe['cleanup'] = 'not_attempted';
        if (
            $this->uncertain || $this->intent === null || $this->safe['simulation_attempted']
            || $this->safe['capture_sent'] || $this->safe['refund_sent'] || $this->safe['authorized_cancel_sent']
        ) {
            return;
        }
        try {
            $observed = $this->readOwn();
            if (!in_array($observed['status'] ?? null, ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION'], true)) {
                return;
            }
            $this->zero($observed);
            $id = $this->ownId();
            $this->stage = 'clean_cancel';
            $this->mutate(
                'klarna_clean_cancel',
                ['cancellation_reason' => 'SDK sandbox unused Klarna cleanup'],
                fn (array $body): array => $this->session->client->payments()->paymentIntents()->cancel($id, $body),
                $id
            );
            $this->safe['cleanup'] = $this->poll('CANCELLED', '0') ? 'pass' : 'pending';
            $this->cleanCancelled = $this->safe['cleanup'] === 'pass';
            $this->uncertain = !$this->cleanCancelled;
        } catch (Throwable) {
            $this->uncertain = true;
            $this->safe['cleanup'] = 'pending';
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['session' => '[redacted]', 'ledger' => '[private]', 'state' => $this->state];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Private Klarna journeys cannot be serialized.');
    }

    private function ownId(): string
    {
        $this->ledger->assertContext($this->session->contextFingerprint());
        if ($this->intent === null || !in_array($this->intent, $this->ledger->created('payment_intents'), true)) {
            throw new ProtocolException('An owned recorded fresh Intent is required.');
        }
        return $this->intent;
    }

    /** @param array<array-key,mixed> $data */
    private static function id(#[SensitiveParameter] array $data): string
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $id) !== 1) {
            throw new ProtocolException('The returned resource identity is unavailable.');
        }
        return $id;
    }

    /** @param array<array-key,mixed> $actual
     * @param array<array-key,mixed> $expected
     */
    private static function same(#[SensitiveParameter] array $actual, #[SensitiveParameter] array $expected): void
    {
        foreach ($expected as $field => $value) {
            if ($field === 'quantity' && $value === 1) {
                if (($actual[$field] ?? null) !== 1 && !ExactDecimal::equalsLiteral($actual[$field] ?? null, '1')) {
                    throw new ProtocolException('The observed product quantity does not match.');
                }
            } elseif (in_array($field, ['amount', 'unit_price'], true)) {
                if (!is_string($value) || !ExactDecimal::equalsLiteral($actual[$field] ?? null, $value)) {
                    throw new ProtocolException('The observed exact monetary field does not match.');
                }
            } elseif (is_array($value) && is_array($actual[$field] ?? null)) {
                if (array_is_list($value) && count($actual[$field]) !== count($value)) {
                    throw new ProtocolException('The observed product inventory does not match.');
                }
                self::same($actual[$field], $value);
            } elseif (($actual[$field] ?? null) !== $value) {
                throw new ProtocolException('The observed own resource mapping does not match.');
            }
        }
    }
}
