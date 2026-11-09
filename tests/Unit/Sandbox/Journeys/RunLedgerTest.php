<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class RunLedgerTest extends TestCase
{
    private const string CONTEXT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-ledger-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
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
            if (is_dir($path) && !is_link($path)) {
                self::remove($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    public function testIdentityIsPersistedBeforeReturningAndCannotChangeIntent(): void
    {
        $index = 0;
        $ledger = RunLedger::create(self::CONTEXT, $this->directory, static function () use (&$index): string {
            return sprintf('00000000-0000-4000-8000-%012d', ++$index);
        });
        $id = $ledger->requestId('create_customer', ['name' => 'sanitized'], self::CONTEXT);
        $file = $this->directory . '/' . $ledger->runId() . '/ledger.json';
        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['actions']);
        self::assertIsArray($data['actions']['create_customer']);
        self::assertSame($id, $data['actions']['create_customer']['request_id']);
        self::assertSame($id, $ledger->requestId('create_customer', ['name' => 'sanitized'], self::CONTEXT));
        self::assertSame(self::CONTEXT, $data['context_fingerprint']);
        self::assertSame($ledger->evidence()['source'], $data['source']);
        foreach ($ledger->evidence()['source'] as $key => $value) {
            self::assertMatchesRegularExpression(
                $key === 'base_commit' ? '/^[a-f0-9]{40,64}$/D' : '/^[a-f0-9]{64}$/D',
                $value,
            );
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, fileperms($file) & 0777);
            self::assertSame(0700, fileperms(dirname($file)) & 0777);
        }
        $this->expectException(RuntimeException::class);
        $ledger->requestId('create_customer', ['name' => 'different action'], self::CONTEXT);
    }

    public function testCleanupInventoryContainsOnlyRecordedCreationsAndDebugDoesNotExposeIds(): void
    {
        $ledger = RunLedger::create(
            self::CONTEXT,
            $this->directory,
            static fn (): string => '00000000-0000-4000-8000-000000000001',
        );
        $ledger->recordCreated('products', 'test-created-product-id');
        $ledger->recordCreated('products', 'test-created-product-id');
        self::assertSame(['test-created-product-id'], $ledger->created('products'));
        self::assertSame([], $ledger->created('prices'));
        self::assertSame(['products' => 1], $ledger->counts());
        self::assertStringNotContainsString('test-created-product-id', print_r($ledger, true));
        $this->expectException(RuntimeException::class);
        serialize($ledger);
    }

    public function testObservedItemIdentitiesAreDurablePrivateAndCannotReplaceAnEarlierPhase(): void
    {
        $ledger = RunLedger::create(self::CONTEXT, $this->directory);
        $references = ['subscription_id' => 'fixture-private-subscription', 'item_id' => 'fixture-private-item'];
        $ledger->recordObservation('before_quantity', $references, self::CONTEXT);
        $state = json_decode((string) file_get_contents(
            $this->directory . '/' . $ledger->runId() . '/ledger.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsArray($state['observations']);
        self::assertSame($references, $state['observations']['before_quantity']);
        self::assertStringNotContainsString(
            'fixture-private',
            json_encode($ledger->evidence(), JSON_THROW_ON_ERROR) . print_r($ledger, true)
        );
        $this->expectException(RuntimeException::class);
        $ledger->recordObservation('before_quantity', ['item_id' => 'replacement'], self::CONTEXT);
    }

    public function testUrlStateSourcesAreRejected(): void
    {
        $this->expectException(RuntimeException::class);
        RunLedger::create(self::CONTEXT, 'https://example.test/state');
    }

    /**
     * @return iterable<string,array{bool}> */
    public static function checkoutMarkers(): iterable
    {
        yield 'ordinary checkout directory' => [false];
        yield 'worktree git file' => [true];
    }

    #[DataProvider('checkoutMarkers')]
    public function testStateCannotBeCreatedWithinAnyGitCheckout(bool $worktree): void
    {
        if ($worktree) {
            file_put_contents($this->directory . '/.git', 'gitdir: /placeholder');
        } else {
            mkdir($this->directory . '/.git');
        }
        mkdir($this->directory . '/nested');
        $this->expectExceptionMessage('Private run state must remain outside Git checkouts.');
        RunLedger::create(self::CONTEXT, $this->directory . '/nested');
    }

    public function testParentDirectoryIsSynchronizedBeforeInitialLedgerAndRunDirectoryAfterRename(): void
    {
        $synchronized = [];
        $ledger = RunLedger::create(self::CONTEXT, $this->directory, synchronize: static function ($stream) use (
            &$synchronized,
        ): bool {
            $synchronized[] = array_intersect_key(stream_get_meta_data($stream), ['uri' => true]);
            return fsync($stream);
        });
        $directory = realpath($this->directory);
        self::assertNotFalse($directory);
        $folder = $directory . '/' . $ledger->runId();
        self::assertSame([
            ['uri' => $directory], ['uri' => $folder . '/ledger.json.writing'], ['uri' => $folder],
        ], $synchronized);
        $ledger->requestId('create', [], self::CONTEXT);
        self::assertSame([
            ['uri' => $directory], ['uri' => $folder . '/ledger.json.writing'], ['uri' => $folder],
            ['uri' => $folder . '/ledger.json.writing'], ['uri' => $folder],
        ], $synchronized);
    }

    /**
     * @return iterable<string,array{int}> */
    public static function failedSynchronization(): iterable
    {
        yield 'file synchronization' => [4];
        yield 'directory synchronization after rename' => [5];
    }

    #[DataProvider('failedSynchronization')]
    public function testFailedPersistencePoisonsEvenAnAlreadyInsertedActionIdentity(int $failure): void
    {
        $calls = 0;
        $ledger = RunLedger::create(self::CONTEXT, $this->directory, synchronize: static function ($stream) use (
            &$calls,
            $failure,
        ): bool {
            return ++$calls !== $failure && fsync($stream);
        });
        try {
            $ledger->requestId('not-durably-committed', ['name' => 'fixture'], self::CONTEXT);
            self::fail('A failed synchronization must stop before returning a mutation identity.');
        } catch (RuntimeException) {
            self::assertSame($failure, $calls);
        }
        $this->expectExceptionMessage('Run state is unavailable or belongs to another account context.');
        $ledger->requestId('not-durably-committed', ['name' => 'fixture'], self::CONTEXT);
    }

    public function testMismatchedOwnerCannotReuseARecordedMutationIdentity(): void
    {
        $ledger = RunLedger::create(self::CONTEXT, $this->directory);
        $ledger->requestId('create', [], self::CONTEXT);
        $this->expectExceptionMessage('Run state is unavailable or belongs to another account context.');
        $ledger->requestId('create', [], str_repeat('b', 64));
    }

    public function testAnEarlierDurableIdentityAlsoBecomesUnavailableAfterALaterFailedFlush(): void
    {
        $calls = 0;
        $ledger = RunLedger::create(self::CONTEXT, $this->directory, synchronize: static function ($stream) use (
            &$calls,
        ): bool {
            return ++$calls !== 6 && fsync($stream);
        });
        $ledger->requestId('earlier', [], self::CONTEXT);
        try {
            $ledger->requestId('later', [], self::CONTEXT);
            self::fail('Expected a failed flush.');
        } catch (RuntimeException) {
            self::assertSame(6, $calls);
        }
        $this->expectExceptionMessage('Run state is unavailable or belongs to another account context.');
        $ledger->requestId('earlier', [], self::CONTEXT);
    }

    private function cleanupPath(RunLedger $ledger): string
    {
        return $this->directory . '/' . $ledger->runId() . '/ledger.json';
    }

    private static function rawObject(int $bytes, string $unit = ' '): string
    {
        $inside = $bytes - 8;
        return '{"x":"' . str_repeat($unit, intdiv($inside, strlen($unit)))
            . str_repeat(' ', $inside % strlen($unit)) . '"}';
    }

    public function testCleanupAllocationAdmitsAllSixSlotsWithWorstCaseUtf8ExpansionBeforeRetention(): void
    {
        $ledger = RunLedger::createConsentCleanup(self::CONTEXT, $this->directory);
        foreach (['customer_initial', 'consent_initial', 'customer_guard', 'consent_guard'] as $role) {
            $ledger->recordObservation('consent_cleanup_' . $role . '_result', [
                'private_json' => self::rawObject(32768, "\u{0080}"),
            ], self::CONTEXT);
        }
        $ledger->reserveCleanupCompletion(self::CONTEXT);
        foreach (['disable', 'consent_readback'] as $role) {
            $ledger->recordObservation('consent_cleanup_' . $role . '_result', [
                'private_json' => self::rawObject(65536, "\u{10000}"),
            ], self::CONTEXT);
        }
        $ledger->recordObservation('consent_cleanup_complete', ['outcome' => 'fixture'], self::CONTEXT);
        $size = $ledger->cleanupStorage();
        self::assertSame(262144, $size['raw_bytes']);
        self::assertSame(131072, $size['pre_action_bytes']);
        self::assertLessThanOrEqual(65536, $size['metadata_bytes']);
        self::assertLessThanOrEqual(3 * $size['raw_bytes'] + $size['metadata_bytes'], $size['image_bytes']);
        self::assertLessThanOrEqual(851968, $size['image_bytes']);
        self::assertLessThan(2097152, 2 * 1000000 + 4096);
        self::assertSame($size['image_bytes'], filesize($this->cleanupPath($ledger)));
        self::assertFileDoesNotExist($this->cleanupPath($ledger) . '.writing');
        self::assertSame([], $ledger->counts());
        // This unit-only total+1 state necessarily also crosses the independent pre-action cap.
        $property = new \ReflectionProperty(RunLedger::class, 'state');
        $state = $property->getValue($ledger);
        self::assertIsArray($state);
        self::assertIsArray($state['observations']);
        $key = 'consent_cleanup_customer_initial_result';
        self::assertIsArray($state['observations'][$key]);
        self::assertIsString($state['observations'][$key]['private_json']);
        $state['observations'][$key]['private_json'] .= ' ';
        $property->setValue($ledger, $state);
        $before = file_get_contents($this->cleanupPath($ledger));
        try {
            $ledger->recordObservation('over_allocation', ['fixed' => 'one'], self::CONTEXT);
            self::fail('The raw total/pre-action plus-one state was retained.');
        } catch (RuntimeException $error) {
            if ($error instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $error;
            }
            self::assertSame($before, file_get_contents($this->cleanupPath($ledger)));
            self::assertFileDoesNotExist($this->cleanupPath($ledger) . '.writing');
        }
    }

    /**
     * @return iterable<string,array{string}> */
    public static function cleanupStorageFailures(): iterable
    {
        foreach (
            ['pre_action_plus_one', 'metadata', 'invalid_utf8', 'unexpected_slot', 'post_without_reserve',
            'body_plus_one', 'inventory', 'completion_reserve'] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('cleanupStorageFailures')]
    public function testCleanupRejectsAllocationOrEncodingBeforeOpeningTemporaryFile(string $case): void
    {
        $ledger = RunLedger::createConsentCleanup(self::CONTEXT, $this->directory);
        if ($case === 'pre_action_plus_one') {
            foreach (['customer_initial', 'consent_initial'] as $role) {
                $ledger->recordObservation('consent_cleanup_' . $role . '_result', [
                    'private_json' => self::rawObject(65536),
                ], self::CONTEXT);
            }
        }
        if ($case === 'completion_reserve') {
            $ledger->recordObservation('large_metadata', ['fixed' => str_repeat('x', 50000)], self::CONTEXT);
        }
        $path = $this->cleanupPath($ledger);
        $before = file_get_contents($path);
        try {
            match ($case) {
                'inventory' => $ledger->recordCreated('payment_consents', 'original-not-created'),
                'completion_reserve' => $ledger->reserveCleanupCompletion(self::CONTEXT),
                default => $ledger->recordObservation(
                    $case === 'unexpected_slot' ? 'unlisted' : ($case === 'post_without_reserve'
                        ? 'consent_cleanup_disable_result' : 'consent_cleanup_customer_guard_result'),
                    match ($case) {
                        'metadata' => ['fixed' => str_repeat('x', 65536)],
                    'invalid_utf8' => ['private_json' => "{\"x\":\"\xff\"}"],
                    'post_without_reserve' => ['private_json' => '{}'],
                    'body_plus_one' => ['private_json' => self::rawObject(65537)],
                    default => ['private_json' => '{}'],
                    },
                    self::CONTEXT
                ),
            };
            self::fail('An invalid image returned permission.');
        } catch (RuntimeException $error) {
            if ($error instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $error;
            }
            self::assertSame($before, file_get_contents($path));
            self::assertFileDoesNotExist($path . '.writing');
        }
        $this->expectException(RuntimeException::class);
        $ledger->assertCleanupDurable(self::CONTEXT);
    }

    public function testCleanupPostSlotsRequireReserveAndHardCompleteImageThresholdIsExact(): void
    {
        $method = new \ReflectionMethod(RunLedger::class, 'boundedCleanupImage');
        $exact = $method->invoke(null, str_repeat('x', 1000000));
        self::assertIsString($exact);
        self::assertSame(1000000, strlen($exact));
        $this->expectException(RuntimeException::class);
        $method->invoke(null, str_repeat('x', 1000001));
    }

    public function testDefaultWriterRetainsItsOriginalLargeImageAndEncodingFailureOrder(): void
    {
        $ledger = RunLedger::create(self::CONTEXT, $this->directory);
        $ledger->recordObservation('default', ['fixed' => str_repeat('x', 1000001)], self::CONTEXT);
        self::assertGreaterThan(1000000, filesize($this->cleanupPath($ledger)));
        $before = file_get_contents($this->cleanupPath($ledger));
        try {
            $ledger->recordObservation('invalid_utf8', ['fixed' => "\xff"], self::CONTEXT);
            self::fail('Invalid UTF-8 was encoded.');
        } catch (RuntimeException $error) {
            if ($error instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $error;
            }
            self::assertSame($before, file_get_contents($this->cleanupPath($ledger)));
            self::assertFileExists($this->cleanupPath($ledger) . '.writing');
            self::assertSame(0, filesize($this->cleanupPath($ledger) . '.writing'));
        }
    }

    #[DataProvider('failedSynchronization')]
    public function testBoundedIoFailuresNeverReturnAnIdentityOrResetHealth(int $failure): void
    {
        $calls = 0;
        $ledger = RunLedger::createConsentCleanup(
            self::CONTEXT,
            $this->directory,
            synchronize: static function ($stream) use (&$calls, $failure): bool {
                return ++$calls !== $failure && fsync($stream);
            }
        );
        try {
            $ledger->requestId('consent_disable_cleanup', [], self::CONTEXT);
            self::fail('A failed file or parent fsync returned its UUID.');
        } catch (RuntimeException $error) {
            if ($error instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $error;
            }
            self::assertSame($failure, $calls);
            foreach (glob(dirname($this->cleanupPath($ledger)) . '/*') ?: [] as $file) {
                self::assertLessThanOrEqual(1000000, filesize($file));
            }
        }
        $this->expectException(RuntimeException::class);
        $ledger->assertCleanupDurable(self::CONTEXT);
    }


    public function testDefaultJournalBytesAndDurabilityOrderMatchTheImmutablePreCleanupWriter(): void
    {
        $git = new \ReflectionMethod(
            \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliation::class,
            'git'
        );
        $source = $git->invoke(null, dirname(__DIR__, 4), ['show',
            '5ee2c7640f763e062d6ad0144973fc7f9763fe64:tests/Support/Sandbox/Journeys/RunLedger.php']);
        self::assertIsString($source);
        $name = 'Jojeringnax\\Airwallex\\Tests\\Support\\Sandbox\\Journeys\\ConsentCleanupLegacyLedgerOracle';
        if (!class_exists($name, false)) {
            eval(str_replace(
                'final class RunLedger',
                'final class ConsentCleanupLegacyLedgerOracle',
                substr($source, 5)
            ));
        }
        if (!class_exists($name, false)) {
            throw new RuntimeException('The immutable writer oracle is unavailable.');
        }
        $legacy = new \ReflectionMethod($name, 'create');
        $images = [];
        $traces = [];
        foreach (['before', 'current'] as $which) {
            $directory = $this->directory . '/' . $which;
            mkdir($directory, 0700);
            $index = 0;
            $ids = static function () use (&$index): string {
                return sprintf('00000000-0000-4000-8000-%012d', ++$index);
            };
            $sync = static function ($stream) use (&$traces, $which, $directory): bool {
                self::assertIsResource($stream);
                $uri = self::streamPath(stream_get_meta_data($stream));
                $traces[$which][] = str_replace($directory, '[ROOT]', $uri);
                return fsync($stream);
            };
            $ledger = $which === 'before'
                ? $legacy->invoke(null, self::CONTEXT, $directory, $ids, $sync)
                : RunLedger::create(self::CONTEXT, $directory, $ids, $sync);
            self::assertIsObject($ledger);
            $type = new \ReflectionObject($ledger);
            $type->getMethod('requestId')->invoke($ledger, 'create', ['key' => "fixture\u{0080}"], self::CONTEXT);
            $type->getMethod('recordCreated')->invoke($ledger, 'products', 'PRIVATE_PRODUCT');
            $type->getMethod('recordObservation')->invoke($ledger, 'observed', ['field' => 'PRIVATE'], self::CONTEXT);
            $paths = glob($directory . '/*/ledger.json') ?: [];
            self::assertCount(1, $paths);
            $images[] = file_get_contents($paths[0]);
        }
        self::assertSame($images[0], $images[1]);
        self::assertSame($traces['before'], $traces['current']);
    }

    /** @return iterable<string,array{string}> */
    public static function changedCleanupImages(): iterable
    {
        foreach (['bytes', 'mode', 'directory_mode', 'symlink', 'temporary'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('changedCleanupImages')]
    public function testChangedBoundedImageCannotBeRewrittenIntoFreshPermission(string $case): void
    {
        $ledger = RunLedger::createConsentCleanup(self::CONTEXT, $this->directory);
        $path = $this->cleanupPath($ledger);
        $before = file_get_contents($path);
        self::assertIsString($before);
        if ($case === 'bytes') {
            file_put_contents($path, $before . ' ');
        } elseif ($case === 'mode') {
            chmod($path, 0644);
        } elseif ($case === 'directory_mode') {
            chmod(dirname($path), 0755);
        } elseif ($case === 'temporary') {
            file_put_contents($path . '.writing', 'stale');
        } else {
            rename($path, $path . '.fixture-original');
            symlink($path . '.fixture-original', $path);
        }
        try {
            $ledger->requestId('consent_disable_cleanup', [], self::CONTEXT);
            self::fail('Changed private journal state was silently replaced.');
        } catch (RuntimeException $error) {
            if ($error instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $error;
            }
            self::assertSame($case === 'bytes' ? $before . ' ' : $before, file_get_contents($path));
        }
        $this->expectException(RuntimeException::class);
        $ledger->assertCleanupDurable(self::CONTEXT);
    }

    /** @return iterable<string,array{int}> */
    public static function exactMetadataBoundaries(): iterable
    {
        yield 'exact J0' => [0];
        yield 'J0 plus one' => [1];
    }

    #[DataProvider('exactMetadataBoundaries')]
    public function testFullMetadataImageBoundaryBeforeTemporaryRetention(int $extra): void
    {
        $ledger = RunLedger::createConsentCleanup(self::CONTEXT, $this->directory);
        $path = $this->cleanupPath($ledger);
        $before = file_get_contents($path);
        self::assertIsString($before);
        $state = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsArray($state['observations']);
        $state['observations']['boundary'] = ['value' => ''];
        $empty = strlen(json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $padding = str_repeat('x', 65536 - $empty + $extra);
        $state['observations']['boundary']['value'] = $padding;
        self::assertSame(65536 + $extra, strlen(json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)));
        try {
            $ledger->recordObservation('boundary', ['value' => $padding], self::CONTEXT);
            self::assertSame(0, $extra);
            self::assertSame(65536, $ledger->cleanupStorage()['metadata_bytes']);
        } catch (RuntimeException $error) {
            if ($error instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $error;
            }
            self::assertSame(1, $extra);
            self::assertSame($before, file_get_contents($path));
        }
        self::assertFileDoesNotExist($path . '.writing');
    }

    public function testReservedPostActionMetadataPlusOneCannotRetainAnotherImage(): void
    {
        $ledger = RunLedger::createConsentCleanup(self::CONTEXT, $this->directory);
        $ledger->reserveCleanupCompletion(self::CONTEXT);
        $path = $this->cleanupPath($ledger);
        $before = file_get_contents($path);
        try {
            $ledger->recordObservation('future_metadata', ['value' => str_repeat('x', 16385)], self::CONTEXT);
            self::fail('Unreserved completion metadata was retained.');
        } catch (RuntimeException $error) {
            if ($error instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $error;
            }
            self::assertSame($before, file_get_contents($path));
            self::assertFileDoesNotExist($path . '.writing');
        }
    }

    public function testBoundedDebugHidesEvenTheNewRunIdAndSerializationIsForbidden(): void
    {
        $ledger = RunLedger::createConsentCleanup(self::CONTEXT, $this->directory);
        self::assertStringNotContainsString($ledger->runId(), print_r($ledger, true));
        $this->expectException(RuntimeException::class);
        serialize($ledger);
    }
    private static function streamPath(mixed $metadata): string
    {
        self::assertIsArray($metadata);
        $uri = $metadata['uri'] ?? null;
        self::assertIsString($uri);
        return $uri;
    }

    public function testStreamPathWitnessRequiresNativeStringMetadata(): void
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        try {
            self::assertSame('php://temp', self::streamPath(stream_get_meta_data($stream)));
        } finally {
            fclose($stream);
        }
        foreach ([null, false, [], ['uri' => null], ['uri' => 1], ['uri' => []]] as $bad) {
            $rejected = false;
            try {
                self::streamPath($bad);
            } catch (\PHPUnit\Framework\AssertionFailedError) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'A fixture stream path requires a native string.');
        }
    }
}
