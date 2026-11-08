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

/** A fresh one-time Intent and one documented customer GET, with no HTML/form automation. */
final class AlipayRedirectPreflight
{
    private const string AMOUNT = '1.23';
    private const string CURRENCY = 'HKD';
    private const string RETURN_URL = 'https://example.com/sdk-sandbox-return';
    private string $stage = 'initialization';
    private ?string $intent = null;
    /** @var array<string,mixed> */
    private array $expected = [];
    private bool $unsafe = false;
    private int $ambiguous = 0;
    private bool $cancelAttempted = false;
    private bool $refundAttempted = false;
    private ?string $observedState = null;

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
        AlipayRedirect $redirect,
        ?Closure $sleep = null,
        int $readAttempts = 7,
    ): array {
        if ($session->isOrganization() || $readAttempts < 1 || $readAttempts > 7) {
            throw new RuntimeException('Alipay preflight requires account context and bounded observations.');
        }
        $ledger->assertContext($session->contextFingerprint());
        $journey = new self($session, $ledger, $sleep ?? static fn (int $n) => sleep($n), $readAttempts);
        $safe = ['scenario' => 'alipay-redirect-preflight', 'api_version' => '2026-08-21',
            'result' => 'blocked', 'reason' => 'customer_interaction_not_automated', 'navigation_attempted' => false];
        try {
            $journey->create();
            $journey->stage = 'intent_confirm';
            $id = $journey->ownId();
            $confirmed = $journey->mutate('redirect_confirm', [
                'payment_method' => ['type' => 'alipayhk',
                    'alipayhk' => ['flow' => 'mobile_web', 'os_type' => 'android']],
            ], fn (array $body): array => $session->client->payments()->paymentIntents()->confirm($id, $body), $id);
            $journey->readOwn();
            $next = $confirmed['next_action'] ?? null;
            $safe['next_action_type'] = is_array($next) && ($next['type'] ?? null) === 'redirect'
                ? 'redirect' : 'unrecognized';
            $safe['next_action_method'] = is_array($next) && ($next['method'] ?? null) === 'GET'
                ? 'GET' : 'unrecognized';
            $safe['redirect_uri'] = AlipayRedirect::diagnose(is_array($next) ? ($next['url'] ?? null) : null);
            $safe['redirect_contract_matches'] = is_array($next) && ($next['type'] ?? null) === 'redirect'
                && ($next['method'] ?? null) === 'GET' && $safe['redirect_uri']['eligible'] === true;
            if ($safe['redirect_contract_matches'] && is_array($next) && is_string($next['url'] ?? null)) {
                $journey->stage = 'customer_redirect_get';
                $ledger->requestId('redirect_navigation', ['url' => $next['url']], $session->contextFingerprint());
                // A local intent only: never add an invented request identity or auth header to this customer GET.
                $safe['navigation_attempted'] = true;
                try {
                    $safe['navigation'] = $redirect->inspect($next['url']);
                } catch (Throwable) {
                    $journey->unsafe = true;
                    ++$journey->ambiguous;
                    throw new RuntimeException('The customer GET needs independent reconciliation.');
                }
                if ($safe['navigation']['http_status'] >= 500) {
                    $journey->unsafe = true;
                    ++$journey->ambiguous;
                    $safe['result'] = 'fail';
                    $safe['reason'] = 'customer_read_server_failure';
                } elseif ($safe['navigation']['http_status'] >= 400) {
                    $safe['result'] = 'fail';
                    $safe['reason'] = 'customer_read_rejected';
                }
            } else {
                $safe['reason'] = 'redirect_contract_unverified';
            }
        } catch (ApiException $error) {
            $safe['result'] = 'fail';
            $safe['http_status'] = $error->httpStatus();
            $safe['reason'] = 'api_request_rejected';
        } catch (Throwable) {
            $safe['result'] = 'fail';
            $safe['reason'] = 'local_protocol_or_transport';
        }
        $safe['stage'] = $journey->stage;
        $cleanup = $journey->finish();
        $safe['cleanup'] = $cleanup;
        $safe['observed_state'] = $journey->observedState ?? 'UNAVAILABLE';
        if ($cleanup === 'fail') {
            $safe['result'] = 'fail';
        } elseif ($cleanup === 'refund_accepted' && $safe['result'] !== 'fail') {
            $safe['result'] = $safe['navigation_attempted'] === true ? 'pass' : 'blocked';
            $safe['reason'] = 'captured_payment_refund_accepted';
        }
        return $safe + [
            'run_id' => $ledger->runId(), 'created_counts' => $ledger->counts(),
            'login_requests' => $session->loginRequests(), 'ambiguous_actions' => $journey->ambiguous,
            'reconciliation_required' => in_array($cleanup, ['fail', 'pending'], true) || $journey->unsafe,
            'retained' => 'Own payment/refund history remains; no other account resources are swept.',
        ] + $ledger->evidence();
    }

    private function create(): void
    {
        $this->stage = 'intent_create_retrieve';
        $this->expected = [
            'amount' => self::AMOUNT, 'currency' => self::CURRENCY,
            'merchant_order_id' => substr($this->ledger->runId(), -36) . '-redirect',
            'return_url' => self::RETURN_URL, 'metadata' => ['sdk_validation_run' => $this->ledger->runId()],
        ];
        $created = $this->mutate(
            'redirect_intent',
            $this->expected,
            fn (array $body): array => $this->session->client->payments()->paymentIntents()->create($body)
        );
        $this->intent = self::id($created);
        $this->ledger->recordCreated('payment_intents', $this->intent);
        $observed = $this->readOwn();
        if (($observed['status'] ?? null) !== 'REQUIRES_PAYMENT_METHOD') {
            $this->unsafe = true;
            throw new ProtocolException('Fresh Intent is not in the expected unconfirmed state.');
        }
    }

    /** @return array<array-key,mixed> */
    private function readOwn(): array
    {
        $id = $this->ownId();
        try {
            $observed = $this->session->client->payments()->paymentIntents()->retrieve($id);
            self::same($observed, ['id' => $id, ...$this->expected]);
            $state = $observed['status'] ?? null;
            $known = ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION', 'REQUIRES_CAPTURE',
                'PENDING', 'PENDING_REVIEW', 'SUCCEEDED', 'CANCELLED'];
            $this->observedState = is_string($state) && in_array($state, $known, true) ? $state : 'UNKNOWN';
            return $observed;
        } catch (Throwable $error) {
            $this->unsafe = true;
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
    ): array {
        $body['request_id'] = $this->ledger->requestId($action, $body, $this->session->contextFingerprint());
        try {
            $response = $send($body);
            if ($expectedId !== null && self::id($response) !== $expectedId) {
                throw new ProtocolException('Sandbox mutation returned an unexpected identity.');
            }
            self::id($response);
            return $response;
        } catch (Throwable $error) {
            if (
                $error instanceof TransportException || $error instanceof ProtocolException
                || ($error instanceof ApiException && $error->httpStatus() >= 500)
            ) {
                $this->unsafe = true;
                ++$this->ambiguous;
            }
            throw $error;
        }
    }

    private function finish(): string
    {
        if ($this->intent === null) {
            return $this->unsafe ? 'pending' : 'not_created';
        }
        try {
            // Always observe the own Intent after the attempted customer read, even when that read was ambiguous.
            $observed = $this->readOwn();
            if ($this->unsafe) {
                return 'pending';
            }
            $state = $observed['status'] ?? null;
            if ($state === 'CANCELLED') {
                return 'cancelled';
            }
            if ($state === 'SUCCEEDED') {
                if (!ExactDecimal::equalsLiteral($observed['captured_amount'] ?? null, self::AMOUNT)) {
                    return 'pending';
                }
                return $this->refund();
            }
            if (!in_array($state, ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION', 'REQUIRES_CAPTURE'], true)) {
                return 'pending';
            }
            if ($this->cancelAttempted || $this->refundAttempted) {
                return 'pending';
            }
            $this->cancelAttempted = true;
            $id = $this->ownId();
            $this->mutate(
                'redirect_cleanup_cancel',
                ['cancellation_reason' => 'SDK sandbox redirect cleanup'],
                fn (array $body): array => $this->session->client->payments()->paymentIntents()->cancel($id, $body),
                $id
            );
            for ($i = 0; $i < $this->readAttempts; ++$i) {
                if (($this->readOwn()['status'] ?? null) === 'CANCELLED') {
                    return 'cancelled';
                }
                $this->pause($i);
            }
            return 'pending';
        } catch (Throwable) {
            return 'fail';
        }
    }

    private function refund(): string
    {
        if ($this->refundAttempted || $this->cancelAttempted) {
            return 'pending';
        }
        $id = $this->ownId();
        $this->refundAttempted = true;
        $created = $this->mutate('redirect_refund', [
            'payment_intent_id' => $id, 'amount' => self::AMOUNT, 'reason' => 'SDK sandbox redirect validation',
        ], fn (array $body): array => $this->session->client->payments()->refunds()->create($body));
        $refund = self::id($created);
        $this->ledger->recordCreated('payment_refunds', $refund);
        for ($i = 0; $i < $this->readAttempts; ++$i) {
            $observed = $this->session->client->payments()->refunds()->retrieve($refund);
            self::same($observed, [
                'id' => $refund, 'payment_intent_id' => $id, 'amount' => self::AMOUNT, 'currency' => self::CURRENCY,
            ]);
            if (in_array($observed['status'] ?? null, ['ACCEPTED', 'SETTLED'], true)) {
                return 'refund_accepted'; // ACCEPTED is acceptance, never shopper settlement.
            }
            if (($observed['status'] ?? null) === 'FAILED') {
                return 'fail';
            }
            $this->pause($i);
        }
        return 'pending';
    }

    private function pause(int $index): void
    {
        if ($index + 1 < $this->readAttempts) {
            ($this->sleep)([1, 2, 4, 8, 8, 7][$index]);
        }
    }

    private function ownId(): string
    {
        $this->ledger->assertContext($this->session->contextFingerprint());
        if ($this->intent === null || !in_array($this->intent, $this->ledger->created('payment_intents'), true)) {
            throw new RuntimeException('The preflight requires its recorded own Intent.');
        }
        return $this->intent;
    }

    /** @param array<array-key,mixed> $data */
    private static function id(#[SensitiveParameter] array $data): string
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new ProtocolException('Sandbox response omitted a resource identity.');
        }
        return $id;
    }

    /**
     * @param array<array-key,mixed> $actual
     * @param array<array-key,mixed> $expected
     */
    private static function same(#[SensitiveParameter] array $actual, #[SensitiveParameter] array $expected): void
    {
        foreach ($expected as $key => $value) {
            if ($key === 'amount') {
                if (!is_string($value) || !ExactDecimal::equalsLiteral($actual[$key] ?? null, $value)) {
                    throw new ProtocolException('Sandbox readback changed the exact amount.');
                }
            } elseif (is_array($value) && is_array($actual[$key] ?? null)) {
                self::same($actual[$key], $value);
            } elseif (!array_key_exists($key, $actual) || $actual[$key] !== $value) {
                throw new ProtocolException('Sandbox readback did not match its own Intent mapping.');
            }
        }
    }
}
