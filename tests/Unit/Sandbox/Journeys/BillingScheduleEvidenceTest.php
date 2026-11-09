<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingScheduleCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingScheduleEvidence;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class BillingScheduleEvidenceTest extends TestCase
{
    private string $directory;
    private BillingScheduleProvider $http;
    private SandboxSession $session;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/billing-schedule-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_CLIENT_ID=fixture_client\nAIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_key\n"
            . "AIRWALLEX_ACCOUNT_ID=fixture_account\n"
        );
        $this->http = new BillingScheduleProvider();
        $this->session = new SandboxSession(
            Configuration::load('sandbox', $this->directory . '/credentials'),
            'organization',
            $this->http,
            static fn (): int => 1830297600
        );
    }

    protected function tearDown(): void
    {
        unlink($this->directory . '/credentials');
        foreach (glob($this->directory . '/*') ?: [] as $folder) {
            foreach (glob($folder . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($folder);
        }
        rmdir($this->directory);
    }

    private function ledger(?Closure $synchronize = null): RunLedger
    {
        return RunLedger::create(
            BillingScheduleEvidence::contextFingerprint($this->session, 'fixture_account'),
            $this->directory,
            synchronize: $synchronize
        );
    }

    /** @return array<string,mixed> */
    private function runCase(string $case, ?RunLedger $ledger = null, ?Closure $clock = null): array
    {
        return BillingScheduleEvidence::execute(
            $this->session,
            $ledger ?? $this->ledger(),
            $case,
            'fixture_entity',
            'fixture_account',
            $clock ?? static fn (): int => 1830297600,
            static fn (int $s) => null,
            3
        );
    }

    /** @return iterable<string,array{string}> */
    public static function cases(): iterable
    {
        foreach (BillingScheduleEvidence::CASES as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('cases')]
    public function testEachCaseIsIndependentFiniteAndUsesDurableOneSendActions(string $case): void
    {
        $ledger = $this->ledger();
        $this->http->intercept = function (RequestInterface $request) use ($ledger): ?ResponseInterface {
            if ($request->getMethod() === 'POST' && str_contains($request->getUri()->getPath(), '/billing/')) {
                $body = BillingScheduleProvider::body($request);
                $files = glob($this->directory . '/*/ledger.json') ?: [];
                self::assertCount(1, $files);
                $state = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($state);
                self::assertSame($ledger->runId(), $state['run_id']);
                self::assertIsString($body['request_id']);
                self::assertStringContainsString($body['request_id'], json_encode($state, JSON_THROW_ON_ERROR));
            }
            return null;
        };
        $result = $this->runCase($case, $ledger);
        self::assertSame(
            in_array($case, [
                'finite-remaining', 'clear-all-pending', 'remaining-active-only',
            ], true) ? 'pending' : 'pass',
            $result['result'],
            json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertTrue($result['cancellation_verified']);
        self::assertFalse($result['ambiguous_mutation']);
        self::assertFalse($result['temporary_indefinite_possible']);
        self::assertSame(['subscription' => 'pass', 'prices' => 'pass', 'products' => 'pass'], $result['cleanup']);
        self::assertTrue($result['funds_movement_unverified']);
        self::assertSame(['customers' => 1, 'products' => 1, 'prices' => 1, 'subscriptions' => 1], $ledger->counts());
        $ids = [];
        foreach ($this->http->mutations() as $request) {
            self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
            self::assertFalse($request->hasHeader('x-login-as'));
            $body = BillingScheduleProvider::body($request);
            self::assertIsString($body['request_id']);
            $ids[] = $body['request_id'];
        }
        self::assertSame($ids, array_values(array_unique($ids)));
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['fixture_', 'example.test', 'Bearer', 'payment_source_id'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testClearAllNeverActivatesAndReservesCancellationBeforeRemovingFiniteBound(): void
    {
        $ledger = $this->ledger();
        $reserved = null;
        $this->http->intercept = function (RequestInterface $request) use (&$reserved): ?ResponseInterface {
            if ($request->getMethod() !== 'POST' || !str_contains($request->getUri()->getPath(), '/subscriptions/')) {
                return null;
            }
            $body = BillingScheduleProvider::body($request);
            self::assertNotSame('NOW', $body['starts_at'] ?? null);
            if (array_key_exists('remaining_duration', $body)) {
                self::assertNull($body['duration']);
                self::assertNull($body['remaining_duration']);
                self::assertNull($body['ends_at']);
                $files = glob($this->directory . '/*/ledger.json') ?: [];
                $state = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($state);
                $actions = $state['actions'];
                self::assertIsArray($actions);
                $cancel = $actions['cleanup_cancel'];
                self::assertIsArray($cancel);
                $reserved = $cancel['request_id'];
            }
            if (str_ends_with($request->getUri()->getPath(), '/cancel')) {
                self::assertSame($reserved, $body['request_id']);
                self::assertSame('NONE', $body['proration_behavior']);
            }
            return null;
        };
        $result = $this->runCase('clear-all-pending', $ledger);
        self::assertSame('pending', $result['result']);
        self::assertIsArray($result['observations']);
        self::assertTrue($result['observations']['future_start_guard']);
        self::assertTrue($result['observations']['visible_schedule_unset']);
        self::assertNotNull($reserved);
    }

    /** @return iterable<string,array{string,string}> */
    public static function immediateTrialCases(): iterable
    {
        yield 'original Z' => ['immediate-oob-trial', '2028-01-03T00:00:00Z'];
        yield 'basic UTC offset' => ['immediate-oob-trial-basic-offset', '2028-01-03T00:00:00+0000'];
    }

    #[DataProvider('immediateTrialCases')]
    public function testImmediateOutOfBandTrialOmitsOnlyStartAndCancelsBeforeArchivesWithoutActivation(
        string $case,
        string $end,
    ): void {
        $result = $this->runCase($case);
        self::assertSame('pass', $result['result']);
        self::assertTrue($result['cancellation_verified']);
        self::assertIsArray($result['observations']);
        foreach (
            ['trial_requested_without_starts_at', 'trial_observed_without_payment_action',
            'trial_end_matches_requested', 'trial_start_before_end_verified', 'trial_cancel_before_end_guard'] as $key
        ) {
            self::assertTrue($result['observations'][$key]);
        }
        $creates = $this->mutationsEnding('/subscriptions/create');
        self::assertCount(1, $creates);
        $body = BillingScheduleProvider::body($creates[0]);
        $keys = array_keys($body);
        sort($keys);
        self::assertSame(['billing_customer_id', 'collection_method', 'currency', 'days_until_due',
            'duration', 'items', 'legal_entity_id', 'metadata', 'request_id', 'trial_ends_at'], $keys);
        self::assertSame('OUT_OF_BAND', $body['collection_method']);
        self::assertSame('EUR', $body['currency']);
        self::assertSame(30, $body['days_until_due']);
        self::assertSame(['period' => 1, 'period_unit' => 'MONTH'], $body['duration']);
        self::assertSame($end, $body['trial_ends_at']);
        self::assertIsArray($body['metadata']);
        self::assertSame($case, $body['metadata']['sdk_validation_case']);
        foreach (['starts_at', 'payment_source_id', 'linked_payment_account_id', 'payment_options'] as $field) {
            self::assertArrayNotHasKey($field, $body);
        }
        $prices = $this->mutationsEnding('/prices/create');
        self::assertCount(1, $prices);
        $price = BillingScheduleProvider::body($prices[0]);
        self::assertSame('1.23', $price['unit_amount']);
        self::assertSame(['period' => 1, 'period_unit' => 'MONTH'], $price['recurring']);
        $cancels = $this->mutationsEnding('/cancel');
        self::assertCount(1, $cancels);
        self::assertSame('NONE', BillingScheduleProvider::body($cancels[0])['proration_behavior']);
        $cancelIndex = array_search($cancels[0], $this->http->requests, true);
        foreach ($this->http->requests as $i => $request) {
            self::assertStringNotContainsString('/pa/', $request->getUri()->getPath());
            self::assertStringNotContainsString('/invoices/', $request->getUri()->getPath());
            if (
                $request->getMethod() === 'POST'
                && $request->getUri()->getPath() !== '/api/v1/authentication/login'
            ) {
                $wire = BillingScheduleProvider::body($request);
                self::assertNotSame('NOW', $wire['starts_at'] ?? null);
                self::assertNotSame('NOW', $wire['trial_ends_at'] ?? null);
                if (array_key_exists('active', $wire) && $wire['active'] === false) {
                    self::assertGreaterThan($cancelIndex, $i);
                }
            }
        }
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function unsafeImmediateTrials(): iterable
    {
        foreach (['PENDING', 'ACTIVE', 'UNPAID', 'PRIVATE_UNKNOWN'] as $state) {
            yield $state => ['status', $state];
        }
        foreach (
            [null, 'now', '2028-02-30T00:00:00Z', '2028-01-01T00:00:00+24:00',
            '2028-01-03T00:00:00Z', '2028-01-04T00:00:00Z'] as $i => $start
        ) {
            yield 'invalid start ' . $i => ['starts_at', $start];
        }
        yield 'missing trial end' => ['trial_ends_at', null];
        yield 'invalid trial end offset' => ['trial_ends_at', '2028-01-03T00:00:00+24:00'];
        yield 'changed fractional trial end' => ['trial_ends_at', '2028-01-03T00:00:00.0000000001Z'];
        yield 'wrong due days' => ['days_until_due', 31];
        yield 'wrong duration' => ['duration', ['period' => 1, 'period_unit' => 'DAY']];
        yield 'wrong customer' => ['billing_customer_id', 'PRIVATE_FOREIGN'];
        yield 'wrong entity' => ['legal_entity_id', 'PRIVATE_FOREIGN'];
        yield 'wrong currency' => ['currency', 'USD'];
        yield 'wrong collection' => ['collection_method', 'AUTO_CHARGE'];
        yield 'wrong marker' => ['metadata', ['sdk_validation_run' => 'PRIVATE_FOREIGN']];
        yield 'unexpected source' => ['payment_source_id', 'PRIVATE_FOREIGN'];
    }

    /** @return iterable<string,array{string,string,mixed}> */
    public static function unsafeImmediateTrialVariants(): iterable
    {
        foreach (self::immediateTrialCases() as $profile => [$case]) {
            foreach (self::unsafeImmediateTrials() as $name => [$field, $value]) {
                yield $profile . '/' . $name => [$case, $field, $value];
            }
        }
    }

    #[DataProvider('unsafeImmediateTrialVariants')]
    public function testUnsafeImmediateTrialNeverCancelsOrArchives(string $case, string $field, mixed $value): void
    {
        $this->http->intercept = static function (
            RequestInterface $request,
            BillingScheduleProvider $http
        ) use (
            $field,
            $value,
        ): ?ResponseInterface {
            $path = $request->getUri()->getPath();
            if (
                $request->getMethod() === 'GET' && str_contains($path, '/subscriptions/')
                && !str_ends_with($path, '/items')
            ) {
                return BillingScheduleProvider::response(array_replace($http->objects[$path], [$field => $value]));
            }
            return null;
        };
        $result = $this->runCase($case);
        self::assertNotSame('pass', $result['result']);
        self::assertFalse($result['cancellation_verified']);
        self::assertCount(0, $this->mutationsEnding('/cancel'));
        self::assertCount(4, $this->http->mutations());
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('immediateTrialCases')]
    public function testImmediateTrialClockMustStillPrecedeEndAfterItemRead(string $case, string $end): void
    {
        $time = new class {
            public int $now = 1830297600;
        };
        $this->http->intercept = static function (RequestInterface $request) use ($time): ?ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/items')) {
                $time->now += 172800;
            }
            return null;
        };
        $result = $this->runCase($case, clock: static fn (): int => $time->now);
        self::assertNotSame('pass', $result['result']);
        self::assertCount(0, $this->mutationsEnding('/cancel'));
        self::assertCount(4, $this->http->mutations());
        self::assertSame($end, BillingScheduleProvider::body($this->mutationsEnding('/subscriptions/create')[0])
            ['trial_ends_at']);
    }

    /** @return iterable<string,array{string}> */
    public static function immediateCancellationFailures(): iterable
    {
        yield 'server rejection' => ['500'];
        yield 'definite rejection' => ['400'];
        yield 'after send transport failure' => ['transport'];
        yield 'ack without terminal state' => ['unchanged'];
    }

    /** @return iterable<string,array{string,string}> */
    public static function immediateCancellationVariants(): iterable
    {
        foreach (self::immediateTrialCases() as $profile => [$case]) {
            foreach (self::immediateCancellationFailures() as $name => [$fault]) {
                yield $profile . '/' . $name => [$case, $fault];
            }
        }
    }

    #[DataProvider('immediateCancellationVariants')]
    public function testImmediateTrialCancellationNeverRepeatsOrArchivesBeforeTerminalReadback(
        string $case,
        string $fault,
    ): void {
        $this->http->intercept = static function (RequestInterface $request) use ($fault): ?ResponseInterface {
            if (!str_ends_with($request->getUri()->getPath(), '/cancel')) {
                return null;
            }
            if ($fault === 'transport') {
                throw new RuntimeException('PRIVATE_AFTER_SEND');
            }
            if ($fault === 'unchanged') {
                $parts = explode('/', $request->getUri()->getPath());
                return BillingScheduleProvider::response(['id' => $parts[count($parts) - 2]]);
            }
            return new Response(
                $fault === '400' ? 400 : 500,
                [],
                '{"code":"validation_error","message":"PRIVATE_MESSAGE"}'
            );
        };
        $result = $this->runCase($case);
        self::assertNotSame('pass', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(1, $this->mutationsEnding('/cancel'));
        self::assertCount(5, $this->http->mutations());
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testBasicOffsetTrialBindsChosenTextInDurableIntentBeforeSend(): void
    {
        $ledger = $this->ledger();
        $checked = false;
        $this->http->intercept = function (RequestInterface $request) use (&$checked): ?ResponseInterface {
            if (!str_ends_with($request->getUri()->getPath(), '/subscriptions/create')) {
                return null;
            }
            $wire = BillingScheduleProvider::body($request);
            $identity = $wire['request_id'];
            unset($wire['request_id']);
            self::assertSame('2028-01-03T00:00:00+0000', $wire['trial_ends_at']);
            self::assertStringContainsString(
                '"trial_ends_at":"2028-01-03T00:00:00+0000"',
                (string) $request->getBody()
            );
            $files = glob($this->directory . '/*/ledger.json') ?: [];
            self::assertCount(1, $files);
            $state = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($state);
            self::assertIsArray($state['actions']);
            self::assertIsArray($state['actions']['create_subscription']);
            $action = $state['actions']['create_subscription'];
            self::assertSame($identity, $action['request_id']);
            $intent = ['resource_kind' => 'subscriptions', 'resource_id' => null, 'body' => $wire];
            self::assertSame(hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)), $action['body_hash']);
            $intent['body']['trial_ends_at'] = '2028-01-03T00:00:00Z';
            self::assertNotSame(hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)), $action['body_hash']);
            $checked = true;
            return null;
        };
        $result = $this->runCase('immediate-oob-trial-basic-offset', $ledger);
        self::assertSame('pass', $result['result']);
        self::assertTrue($checked);
    }

    public function testBasicOffsetTrialAllowsEquivalentResponseTimeWithoutRewritingRequest(): void
    {
        $this->http->intercept = static function (
            RequestInterface $request,
            BillingScheduleProvider $http,
        ): ?ResponseInterface {
            $path = $request->getUri()->getPath();
            if ($request->getMethod() === 'GET' && ($http->objects[$path]['status'] ?? null) === 'IN_TRIAL') {
                return BillingScheduleProvider::response(array_replace($http->objects[$path], [
                    'trial_ends_at' => '2028-01-03T01:00:00+01:00',
                ]));
            }
            return null;
        };
        $result = $this->runCase('immediate-oob-trial-basic-offset');
        self::assertSame('pass', $result['result']);
        self::assertSame(
            '2028-01-03T00:00:00+0000',
            BillingScheduleProvider::body($this->mutationsEnding('/subscriptions/create')[0])['trial_ends_at']
        );
        self::assertTrue($result['cancellation_verified']);
    }

    /** @return iterable<string,array{string}> */
    public static function trialCreateFailures(): iterable
    {
        foreach (['400', '500', 'transport', 'missing_id'] as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('trialCreateFailures')]
    public function testBasicOffsetTrialCreateFailureHasNoReplayOrDependentWrite(string $fault): void
    {
        $this->http->intercept = static function (RequestInterface $request) use ($fault): ?ResponseInterface {
            if (!str_ends_with($request->getUri()->getPath(), '/subscriptions/create')) {
                return null;
            }
            if ($fault === 'transport') {
                throw new RuntimeException('PRIVATE_AFTER_SEND');
            }
            return $fault === 'missing_id' ? new Response(201, [], '{}')
                : new Response(
                    $fault === '400' ? 400 : 500,
                    [],
                    '{"code":"validation_error","message":"PRIVATE_MESSAGE"}'
                );
        };
        $result = $this->runCase('immediate-oob-trial-basic-offset');
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(1, $this->mutationsEnding('/subscriptions/create'));
        self::assertCount(4, $this->http->mutations());
        self::assertCount(0, $this->mutationsEnding('/cancel'));
        self::assertCount(0, $this->mutationsEnding('/update'));
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testBasicOffsetTrialRequiresHealthyDurableCreateIntentBeforeSending(): void
    {
        $ledger = $this->ledger(function ($stream): bool {
            foreach (glob($this->directory . '/*/ledger.json.writing') ?: [] as $file) {
                $json = file_get_contents($file);
                if (!is_string($json) || str_contains($json, '"create_subscription"')) {
                    return false;
                }
            }
            return true;
        });
        $result = $this->runCase('immediate-oob-trial-basic-offset', $ledger);
        self::assertSame('fail', $result['result']);
        self::assertCount(0, $this->mutationsEnding('/subscriptions/create'));
        self::assertCount(3, $this->http->mutations());
        self::assertCount(0, $this->mutationsEnding('/cancel'));
    }

    public function testBasicOffsetTrialOverflowClockFailsBeforeCatalogueOrAuthentication(): void
    {
        $result = $this->runCase('immediate-oob-trial-basic-offset', clock: static fn (): int => PHP_INT_MAX);
        self::assertSame('fail', $result['result']);
        self::assertSame([], $this->http->requests);
    }

    public function testBasicOffsetTrialBadItemCannotUseGenericCleanupCancellation(): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface
            => str_ends_with($request->getUri()->getPath(), '/items')
                ? BillingScheduleProvider::response(['items' => [['id' => 'PRIVATE_ITEM',
                    'subscription_id' => 'PRIVATE_PARENT', 'quantity' => 1,
                    'price' => ['id' => 'PRIVATE_PRICE', 'currency' => 'EUR']]], 'page_after' => null]) : null;
        $result = $this->runCase('immediate-oob-trial-basic-offset');
        self::assertSame('fail', $result['result']);
        self::assertCount(0, $this->mutationsEnding('/cancel'));
        self::assertCount(4, $this->http->mutations());
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testBasicOffsetCaseUsesExistingFixedCommandAndOrganizationProfile(): void
    {
        $result = BillingScheduleCommand::run(
            ['environment' => 'sandbox', 'context' => 'organization',
            'credentials-file' => $this->directory . '/credentials', 'state-dir' => $this->directory,
            'case' => 'immediate-oob-trial-basic-offset'],
            $this->http,
            static fn (): int => 1830297600,
            static fn (int $s) => null
        );
        self::assertSame('pass', $result['result']);
        self::assertSame('immediate-oob-trial-basic-offset', $result['case']);
        self::assertSame('organization', $result['context']);
        self::assertSame('fixture_account', $this->http->requests[0]->getHeaderLine('x-login-as'));
        self::assertSame('/api/v1/account', $this->http->requests[1]->getUri()->getPath());
        self::assertSame('', $this->http->requests[2]->getHeaderLine('x-login-as'));
        self::assertSame(
            '2028-01-03T00:00:00+0000',
            BillingScheduleProvider::body($this->mutationsEnding('/subscriptions/create')[0])['trial_ends_at']
        );
        self::assertSame([], $result['diagnostics']);
    }

    public function testTrialOnlyUsesVerifiedCollectorAndNeverCreatesInstrumentOrPayment(): void
    {
        $result = $this->runCase('checkout-trial');
        self::assertSame('pass', $result['result']);
        self::assertIsArray($result['observations']);
        self::assertTrue($result['observations']['trial_observed_without_payment_action']);
        $creates = $this->mutationsEnding('/subscriptions/create');
        self::assertCount(1, $creates);
        $body = BillingScheduleProvider::body($creates[0]);
        self::assertSame('CHARGE_ON_CHECKOUT', $body['collection_method']);
        self::assertSame('fixture_account', $body['linked_payment_account_id']);
        self::assertSame('fixture_entity', $body['legal_entity_id']);
        self::assertArrayHasKey('trial_ends_at', $body);
        self::assertArrayNotHasKey('payment_options', $body);
        self::assertArrayNotHasKey('payment_source_id', $body);
        foreach ($this->http->requests as $request) {
            self::assertStringNotContainsString('/pa/', $request->getUri()->getPath());
            self::assertStringNotContainsString('checkouts', $request->getUri()->getPath());
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function activeCancelCases(): iterable
    {
        yield 'all' => ['cancel-all', 'ALL'];
        yield 'prorated' => ['cancel-prorated', 'PRORATED'];
    }

    #[DataProvider('activeCancelCases')]
    public function testCancelModesUseSeparateFreshActionAndDoNotClaimRefund(string $case, string $mode): void
    {
        $result = $this->runCase($case);
        $cancel = $this->mutationsEnding('/cancel');
        self::assertCount(1, $cancel);
        self::assertSame($mode, BillingScheduleProvider::body($cancel[0])['proration_behavior']);
        self::assertIsArray($result['steps']);
        self::assertSame('pass', $result['steps']['active_observed']);
        self::assertTrue($result['funds_movement_unverified']);
    }

    public function testIgnoredFlagClearRemainsPendingAndStillCancelsOwnedSubscription(): void
    {
        $this->http->intercept = static function (RequestInterface $request): ?ResponseInterface {
            if (
                str_ends_with($request->getUri()->getPath(), '/update')
                && (BillingScheduleProvider::body($request)['cancel_at_period_end'] ?? null) === false
            ) {
                return BillingScheduleProvider::response(['id' => basename(dirname($request->getUri()->getPath()))]);
            }
            return null;
        };
        $result = $this->runCase('flag-seven-days');
        self::assertSame('pending', $result['result']);
        self::assertSame('flag_false_observed', $result['phase']);
        self::assertTrue($result['cancellation_verified']);
        self::assertIsArray($result['observations']);
        self::assertArrayNotHasKey('flag_false_observed', $result['observations']);
        self::assertCount(1, $this->mutationsEnding('/cancel'));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function wrongOwnership(): iterable
    {
        yield 'customer' => ['billing_customer_id', 'other_customer'];
        yield 'entity' => ['legal_entity_id', 'other_entity'];
        yield 'currency' => ['currency', 'USD'];
        yield 'collection' => ['collection_method', 'AUTO_CHARGE'];
        yield 'marker' => ['metadata', ['sdk_validation_run' => 'other_run']];
        yield 'instrument' => ['payment_source_id', 'unexpected_source'];
    }

    #[DataProvider('wrongOwnership')]
    public function testWrongOwnershipStopsUpdatesCancellationAndArchives(string $field, mixed $value): void
    {
        $this->http->intercept = static function (RequestInterface $request, BillingScheduleProvider $http)
 use ($field, $value): ?ResponseInterface {
            if (
                $request->getMethod() === 'GET' && str_contains($request->getUri()->getPath(), '/subscriptions/')
                && isset($http->objects[$request->getUri()->getPath()])
            ) {
                return BillingScheduleProvider::response(array_replace(
                    $http->objects[$request->getUri()->getPath()],
                    [$field => $value]
                ));
            }
                return null;
        };
        $result = $this->runCase('flag-one-day');
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(4, $this->http->mutations());
    }

    /** @return iterable<string,array{string}> */
    public static function ambiguousRoutes(): iterable
    {
        yield 'subscription create' => ['/subscriptions/create'];
        yield 'update' => ['/update'];
        yield 'cancel' => ['/cancel'];
    }

    #[DataProvider('ambiguousRoutes')]
    public function testAmbiguousOutcomeStopsAllLaterWritesWithoutReplay(string $suffix): void
    {
        $fired = false;
        $this->http->intercept = static function (RequestInterface $request)
 use ($suffix, &$fired): ?ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), $suffix)) {
                self::assertFalse($fired);
                $fired = true;
                return new Response(500, [], '{"code":"internal_error","source":"private_id",'
                    . '"message":"PRIVATE_PAYLOAD"}');
            }
            if ($fired && $request->getMethod() === 'POST') {
                self::fail('A later mutation followed an ambiguous outcome.');
            }
            return null;
        };
        $result = $this->runCase('clear-all-pending');
        self::assertTrue($fired);
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['ambiguous_mutation']);
        self::assertSame(['writes' => 'skipped_ambiguous'], $result['cleanup']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testAcceptedCancelWithoutCancelledReadbackNeverReplaysOrArchives(): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface
            => str_ends_with($request->getUri()->getPath(), '/cancel')
                ? BillingScheduleProvider::response(['id' => basename(dirname($request->getUri()->getPath()))]) : null;
        $result = $this->runCase('cancel-all');
        self::assertSame('fail', $result['result']);
        self::assertFalse($result['cancellation_verified']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(1, $this->mutationsEnding('/cancel'));
        self::assertCount(1, $this->mutationsEnding('/update')); // activation only; no catalogue archive
    }

    public function testPersistenceFailureAfterCreationPreventsEveryMutation(): void
    {
        $healthy = true;
        $ledger = $this->ledger(static function ($stream) use (&$healthy): bool {
            return $healthy;
        });
        $healthy = false;
        $result = $this->runCase('clear-all-pending', $ledger);
        self::assertSame('fail', $result['result']);
        self::assertSame([], $this->http->requests);
    }

    public function testContextIncludesConfiguredCollectorAndMismatchFailsBeforeAnyNetwork(): void
    {
        $ledger = RunLedger::create(
            BillingScheduleEvidence::contextFingerprint($this->session, 'another_account'),
            $this->directory
        );
        try {
            $this->runCase('flag-one-day', $ledger);
            self::fail('Wrong owner context accepted.');
        } catch (RuntimeException) {
            self::assertSame([], $this->http->requests);
        }
    }

    public function testDefaultObservationBoundIsThirtySecondsAndGetOnly(): void
    {
        $this->http->intercept = static function (RequestInterface $request): ?ResponseInterface {
            if (
                str_ends_with($request->getUri()->getPath(), '/update')
                && (BillingScheduleProvider::body($request)['cancel_at_period_end'] ?? null) === false
            ) {
                return BillingScheduleProvider::response(['id' => basename(dirname($request->getUri()->getPath()))]);
            }
            return null;
        };
        $waits = [];
        $result = BillingScheduleEvidence::execute(
            $this->session,
            $this->ledger(),
            'flag-seven-days',
            'fixture_entity',
            'fixture_account',
            static fn (): int => 1830297600,
            static function (int $seconds) use (&$waits): void {
                $waits[] = $seconds;
            }
        );
        self::assertSame([1, 2, 4, 8, 8, 7], $waits);
        self::assertSame('pending', $result['result']);
        self::assertTrue($result['cancellation_verified']);
    }

    /** @return list<RequestInterface> */
    private function mutationsEnding(string $suffix): array
    {
        return array_values(array_filter($this->http->mutations(), static fn (RequestInterface $request): bool
            => str_ends_with($request->getUri()->getPath(), $suffix)));
    }

    public function testCommandVerifiesAccountInSeparateLoginBeforeOrganizationBillingWrites(): void
    {
        $result = BillingScheduleCommand::run(['environment' => 'sandbox', 'context' => 'organization',
            'credentials-file' => $this->directory . '/credentials', 'state-dir' => $this->directory,
            'case' => 'checkout-trial'], $this->http, static fn (): int => 1830297600, static fn (int $s) => null);
        self::assertSame('pass', $result['result']);
        self::assertSame('fixture_account', $this->http->requests[0]->getHeaderLine('x-login-as'));
        self::assertSame('/api/v1/account', $this->http->requests[1]->getUri()->getPath());
        self::assertSame('', $this->http->requests[2]->getHeaderLine('x-login-as'));
        self::assertSame('organization', $result['context']);
        self::assertSame([], $result['diagnostics']);
    }

    /** @return iterable<string,array{string}> */
    public static function finiteCases(): iterable
    {
        yield 'duration' => ['finite-duration'];
        yield 'absolute end' => ['finite-ends'];
        yield 'remaining duration' => ['finite-remaining'];
    }

    #[DataProvider('finiteCases')]
    public function testFiniteControlsUseExactSeparatePayloadAndNeverActivate(string $case): void
    {
        $result = $this->runCase($case);
        $updates = $this->mutationsEnding('/update');
        self::assertCount(3, $updates); // one schedule request plus Price and Product archives
        $body = BillingScheduleProvider::body($updates[0]);
        unset($body['request_id']);
        if ($case === 'finite-duration') {
            self::assertSame(['duration' => ['period' => 7, 'period_unit' => 'DAY']], $body);
        } elseif ($case === 'finite-ends') {
            self::assertSame(['ends_at' => '2028-01-03T00:00:00Z'], $body);
            $create = BillingScheduleProvider::body($this->mutationsEnding('/subscriptions/create')[0]);
            self::assertArrayNotHasKey('duration', $create);
        } else {
            self::assertSame(['duration' => null, 'ends_at' => null,
                'remaining_duration' => ['period' => 1, 'period_unit' => 'DAY']], $body);
            self::assertSame('pending', $result['result']);
            self::assertIsArray($result['observations']);
            self::assertTrue($result['observations']['remaining_timing_unverified']);
        }
        foreach ($updates as $request) {
            self::assertNotSame('NOW', BillingScheduleProvider::body($request)['starts_at'] ?? null);
        }
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function badCatalogue(): iterable
    {
        yield 'wrong exact amount' => ['unit_amount', '1.2300000000000000001'];
        yield 'wrong product relation' => ['product_id', 'unrelated_product'];
        yield 'wrong recurrence' => ['recurring', ['period' => 2, 'period_unit' => 'DAY']];
        yield 'wrong currency' => ['currency', 'USD'];
        yield 'inactive' => ['active', false];
    }

    #[DataProvider('badCatalogue')]
    public function testBadCatalogueReadbackCannotCreateSubscriptionOrArchiveUnverifiedPrice(
        string $field,
        mixed $value,
    ): void {
        $this->http->intercept = static function (RequestInterface $request, BillingScheduleProvider $http)
 use ($field, $value): ?ResponseInterface {
            if ($request->getMethod() === 'GET' && str_contains($request->getUri()->getPath(), '/prices/')) {
                return BillingScheduleProvider::response(array_replace(
                    $http->objects[$request->getUri()->getPath()],
                    [$field => $value]
                ));
            }
                return null;
        };
        $result = $this->runCase('flag-one-day');
        self::assertSame('fail', $result['result']);
        self::assertCount(0, $this->mutationsEnding('/subscriptions/create'));
        // An already inactive verified Price needs no archive; its verified own Product may still be archived.
        self::assertCount($field === 'active' ? 1 : 0, $this->mutationsEnding('/update'));
    }

    public function testCleanupChecksFullCatalogueAgainAndStopsAfterPartialAmbiguousArchive(): void
    {
        $failed = false;
        $this->http->intercept = static function (RequestInterface $request) use (&$failed): ?ResponseInterface {
            if (
                str_contains($request->getUri()->getPath(), '/prices/')
                && str_ends_with($request->getUri()->getPath(), '/update')
            ) {
                self::assertFalse($failed);
                $failed = true;
                return new Response(500, [], '{"code":"internal_error"}');
            }
            if ($failed && $request->getMethod() === 'POST') {
                self::fail('A Product archive followed an ambiguous Price archive.');
            }
            return null;
        };
        $result = $this->runCase('cancel-prorated');
        self::assertTrue($result['cancellation_verified']);
        self::assertTrue($result['ambiguous_mutation']);
        self::assertSame('fail', $result['result']);
        self::assertTrue($failed);
    }

    public function testFailedDurabilityWhileReservingCancellationPreventsAllNullClear(): void
    {
        $ledger = $this->ledger(function ($stream): bool {
            foreach (glob($this->directory . '/*/ledger.json.writing') ?: [] as $file) {
                $json = file_get_contents($file);
                if (!is_string($json) || str_contains($json, '"cleanup_cancel"')) {
                    return false;
                }
            }
            return true;
        });
        $result = $this->runCase('clear-all-pending', $ledger);
        self::assertSame('fail', $result['result']);
        self::assertCount(4, $this->http->mutations());
        self::assertCount(0, $this->mutationsEnding('/update'));
        self::assertCount(0, $this->mutationsEnding('/cancel'));
    }

    public function testAmbiguousClearRetainsTemporaryIndefiniteWarningAndDoesNotBlindlyCancel(): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface
            => str_ends_with($request->getUri()->getPath(), '/update')
                ? new Response(500, [], '{"code":"internal_error"}') : null;
        $result = $this->runCase('clear-all-pending');
        self::assertTrue($result['temporary_indefinite_possible']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(0, $this->mutationsEnding('/cancel'));
    }

    public function testUnexpectedUnknownStateCannotBecomePassedEvidenceOrAutomaticCancellation(): void
    {
        $this->http->intercept = static function (
            RequestInterface $request,
            BillingScheduleProvider $http,
        ): ?ResponseInterface {
            if (
                $request->getMethod() === 'GET' && str_contains($request->getUri()->getPath(), '/subscriptions/')
                && isset($http->objects[$request->getUri()->getPath()])
            ) {
                return BillingScheduleProvider::response(array_replace(
                    $http->objects[$request->getUri()->getPath()],
                    ['status' => 'PRIVATE_FUTURE_ENUM']
                ));
            }
                return null;
        };
        $result = $this->runCase('cancel-all');
        self::assertSame('fail', $result['result']);
        self::assertIsArray($result['observations']);
        self::assertSame('UNKNOWN', $result['observations']['last_status']);
        self::assertStringNotContainsString('PRIVATE_FUTURE_ENUM', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertCount(4, $this->http->mutations());
    }

    public function testTrialWrongCollectorCannotActivateOrCleanUpUnverifiedSubscription(): void
    {
        $this->http->intercept = static function (
            RequestInterface $request,
            BillingScheduleProvider $http,
        ): ?ResponseInterface {
            if (
                $request->getMethod() === 'GET' && str_contains($request->getUri()->getPath(), '/subscriptions/')
                && isset($http->objects[$request->getUri()->getPath()])
            ) {
                return BillingScheduleProvider::response(array_replace(
                    $http->objects[$request->getUri()->getPath()],
                    ['linked_payment_account_id' => 'wrong_collector']
                ));
            }
                return null;
        };
        $result = $this->runCase('checkout-trial');
        self::assertSame('fail', $result['result']);
        self::assertCount(4, $this->http->mutations());
    }

    /** @return iterable<string,array{string}> */
    public static function malformedTimestamps(): iterable
    {
        yield 'offset hour' => ['2028-01-02T00:00:00+24:00'];
        yield 'noncolon offset hour' => ['2028-01-02T00:00:00+2400'];
        yield 'offset minute' => ['2028-01-02T00:00:00+00:60'];
        yield 'calendar' => ['2028-02-30T00:00:00Z'];
        yield 'relative' => ['now'];
    }

    #[DataProvider('malformedTimestamps')]
    public function testMalformedObservedScheduleNeverBecomesAFlagComparison(string $timestamp): void
    {
        $this->http->intercept = static function (RequestInterface $request, BillingScheduleProvider $http)
 use ($timestamp): ?ResponseInterface {
            if (
                $request->getMethod() === 'GET' && str_contains($request->getUri()->getPath(), '/subscriptions/')
                && isset($http->objects[$request->getUri()->getPath()])
            ) {
                $object = $http->objects[$request->getUri()->getPath()];
                if (($object['status'] ?? null) === 'ACTIVE') {
                    return BillingScheduleProvider::response(array_replace($object, ['ends_at' => $timestamp]));
                }
            }
                return null;
        };
        $result = $this->runCase('flag-one-day');
        self::assertSame('fail', $result['result']);
        self::assertIsArray($result['observations']);
        self::assertArrayNotHasKey('before_flag_end_after_current_period', $result['observations']);
        self::assertTrue($result['cancellation_verified']);
    }

    public function testComparisonRetainsFractionalPrecisionAndDoesNotInferMembership(): void
    {
        $this->http->intercept = static function (
            RequestInterface $request,
            BillingScheduleProvider $http,
        ): ?ResponseInterface {
            if (
                $request->getMethod() === 'GET' && str_contains($request->getUri()->getPath(), '/subscriptions/')
                && isset($http->objects[$request->getUri()->getPath()])
            ) {
                $object = $http->objects[$request->getUri()->getPath()];
                if (($object['status'] ?? null) === 'ACTIVE') {
                    return BillingScheduleProvider::response(array_replace($object, [
                        'ends_at' => '2028-01-02T01:00:00.123456789012345679+01:00',
                        'current_period_ends_at' => '2028-01-02T00:00:00.123456789012345678Z',
                    ]));
                }
            }
                return null;
        };
        $result = $this->runCase('flag-seven-days');
        self::assertSame('pass', $result['result']);
        self::assertIsArray($result['observations']);
        self::assertTrue($result['observations']['before_flag_end_after_current_period']);
        self::assertFalse($result['observations']['before_flag_end_matches_current_period']);
    }

    public function testMissingMutationIdentityIsAmbiguousAndHasNoInventedCleanupTarget(): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface
            => str_ends_with($request->getUri()->getPath(), '/subscriptions/create')
                ? new Response(201, [], '{}') : null;
        $result = $this->runCase('cancel-all');
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['ambiguous_mutation']);
        self::assertCount(0, $this->mutationsEnding('/cancel'));
        self::assertCount(4, $this->http->mutations());
    }

    public function testUnauthorizedMutationIsSentOnceAndCannotStartCleanupWrites(): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface
            => str_ends_with($request->getUri()->getPath(), '/update')
                ? new Response(401, [], '{"code":"unauthorized"}') : null;
        $result = $this->runCase('clear-all-pending');
        self::assertSame(401, $result['http_status']);
        self::assertTrue($result['ambiguous_mutation']);
        self::assertCount(1, $this->mutationsEnding('/update'));
        self::assertCount(0, $this->mutationsEnding('/cancel'));
    }

    /** @return iterable<string,array{string,int,bool}> */
    public static function flagCaps(): iterable
    {
        yield 'natural end is current period' => ['flag-one-day', 1, false];
        yield 'natural end after current period' => ['flag-seven-days', 7, true];
    }

    #[DataProvider('flagCaps')]
    public function testFlagCasesKeepRecurrenceSeparateFromTheirFiniteLifetimeCap(
        string $case,
        int $period,
        bool $afterPeriod,
    ): void {
        $result = $this->runCase($case);
        $create = BillingScheduleProvider::body($this->mutationsEnding('/subscriptions/create')[0]);
        self::assertSame(['period' => $period, 'period_unit' => 'DAY'], $create['duration']);
        $price = BillingScheduleProvider::body($this->mutationsEnding('/prices/create')[0]);
        self::assertSame(['period' => 1, 'period_unit' => 'DAY'], $price['recurring']);
        self::assertSame('1.23', $price['unit_amount']);
        self::assertIsArray($result['observations']);
        self::assertSame($afterPeriod, $result['observations']['before_flag_end_after_current_period']);
        self::assertTrue($result['observations']['before_flag_duration_matches_created']);
        self::assertTrue($result['observations']['after_flag_duration_matches_created']);
        self::assertTrue($result['observations']['flag_false_observed']);
        self::assertTrue($result['observations']['indefinite_renewal_unverified']);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidInitialItems(): iterable
    {
        foreach (
            ['parent', 'price', 'currency', 'quantity', 'missing_quantity',
                'blank_id', 'duplicate', 'nonterminal'] as $kind
        ) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('invalidInitialItems')]
    public function testInitialItemMustBindTheFreshSubscriptionToTheExactPriceBeforeCaseUpdates(string $kind): void
    {
        $this->http->intercept = static function (RequestInterface $request, BillingScheduleProvider $http)
 use ($kind): ?ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/items')) {
                $parent = $http->objects[substr($request->getUri()->getPath(), 0, -6)];
                $prices = array_values(array_filter($http->objects, static fn (array $object): bool
                    => isset($object['unit_amount'])));
                $item = ['id' => 'fixture_item', 'subscription_id' => $parent['id'],
                    'quantity' => '1', 'price' => ['id' => $prices[0]['id'], 'currency' => 'EUR']];
                if ($kind === 'parent') {
                    $item['subscription_id'] = 'foreign_parent';
                } elseif ($kind === 'price') {
                    $item['price']['id'] = 'foreign_price';
                } elseif ($kind === 'currency') {
                    $item['price']['currency'] = 'USD';
                } elseif ($kind === 'quantity') {
                    $item['quantity'] = '1.0000000000000000001';
                } elseif ($kind === 'blank_id') {
                    $item['id'] = '   ';
                } elseif ($kind === 'missing_quantity') {
                    unset($item['quantity']);
                }
                $page = ['items' => $kind === 'duplicate' ? [$item, $item] : [$item],
                    'page_after' => $kind === 'nonterminal' ? 'private_bookmark' : null];
                return new Response(200, [], (new \Jojeringnax\Airwallex\Internal\Json\JsonCodec())
                    ->encode($page, ['/items/*/quantity']));
            }
                return null;
        };
        $result = $this->runCase('flag-seven-days');
        self::assertSame('fail', $result['result']);
        self::assertSame('initial_item', $result['phase']);
        self::assertTrue($result['cancellation_verified']);
        self::assertCount(1, $this->mutationsEnding('/cancel')); // separately verified own-resource cleanup only
        foreach ($this->mutationsEnding('/update') as $request) {
            self::assertStringNotContainsString('/subscriptions/', $request->getUri()->getPath());
        }
        self::assertStringNotContainsString('foreign', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private_bookmark', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testActiveRemainingRequestOmitsAllClearingFieldsAndDoesNotRequireAnEchoOrExactMapping(): void
    {
        $this->http->intercept = static function (
            RequestInterface $request,
            BillingScheduleProvider $http,
        ): ?ResponseInterface {
            if ($request->getMethod() === 'POST' && str_ends_with($request->getUri()->getPath(), '/update')) {
                $body = BillingScheduleProvider::body($request);
                if (isset($body['remaining_duration'])) {
                    $base = dirname($request->getUri()->getPath());
                    self::assertSame('ACTIVE', $http->objects[$base]['status']);
                    self::assertSame(['period' => 7, 'period_unit' => 'DAY'], $http->objects[$base]['duration']);
                    self::assertIsString($body['request_id']);
                    unset($body['request_id']);
                    self::assertSame(['remaining_duration' => ['period' => 1, 'period_unit' => 'DAY']], $body);
                    // Deliberately not a one-day mapping: the harness only observes a still-bounded end.
                    $http->objects[$base]['ends_at'] = '2028-01-03T00:00:00Z';
                    return BillingScheduleProvider::response(['id' => $http->objects[$base]['id']]);
                }
            }
                return null;
        };
        $result = $this->runCase('remaining-active-only');
        self::assertSame('pending', $result['result']);
        self::assertSame('request_and_visible_fields_only', $result['reason']);
        self::assertIsArray($result['observations']);
        self::assertTrue($result['observations']['remaining_visible_finite_end']);
        self::assertTrue($result['observations']['remaining_timing_unverified']);
        self::assertTrue($result['cancellation_verified']);
    }

    public function testFiniteRestoreUsesTheExactPrivateSavedTimestampAndReservedActionBeforeSettingTrue(): void
    {
        $original = '2028-01-08T01:00:00.123456789012+01:00';
        $captured = false;
        $reserved = null;
        $this->http->intercept = function (RequestInterface $request, BillingScheduleProvider $http)
 use ($original, &$captured, &$reserved): ?ResponseInterface {
                $path = $request->getUri()->getPath();
            if (
                $request->getMethod() === 'GET' && isset($http->objects[$path])
                    && ($http->objects[$path]['status'] ?? null) === 'ACTIVE' && !$captured
            ) {
                $http->objects[$path]['ends_at'] = $original;
                $captured = true;
            }
            if (
                $request->getMethod() === 'POST' && str_contains($path, '/subscriptions/')
                    && str_ends_with($path, '/update')
            ) {
                $body = BillingScheduleProvider::body($request);
                if (($body['cancel_at_period_end'] ?? null) === true) {
                    $files = glob($this->directory . '/*/ledger.json') ?: [];
                    $state = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
                    self::assertIsArray($state);
                    $observations = $state['observations'];
                    self::assertIsArray($observations);
                    $saved = $observations['supplemental_original_end'];
                    self::assertIsArray($saved);
                    self::assertSame($original, $saved['ends_at']);
                    $actions = $state['actions'];
                    self::assertIsArray($actions);
                    $intent = $actions['restore_finite_end'];
                    self::assertIsArray($intent);
                    $reserved = $intent['request_id'];
                } elseif (($body['cancel_at_period_end'] ?? null) === false) {
                    self::assertSame($reserved, $body['request_id']);
                    unset($body['request_id']);
                    self::assertSame(['cancel_at_period_end' => false, 'ends_at' => $original], $body);
                    self::assertSame('2028-01-02T00:00:00Z', $http->objects[dirname($path)]['ends_at']);
                }
            }
                return null;
        };
        $result = $this->runCase('flag-restore-end');
        self::assertSame('pass', $result['result']);
        self::assertTrue($result['cancellation_verified']);
        self::assertIsArray($result['observations']);
        self::assertTrue($result['observations']['finite_flag_restoration_observed']);
        self::assertTrue($result['observations']['before_restore_end_after_current_period']);
        self::assertTrue($result['observations']['scheduled_restore_end_matches_current_period']);
        self::assertTrue($result['observations']['after_restore_end_after_current_period']);
        self::assertStringNotContainsString($original, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertNotNull($reserved);
    }

    /** @return iterable<string,array{string}> */
    public static function partialRestores(): iterable
    {
        foreach (['flag_only', 'end_only', 'neither'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('partialRestores')]
    public function testRestoreRequiresBothFalseAndTheOriginalFiniteEndWithoutRepeatingTheMutation(string $kind): void
    {
        $restores = 0;
        $this->http->intercept = static function (RequestInterface $request, BillingScheduleProvider $http)
 use ($kind, &$restores): ?ResponseInterface {
            if ($request->getMethod() === 'POST' && str_ends_with($request->getUri()->getPath(), '/update')) {
                $body = BillingScheduleProvider::body($request);
                if (($body['cancel_at_period_end'] ?? null) === false && isset($body['ends_at'])) {
                    ++$restores;
                    $base = dirname($request->getUri()->getPath());
                    if ($kind === 'flag_only') {
                        $http->objects[$base]['cancel_at_period_end'] = false;
                    } elseif ($kind === 'end_only') {
                        $http->objects[$base]['ends_at'] = $body['ends_at'];
                    }
                    return BillingScheduleProvider::response(['id' => $http->objects[$base]['id']]);
                }
            }
                return null;
        };
        $result = $this->runCase('flag-restore-end');
        self::assertSame('pending', $result['result']);
        self::assertSame('restored_flag_and_end', $result['phase']);
        self::assertSame(1, $restores);
        self::assertTrue($result['cancellation_verified']);
        self::assertIsArray($result['observations']);
        self::assertArrayNotHasKey('finite_flag_restoration_observed', $result['observations']);
    }

    /** @return iterable<string,array{string}> */
    public static function unsafeOriginalEnds(): iterable
    {
        yield 'past end' => ['2027-12-31T00:00:00Z'];
        yield 'exceeds safety bound' => ['2028-02-01T00:00:00Z'];
        yield 'fraction beyond safety bound' => ['2028-01-09T00:00:00.000000000001Z'];
        yield 'invalid offset' => ['2028-01-08T00:00:00+24:00'];
        yield 'natural final period' => ['2028-01-02T00:00:00Z'];
    }

    #[DataProvider('unsafeOriginalEnds')]
    public function testRestoreCannotSetTheEndingFlagWithoutAnEstablishedSafeNonfinalOriginalEnd(string $end): void
    {
        $this->http->intercept = static function (RequestInterface $request, BillingScheduleProvider $http)
 use ($end): ?ResponseInterface {
                $path = $request->getUri()->getPath();
            if (
                $request->getMethod() === 'GET' && isset($http->objects[$path])
                    && ($http->objects[$path]['status'] ?? null) === 'ACTIVE'
            ) {
                return BillingScheduleProvider::response(array_replace($http->objects[$path], ['ends_at' => $end]));
            }
                return null;
        };
        $result = $this->runCase('flag-restore-end');
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['cancellation_verified']);
        foreach ($this->mutationsEnding('/update') as $request) {
            self::assertArrayNotHasKey('cancel_at_period_end', BillingScheduleProvider::body($request));
        }
    }

    public function testUnboundedRemainingReadbackIsPendingAndCancelsBeforeAnyArchive(): void
    {
        $this->http->intercept = static function (
            RequestInterface $request,
            BillingScheduleProvider $http,
        ): ?ResponseInterface {
            if ($request->getMethod() === 'POST' && str_ends_with($request->getUri()->getPath(), '/update')) {
                $body = BillingScheduleProvider::body($request);
                if (isset($body['remaining_duration'])) {
                    $base = dirname($request->getUri()->getPath());
                    unset($http->objects[$base]['duration'], $http->objects[$base]['ends_at']);
                    return BillingScheduleProvider::response(['id' => $http->objects[$base]['id']]);
                }
            }
                return null;
        };
        $result = $this->runCase('remaining-active-only');
        self::assertSame('pending', $result['result']);
        self::assertSame('active_remaining_finite_observed', $result['phase']);
        self::assertTrue($result['cancellation_verified']);
        self::assertFalse($result['temporary_indefinite_possible']);
        self::assertIsArray($result['observations']);
        self::assertArrayNotHasKey('remaining_visible_finite_end', $result['observations']);
    }

    public function testFailurePersistingTheFiniteRestoreIntentPreventsSchedulingTrue(): void
    {
        $ledger = $this->ledger(function ($stream): bool {
            foreach (glob($this->directory . '/*/ledger.json.writing') ?: [] as $file) {
                $json = file_get_contents($file);
                if (!is_string($json) || str_contains($json, '"restore_finite_end"')) {
                    return false;
                }
            }
            return true;
        });
        $result = $this->runCase('flag-restore-end', $ledger);
        self::assertSame('fail', $result['result']);
        self::assertSame('prepare_finite_restore', $result['phase']);
        self::assertTrue($result['reconciliation_required']);
        foreach ($this->mutationsEnding('/update') as $request) {
            self::assertArrayNotHasKey('cancel_at_period_end', BillingScheduleProvider::body($request));
        }
        self::assertCount(0, $this->mutationsEnding('/cancel'));
    }

    /** @return iterable<string,array{string}> */
    public static function supplementalCases(): iterable
    {
        yield 'remaining-only' => ['remaining-active-only'];
        yield 'restore finite end' => ['flag-restore-end'];
    }

    #[DataProvider('supplementalCases')]
    public function testSupplementalRejectedSentMutationRetainsResourcesWithoutAReplayOrCleanup(string $case): void
    {
        $rejected = false;
        $this->http->intercept = static function (RequestInterface $request) use (&$rejected): ?ResponseInterface {
            $path = $request->getUri()->getPath();
            if ($request->getMethod() === 'POST' && str_ends_with($path, '/update')) {
                $body = BillingScheduleProvider::body($request);
                if (
                    isset($body['remaining_duration'])
                    || (($body['cancel_at_period_end'] ?? null) === false && isset($body['ends_at']))
                ) {
                    self::assertFalse($rejected);
                    $rejected = true;
                    return new Response(400, [], '{"code":"validation_error","source":"ends_at",'
                        . '"message":"PRIVATE_PROVIDER_TEXT"}');
                }
            }
            if ($rejected && $request->getMethod() === 'POST') {
                self::fail('A mutation followed the rejected uncertain action.');
            }
            return null;
        };
        $result = $this->runCase($case);
        self::assertTrue($rejected);
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['ambiguous_mutation']);
        self::assertSame(['writes' => 'skipped_ambiguous'], $result['cleanup']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(0, $this->mutationsEnding('/cancel'));
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }
}
