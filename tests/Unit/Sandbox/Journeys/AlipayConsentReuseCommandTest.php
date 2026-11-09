<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReuseCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class AlipayConsentReuseCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/consent-command-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $resolved = realpath($directory);
        self::assertIsString($resolved);
        $this->directory = $resolved;
        file_put_contents($resolved . '/credentials', "AIRWALLEX_CLIENT_ID=PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=PRIVATE_KEY\nAIRWALLEX_ACCOUNT_ID=PRIVATE_ACCOUNT\n");
        chmod($resolved . '/credentials', 0600);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->directory,
            \FilesystemIterator::SKIP_DOTS
        ), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'state-dir' => $this->directory, 'verify-and-charge-one-alipay-hk-consent' => true];
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidOptions(): iterable
    {
        yield 'production' => ['environment', 'production'];
        yield 'valued flag' => ['verify-and-charge-one-alipay-hk-consent', 'true'];
        yield 'false flag' => ['verify-and-charge-one-alipay-hk-consent', false];
        yield 'no flag' => ['verify-and-charge-one-alipay-hk-consent', null];
        yield 'context' => ['context', 'organization'];
        yield 'URL' => ['url', 'https://PRIVATE'];
        yield 'ledger' => ['ledger', '/PRIVATE'];
        yield 'ID' => ['intent-id', 'PRIVATE'];
        yield 'amount' => ['amount', '100'];
        yield 'OS' => ['os', 'ios'];
        yield 'canonical' => ['target-mode', 'canonical'];
        yield 'root' => ['sdk-root', '/PRIVATE'];
        yield 'timeout' => ['timeout', 999];
    }

    #[DataProvider('invalidOptions')]
    public function testClosedFixedProfileRejectsBeforeCredentialReadAndAnyHttp(string $key, mixed $value): void
    {
        $provider = new PaymentsProvider();
        $options = $this->options();
        $options['credentials-file'] = '/nonexistent-PRIVATE';
        $options[$key] = $value;
        $result = AlipayConsentReuseCommand::run($options, $provider);
        self::assertSame('fail', $result['result']);
        self::assertSame('configuration', $result['stage']);
        self::assertSame([], $provider->requests);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testAccountIsRequiredAndNoDefaultOrOrganizationAuthenticationFallbackExists(): void
    {
        file_put_contents($this->directory . '/credentials', "AIRWALLEX_CLIENT_ID=PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=PRIVATE_KEY\n");
        $provider = new PaymentsProvider();
        self::assertSame('fail', AlipayConsentReuseCommand::run($this->options(), $provider)['result']);
        self::assertSame([], $provider->requests);
    }

    /** @return iterable<string,array{string}> */
    public static function durabilityStages(): iterable
    {
        foreach (
            ['preflight', 'customer_create_wire_prepared', 'customer_create_possible_send',
            'customer_create_wire_result', 'resources'] as $stage
        ) {
            yield $stage => [$stage];
        }
    }

    #[DataProvider('durabilityStages')]
    public function testDurabilityFailureStopsBeforeSendOrBeforeDependentDelivery(string $stage): void
    {
        $provider = new PaymentsProvider();
        $provider->response = AlipayConsentReuseTest::normal(...);
        $result = AlipayConsentReuseCommand::run(
            $this->options(),
            $provider,
            function (string $context, string $directory) use ($stage): RunLedger {
                return RunLedger::create($context, $directory, synchronize: static function ($stream) use (
                    $directory,
                    $stage,
                ): bool {
                    foreach (glob($directory . '/sdk-validation-*/*.writing') ?: [] as $path) {
                        $raw = file_get_contents($path);
                        if (!is_string($raw)) {
                            return false;
                        }
                        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                        if (!is_array($data)) {
                            return false;
                        }
                        $actions = $data['actions'] ?? null;
                        $observations = $data['observations'] ?? null;
                        if (!is_array($actions) || !is_array($observations)) {
                            return false;
                        }
                        if (
                            $stage === 'preflight' && isset($actions['consent_reuse_preflight'])
                            || $stage === 'resources' && ($data['resources'] ?? []) !== []
                            || isset($observations[$stage])
                        ) {
                            return false;
                        }
                    }
                    return fsync($stream);
                });
            },
            static function (int $seconds): void {
            },
            attempts: 1
        );
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        $writes = array_values(array_filter($provider->requests, static fn (RequestInterface $request): bool =>
            $request->getMethod() === 'POST' && !str_contains($request->getUri()->getPath(), '/authentication/')));
        self::assertCount(in_array($stage, ['customer_create_wire_result', 'resources'], true) ? 1 : 0, $writes);
        foreach ($provider->requests as $request) {
            self::assertStringNotContainsString('/payment_consents/', $request->getUri()->getPath());
        }
    }

    public function testExactAccountLoginAndPreauthIntentPrecedeEveryProviderOperation(): void
    {
        $provider = new PaymentsProvider();
        $provider->response = AlipayConsentReuseTest::normal(...);
        $provider->intercept = function (RequestInterface $request): ?ResponseInterface {
            $paths = glob($this->directory . '/sdk-validation-*/ledger.json') ?: [];
            self::assertCount(1, $paths);
            $raw = file_get_contents($paths[0]);
            self::assertIsString($raw);
            $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($state);
            self::assertIsArray($state['actions']);
            self::assertArrayHasKey('consent_reuse_preflight', $state['actions']);
            if ($request->getUri()->getPath() === '/api/v1/authentication/login') {
                self::assertSame('PRIVATE_ACCOUNT', $request->getHeaderLine('x-login-as'));
            } else {
                self::assertFalse($request->hasHeader('x-login-as'));
            }
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
            return null;
        };
        $result = AlipayConsentReuseCommand::run(
            $this->options(),
            $provider,
            sleep: static function (int $seconds): void {
            },
            attempts: 1
        );
        self::assertSame('pass', $result['result']);
        self::assertCount(2, array_filter($provider->requests, static fn (RequestInterface $request): bool =>
            str_ends_with($request->getUri()->getPath(), '/authentication/login')));
    }

    public function testUnexpectedLoginSuccessShapeAndStatusNeverReachesResourceCreate(): void
    {
        $provider = new PaymentsProvider();
        $provider->intercept = static fn (): ResponseInterface => new Response(
            200,
            [],
            '{"token":"PRIVATE","expires_at":"2100-01-01T00:00:00Z"}'
        );
        $result = AlipayConsentReuseCommand::run($this->options(), $provider);
        self::assertSame('fail', $result['result']);
        self::assertCount(1, $provider->requests);
    }

    public function testFixedEntrySourceChangeBlocksDeliveryAndEveryDependentWrite(): void
    {
        $tool = dirname(__DIR__, 4) . '/tools/alipay-hk-consent-reuse.php';
        $original = file_get_contents($tool);
        self::assertIsString($original);
        $provider = new PaymentsProvider();
        $provider->intercept = static function () use ($tool, $original): ?ResponseInterface {
            file_put_contents($tool, $original . "\n// synthetic source-change fixture\n");
            return null;
        };
        try {
            $result = AlipayConsentReuseCommand::run($this->options(), $provider);
        } finally {
            file_put_contents($tool, $original);
        }
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(1, $provider->requests);
        self::assertSame('/api/v1/authentication/login', $provider->requests[0]->getUri()->getPath());
    }

    public function testAnotherAccountJournalCannotBeUsedBeforeAuthentication(): void
    {
        $provider = new PaymentsProvider();
        $result = AlipayConsentReuseCommand::run(
            $this->options(),
            $provider,
            static fn (string $context, string $directory): RunLedger =>
                RunLedger::create(str_repeat('b', 64), $directory)
        );
        self::assertSame('fail', $result['result']);
        self::assertSame([], $provider->requests);
    }

    /** @return iterable<string,array{bool,int}> */
    public static function unsafePermissions(): iterable
    {
        yield 'open credential file' => [false, 0644];
        yield 'open state base' => [true, 0755];
    }

    #[DataProvider('unsafePermissions')]
    public function testPrivatePermissionsAreRequiredBeforeAuthentication(bool $state, int $mode): void
    {
        chmod($state ? $this->directory : $this->directory . '/credentials', $mode);
        $provider = new PaymentsProvider();
        $result = AlipayConsentReuseCommand::run($this->options(), $provider);
        self::assertSame('fail', $result['result']);
        self::assertSame([], $provider->requests);
    }

    /** @return iterable<string,array{string,string}> */
    public static function finalProofFailures(): iterable
    {
        foreach (['intent_confirm_wire_prepared', 'intent_confirm_possible_send'] as $phase) {
            foreach (['source', 'clock'] as $change) {
                yield $phase . '-' . $change => [$phase, $change];
            }
        }
    }

    #[DataProvider('finalProofFailures')]
    public function testFinalProofAfterFsyncStopsConfirmAndAllDependentCleanup(string $phase, string $change): void
    {
        $provider = new PaymentsProvider();
        $provider->response = AlipayConsentReuseTest::normal(...);
        $now = 0.0;
        $fired = false;
        $tool = dirname(__DIR__, 4) . '/tools/alipay-hk-consent-reuse.php';
        $original = file_get_contents($tool);
        self::assertIsString($original);
        try {
            $result = AlipayConsentReuseCommand::run(
                $this->options(),
                $provider,
                static function (
                    string $context,
                    string $directory
                ) use (
                    $phase,
                    $change,
                    &$now,
                    &$fired,
                    $tool,
                    $original,
                ): RunLedger {
                    return RunLedger::create($context, $directory, synchronize: static function ($stream) use (
                        $directory,
                        $phase,
                        $change,
                        &$now,
                        &$fired,
                        $tool,
                        $original,
                    ): bool {
                        if (!$fired) {
                            foreach (glob($directory . '/sdk-validation-*/*.writing') ?: [] as $path) {
                                $raw = file_get_contents($path);
                                self::assertIsString($raw);
                                $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                                self::assertIsArray($state);
                                $observations = $state['observations'] ?? null;
                                self::assertIsArray($observations);
                                if (isset($observations[$phase])) {
                                    $fired = true;
                                    if ($change === 'source') {
                                        file_put_contents($tool, $original . "\n// synthetic fsync proof change\n");
                                    } else {
                                        $now = 241.0;
                                    }
                                }
                            }
                        }
                        return fsync($stream);
                    });
                },
                static function (int $seconds): void {
                },
                static function () use (&$now): float {
                    return $now;
                },
                attempts: 1
            );
        } finally {
            file_put_contents($tool, $original);
        }
        self::assertTrue($fired);
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
        foreach (['/confirm', '/refunds/create', '/cancel', '/disable'] as $suffix) {
            self::assertCount(0, array_filter($provider->requests, static fn (RequestInterface $request): bool =>
                str_ends_with($request->getUri()->getPath(), $suffix)));
        }
        $paths = glob($this->directory . '/sdk-validation-*/ledger.json') ?: [];
        self::assertCount(1, $paths);
        $raw = file_get_contents($paths[0]);
        self::assertIsString($raw);
        $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsArray($state['observations']);
        $possible = $state['observations']['intent_confirm_possible_send'] ?? null;
        self::assertIsArray($possible);
        self::assertSame('possible', $possible['invocation']);
        self::assertIsArray($state['resources']);
        self::assertNotEmpty($state['resources']['payment_intents'] ?? null);
    }

    /** @return iterable<string,array{list<string>,bool}> */
    public static function cliNegatives(): iterable
    {
        yield 'missing' => [[], false];
        yield 'production' => [['--environment=production'], false];
        yield 'valued flag' => [['--verify-and-charge-one-alipay-hk-consent=true'], false];
        yield 'duplicate' => [['--environment=sandbox', '--environment=sandbox'], false];
        yield 'root' => [['--sdk-root=/PRIVATE'], false];
        yield 'URL' => [['--url=https://PRIVATE'], false];
        yield 'argv disabled' => [['--environment=sandbox', '--credentials-file=/PRIVATE',
            '--state-dir=/PRIVATE', '--verify-and-charge-one-alipay-hk-consent'], true];
    }

    /** @param list<string> $arguments */
    #[DataProvider('cliNegatives')]
    public function testRealCliFailsWithoutReadingAnythingOrWritingRawStderr(array $arguments, bool $disabled): void
    {
        $tool = dirname(__DIR__, 4) . '/tools/alipay-hk-consent-reuse.php';
        $process = proc_open(
            [PHP_BINARY, ...($disabled ? ['-d', 'register_argc_argv=0'] : []), $tool, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $pipes = self::processPipes($pipes, $process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $stderr);
        self::assertIsString($output);
        self::assertStringNotContainsString('PRIVATE', $output);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame('configuration', $result['stage']);
    }

    public function testInvalidPipeShapeClosesAndReapsOwnedFixtureChild(): void
    {
        $process = proc_open(
            [PHP_BINARY, '-r', 'stream_get_contents(STDIN);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $pipes = self::processPipes($pipes, $process);
        $invalid = $pipes;
        $invalid[3] = $invalid[2];
        unset($invalid[2]);
        $rejected = false;
        try {
            try {
                self::processPipes($invalid, $process);
            } catch (AssertionFailedError) {
                $rejected = true;
            }
            self::assertTrue($rejected);
            self::assertFalse(is_resource($process));
            foreach ($pipes as $pipe) {
                self::assertFalse(is_resource($pipe));
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    /**
     * @param resource $process
     * @return array{resource,resource,resource}
     */
    private static function processPipes(mixed $pipes, $process): array
    {
        try {
            self::assertIsArray($pipes);
            $input = $pipes[0] ?? null;
            $output = $pipes[1] ?? null;
            $errors = $pipes[2] ?? null;
            self::assertIsResource($input);
            self::assertIsResource($output);
            self::assertIsResource($errors);
            return [$input, $output, $errors];
        } catch (\Throwable $failure) {
            try {
                if (is_array($pipes)) {
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe) && get_resource_type($pipe) === 'stream') {
                            fclose($pipe);
                        }
                    }
                }
            } finally {
                proc_terminate($process);
                proc_close($process);
            }
            throw $failure;
        }
    }
}
