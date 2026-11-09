<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\PaymentMethodConfigCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class PaymentMethodConfigCommandTest extends TestCase
{
    /** @return iterable<string,array{list<string>}> */
    public static function cliCases(): iterable
    {
        yield 'missing' => [[]];
        yield 'production' => [['--environment=production']];
        yield 'duplicate' => [['--environment=sandbox', '--environment=sandbox']];
        yield 'valued flag' => [['--read-one-alipay-hk-recurring-config=true']];
        yield 'duplicate flag' => [['--read-one-alipay-hk-recurring-config', '--read-one-alipay-hk-recurring-config']];
        foreach (['query', 'url', 'account-id', 'context', 'prior-ledger', 'sdk-root', 'timeout'] as $name) {
            yield $name => [['--' . $name . '=SYNTHETIC_PRIVATE']];
        }
        yield 'external missing' => [['--environment=sandbox', '--credentials-file=/SYNTHETIC_PRIVATE',
            '--state-dir=/SYNTHETIC_PRIVATE', '--read-one-alipay-hk-recurring-config']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('cliCases')]
    public function testActualCliNegativesRemainValueFree(array $arguments): void
    {
        $this->cli($arguments);
    }

    public function testArgvAndNativeStreamDisabledAreClosed(): void
    {
        $this->cli(['--environment=sandbox'], ['-d', 'register_argc_argv=0']);
        $this->cli(['--environment=sandbox', '--credentials-file=/SYNTHETIC_PRIVATE',
            '--state-dir=/SYNTHETIC_PRIVATE', '--read-one-alipay-hk-recurring-config'], ['-d', 'allow_url_fopen=0']);
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $phpOptions
     */
    private function cli(array $arguments, array $phpOptions = []): void
    {
        $process = proc_open(
            [PHP_BINARY, ...$phpOptions,
            dirname(__DIR__, 4) . '/tools/payment-method-config-observation.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The synthetic CLI child is unavailable.');
        }
        try {
            [$input, $output, $errors] = self::pipes($pipes);
            fclose($input);
            $out = stream_get_contents($output);
            $err = stream_get_contents($errors);
            self::assertIsString($out);
            self::assertSame('', $err);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $out);
            $safe = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($safe);
            self::assertSame('fail', $safe['result']);
            self::assertSame('configuration', $safe['phase']);
            self::assertSame(0, $safe['resource_mutations_sent']);
        } finally {
            self::closePipes($pipes);
            $exit = proc_close($process);
        }
        self::assertSame(1, $exit);
    }

    /** @return array{resource,resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (!is_array($pipes)) {
            throw new RuntimeException('Synthetic child pipes are unavailable.');
        }
        $input = $pipes[0] ?? null;
        $output = $pipes[1] ?? null;
        $errors = $pipes[2] ?? null;
        if (!is_resource($input) || !is_resource($output) || !is_resource($errors)) {
            throw new RuntimeException('Synthetic child pipes are unavailable.');
        }
        return [$input, $output, $errors];
    }

    /** @return iterable<string,array{string}> */
    public static function invalidPaths(): iterable
    {
        foreach (['credentials-mode', 'state-mode', 'credentials-symlink', 'state-symlink', 'git'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('invalidPaths')]
    public function testPrivateIntakeRejectsInvalidModesSymlinksAndGitBeforeAuth(string $mode): void
    {
        $f = PaymentMethodConfigObservationTest::fixture();
        $calls = 0;
        try {
            $options = PaymentMethodConfigObservationTest::options($f);
            if ($mode === 'credentials-mode') {
                chmod($f['credentials'], 0644);
            } elseif ($mode === 'state-mode') {
                chmod($f['state'], 0755);
            } elseif ($mode === 'credentials-symlink') {
                symlink($f['credentials'], $f['base'] . '/link');
                $options['credentials-file'] = $f['base'] . '/link';
            } elseif ($mode === 'state-symlink') {
                symlink($f['state'], $f['base'] . '/link');
                $options['state-dir'] = $f['base'] . '/link';
            } else {
                mkdir($f['base'] . '/.git', 0700);
            }
            $client = PaymentMethodConfigObservationTest::client(static function () use (&$calls): ResponseInterface {
                ++$calls;
                return PaymentMethodConfigObservationTest::login();
            });
            $result = PaymentMethodConfigCommand::run($options, $client);
            self::assertSame('fail', $result['result']);
            self::assertSame(0, $calls);
        } finally {
            PaymentMethodConfigObservationTest::remove($f['base']);
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function sourceChanges(): iterable
    {
        foreach (['prepared', 'possible_invocation'] as $stage) {
            foreach (['credential', 'source', 'context', 'deadline'] as $change) {
                yield $stage . '/' . $change => [$stage, $change];
            }
        }
    }

    #[DataProvider('sourceChanges')]
    public function testPostFsyncOriginalProofStopsChangedSourceCredentialsContextOrClock(
        string $stage,
        string $change
    ): void {
        $f = PaymentMethodConfigObservationTest::fixture();
        $tool = dirname(__DIR__, 4) . '/tools/payment-method-config-observation.php';
        $original = file_get_contents($tool);
        self::assertIsString($original);
        $calls = 0;
        $now = 0.0;
        try {
            $clock = static function () use (&$now): float {
                return $now;
            };
            $client = PaymentMethodConfigObservationTest::client(static function () use (&$calls): ResponseInterface {
                ++$calls;
                return PaymentMethodConfigObservationTest::login();
            });
            $ledgers = static function (
                string $context,
                string $directory
            ) use (
                $f,
                $stage,
                $change,
                $tool,
                &$now
            ): RunLedger {
                return RunLedger::create($context, $directory, synchronize: static function ($stream) use (
                    $f,
                    $stage,
                    $change,
                    $tool,
                    &$now,
                ): bool {
                    if (!is_resource($stream)) {
                        return false;
                    }
                    foreach (glob($f['state'] . '/sdk-validation-*/ledger.json.writing') ?: [] as $path) {
                        $raw = file_get_contents($path);
                        if (!is_string($raw)) {
                            continue;
                        }
                        $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                        if (
                            !is_array($state) || !is_array($state['observations'] ?? null)
                            || !isset($state['observations']['payment_config_login_' . $stage])
                        ) {
                            continue;
                        }
                        if ($change === 'source') {
                            file_put_contents($tool, "\n// synthetic source change\n", FILE_APPEND);
                        } elseif ($change === 'deadline') {
                            $now = 120.0;
                        } else {
                            file_put_contents($f['credentials'], $change === 'credential' ? "\n# synthetic change\n"
                            : "\nAIRWALLEX_ACCOUNT_ID=SYNTHETIC_PRIVATE_FOREIGN\n", FILE_APPEND);
                        }
                    }
                    return fsync($stream);
                });
            };
            $result = PaymentMethodConfigCommand::run(
                PaymentMethodConfigObservationTest::options($f),
                $client,
                $ledgers,
                $clock
            );
            self::assertSame('fail', $result['result']);
            self::assertSame(0, $calls);
            self::assertTrue($result['transport_poisoned']);
            self::assertSame(1, $result['possible_invocations']);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
        } finally {
            file_put_contents($tool, $original);
            PaymentMethodConfigObservationTest::remove($f['base']);
        }
    }

    public function testSnapshotDurabilityFailureCannotBeReportedAsObservationPass(): void
    {
        $f = PaymentMethodConfigObservationTest::fixture();
        $calls = 0;
        try {
            $client = PaymentMethodConfigObservationTest::client(static function (
                RequestInterface $r
            ) use (&$calls): ResponseInterface {
                ++$calls; return $r->getMethod() === 'POST' ? PaymentMethodConfigObservationTest::login()
                    : PaymentMethodConfigObservationTest::response('{"items":[],"has_more":false}');
            });
            $ledgers = static fn (string $context, string $directory): RunLedger => RunLedger::create(
                $context,
                $directory,
                synchronize: static function ($stream) use ($f): bool {
                    if (!is_resource($stream)) {
                        return false;
                    }
                    foreach (glob($f['state'] . '/sdk-validation-*/ledger.json.writing') ?: [] as $path) {
                        $raw = file_get_contents($path);
                        if (is_string($raw) && str_contains($raw, 'payment_method_config_snapshot')) {
                            return false;
                        }
                    }
                    return fsync($stream);
                }
            );
            $result = PaymentMethodConfigCommand::run(
                PaymentMethodConfigObservationTest::options($f),
                $client,
                $ledgers
            );
            self::assertSame('fail', $result['result']);
            self::assertFalse($result['private_snapshot_retained']);
            self::assertTrue($result['transport_poisoned']);
            self::assertSame(2, $calls);
            self::assertSame('private_snapshot', $result['phase']);
        } finally {
            PaymentMethodConfigObservationTest::remove($f['base']);
        }
    }
    /** Cleanup also covers an OS result that failed tuple validation. */
    private static function closePipes(mixed $pipes): void
    {
        if (is_array($pipes)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }
}
