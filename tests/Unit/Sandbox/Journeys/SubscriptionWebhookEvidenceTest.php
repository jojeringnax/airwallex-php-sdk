<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SubscriptionWebhookEvidence;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubscriptionWebhookEvidenceTest extends TestCase
{
    private string $directory;
    private WebhookDeliveryFixtureRuntime $runtime;
    private WebhookDeliveryStore $store;
    private SubscriptionWebhookEvidence $evidence;
    /** @var array<string,mixed> */
    private array $projection;
    private int $now = WebhookDeliveryFixtureRuntime::NOW;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/synthetic-billing-capture-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $context = hash('sha256', 'synthetic');
        $ledger = RunLedger::create($context, $this->directory);
        $store = new WebhookDeliveryStore($this->directory, $ledger);
        $this->store = $store;
        $source = hash('sha256', 'source');
        $binding = hash('sha256', 'registration');
        $this->runtime = new WebhookDeliveryFixtureRuntime(['folder' => $store->folder(), 'path' => '/synthetic',
            'source' => $source, 'context' => $context]);
        $this->runtime->arm($binding);
        $this->projection = ['id' => '123',
            'billing_customer_id' => 'SYNTHETIC_PRIVATE_CUSTOMER',
            'legal_entity_id' => 'SYNTHETIC_PRIVATE_ENTITY',
            'collection_method' => 'OUT_OF_BAND', 'currency' => 'EUR', 'starts_at' => '2028-01-02T00:00:00Z',
            'metadata' => ['sdk_validation_run' => 'SYNTHETIC_PRIVATE_MARKER']];
        $this->runtime->eventFilter = static function (array $event): array {
            unset($event['account_id']);
            $event['org_id'] = 'SYNTHETIC_PRIVATE_ORG';
            return $event;
        };
        $this->evidence = new SubscriptionWebhookEvidence(
            $store,
            'SYNTHETIC_PRIVATE_SECRET',
            $binding,
            $context,
            $source,
            'SYNTHETIC_PRIVATE_ACCOUNT',
            'SYNTHETIC_PRIVATE_COLLECTOR',
            $this->projection,
            WebhookDeliveryFixtureRuntime::NOW,
            fn (): int => $this->now,
            'SYNTHETIC_PRIVATE_ORG'
        );
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
            is_dir($path) ? self::remove($path) : unlink($path);
        }
        rmdir($directory);
    }

    private function capture(bool $cancelled = false): void
    {
        $this->runtime->capture(
            $this->projection + ['status' => $cancelled ? 'CANCELLED' : 'PENDING',
                'days_until_due' => 30, 'duration' => ['period' => 1, 'period_unit' => 'MONTH'],
                'recurring' => ['period' => 1, 'period_unit' => 'MONTH'],
                'cancel_at_period_end' => false, 'enable_automatic_tax' => false, 'applied_discounts' => []],
            $cancelled ? 'subscription.cancelled' : 'subscription.created'
        );
    }

    /** @return iterable<string,array{bool}> */
    public static function shapes(): iterable
    {
        yield 'generic' => [false];
        yield 'direct' => [true];
    }

    #[DataProvider('shapes')]
    public function testTwoShapesAndOutOfOrderSnapshotsRetainRawBytesWithoutSdkDirectExpansion(bool $direct): void
    {
        $this->runtime->eventFilter = static function (array $event) use ($direct): array {
            unset($event['account_id']);
            $event['org_id'] = 'SYNTHETIC_PRIVATE_ORG';
            if ($direct) {
                self::assertIsArray($event['data']);
                $event['data'] = $event['data']['object'];
            }
            return $event;
        };
        $this->capture(true);
        $this->capture();
        $before = $this->store->read('capture-000001.body');
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['fresh_contract_matching_created_count']);
        self::assertSame(1, $safe['fresh_contract_matching_cancelled_count']);
        self::assertTrue($safe['typed_owner_verified']);
        self::assertSame($before, $this->store->read('capture-000001.body'));
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function badEnvelope(): iterable
    {
        yield 'owner absent' => ['org_id', null];
        yield 'owner foreign' => ['org_id', 'foreign'];
        yield 'owner number' => ['org_id', 123];
        yield 'account competing' => ['account_id', 'SYNTHETIC_PRIVATE_ACCOUNT'];
        yield 'legacy competing' => ['accountId', 'SYNTHETIC_PRIVATE_ACCOUNT'];
        yield 'version absent' => ['version', null];
        yield 'version foreign' => ['version', '2024-01-01'];
        yield 'native event ID' => ['id', 123];
        yield 'native created_at' => ['created_at', 123];
        yield 'wrong name' => ['name', 'subscription.active'];
    }

    #[DataProvider('badEnvelope')]
    public function testTypedVersionOwnerAndEnvelopeGatesCannotBeSubstitutedByResourceFacts(
        string $field,
        mixed $value
    ): void {
        $this->runtime->eventFilter = static function (array $event) use ($field, $value): array {
            unset($event['account_id']);
            $event['org_id'] = 'SYNTHETIC_PRIVATE_ORG';
            if ($value === null) {
                unset($event[$field]);
            } else {
                $event[$field] = $value;
            }
            return $event;
        };
        $this->capture();
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['mac_verified_count']);
        self::assertSame(0, $safe['fresh_contract_matching_created_count']);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function badResource(): iterable
    {
        yield 'wrong native small int' => ['days_until_due', '30'];
        yield 'wrong due' => ['days_until_due', 31];
        yield 'wrong native duration' => ['duration', ['period' => '1', 'period_unit' => 'MONTH']];
        yield 'wrong recurring' => ['recurring', ['period' => 1, 'period_unit' => 'DAY']];
        yield 'foreign resource' => ['id', 'foreign'];
        yield 'native resource ID' => ['id', 123];
        yield 'wrong Customer' => ['billing_customer_id', 'foreign'];
        yield 'wrong entity' => ['legal_entity_id', 'foreign'];
        yield 'wrong marker' => ['metadata', ['sdk_validation_run' => 'foreign']];
        yield 'wrong currency' => ['currency', 'USD'];
        yield 'trial' => ['trial_ends_at', '2028-01-03T00:00:00Z'];
        yield 'source' => ['payment_source_id', 'foreign'];
        yield 'auto charge' => ['collection_method', 'AUTO_CHARGE'];
        yield 'active event state' => ['status', 'ACTIVE'];
        yield 'wrong start' => ['starts_at', '2028-01-03T00:00:00Z'];
        yield 'discounts null' => ['applied_discounts', null];
        yield 'tax' => ['enable_automatic_tax', true];
        yield 'scheduled cancellation' => ['cancel_at_period_end', true];
    }

    #[DataProvider('badResource')]
    public function testNativeSameResourceAndExactOriginalTermsAreRequired(string $field, mixed $value): void
    {
        $this->runtime->eventFilter = static function (array $event) use ($field, $value): array {
            unset($event['account_id']);
            $event['org_id'] = 'SYNTHETIC_PRIVATE_ORG';
            self::assertIsArray($event['data']);
            self::assertIsArray($event['data']['object']);
            $event['data']['object'][$field] = $value;
            return $event;
        };
        $this->capture();
        $safe = $this->evidence->collect();
        self::assertSame(0, $safe['matching_created_count']);
        self::assertSame(0, $safe['fresh_contract_matching_created_count']);
    }

    public function testMacPrecedesToxicJsonAndOriginalFreshnessPrecedesParsing(): void
    {
        foreach ([false, true] as $validMac) {
            $this->runtime->bodyFilter = static fn (string $body): string => 'SYNTHETIC_PRIVATE_TOXIC';
            $this->runtime->metadataFilter = static function (array $metadata) use ($validMac): array {
                $metadata = self::named($metadata);
                $metadata['timestamp'] = (string) (WebhookDeliveryFixtureRuntime::NOW - 300001);
                $metadata['signature'] = $validMac ? hash_hmac(
                    'sha256',
                    $metadata['timestamp'] . 'SYNTHETIC_PRIVATE_TOXIC',
                    'SYNTHETIC_PRIVATE_SECRET'
                ) : str_repeat('0', 64);
                return $metadata;
            };
            $this->capture();
        }
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['mac_verified_count']);
        self::assertSame(0, $safe['receipt_freshness_verified_count']);
        self::assertSame(0, $safe['unrecognized_or_foreign_count']);
    }

    public function testCurrentFreshnessForEachSelectedEventCannotBeBorrowedOrKeptForever(): void
    {
        $this->capture();
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['fresh_contract_matching_created_count']);
        $this->now += 300001;
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['matching_created_count']);
        self::assertSame(0, $safe['fresh_contract_matching_created_count']);
        self::assertSame(0, $safe['fresh_contract_matching_cancelled_count']);
    }

    /** @return iterable<string,array{string}> */
    public static function duplicateFields(): iterable
    {
        yield 'root' => ['"org_id":"SYNTHETIC_PRIVATE_ORG"'];
        yield 'escaped root' => ['"org\u005fid":"SYNTHETIC_PRIVATE_ORG"'];
        yield 'event' => ['"n\u0061me":"subscription.created"'];
    }

    #[DataProvider('duplicateFields')]
    public function testValidSignedDuplicateDecodedKeysDoNotEstablishResourceProof(string $duplicate): void
    {
        $this->runtime->bodyFilter = static fn (string $body): string => substr($body, 0, -1) . ',' . $duplicate . '}';
        $this->capture();
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['mac_verified_count']);
        self::assertSame(0, $safe['matching_created_count']);
        self::assertSame([], $safe['events']);
    }

    public function testDuplicateEventCannotSupplyMissingCounterpartAndConflictingBytesInvalidatePair(): void
    {
        $this->runtime->eventFilter = static function (array $event): array {
            unset($event['account_id']);
            $event['org_id'] = 'SYNTHETIC_PRIVATE_ORG';
            $event['id'] = 'SYNTHETIC_PRIVATE_SAME_EVENT';
            return $event;
        };
        $this->capture();
        $this->capture();
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['matching_created_count']);
        self::assertSame(1, $safe['duplicate_matching_count']);
        self::assertSame(0, $safe['matching_cancelled_count']);
        $this->capture(true);
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['conflicting_event_count']);
        self::assertFalse($safe['typed_owner_verified']);
    }

    public function testAmbiguousGenericAndDirectShapeIsNeverRepaired(): void
    {
        $this->runtime->eventFilter = static function (array $event): array {
            self::assertIsArray($event['data']);
            $event['data']['id'] = 'SYNTHETIC_PRIVATE_SUBSCRIPTION';
            return $event;
        };
        $this->capture();
        self::assertSame(0, $this->evidence->collect()['matching_created_count']);
    }
    public function testCoherentMaximum16PrivateCapturesStayBelowUnchanged64KiBOutputBound(): void
    {
        for ($index = 0; $index < 16; ++$index) {
            $this->capture($index % 2 === 0);
        }
        $safe = $this->evidence->collect();
        self::assertSame(16, $safe['captured_count']);
        self::assertSame(8, $safe['fresh_contract_matching_created_count']);
        self::assertSame(8, $safe['fresh_contract_matching_cancelled_count']);
        $json = json_encode($safe, JSON_THROW_ON_ERROR);
        self::assertLessThan(60000, strlen($json)); // Leaves room for fixed controller/source/cleanup facts.
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $json);
        $this->capture();
        $this->expectException(\Jojeringnax\Airwallex\Exception\ProtocolException::class);
        $this->evidence->collect();
    }

    public function testPrivateCaptureProvenanceMismatchFailsBeforeAnyMacOrJsonFact(): void
    {
        $this->runtime->metadataFilter = static function (array $metadata): array {
            $metadata = self::named($metadata);
            $metadata['source'] = 'foreign';
            return $metadata;
        };
        $this->capture();
        $this->expectException(\Jojeringnax\Airwallex\Exception\ProtocolException::class);
        $this->evidence->collect();
    }
    /**
     * @param array<array-key,mixed> $value
     * @return array<string,mixed>
     */
    private static function named(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException('Synthetic metadata needs named fields.');
            }
            $result[$key] = $item;
        }
        return $result;
    }
    public function testLiteralEmptyDiscountObjectIsNotAnEmptyArrayProof(): void
    {
        $this->runtime->bodyFilter = static fn (string $body): string =>
            str_replace('"applied_discounts":[]', '"applied_discounts":{}', $body);
        $this->capture();
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['mac_verified_count']);
        self::assertSame(0, $safe['matching_created_count']);
    }
    #[DataProvider('shapes')]
    public function testUnknownNumbersAndWrapperFieldsRetainOriginalBytes(bool $direct): void
    {
        $number = '9223372036854775808.12345678901234567890';
        $this->runtime->eventFilter = static function (array $event) use ($direct, $number): array {
            unset($event['account_id']);
            $event['org_id'] = 'SYNTHETIC_PRIVATE_ORG';
            self::assertIsArray($event['data']);
            self::assertIsArray($event['data']['object']);
            $event['data']['object']['unknown_number'] = $number;
            if ($direct) {
                $event['data'] = $event['data']['object'];
            } else {
                $event['data']['unknown_wrapper'] = ['retained' => true];
            }
            return $event;
        };
        $this->runtime->bodyFilter = static fn (string $body): string =>
            str_replace('"' . $number . '"', $number, $body);
        $this->capture();
        $before = $this->store->read('capture-000001.body');
        self::assertStringContainsString($number, $before);
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['fresh_contract_matching_created_count']);
        self::assertSame($before, $this->store->read('capture-000001.body'));
        self::assertStringNotContainsString($number, json_encode($safe, JSON_THROW_ON_ERROR));
    }
    public function testCollectionObservationWorkCannotResetThe30SecondWaitDeadline(): void
    {
        $state = new class {
            public float $time = 0.0;
            public int $polls = 0;
        };
        $runtime = new class (static function () use ($state): void {
 $state->time += 11;
++$state->polls;
        }) implements
            \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryRuntime
        {
            public function __construct(private readonly \Closure $tick)
            {
            }
            public function destination(): string
            {
                return 'https://synthetic.trycloudflare.com/unused';
            }
            public function arm(string $registrationFingerprint): void
            {
            }
            public function poll(): array
            {
                ($this->tick)();
                return array_fill_keys(['stream_failure', 'persistence_failure', 'limit_failure', 'closed',
                    'supervisor_failed', 'log_limit_failure', 'tunnel_exited'], false);
            }
            public function stop(): array
            {
                return ['receiver_closed' => true, 'tunnel_stopped' => true,
                'logs_closed' => true, 'failed' => false];
            }
        };
        $method = new \ReflectionMethod(
            \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SubscriptionWebhookProbe::class,
            'collect'
        );
        $observed = $method->invoke(
            null,
            $runtime,
            $this->evidence,
            'matching_created_count',
            static function (int $delay) use ($state): void {
                $state->time += $delay / 1000;
            },
            300.0,
            static fn (): float => $state->time
        );
        self::assertIsArray($observed);
        self::assertSame(0, $observed['matching_created_count']);
        self::assertLessThanOrEqual(3, $state->polls);
    }
}
