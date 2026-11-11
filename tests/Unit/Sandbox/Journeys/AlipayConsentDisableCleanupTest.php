<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Jojeringnax\Airwallex\Tests\Support\HistoricalAlipayCleanupTestCase;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentDisableCleanupCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use RuntimeException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AlipayConsentDisableCleanupTest extends HistoricalAlipayCleanupTestCase
{
    /** @var list<string> */
    private array $bases = [];

    /**
     * @return array{base:string,state:string,credentials:string,prior:string,
     * provider:AlipayConsentReconciliationProvider,marker:string,customer:string,consent:string}
     */
    public static function fixture(bool $numeric = false): array
    {
        $f = AlipayConsentReconciliationTest::fixture($numeric);
        $provider = $f['provider'];
        $provider->intercept = static function (RequestInterface $request) use ($provider, $f): ResponseInterface {
            $path = $request->getUri()->getPath();
            if ($path === '/api/v1/authentication/login') {
                return new Response(
                    201,
                    ['Content-Type' => 'application/json'],
                    '{"token":"synthetic-cleanup-token","expires_at":"2100-01-01T00:00:00Z"}'
                );
            }
            if ($path === '/api/v1/pa/payment_consents/' . $f['consent'] . '/disable') {
                $provider->resources[$f['consent']]['status'] = 'DISABLED';
                return new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    (new JsonCodec())->encode($provider->resources[$f['consent']])
                );
            }
            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                (new JsonCodec())->encode($provider->resources[basename($path)])
            );
        };
        return $f;
    }

    public function testCurrentSdkAdmissionIsCleanupOnlyAndUnknownSdkStillStopsBeforeAuthentication(): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $configuration = \Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration::load('sandbox', $f['credentials']);
        $session = new \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession(
            $configuration,
            'account',
            $f['provider']
        );
        $rejected = false;
        try {
            \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliation::load(
                $f['prior'],
                $f['state'],
                $session
            );
        } catch (RuntimeException) {
            $rejected = true;
        }
        self::assertTrue($rejected);
        $prior = \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliation::loadForCleanup(
            $f['prior'],
            $f['state'],
            $session
        );
        self::assertInstanceOf(
            \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliation::class,
            $prior
        );
        self::assertSame([], $f['provider']->requests);
        $path = self::sdkRoot() . '/src/RetryPolicy.php';
        $original = file_get_contents($path);
        self::assertIsString($original);
        $priorHash = hash_file('sha256', $f['prior']);
        try {
            file_put_contents($path, "\n", FILE_APPEND);
            $r = AlipayConsentDisableCleanupCommand::run(self::options($f), $f['provider']);
            self::assertSame('prior_validation', $r['phase']);
            self::assertSame('unavailable', $r['cleanup_observation_result']);
            self::assertSame([], $f['provider']->requests);
            self::assertSame($priorHash, hash_file('sha256', $f['prior']));
        } finally {
            file_put_contents($path, $original);
        }
    }

    /**
     * @param array{state:string,credentials:string,prior:string} $f
     * @return array<string,mixed> */
    public static function options(array $f): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $f['credentials'],
            'state-dir' => $f['state'], 'prior-ledger' => $f['prior'],
            'disable-own-alipay-hk-consent-once' => true];
    }

    protected function tearDown(): void
    {
        foreach ($this->bases as $base) {
            AlipayConsentReconciliationTest::remove($base);
        }
    }

    public function testOwnedDisableAndReadbackKeepTheOriginalCasePendingAndImmutable(): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $before = file_get_contents($f['prior']);
        $r = AlipayConsentDisableCleanupCommand::run(self::options($f), $f['provider']);
        self::assertSame('pass', $r['cleanup_observation_result'], json_encode($r, JSON_THROW_ON_ERROR));
        self::assertSame('pending', $r['result']);
        self::assertSame('DISABLED', $r['current_consent_state']);
        self::assertSame('durable', $r['claim_durability']);
        self::assertTrue($r['action_uuid_durable']);
        self::assertTrue($r['claim_binding_durable']);
        self::assertTrue($r['historical_verification_or_financial_disposition_unresolved']);
        self::assertSame(7, $r['http_invocations']);
        self::assertSame(1, $r['disable_invocations']);
        self::assertTrue($r['disable_possible']);
        self::assertSame($before, file_get_contents($f['prior']));
        self::assertStringNotContainsString('PRIVATE', json_encode($r, JSON_THROW_ON_ERROR));
        self::assertSame(
            ['POST', 'GET', 'GET', 'GET', 'GET', 'POST', 'GET'],
            array_map(static fn (RequestInterface $request): string => $request->getMethod(), $f['provider']->requests)
        );
        $claims = glob($f['state'] . '/.consent-disable-*.claim') ?: [];
        self::assertCount(1, $claims);
        $claimBefore = file_get_contents($claims[0]);
        $f['provider']->requests = [];
        $second = AlipayConsentDisableCleanupCommand::run(self::options($f), $f['provider']);
        self::assertSame('fail', $second['result']);
        self::assertSame('claim_precheck', $second['phase']);
        self::assertSame([], $f['provider']->requests);
        self::assertSame($claimBefore, file_get_contents($claims[0]));
    }

    /**
     * @return iterable<string,array{string}> */
    public static function initialStates(): iterable
    {
        foreach (['DISABLED', 'VERIFIED', 'PAUSED', 'PENDING', 'REQUIRES_CUSTOMER_ACTION', 'UNLISTED'] as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('initialStates')]
    public function testOnlyRpmCanReachTheClaimAndDisableWhileDisabledIsAnObservation(string $state): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $f['provider']->resources[$f['consent']]['status'] = $state;
        $r = AlipayConsentDisableCleanupCommand::run(self::options($f), $f['provider']);
        self::assertSame('pending', $r['result']);
        self::assertSame($state === 'DISABLED' ? 'pass' : 'pending', $r['cleanup_observation_result']);
        self::assertSame(3, $r['http_invocations']);
        self::assertSame(0, $r['disable_invocations']);
        self::assertSame('not_attempted', $r['claim_durability']);
        self::assertSame([], glob($f['state'] . '/.consent-disable-*.claim'));
        self::assertTrue($r['historical_verification_or_financial_disposition_unresolved']);
    }

    /**
     * @return iterable<string,array{string}> */
    public static function ownerFailures(): iterable
    {
        foreach (
            ['customer_id', 'customer_marker', 'consent_id', 'consent_owner', 'consent_marker',
            'trigger', 'reason', 'method_type', 'numeric_owner', 'duplicate_owner', 'status_type'] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('ownerFailures')]
    public function testCurrentLiteralOwnerTermsAndUniqueNativeObjectsAreRequired(string $case): void
    {
        $f = self::fixture($case === 'numeric_owner');
        $this->bases[] = $f['base'];
        $p = $f['provider'];
        match ($case) {
            'customer_id' => $p->resources[$f['customer']]['id'] = 'FOREIGN',
            'customer_marker' => $p->resources[$f['customer']]['merchant_customer_id'] = 'FOREIGN',
            'consent_id' => $p->resources[$f['consent']]['id'] = 'FOREIGN',
            'consent_owner' => $p->resources[$f['consent']]['customer_id'] = 'FOREIGN',
            'consent_marker' => $p->resources[$f['consent']]['metadata'] = ['sdk_validation_run' => 'FOREIGN'],
            'trigger' => $p->resources[$f['consent']]['next_triggered_by'] = 'customer',
            'reason' => $p->resources[$f['consent']]['merchant_trigger_reason'] = 'scheduled',
            'method_type' => $p->resources[$f['consent']]['payment_method'] = ['type' => 'card'],
            'status_type' => $p->resources[$f['consent']]['status'] = 123,
            default => null,
        };
        $original = $p->intercept;
        self::assertNotNull($original);
        if (in_array($case, ['numeric_owner', 'duplicate_owner'], true)) {
            $p->intercept = static function (RequestInterface $request) use ($original, $f, $case): ResponseInterface {
                $response = $original($request);
                self::assertInstanceOf(ResponseInterface::class, $response);
                if ($request->getUri()->getPath() === '/api/v1/pa/payment_consents/' . $f['consent']) {
                    $raw = (string) $response->getBody();
                    $raw = $case === 'numeric_owner' ? str_replace(
                        '"customer_id":"' . $f['customer'] . '"',
                        '"customer_id":' . $f['customer'],
                        $raw
                    ) : '{"customer_id":"FOREIGN",' . substr($raw, 1);
                    return new Response(200, ['Content-Type' => 'application/json'], $raw);
                }
                return $response;
            };
        }
        $r = AlipayConsentDisableCleanupCommand::run(self::options($f), $p);
        self::assertSame('fail', $r['result']);
        self::assertSame(0, $r['disable_invocations']);
        self::assertSame([], glob($f['state'] . '/.consent-disable-*.claim'));
        self::assertStringNotContainsString('FOREIGN', json_encode($r, JSON_THROW_ON_ERROR));
    }

    public function testQuotedNumericIdentitiesAndMissingOptionalMethodIdStillPermitTheExactOwnedCleanup(): void
    {
        $f = self::fixture(true);
        $this->bases[] = $f['base'];
        unset($f['provider']->resources[$f['consent']]['payment_method']);
        $r = AlipayConsentDisableCleanupCommand::run(self::options($f), $f['provider']);
        self::assertSame('pass', $r['cleanup_observation_result']);
        self::assertSame(1, $r['disable_invocations']);
    }

    /**
     * @return iterable<string,array{int}> */
    public static function claimFailures(): iterable
    {
        yield 'file fsync' => [1];
        yield 'parent fsync' => [2];
        yield 'file already closed' => [3];
        yield 'parent already closed' => [4];
    }

    #[DataProvider('claimFailures')]
    public function testFailedClaimDurabilityNeverClaimsPersistenceOrRequestsAnActionUuid(int $failure): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $calls = 0;
        $r = AlipayConsentDisableCleanupCommand::run(
            self::options($f),
            $f['provider'],
            claimSynchronize: static function ($stream) use (&$calls, $failure): bool {
                ++$calls;
                if ($failure >= 3 && $calls === $failure - 2) {
                    fclose($stream);
                    return true;
                }
                return $calls !== $failure && fsync($stream);
            }
        );
        self::assertSame('fail', $r['result']);
        self::assertSame('unestablished', $r['claim_durability']);
        self::assertFalse($r['action_uuid_durable']);
        self::assertFalse($r['claim_binding_durable']);
        self::assertSame(0, $r['disable_invocations']);
        self::assertSame(3, $r['http_invocations']);
        self::assertSame($failure >= 3 ? $failure - 2 : $failure, $calls);
        self::assertCount(1, glob($f['state'] . '/.consent-disable-*.claim') ?: []);
        $f['provider']->requests = [];
        $again = AlipayConsentDisableCleanupCommand::run(self::options($f), $f['provider']);
        self::assertSame('claim_precheck', $again['phase']);
        self::assertSame([], $f['provider']->requests);
    }

    /**
     * @return iterable<string,array{string}> */
    public static function afterClaimFailures(): iterable
    {
        foreach (
            ['uuid_fsync', 'binding_fsync', 'claim_mutation', 'credential_mutation', 'prior_mutation',
            'source_mutation', 'clock_rollback', 'clock_expiry', 'guard_owner', 'guard_state'] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('afterClaimFailures')]
    public function testNoDependentWriteAfterClaimOrJournalOrFinalGuardFailure(string $case): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $clock = 0.0;
        $fired = false;
        $sourcePath = self::sdkRoot() . '/tests/Support/Sandbox/Journeys/AlipayConsentReconciliation.php';
        $sourceBefore = file_get_contents($sourcePath);
        self::assertIsString($sourceBefore);
        $factory = static function (
            string $context,
            string $directory
        ) use (
            $f,
            $case,
            &$clock,
            &$fired,
            $sourcePath,
            $sourceBefore
): RunLedger {
            return RunLedger::createConsentCleanup($context, $directory, synchronize: static function ($stream) use (
                $f,
                $case,
                &$clock,
                &$fired,
                $sourcePath,
                $sourceBefore
            ): bool {
                $uri = self::streamPath(stream_get_meta_data($stream));
                if (str_ends_with($uri, '.writing')) {
                    $raw = file_get_contents($uri);
                    $state = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : [];
                    self::assertIsArray($state);
                    $actions = $state['actions'] ?? [];
                    self::assertIsArray($actions);
                    $obs = $state['observations'] ?? [];
                    self::assertIsArray($obs);
                    if (!$fired && isset($actions['consent_disable_cleanup'])) {
                        if (
                            $case === 'uuid_fsync' || $case === 'binding_fsync'
                            && isset($obs['consent_cleanup_claim_binding'])
                        ) {
                            $fired = true;
                            return false;
                        }
                        if ($case === 'claim_mutation') {
                            $fired = true;
                            foreach (glob($f['state'] . '/.consent-disable-*.claim') ?: [] as $claim) {
                                file_put_contents($claim, 'changed');
                            }
                        }
                        if ($case === 'credential_mutation') {
                            $fired = true;
                            file_put_contents($f['credentials'], "AIRWALLEX_CLIENT_ID=changed\n");
                        }
                        if ($case === 'prior_mutation') {
                            $fired = true;
                            file_put_contents($f['prior'], '{}');
                        }
                        if ($case === 'source_mutation') {
                            $fired = true;
                            file_put_contents($sourcePath, $sourceBefore . "\n// synthetic mutation\n");
                        }
                        if ($case === 'clock_rollback' || $case === 'clock_expiry') {
                            $fired = true;
                            $clock = $case === 'clock_expiry' ? 120.0 : -1.0;
                        }
                    }
                }
                return fsync($stream);
            });
        };
        $original = $f['provider']->intercept;
        self::assertNotNull($original);
        $f['provider']->intercept = static function (RequestInterface $request) use (
            $original,
            $f,
            $case,
            &$fired
): ResponseInterface {
            $isGuard = count($f['provider']->requests) >= 5;
            if ($isGuard && $request->getMethod() === 'GET') {
                if ($case === 'guard_owner') {
                    $fired = true;
                    $f['provider']->resources[$f['consent']]['customer_id'] = 'FOREIGN';
                } elseif ($case === 'guard_state') {
                    $fired = true;
                    $f['provider']->resources[$f['consent']]['status'] = 'VERIFIED';
                }
            }
            $response = $original($request);
            self::assertInstanceOf(ResponseInterface::class, $response);
            return $response;
        };
        try {
            $r = AlipayConsentDisableCleanupCommand::run(
                self::options($f),
                $f['provider'],
                $factory,
                static function () use (&$clock): float {
                    return $clock;
                }
            );
            self::assertTrue($fired);
            self::assertSame($case === 'guard_state' ? 'pending' : 'fail', $r['result']);
            self::assertSame(0, $r['disable_invocations']);
            self::assertCount(1, glob($f['state'] . '/.consent-disable-*.claim') ?: []);
            if (!in_array($case, ['uuid_fsync', 'binding_fsync'], true)) {
                self::assertSame('durable', $r['claim_durability']);
            }
        } finally {
            file_put_contents($sourcePath, $sourceBefore);
        }
    }

    /**
     * @return iterable<string,array{string}> */
    public static function disableFailures(): iterable
    {
        foreach (
            ['400', '401', '403', '404', '500', '201', 'transport', 'wrong_state', 'wrong_id',
            'readback_foreign', 'readback_state', 'duplicate', 'bad_media', 'oversize'] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('disableFailures')]
    public function testPossiblySentFailuresRemainUncertainWithoutRetryOrExtraReadLane(string $case): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $original = $f['provider']->intercept;
        self::assertNotNull($original);
        $f['provider']->intercept = static function (
            RequestInterface $request
        ) use (
            $original,
            $f,
            $case
): ResponseInterface {
            $path = $request->getUri()->getPath();
            $disable = str_ends_with($path, '/disable');
            $readback = !$disable && count($f['provider']->requests) === 7;
            if ($disable && ctype_digit($case)) {
                return new Response(
                    (int) $case,
                    ['Content-Type' => 'application/json'],
                    '{"message":"PRIVATE failure"}'
                );
            }
            if ($disable && $case === 'transport') {
                throw new RuntimeException('PRIVATE exception');
            }
            $r = $original($request);
            self::assertInstanceOf(ResponseInterface::class, $r);
            if ($disable && $case === 'wrong_state' || $readback && $case === 'readback_state') {
                $f['provider']->resources[$f['consent']]['status'] = 'VERIFIED';
            }
            if ($disable && $case === 'wrong_id' || $readback && $case === 'readback_foreign') {
                $f['provider']->resources[$f['consent']]['id'] = 'FOREIGN';
            }
            if ($disable || $readback) {
                $raw = (new JsonCodec())->encode($f['provider']->resources[$f['consent']]);
                if ($disable && $case === 'duplicate') {
                    $raw = '{"status":"VERIFIED",' . substr($raw, 1);
                }
                if ($disable && $case === 'oversize') {
                    $raw = str_repeat(' ', 65537);
                }
                return new Response(200, ['Content-Type' => $disable && $case === 'bad_media'
                    ? 'text/html' : 'application/json'], $raw);
            }
            return $r;
        };
        $r = AlipayConsentDisableCleanupCommand::run(self::options($f), $f['provider']);
        self::assertSame('fail', $r['result']);
        self::assertSame(1, $r['disable_invocations']);
        self::assertTrue($r['disable_possible']);
        self::assertSame('pending_unverified', $r['consent_cleanup']);
        self::assertTrue($r['transport_poisoned']);
        self::assertSame(str_starts_with($case, 'readback_') ? 7 : 6, $r['http_invocations']);
        self::assertTrue($r['historical_reconciliation_required']);
        self::assertStringNotContainsString('PRIVATE exception', json_encode($r, JSON_THROW_ON_ERROR));
    }

    public function testOneDocumentedTokenRefreshUsesTheEighthSlotWithoutAddingAnotherResourceRead(): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $unix = 100;
        $logins = 0;
        $original = $f['provider']->intercept;
        self::assertNotNull($original);
        $f['provider']->intercept = static function (RequestInterface $request) use (
            $original,
            $f,
            &$unix,
            &$logins
): ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/login')) {
                ++$logins;
                return new Response(201, ['Content-Type' => 'application/json'], json_encode([
                    'token' => 'synthetic-cleanup-token', 'expires_at' => $logins === 1
                        ? '1970-01-01T00:03:20Z' : '2100-01-01T00:00:00Z',
                ], JSON_THROW_ON_ERROR));
            }
            $r = $original($request);
            self::assertInstanceOf(ResponseInterface::class, $r);
            if (count($f['provider']->requests) === 3) {
                $unix = 201;
            }
            return $r;
        };
        $r = AlipayConsentDisableCleanupCommand::run(
            self::options($f),
            $f['provider'],
            unixClock: static function () use (&$unix): int {
                return $unix;
            }
        );
        self::assertSame('pass', $r['cleanup_observation_result'], json_encode($r, JSON_THROW_ON_ERROR));
        self::assertSame(8, $r['http_invocations']);
        self::assertSame(2, $r['login_attempts']);
        self::assertSame(2, $r['customer_get_attempts']);
        self::assertSame(3, $r['consent_get_attempts']);
        self::assertSame(1, $r['disable_invocations']);
    }

    /** @return iterable<string,array{string}> */
    public static function nativeLoginFailures(): iterable
    {
        foreach (
            ['unexpected_200', 'numeric_token', 'duplicate_token', 'expired', 'wall_rollback',
            'header_flood', 'long_value', 'wrong_media', 'late_native', 'late_close', 'late_read'] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('nativeLoginFailures')]
    public function testNativeLoginBoundsCloseEveryRejectedStreamAndNeverSendResourceReads(string $case): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $now = 0.0;
        $unix = 100;
        $headers = ['Content-Type' => $case === 'wrong_media' ? 'text/html' : 'application/json'];
        if ($case === 'header_flood') {
            $headers['X-Flood'] = array_fill(0, 5000, '');
        }
        if ($case === 'long_value') {
            $headers['X-Long'] = str_repeat('a', 8193);
        }
        $raw = '{"token":"synthetic","expires_at":"2100-01-01T00:00:00Z"}';
        if ($case === 'numeric_token') {
            $raw = '{"token":123,"expires_at":"2100-01-01T00:00:00Z"}';
        } elseif ($case === 'duplicate_token') {
            $raw = '{"token":"other",' . substr($raw, 1);
        } elseif ($case === 'expired') {
            $raw = '{"token":"synthetic","expires_at":"1970-01-01T00:01:40Z"}';
        }
        $onRead = static function () use (&$now, $case): void {
            if ($case === 'late_read') {
                $now = 10.0;
            }
        };
        $onClose = static function () use (&$now, $case): void {
            if ($case === 'late_close') {
                $now = 10.0;
            }
        };
        $stream = new class (
            \GuzzleHttp\Psr7\Utils::streamFor($raw),
            $onRead,
            $onClose
        ) implements \Psr\Http\Message\StreamInterface {
            use \GuzzleHttp\Psr7\StreamDecoratorTrait;

            public int $closes = 0;
            public function __construct(
                private \Psr\Http\Message\StreamInterface $stream,
                private readonly \Closure $onRead,
                private readonly \Closure $onClose
            ) {
            }
            public function read(int $length): string
            {
                ($this->onRead)();
                return $this->stream->read($length);
            }
            public function close(): void
            {
                ++$this->closes;
                ($this->onClose)();
                $this->stream->close();
            }
        };
        $f['provider']->intercept = static function (RequestInterface $request) use (
            $stream,
            $headers,
            $case,
            &$now,
            &$unix
): ResponseInterface {
            if ($case === 'late_native') {
                $now = 10.0;
            }
            if ($case === 'wall_rollback') {
                $unix = -1;
            }
            return new Response($case === 'unexpected_200' ? 200 : 201, $headers, $stream);
        };
        $r = AlipayConsentDisableCleanupCommand::run(
            self::options($f),
            $f['provider'],
            clock: static function () use (&$now): float {
                return $now;
            },
            unixClock: static function () use (&$unix): int {
                return $unix;
            }
        );
        self::assertSame('fail', $r['result']);
        self::assertSame(1, $r['http_invocations']);
        self::assertSame(0, $r['disable_invocations']);
        self::assertSame(1, $stream->closes);
        self::assertTrue($r['transport_poisoned']);
        self::assertCount(1, $f['provider']->requests);
    }

    /** @return iterable<string,array{bool}> */
    public static function coherentOutcomes(): iterable
    {
        yield 'complete observation' => [false];
        yield 'late contradictory readback' => [true];
    }

    #[DataProvider('coherentOutcomes')]
    public function testCompleteControllerJournalFitsMaximumAllocationWithoutExtraFiles(bool $failure): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $original = $f['provider']->intercept;
        self::assertNotNull($original);
        $f['provider']->intercept = static function (
            RequestInterface $request
        ) use (
            $original,
            $f,
            $failure
): ResponseInterface {
            $r = $original($request);
            self::assertInstanceOf(ResponseInterface::class, $r);
            if (str_ends_with($request->getUri()->getPath(), '/login')) {
                return $r;
            }
            $body = (string) $r->getBody();
            if ($failure && count($f['provider']->requests) === 7) {
                $body = str_replace('"status":"DISABLED"', '"status":"VERIFIED"', $body);
            }
            $raw = substr($body, 0, -1) . ',"padding":"';
            $target = count($f['provider']->requests) >= 6 ? 65536 : 32768;
            $space = $target - strlen($raw) - 2;
            $raw .= str_repeat("\u{0080}", intdiv($space, 2)) . str_repeat('x', $space % 2) . '"}';
            self::assertSame($target, strlen($raw));
            return new Response(200, ['Content-Type' => 'application/json'], $raw);
        };
        $r = AlipayConsentDisableCleanupCommand::run(self::options($f), $f['provider']);
        self::assertSame(
            $failure ? 'fail' : 'pass',
            $r['cleanup_observation_result'],
            json_encode($r, JSON_THROW_ON_ERROR)
        );
        $paths = array_values(array_filter(
            glob($f['state'] . '/*/ledger.json') ?: [],
            static fn (string $p): bool => $p !== $f['prior']
        ));
        self::assertCount(1, $paths);
        $raw = file_get_contents($paths[0]);
        self::assertIsString($raw);
        self::assertLessThanOrEqual(851968, strlen($raw));
        $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsArray($state['observations']);
        $total = $pre = 0;
        foreach ($state['observations'] as $phase => &$entry) {
            self::assertIsString($phase);
            self::assertIsArray($entry);
            if (isset($entry['private_json'])) {
                self::assertIsString($entry['private_json']);
                $bytes = strlen($entry['private_json']);
                $total += $bytes;
                if (str_contains($phase, '_initial_') || str_contains($phase, '_guard_')) {
                    $pre += $bytes;
                }
                $entry['private_json'] = '';
            }
        }
        unset($entry);
        self::assertSame(262144, $total);
        self::assertSame(131072, $pre);
        self::assertLessThanOrEqual(65536, strlen(json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)));
        self::assertCount(1, glob(dirname($paths[0]) . '/*') ?: []);
        self::assertSame([], $state['resources']);
        self::assertFileDoesNotExist($paths[0] . '.writing');
    }

    public function testPrivateClaimImageHardCapIsCheckedAtExactBoundaryBeforeCreation(): void
    {
        $method = new \ReflectionMethod(
            \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentDisableCleanup::class,
            'boundedClaimBytes'
        );
        self::assertSame(str_repeat('x', 4096), $method->invoke(null, str_repeat('x', 4096)));
        $this->expectException(RuntimeException::class);
        $method->invoke(null, str_repeat('x', 4097));
    }

    public function testClaimThatFinishesAfterCaseDeadlineCannotAcquireAnActionUuid(): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $now = 0.0;
        $r = AlipayConsentDisableCleanupCommand::run(
            self::options($f),
            $f['provider'],
            clock: static function () use (&$now): float {
                return $now;
            },
            claimSynchronize: static function ($stream) use (&$now): bool {
                $ok = fsync($stream);
                $now = 120.0;
                return $ok;
            }
        );
        self::assertSame('fail', $r['result']);
        self::assertSame('durable', $r['claim_durability']);
        self::assertFalse($r['action_uuid_durable']);
        self::assertFalse($r['claim_binding_durable']);
        self::assertSame(0, $r['disable_invocations']);
        self::assertSame(3, $r['http_invocations']);
    }

    public function testFinalGuardAlreadyDisabledIsSeparatelyLabelledWithoutAPost(): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $original = $f['provider']->intercept;
        self::assertNotNull($original);
        $f['provider']->intercept = static function (RequestInterface $request) use ($original, $f): ResponseInterface {
            if (count($f['provider']->requests) === 5) {
                $f['provider']->resources[$f['consent']]['status'] = 'DISABLED';
            }
            $r = $original($request);
            self::assertInstanceOf(ResponseInterface::class, $r);
            return $r;
        };
        $r = AlipayConsentDisableCleanupCommand::run(self::options($f), $f['provider']);
        self::assertSame('pending', $r['result']);
        self::assertSame('pass', $r['cleanup_observation_result']);
        self::assertSame('already_disabled_current_observation', $r['consent_cleanup']);
        self::assertSame(5, $r['http_invocations']);
        self::assertSame(0, $r['disable_invocations']);
        self::assertTrue($r['claim_binding_durable']);
        self::assertLessThanOrEqual(65536, strlen(json_encode($r, JSON_THROW_ON_ERROR)));
    }
    private static function streamPath(mixed $metadata): string
    {
        self::assertIsArray($metadata);
        $uri = $metadata['uri'] ?? null;
        self::assertIsString($uri);
        return $uri;
    }
}
