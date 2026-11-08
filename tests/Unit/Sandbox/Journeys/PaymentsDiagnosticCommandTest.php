<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\PaymentsDiagnosticCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class PaymentsDiagnosticCommandTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'no arguments' => [[]];
        yield 'production' => [['environment' => 'production', 'credentials-file' => '/unread-private-fixture']];
        yield 'implicit environment' => [['credentials-file' => '/unread-private-fixture']];
        yield 'missing credential argument' => [['environment' => 'sandbox']];
        yield 'relative credential source' => [['environment' => 'sandbox', 'credentials-file' => 'relative-fixture']];
        yield 'URL credential source' => [[
            'environment' => 'sandbox', 'credentials-file' => 'https://example.test/private',
        ]];
        yield 'nonexistent source' => [[
            'environment' => 'sandbox', 'credentials-file' => '/nonexistent-private-fixture',
        ]];
        yield 'wrong option types' => [['environment' => 'sandbox', 'credentials-file' => [], 'state-dir' => []]];
    }

    /** @param array<string,mixed> $options */
    #[DataProvider('invalidConfiguration')]
    public function testRejectedConfigurationCannotSendEvenAuthentication(array $options): void
    {
        $provider = new PaymentsProvider();
        $result = PaymentsDiagnosticCommand::run($options, $provider);
        self::assertSame('fail', $result['result']);
        self::assertSame('2026-08-21', $result['api_version']);
        self::assertSame([], $provider->requests);
        foreach (['private-fixture', 'relative-fixture', 'example.test'] as $private) {
            self::assertStringNotContainsString($private, json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    public function testSameCommandRunsOnlyInjectedSandboxAccountTrafficAndReturnsAllowlistedEvidence(): void
    {
        $directory = sys_get_temp_dir() . '/airwallex-diagnostic-command-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        try {
            $credentials = $directory . '/synthetic-credentials';
            file_put_contents(
                $credentials,
                "AIRWALLEX_CLIENT_ID=fixture_client\nAIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_key\n"
                . "AIRWALLEX_ACCOUNT_ID=fixture_account\n"
            );
            $provider = new PaymentsProvider();
            $result = PaymentsDiagnosticCommand::run([
                'environment' => 'sandbox', 'credentials-file' => $credentials, 'state-dir' => $directory,
            ], $provider);
            self::assertSame('pass', $result['result']);
            self::assertSame('account', $result['context']);
            self::assertIsArray($result['diagnostics']);
            self::assertIsArray($result['diagnostics']['entries']);
            self::assertNotEmpty($result['diagnostics']['entries']);
            self::assertSame(0, $result['diagnostics']['entries_dropped']);
            foreach ($provider->requests as $request) {
                self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
                self::assertFalse($request->hasHeader('x-on-behalf-of'));
                if (str_ends_with($request->getUri()->getPath(), '/authentication/login')) {
                    self::assertSame('fixture_account', $request->getHeaderLine('x-login-as'));
                }
            }
            $json = json_encode($result['diagnostics'], JSON_THROW_ON_ERROR);
            foreach (['synthetic-', 'fixture_client', 'fixture_key', 'fixture_account', 'airwallex.com'] as $private) {
                self::assertStringNotContainsString($private, $json);
            }
        } finally {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                if (!$entry instanceof SplFileInfo) {
                    throw new RuntimeException('Unexpected fixture cleanup entry.');
                }
                if ($entry->isDir()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($directory);
        }
    }
}
