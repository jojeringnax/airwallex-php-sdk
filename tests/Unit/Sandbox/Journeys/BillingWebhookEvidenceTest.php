<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingWebhookEvidence;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingWebhookEvidenceTest extends TestCase
{
    private string $directory;
    private WebhookDeliveryFixtureRuntime $runtime;
    private WebhookDeliveryStore $store;
    private BillingWebhookEvidence $evidence;
    /** @var array<string,mixed> */
    private array $projection;

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
        $this->projection = ['id' => 'SYNTHETIC_PRIVATE_CHECKOUT',
            'billing_customer_id' => 'SYNTHETIC_PRIVATE_CUSTOMER',
            'legal_entity_id' => 'SYNTHETIC_PRIVATE_ENTITY',
            'linked_payment_account_id' => 'SYNTHETIC_PRIVATE_COLLECTOR',
            'mode' => 'SETUP', 'ui_mode' => 'HOSTED', 'currency' => 'EUR',
            'metadata' => ['sdk_validation_run' => 'SYNTHETIC_PRIVATE_MARKER']];
        $this->evidence = new BillingWebhookEvidence(
            $store,
            'SYNTHETIC_PRIVATE_SECRET',
            $binding,
            $context,
            $source,
            'SYNTHETIC_PRIVATE_ACCOUNT',
            'SYNTHETIC_PRIVATE_COLLECTOR',
            $this->projection,
            WebhookDeliveryFixtureRuntime::NOW,
            static fn (): int => WebhookDeliveryFixtureRuntime::NOW
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
            $this->projection + ['status' => $cancelled ? 'CANCELLED' : 'ACTIVE', 'url' => null],
            $cancelled ? 'billing_checkout.cancelled' : 'billing_checkout.created'
        );
    }

    public function testOutOfOrderStatesUseHistoricalProjectionAndSeparateManagerAndCollectorComparisons(): void
    {
        $this->capture(true);
        $this->capture();
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['matching_created_count']);
        self::assertSame(1, $safe['matching_cancelled_count']);
        self::assertIsArray($safe['events']);
        self::assertIsArray($safe['events'][0]);
        self::assertSame('cancelled', $safe['events'][0]['event']);
        self::assertSame('match', $safe['events'][0]['account_id_configured_manager']);
        self::assertSame('mismatch', $safe['events'][0]['account_id_collector']);
        self::assertSame('absent', $safe['events'][0]['org_id']);
        self::assertFalse($safe['typed_owner_verified']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testIdenticalDuplicateDoesNotInventAnotherTransition(): void
    {
        $this->runtime->eventFilter = static fn (array $event): array => array_replace(
            $event,
            ['id' => 'SYNTHETIC_PRIVATE_FIXED_EVENT']
        );
        $this->capture();
        $this->capture();
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['matching_created_count']);
        self::assertSame(1, $safe['duplicate_matching_count']);
        self::assertSame(0, $safe['conflicting_event_count']);
    }

    public function testBothDocumentedShapesTogetherAreAmbiguousWithoutNormalization(): void
    {
        $this->runtime->eventFilter = static function (array $event): array {
            $event['data'] = ['id' => 'SYNTHETIC_PRIVATE_CHECKOUT', 'object' => ['id' => 'SYNTHETIC_PRIVATE_CHECKOUT']];
            return $event;
        };
        $this->capture();
        $safe = $this->evidence->collect();
        self::assertSame(0, $safe['matching_created_count']);
        self::assertIsArray($safe['events']);
        self::assertIsArray($safe['events'][0]);
        self::assertSame('ambiguous', $safe['events'][0]['shape']);
    }

    /** @return iterable<string,array{int}> */
    public static function staleOrFuture(): iterable
    {
        yield 'stale' => [-300001];
        yield 'future beyond tolerance' => [300001];
    }

    #[DataProvider('staleOrFuture')]
    public function testValidMacWithBadTimestampStopsBeforeToxicJson(int $offset): void
    {
        $body = 'SYNTHETIC_PRIVATE_TOXIC';
        $this->runtime->bodyFilter = static fn (string $original): string => $body;
        $this->runtime->metadataFilter = static function (array $metadata) use ($offset, $body): array {
            $result = [];
            foreach ($metadata as $key => $value) {
                if (!is_string($key)) {
                    throw new \RuntimeException('Synthetic capture metadata requires named keys.');
                }
                $result[$key] = $value;
            }
            $result['timestamp'] = (string) (WebhookDeliveryFixtureRuntime::NOW + $offset);
            $result['signature'] = hash_hmac('sha256', $result['timestamp'] . $body, 'SYNTHETIC_PRIVATE_SECRET');
            return $result;
        };
        $this->capture();
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['mac_verified_count']);
        self::assertSame(0, $safe['receipt_freshness_verified_count']);
        self::assertSame(0, $safe['unrecognized_or_foreign_count']);
        self::assertSame(0, $safe['matching_created_count']);
    }
    /** @return iterable<string,array{string}> */
    public static function duplicateProofFields(): iterable
    {
        yield 'root ID' => ['id'];
        yield 'escaped name alias' => ['name'];
        yield 'nested resource' => ['currency'];
        yield 'owner escaped alias' => ['owner'];
    }

    #[DataProvider('duplicateProofFields')]
    public function testValidMacAmbiguousObjectKeysAreWithheldBeforeEnvelopeInterpretation(string $field): void
    {
        $this->runtime->bodyFilter = static function (string $body) use ($field): string {
            if ($field === 'currency') {
                self::assertStringContainsString('"currency":"EUR"', $body);
                return str_replace('"currency":"EUR"', '"currency":"EUR","currency":"EUR"', $body);
            }
            $duplicate = match ($field) {
                'id' => '"id":"SYNTHETIC_PRIVATE_EVENT_0"',
                'name' => '"n\\u0061me":"billing_checkout.created"',
                default => '"org_id":"SYNTHETIC_PRIVATE_ORG","org\\u005fid":"SYNTHETIC_PRIVATE_ORG"',
            };
            return substr($body, 0, -1) . ',' . $duplicate . '}';
        };
        $this->capture();
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['mac_verified_count']);
        self::assertSame(1, $safe['receipt_freshness_verified_count']);
        self::assertSame(0, $safe['matching_created_count']);
        self::assertSame(1, $safe['unrecognized_or_foreign_count']);
        self::assertSame([], $safe['events']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }
    /** @return iterable<string,array{string,bool,string,string}> */
    public static function literalNumericOwners(): iterable
    {
        yield 'large root org' => ['org_id', false, '1234567890123456789012345678901234567890', 'org_id'];
        yield 'root account' => ['account_id', false, '123', 'account_id_configured_manager'];
        yield 'large legacy' => ['accountId', false, '1234567890123456789012345678901234567890', 'legacy_accountId'];
        yield 'nested large org' => ['org_id', true, '1234567890123456789012345678901234567890', 'object_org_id'];
        yield 'nested overflow account' => ['account_id', true, '1e999', 'object_account_id_configured_manager'];
    }

    #[DataProvider('literalNumericOwners')]
    public function testLiteralNumericOwnersAreWrongTypeWithoutRoundedOrStringifiedComparison(
        string $field,
        bool $nested,
        string $number,
        string $diagnostic,
    ): void {
        $this->runtime->eventFilter = static function (array $event) use ($field, $nested): array {
            if (!$nested) {
                $event[$field] = 'SYNTHETIC_PRIVATE_NUMBER';
                return $event;
            }
            $data = $event['data'];
            self::assertIsArray($data);
            $object = $data['object'];
            self::assertIsArray($object);
            $object[$field] = 'SYNTHETIC_PRIVATE_NUMBER';
            $event['data'] = ['object' => $object];
            return $event;
        };
        $this->runtime->bodyFilter = static fn (string $body): string =>
            str_replace('"SYNTHETIC_PRIVATE_NUMBER"', $number, $body);
        $this->capture();
        $safe = $this->evidence->collect();
        self::assertIsArray($safe['events']);
        self::assertIsArray($safe['events'][0]);
        self::assertSame('wrong_type', $safe['events'][0][$diagnostic]);
        self::assertFalse($safe['events'][0]['typed_owner_verified']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testNumericEnvelopeIdCannotBorrowLosslessStringConversionForEventIdentity(): void
    {
        $this->runtime->eventFilter = static fn (array $event): array => array_replace($event, ['id' => 123]);
        $this->capture();
        $safe = $this->evidence->collect();
        self::assertSame(1, $safe['mac_verified_count']);
        self::assertSame(1, $safe['unrecognized_or_foreign_count']);
        self::assertSame(0, $safe['matching_created_count']);
    }
    /** @return iterable<string,array{bool,string}> */
    public static function contractShapes(): iterable
    {
        foreach ([false, true] as $direct) {
            foreach (
                [
                'valid', 'version', 'missing_version', 'time_type', 'owner', 'numeric_owner',
                'other_owner', 'resource', 'numeric_resource', 'marker', 'state', 'ambiguous',
                'duplicate_key', 'signature', 'cancelled_url',
                ] as $fault
            ) {
                yield ($direct ? 'direct-' : 'generic-') . $fault => [$direct, $fault];
            }
        }
    }

    #[DataProvider('contractShapes')]
    public function testVerifiedDirectAndGenericContractCountersRetainEveryAcceptanceGate(
        bool $direct,
        string $fault,
    ): void {
        $organization = $fault === 'numeric_owner' ? '123' : 'SYNTHETIC_PRIVATE_ORG';
        $evidence = new BillingWebhookEvidence(
            $this->store,
            'SYNTHETIC_PRIVATE_SECRET',
            hash('sha256', 'registration'),
            hash('sha256', 'synthetic'),
            hash('sha256', 'source'),
            'SYNTHETIC_PRIVATE_ACCOUNT',
            'SYNTHETIC_PRIVATE_COLLECTOR',
            $this->projection,
            WebhookDeliveryFixtureRuntime::NOW,
            static fn (): int => WebhookDeliveryFixtureRuntime::NOW,
            $organization,
        );
        $this->runtime->eventFilter = static function (array $event) use ($direct, $fault, $organization): array {
            unset($event['account_id']);
            $event['org_id'] = $organization;
            self::assertIsArray($event['data']);
            $resource = $event['data']['object'];
            self::assertIsArray($resource);
            if (($resource['status'] ?? null) === 'CANCELLED') {
                unset($resource['url']);
                if ($fault === 'cancelled_url') {
                    $resource['url'] = 'SYNTHETIC_PRIVATE_STALE_CAPABILITY';
                }
            }
            switch ($fault) {
                case 'version':
                    $event['version'] = '2099-01-01';
                    break;
                case 'missing_version':
                    unset($event['version']);
                    break;
                case 'time_type':
                    $event['created_at'] = 123;
                    break;
                case 'owner':
                    $event['org_id'] = 'SYNTHETIC_PRIVATE_FOREIGN_ORG';
                    break;
                case 'numeric_owner':
                    $event['org_id'] = 123;
                    break;
                case 'other_owner':
                    $event['accountId'] = 'SYNTHETIC_PRIVATE_ACCOUNT';
                    break;
                case 'resource':
                    $resource['id'] = 'SYNTHETIC_PRIVATE_FOREIGN_CHECKOUT';
                    break;
                case 'numeric_resource':
                    $resource['id'] = 123;
                    break;
                case 'marker':
                    $resource['metadata'] = ['sdk_validation_run' => 'SYNTHETIC_PRIVATE_FOREIGN_MARKER'];
                    break;
                case 'state':
                    $resource['status'] = 'FUTURE';
                    break;
            }
            $event['data'] = $direct ? $resource : ['object' => $resource];
            if ($fault === 'ambiguous') {
                if ($direct) {
                    $event['data']['object'] = $resource;
                } else {
                    $event['data']['id'] = $resource['id'];
                }
            }
            return $event;
        };
        if ($fault === 'duplicate_key') {
            $this->runtime->bodyFilter = static fn (string $raw): string => str_replace(
                '"version":"2026-08-21"',
                '"version":"2026-08-21","versi\\u006fn":"2026-08-21"',
                $raw
            );
        }
        if ($fault === 'signature') {
            $this->runtime->metadataFilter = self::invalidSignature(...);
        }
        // Delivery order does not authorize a state transition; each snapshot has its own status proof.
        $this->capture(true);
        $this->capture();
        $before = [];
        foreach (glob($this->store->folder() . '/capture-*') ?: [] as $file) {
            $before[$file] = hash_file('sha256', $file);
        }
        $safe = $evidence->collect();
        self::assertSame($fault === 'signature' ? 0 : 2, $safe['mac_verified_count']);
        self::assertSame(
            $fault === 'valid' || $fault === 'cancelled_url' ? 1 : 0,
            $safe['contract_matching_created_count']
        );
        self::assertSame($fault === 'valid' ? 1 : 0, $safe['contract_matching_cancelled_count']);
        self::assertSame(0, $safe['conflicting_event_count']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        foreach ($before as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', $file));
        }
        if ($fault === 'valid') {
            self::assertTrue($safe['typed_owner_verified']);
            self::assertIsArray($safe['events']);
            self::assertCount(2, $safe['events']);
            foreach ($safe['events'] as $event) {
                self::assertIsArray($event);
                self::assertSame($direct ? 'direct' : 'generic', $event['shape']);
            }
        }
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private static function invalidSignature(array $metadata): array
    {
        $metadata['signature'] = str_repeat('0', 64);
        return $metadata;
    }

    /** @return iterable<string,array{string|int,bool}> */
    public static function literalCreationTimes(): iterable
    {
        yield 'string catalog representation' => ['2028-01-01T00:00:00Z', true];
        yield 'raw numeric is not a string timestamp' => [123, false];
    }

    #[DataProvider('literalCreationTimes')]
    public function testEnvelopeCreationTimeTypeDoesNotRewriteSignedResourceFacts(string|int $time, bool $matches): void
    {
        $this->runtime->eventFilter = static function (array $event) use ($time): array {
            unset($event['account_id']);
            $event['org_id'] = 'SYNTHETIC_PRIVATE_ORG';
            $event['created_at'] = $time;
            return $event;
        };
        $evidence = new BillingWebhookEvidence(
            $this->store,
            'SYNTHETIC_PRIVATE_SECRET',
            hash('sha256', 'registration'),
            hash('sha256', 'synthetic'),
            hash('sha256', 'source'),
            'SYNTHETIC_PRIVATE_ACCOUNT',
            'SYNTHETIC_PRIVATE_COLLECTOR',
            $this->projection,
            WebhookDeliveryFixtureRuntime::NOW,
            static fn (): int => WebhookDeliveryFixtureRuntime::NOW,
            'SYNTHETIC_PRIVATE_ORG'
        );
        $this->capture();
        $safe = $evidence->collect();
        self::assertSame(1, $safe['mac_verified_count']);
        self::assertSame(1, $safe['receipt_freshness_verified_count']);
        self::assertSame(1, $safe['matching_created_count']);
        self::assertSame(1, $safe['owner_matching_created_count']);
        self::assertSame($matches ? 1 : 0, $safe['contract_matching_created_count']);
    }
    /** @return iterable<string,array{string,bool}> */
    public static function quotedResourceReferences(): iterable
    {
        foreach (['id', 'billing_customer_id', 'legal_entity_id', 'linked_payment_account_id'] as $field) {
            yield $field . ' string' => [$field, true];
            yield $field . ' number' => [$field, false];
        }
    }

    #[DataProvider('quotedResourceReferences')]
    public function testResourceStringComparisonsAndBooleansKeepLiteralTypes(string $field, bool $quoted): void
    {
        $expected = array_replace($this->projection, [$field => '123']);
        $collector = $field === 'linked_payment_account_id' ? '123' : 'SYNTHETIC_PRIVATE_COLLECTOR';
        $evidence = new BillingWebhookEvidence(
            $this->store,
            'SYNTHETIC_PRIVATE_SECRET',
            hash('sha256', 'registration'),
            hash('sha256', 'synthetic'),
            hash('sha256', 'source'),
            'SYNTHETIC_PRIVATE_ACCOUNT',
            $collector,
            $expected,
            WebhookDeliveryFixtureRuntime::NOW,
            static fn (): int => WebhookDeliveryFixtureRuntime::NOW,
            'SYNTHETIC_PRIVATE_ORG'
        );
        $this->runtime->eventFilter = static function (array $event): array {
            unset($event['account_id']);
            $event['org_id'] = 'SYNTHETIC_PRIVATE_ORG';
            return $event;
        };
        $object = $expected + ['status' => 'ACTIVE', 'url' => null];
        if (!$quoted) {
            $object[$field] = 123;
        }
        $this->runtime->capture($object, 'billing_checkout.created');
        $safe = $evidence->collect();
        self::assertSame(1, $safe['mac_verified_count']);
        self::assertSame($quoted ? 1 : 0, $safe['matching_created_count']);
        self::assertSame($quoted ? 1 : 0, $safe['contract_matching_created_count']);
        self::assertIsArray($safe['events']);
        self::assertIsArray($safe['events'][0]);
        self::assertSame($quoted, $safe['events'][0][$field . '_matches']);
        self::assertSame($quoted, $safe['events'][0]['resource_matches']);
    }
}
