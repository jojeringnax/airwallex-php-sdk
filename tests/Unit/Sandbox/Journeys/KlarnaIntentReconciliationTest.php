<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\KlarnaIntentReconciliation;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\KlarnaIntentReconciliationCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\KlarnaReconciliationClaim;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\KlarnaReconciliationTransport;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class KlarnaIntentReconciliationTest extends TestCase
{
    private string $directory;
    private string $priorPath;
    private string $fingerprint;
    private string $marker;
    private string $original;
    private KlarnaReconciliationProvider $provider;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/klarna-reconciliation-' . bin2hex(random_bytes(8));
        mkdir($path, 0700);
        $directory = realpath($path);
        self::assertIsString($directory);
        $this->directory = $directory;
        file_put_contents($directory . '/credentials', "AIRWALLEX_CLIENT_ID=PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=PRIVATE_KEY\nAIRWALLEX_ACCOUNT_ID=PRIVATE_ACCOUNT\n");
        $this->prior('1.23');
    }

    private function prior(string $amount, bool $legacy = false): void
    {
        $session = new SandboxSession(Configuration::load('sandbox', $this->directory . '/credentials'), 'account');
        $this->fingerprint = $session->contextFingerprint();
        $ledger = RunLedger::create($this->fingerprint, $this->directory);
        $this->marker = $ledger->runId();
        $this->priorPath = $this->directory . '/' . $this->marker . '/ledger.json';
        $hash = hash_file('sha256', dirname(__DIR__, 4) . '/tools/klarna-manual-capture.php');
        self::assertIsString($hash);
        $binding = ['case' => 'capture-refund', 'amount' => $amount,
            'amount_choice' => $amount === '100.00' ? 'guide' : 'default',
            'tool_sha256' => $hash, 'api_version' => '2026-08-21'];
        if ($legacy) {
            unset($binding['amount'], $binding['amount_choice']);
        }
        $ledger->requestId('klarna_preflight', $binding, $this->fingerprint);
        $body = KlarnaIntentReconciliation::createBody($this->marker, $amount);
        $ledger->requestId('klarna_create', $body, $this->fingerprint);
        $ledger->recordCreated('payment_intents', 'PRIVATE_INTENT');
        $ledger->recordObservation('klarna_create_response', ['id' => 'PRIVATE_INTENT'], $this->fingerprint);
        $ledger->requestId('klarna_confirm', ['payment_method' => ['type' => 'klarna',
            'klarna' => ['country_code' => 'DE', 'language' => 'en']],
            'payment_method_options' => ['klarna' => ['auto_capture' => false]]], $this->fingerprint);
        $ledger->recordObservation('klarna_confirm_target', ['intent_id' => 'PRIVATE_INTENT'], $this->fingerprint);
        $raw = file_get_contents($this->priorPath);
        self::assertIsString($raw);
        $this->original = $raw;
        $this->provider = new KlarnaReconciliationProvider($body);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->directory,
            \FilesystemIterator::SKIP_DOTS
        ), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $path = $file->getPathname();
            is_link($path) || !$file->isDir() ? unlink($path) : rmdir($path);
        }
        rmdir($this->directory);
    }

    /** @return array<string,mixed> */
    private function options(bool $cancel = false): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'state-dir' => $this->directory, 'prior-ledger' => $this->priorPath, 'cancel-unconfirmed' => $cancel];
    }

    /** @param (Closure(string,string):RunLedger)|null $ledgers
     * @return array<string,mixed>
     */
    private function runCase(bool $cancel = false, ?Closure $ledgers = null): array
    {
        $result = KlarnaIntentReconciliationCommand::run(
            $this->options($cancel),
            $this->provider,
            $ledgers,
            static function (): void {
            },
            3
        );
        self::assertSame($this->original, file_get_contents($this->priorPath));
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
        return $result;
    }

    private function cancels(): int
    {
        return count(array_filter($this->provider->requests, static fn (RequestInterface $r): bool
            => str_ends_with($r->getUri()->getPath(), '/cancel')));
    }

    public function testDefaultReconcilesWithoutAnyMutationOrClaimAndUsesExactNativeRefundFilter(): void
    {
        $result = $this->runCase();
        self::assertSame('blocked', $result['result']);
        self::assertTrue($result['own_intent_verified']);
        self::assertTrue($result['zero_captured_verified']);
        self::assertTrue($result['zero_refunds_verified']);
        self::assertTrue($result['prior_ledger_unchanged']);
        self::assertSame(0, $this->cancels());
        self::assertDirectoryDoesNotExist($this->directory . '/klarna-cancellation-claims');
        self::assertCount(3, $this->provider->requests);
        self::assertSame(
            'payment_intent_id=PRIVATE_INTENT&page_num=0&page_size=100',
            $this->provider->requests[2]->getUri()->getQuery()
        );
    }

    public function testFreshCancelHasDurableClaimWireAndDistinctIdentityBeforeItsOnlySend(): void
    {
        $this->provider->before = function (RequestInterface $request): void {
            if (!str_ends_with($request->getUri()->getPath(), '/cancel')) {
                return;
            }
            $claims = glob($this->directory . '/klarna-cancellation-claims/*.json');
            self::assertIsArray($claims);
            self::assertCount(1, $claims);
            self::assertSame(0600, fileperms($claims[0]) & 0777);
            $files = glob($this->directory . '/sdk-validation-*/ledger.json');
            self::assertIsArray($files);
            $proved = false;
            foreach ($files as $path) {
                $data = json_decode(file_get_contents($path) ?: '', true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($data);
                $observations = $data['observations'] ?? null;
                self::assertIsArray($observations);
                $wire = $observations['klarna_reconciliation_cancel_wire'] ?? null;
                if (!is_array($wire)) {
                    continue;
                }
                self::assertSame(hash('sha256', (string) $request->getBody()), $wire['wire_sha256']);
                self::assertSame(hash('sha256', $this->original), $wire['prior_sha256']);
                $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                self::assertSame(['cancellation_reason', 'request_id'], array_keys($body));
                self::assertIsString($body['request_id']);
                self::assertStringNotContainsString($body['request_id'], $this->original);
                $proved = true;
            }
            self::assertTrue($proved);
        };
        $result = $this->runCase(true);
        self::assertSame('pass', $result['result']);
        self::assertTrue($result['cancelled_verified']);
        self::assertTrue($result['post_cancel_refunds_empty']);
        self::assertFalse($result['reconciliation_required']);
        self::assertSame(1, $this->cancels());
        self::assertCount(7, $this->provider->requests);
    }

    public function testGuideAndLegacyPriorBindingsAreAcceptedWithoutChangingTheirAmount(): void
    {
        foreach ([['100.00', false], ['1.23', true]] as [$amount, $legacy]) {
            $this->prior($amount, $legacy);
            $result = $this->runCase(true);
            self::assertSame('pass', $result['result']);
            self::assertSame($amount === '100.00' ? 'guide' : 'default', $result['amount_choice']);
        }
    }

    public function testExistingCancelledIsReadOnlyAndStillRequiresTerminalEmptyRefundPage(): void
    {
        $this->provider->intent['status'] = 'CANCELLED';
        $result = $this->runCase(true);
        self::assertSame('pass', $result['result']);
        self::assertSame(0, $this->cancels());
        self::assertCount(3, $this->provider->requests);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function badIntent(): iterable
    {
        yield 'foreign' => ['id', 'FOREIGN'];
        yield 'wrong money' => ['amount', '1.24'];
        yield 'float' => ['amount', 1.23];
        yield 'currency' => ['currency', 'HKD'];
        yield 'merchant order' => ['merchant_order_id', 'OTHER'];
        yield 'run marker' => ['metadata', ['sdk_validation_run' => 'OTHER']];
        yield 'return URL' => ['return_url', 'https://other.test'];
        yield 'products' => ['order', ['products' => []]];
        yield 'captured' => ['captured_amount', '0.01'];
        yield 'missing captured' => ['captured_amount', null];
        yield 'authorized' => ['status', 'REQUIRES_CAPTURE'];
        yield 'succeeded' => ['status', 'SUCCEEDED'];
        yield 'pending' => ['status', 'PENDING'];
        yield 'unknown' => ['status', 'PRIVATE_UNKNOWN'];
    }

    #[DataProvider('badIntent')]
    public function testUnprovedOrIneligibleIntentCannotCancel(string $field, mixed $value): void
    {
        $this->provider->intent[$field] = $value;
        $result = $this->runCase(true);
        self::assertTrue($result['reconciliation_required']);
        self::assertSame(0, $this->cancels());
    }

    /** @return iterable<string,array{array<array-key,mixed>}> */
    public static function badRefundPages(): iterable
    {
        yield 'missing' => [[]];
        yield 'no paging' => [['items' => []]];
        yield 'more' => [['items' => [], 'has_more' => true]];
        yield 'not array' => [['items' => null, 'has_more' => false]];
        yield 'nonempty own' => [['items' => [['id' => 'PRIVATE_REFUND', 'payment_intent_id' => 'PRIVATE_INTENT']],
            'has_more' => false]];
        yield 'foreign' => [['items' => [['id' => 'PRIVATE_FOREIGN', 'payment_intent_id' => 'PRIVATE_OTHER']],
            'has_more' => false]];
    }

    /** @param array<array-key,mixed> $page */
    #[DataProvider('badRefundPages')]
    public function testIncompleteOrNonzeroRefundInventoryCannotCancel(array $page): void
    {
        $this->provider->refunds = $page;
        $result = $this->runCase(true);
        self::assertSame('fail', $result['result']);
        self::assertSame(0, $this->cancels());
    }

    public function testPendingAndAmbiguousCancellationRemainFencedAcrossNewInvocations(): void
    {
        $this->provider->cancel = false;
        $first = $this->runCase(true);
        self::assertSame('blocked', $first['result']);
        self::assertTrue($first['reconciliation_required']);
        self::assertFalse($first['zero_refunds_verified']);
        self::assertSame(1, $this->cancels());
        $second = $this->runCase(true);
        self::assertSame('cancellation_already_reserved', $second['reason']);
        self::assertSame(1, $this->cancels());
    }

    public function testSentTransportFailureRetainsPermanentClaimAndNoReplay(): void
    {
        $this->provider->before = static function (RequestInterface $request): void {
            if (str_ends_with($request->getUri()->getPath(), '/cancel')) {
                throw new RuntimeException('PRIVATE_URL_TOKEN');
            }
        };
        self::assertSame('fail', $this->runCase(true)['result']);
        self::assertSame('cancellation_already_reserved', $this->runCase(true)['reason']);
        self::assertSame(1, $this->cancels());
    }

    /** @param Closure(array<array-key,mixed>):array<array-key,mixed> $change */
    private function editPrior(Closure $change): void
    {
        $data = json_decode($this->original, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $raw = json_encode($change($data), JSON_THROW_ON_ERROR);
        file_put_contents($this->priorPath, $raw);
        $this->original = $raw;
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidPrior(): iterable
    {
        yield 'version' => ['api_version', '2025-01-01'];
        yield 'context' => ['context_fingerprint', str_repeat('0', 64)];
        yield 'source' => ['source', ['base_commit' => 'PRIVATE']];
        yield 'run' => ['run_id', 'PRIVATE'];
        yield 'inventory' => ['resources', ['payment_intents' => ['PRIVATE_INTENT', 'PRIVATE_OTHER']]];
        yield 'unknown action' => ['actions', []];
        yield 'returned proof' => ['observations', []];
    }

    #[DataProvider('invalidPrior')]
    public function testPriorValidationFailsBeforeAuth(string $field, mixed $value): void
    {
        $this->editPrior(static function (array $data) use ($field, $value): array {
            $data[$field] = $value;
            return $data;
        });
        self::assertSame('fail', $this->runCase(true)['result']);
        self::assertSame([], $this->provider->requests);
    }

    public function testCopiedRunAndDifferentOrSymlinkStateRootsAreRejectedBeforeAuth(): void
    {
        mkdir($this->directory . '/other', 0700);
        foreach ([$this->directory . '/other', $this->directory . '/.'] as $root) {
            $options = $this->options(true);
            $options['state-dir'] = $root;
            self::assertSame('fail', KlarnaIntentReconciliationCommand::run($options, $this->provider)['result']);
        }
        symlink($this->directory, $this->directory . '/link');
        $options = $this->options(true);
        $options['state-dir'] = $this->directory . '/link';
        self::assertSame('fail', KlarnaIntentReconciliationCommand::run($options, $this->provider)['result']);
        copy($this->priorPath, $this->directory . '/other/ledger.json');
        $options = $this->options(true);
        $options['prior-ledger'] = $this->directory . '/other/ledger.json';
        self::assertSame('fail', KlarnaIntentReconciliationCommand::run($options, $this->provider)['result']);
        self::assertSame([], $this->provider->requests);
    }

    public function testPriorReservedCancelBlocksNewCancel(): void
    {
        $this->editPrior(static function (array $data): array {
            self::assertIsArray($data['actions']);
            $data['actions']['klarna_clean_cancel'] = ['request_id' => '12345678-1234-1234-1234-123456789abc',
                'body_hash' => str_repeat('1', 64)];
            return $data;
        });
        self::assertSame('cancellation_already_reserved', $this->runCase(true)['reason']);
        self::assertSame(0, $this->cancels());
    }

    public function testPoisonedNewJournalPreventsAuth(): void
    {
        $result = $this->runCase(true, function (string $fp, string $directory): RunLedger {
            $ledger = RunLedger::create($fp, $directory);
            try {
                $ledger->requestId('poison', [], str_repeat('0', 64));
            } catch (RuntimeException) {
                // A context rejection alone does not poison durability: remove the owned directory instead.
            }
            $folder = $directory . '/' . $ledger->runId();
            unlink($folder . '/ledger.json');
            rmdir($folder);
            return $ledger;
        });
        self::assertSame('fail', $result['result']);
        self::assertSame([], $this->provider->requests);
    }

    public function testClaimFailureLeavesAFileAndNewClaimCannotReacquire(): void
    {
        $claim = new KlarnaReconciliationClaim(
            $this->directory,
            $this->fingerprint,
            $this->marker,
            'PRIVATE_INTENT',
            static fn (): bool => false
        );
        self::assertFalse($claim->acquire(['prior_sha256' => hash('sha256', $this->original)]));
        $again = new KlarnaReconciliationClaim($this->directory, $this->fingerprint, $this->marker, 'PRIVATE_INTENT');
        self::assertTrue($again->reserved());
        self::assertFalse($again->acquire([]));
        self::assertSame('cancellation_already_reserved', $this->runCase(true)['reason']);
        self::assertSame(0, $this->cancels());
    }

    public function testClaimIsIndependentOfLaterPriorFileHashAndNewCleanupRun(): void
    {
        $this->provider->cancel = false;
        $this->runCase(true);
        file_put_contents($this->priorPath, $this->original . "\n");
        $this->original .= "\n";
        self::assertSame('cancellation_already_reserved', $this->runCase(true)['reason']);
        self::assertSame(1, $this->cancels());
    }
    /** @return iterable<string,array{int}> */
    public static function claimSyncFailures(): iterable
    {
        yield 'claim file' => [1];
        yield 'registry directory' => [2];
        yield 'original state root' => [3];
    }

    #[DataProvider('claimSyncFailures')]
    public function testEveryClaimDurabilityFailureRemainsReserved(int $failure): void
    {
        $calls = 0;
        $claim = new KlarnaReconciliationClaim(
            $this->directory,
            $this->fingerprint,
            $this->marker,
            'PRIVATE_INTENT',
            static function ($stream) use (&$calls, $failure): bool {
                self::assertTrue(stream_get_meta_data($stream)['seekable']);
                return ++$calls !== $failure;
            }
        );
        self::assertFalse($claim->acquire(['prior_sha256' => hash('sha256', $this->original)]));
        $other = new KlarnaReconciliationClaim($this->directory, $this->fingerprint, $this->marker, 'PRIVATE_INTENT');
        self::assertTrue($other->reserved());
        self::assertFalse($other->acquire([]));
        self::assertSame('cancellation_already_reserved', $this->runCase(true)['reason']);
        self::assertSame(0, $this->cancels());
    }

    public function testTwoPreconstructedClaimantsCannotBothWinAndPartialFileCannotBeRepaired(): void
    {
        $first = new KlarnaReconciliationClaim($this->directory, $this->fingerprint, $this->marker, 'PRIVATE_INTENT');
        $second = new KlarnaReconciliationClaim($this->directory, $this->fingerprint, $this->marker, 'PRIVATE_INTENT');
        self::assertTrue($first->acquire(['synthetic' => 'first']));
        self::assertFalse($second->acquire(['synthetic' => 'second']));
        $files = glob($this->directory . '/klarna-cancellation-claims/*.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        file_put_contents($files[0], '{');
        self::assertSame('cancellation_already_reserved', $this->runCase(true)['reason']);
        self::assertSame('{', file_get_contents($files[0]));
        self::assertSame(0, $this->cancels());
    }

    public function testPreauthIntentIsDurableBeforeLoginAndReturnedMoneyIsNotInOutput(): void
    {
        $this->provider->before = function (RequestInterface $request): void {
            if ($request->getUri()->getPath() !== '/api/v1/authentication/login') {
                return;
            }
            $files = glob($this->directory . '/sdk-validation-*/ledger.json');
            self::assertIsArray($files);
            $preflight = 0;
            foreach ($files as $path) {
                $state = json_decode(file_get_contents($path) ?: '', true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($state);
                self::assertIsArray($state['actions']);
                $preflight += isset($state['actions']['klarna_reconciliation_preflight']) ? 1 : 0;
            }
            self::assertSame(1, $preflight);
        };
        $result = $this->runCase();
        self::assertArrayNotHasKey('amount', $result);
        self::assertArrayNotHasKey('intent_id', $result);
    }

    public function testChangedPriorDuringFreshReadForbidsAnyCancelAndIsReported(): void
    {
        $this->provider->before = function (RequestInterface $request): void {
            if ($request->getMethod() === 'GET') {
                file_put_contents($this->priorPath, $this->original . "\n");
            }
        };
        $result = KlarnaIntentReconciliationCommand::run($this->options(true), $this->provider);
        self::assertFalse($result['prior_ledger_unchanged']);
        self::assertSame('fail', $result['result']);
        self::assertSame(0, $this->cancels());
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function badOptions(): iterable
    {
        yield 'production' => [['environment' => 'production']];
        yield 'root override' => [['sdk-root' => '/PRIVATE']];
        yield 'identity override' => [['intent-id' => 'PRIVATE']];
        yield 'URL override' => [['base-url' => 'https://PRIVATE.test']];
        yield 'string flag' => [['cancel-unconfirmed' => 'true']];
    }

    /** @param array<string,mixed> $change */
    #[DataProvider('badOptions')]
    public function testInvalidCommandCannotReadNonexistentCredentialsOrAuthenticate(array $change): void
    {
        $options = array_replace($this->options(), ['credentials-file' => '/unread-PRIVATE'], $change);
        $safe = KlarnaIntentReconciliationCommand::run($options, $this->provider);
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $this->provider->requests);
        self::assertStringNotContainsString('PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }
    public function testPreCancelFreshReadRejectsStateChangeWithoutUsingOriginalUncapturedProof(): void
    {
        $reads = 0;
        $this->provider->before = function (RequestInterface $request) use (&$reads): void {
            if (
                $request->getMethod() === 'GET'
                && $request->getUri()->getPath() === '/api/v1/pa/payment_intents/PRIVATE_INTENT'
                && ++$reads === 2
            ) {
                $this->provider->intent['status'] = 'REQUIRES_CAPTURE';
            }
        };
        self::assertSame('fail', $this->runCase(true)['result']);
        self::assertSame(0, $this->cancels());
        self::assertDirectoryDoesNotExist($this->directory . '/klarna-cancellation-claims');
    }

    public function testPostCancelRefundUncertaintyDoesNotKeepTheEarlierZeroClaim(): void
    {
        $pages = 0;
        $this->provider->before = function (RequestInterface $request) use (&$pages): void {
            if ($request->getUri()->getPath() === '/api/v1/pa/refunds' && ++$pages === 2) {
                $this->provider->refunds = ['items' => []];
            }
        };
        $safe = $this->runCase(true);
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['pre_cancel_refunds_empty']);
        self::assertFalse($safe['post_cancel_refunds_empty']);
        self::assertFalse($safe['zero_refunds_verified']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(1, $this->cancels());
    }

    public function testCancellationJournalFailureAfterClaimStillPermanentlyForbidsSend(): void
    {
        $safe = $this->runCase(true, static function (string $fp, string $directory): RunLedger {
            return RunLedger::create($fp, $directory, synchronize: static function ($stream) use ($directory): bool {
                $files = glob($directory . '/sdk-validation-*/ledger.json.writing');
                if ($files === false) {
                    return false;
                }
                foreach ($files as $path) {
                    $raw = file_get_contents($path);
                    if ($raw !== false && str_contains($raw, 'klarna_reconciliation_cancel')) {
                        return false;
                    }
                }
                return is_resource($stream) && fsync($stream);
            });
        });
        self::assertSame('fail', $safe['result']);
        self::assertSame(0, $this->cancels());
        self::assertSame('cancellation_already_reserved', $this->runCase(true)['reason']);
    }

    /** @return iterable<string,array{int}> */
    public static function rejections(): iterable
    {
        yield '401' => [401];
        yield '403' => [403];
        yield '429' => [429];
        yield '500' => [500];
    }

    #[DataProvider('rejections')]
    public function testDefiniteHttpRejectionDoesNotReplayOrCancel(int $status): void
    {
        $this->provider->alter = static function (
            RequestInterface $request,
            ResponseInterface $response
        ) use (
            $status
        ): ResponseInterface {
            return $request->getMethod() === 'GET'
                ? new Response(
                    $status,
                    ['Content-Type' => 'application/json'],
                    '{"code":"PRIVATE","message":"PRIVATE_BODY"}'
                ) : $response;
        };
        $safe = $this->runCase(true);
        self::assertSame('fail', $safe['result']);
        self::assertSame($status, $safe['http_status']);
        self::assertCount(2, $this->provider->requests);
        self::assertSame(0, $this->cancels());
    }

    public function testUnsafePrivatePermissionsAndGitBoundaryFailBeforeAuth(): void
    {
        chmod($this->priorPath, 0644);
        self::assertSame('fail', $this->runCase(true)['result']);
        self::assertSame([], $this->provider->requests);
    }

    public function testGitAncestorFailsBeforeAuth(): void
    {
        mkdir($this->directory . '/.git', 0700);
        self::assertSame('fail', $this->runCase(true)['result']);
        self::assertSame([], $this->provider->requests);
    }
    /** @return iterable<string,array{bool}> */
    public static function shortWrites(): iterable
    {
        yield 'short complete' => [false];
        yield 'partial then stalled' => [true];
    }

    #[DataProvider('shortWrites')]
    public function testClaimWriteLoopCompletesOrLeavesPermanentPartialFence(bool $stall): void
    {
        $calls = 0;
        $claim = new KlarnaReconciliationClaim(
            $this->directory,
            $this->fingerprint,
            $this->marker,
            'PRIVATE_INTENT',
            write: static function ($stream, string $bytes) use (&$calls, $stall): int|false {
                if ($stall && ++$calls > 1) {
                    return 0;
                }
                return fwrite($stream, substr($bytes, 0, 3));
            }
        );
        self::assertSame(!$stall, $claim->acquire(['synthetic' => 'binding']));
        $second = new KlarnaReconciliationClaim($this->directory, $this->fingerprint, $this->marker, 'PRIVATE_INTENT');
        self::assertTrue($second->reserved());
        self::assertFalse($second->acquire([]));
    }

    /** @return iterable<string,array{list<string>,bool}> */
    public static function cliNegatives(): iterable
    {
        yield 'production' => [['--environment=production'], false];
        yield 'duplicate' => [['--environment=sandbox', '--cancel-unconfirmed', '--cancel-unconfirmed'], false];
        yield 'valued flag' => [['--environment=sandbox', '--cancel-unconfirmed=true'], false];
        yield 'root' => [['--environment=sandbox', '--sdk-root=/PRIVATE'], false];
        yield 'ID' => [['--environment=sandbox', '--intent-id=PRIVATE'], false];
        yield 'argv disabled' => [['--environment=sandbox', '--cancel-unconfirmed'], true];
    }

    /** @param list<string> $arguments */
    #[DataProvider('cliNegatives')]
    public function testTrackedCliRejectsInvalidInputsWithFixedOutputAndEmptyStderr(
        array $arguments,
        bool $disable,
    ): void {
        $tool = dirname(__DIR__, 4) . '/tools/klarna-intent-reconciliation.php';
        $command = [PHP_BINARY, ...($disable ? ['-d', 'register_argc_argv=0'] : []), $tool,
            ...$arguments, '--credentials-file=/unread-PRIVATE', '--prior-ledger=/unread-PRIVATE',
            '--state-dir=/unread-PRIVATE'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        [$output, $errors] = self::processPipes($pipes);
        $stdout = stream_get_contents($output);
        $stderr = stream_get_contents($errors);
        fclose($output);
        fclose($errors);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $stderr);
        self::assertIsString($stdout);
        self::assertStringNotContainsString('PRIVATE', $stdout);
        $safe = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($safe);
        self::assertSame('fail', $safe['result']);
        self::assertSame('configuration', $safe['phase']);
    }
    /** @return array{resource,resource} */
    private static function processPipes(mixed $pipes): array
    {
        if (!is_array($pipes) || !is_resource($pipes[1] ?? null) || !is_resource($pipes[2] ?? null)) {
            throw new RuntimeException('Synthetic CLI output pipes are unavailable.');
        }
        return [$pipes[1], $pipes[2]];
    }
    /** @return iterable<string,array{string,string}> */
    public static function duplicatePriorKeys(): iterable
    {
        yield 'context' => ['context_fingerprint', 'context_fingerprint'];
        yield 'escaped context' => ['context_fingerprint', 'context_\\u0066ingerprint'];
        yield 'resources' => ['resources', 'resources'];
        yield 'create action' => ['klarna_create', 'klarna_create'];
        yield 'escaped create action' => ['klarna_create', 'klarna_\\u0063reate'];
    }

    #[DataProvider('duplicatePriorKeys')]
    public function testDuplicateOrEscapedAliasPriorKeysFailBeforeAuthOrClaim(string $key, string $alias): void
    {
        $raw = preg_replace(
            '/"' . preg_quote($key, '/') . '"\\s*:/',
            '"' . $key . '":null,"' . $alias . '":',
            $this->original,
            1
        );
        self::assertIsString($raw);
        self::assertNotSame($this->original, $raw);
        $native = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $original = json_decode($this->original, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($native);
        self::assertIsArray($original);
        if ($key === 'klarna_create') {
            self::assertIsArray($native['actions']);
            self::assertIsArray($original['actions']);
            self::assertSame($original['actions'][$key], $native['actions'][$key]);
        } else {
            self::assertSame($original[$key], $native[$key]);
        }
        file_put_contents($this->priorPath, $raw);
        $this->original = $raw;
        $safe = $this->runCase(true);
        self::assertSame('fail', $safe['result']);
        self::assertSame('prior_validation', $safe['phase']);
        self::assertSame([], $this->provider->requests);
        self::assertDirectoryDoesNotExist($this->directory . '/klarna-cancellation-claims');
    }

    public function testBracesAndEscapedQuotesInValuesDoNotInventObjectKeys(): void
    {
        $this->editPrior(static function (array $state): array {
            self::assertIsArray($state['observations']);
            $state['observations']['synthetic_note'] = ['note' => '{"resources":[],"resources":{}} \\" \\\\'];
            return $state;
        });
        self::assertTrue($this->runCase()['own_intent_verified']);
    }
    private function numericOpaqueId(): void
    {
        $this->original = str_replace('"PRIVATE_INTENT"', '"123"', $this->original);
        file_put_contents($this->priorPath, $this->original);
        $this->provider->target = '123';
        $this->provider->intent['id'] = '123';
    }

    public function testQuotedNumericOpaqueIdentityIsSupportedWithoutPrefixAssumption(): void
    {
        $this->numericOpaqueId();
        self::assertSame('pass', $this->runCase(true)['result']);
    }

    public function testNumericJsonIdentityCannotMasqueradeAsExpectedStringAfterLosslessDecode(): void
    {
        $this->numericOpaqueId();
        $this->provider->alter = static function (
            RequestInterface $request,
            ResponseInterface $response,
        ): ResponseInterface {
            return $request->getMethod() === 'GET'
                ? $response->withBody(\GuzzleHttp\Psr7\Utils::streamFor(
                    str_replace('"id":"123"', '"id":123', (string) $response->getBody())
                )) : $response;
        };
        $safe = $this->runCase(true);
        self::assertSame('fail', $safe['result']);
        self::assertFalse($safe['own_intent_verified']);
        self::assertSame(0, $this->cancels());
    }

    /** @return iterable<string,array{bool}> */
    public static function numericPriorReferences(): iterable
    {
        yield 'resource' => [true];
        yield 'observed create ID' => [false];
    }

    #[DataProvider('numericPriorReferences')]
    public function testNumericPriorResourceAndObservationReferencesAreRejectedBeforeAuth(bool $resource): void
    {
        $this->numericOpaqueId();
        $state = json_decode($this->original, flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $state);
        if ($resource) {
            self::assertInstanceOf(\stdClass::class, $state->resources);
            $state->resources->payment_intents = [123];
        } else {
            self::assertInstanceOf(\stdClass::class, $state->observations);
            self::assertInstanceOf(\stdClass::class, $state->observations->klarna_create_response);
            $state->observations->klarna_create_response->id = 123;
        }
        $this->original = json_encode($state, JSON_THROW_ON_ERROR);
        file_put_contents($this->priorPath, $this->original);
        self::assertSame('fail', $this->runCase(true)['result']);
        self::assertSame([], $this->provider->requests);
    }
}
