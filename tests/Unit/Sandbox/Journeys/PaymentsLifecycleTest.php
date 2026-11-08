<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\PaymentsLifecycle;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class PaymentsLifecycleTest extends TestCase
{
    private string $directory;
    private PaymentsProvider $provider;
    private SandboxSession $session;
    private RunLedger $ledger;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-payments-journey-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $path = $this->directory . '/credentials';
        file_put_contents($path, "AIRWALLEX_CLIENT_ID=fixture_client\nAIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_key\n"
            . "AIRWALLEX_ACCOUNT_ID=fixture_account\n");
        $this->provider = new PaymentsProvider();
        $this->session = new SandboxSession(
            Configuration::load('sandbox', $path),
            'account',
            $this->provider,
            static fn (): int => 100,
        );
        $sequence = 0;
        $this->ledger = RunLedger::create(
            $this->session->contextFingerprint(),
            $this->directory,
            static function () use (&$sequence): string {
                return sprintf('00000000-0000-4000-8000-%012d', ++$sequence);
            }
        );
    }

    protected function tearDown(): void
    {
        self::remove($this->directory);
    }

    private static function remove(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $file = $path . '/' . $entry;
            if (is_dir($file)) {
                self::remove($file);
            } else {
                unlink($file);
            }
        }
        rmdir($path);
    }

    /** @return array<string,mixed> */
    private function runJourney(): array
    {
        return PaymentsLifecycle::execute(
            $this->session,
            $this->ledger,
            static fn (int $seconds) => null,
            readAttempts: 3,
        );
    }

    public function testIndependentFlowsExercisePublicOperationsExactReadbackAndOwnCleanup(): void
    {
        $result = $this->runJourney();
        self::assertSame('pass', $result['result']);
        self::assertSame([
            'payment_customers' => 2, 'payment_consents' => 2, 'payment_intents' => 5, 'payment_refunds' => 3,
        ], $result['created_counts']);
        self::assertSame(0, $result['ambiguous_mutations']);
        self::assertFalse($result['reconciliation_required']);
        self::assertSame(2, $result['login_requests']);
        foreach ($this->ledger->created('payment_refunds') as $refund) {
            self::assertSame('HKD', $this->provider->resources[$refund]['currency']);
        }
        foreach ($this->provider->requests as $request) {
            self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
            self::assertSame('https', $request->getUri()->getScheme());
            self::assertSame('2026-08-21', $request->getHeaderLine('x-api-version'));
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
            if (str_ends_with($request->getUri()->getPath(), '/authentication/login')) {
                self::assertSame('fixture_account', $request->getHeaderLine('x-login-as'));
            }
            if (str_ends_with($request->getUri()->getPath(), '/payment_intents/create')) {
                self::assertStringContainsString('"amount":1.23', (string) $request->getBody());
            }
        }
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['synthetic-', 'fixture_key', 'fixture_client', 'fixture_account', '4035501000000008'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }

    public function testEveryMutationHasADurableBodyHashBeforeSendAndSimulationHasNoInventedIdentity(): void
    {
        $this->provider->beforeMutation = function (RequestInterface $request, array $body): void {
            $state = json_decode((string) file_get_contents(
                $this->directory . '/' . $this->ledger->runId() . '/ledger.json',
            ), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($state);
            self::assertIsArray($state['actions']);
            $id = $body['request_id'] ?? null;
            unset($body['request_id']);
            $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
            $matches = array_filter($state['actions'], static fn (mixed $a): bool
                => is_array($a) && ($a['body_hash'] ?? null) === $hash
                && ($id === null || ($a['request_id'] ?? null) === $id));
            self::assertCount(1, $matches);
            if (str_contains($request->getUri()->getPath(), '/simulation/')) {
                self::assertNull($id);
            } else {
                self::assertIsString($id);
            }
        };
        self::assertSame('pass', $this->runJourney()['result']);
    }

    public function testAlipayRejectionDoesNotSkipIndependentCancellationOrCardChecks(): void
    {
        $this->provider->intercept = static function (RequestInterface $request): ?ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/verify')) {
                return new Response(403, [], '{"code":"forbidden","message":"PRIVATE_PROVIDER_MESSAGE"}');
            }
            return null;
        };
        $result = $this->runJourney();
        self::assertSame('blocked', $result['result']);
        self::assertIsArray($result['steps']);
        self::assertSame(['result' => 'pass'], $result['steps']['unconfirmed_cancellation']);
        self::assertSame(['result' => 'pass'], $result['steps']['alipay_one_time']);
        self::assertSame(['result' => 'pass'], $result['steps']['card_capture_refund']);
        self::assertSame(['result' => 'pass'], $result['steps']['card_authorization_cancel']);
        self::assertSame(0, $result['ambiguous_mutations']);
        self::assertStringNotContainsString('PRIVATE_PROVIDER_MESSAGE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testAmbiguousConfirmIsNeverReplayedCancelledOrRefundedAutomatically(): void
    {
        $uncertain = null;
        $this->provider->intercept = static function (RequestInterface $request) use (&$uncertain): ?ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/confirm')) {
                $body = (new JsonCodec())->decodeObject((string) $request->getBody());
                if (isset($body['payment_consent_id'])) {
                    $uncertain = basename(dirname($request->getUri()->getPath()));
                    return new Response(503, [], '{"code":"internal_error"}');
                }
            }
            return null;
        };
        $result = $this->runJourney();
        self::assertSame('fail', $result['result']);
        self::assertSame(1, $result['ambiguous_mutations']);
        self::assertTrue($result['reconciliation_required']);
        self::assertIsString($uncertain);
        $writes = array_filter($this->provider->requests, static fn (RequestInterface $r): bool
            => $r->getMethod() === 'POST' && str_contains($r->getUri()->getPath(), '/' . $uncertain . '/'));
        self::assertCount(1, $writes);
        foreach ($this->provider->mutationBodies as $body) {
            self::assertNotSame($uncertain, $body['payment_intent_id'] ?? null);
        }
    }

    /** @return iterable<string,array{bool}> */
    public static function unavailableRefundCurrencies(): iterable
    {
        yield 'missing' => [true];
        yield 'wrong' => [false];
    }

    #[DataProvider('unavailableRefundCurrencies')]
    public function testRefundReadbackRequiresOwnedCurrencyWithoutRepeatingFinancialActions(bool $missing): void
    {
        $this->provider->response = static function (RequestInterface $request, array $resource) use ($missing): array {
            if ($request->getMethod() === 'GET' && str_contains($request->getUri()->getPath(), '/refunds/')) {
                if ($missing) {
                    unset($resource['currency']);
                } else {
                    $resource['currency'] = 'PRIVATE_WRONG_CURRENCY';
                }
            }
            return $resource;
        };
        $result = $this->runJourney();
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertSame(0, $result['ambiguous_mutations']);
        self::assertIsArray($result['steps']);
        foreach (['alipay_saved_consent', 'alipay_one_time', 'card_capture_refund'] as $step) {
            self::assertSame(['result' => 'fail', 'stage' => match ($step) {
                'alipay_saved_consent' => 'alipay_saved_refund_create_retrieve',
                'alipay_one_time' => 'alipay_one_time_refund_create_retrieve',
                default => 'card_capture_refund_create_retrieve',
            }, 'reason' => 'local_protocol_or_transport'], $result['steps'][$step]);
        }
        $refunds = $this->ledger->created('payment_refunds');
        self::assertCount(3, $refunds);
        foreach ($refunds as $refund) {
            $parent = $this->provider->resources[$refund]['payment_intent_id'];
            self::assertIsString($parent);
            $reads = array_filter($this->provider->requests, static fn (RequestInterface $request): bool
                => $request->getMethod() === 'GET' && str_ends_with($request->getUri()->getPath(), '/' . $refund));
            self::assertCount(1, $reads);
            $writes = array_filter($this->provider->mutationBodies, static fn (array $body): bool
                => ($body['payment_intent_id'] ?? null) === $parent);
            self::assertCount(1, $writes, 'A failed refund observation must not create another refund.');
            $cancels = array_filter($this->provider->requests, static fn (RequestInterface $request): bool
                => str_ends_with($request->getUri()->getPath(), '/' . $parent . '/cancel'));
            self::assertCount(0, $cancels);
        }
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testPendingRefundReadsAreBoundedAndDoNotCreateAnotherRefund(): void
    {
        $this->provider->pendingRefunds = true;
        $result = $this->runJourney();
        self::assertSame('pending', $result['result']);
        self::assertIsArray($result['created_counts']);
        self::assertSame(3, $result['created_counts']['payment_refunds']);
        foreach ($this->ledger->created('payment_refunds') as $id) {
            $reads = array_filter($this->provider->requests, static fn (RequestInterface $r): bool
                => $r->getMethod() === 'GET' && str_ends_with($r->getUri()->getPath(), '/' . $id));
            self::assertCount(3, $reads);
            $parent = $this->provider->resources[$id]['payment_intent_id'];
            self::assertIsString($parent);
            $index = array_search($parent, $this->ledger->created('payment_intents'), true);
            self::assertIsInt($index);
            self::assertIsArray($result['cleanup']);
            self::assertSame('pending', $result['cleanup']['intent_' . $index]);
            $cancels = array_filter($this->provider->requests, static fn (RequestInterface $request): bool
                => str_ends_with($request->getUri()->getPath(), '/' . $parent . '/cancel'));
            self::assertCount(0, $cancels);
        }
    }

    /** @return iterable<string,array{string,mixed,bool}> */
    public static function incorrectIntentFields(): iterable
    {
        yield 'wrong exact amount' => ['amount', '1.23000000000000000001', false];
        yield 'wrong currency' => ['currency', 'USD', false];
        yield 'missing order' => ['merchant_order_id', null, true];
        yield 'wrong run' => ['metadata', ['sdk_validation_run' => 'another-run'], false];
    }

    #[DataProvider('incorrectIntentFields')]
    public function testWrongOrMissingIntentReadbackCannotPass(string $field, mixed $value, bool $missing): void
    {
        $this->provider->response = static function (
            RequestInterface $request,
            array $resource
        ) use (
            $field,
            $value,
            $missing,
        ): array {
            if ($request->getMethod() === 'GET' && str_contains($request->getUri()->getPath(), '/payment_intents/')) {
                if ($missing) {
                    unset($resource[$field]);
                } else {
                    $resource[$field] = $value;
                }
            }
            return $resource;
        };
        $result = $this->runJourney();
        self::assertSame('fail', $result['result']);
        self::assertSame([], $this->ledger->created('payment_refunds'));
    }

    public function testWrongAccountCannotSendAnyPaymentsOperation(): void
    {
        $other = RunLedger::create(str_repeat('b', 64), $this->directory);
        $this->expectException(RuntimeException::class);
        try {
            PaymentsLifecycle::execute($this->session, $other, static fn (int $seconds) => null, readAttempts: 3);
        } finally {
            self::assertSame([], $this->provider->requests);
        }
    }

    public function testUnknownNextActionDoesNotInventAShopperActionOrCharge(): void
    {
        $this->provider->response = static function (RequestInterface $request, array $resource): array {
            if (str_ends_with($request->getUri()->getPath(), '/verify')) {
                $resource['next_action'] = ['type' => 'future_action', 'url' => 'https://example.test/private'];
            }
            return $resource;
        };
        $result = $this->runJourney();
        self::assertSame('pending', $result['result']);
        self::assertIsArray($result['steps']);
        self::assertIsArray($result['steps']['alipay_saved_consent']);
        self::assertSame('pending', $result['steps']['alipay_saved_consent']['result']);
        self::assertCount(2, $this->ledger->created('payment_refunds'));
        foreach ($this->provider->requests as $request) {
            self::assertFalse(str_ends_with($request->getUri()->getPath(), '/shopper_actions/verify'));
        }
    }

    public function testEquivalentDecimalReadbacksPreserveValueWithoutFloats(): void
    {
        $this->provider->response = static function (RequestInterface $request, array $resource): array {
            if (isset($resource['amount'])) {
                $resource['amount'] = '1.2300000000';
            }
            return $resource;
        };
        self::assertSame('pass', $this->runJourney()['result']);
    }

    public function testCleanupFailureIsVisibleAndRetainsThePrivateInventory(): void
    {
        $this->provider->intercept = static fn (RequestInterface $request): ?ResponseInterface
            => str_ends_with($request->getUri()->getPath(), '/disable')
                ? new Response(403, [], '{"code":"forbidden"}') : null;
        $result = $this->runJourney();
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(2, $this->ledger->created('payment_consents'));
    }

    public function testFailedDurabilityPreventsEveryIndependentFlowFromSending(): void
    {
        $calls = 0;
        $ledger = RunLedger::create(
            $this->session->contextFingerprint(),
            $this->directory,
            synchronize: static function ($stream) use (&$calls): bool {
                return ++$calls !== 4 && fsync($stream);
            },
        );
        $result = PaymentsLifecycle::execute(
            $this->session,
            $ledger,
            static fn (int $seconds) => null,
            readAttempts: 3,
        );
        self::assertSame('fail', $result['result']);
        self::assertSame([], $this->provider->requests);
    }

    /** @return iterable<string,array{mixed,bool}> */
    public static function unavailableCapturedAmounts(): iterable
    {
        yield 'missing' => [null, true];
        yield 'zero' => ['0', false];
        yield 'partial' => ['0.50', false];
        yield 'malformed' => ['invalid', false];
    }

    #[DataProvider('unavailableCapturedAmounts')]
    public function testRefundRequiresObservedCapturedFundsRatherThanOrderAmount(mixed $value, bool $missing): void
    {
        $this->provider->response = static function (
            RequestInterface $request,
            array $resource
        ) use (
            $value,
            $missing,
        ): array {
            $order = $resource['merchant_order_id'] ?? null;
            if ($request->getMethod() === 'GET' && is_string($order) && str_ends_with($order, '-alipay_saved')) {
                if ($missing) {
                    unset($resource['captured_amount']);
                } else {
                    $resource['captured_amount'] = $value;
                }
            }
            return $resource;
        };
        $result = $this->runJourney();
        self::assertNotSame('pass', $result['result']);
        $target = null;
        foreach ($this->provider->resources as $resource) {
            $order = $resource['merchant_order_id'] ?? null;
            if (is_string($order) && str_ends_with($order, '-alipay_saved')) {
                $target = $resource;
                break;
            }
        }
        self::assertIsArray($target);
        foreach ($this->provider->mutationBodies as $body) {
            self::assertNotSame($target['id'], $body['payment_intent_id'] ?? null);
        }
        self::assertCount(2, $this->ledger->created('payment_refunds'));
    }

    /** @return iterable<string,array{string,mixed,bool}> */
    public static function invalidAuthorizationFields(): iterable
    {
        yield 'missing amount' => ['amount', null, true];
        yield 'zero amount' => ['amount', '0', false];
        yield 'wrong amount' => ['amount', '1.2301', false];
        yield 'malformed amount' => ['amount', 'invalid', false];
        yield 'missing currency' => ['currency', null, true];
        yield 'wrong currency' => ['currency', 'USD', false];
    }

    #[DataProvider('invalidAuthorizationFields')]
    public function testActualAuthorizationMustMatchAmountAndCurrencyBeforeFurtherWrites(
        string $field,
        mixed $value,
        bool $missing,
    ): void {
        $this->provider->response = static function (
            RequestInterface $request,
            array $resource
        ) use (
            $field,
            $value,
            $missing,
        ): array {
            if (str_contains($request->getUri()->getPath(), '/payment_attempts/')) {
                if ($missing) {
                    unset($resource[$field]);
                } else {
                    $resource[$field] = $value;
                }
            }
            return $resource;
        };
        self::assertSame('fail', $this->runJourney()['result']);
        foreach ($this->provider->requests as $request) {
            self::assertFalse(str_ends_with($request->getUri()->getPath(), '/capture'));
        }
        self::assertCount(2, $this->ledger->created('payment_refunds'));
    }

    public function testDefaultAsyncWindowUsesSevenReadsAndThirtySecondsOfInjectedWaiting(): void
    {
        $this->provider->pendingRefunds = true;
        $delays = [];
        $result = PaymentsLifecycle::execute($this->session, $this->ledger, static function (int $seconds) use (
            &$delays,
        ): void {
            $delays[] = $seconds;
        });
        self::assertSame('pending', $result['result']);
        self::assertSame(array_merge(...array_fill(0, 3, [1, 2, 4, 8, 8, 7])), $delays);
        foreach ($this->ledger->created('payment_refunds') as $id) {
            $reads = array_filter($this->provider->requests, static fn (RequestInterface $r): bool
                => $r->getMethod() === 'GET' && str_ends_with($r->getUri()->getPath(), '/' . $id));
            self::assertCount(7, $reads);
        }
    }

    public function testUnreadableSimulationSuccessRetainsTargetWithoutReplayOrCleanupMutation(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willThrowException(new RuntimeException('SYNTHETIC_PRIVATE_STREAM_FAILURE'));
        $this->provider->intercept = static fn (RequestInterface $request): ?ResponseInterface
            => str_ends_with($request->getUri()->getPath(), '/shopper_actions/verify')
                ? new Response(200, [], $stream) : null;
        $result = $this->runJourney();
        self::assertSame('fail', $result['result']);
        self::assertSame(1, $result['ambiguous_mutations']);
        self::assertTrue($result['reconciliation_required']);
        $consents = $this->ledger->created('payment_consents');
        self::assertCount(2, $consents);
        $simulationRequests = 0;
        foreach ($this->provider->requests as $request) {
            $path = $request->getUri()->getPath();
            if (str_ends_with($path, '/shopper_actions/verify')) {
                ++$simulationRequests;
            }
            self::assertFalse(str_ends_with($path, '/' . $consents[1] . '/disable'));
        }
        self::assertSame(1, $simulationRequests);
        self::assertStringNotContainsString(
            'SYNTHETIC_PRIVATE_STREAM_FAILURE',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }
}
