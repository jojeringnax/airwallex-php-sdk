<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebhookDeliveryStoreTest extends TestCase
{
    private string $directory;
    private WebhookDeliveryStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sdk-webhook-store-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $ledger = RunLedger::create(str_repeat('a', 64), $this->directory);
        $this->store = new WebhookDeliveryStore($this->directory, $ledger);
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

    public function testSensitiveProofIsPrivateAtomicAndCannotReplaceEarlierSnapshot(): void
    {
        $raw = '{"secret":"SYNTHETIC_PRIVATE_SECRET","url":"https://SYNTHETIC_PRIVATE.test"}';
        $hash = $this->store->write('registration.json', $raw);
        self::assertSame(hash('sha256', $raw), $hash);
        self::assertSame($raw, $this->store->read('registration.json'));
        self::assertSame(0600, fileperms($this->store->folder() . '/registration.json') & 0777);
        self::assertSame(0700, fileperms($this->store->folder()) & 0777);
        try {
            $this->store->write('registration.json', 'replacement');
            self::fail('A private original was replaced.');
        } catch (RuntimeException) {
            self::assertSame($raw, $this->store->read('registration.json'));
        }
        $ledger = file_get_contents($this->store->folder() . '/ledger.json');
        self::assertIsString($ledger);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $ledger);
        ob_start();
        var_dump($this->store);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString($this->directory, $debug);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $debug);
        $this->expectException(RuntimeException::class);
        serialize($this->store);
    }

    /** @return iterable<string,array{string}> */
    public static function tampering(): iterable
    {
        foreach (['symlink', 'mode', 'oversize', 'directorymode', 'git', 'identity'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('tampering')]
    public function testPrivateEvidenceReadCannotEscapeOrUseChangedState(string $change): void
    {
        $folder = $this->store->folder();
        $path = $folder . '/registration.json';
        $this->store->write('registration.json', 'original');
        switch ($change) {
            case 'symlink':
                unlink($path);
                symlink('/no/fixture', $path);
                break;
            case 'mode':
                chmod($path, 0644);
                break;
            case 'oversize':
                file_put_contents($path, str_repeat('x', 65537));
                break;
            case 'directorymode':
                chmod($folder, 0755);
                break;
            case 'git':
                symlink('/no/fixture', $this->directory . '/.git');
                break;
            case 'identity':
                rename($folder, $folder . '-original');
                mkdir($folder, 0700);
                file_put_contents($folder . '/registration.json', 'foreign');
                chmod($folder . '/registration.json', 0600);
                break;
        }
        clearstatcache();
        $this->expectException(RuntimeException::class);
        $this->store->read('registration.json');
    }

    /** @return iterable<string,array{string}> */
    public static function unknownNames(): iterable
    {
        yield 'traversal' => ['../credentials'];
        yield 'absolute' => ['/tmp/credentials'];
        yield 'unknown' => ['private.env'];
        yield 'URL' => ['https://SYNTHETIC_PRIVATE.test'];
    }

    #[DataProvider('unknownNames')]
    public function testOnlyClosedPrivateFilenamesCanBeWritten(string $name): void
    {
        $this->expectException(RuntimeException::class);
        $this->store->write($name, 'SYNTHETIC_PRIVATE');
    }
    public function testOnlyTheTwoNewSubscriptionSnapshotsArePrivateExclusiveAndReadable(): void
    {
        foreach (['subscription-pending.json', 'subscription-cancelled.json'] as $name) {
            self::assertSame(hash('sha256', 'PRIVATE_SNAPSHOT'), $this->store->write($name, 'PRIVATE_SNAPSHOT'));
            self::assertSame('PRIVATE_SNAPSHOT', $this->store->read($name));
            self::assertSame(0600, fileperms($this->store->folder() . '/' . $name) & 0777);
        }
        $this->expectException(RuntimeException::class);
        $this->store->read('subscription-active.json');
    }
}
