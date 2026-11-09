<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingCatalogueCleanup;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingScheduleEvidence;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\ScheduleCleanup;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\ScheduleCleanupCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class ScheduleCleanupTest extends TestCase
{
    private string $directory;
    private string $prior;
    private string $marker;
    private BillingScheduleProvider $http;
    private SandboxSession $session;
    private const int NOW = 1830297600;
    private const string CUSTOMER = 'PRIVATE_CUSTOMER';
    private const string PRODUCT = 'PRIVATE_PRODUCT';
    private const string PRICE = 'PRIVATE_PRICE';
    private const string SUB = 'PRIVATE_SUBSCRIPTION';
    private const string ITEM = 'PRIVATE_ITEM';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sdk-schedule-cleanup-fixture-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/credentials', "AIRWALLEX_CLIENT_ID=PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=PRIVATE_KEY\nAIRWALLEX_ACCOUNT_ID=fixture_account\n");
        $this->http = new BillingScheduleProvider();
        $this->session = new SandboxSession(
            Configuration::load('sandbox', $this->directory . '/credentials'),
            'organization',
            $this->http,
            static fn (): int => self::NOW
        );
        $fingerprint = BillingScheduleEvidence::contextFingerprint($this->session, 'fixture_account');
        $original = RunLedger::create($fingerprint, $this->directory);
        foreach (['create_customer', 'create_product', 'create_price', 'create_subscription'] as $action) {
            $original->requestId($action, ['synthetic_intent' => $action], $fingerprint);
        }
        foreach (
            ['customers' => self::CUSTOMER, 'products' => self::PRODUCT, 'prices' => self::PRICE,
            'subscriptions' => self::SUB] as $kind => $id
        ) {
            $original->recordCreated($kind, $id);
        }
        $original->recordObservation('initial_item', ['subscription_id' => self::SUB,
            'item_id' => self::ITEM, 'price_id' => self::PRICE], $fingerprint);
        $this->marker = $original->runId();
        $this->prior = $this->directory . '/' . $original->runId() . '/ledger.json';
        $metadata = ['sdk_validation_run' => $this->marker, 'sdk_validation_case' => 'finite-remaining'];
        $this->http->objects = [
            $this->path('customers') => ['id' => self::CUSTOMER, 'name' => $this->marker,
                'email' => $this->marker . '@example.test', 'default_billing_currency' => 'EUR',
                'default_legal_entity_id' => 'fixture_entity', 'metadata' => $metadata],
            $this->path('products') => ['id' => self::PRODUCT, 'name' => $this->marker,
                'active' => true, 'metadata' => $metadata],
            $this->path('prices') => ['id' => self::PRICE, 'product_id' => self::PRODUCT, 'currency' => 'EUR',
                'pricing_model' => 'PER_UNIT', 'billing_type' => 'IN_ADVANCE', 'unit_amount' => '1.2300',
                'recurring' => ['period' => 1, 'period_unit' => 'DAY'], 'active' => true, 'metadata' => $metadata],
            $this->path('subscriptions') => ['id' => self::SUB, 'billing_customer_id' => self::CUSTOMER,
                'legal_entity_id' => 'fixture_entity', 'currency' => 'EUR', 'collection_method' => 'OUT_OF_BAND',
                'starts_at' => '2028-01-02T00:00:00Z', 'days_until_due' => 30, 'status' => 'PENDING',
                'duration' => null, 'ends_at' => null, 'metadata' => $metadata],
            $this->path('items') => ['id' => self::ITEM, 'subscription_id' => self::SUB, 'quantity' => '1.00',
                'price' => ['id' => self::PRICE, 'currency' => 'EUR']],
        ];
    }

    private function path(string $kind): string
    {
        return '/api/v1/billing/' . match ($kind) {
            'customers' => 'billing_customers/' . self::CUSTOMER,
            'products' => 'products/' . self::PRODUCT, 'prices' => 'prices/' . self::PRICE,
            'items' => 'subscriptions/' . self::SUB . '/items/' . self::ITEM,
            default => 'subscriptions/' . self::SUB,
        };
    }

    protected function tearDown(): void
    {
        self::remove($this->directory);
    }

    private static function remove(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            is_dir($path) && !is_link($path) ? self::remove($path) : unlink($path);
        }
        rmdir($directory);
    }

    /** @return array<string,mixed> */
    private function options(string $mode = 'verify-pending-and-cancel'): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'prior-ledger' => $this->prior, 'state-dir' => $this->directory, 'mode' => $mode];
    }

    /** @return array<string,mixed> */
    private function runCleanup(string $mode = 'verify-pending-and-cancel'): array
    {
        return ScheduleCleanupCommand::run(
            $this->options($mode),
            $this->http,
            static fn (): int => self::NOW,
            static fn (int $seconds) => null
        );
    }

    /** @return array<array-key,mixed> */
    private function priorState(): array
    {
        $raw = file_get_contents($this->prior);
        self::assertIsString($raw);
        $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        return $state;
    }

    /** @param array<array-key,mixed> $state */
    private function writePrior(array $state): void
    {
        file_put_contents($this->prior, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($this->prior, 0600);
    }

    public function testOneFuturePendingCancellationThenOwnedArchivesWithDurableNewIdentities(): void
    {
        $before = file_get_contents($this->prior);
        self::assertIsString($before);
        $this->http->intercept = function (RequestInterface $request): ?ResponseInterface {
            if ($request->getMethod() === 'POST' && !str_ends_with($request->getUri()->getPath(), '/login')) {
                $files = array_values(array_filter(
                    glob($this->directory . '/sdk-validation-*/ledger.json') ?: [],
                    fn (string $path): bool => $path !== $this->prior
                ));
                self::assertCount(1, $files);
                $raw = file_get_contents($files[0]);
                self::assertIsString($raw);
                $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($state);
                self::assertIsArray($state['actions']);
                $body = BillingScheduleProvider::body($request);
                $matching = array_filter($state['actions'], static fn (mixed $action): bool
                    => is_array($action) && ($action['request_id'] ?? null) === ($body['request_id'] ?? null));
                self::assertCount(1, $matching, 'The new action must be durable before sending.');
                $action = array_key_first($matching);
                self::assertIsString($action);
                $intent = self::action($matching[$action]);
                unset($body['request_id']);
                self::assertSame(hash('sha256', json_encode([
                    'prior_ledger_sha256' => hash_file('sha256', $this->prior),
                    'original_run_fingerprint' => hash('sha256', $this->marker), 'operation' => $action,
                    'resource_id' => basename(dirname($request->getUri()->getPath())), 'body' => $body,
                ], JSON_THROW_ON_ERROR)), $intent['body_hash']);
            }
            return null;
        };
        $safe = $this->runCleanup();
        self::assertSame('pass', $safe['result']);
        self::assertTrue($safe['subscription_cancel_sent']);
        self::assertTrue($safe['subscription_cancelled_verified']);
        self::assertTrue($safe['pending_future_start_verified']);
        self::assertTrue($safe['price_archived']);
        self::assertTrue($safe['product_archived']);
        self::assertTrue($safe['prior_ledger_unchanged']);
        self::assertFalse($safe['global_subscription_absence_proven']);
        self::assertFalse($safe['prior_action_reconciliation_complete']);
        self::assertSame($before, file_get_contents($this->prior));
        $writes = $this->http->mutations();
        self::assertCount(3, $writes);
        self::assertStringEndsWith('/cancel', $writes[0]->getUri()->getPath());
        self::assertSame('NONE', BillingScheduleProvider::body($writes[0])['proration_behavior']);
        $ids = [];
        foreach ($writes as $index => $write) {
            $body = BillingScheduleProvider::body($write);
            self::assertIsString($body['request_id']);
            $ids[] = $body['request_id'];
            self::assertSame(
                $index === 0 ? ['proration_behavior', 'request_id'] : ['active', 'request_id'],
                array_keys($body)
            );
            self::assertSame('2026-08-21', $write->getHeaderLine('x-api-version'));
            self::assertFalse($write->hasHeader('x-on-behalf-of'));
        }
        self::assertCount(3, array_unique($ids));
        $original = $this->priorState();
        self::assertIsArray($original['actions']);
        foreach ($original['actions'] as $action) {
            self::assertIsArray($action);
            self::assertNotContains($action['request_id'], $ids);
        }
        $this->safe($safe);
    }

    private function catalogueOnly(string $case = 'checkout-trial'): void
    {
        $state = $this->priorState();
        self::assertIsArray($state['resources']);
        unset($state['resources']['subscriptions'], $state['observations']);
        $this->writePrior($state);
        foreach (['customers', 'products', 'prices'] as $kind) {
            $this->http->objects[$this->path($kind)]['metadata'] = ['sdk_validation_run' => $this->marker,
                'sdk_validation_case' => $case];
        }
        $this->http->objects[$this->path('prices')]['recurring'] = ['period' => 1, 'period_unit' => 'MONTH'];
    }

    #[DataProvider('monthlyCases')]
    public function testMonthlyTrialCatalogueOnlyRejectsDailyPriceBeforeAnyArchive(string $case): void
    {
        $this->catalogueOnly($case);
        $this->http->objects[$this->path('prices')]['recurring'] = ['period' => 1, 'period_unit' => 'DAY'];
        $options = $this->options();
        unset($options['mode']);
        $safe = ScheduleCleanupCommand::run($options, $this->http, static fn (): int => self::NOW);
        self::assertSame('fail', $safe['result']);
        self::assertCount(0, $this->http->mutations());
        $this->safe($safe);
    }

    /** @return iterable<string,array{string}> */
    public static function monthlyCases(): iterable
    {
        yield 'checkout trial' => ['checkout-trial'];
        yield 'immediate out-of-band trial' => ['immediate-oob-trial'];
        yield 'basic-offset out-of-band trial' => ['immediate-oob-trial-basic-offset'];
    }

    #[DataProvider('monthlyCases')]
    public function testFailedCreateCatalogueOnlyNeverLooksForAnUnrecordedSubscription(string $case): void
    {
        $this->catalogueOnly($case);
        $options = $this->options();
        unset($options['mode']);
        $safe = ScheduleCleanupCommand::run($options, $this->http, static fn (): int => self::NOW);
        self::assertSame('pass', $safe['result']);
        self::assertFalse($safe['subscription_recorded']);
        self::assertFalse($safe['global_subscription_absence_proven']);
        self::assertFalse($safe['prior_action_reconciliation_complete']);
        self::assertCount(2, $this->http->mutations());
        foreach ($this->http->requests as $request) {
            self::assertStringNotContainsString('/subscriptions', $request->getUri()->getPath());
        }
        $this->safe($safe);
    }

    public function testDefaultModeRejectsRecordedSubscriptionBeforeAuthentication(): void
    {
        $safe = $this->runCleanup('catalogue-only');
        self::assertSame('configuration', $safe['phase']);
        self::assertSame([], $this->http->requests);
    }

    public function testAlreadyCancelledVerifiesStableScopeAndDoesNotRepeatAnOldCancel(): void
    {
        $this->http->objects[$this->path('subscriptions')]['status'] = 'CANCELLED';
        $this->http->objects[$this->path('subscriptions')]['starts_at'] = null;
        $state = $this->priorState();
        self::assertIsArray($state['actions']);
        $state['actions']['cleanup_cancel'] = $state['actions']['create_subscription'];
        $this->writePrior($state);
        unset($this->http->objects[$this->path('items')]);
        $safe = $this->runCleanup();
        self::assertSame('pass', $safe['result']);
        self::assertFalse($safe['subscription_cancel_sent']);
        self::assertTrue($safe['subscription_cancelled_verified']);
        self::assertCount(2, $this->http->mutations());
    }

    /** @return iterable<string,array{string,string,mixed}> */
    public static function wrongObjects(): iterable
    {
        yield 'Customer currency' => ['customers', 'default_billing_currency', 'USD'];
        yield 'Customer entity' => ['customers', 'default_legal_entity_id', 'PRIVATE_OTHER'];
        yield 'Customer identity' => ['customers', 'id', 'PRIVATE_OTHER'];
        yield 'Product marker' => ['products', 'metadata', ['sdk_validation_run' => 'PRIVATE_OTHER']];
        yield 'Price Product' => ['prices', 'product_id', 'PRIVATE_OTHER'];
        yield 'Price amount' => ['prices', 'unit_amount', '1.2300000001'];
        yield 'Price currency' => ['prices', 'currency', 'USD'];
        yield 'Price recurrence' => ['prices', 'recurring', ['period' => 2, 'period_unit' => 'DAY']];
        yield 'Price boolean' => ['prices', 'active', null];
        yield 'Subscription customer' => ['subscriptions', 'billing_customer_id', 'PRIVATE_OTHER'];
        yield 'Subscription currency' => ['subscriptions', 'currency', 'USD'];
        yield 'Subscription entity' => ['subscriptions', 'legal_entity_id', 'PRIVATE_OTHER'];
        yield 'Subscription collection' => ['subscriptions', 'collection_method', 'CHARGE_ON_CHECKOUT'];
        yield 'Subscription instrument' => ['subscriptions', 'payment_source_id', 'PRIVATE_OTHER'];
        yield 'Item parent' => ['items', 'subscription_id', 'PRIVATE_OTHER'];
        yield 'Item Price' => ['items', 'price', ['id' => 'PRIVATE_OTHER', 'currency' => 'EUR']];
        yield 'Item quantity' => ['items', 'quantity', '1.00001'];
    }

    #[DataProvider('wrongObjects')]
    public function testAllOwnershipAndExactConfigurationChecksPrecedeEveryWrite(
        string $kind,
        string $field,
        mixed $value,
    ): void {
        $this->http->objects[$this->path($kind)][$field] = $value;
        $safe = $this->runCleanup();
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $this->http->mutations());
        $this->safe($safe);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function badStates(): iterable
    {
        foreach (['ACTIVE', 'IN_TRIAL', 'UNPAID', 'PRIVATE_UNKNOWN'] as $status) {
            yield $status => ['status', $status];
        }
        foreach (
            ['2027-01-01T00:00:00Z', 'now', '+1 hour', '2028-02-30T00:00:00Z',
            '2028-01-02T00:00:00+24:00', null] as $i => $date
        ) {
            yield 'invalid future ' . $i => ['starts_at', $date];
        }
    }

    #[DataProvider('badStates')]
    public function testOnlyAnAbsoluteFuturePendingStateCanBeCancelled(string $field, mixed $value): void
    {
        $this->http->objects[$this->path('subscriptions')][$field] = $value;
        $safe = $this->runCleanup();
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $this->http->mutations());
        $this->safe($safe);
    }

    public function testPriorCancellationIntentDoesNotProveItWasUnsent(): void
    {
        $state = $this->priorState();
        self::assertIsArray($state['actions']);
        $state['actions']['cleanup_cancel'] = $state['actions']['create_subscription'];
        $this->writePrior($state);
        self::assertSame('fail', $this->runCleanup()['result']);
        self::assertSame([], $this->http->mutations());
    }

    public function testFailedIntentDurabilityPreventsCancellationAndEveryArchive(): void
    {
        $healthy = true;
        $safe = ScheduleCleanupCommand::run(
            $this->options(),
            $this->http,
            static fn (): int => self::NOW,
            static fn (int $seconds) => null,
            static function (string $fingerprint, string $directory) use (&$healthy): RunLedger {
                $ledger = RunLedger::create(
                    $fingerprint,
                    $directory,
                    synchronize: static function ($stream) use (&$healthy): bool {
                        return $healthy && fsync($stream);
                    }
                );
                $healthy = false;
                return $ledger;
            }
        );
        self::assertSame('fail', $safe['result']);
        self::assertFalse($safe['subscription_cancel_sent']);
        self::assertFalse($safe['ambiguous_mutation']);
        self::assertSame([], $this->http->mutations());
    }

    /** @return iterable<string,array{string}> */
    public static function sentFailures(): iterable
    {
        yield 'transport cancellation' => ['transport'];
        yield '400 cancellation' => ['400'];
        yield '500 archive' => ['archive'];
        yield 'wrong cancellation acknowledgement' => ['wrong_ack'];
    }

    #[DataProvider('sentFailures')]
    public function testFailedSentMutationStopsWithoutAnyReplayOrSubstituteAction(string $fault): void
    {
        $this->http->intercept = static function (RequestInterface $request) use ($fault): ?ResponseInterface {
            $path = $request->getUri()->getPath();
            if (str_ends_with($path, '/cancel') && $fault !== 'archive') {
                if ($fault === 'transport') {
                    throw new RuntimeException('PRIVATE_PROVIDER_AFTER_SEND');
                }
                return new Response($fault === '400' ? 400 : 200, [], $fault === '400'
                    ? '{"code":"validation_error","source":"PRIVATE_SOURCE","message":"PRIVATE_MESSAGE"}'
                    : '{"id":"PRIVATE_WRONG_ACK"}');
            }
            if (str_contains($path, '/prices/') && str_ends_with($path, '/update') && $fault === 'archive') {
                return new Response(500, [], '{"code":"PRIVATE_CODE","message":"PRIVATE_MESSAGE"}');
            }
            return null;
        };
        $safe = $this->runCleanup();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['ambiguous_mutation']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertCount($fault === 'archive' ? 2 : 1, $this->http->mutations());
        $this->safe($safe);
    }

    public function testUnconfirmedCancellationGetsSevenReadsButNeverAnotherCancelOrAnyArchive(): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface
            => str_ends_with($request->getUri()->getPath(), '/cancel')
                ? BillingScheduleProvider::response(['id' => self::SUB]) : null;
        $delays = [];
        $safe = ScheduleCleanupCommand::run(
            $this->options(),
            $this->http,
            static fn (): int => self::NOW,
            static function (int $seconds) use (&$delays): void {
                $delays[] = $seconds;
            }
        );
        self::assertSame('fail', $safe['result']);
        self::assertFalse($safe['subscription_cancelled_verified']);
        self::assertSame([1, 2, 4, 8, 8, 7], $delays);
        self::assertCount(1, $this->http->mutations());
    }

    public function testWrongCollectorContextAndInventoryFailBeforeAuthentication(): void
    {
        $state = $this->priorState();
        $state['context_fingerprint'] = BillingScheduleEvidence::contextFingerprint($this->session, 'PRIVATE_OTHER');
        $this->writePrior($state);
        $safe = $this->runCleanup();
        self::assertSame('configuration', $safe['phase']);
        self::assertSame([], $this->http->requests);
    }

    public function testOldCatalogueHelperCannotAcceptTheNewOnePriceDerivedContextLedger(): void
    {
        $caught = null;
        try {
            BillingCatalogueCleanup::load($this->prior, $this->session);
        } catch (RuntimeException $error) {
            $caught = $error;
        }
        self::assertNotNull($caught);
        self::assertSame([], $this->http->requests);
    }

    public function testPrivatePermissionsAndOriginalHashAreEnforced(): void
    {
        chmod($this->prior, 0644);
        self::assertSame('configuration', $this->runCleanup()['phase']);
        self::assertSame([], $this->http->requests);
        chmod($this->prior, 0600);
        $cleanup = ScheduleCleanup::load($this->prior, $this->session, 'fixture_account', true);
        $ledger = RunLedger::create(
            BillingScheduleEvidence::contextFingerprint($this->session, 'fixture_account'),
            $this->directory
        );
        file_put_contents($this->prior, "\n", FILE_APPEND);
        $safe = $cleanup->execute($this->session, $ledger, 'fixture_account', static fn (): int => self::NOW);
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $this->http->requests);
    }

    public function testOwnershipMismatchAfterCancelPreventsEveryCatalogueWrite(): void
    {
        $this->http->intercept = function (RequestInterface $request): ?ResponseInterface {
            if (
                $request->getMethod() === 'GET' && $request->getUri()->getPath() === $this->path('subscriptions')
                && ($this->http->objects[$this->path('subscriptions')]['status'] ?? null) === 'CANCELLED'
            ) {
                $this->http->objects[$this->path('subscriptions')]['billing_customer_id'] = 'PRIVATE_OTHER';
            }
            return null;
        };
        $safe = $this->runCleanup();
        self::assertSame('fail', $safe['result']);
        self::assertFalse($safe['subscription_cancelled_verified']);
        self::assertCount(1, $this->http->mutations());
        $this->safe($safe);
    }

    public function testPriorLedgerChangeAfterPreflightPreventsAnySentAction(): void
    {
        $this->http->intercept = function (RequestInterface $request): ?ResponseInterface {
            if ($request->getMethod() === 'GET' && $request->getUri()->getPath() === $this->path('items')) {
                file_put_contents($this->prior, "\n", FILE_APPEND);
            }
            return null;
        };
        $safe = $this->runCleanup();
        self::assertSame('fail', $safe['result']);
        self::assertFalse($safe['subscription_cancel_sent']);
        self::assertSame([], $this->http->mutations());
    }

    public function testNewlyObservedCancelledStateIsNotCancelledAgain(): void
    {
        $this->http->intercept = function (RequestInterface $request): ?ResponseInterface {
            if ($request->getMethod() === 'GET' && $request->getUri()->getPath() === $this->path('items')) {
                $this->http->objects[$this->path('subscriptions')]['status'] = 'CANCELLED';
            }
            return null;
        };
        $safe = $this->runCleanup();
        self::assertSame('pass', $safe['result']);
        self::assertFalse($safe['subscription_cancel_sent']);
        self::assertTrue($safe['subscription_cancelled_verified']);
        self::assertCount(2, $this->http->mutations());
    }

    /** @return iterable<string,array{string}> */
    public static function invalidLedgers(): iterable
    {
        foreach (
            ['version', 'source', 'source_digest', 'source_commit', 'marker', 'two_prices',
            'two_subscriptions', 'unknown_inventory', 'bad_reference', 'create_intent',
            'missing_item', 'wrong_item_parent', 'wrong_item_price'] as $fault
        ) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('invalidLedgers')]
    public function testInvalidPriorProvenanceAndShapeNeverAuthenticate(string $fault): void
    {
        $state = $this->priorState();
        self::assertIsArray($state['resources']);
        self::assertIsArray($state['source']);
        self::assertIsArray($state['actions']);
        self::assertIsArray($state['observations']);
        self::assertIsArray($state['observations']['initial_item']);
        switch ($fault) {
            case 'version':
                $state['api_version'] = 'PRIVATE_VERSION';
                break;
            case 'source':
                unset($state['source']);
                break;
            case 'source_digest':
                $state['source']['sdk_digest'] = 'PRIVATE_SOURCE';
                break;
            case 'source_commit':
                $state['source']['base_commit'] = 'PRIVATE_SOURCE';
                break;
            case 'marker':
                $state['run_id'] = 'PRIVATE_MARKER';
                break;
            case 'two_prices':
                $state['resources']['prices'] = [self::PRICE, 'PRIVATE_OTHER'];
                break;
            case 'two_subscriptions':
                $state['resources']['subscriptions'] = [self::SUB, 'PRIVATE_OTHER'];
                break;
            case 'unknown_inventory':
                $state['resources']['payment_intents'] = ['PRIVATE_OTHER'];
                break;
            case 'bad_reference':
                $state['resources']['prices'] = ['../PRIVATE_OTHER'];
                break;
            case 'create_intent':
                unset($state['actions']['create_subscription']);
                break;
            case 'missing_item':
                unset($state['observations']);
                break;
            case 'wrong_item_parent':
                $state['observations']['initial_item']['subscription_id'] = 'PRIVATE_OTHER';
                break;
            default:
                $state['observations']['initial_item']['price_id'] = 'PRIVATE_OTHER';
        }
        $this->writePrior($state);
        $safe = $this->runCleanup();
        self::assertSame('configuration', $safe['phase']);
        self::assertSame([], $this->http->requests);
        $this->safe($safe);
    }

    public function testCopiedPrivateLedgerInsideAGitCheckoutIsRejectedBeforeAuthentication(): void
    {
        file_put_contents($this->directory . '/.git', 'gitdir: /PRIVATE_UNUSED');
        self::assertSame('configuration', $this->runCleanup()['phase']);
        self::assertSame([], $this->http->requests);
    }

    public function testUnknownOptionsProductionAndModeMismatchesCannotReachAuthentication(): void
    {
        foreach (
            ['environment' => 'production', 'context' => 'account', 'sdk-root' => '/PRIVATE_ROOT',
            'mode' => 'PRIVATE_MODE', 'subscription-id' => self::SUB] as $key => $value
        ) {
            $options = $this->options();
            $options[$key] = $value;
            self::assertSame('configuration', ScheduleCleanupCommand::run($options, $this->http)['phase']);
        }
        self::assertSame([], $this->http->requests);
        $this->catalogueOnly();
        self::assertSame('configuration', $this->runCleanup()['phase']);
        self::assertSame([], $this->http->requests);
    }

    public function testInactiveTargetsNeedNoNewArchiveAndLoadedCleanupCannotBeReused(): void
    {
        $this->http->objects[$this->path('subscriptions')]['status'] = 'CANCELLED';
        $this->http->objects[$this->path('prices')]['active'] = false;
        $this->http->objects[$this->path('products')]['active'] = false;
        $cleanup = ScheduleCleanup::load($this->prior, $this->session, 'fixture_account', true);
        $ledger = RunLedger::create(
            BillingScheduleEvidence::contextFingerprint($this->session, 'fixture_account'),
            $this->directory
        );
        $safe = $cleanup->execute($this->session, $ledger, 'fixture_account', static fn (): int => self::NOW);
        self::assertSame('pass', $safe['result']);
        self::assertTrue($safe['price_already_inactive']);
        self::assertTrue($safe['product_already_inactive']);
        self::assertSame([], $this->http->mutations());
        ob_start();
        var_dump($cleanup);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('PRIVATE_', $debug);
        self::assertStringNotContainsString($this->directory, $debug);
        $requests = count($this->http->requests);
        $caught = null;
        try {
            $cleanup->execute($this->session, $ledger, 'fixture_account');
        } catch (RuntimeException $error) {
            $caught = $error;
        }
        self::assertNotNull($caught);
        self::assertCount($requests, $this->http->requests);
        $this->expectException(RuntimeException::class);
        serialize($cleanup);
    }

    public function testCliRejectsOverridesDuplicatesAndMissingArgvWithoutRawDiagnostics(): void
    {
        foreach (
            [['--sdk-root=/PRIVATE_ROOT'], ['--environment=production'],
            ['--mode=catalogue-only', '--mode=verify-pending-and-cancel']] as $extra
        ) {
            $this->cli($extra);
        }
        $this->cli(['--sdk-root=/PRIVATE_ROOT'], true);
    }

    /** @param list<string> $extra */
    private function cli(array $extra, bool $withoutArgv = false): void
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'register_argc_argv=' . ($withoutArgv ? '0' : '1'),
            dirname(__DIR__, 4) . '/tools/billing-schedule-cleanup.php', '--environment=sandbox',
            '--credentials-file=/absent-synthetic-credentials', '--prior-ledger=/absent-synthetic-ledger',
            '--state-dir=' . $this->directory, ...$extra],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        [$output, $errors] = self::pipes($pipes);
        $contents = stream_get_contents($output);
        $diagnostics = stream_get_contents($errors);
        fclose($output);
        fclose($errors);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $diagnostics);
        self::assertIsString($contents);
        $safe = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($safe);
        self::assertSame('configuration', $safe['phase']);
        self::assertSame('fail', $safe['result']);
        self::assertStringNotContainsString('PRIVATE_', $contents);
    }

    /** @return array{resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (!is_array($pipes) || !is_resource($pipes[1] ?? null) || !is_resource($pipes[2] ?? null)) {
            throw new RuntimeException('Fixture process pipes are unavailable.');
        }
        return [$pipes[1], $pipes[2]];
    }

    /** @return array{request_id:string,body_hash:string} */
    private static function action(mixed $value): array
    {
        if (
            !is_array($value) || !is_string($value['request_id'] ?? null)
            || !is_string($value['body_hash'] ?? null)
        ) {
            throw new RuntimeException('The synthetic durable action has an invalid shape.');
        }
        return ['request_id' => $value['request_id'], 'body_hash' => $value['body_hash']];
    }

    /** @param array<string,mixed> $safe */
    private function safe(array $safe): void
    {
        $json = json_encode($safe, JSON_THROW_ON_ERROR);
        foreach (
            ['PRIVATE_', $this->directory, $this->marker, 'fixture_account', 'fixture_entity',
            'fixture_token', 'https://'] as $secret
        ) {
            self::assertStringNotContainsString($secret, $json);
        }
    }
}
