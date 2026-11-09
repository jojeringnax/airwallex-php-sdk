<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliationCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AlipayConsentReconciliationCommandTest extends TestCase
{
    /** @var list<string> */
    private array $bases = [];

    protected function tearDown(): void
    {
        foreach ($this->bases as $base) {
            AlipayConsentReconciliationTest::remove($base);
        }
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidOptions(): iterable
    {
        yield 'production' => ['environment', 'production'];
        yield 'missing read flag' => ['read-own-alipay-hk-consent', null];
        yield 'valued flag' => ['read-own-alipay-hk-consent', 'true'];
        yield 'false flag' => ['read-own-alipay-hk-consent', false];
        foreach (
            ['context', 'account', 'id', 'url', 'source', 'adopt', 'disable', 'timeout', 'retry',
            'sdk-root', 'simulation', 'payment-intent-id'] as $name
        ) {
            yield $name => [$name, 'PRIVATE'];
        }
    }

    #[DataProvider('invalidOptions')]
    public function testNoContextTargetActionOrRuntimeSelectorIsAcceptedBeforeConfigRead(
        string $key,
        mixed $value,
    ): void {
        $provider = AlipayConsentReconciliationTest::provider();
        $r = AlipayConsentReconciliationCommand::run(['environment' => 'sandbox',
            'credentials-file' => '/unread-PRIVATE', 'prior-ledger' => '/unread-PRIVATE',
            'state-dir' => '/unread-PRIVATE', 'read-own-alipay-hk-consent' => true, $key => $value], $provider);
        self::assertSame('configuration', $r['phase']);
        self::assertSame([], $provider->requests);
        self::assertTrue($r['historical_reconciliation_required']);
        self::assertStringNotContainsString('PRIVATE', json_encode($r, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,int}> */
    public static function durabilityStages(): iterable
    {
        yield 'journal_create' => ['journal_create', 0];
        yield 'binding' => ['consent_reconciliation_binding', 0];
        yield 'second local intent' => ['consent_reconciliation_consent_intent', 0];
        yield 'login possible' => ['consent_readonly_login_1_possible_invocation', 0];
        yield 'login response' => ['consent_readonly_login_1_result', 1];
        yield 'customer possible' => ['consent_readonly_customer_possible_invocation', 1];
        yield 'customer response' => ['consent_readonly_customer_result', 2];
        yield 'customer ownership' => ['consent_reconciliation_customer_owned', 2];
        yield 'consent possible' => ['consent_readonly_consent_possible_invocation', 2];
        yield 'consent response' => ['consent_readonly_consent_result', 3];
        yield 'final observation' => ['consent_reconciliation_owned', 3];
    }

    /** @return array<string,mixed>|null */
    private static function writing(string $directory): ?array
    {
        foreach (glob($directory . '/sdk-validation-*/*.writing') ?: [] as $path) {
            $raw = file_get_contents($path);
            if (!is_string($raw)) {
                continue;
            }
            $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($state)) {
                $checked = [];
                foreach ($state as $key => $value) {
                    if (!is_string($key)) {
                        return null;
                    }
                    $checked[$key] = $value;
                }
                return $checked;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $state */
    private static function phasePresent(array $state, string $name): bool
    {
        $observations = $state['observations'] ?? null;
        $actions = $state['actions'] ?? null;
        return $name === 'journal_create' || is_array($observations) && array_key_exists($name, $observations)
            || is_array($actions) && array_key_exists($name, $actions);
    }

    #[DataProvider('durabilityStages')]
    public function testEveryDurabilityFailureStopsBeforeDependentReadOrPublicObservation(
        string $phase,
        int $calls,
    ): void {
        $f = AlipayConsentReconciliationTest::fixture();
        $this->bases[] = $f['base'];
        $before = hash_file('sha256', $f['prior']);
        $hit = false;
        $r = AlipayConsentReconciliationCommand::run(
            AlipayConsentReconciliationTest::options($f),
            $f['provider'],
            static function (string $context, string $directory) use ($phase, &$hit): RunLedger {
                return RunLedger::create(
                    $context,
                    $directory,
                    synchronize: static function ($stream) use ($phase, $directory, &$hit): bool {
                        $state = self::writing($directory);
                        if ($state !== null && self::phasePresent($state, $phase)) {
                            $hit = true;
                            return false;
                        }
                        return fsync($stream);
                    }
                );
            }
        );
        self::assertTrue($hit);
        self::assertSame('fail', $r['result']);
        self::assertNotSame('pass', $r['observation_result']);
        self::assertFalse($r['owner_chain_matched']);
        self::assertFalse($r['current_terminal_consent_capability_observed']);
        self::assertCount($calls, $f['provider']->requests);
        self::assertSame($before, hash_file('sha256', $f['prior']));
        self::assertTrue($r['historical_verification_or_financial_disposition_unresolved']);
        self::assertStringNotContainsString('PRIVATE', json_encode($r, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,string,int}> */
    public static function fsyncRaces(): iterable
    {
        foreach (
            ['consent_readonly_login_1_possible_invocation' => 0,
            'consent_readonly_customer_possible_invocation' => 1,
            'consent_readonly_customer_result' => 2, 'consent_reconciliation_owned' => 3] as $phase => $calls
        ) {
            foreach (['prior', 'source', 'missing-source', 'support-source', 'credentials', 'deadline'] as $fault) {
                yield $phase . '/' . $fault => [$phase, $fault, $calls];
            }
        }
    }

    #[DataProvider('fsyncRaces')]
    public function testOriginalProofIsCheckedAfterFsyncAndBeforeNativeInvocationOrDelivery(
        string $phase,
        string $fault,
        int $calls,
    ): void {
        $f = AlipayConsentReconciliationTest::fixture();
        $this->bases[] = $f['base'];
        $tool = dirname(__DIR__, 4) . '/tools/alipay-consent-reconciliation.php';
        $support = dirname(__DIR__, 3) . '/Support/Sandbox/Journeys/AlipayConsentReconciliation.php';
        $supportBytes = file_get_contents($support);
        self::assertIsString($supportBytes);
        $toolBytes = file_get_contents($tool);
        self::assertIsString($toolBytes);
        $prior = file_get_contents($f['prior']);
        $credentials = file_get_contents($f['credentials']);
        self::assertIsString($prior);
        self::assertIsString($credentials);
        $now = 0.0;
        $hit = false;
        try {
            $r = AlipayConsentReconciliationCommand::run(
                AlipayConsentReconciliationTest::options($f),
                $f['provider'],
                static function (
                    string $context,
                    string $directory
                ) use (
                    $phase,
                    $fault,
                    $f,
                    $tool,
                    $support,
                    &$now,
                    &$hit
                ): RunLedger {
                    return RunLedger::create(
                        $context,
                        $directory,
                        synchronize: static function ($stream) use (
                            $phase,
                            $fault,
                            $directory,
                            $f,
                            $tool,
                            $support,
                            &$now,
                            &$hit
                        ): bool {
                            $state = self::writing($directory);
                            if (!$hit && $state !== null && self::phasePresent($state, $phase)) {
                                $hit = true;
                                if ($fault === 'prior') {
                                    file_put_contents($f['prior'], "\n", FILE_APPEND);
                                } elseif ($fault === 'source') {
                                    file_put_contents($tool, "\n", FILE_APPEND);
                                } elseif ($fault === 'missing-source') {
                                    unlink($tool);
                                } elseif ($fault === 'support-source') {
                                    file_put_contents($support, "\n", FILE_APPEND);
                                } elseif ($fault === 'credentials') {
                                    file_put_contents($f['credentials'], "\n", FILE_APPEND);
                                } else {
                                    $now = 121.0;
                                }
                            }
                            return fsync($stream);
                        }
                    );
                },
                static function () use (&$now): float {
                        return $now;
                }
            );
        } finally {
            file_put_contents($tool, $toolBytes);
            file_put_contents($support, $supportBytes);
            file_put_contents($f['prior'], $prior);
            file_put_contents($f['credentials'], $credentials);
        }
        self::assertTrue($hit);
        self::assertSame('fail', $r['result']);
        self::assertNotSame('pass', $r['observation_result']);
        self::assertFalse($r['owner_chain_matched']);
        self::assertFalse($r['current_terminal_consent_capability_observed']);
        self::assertCount($calls, $f['provider']->requests);
        self::assertTrue($r['historical_reconciliation_required']);
        self::assertSame(0, $r['cleanup_writes_sent']);
        self::assertStringNotContainsString('PRIVATE', json_encode($r, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{list<string>,bool}> */
    public static function cliNegatives(): iterable
    {
        yield 'missing' => [[], false];
        yield 'production' => [['--environment=production'], false];
        yield 'duplicate' => [['--environment=sandbox', '--environment=sandbox'], false];
        yield 'valued flag' => [['--read-own-alipay-hk-consent=true'], false];
        yield 'disable' => [['--disable'], false];
        yield 'ID' => [['--consent-id=PRIVATE'], false];
        yield 'URL' => [['--url=https://PRIVATE'], false];
        yield 'root' => [['--sdk-root=/PRIVATE'], false];
        yield 'argv disabled' => [['--environment=sandbox', '--credentials-file=/PRIVATE',
            '--prior-ledger=/PRIVATE', '--state-dir=/PRIVATE', '--read-own-alipay-hk-consent'], true];
    }

    /** @param list<string> $args */
    #[DataProvider('cliNegatives')]
    public function testRealCliAlwaysFailsClosedAndKeepsRawStderrEmpty(array $args, bool $disabled): void
    {
        $tool = dirname(__DIR__, 4) . '/tools/alipay-consent-reconciliation.php';
        $process = proc_open(
            [PHP_BINARY, ...($disabled ? ['-d', 'register_argc_argv=0'] : []), $tool, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        try {
            [$input, $output, $errors] = self::pipes($pipes);
            fclose($input);
            $raw = stream_get_contents($output);
            $stderr = stream_get_contents($errors);
            fclose($output);
            fclose($errors);
            self::assertSame(1, proc_close($process));
            self::assertSame('', $stderr);
            self::assertIsString($raw);
            self::assertStringNotContainsString('PRIVATE', $raw);
            $r = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($r);
            self::assertSame('configuration', $r['phase']);
            self::assertTrue($r['historical_reconciliation_required']);
        } finally {
            self::closePipes($pipes);
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    private static function closePipes(mixed $pipes): void
    {
        if (is_array($pipes)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe) && get_resource_type($pipe) === 'stream') {
                    fclose($pipe);
                }
            }
        }
    }

    /** @return array{resource,resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        self::assertIsArray($pipes);
        $input = $pipes[0] ?? null;
        $output = $pipes[1] ?? null;
        $errors = $pipes[2] ?? null;
        self::assertIsResource($input);
        self::assertIsResource($output);
        self::assertIsResource($errors);
        return [$input, $output, $errors];
    }
}
