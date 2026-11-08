<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingLifecycle;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class BillingLifecycleTest extends TestCase
{
    private const string RUN = 'sdk-validation-00000000-0000-4000-8000-000000000001';
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-lifecycle-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_CLIENT_ID=fixture_client\nAIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_key\n"
            . "AIRWALLEX_ACCOUNT_ID=fixture_account\n",
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

    /** @param array<string,mixed> $object */
    private static function json(array $object): string
    {
        $json = preg_replace(
            '/"(unit_amount|amount_due|total_amount|amount|quantity)":"(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?)"/',
            '"$1":$2',
            json_encode($object, JSON_THROW_ON_ERROR),
        );
        if ($json === null) {
            throw new RuntimeException('Invalid fixture encoding.');
        }
        return $json;
    }

    /** @return array<string,mixed> */
    private static function subscription(string $status = 'ACTIVE', bool $ending = false): array
    {
        return [
            'id' => 'owned-subscription', 'billing_customer_id' => 'owned-customer',
            'legal_entity_id' => 'owned-entity', 'currency' => 'EUR', 'collection_method' => 'OUT_OF_BAND',
            'days_until_due' => 30, 'duration' => ['period' => 1, 'period_unit' => 'MONTH'],
            'metadata' => ['sdk_validation_run' => self::RUN], 'status' => $status, 'cancel_at_period_end' => $ending,
            'starts_at' => $status === 'PENDING' ? '2028-01-02T00:00:00Z' : '2028-01-01T00:00:00Z',
            'trial_ends_at' => in_array($status, ['PENDING', 'IN_TRIAL'], true)
                ? '2028-01-03T00:00:00Z' : '2028-01-01T00:00:00Z',
            'billing_cycle_anchor_at' => '2028-01-01T00:00:00Z',
            'current_period_starts_at' => '2028-01-01T00:00:00Z',
            'current_period_ends_at' => '2028-02-01T00:00:00Z', 'latest_invoice_id' => 'owned-invoice',
        ];
    }

    /** @return array<string,mixed> */
    private static function item(string $quantity = '1', string $price = 'owned-first-price'): array
    {
        return [
            'id' => $price === 'owned-first-price' ? 'owned-first-item' : 'owned-second-item',
            'subscription_id' => 'owned-subscription', 'quantity' => $quantity,
            'price' => ['id' => $price, 'currency' => 'EUR'],
        ];
    }

    /** @return array<string,mixed> */
    private static function invoice(): array
    {
        return [
            'id' => 'owned-invoice', 'billing_customer_id' => 'owned-customer',
            'subscription_id' => 'owned-subscription', 'currency' => 'EUR', 'collection_method' => 'OUT_OF_BAND',
            'amount_due' => '1.2300', 'total_amount' => '1.23',
            'status' => 'DRAFT', 'payment_status' => 'UNPAID', 'pdf_url' => null,
            'hosted_url' => 'https://example.test/private-expired-link',
        ];
    }

    /** @return list<array{int,string}|Throwable> */
    private function fixtures(): array
    {
        $marker = ['sdk_validation_run' => self::RUN];
        $price = [
            'currency' => 'EUR', 'product_id' => 'owned-product', 'pricing_model' => 'PER_UNIT',
            'recurring' => ['period' => 1, 'period_unit' => 'MONTH'], 'billing_type' => 'IN_ADVANCE',
            'metadata' => $marker, 'active' => true,
        ];
        return [
            [201, '{"token":"fixture_token","expires_at":"2030-01-01T00:00:00Z"}'],
            [201, '{"id":"owned-customer"}'],
            [200, self::json([
                'id' => 'owned-customer', 'name' => self::RUN, 'email' => self::RUN . '@example.test',
                'default_billing_currency' => 'EUR', 'default_legal_entity_id' => 'owned-entity', 'metadata' => $marker,
            ])],
            [201, '{"id":"owned-product"}'],
            [200, self::json(['id' => 'owned-product', 'name' => self::RUN, 'metadata' => $marker, 'active' => true])],
            [201, '{"id":"owned-first-price"}'],
            [200, self::json($price + ['id' => 'owned-first-price', 'unit_amount' => '1.2300'])],
            [201, '{"id":"owned-second-price"}'],
            [200, self::json($price + ['id' => 'owned-second-price', 'unit_amount' => '2.34'])],
            [201, self::json(self::subscription('PENDING'))],
            [200, self::json(self::subscription('PENDING'))],
            [200, '{"id":"owned-subscription"}'],
            [200, self::json(self::subscription('IN_TRIAL'))],
            [200, '{"id":"owned-subscription"}'],
            [200, self::json(self::subscription())],
            [200, self::json(['items' => [self::item()], 'page_after' => null])],
            [200, '{"id":"owned-subscription"}'],
            [200, self::json(['items' => [self::item('2')], 'page_after' => null])],
            [200, self::json(self::subscription())],
            [200, '{"id":"owned-subscription"}'],
            [200, self::json(['items' => [self::item('1', 'owned-second-price')], 'page_after' => null])],
            [200, self::json(self::subscription())],
            [200, '{"id":"owned-subscription"}'],
            [200, self::json(self::subscription(ending: true))],
            [200, '{"id":"owned-subscription"}'],
            [200, self::json(self::subscription())],
            [200, self::json(self::invoice())],
            [200, self::json(['items' => [
                ['id' => 'owned-line', 'invoice_id' => 'owned-invoice', 'quantity' => 1, 'amount' => '1.2300'],
            ], 'page_after' => null])],
            ...$this->cleanupFixtures(),
        ];
    }

    /** @return list<array{int,string}> */
    private function cleanupFixtures(string $state = 'ACTIVE'): array
    {
        return [
            [200, self::json(self::subscription($state))],
            [200, '{"id":"owned-subscription"}'],
            [200, self::json(self::subscription('CANCELLED'))],
            [200, '{"id":"owned-first-price","active":false}'],
            [200, '{"id":"owned-second-price","active":false}'],
            [200, '{"id":"owned-product","active":false}'],
        ];
    }

    /** @return list<array{int,string}|Throwable> */
    private function withoutTrialFixtures(): array
    {
        $fixtures = $this->fixtures();
        // Keep the first NOW update; omit the IN_TRIAL read and trial-ending mutation response.
        array_splice($fixtures, 12, 2);
        $result = [];
        foreach ($fixtures as $entry) {
            if (!is_array($entry)) {
                $result[] = $entry;
                continue;
            }
            $body = json_decode($entry[1], true, flags: JSON_THROW_ON_ERROR);
            if (is_array($body) && array_key_exists('trial_ends_at', $body)) {
                unset($body['trial_ends_at']);
                $entry[1] = json_encode($body, JSON_THROW_ON_ERROR);
            }
            $result[] = $entry;
        }
        return $result;
    }

    /**
     * @param list<array{int,string}|Throwable> $responses
     * @return array{array<string,mixed>,RecordingClient,RunLedger,int,list<int>}
     */
    private function runJourney(array $responses, int $attempts = 3, bool $withTrial = true): array
    {
        $queue = array_map(
            static fn (array | Throwable $entry): Response | Throwable
                => is_array($entry) ? new Response($entry[0], [], $entry[1]) : $entry,
            $responses,
        );
        $recording = new RecordingClient(new HttpClient(['handler' => new MockHandler($queue)]));
        $session = new SandboxSession(
            Configuration::load('sandbox', $this->directory . '/credentials'),
            'organization',
            $recording,
            static fn (): int => 100,
        );
        $index = 0;
        $ledger = RunLedger::create(
            $session->contextFingerprint(),
            $this->directory,
            static function () use (&$index): string {
                return sprintf('00000000-0000-4000-8000-%012d', ++$index);
            },
        );
        $waits = 0;
        $delays = [];
        $result = BillingLifecycle::execute(
            $session,
            $ledger,
            'owned-entity',
            static fn (): int => 1830297600, // 2028-01-01 UTC.
            static function (int $seconds) use (&$waits, &$delays): void {
                ++$waits;
                $delays[] = $seconds;
            },
            observationAttempts: $attempts,
            withTrial: $withTrial,
        );
        return [$result, $recording, $ledger, $waits, $delays];
    }

    public function testOwnedManualLifecycleObservesAllChangesAndCancelsBeforeArchiving(): void
    {
        [$result, $http, $ledger, $waits] = $this->runJourney($this->fixtures());
        self::assertSame('pass', $result['result']);
        self::assertSame('billing-lifecycle', $result['scenario']);
        self::assertTrue($result['trial_requested']);
        self::assertFalse($result['ambiguous_mutation']);
        self::assertSame(0, $waits);
        self::assertSame([
            'pdf_url_present' => true, 'pdf_url_nonempty' => false,
            'hosted_url_present' => true, 'hosted_url_nonempty' => true,
            'invoice_status' => 'DRAFT', 'invoice_payment_status' => 'UNPAID',
        ], $result['observations']);
        self::assertSame(['customers' => 1, 'products' => 1, 'prices' => 2, 'subscriptions' => 1], $ledger->counts());
        self::assertSame([
            'subscriptions_0' => 'pass', 'prices_0' => 'pass', 'prices_1' => 'pass', 'products_0' => 'pass',
        ], $result['cleanup']);
        self::assertCount(34, $http->requests);
        $requestIds = [];
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('', $request->getHeaderLine('x-login-as'));
            self::assertSame('', $request->getHeaderLine('x-on-behalf-of'));
            self::assertStringStartsWith(
                'https://api.sandbox.airwallex.com/api/v1/billing/',
                (string) $request->getUri(),
            );
            if ($request->getMethod() === 'POST') {
                $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                self::assertIsString($body['request_id']);
                $requestIds[] = $body['request_id'];
                self::assertStringNotContainsString('/invoices/', (string) $request->getUri());
                self::assertArrayNotHasKey('payment_source_id', $body);
                self::assertArrayNotHasKey('linked_payment_account_id', $body);
            }
        }
        self::assertCount(15, array_unique($requestIds));
        self::assertStringContainsString('"starts_at":"NOW"', (string) $http->requests[11]->getBody());
        self::assertStringContainsString('"trial_ends_at":"NOW"', (string) $http->requests[13]->getBody());
        self::assertStringContainsString(
            '"default_proration_mode":"PRORATED"',
            (string) $http->requests[16]->getBody(),
        );
        self::assertStringContainsString('"default_proration_mode":"NONE"', (string) $http->requests[19]->getBody());
        self::assertStringContainsString('"cancel_at_period_end":true', (string) $http->requests[22]->getBody());
        self::assertStringContainsString('"cancel_at_period_end":false', (string) $http->requests[24]->getBody());
        self::assertStringContainsString('"proration_behavior":"NONE"', (string) $http->requests[29]->getBody());
        self::assertStringEndsWith(
            '/subscriptions/owned-subscription/cancel',
            (string) $http->requests[29]->getUri(),
        );
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $privateValues = [
            'owned-customer', 'owned-subscription', 'owned-invoice', 'owned-entity', 'private-expired-link',
        ];
        foreach ($privateValues as $id) {
            self::assertStringNotContainsString($id, $json);
        }
    }

    public function testAsynchronousStateIsReadAgainWithoutRepeatingTheMutation(): void
    {
        $fixtures = $this->fixtures();
        $responses = [
            ...array_slice($fixtures, 0, 12),
            [200, self::json(self::subscription('PENDING'))],
            ...array_slice($fixtures, 12),
        ];
        [$result, $http, , $waits] = $this->runJourney($responses);
        self::assertSame('pass', $result['result']);
        self::assertSame(1, $waits);
        self::assertCount(35, $http->requests);
        self::assertSame('GET', $http->requests[12]->getMethod());
        self::assertSame('GET', $http->requests[13]->getMethod());
        self::assertStringContainsString('"trial_ends_at":"NOW"', (string) $http->requests[14]->getBody());
    }

    public function testSeparatelyLabeledNoTrialJourneyObservesActivationAndAllRemainingCheckpoints(): void
    {
        [$result, $http, $ledger, $waits] = $this->runJourney($this->withoutTrialFixtures(), withTrial: false);
        self::assertSame('pass', $result['result']);
        self::assertSame('billing-lifecycle-no-trial', $result['scenario']);
        self::assertFalse($result['trial_requested']);
        self::assertSame(0, $waits);
        self::assertIsArray($result['steps']);
        self::assertArrayNotHasKey('trial_started', $result['steps']);
        self::assertArrayNotHasKey('trial_ended', $result['steps']);
        foreach (
            [
            'future_start', 'activated_without_trial', 'quantity_observed', 'quantity_cycle_kept',
            'replacement_observed', 'plan_cycle_kept', 'ending_scheduled', 'period_end_flag_cleared',
            ] as $step
        ) {
            self::assertSame('pass', $result['steps'][$step]);
        }
        self::assertSame(['customers' => 1, 'products' => 1, 'prices' => 2, 'subscriptions' => 1], $ledger->counts());
        self::assertCount(32, $http->requests);
        foreach ($http->requests as $request) {
            self::assertStringNotContainsString('trial_ends_at', (string) $request->getBody());
        }
        self::assertSame([
            'subscriptions_0' => 'pass', 'prices_0' => 'pass', 'prices_1' => 'pass', 'products_0' => 'pass',
        ], $result['cleanup']);
        self::assertIsArray($result['observations']);
        self::assertTrue($result['observations']['pdf_url_present']);
    }

    public function testNoTrialJourneyWaitsForActiveWithoutMutatingOrClaimingTrialCompletion(): void
    {
        $fixtures = $this->withoutTrialFixtures();
        $responses = [
            ...array_slice($fixtures, 0, 12),
            [200, self::json(self::subscription('IN_TRIAL'))],
            ...array_slice($fixtures, 12),
        ];
        [$result, $http, , $waits] = $this->runJourney($responses, withTrial: false);
        self::assertSame('pass', $result['result']);
        self::assertFalse($result['trial_requested']);
        self::assertSame(1, $waits);
        self::assertSame('GET', $http->requests[12]->getMethod());
        self::assertSame('GET', $http->requests[13]->getMethod());
        foreach ($http->requests as $request) {
            self::assertStringNotContainsString('trial_ends_at', (string) $request->getBody());
        }
    }

    public function testNoTrialCreateFailureRemainsAmbiguousAndCannotReplayOrArchive(): void
    {
        $responses = array_slice($this->withoutTrialFixtures(), 0, 9);
        $responses[] = [400, '{"code":"validation_error"}'];
        [$result, $http, $ledger] = $this->runJourney($responses, withTrial: false);
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['ambiguous_mutation']);
        self::assertFalse($result['trial_requested']);
        self::assertSame([], $ledger->created('subscriptions'));
        self::assertCount(10, $http->requests);
    }

    public function testNoTrialJourneyCannotTreatAnUnfinishedTrialStateAsActive(): void
    {
        $responses = array_slice($this->withoutTrialFixtures(), 0, 12);
        for ($index = 0; $index < 3; ++$index) {
            $responses[] = [200, self::json(self::subscription('IN_TRIAL'))];
        }
        $responses = [...$responses, ...$this->cleanupFixtures('IN_TRIAL')];
        [$result, $http, , $waits] = $this->runJourney($responses, withTrial: false);
        self::assertSame('pending', $result['result']);
        self::assertSame('activated_without_trial_not_observed_within_read_bound', $result['reason']);
        self::assertFalse($result['trial_requested']);
        self::assertSame(2, $waits);
        self::assertCount(21, $http->requests);
        foreach ($http->requests as $request) {
            self::assertStringNotContainsString('trial_ends_at', (string) $request->getBody());
        }
    }

    public function testExhaustedObservationIsPendingAndOnlyOwnedCleanupFollows(): void
    {
        $responses = array_slice($this->fixtures(), 0, 12);
        for ($index = 0; $index < 3; ++$index) {
            $responses[] = [200, self::json(self::subscription('PENDING'))];
        }
        $responses = [...$responses, ...$this->cleanupFixtures('PENDING')];
        [$result, $http, , $waits] = $this->runJourney($responses);
        self::assertSame('pending', $result['result']);
        self::assertSame('trial_started_not_observed_within_read_bound', $result['reason']);
        self::assertSame(2, $waits);
        self::assertCount(21, $http->requests);
        foreach (array_slice($http->requests, 12) as $request) {
            self::assertStringNotContainsString('"trial_ends_at":"NOW"', (string) $request->getBody());
        }
    }

    /** @return iterable<string,array{int,bool}> */
    public static function ambiguousMutations(): iterable
    {
        foreach ([1, 3, 5, 9, 11, 16, 19, 29] as $index) {
            yield 'transport-' . $index => [$index, false];
            yield 'http-500-' . $index => [$index, true];
        }
    }

    #[DataProvider('ambiguousMutations')]
    public function testAmbiguousMutationStopsEverySubsequentWriteIncludingCleanup(int $index, bool $httpError): void
    {
        $responses = array_slice($this->fixtures(), 0, $index);
        $responses[] = $httpError ? [500, '{"code":"temporary_error","message":"SENTINEL_PRIVATE"}']
            : new RuntimeException('SENTINEL_PRIVATE transport failure');
        [$result, $http] = $this->runJourney($responses);
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['ambiguous_mutation']);
        self::assertCount($index + 1, $http->requests);
        self::assertStringNotContainsString('SENTINEL_PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testMissingCreatedSubscriptionIdentityIsAmbiguousAndNeverReplayed(): void
    {
        $responses = array_slice($this->fixtures(), 0, 9);
        $responses[] = [201, '{}'];
        [$result, $http, $ledger] = $this->runJourney($responses);
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['ambiguous_mutation']);
        self::assertSame([], $ledger->created('subscriptions'));
        self::assertCount(10, $http->requests);
    }

    /** @return iterable<string,array{string,string,mixed,bool}> */
    public static function incorrectReadBacks(): iterable
    {
        $fields = [
            'subscription' => [
                'id' => 'unrelated-subscription', 'billing_customer_id' => 'unrelated-customer',
                'legal_entity_id' => 'unrelated-entity', 'currency' => 'USD', 'collection_method' => 'AUTO_CHARGE',
                'days_until_due' => 1, 'duration' => ['period' => 2, 'period_unit' => 'MONTH'],
                'metadata' => ['sdk_validation_run' => 'unrelated-run'],
                'starts_at' => '2028-01-02T00:00:00.000000001Z',
            ],
            'invoice' => [
                'billing_customer_id' => 'unrelated-customer', 'subscription_id' => 'unrelated-subscription',
                'currency' => 'USD', 'collection_method' => 'AUTO_CHARGE', 'amount_due' => 'invalid',
                'total_amount' => 'invalid', 'pdf_url' => [], 'hosted_url' => [],
            ],
        ];
        foreach ($fields as $kind => $values) {
            foreach ($values as $field => $value) {
                yield $kind . '-' . $field . '-wrong' => [$kind, $field, $value, false];
                if (!in_array($field, ['pdf_url', 'hosted_url'], true)) {
                    yield $kind . '-' . $field . '-missing' => [$kind, $field, null, true];
                }
            }
        }
        $timestamps = [
            'positive colon hour' => '2028-01-03T00:00:00+24:00',
            'negative colon hour' => '2028-01-01T00:00:00-24:00',
            'noncolon hour' => '2028-01-03T00:00:00+2400',
            'colon minute' => '2028-01-02T01:00:00+00:60',
            'noncolon minute' => '2028-01-02T01:00:00+0060',
            'invalid calendar day' => '2028-02-30T00:00:00Z',
            'relative word' => 'now',
            'relative offset' => '+1 hour',
        ];
        foreach ($timestamps as $name => $value) {
            yield $name => ['subscription', 'starts_at', $value, false];
        }
    }

    #[DataProvider('incorrectReadBacks')]
    public function testWrongOrMissingReadBackCannotPassAndOnlyVerifiedOwnedCleanupFollows(
        string $kind,
        string $field,
        mixed $value,
        bool $missing,
    ): void {
        $index = $kind === 'subscription' ? 10 : 26;
        $body = $kind === 'subscription' ? self::subscription('PENDING') : self::invoice();
        if ($missing) {
            unset($body[$field]);
        } else {
            $body[$field] = $value;
        }
        $responses = array_slice($this->fixtures(), 0, $index);
        $responses[] = [200, self::json($body)];
        $responses = [...$responses, ...$this->cleanupFixtures($kind === 'subscription' ? 'PENDING' : 'ACTIVE')];
        [$result, $http] = $this->runJourney($responses);
        self::assertSame('fail', $result['result']);
        self::assertFalse($result['ambiguous_mutation']);
        self::assertSame([
            'subscriptions_0' => 'pass', 'prices_0' => 'pass', 'prices_1' => 'pass', 'products_0' => 'pass',
        ], $result['cleanup']);
        self::assertCount(count($responses), $http->requests);
    }

    public function testInvoiceDiscoveryIsFilteredAndWaitsForAnOwnedInvoiceWithoutCreatingOne(): void
    {
        $fixtures = $this->fixtures();
        $subscription = self::subscription();
        unset($subscription['latest_invoice_id']);
        $responses = [
            ...array_slice($fixtures, 0, 25),
            [200, self::json($subscription)],
            [200, '{"items":[],"page_after":null}'],
            [200, self::json(['items' => [self::invoice()], 'page_after' => null])],
            ...array_slice($fixtures, 26),
        ];
        [$result, $http, , $waits] = $this->runJourney($responses);
        self::assertSame('pass', $result['result']);
        self::assertSame(1, $waits);
        self::assertCount(36, $http->requests);
        foreach ([26, 27] as $index) {
            self::assertStringEndsWith(
                '/invoices?billing_customer_id=owned-customer&subscription_id=owned-subscription&page_size=20',
                (string) $http->requests[$index]->getUri(),
            );
            self::assertSame('GET', $http->requests[$index]->getMethod());
        }
    }

    public function testAnUnfamiliarInvoiceStateIsBlockedRatherThanReportedAsSuccess(): void
    {
        $responses = array_slice($this->fixtures(), 0, 26);
        $body = self::invoice();
        $body['status'] = 'UNFAMILIAR';
        $responses[] = [200, self::json($body)];
        $responses = [...$responses, ...$this->cleanupFixtures()];
        [$result] = $this->runJourney($responses);
        self::assertSame('blocked', $result['result']);
        self::assertSame('invoice_state_unfamiliar', $result['reason']);
    }

    public function testUnconfirmedCancellationStopsBeforeArchivingAndIsNeverRepeated(): void
    {
        $responses = array_slice($this->fixtures(), 0, 30);
        for ($index = 0; $index < 3; ++$index) {
            $responses[] = [200, self::json(self::subscription())];
        }
        [$result, $http, , $waits] = $this->runJourney($responses);
        self::assertSame('fail', $result['result']);
        self::assertSame(['subscriptions_0' => 'fail'], $result['cleanup']);
        self::assertSame(2, $waits);
        self::assertCount(33, $http->requests);
        foreach (array_slice($http->requests, 30) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    public function testActiveCycleFieldsMayBecomeAvailableOnABoundedLaterRead(): void
    {
        $fixtures = $this->fixtures();
        $body = self::subscription();
        unset($body['billing_cycle_anchor_at']);
        $responses = [
            ...array_slice($fixtures, 0, 14), [200, self::json($body)], ...array_slice($fixtures, 14),
        ];
        [$result, $http, , $waits] = $this->runJourney($responses);
        self::assertSame('pass', $result['result']);
        self::assertSame(1, $waits);
        self::assertCount(35, $http->requests);
    }

    public function testFlagReversalNotObservedRemainsPendingAndDoesNotAdvanceToInvoices(): void
    {
        $responses = array_slice($this->fixtures(), 0, 25);
        for ($index = 0; $index < 3; ++$index) {
            $responses[] = [200, self::json(self::subscription(ending: true))];
        }
        $responses = [...$responses, ...$this->cleanupFixtures()];
        [$result, $http] = $this->runJourney($responses);
        self::assertSame('pending', $result['result']);
        self::assertSame('period_end_flag_cleared_not_observed_within_read_bound', $result['reason']);
        foreach ($http->requests as $request) {
            self::assertStringNotContainsString('/invoices/', (string) $request->getUri());
        }
    }

    public function testKeepCycleActionCannotPassIfTheObservedAnchorChanges(): void
    {
        $responses = array_slice($this->fixtures(), 0, 18);
        $body = self::subscription();
        $body['billing_cycle_anchor_at'] = '2028-01-02T00:00:00Z';
        $responses[] = [200, self::json($body)];
        $responses = [...$responses, ...$this->cleanupFixtures()];
        [$result, $http] = $this->runJourney($responses);
        self::assertSame('fail', $result['result']);
        self::assertFalse($result['ambiguous_mutation']);
        foreach (array_slice($http->requests, 19) as $request) {
            self::assertStringNotContainsString('"default_proration_mode"', (string) $request->getBody());
        }
    }

    public function testLifecycleCommandWithMissingLegalEntityRetainsEvidenceAndCreatesNothing(): void
    {
        $http = new RecordingClient(new HttpClient(['handler' => new MockHandler([
            new Response(201, [], '{"token":"fixture_token","expires_at":"2030-01-01T00:00:00Z"}'),
            new Response(200, [], '{"id":"fixture_account","account_details":{}}'),
        ])]));
        $result = SandboxCommand::run([
            'scenario' => 'billing-lifecycle', 'environment' => 'sandbox', 'context' => 'organization',
            'credentials-file' => $this->directory . '/credentials', 'state-dir' => $this->directory,
        ], static fn (Configuration $config, string $context): SandboxSession
            => new SandboxSession($config, $context, $http));
        self::assertSame('billing-lifecycle', $result['scenario']);
        self::assertSame('blocked', $result['result']);
        self::assertSame('2026-08-21', $result['api_version']);
        self::assertIsArray($result['source']);
        self::assertIsString($result['context_fingerprint']);
        self::assertCount(2, $http->requests);
        self::assertSame([], glob($this->directory . '/sdk-validation-*'));
    }

    public function testAccountContextIsRejectedBeforeAnyProviderRequest(): void
    {
        $http = new RecordingClient(new HttpClient(['handler' => new MockHandler([])]));
        $session = new SandboxSession(
            Configuration::load('sandbox', $this->directory . '/credentials'),
            'account',
            $http,
        );
        $ledger = RunLedger::create($session->contextFingerprint(), $this->directory);
        $this->expectException(RuntimeException::class);
        try {
            BillingLifecycle::execute($session, $ledger, 'owned-entity');
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function testValidTimezoneFormsAndZeroFractionNormalizeToTheSameRequestedInstant(): void
    {
        $fixtures = $this->fixtures();
        $body = self::subscription('PENDING');
        $body['starts_at'] = '2028-01-02T00:00:00.000000000+0000';
        $body['trial_ends_at'] = '2028-01-03T01:00:00+01:00';
        $responses = [
            ...array_slice($fixtures, 0, 10), [200, self::json($body)], ...array_slice($fixtures, 11),
        ];
        [$result] = $this->runJourney($responses);
        self::assertSame('pass', $result['result']);
    }

    public function testLongObservationBudgetWaitsThirtySecondsWithoutRepeatingAnyMutation(): void
    {
        $responses = array_slice($this->fixtures(), 0, 12);
        for ($index = 0; $index < 7; ++$index) {
            $responses[] = [200, self::json(self::subscription('PENDING'))];
        }
        $responses = [...$responses, ...$this->cleanupFixtures('PENDING')];
        [$result, $http, , $waits, $delays] = $this->runJourney($responses, 7);
        self::assertSame('pending', $result['result']);
        self::assertSame(6, $waits);
        self::assertSame([1, 2, 4, 8, 8, 7], $delays);
        self::assertSame(30, array_sum($delays));
        self::assertCount(25, $http->requests);
        foreach (array_slice($http->requests, 12, 7) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }
}
