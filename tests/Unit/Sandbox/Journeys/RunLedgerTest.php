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

    /** @return iterable<string,array{bool}> */
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

    /** @return iterable<string,array{int}> */
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
}
