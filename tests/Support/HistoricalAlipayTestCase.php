<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support;

use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliation;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SourceEvidence;
use PharData;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;
use Throwable;

/** Runs the current historical-reconciliation harness with its original SDK in an isolated PHP process. */
abstract class HistoricalAlipayTestCase extends TestCase
{
    // The SDK used by the original ledger, independently of the current branch's SDK.
    private const string SOURCE = 'e089ff21de8d255990fc704506898201546c5467';
    private static ?string $root = null;

    protected function setUp(): void
    {
        foreach (get_declared_classes() as $class) {
            if (
                str_starts_with($class, 'Jojeringnax\\Airwallex\\')
                && !str_starts_with($class, 'Jojeringnax\\Airwallex\\Tests\\')
            ) {
                throw new RuntimeException('Historical SDK fixtures require a fresh PHP process.');
            }
        }
        $root = sys_get_temp_dir() . '/historical-alipay-sdk-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $root = realpath($root);
        self::assertIsString($root);
        self::$root = $root;
        try {
            self::git(['archive', '--format=tar', '--output=' . $root . '/sdk.tar', self::SOURCE,
                'src', 'resources/contracts', 'composer.json']);
            (new PharData($root . '/sdk.tar'))->extractTo($root);
            unlink($root . '/sdk.tar');
            // Package archives omit dotfiles, but source fingerprints include this tracked placeholder.
            file_put_contents($root . '/src/.gitkeep', self::git(['show', self::SOURCE . ':src/.gitkeep']));
            $current = dirname(__DIR__, 2);
            self::copyTree($current . '/tests/Support/Sandbox', $root . '/tests/Support/Sandbox');
            mkdir($root . '/tools', 0700);
            foreach (['sandbox.php', 'alipay-hk-consent-reuse.php', 'alipay-consent-reconciliation.php'] as $tool) {
                self::assertTrue(copy($current . '/tools/' . $tool, $root . '/tools/' . $tool));
            }
            // Reuse local objects for the harness's immutable-source/ancestor proofs without creating a worktree.
            $gitDirectory = trim(self::git(['rev-parse', '--absolute-git-dir']));
            file_put_contents($root . '/.git', 'gitdir: ' . $gitDirectory . "\n");
            mkdir($root . '/vendor', 0700);
            $autoload = '<?php' . "\nrequire " . var_export($current . '/vendor/autoload.php', true) . ";\n"
                . '$loader = new \\Composer\\Autoload\\ClassLoader();' . "\n"
                . '$loader->addPsr4("Jojeringnax\\\\Airwallex\\\\", dirname(__DIR__) . "/src");' . "\n"
                . '$loader->addPsr4("Jojeringnax\\\\Airwallex\\\\Tests\\\\Support\\\\Sandbox\\\\",'
                . ' dirname(__DIR__) . "/tests/Support/Sandbox");' . "\n"
                . '$loader->register(true);' . "\nreturn \$loader;\n";
            file_put_contents($root . '/vendor/autoload.php', $autoload);
            require $root . '/vendor/autoload.php';
            self::assertSame($root . '/src/Client.php', (new ReflectionClass(Client::class))->getFileName());
            self::assertSame(AlipayConsentReconciliation::SDK, SourceEvidence::capture()['sdk_digest']);
        } catch (Throwable $failure) {
            self::tearDownAfterClass();
            throw $failure;
        }
    }

    protected static function sdkRoot(): string
    {
        $root = self::$root;
        self::assertNotNull($root);
        return $root;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$root === null) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo) {
                throw new RuntimeException('Cannot identify a historical fixture file.');
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir(self::$root);
        self::$root = null;
    }

    private static function copyTree(string $source, string $target): void
    {
        mkdir($target, 0700, true);
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo) {
                throw new RuntimeException('Cannot identify a current harness file.');
            }
            $destination = $target . substr($file->getPathname(), strlen($source));
            if ($file->isDir()) {
                mkdir($destination, 0700);
            } elseif (!copy($file->getPathname(), $destination)) {
                throw new RuntimeException('Cannot copy the current harness into the historical fixture.');
            }
        }
    }

    /** @param list<string> $arguments */
    private static function git(array $arguments): string
    {
        $process = proc_open(
            ['git', '-C', dirname(__DIR__, 2), ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            env_vars: ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'LANG' => 'C',
                'GIT_ALLOW_PROTOCOL' => '', 'GIT_NO_LAZY_FETCH' => '1', 'GIT_NO_REPLACE_OBJECTS' => '1',
                'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null']
        );
        self::assertIsResource($process);
        $output = $pipes[1] ?? null;
        self::assertIsResource($output);
        $result = stream_get_contents($output);
        fclose($output);
        self::assertSame(0, proc_close($process), 'Historical SDK source must be available in local Git objects.');
        self::assertIsString($result);
        return $result;
    }
}
