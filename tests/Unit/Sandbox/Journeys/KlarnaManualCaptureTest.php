<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\KlarnaManualCaptureCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class KlarnaManualCaptureTest extends TestCase
{
    private string $directory;
    private KlarnaProvider $provider;
    /** @var list<RequestInterface> */
    private array $redirects = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/klarna-fixture-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/credentials', "AIRWALLEX_CLIENT_ID=PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=PRIVATE_KEY\nAIRWALLEX_ACCOUNT_ID=PRIVATE_ACCOUNT\n");
        $this->provider = new KlarnaProvider();
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->directory,
            \FilesystemIterator::SKIP_DOTS
        ), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    /** @return array<string,mixed> */
    private function options(string $case = 'capture-refund'): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'state-dir' => $this->directory, 'case' => $case, 'create-one-klarna' => true];
    }

    /** @param (Closure(string,string):RunLedger)|null $ledgers
     * @return array<string,mixed>
     */
    private function execute(
        string $case = 'capture-refund',
        ?ResponseInterface $redirect = null,
        ?Closure $ledgers = null
    ): array {
        $http = new Client(['handler' => new MockHandler([function (RequestInterface $request) use (
            $redirect,
        ): ResponseInterface {
                $this->redirects[] = $request;
                return $redirect ?? new Response(302, ['Location' => KlarnaProvider::MOCK]);
        }])]);
        return KlarnaManualCaptureCommand::run(
            $this->options($case),
            $this->provider,
            $http,
            $ledgers,
            static function (int $seconds): void {
                self::assertContains($seconds, [1, 2, 4, 7, 8]);
            },
            3
        );
    }

    /** @return array<array-key,mixed> */
    private function journal(): array
    {
        $paths = glob($this->directory . '/sdk-validation-*/ledger.json') ?: [];
        self::assertCount(1, $paths);
        $raw = file_get_contents($paths[0]);
        self::assertIsString($raw);
        $value = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        self::assertSame(0600, fileperms($paths[0]) & 0777);
        self::assertSame(0700, fileperms(dirname($paths[0])) & 0777);
        return $value;
    }

    /** @return list<RequestInterface> */
    private function mutations(string $suffix = ''): array
    {
        return array_values(array_filter($this->provider->requests, static fn (RequestInterface $request): bool =>
            $request->getMethod() === 'POST' && $request->getUri()->getPath() !== '/api/v1/authentication/login'
            && ($suffix === '' || str_ends_with($request->getUri()->getPath(), $suffix))));
    }

    /** @param array<string,mixed> $result */
    private static function privateOutput(array $result): void
    {
        $raw = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE', 'https:', 'klarna-fixture-', 'client_secret', 'checksum'] as $needle) {
            self::assertStringNotContainsString($needle, $raw);
        }
        self::assertFalse($result['financial_settlement_verified'] ?? false);
    }

    /** @return iterable<string,array{string}> */
    public static function cases(): iterable
    {
        yield 'capture then refund' => ['capture-refund'];
        yield 'authorization cancel' => ['authorized-cancel'];
    }

    #[DataProvider('cases')]
    public function testFreshExactWirePrivateDurabilityAndSeparateManualDispositions(string $case): void
    {
        $this->provider->before = function (RequestInterface $request): void {
            $state = $this->journal();
            self::assertIsArray($state['actions']);
            self::assertArrayHasKey('klarna_preflight', $state['actions']);
            if (str_ends_with($request->getUri()->getPath(), '/confirm')) {
                self::assertArrayHasKey('klarna_confirm', $state['actions']);
                self::assertIsArray($state['observations']);
                self::assertSame(['intent_id' => 'PRIVATE_INTENT'], $state['observations']['klarna_confirm_target']);
            }
        };
        $result = $this->execute($case);
        self::assertSame('pass', $result['result']);
        self::assertTrue($result['authorization_verified']);
        self::assertTrue($result['simulation_acknowledged']);
        self::assertFalse($result['reconciliation_required']);
        self::assertCount(1, $this->redirects);
        self::assertSame(KlarnaProvider::REDIRECT, (string) $this->redirects[0]->getUri());
        foreach (['Authorization', 'Cookie', 'Referer', 'x-api-version', 'x-client-id', 'x-login-as'] as $header) {
            self::assertFalse($this->redirects[0]->hasHeader($header));
        }
        $created = (string) $this->mutations('/create')[0]->getBody();
        self::assertStringContainsString('"amount":1.23', $created);
        self::assertStringContainsString('"unit_price":1.23', $created);
        self::assertStringNotContainsString('customer_id', $created);
        $confirm = json_decode((string) $this->mutations('/confirm')[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($confirm);
        self::assertSame(['klarna' => ['auto_capture' => false]], $confirm['payment_method_options']);
        self::assertSame(
            ['type' => 'klarna', 'klarna' => ['country_code' => 'DE', 'language' => 'en']],
            $confirm['payment_method']
        );
        self::assertSame($case === 'capture-refund', $result['refund_accepted']);
        self::assertSame($case === 'authorized-cancel', $result['authorized_cancel_verified']);
        self::assertCount($case === 'capture-refund' ? 1 : 0, $this->mutations('/capture'));
        self::assertCount($case === 'authorized-cancel' ? 1 : 0, $this->mutations('/cancel'));
        $ids = [];
        foreach ($this->mutations() as $request) {
            if (str_contains($request->getUri()->getPath(), '/simulation/')) {
                continue;
            }
            $data = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertIsString($data['request_id']);
            self::assertNotContains($data['request_id'], $ids);
            $ids[] = $data['request_id'];
        }
        self::privateOutput($result);
    }

    public function testOwnSgRedirectUsesSameCanonicalCallbackWithoutRegionalRewrite(): void
    {
        $url = str_replace('/redirect/hk/', '/redirect/sg/', KlarnaProvider::REDIRECT);
        $this->provider->alter = function (
            RequestInterface $request,
            ResponseInterface $response
        ) use (
            $url,
        ): ResponseInterface {
            return str_ends_with($request->getUri()->getPath(), '/confirm')
                ? KlarnaProvider::response($this->provider->intent + ['next_action' => [
                    'type' => 'redirect', 'method' => 'GET', 'url' => $url]]) : $response;
        };
        $result = $this->execute();
        self::assertSame('pass', $result['result']);
        self::assertTrue($result['simulation_acknowledged']);
        self::assertTrue($result['capture_verified']);
        self::assertTrue($result['refund_accepted']);
        self::assertCount(1, $this->redirects);
        self::assertSame($url, (string) $this->redirects[0]->getUri());
        self::assertFalse($this->redirects[0]->hasHeader('Cookie'));
        self::assertFalse($this->redirects[0]->hasHeader('Authorization'));
        self::assertCount(1, $this->mutations('/pay'));
        self::assertSame([], $this->mutations('/cancel'));
        self::privateOutput($result);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function wrongIntents(): iterable
    {
        foreach (['id', 'currency', 'merchant_order_id', 'metadata', 'order', 'return_url'] as $field) {
            yield $field => [$field, 'PRIVATE_FOREIGN'];
        }
        foreach ([null, '0', '1.23001', 'NaN'] as $value) {
            yield 'amount ' . (string) $value => ['amount', $value];
        }
        foreach ([null, '0.01', '1.23'] as $value) {
            yield 'captured ' . (string) $value => ['captured_amount', $value];
        }
        yield 'unknown state' => ['status', 'UNRECOGNIZED'];
    }

    #[DataProvider('wrongIntents')]
    public function testIncorrectOwnCreateReadCannotConfirmOrDispose(string $field, mixed $value): void
    {
        $this->provider->alter = static function (
            RequestInterface $request,
            ResponseInterface $response
        ) use (
            $field,
            $value,
        ): ResponseInterface {
            if ($request->getMethod() === 'GET') {
                $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    throw new RuntimeException('Synthetic response is unavailable.');
                }
                $data[$field] = $value;
                return new Response(200, [], json_encode($data, JSON_THROW_ON_ERROR));
            }
            return $response;
        };
        $result = $this->execute();
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(1, $this->mutations());
        self::assertSame([], $this->redirects);
        self::privateOutput($result);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function wrongRefunds(): iterable
    {
        foreach (['id', 'payment_intent_id', 'currency', 'amount', 'status'] as $field) {
            yield 'missing ' . $field => [$field, null];
            yield 'wrong ' . $field => [$field, 'PRIVATE_WRONG'];
        }
    }

    #[DataProvider('wrongRefunds')]
    public function testRefundMismatchRetainsParentWithOneRefundAndNoSubstituteCancellation(
        string $field,
        mixed $value
    ): void {
        $this->provider->alter = static function (
            RequestInterface $request,
            ResponseInterface $response
        ) use (
            $field,
            $value,
        ): ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/refunds/PRIVATE_REFUND')) {
                $data = ['id' => 'PRIVATE_REFUND', 'payment_intent_id' => 'PRIVATE_INTENT', 'currency' => 'EUR',
                    'amount' => '1.23', 'status' => 'ACCEPTED'];
                $data[$field] = $value;
                return new Response(200, [], json_encode($data, JSON_THROW_ON_ERROR));
            }
            return $response;
        };
        $result = $this->execute();
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(1, $this->mutations('/capture'));
        self::assertCount(1, array_filter($this->mutations('/create'), static fn (RequestInterface $r): bool =>
            str_contains($r->getUri()->getPath(), '/refunds/')));
        self::assertSame([], $this->mutations('/cancel'));
        self::privateOutput($result);
    }

    /** @return iterable<string,array{string}> */
    public static function pendingActions(): iterable
    {
        foreach (['authorize', 'capture', 'refund', 'cancel'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('pendingActions')]
    public function testUnfinishedActionsDoNotReplayOrChooseAlternativeDisposition(string $action): void
    {
        $this->provider->authorize = $action !== 'authorize';
        $this->provider->capture = $action !== 'capture';
        $this->provider->cancel = $action !== 'cancel';
        $this->provider->refundState = $action === 'refund' ? 'RECEIVED' : 'ACCEPTED';
        $result = $this->execute($action === 'cancel' ? 'authorized-cancel' : 'capture-refund');
        self::assertNotSame('pass', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        foreach (['/capture', '/cancel', '/pay'] as $suffix) {
            self::assertLessThanOrEqual(1, count($this->mutations($suffix)));
        }
        self::assertCount($action === 'cancel' ? 1 : 0, $this->mutations('/cancel'));
        self::privateOutput($result);
    }

    /** @return iterable<string,array{string,int}> */
    public static function rejectedSends(): iterable
    {
        foreach (['confirm', 'capture', 'cancel', 'create'] as $action) {
            foreach ([400, 500] as $status) {
                yield $action . $status => [$action, $status];
            }
        }
    }

    #[DataProvider('rejectedSends')]
    public function testSentFailuresNeverReplayOrIntroduceCleanup(string $action, int $status): void
    {
        $this->provider->alter = static function (
            RequestInterface $request,
            ResponseInterface $response
        ) use (
            $action,
            $status,
        ): ResponseInterface {
            return str_ends_with($request->getUri()->getPath(), '/' . $action)
                ? new Response($status, [], '{"code":"PRIVATE_OPAQUE","source":"PRIVATE_FIELD"}') : $response;
        };
        $result = $this->execute($action === 'cancel' ? 'authorized-cancel' : 'capture-refund');
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(1, $this->mutations('/' . $action));
        if ($action !== 'cancel') {
            self::assertSame([], $this->mutations('/cancel'));
        }
        self::privateOutput($result);
    }

    public function testUnknownRedirectTargetsCannotSendOrSimulate(): void
    {
        $result = $this->execute(redirect: new Response(302, ['Location' => 'https://PRIVATE_THIRD_PARTY.test']));
        self::assertSame('blocked', $result['result']);
        self::assertFalse($result['simulation_attempted']);
        self::assertSame([], $this->mutations('/pay'));
        self::assertCount(1, $this->mutations('/cancel'));
        self::assertSame('pass', $result['cleanup']);
        self::assertFalse($result['reconciliation_required']);
        self::assertCount(1, $this->redirects);
        self::privateOutput($result);
    }

    /** @return iterable<string,array{string,string,mixed}> */
    public static function phaseMismatches(): iterable
    {
        foreach (['REQUIRES_CAPTURE', 'SUCCEEDED'] as $state) {
            foreach (['id', 'currency', 'amount', 'merchant_order_id', 'metadata', 'captured_amount'] as $field) {
                yield $state . $field => [$state, $field, $field === 'captured_amount' ? '0.99' : 'PRIVATE_WRONG'];
            }
        }
    }

    #[DataProvider('phaseMismatches')]
    public function testFreshAuthorizationAndCaptureProofMismatchesPreventDependentActions(
        string $state,
        string $field,
        mixed $value,
    ): void {
        $this->provider->alter = static function (
            RequestInterface $request,
            ResponseInterface $response
        ) use (
            $state,
            $field,
            $value,
        ): ResponseInterface {
            if ($request->getMethod() === 'GET') {
                $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $response->getBody()->rewind();
                if (is_array($data) && ($data['status'] ?? null) === $state) {
                    $data[$field] = $value;
                    return new Response(200, [], json_encode($data, JSON_THROW_ON_ERROR));
                }
            }
            return $response;
        };
        $result = $this->execute();
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount($state === 'SUCCEEDED' ? 1 : 0, $this->mutations('/capture'));
        self::assertCount(1, $this->mutations('/create'));
        self::assertCount(1, $this->mutations('/confirm'));
        self::assertCount(1, $this->mutations('/pay'));
        self::assertSame([], $this->mutations('/cancel'));
        self::privateOutput($result);
    }

    /** @return iterable<string,array{int,string}> */
    public static function simulationFailures(): iterable
    {
        yield 'nonempty success' => [200, 'PRIVATE_NONEMPTY'];
        yield 'bad request' => [400, '{"code":"validation_error","message":"PRIVATE"}'];
        yield 'server failure' => [500, '{"code":"internal_error","message":"PRIVATE"}'];
    }

    #[DataProvider('simulationFailures')]
    public function testSentSimulationFailureNeverCapturesCancelsOrRetries(int $status, string $body): void
    {
        $this->provider->alter = static function (
            RequestInterface $request,
            ResponseInterface $response
        ) use (
            $status,
            $body,
        ): ResponseInterface {
            return str_ends_with($request->getUri()->getPath(), '/pay')
                ? new Response($status, [], $body) : $response;
        };
        $result = $this->execute();
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(1, $this->mutations('/pay'));
        self::assertSame([], $this->mutations('/capture'));
        self::assertSame([], $this->mutations('/cancel'));
        self::privateOutput($result);
    }

    public function testMissingCreateIdentityCannotTriggerAnyFollowup(): void
    {
        $this->provider->alter = static function (
            RequestInterface $request,
            ResponseInterface $response,
        ): ResponseInterface {
            return str_ends_with($request->getUri()->getPath(), '/create')
                ? new Response(200, [], '{}') : $response;
        };
        $result = $this->execute();
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(1, $this->mutations());
        self::assertSame([], $this->redirects);
    }

    public function testInventoryPersistenceFailureStopsBeforeConfirmation(): void
    {
        $result = $this->execute(ledgers: static fn (string $fp, string $directory): RunLedger =>
            RunLedger::create($fp, $directory, synchronize: static function ($stream) use ($directory): bool {
                foreach (glob($directory . '/sdk-validation-*/*.writing') ?: [] as $path) {
                    $raw = file_get_contents($path);
                    if (is_string($raw) && str_contains($raw, '"payment_intents"')) {
                        return false;
                    }
                }
                return fsync($stream);
            }));
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(1, $this->mutations());
        self::assertSame([], $this->redirects);
    }

    public function testDefaultBoundedPendingObservationWaitsThirtySecondsUsingOnlyFakeSleeps(): void
    {
        $this->provider->authorize = false;
        $waits = [];
        $http = new Client(['handler' => new MockHandler([new Response(302, ['Location' => KlarnaProvider::MOCK])])]);
        $result = KlarnaManualCaptureCommand::run(
            $this->options(),
            $this->provider,
            $http,
            sleep: static function (int $seconds) use (&$waits): void {
                $waits[] = $seconds;
            }
        );
        self::assertSame([1, 2, 4, 8, 8, 7], $waits);
        self::assertTrue($result['reconciliation_required']);
        self::assertSame([], $this->mutations('/capture'));
    }

    /** @return iterable<string,array{string}> */
    public static function unsafeReads(): iterable
    {
        yield 'server error' => ['server'];
        yield 'unreadable response' => ['stream'];
        yield 'cleanup owner changed' => ['owner'];
        yield 'cleanup captured changed' => ['captured'];
    }

    #[DataProvider('unsafeReads')]
    public function testUncertainOrMismatchedUnusedIntentCannotEnterCleanCancellation(string $mode): void
    {
        if ($mode === 'owner' || $mode === 'captured') {
            $reads = 0;
            $this->provider->alter = static function (
                RequestInterface $request,
                ResponseInterface $response
            ) use (
                &$reads,
                $mode,
            ): ResponseInterface {
                if ($request->getMethod() === 'GET' && ++$reads === 3) {
                    $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    if (!is_array($data)) {
                        throw new RuntimeException('Synthetic owner is unavailable.');
                    }
                    $data[$mode === 'owner' ? 'merchant_order_id' : 'captured_amount'] =
                        $mode === 'owner' ? 'PRIVATE_FOREIGN' : '0.01';
                    return new Response(200, [], json_encode($data, JSON_THROW_ON_ERROR));
                }
                return $response;
            };
        }
        $redirect = $mode === 'server' ? new Response(500) : ($mode === 'stream'
            ? new Response(302, [], new SimulationProbeStream('PRIVATE', 'broken'))
            : new Response(302, ['Location' => 'https://PRIVATE_UNKNOWN.test']));
        $result = $this->execute(redirect: $redirect);
        self::assertNotSame('pass', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertSame([], $this->mutations('/pay'));
        self::assertSame([], $this->mutations('/cancel'));
        self::privateOutput($result);
    }

    /** @return iterable<string,array{list<string>,list<string>}> */
    public static function cliNegatives(): iterable
    {
        yield 'missing' => [[], []];
        yield 'production' => [['--environment=production', '--create-one-klarna', '--case=capture-refund'], []];
        yield 'case missing' => [['--environment=sandbox', '--create-one-klarna'], []];
        yield 'double case' => [['--case=capture-refund', '--case=authorized-cancel'], []];
        yield 'root override' => [['--sdk-root=/PRIVATE'], []];
        yield 'URL override' => [['--url=https://PRIVATE.test'], []];
        yield 'disabled argv' => [['--environment=production'], ['-d', 'register_argc_argv=0']];
        yield 'stream unavailable' => [['--environment=sandbox', '--create-one-klarna', '--case=capture-refund',
            '--credentials-file=/NONEXISTENT_PRIVATE', '--state-dir=/NONEXISTENT_PRIVATE'],
            ['-d', 'allow_url_fopen=0']];
    }

    /** @param list<string> $arguments
     * @param list<string> $phpOptions
     */
    #[DataProvider('cliNegatives')]
    public function testRealCliRejectsConfigurationWithoutSecretsOrNetwork(array $arguments, array $phpOptions): void
    {
        $process = proc_open([PHP_BINARY, ...$phpOptions, dirname(__DIR__, 4) . '/tools/klarna-manual-capture.php',
            ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        [$output, $errors] = self::pipes($pipes);
        $stdout = stream_get_contents($output);
        $stderr = stream_get_contents($errors);
        fclose($output);
        fclose($errors);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $stderr);
        self::assertIsString($stdout);
        self::assertStringNotContainsString('PRIVATE', $stdout);
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame('fail', $result['result']);
    }

    /** @return array{resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (!is_array($pipes) || !is_resource($pipes[1] ?? null) || !is_resource($pipes[2] ?? null)) {
            throw new RuntimeException('Synthetic CLI pipes are unavailable.');
        }
        return [$pipes[1], $pipes[2]];
    }

    public function testDirectAuthorizationMoneyFailureCannotFallThroughToOtherwiseCleanableState(): void
    {
        $reads = 0;
        $this->provider->alter = function (
            RequestInterface $request,
            ResponseInterface $response
        ) use (
            &$reads,
        ): ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/confirm')) {
                $this->provider->intent['status'] = 'REQUIRES_CAPTURE';
                return KlarnaProvider::response($this->provider->intent);
            }
            if ($request->getMethod() === 'GET' && $this->provider->intent['status'] === 'REQUIRES_CAPTURE') {
                if (++$reads === 2) {
                    $bad = $this->provider->intent;
                    $bad['captured_amount'] = '0.99';
                    // A later otherwise-valid read must not erase the earlier money/protocol failure.
                    $this->provider->intent['status'] = 'REQUIRES_CUSTOMER_ACTION';
                    return KlarnaProvider::response($bad);
                }
            }
            return $response;
        };
        $result = $this->execute();
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertSame([], $this->mutations('/cancel'));
        self::assertSame([], $this->mutations('/capture'));
        self::assertSame([], $this->mutations('/pay'));
        self::assertSame([], $this->redirects);
        self::assertSame(2, $reads);
        self::privateOutput($result);
    }

    public function testUndurablePreauthIntentPreventsEveryRequest(): void
    {
        $syncs = 0;
        $result = $this->execute(ledgers: static fn (string $fp, string $directory): RunLedger =>
            RunLedger::create($fp, $directory, synchronize: static function () use (&$syncs): bool {
                return ++$syncs < 4;
            }));
        self::assertSame('fail', $result['result']);
        self::assertSame([], $this->provider->requests);
        self::assertSame([], $this->redirects);
    }

    public function testNoDefaultCaseProductionOrRootOverrideCanReadConfigurationOrSend(): void
    {
        foreach ([[], ['environment' => 'production'], ['sdk-root' => '/PRIVATE']] as $options) {
            $result = KlarnaManualCaptureCommand::run($options, $this->provider);
            self::assertSame('fail', $result['result']);
            self::assertSame([], $this->provider->requests);
        }
    }
}
