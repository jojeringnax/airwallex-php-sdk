<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Tools;

use PharData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ArtifactInspectionTest extends TestCase
{
    private string $temporary;
    private string $package;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/artifact-functions.php';
        require_once dirname(__DIR__, 3) . '/tools/consumer-functions.php';
    }

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/airwallex-artifact-test-' . bin2hex(random_bytes(8));
        $this->package = $this->temporary . '/package';
        foreach (['src', 'resources', 'docs', 'examples'] as $directory) {
            mkdir($this->package . '/' . $directory, 0700, true);
        }
        $metadata = [
            'name' => 'jojeringnax/airwallex-php-sdk', 'license' => 'MIT',
            'require' => [
                'php' => '>=8.3 <8.6', 'psr/http-client' => '^1.0', 'psr/http-factory' => '^1.0',
                'psr/http-message' => '^2.0', 'psr/simple-cache' => '^3.0',
            ],
        ];
        file_put_contents($this->package . '/composer.json', json_encode($metadata, JSON_THROW_ON_ERROR));
        foreach (
            [
                'LICENSE', 'README.md', 'SECURITY.md', 'src/client.php',
                'resources/contract.json', 'docs/usage.md', 'examples/example.php',
            ] as $file
        ) {
            file_put_contents($this->package . '/' . $file, 'synthetic package fixture: ' . $file);
        }
    }

    protected function tearDown(): void
    {
        \removeDirectory($this->temporary);
    }

    public function testInspectedArchivePreservesEverySourceByteAndUsesArchivedMetadata(): void
    {
        $expected = \expectedPackageFiles($this->package);
        $archive = $this->archive($expected);
        $contents = \inspectPackageArchive($archive, \packageSourceContents($expected));
        self::assertSame(array_keys($expected), array_keys($contents));
        foreach ($expected as $name => $file) {
            self::assertSame(hash_file('sha256', $file), $contents[$name]['sha256']);
        }
        $package = \archivedPackageDefinition($archive);
        self::assertSame('dev-main', $package['version']);
        self::assertSame(['type' => 'tar', 'url' => $archive], $package['dist']);
    }

    public function testArchiveContainingExternalConfigurationIsRejectedWithoutPrintingIt(): void
    {
        $expected = \expectedPackageFiles($this->package);
        $archive = $this->archive($expected);
        (new PharData($archive))->addFromString('.env', 'SYNTHETIC_PRIVATE_CONFIGURATION');
        try {
            \inspectPackageArchive($archive, \packageSourceContents($expected));
            self::fail('External configuration was accepted into the distribution.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE_CONFIGURATION', $error->getMessage());
        }
    }

    public function testMissingRuntimeContractFailsEvenWhenSourceAutoloadFilesExist(): void
    {
        $expected = \expectedPackageFiles($this->package);
        $archiveFiles = $expected;
        unset($archiveFiles['resources/contract.json']);
        $archive = $this->archive($archiveFiles);
        $this->expectExceptionMessage('Package archive omits required source, schemas, documentation or examples.');
        \inspectPackageArchive($archive, \packageSourceContents($expected));
    }

    public function testChangedArchiveBytesFailSourceIntegrityCheck(): void
    {
        $expected = \expectedPackageFiles($this->package);
        $archive = $this->archive($expected);
        (new PharData($archive))->addFromString('src/client.php', 'changed bytes');
        $this->expectExceptionMessage('Package archive content does not match its source.');
        \inspectPackageArchive($archive, \packageSourceContents($expected));
    }

    public function testDistributionDirectoryCannotLinkToExternalFiles(): void
    {
        $external = $this->temporary . '/external';
        mkdir($external, 0700);
        file_put_contents($external . '/private.txt', 'SYNTHETIC_EXTERNAL_CONTENT');
        self::assertTrue(symlink($external, $this->package . '/docs/external'));
        $this->expectExceptionMessage('Package content must be regular local files.');
        \expectedPackageFiles($this->package);
    }

    public function testIgnoredUntrackedFilesCannotEnterAnExactCommitCandidate(): void
    {
        $commit = $this->commitFixture();
        $committed = \committedPackageContents($this->package, $commit);
        file_put_contents($this->package . '/.git/info/exclude', "docs/ignored-private.txt\n");
        file_put_contents($this->package . '/docs/ignored-private.txt', 'SYNTHETIC_PRIVATE_CONTENT');
        self::assertSame('', \capturePackageCommand(['git', 'status', '--porcelain'], $this->package));
        $working = \expectedPackageFiles($this->package);
        self::assertNotSame($committed, \packageSourceContents($working));
        $archive = $this->archive($working);
        $this->expectExceptionMessage('Package archive contains content outside the distribution.');
        \inspectPackageArchive($archive, $committed);
    }

    public function testRetainedCandidateCommandRejectsIgnoredDataBeforeCreatingOutput(): void
    {
        mkdir($this->package . '/tools', 0700);
        foreach (['artifact-functions.php', 'consumer-functions.php', 'check-artifact.php'] as $tool) {
            copy(dirname(__DIR__, 3) . '/tools/' . $tool, $this->package . '/tools/' . $tool);
        }
        $this->commitFixture();
        file_put_contents($this->package . '/.git/info/exclude', "docs/ignored-private.txt\n");
        file_put_contents($this->package . '/docs/ignored-private.txt', 'SYNTHETIC_PRIVATE_CONTENT');
        self::assertSame('', \capturePackageCommand(['git', 'status', '--porcelain'], $this->package));
        $destination = $this->temporary . '/candidate';
        $process = proc_open([
            PHP_BINARY, $this->package . '/tools/check-artifact.php', '--output=' . $destination,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->package);
        if (!is_resource($process)) {
            self::fail('Cannot execute isolated candidate fixture.');
        }
        /** @var array{0:resource,1:resource,2:resource} $pipes */
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(0, proc_close($process));
        self::assertIsString($stdout);
        self::assertIsString($stderr);
        self::assertStringContainsString(
            'require a clean checkout with exact committed package bytes.',
            $stdout . $stderr
        );
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE_CONTENT', $stdout . $stderr);
        self::assertDirectoryDoesNotExist($destination);
    }

    /** @return iterable<string,array{string}> */
    public static function hiddenIndexFlags(): iterable
    {
        yield 'assume unchanged' => ['--assume-unchanged'];
        yield 'skip worktree' => ['--skip-worktree'];
    }

    #[DataProvider('hiddenIndexFlags')]
    public function testIndexFlagsCannotHideDifferentCandidateBytes(string $flag): void
    {
        $commit = $this->commitFixture();
        $committed = \committedPackageContents($this->package, $commit);
        \capturePackageCommand(['git', 'update-index', $flag, 'docs/usage.md'], $this->package);
        file_put_contents($this->package . '/docs/usage.md', 'SYNTHETIC_HIDDEN_TRACKED_CHANGE');
        self::assertSame('', \capturePackageCommand(['git', 'status', '--porcelain'], $this->package));
        $working = \expectedPackageFiles($this->package);
        self::assertNotSame($committed, \packageSourceContents($working));
        $archive = $this->archive($working);
        $this->expectExceptionMessage('Package archive content does not match its source.');
        \inspectPackageArchive($archive, $committed);
    }

    public function testCandidateArchiveExpectationsStayBoundToCommitAfterWorkingFilesChange(): void
    {
        file_put_contents($this->package . '/resources/exact.bin', "\0 leading and trailing bytes \n\0");
        $commit = $this->commitFixture();
        $committed = \committedPackageContents($this->package, $commit);
        self::assertSame($committed, \packageSourceContents(\expectedPackageFiles($this->package)));
        $archive = $this->archive(\expectedPackageFiles($this->package));
        file_put_contents($this->package . '/docs/usage.md', "changed after archiving\0\n");
        self::assertSame($committed, \inspectPackageArchive($archive, $committed));
    }

    public function testComposerExportRulesExcludeSyntheticSecretsAndAgentState(): void
    {
        copy(dirname(__DIR__, 3) . '/.gitattributes', $this->package . '/.gitattributes');
        file_put_contents($this->package . '/.env', 'SYNTHETIC_PRIVATE_CONFIGURATION');
        file_put_contents($this->package . '/AGENTS.md', 'synthetic local agent instructions');
        mkdir($this->package . '/.agents', 0700);
        file_put_contents($this->package . '/.agents/local.md', 'synthetic local state');
        \runCommand([
            ...\consumerComposerCommand(), 'archive', '--format=tar', '--dir=' . $this->temporary,
            '--file=export-rules', '--no-interaction', '--no-plugins', '--no-scripts',
        ], $this->package);
        $expected = \expectedPackageFiles($this->package);
        $contents = \inspectPackageArchive($this->temporary . '/export-rules.tar', \packageSourceContents($expected));
        self::assertSame(array_keys($expected), array_keys($contents));
        self::assertArrayNotHasKey('.env', $contents);
        self::assertArrayNotHasKey('AGENTS.md', $contents);
    }

    /** @param array<string,string> $files */
    private function archive(array $files): string
    {
        $path = $this->temporary . '/fixture.tar';
        $archive = new PharData($path);
        foreach ($files as $name => $file) {
            $archive->addFile($file, $name);
        }
        unset($archive);
        return $path;
    }

    private function commitFixture(): string
    {
        \capturePackageCommand(['git', 'init', '--quiet'], $this->package);
        \capturePackageCommand(['git', 'add', '--all'], $this->package);
        \capturePackageCommand([
            'git', '-c', 'user.name=Synthetic Artifact Test', '-c', 'user.email=artifact@example.test',
            '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'Synthetic package fixture',
        ], $this->package);
        return \capturePackageCommand(['git', 'rev-parse', 'HEAD'], $this->package);
    }
}
