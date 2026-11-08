<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryEvidence;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookDeliveryEvidenceTest extends TestCase
{
    private string $directory;
    private WebhookDeliveryStore $store;
    private WebhookDeliveryFixtureRuntime $runtime;
    private int $now;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sdk-webhook-evidence-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->store = new WebhookDeliveryStore(
            $this->directory,
            RunLedger::create(str_repeat('a', 64), $this->directory)
        );
        $this->runtime = new WebhookDeliveryFixtureRuntime(['folder' => $this->store->folder(),
            'path' => '/' . str_repeat('a', 64), 'context' => str_repeat('a', 64), 'source' => str_repeat('b', 64)]);
        $this->runtime->arm(str_repeat('c', 64));
        $this->now = WebhookDeliveryFixtureRuntime::NOW;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->directory . '/*') ?: [] as $folder) {
            rmdir($folder);
        }
        rmdir($this->directory);
    }

    /** @return array<string,mixed> */
    private static function object(): array
    {
        return ['id' => 'SYNTHETIC_PRIVATE_INTENT', 'amount' => '1.23', 'captured_amount' => '0', 'currency' => 'HKD',
            'merchant_order_id' => 'SYNTHETIC_PRIVATE_ORDER', 'status' => 'REQUIRES_PAYMENT_METHOD',
            'metadata' => ['sdk_validation_run' => 'SYNTHETIC_PRIVATE_RUN']];
    }

    private function evidence(): WebhookDeliveryEvidence
    {
        return new WebhookDeliveryEvidence(
            $this->store,
            'SYNTHETIC_PRIVATE_SECRET',
            str_repeat('c', 64),
            str_repeat('a', 64),
            str_repeat('b', 64),
            'SYNTHETIC_PRIVATE_ACCOUNT',
            'SYNTHETIC_PRIVATE_INTENT',
            'SYNTHETIC_PRIVATE_ORDER',
            'SYNTHETIC_PRIVATE_RUN',
            WebhookDeliveryFixtureRuntime::NOW,
            fn (): int => $this->now
        );
    }

    public function testOriginalReceiptFreshnessAndLaterMacEvidenceRemainDistinct(): void
    {
        $this->runtime->capture(self::object(), 'payment_intent.created');
        $this->now += 300001;
        $evidence = $this->evidence();
        $counts = $evidence->collect();
        self::assertSame(1, $counts['mac_verified_count']);
        self::assertSame(1, $counts['receipt_freshness_verified_count']);
        self::assertSame(0, $counts['current_freshness_verified_count']);
        self::assertSame(1, $counts['matching_created_count']);
        self::assertSame($counts, $evidence->collect());
        $metadata = json_decode($this->store->read('capture-000001.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertSame('000' . WebhookDeliveryFixtureRuntime::NOW, $metadata['timestamp']);
        ob_start();
        var_dump($evidence);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $debug);
        $this->expectException(ProtocolException::class);
        serialize($evidence);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function alteredProvenance(): iterable
    {
        yield 'context' => ['context', str_repeat('d', 64)];
        yield 'source' => ['source', str_repeat('d', 64)];
        yield 'registration' => ['registration', str_repeat('d', 64)];
        yield 'hash' => ['sha256', str_repeat('d', 64)];
        yield 'size' => ['length', 1];
        yield 'old receipt' => ['received_at_ms', WebhookDeliveryFixtureRuntime::NOW - 1001];
        yield 'future receipt' => ['received_at_ms', WebhookDeliveryFixtureRuntime::NOW + 1001];
        yield 'noninteger receipt' => ['received_at_ms', 'SYNTHETIC_PRIVATE_CLOCK'];
        yield 'overflow clock' => ['received_at_ms', PHP_INT_MAX];
        yield 'unknown format' => ['format', 2];
    }

    #[DataProvider('alteredProvenance')]
    public function testCaptureCannotBorrowAnotherReceiptContextOrAlteredOriginalBytes(string $key, mixed $value): void
    {
        $this->runtime->metadataFilter = static function (array $metadata) use ($key, $value): array {
            $changed = [];
            foreach ($metadata as $name => $entry) {
                if (!is_string($name)) {
                    throw new ProtocolException('Invalid synthetic metadata keys.');
                }
                $changed[$name] = $entry;
            }
            $changed[$key] = $value;
            return $changed;
        };
        $this->runtime->capture(self::object(), 'payment_intent.created');
        $this->expectException(ProtocolException::class);
        $this->evidence()->collect();
    }

    public function testExactDuplicateDeliveryIsCountedWithoutRepeatingAnyApplicationWork(): void
    {
        $this->runtime->eventFilter = static function (array $event): array {
            $event['id'] = 'SYNTHETIC_PRIVATE_SAME_EVENT';
            return $event;
        };
        $this->runtime->capture(self::object(), 'payment_intent.created');
        $this->runtime->capture(self::object(), 'payment_intent.created');
        $counts = $this->evidence()->collect();
        self::assertSame(2, $counts['matching_created_count']);
        self::assertSame(1, $counts['duplicate_matching_count']);
        self::assertSame(0, $counts['matching_cancelled_count']);
        self::assertSame($this->store->read('capture-000001.body'), $this->store->read('capture-000002.body'));
    }

    public function testValidMacForInvalidJsonIsRetainedWithoutFabricatingAnEvent(): void
    {
        $this->runtime->bodyFilter = static fn (string $body): string => 'SYNTHETIC_PRIVATE_INVALID_JSON';
        $this->runtime->capture(self::object(), 'payment_intent.created');
        $counts = $this->evidence()->collect();
        self::assertSame(1, $counts['mac_verified_count']);
        self::assertSame(1, $counts['unrecognized_or_foreign_count']);
        self::assertSame(0, $counts['matching_created_count']);
        self::assertSame('SYNTHETIC_PRIVATE_INVALID_JSON', $this->store->read('capture-000001.body'));
    }
}
