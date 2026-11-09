<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BillingWebhookCommandTest extends TestCase
{
    /** @return iterable<string,array{list<string>}> */
    public static function rejectedArguments(): iterable
    {
        yield 'missing options' => [[]];
        yield 'production' => [['--environment=production']];
        yield 'nonexistent external' => [['--environment=sandbox',
            '--credentials-file=/no/SYNTHETIC_PRIVATE_FILE', '--state-dir=/no/SYNTHETIC_PRIVATE_STATE']];
        yield 'unknown target' => [['--environment=sandbox', '--url=https://SYNTHETIC_PRIVATE_RECEIVER.test']];
        yield 'context' => [['--environment=sandbox', '--context=organization']];
        yield 'SDK root' => [['--environment=sandbox', '--sdk-root=/SYNTHETIC_PRIVATE_ROOT']];
        yield 'positional' => [['--environment=sandbox', 'SYNTHETIC_PRIVATE_POSITIONAL']];
        yield 'duplicate' => [['--environment=sandbox', '--environment=sandbox']];
        yield 'not equals' => [['--environment', 'sandbox']];
        yield 'ID' => [['--id=SYNTHETIC_PRIVATE_ID']];
        yield 'old ledger' => [['--ledger=/SYNTHETIC_PRIVATE_LEDGER']];
        yield 'profile selector' => [['--management-profile=DEFAULT']];
        yield 'false flag' => [['--create-one-billing-webhook-and-checkout=false']];
        yield 'duplicate flag' => [['--create-one-billing-webhook-and-checkout',
            '--create-one-billing-webhook-and-checkout']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('rejectedArguments')]
    public function testRealCliFailsWithoutReadingConfigurationOrPrintingInputs(array $arguments): void
    {
        $this->cli($arguments);
        $this->cli($arguments, defaultLogin: true);
    }

    public function testNoArgvUnknownRootStillFailsWithoutWarnings(): void
    {
        $this->cli(
            ['--environment=sandbox', '--sdk-root=/SYNTHETIC_PRIVATE_ROOT'],
            ['-d', 'register_argc_argv=0']
        );
        $this->cli(
            ['--environment=sandbox', '--sdk-root=/SYNTHETIC_PRIVATE_ROOT'],
            ['-d', 'register_argc_argv=0'],
            true
        );
    }

    public function testUnavailableStreamWrapperConfigurationFailsBeforeProvenanceOrAuthentication(): void
    {
        $this->cli(['--environment=sandbox', '--credentials-file=/SYNTHETIC_PRIVATE_FILE',
            '--state-dir=/SYNTHETIC_PRIVATE_STATE'], ['-d', 'allow_url_fopen=0']);
        $this->cli(['--environment=sandbox', '--credentials-file=/SYNTHETIC_PRIVATE_FILE',
            '--state-dir=/SYNTHETIC_PRIVATE_STATE'], ['-d', 'allow_url_fopen=0'], true);
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $phpOptions
     */
    private function cli(array $arguments, array $phpOptions = [], bool $defaultLogin = false): void
    {
        $tool = $defaultLogin ? 'billing-webhook-default-login-probe.php' : 'billing-webhook-delivery-probe.php';
        $process = proc_open(
            [PHP_BINARY, ...$phpOptions,
            dirname(__DIR__, 4) . '/tools/' . $tool, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        [$input, $outputPipe, $errorPipe] = self::pipes($pipes);
        fclose($input);
        $output = stream_get_contents($outputPipe);
        $error = stream_get_contents($errorPipe);
        fclose($outputPipe);
        fclose($errorPipe);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $error);
        self::assertIsString($output);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $output);
        $safe = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($safe);
        self::assertSame('fail', $safe['result']);
        self::assertSame('configuration', $safe['phase']);
        self::assertFalse($safe['registration_created']);
        self::assertFalse($safe['checkout_created']);
        self::assertFalse($safe['registration_outcome_unknown']);
        self::assertSame(
            hash_file('sha256', dirname(__DIR__, 4) . '/tools/' . $tool),
            $safe['entry_sha256']
        );
        self::assertSame($defaultLogin ? 'default-login' : 'account', $safe['management_context']);
        if ($defaultLogin) {
            self::assertSame('DEFAULT', $safe['management_profile']);
        } else {
            self::assertArrayNotHasKey('management_profile', $safe);
        }
    }
    /** @return array{resource,resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (!is_array($pipes)) {
            throw new RuntimeException('Synthetic CLI pipes are unavailable.');
        }
        $input = $pipes[0] ?? null;
        $output = $pipes[1] ?? null;
        $error = $pipes[2] ?? null;
        if (!is_resource($input) || !is_resource($output) || !is_resource($error)) {
            throw new RuntimeException('Synthetic CLI pipes are unavailable.');
        }
        return [$input, $output, $error];
    }
}
