<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayRedirect;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayRedirectCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayRedirectPreflight;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class AlipayRedirectPreflightTest extends TestCase
{
    private string $directory;
    private PaymentsProvider $provider;
    /** @var list<RequestInterface> */
    private array $customerGets = [];
    private RunLedger $ledger;
    private const string URL = 'https://api.sandbox.airwallex.com/pa/redirect/hk/synthetic_attempt'
        . '?checksum=PRIVATE_SESSION';
    private const string MOCK = 'https://api.sandbox.airwallex.com/pa/sandbox/sg/mck_PRIVATE_MOCK'
        . '?checksum=PRIVATE_MOCK_CHECKSUM';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-alipay-fixture-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_CLIENT_ID=PRIVATE_CLIENT\nAIRWALLEX_SANDBOX_API_KEY_MASTER=PRIVATE_KEY\n"
            . "AIRWALLEX_ACCOUNT_ID=PRIVATE_ACCOUNT\n"
        );
        $this->provider = new PaymentsProvider();
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    /** @return array<string,mixed> */
    private function execute(
        string $fault = '',
        string $state = 'REQUIRES_CUSTOMER_ACTION',
        int $status = 200,
        bool $readMock = false,
    ): array {
        $this->provider->intercept = static function (RequestInterface $request) use ($fault): ?Response {
            $path = $request->getUri()->getPath();
            if (str_ends_with($path, '/confirm') && in_array($fault, ['confirm_400', 'confirm_500'], true)) {
                return new Response(
                    $fault === 'confirm_400' ? 400 : 500,
                    [],
                    '{"code":"PRIVATE_CODE","message":"PRIVATE_MESSAGE","source":"PRIVATE_SOURCE"}'
                );
            }
            if (str_ends_with($path, '/cancel') && $fault === 'cancel_ambiguous') {
                throw new RuntimeException('PRIVATE_AFTER_SEND');
            }
            if (str_ends_with($path, '/cancel') && $fault === 'cancel_rejected') {
                return new Response(400, [], '{"message":"PRIVATE_MESSAGE"}');
            }
            if (str_ends_with($path, '/refunds/create') && $fault === 'refund_500') {
                return new Response(500, [], '{"message":"PRIVATE_MESSAGE"}');
            }
            return null;
        };
        $this->provider->response = function (RequestInterface $request, array $data) use ($fault): array {
            if (str_ends_with($request->getUri()->getPath(), '/confirm')) {
                $data['next_action'] = ['type' => 'redirect', 'method' => 'GET', 'url' => self::URL];
                if ($fault === 'wrong_method') {
                    $data['next_action']['method'] = 'POST';
                } elseif ($fault === 'unknown_action') {
                    $data['next_action']['type'] = 'PRIVATE_ACTION';
                } elseif ($fault === 'regional_sg') {
                    $data['next_action']['url'] = str_replace('/hk/', '/sg/', self::URL);
                } elseif ($fault === 'mock_hk') {
                    $data['next_action']['url'] = str_replace('/redirect/', '/sandbox/', self::URL);
                } elseif ($fault === 'mock_sg') {
                    $data['next_action']['url'] = str_replace('/redirect/hk/', '/sandbox/sg/', self::URL);
                } elseif ($fault === 'other_sandbox_path') {
                    $data['next_action']['url'] = 'https://api.sandbox.airwallex.com/pa/PRIVATE_ID'
                        . '?PRIVATE_KEY=PRIVATE_VALUE';
                } elseif ($fault === 'lookalike_url') {
                    $data['next_action']['url'] = str_replace('.com/', '.com.evil.test/', self::URL);
                } elseif ($fault === 'foreign_url') {
                    $data['next_action']['url'] = 'https://api.airwallex.com/PRIVATE_ID';
                }
            }
            if (str_contains($request->getUri()->getPath(), '/refunds/')) {
                if ($fault === 'refund_missing_currency') {
                    unset($data['currency']);
                } else {
                    $data['currency'] = $fault === 'refund_wrong_currency' ? 'USD' : 'HKD';
                }
            }
            return $data;
        };
        $config = Configuration::load('sandbox', $this->directory . '/credentials');
        $session = new SandboxSession($config, 'account', $this->provider);
        $this->ledger = RunLedger::create($session->contextFingerprint(), $this->directory);
        $this->provider->beforeMutation = function (RequestInterface $request, array $body): void {
            $contents = file_get_contents($this->directory . '/' . $this->ledger->runId() . '/ledger.json');
            self::assertIsString($contents);
            $state = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($state);
            self::assertIsArray($state['actions']);
            $persisted = array_filter($state['actions'], static fn (mixed $action): bool
                => is_array($action) && ($action['request_id'] ?? null) === ($body['request_id'] ?? null));
            self::assertCount(1, $persisted, 'Every mutation identity must exist durably before transmission.');
        };
        $reader = new AlipayRedirect($this->reader(function (RequestInterface $request) use (
            $fault,
            $state,
            $status,
            $readMock,
        ) {
            $this->customerGets[] = $request;
            $contents = file_get_contents($this->directory . '/' . $this->ledger->runId() . '/ledger.json');
            self::assertIsString($contents);
            self::assertStringNotContainsString('PRIVATE_SESSION', $contents);
            $durable = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($durable);
            self::assertIsArray($durable['actions']);
            self::assertArrayHasKey('redirect_navigation', $durable['actions']);
            if ($readMock && count($this->customerGets) === 1) {
                self::assertArrayNotHasKey('redirect_mock_navigation', $durable['actions']);
                return new Response(302, ['Location' => self::MOCK]);
            }
            if ($readMock) {
                self::assertSame(self::MOCK, (string) $request->getUri());
                self::assertArrayHasKey('redirect_mock_navigation', $durable['actions']);
                $intent = $durable['actions']['redirect_mock_navigation'];
                self::assertIsArray($intent);
                self::assertSame(
                    hash('sha256', json_encode(['url' => self::MOCK], JSON_THROW_ON_ERROR)),
                    $intent['body_hash']
                );
                if ($fault === 'mock_ambiguous') {
                    throw new RuntimeException('PRIVATE_MOCK_READ_ERROR');
                }
            }
            if ($fault === 'redirect_ambiguous') {
                throw new RuntimeException('PRIVATE_AFTER_SEND');
            }
            foreach ($this->provider->resources as $id => $resource) {
                if (str_starts_with($id, 'synthetic-payment_intents-')) {
                    $this->provider->resources[$id]['status'] = $state;
                    if ($state === 'SUCCEEDED') {
                        $this->provider->resources[$id]['captured_amount'] =
                            $fault === 'wrong_capture' ? '0.01' : '1.23';
                    }
                    if (str_starts_with($fault, 'owner_')) {
                        $field = substr($fault, 6);
                        $this->provider->resources[$id][$field] = 'PRIVATE_WRONG_OWNER';
                    }
                }
            }
            return new Response(
                $status,
                ['Content-Type' => 'text/html', 'Location' => 'alipayhk://PRIVATE_APP'],
                '<!doctype html><form method="POST" action="PRIVATE_ACTION">PRIVATE_NAME</form>'
            );
        }));
        return AlipayRedirectPreflight::execute($session, $this->ledger, $reader, static fn () => null, 1, $readMock);
    }

    public function testFreshAndroidIntentAndOnePrivateCustomerGetWithOwnedCancellation(): void
    {
        $safe = $this->execute();
        self::assertSame('blocked', $safe['result']);
        self::assertSame('cancelled', $safe['cleanup']);
        self::assertFalse($safe['reconciliation_required']);
        self::assertSame(0, $safe['ambiguous_actions']);
        self::assertCount(1, $this->customerGets);
        self::assertSame(self::URL, (string) $this->customerGets[0]->getUri());
        self::assertFalse($this->customerGets[0]->hasHeader('Authorization'));
        self::assertSame('', (string) $this->customerGets[0]->getBody());
        $bodies = $this->provider->mutationBodies;
        self::assertCount(3, $bodies); // Create, confirm and own cancellable cleanup only.
        self::assertSame('1.23', $bodies[0]['amount']);
        self::assertSame('HKD', $bodies[0]['currency']);
        self::assertSame(
            ['type' => 'alipayhk', 'alipayhk' => ['flow' => 'mobile_web', 'os_type' => 'android']],
            $bodies[1]['payment_method']
        );
        self::assertNotSame($bodies[0]['request_id'], $bodies[1]['request_id']);
        self::assertNotSame($bodies[1]['request_id'], $bodies[2]['request_id']);
        self::assertSame('PRIVATE_ACCOUNT', $this->provider->requests[0]->getHeaderLine('x-login-as'));
        foreach ($this->provider->requests as $request) {
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
        }
        $encoded = json_encode($safe, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE_', 'synthetic-payment_intents-', 'checksum=', 'https://', '<form'] as $value) {
            self::assertStringNotContainsString($value, $encoded);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function badActions(): iterable
    {
        yield 'method must GET' => ['wrong_method'];
        yield 'type must redirect' => ['unknown_action'];
        yield 'production target' => ['foreign_url'];
    }

    #[DataProvider('badActions')]
    public function testUnknownActionNeverCausesCustomerRead(string $fault): void
    {
        $safe = $this->execute($fault);
        self::assertFalse($safe['redirect_contract_matches']);
        self::assertFalse($safe['navigation_attempted']);
        self::assertSame('cancelled', $safe['cleanup']);
        self::assertCount(0, $this->customerGets);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,string,string,bool}> */
    public static function redirectTargets(): iterable
    {
        yield 'verified regional SG' => ['regional_sg', 'sandbox_api', 'redirect_sg', true];
        yield 'mock HK remains diagnostic' => ['mock_hk', 'sandbox_api', 'sandbox_hk', false];
        yield 'mock SG remains diagnostic' => ['mock_sg', 'sandbox_api', 'sandbox_sg', false];
        yield 'other Sandbox path remains diagnostic' => ['other_sandbox_path',
            'sandbox_api', 'other_sandbox_path', false];
        yield 'lookalike never navigates' => ['lookalike_url', 'unverified_origin', 'redirect_hk', false];
    }

    #[DataProvider('redirectTargets')]
    public function testTargetEvidenceSurvivesBeforeTheEligibilityGate(
        string $fault,
        string $origin,
        string $family,
        bool $navigate,
    ): void {
        $safe = $this->execute($fault);
        self::assertIsArray($safe['redirect_uri']);
        self::assertSame($origin, $safe['redirect_uri']['origin_category']);
        self::assertSame($family, $safe['redirect_uri']['path_family']);
        self::assertSame($navigate, $safe['redirect_contract_matches']);
        self::assertSame($navigate, $safe['navigation_attempted']);
        self::assertCount($navigate ? 1 : 0, $this->customerGets);
        self::assertSame('cancelled', $safe['cleanup']);
        self::assertSame(0, $safe['ambiguous_actions']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
        if ($navigate) {
            self::assertSame(str_replace('/hk/', '/sg/', self::URL), (string) $this->customerGets[0]->getUri());
            self::assertIsArray($safe['navigation']);
            self::assertSame('sandbox_api_sg_redirect', $safe['navigation']['origin_category']);
        }
    }

    public function testAlreadyCapturedFundsAreRefundedOnlyForExactOwnAmount(): void
    {
        $safe = $this->execute(state: 'SUCCEEDED');
        self::assertSame('pass', $safe['result']);
        self::assertSame('refund_accepted', $safe['cleanup']);
        $refunds = array_values(array_filter(
            $this->provider->mutationBodies,
            static fn (array $body): bool => isset($body['payment_intent_id'])
        ));
        self::assertCount(1, $refunds);
        self::assertSame('1.23', $refunds[0]['amount']);
        self::assertSame(['payment_intents' => 1, 'payment_refunds' => 1], $safe['created_counts']);
    }

    public function testMismatchedCapturedAmountCannotRefundOrCancel(): void
    {
        $safe = $this->execute('wrong_capture', 'SUCCEEDED');
        self::assertSame('pending', $safe['cleanup']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertCount(2, $this->provider->mutationBodies);
    }

    /** @return iterable<string,array{string}> */
    public static function ownerMismatches(): iterable
    {
        foreach (['id', 'amount', 'currency', 'merchant_order_id', 'metadata', 'return_url'] as $field) {
            yield $field => ['owner_' . $field];
        }
    }

    #[DataProvider('ownerMismatches')]
    public function testPostReadOwnerMismatchPreventsEveryFinancialCleanup(string $fault): void
    {
        $safe = $this->execute($fault);
        self::assertSame('fail', $safe['result']);
        self::assertSame('fail', $safe['cleanup']);
        self::assertCount(2, $this->provider->mutationBodies);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,int,int}> */
    public static function failures(): iterable
    {
        yield 'confirmed 4xx permits owned cleanup' => ['confirm_400', 3, 0];
        yield 'ambiguous confirmation suspends cleanup' => ['confirm_500', 2, 0];
        yield 'ambiguous customer GET suspends cleanup' => ['redirect_ambiguous', 2, 1];
        yield 'ambiguous cancellation never repeats' => ['cancel_ambiguous', 3, 1];
        yield 'rejected cancellation never repeats' => ['cancel_rejected', 3, 1];
    }

    #[DataProvider('failures')]
    public function testFailureCannotReplayOriginalActionOrExposeProviderData(
        string $fault,
        int $mutations,
        int $gets,
    ): void {
        $safe = $this->execute($fault);
        self::assertSame('fail', $safe['result']);
        self::assertCount($mutations, array_filter(
            $this->provider->requests,
            static fn (RequestInterface $request): bool => $request->getMethod() === 'POST'
                && $request->getUri()->getPath() !== '/api/v1/authentication/login'
        ));
        self::assertCount($gets, $this->customerGets);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
        if ($fault === 'redirect_ambiguous') {
            self::assertSame('pending', $safe['cleanup']);
            self::assertGreaterThan(0, $safe['ambiguous_actions']);
        }
    }

    public function testServerErrorOnCustomerGetRemainsAmbiguousEvenWithOwnedReadback(): void
    {
        $safe = $this->execute(status: 503);
        self::assertSame('pending', $safe['cleanup']);
        self::assertCount(2, $this->provider->mutationBodies);
        self::assertCount(1, $this->customerGets);
    }

    public function testUnknownProviderStateIsNotPublishedOrCancelled(): void
    {
        $safe = $this->execute(state: 'PRIVATE_UNKNOWN_STATE');
        self::assertSame('UNKNOWN', $safe['observed_state']);
        self::assertSame('pending', $safe['cleanup']);
        self::assertCount(2, $this->provider->mutationBodies);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testInvalidConfigurationCannotReachEitherTransport(): void
    {
        foreach (
            ['environment' => 'production', 'context' => 'organization', 'url' => self::URL,
            'sdk-root' => '/private', 'ledger' => '/private', 'create-one-alipay-hk' => false,
                'read-one-sandbox-mock' => 'true'] as $key => $value
        ) {
            $options = ['environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
                'state-dir' => $this->directory, 'create-one-alipay-hk' => true];
            $options[$key] = $value;
            $safe = AlipayRedirectCommand::run($options, $this->provider, $this->provider);
            self::assertSame('configuration', $safe['stage']);
            self::assertSame('fail', $safe['result']);
        }
        self::assertCount(0, $this->provider->requests);
    }

    /** @return iterable<string,array{bool}> */
    public static function mockOptions(): iterable
    {
        yield 'default' => [false];
        yield 'explicit mock opt-in' => [true];
    }

    #[DataProvider('mockOptions')]
    public function testFreshCommandUsesSeparateCustomerTransportAndSafeSourceReceipt(bool $readMock): void
    {
        $this->provider->response = static function (RequestInterface $request, array $data): array {
            if (str_ends_with($request->getUri()->getPath(), '/confirm')) {
                $data['next_action'] = ['type' => 'redirect', 'method' => 'GET', 'url' => self::URL];
            }
            return $data;
        };
        $customer = $this->reader(function (RequestInterface $request) use ($readMock): ResponseInterface {
            $this->customerGets[] = $request;
            if ($readMock && count($this->customerGets) === 1) {
                return new Response(302, ['Location' => self::MOCK]);
            }
            return new Response(200, ['Content-Type' => 'text/html'], '<p>PRIVATE_PAGE</p>');
        });
        $safe = AlipayRedirectCommand::run([
            'environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'state-dir' => $this->directory, 'create-one-alipay-hk' => true,
        ] + ($readMock ? ['read-one-sandbox-mock' => true] : []), $this->provider, $customer);
        self::assertSame('blocked', $safe['result']);
        self::assertSame('cancelled', $safe['cleanup']);
        self::assertCount($readMock ? 2 : 1, $this->customerGets);
        self::assertSame($readMock, $safe['read_one_sandbox_mock']);
        self::assertFalse($this->customerGets[0]->hasHeader('Authorization'));
        self::assertIsArray($safe['source']);
        foreach (['base_commit', 'sdk_digest', 'harness_digest'] as $field) {
            self::assertIsString($safe['source'][$field]);
            self::assertMatchesRegularExpression('/^[a-f0-9]{40,64}$/D', $safe['source'][$field]);
        }
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testOptInMockReadUsesItsOwnDurableIntentAndKeepsApplicationInteractionBlocked(): void
    {
        $safe = $this->execute(readMock: true);
        self::assertTrue($safe['read_one_sandbox_mock']);
        self::assertSame('blocked', $safe['result']);
        self::assertSame('cancelled', $safe['cleanup']);
        self::assertSame('customer_sandbox_mock_get', $safe['stage']);
        self::assertCount(2, $this->customerGets);
        self::assertSame(0, $safe['ambiguous_actions']);
        self::assertFalse($safe['reconciliation_required']);
        self::assertIsArray($safe['navigation']);
        self::assertTrue($safe['navigation']['mock_read_attempted']);
        self::assertIsArray($safe['navigation']['sandbox_mock']);
        self::assertTrue($safe['navigation']['sandbox_mock']['post_form_present']);
        self::assertFalse($safe['navigation']['sandbox_mock']['location_follow_allowed']);
        foreach ($this->customerGets as $request) {
            self::assertFalse($request->hasHeader('Authorization'));
            self::assertFalse($request->hasHeader('Cookie'));
            self::assertFalse($request->hasHeader('Referer'));
        }
        self::assertCount(3, $this->provider->mutationBodies);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,int,string,int}> */
    public static function mockFailures(): iterable
    {
        yield 'definite rejection permits owned cancel' => ['', 400, 'cancelled', 0];
        yield 'server failure suppresses financial cleanup' => ['', 503, 'pending', 1];
        yield 'transport ambiguity suppresses financial cleanup' => ['mock_ambiguous', 200, 'pending', 1];
    }

    #[DataProvider('mockFailures')]
    public function testMockFailureCannotReplayAnActionOrInventCompletion(
        string $fault,
        int $status,
        string $cleanup,
        int $ambiguous,
    ): void {
        $safe = $this->execute($fault, status: $status, readMock: true);
        self::assertSame('fail', $safe['result']);
        self::assertSame($cleanup, $safe['cleanup']);
        self::assertSame($ambiguous, $safe['ambiguous_actions']);
        self::assertCount(2, $this->customerGets);
        self::assertCount($ambiguous === 0 ? 3 : 2, $this->provider->mutationBodies);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testFailedMockReservationSendsNoSecondGetAndRequiresDurabilityReconciliation(): void
    {
        $configuration = Configuration::load('sandbox', $this->directory . '/credentials');
        $session = new SandboxSession($configuration, 'account', $this->provider);
        $health = new class {
            public bool $healthy = true;
        };
        $ledger = RunLedger::create(
            $session->contextFingerprint(),
            $this->directory,
            synchronize: static function ($stream) use ($health): bool {
                return $health->healthy && fsync($stream);
            }
        );
        $this->provider->response = static function (RequestInterface $request, array $data): array {
            if (str_ends_with($request->getUri()->getPath(), '/confirm')) {
                $data['next_action'] = ['type' => 'redirect', 'method' => 'GET', 'url' => self::URL];
            }
            return $data;
        };
        $customer = $this->reader(function (RequestInterface $request) use ($health): ResponseInterface {
            $this->customerGets[] = $request;
            $health->healthy = false;
            return new Response(302, ['Location' => self::MOCK]);
        });
        $reader = new AlipayRedirect($customer);
        $safe = AlipayRedirectPreflight::execute($session, $ledger, $reader, static fn () => null, 1, true);
        self::assertSame('fail', $safe['result']);
        self::assertSame('mock_navigation_not_durable', $safe['reason']);
        self::assertIsArray($safe['navigation']);
        self::assertTrue($safe['navigation']['mock_read_reservation_failed']);
        self::assertFalse($safe['navigation']['mock_read_attempted']);
        self::assertSame(0, $safe['ambiguous_actions']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertCount(1, $this->customerGets);
        self::assertCount(2, $this->provider->mutationBodies);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string}> */
    public static function refundCurrencies(): iterable
    {
        yield 'missing currency' => ['refund_missing_currency'];
        yield 'wrong currency' => ['refund_wrong_currency'];
    }

    #[DataProvider('refundCurrencies')]
    public function testRefundCannotPassWithoutExactHkdCurrency(string $fault): void
    {
        $safe = $this->execute($fault, 'SUCCEEDED');
        self::assertSame('fail', $safe['result']);
        self::assertSame('fail', $safe['cleanup']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(['payment_intents' => 1, 'payment_refunds' => 1], $safe['created_counts']);
        self::assertCount(3, $this->provider->mutationBodies);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testAcceptedRefundMayRemainPendingWithoutASecondMutation(): void
    {
        $this->provider->pendingRefunds = true;
        $safe = $this->execute(state: 'SUCCEEDED');
        self::assertSame('pending', $safe['cleanup']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(['payment_intents' => 1, 'payment_refunds' => 1], $safe['created_counts']);
        self::assertCount(3, $this->provider->mutationBodies);
    }

    public function testAmbiguousRefundIsNeverRepeatedOrReplacedByCancellation(): void
    {
        $safe = $this->execute('refund_500', 'SUCCEEDED');
        self::assertSame('fail', $safe['cleanup']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(1, $safe['ambiguous_actions']);
        $refunds = array_filter($this->provider->requests, static fn (RequestInterface $request): bool
            => str_ends_with($request->getUri()->getPath(), '/refunds/create'));
        $cancels = array_filter($this->provider->requests, static fn (RequestInterface $request): bool
            => str_ends_with($request->getUri()->getPath(), '/cancel'));
        self::assertCount(1, $refunds);
        self::assertCount(0, $cancels);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testCliRejectsFalseCreationRootOverrideAndMissingArgvWithoutDiagnostics(): void
    {
        foreach (
            [['--create-one-alipay-hk=false'], ['--sdk-root=/PRIVATE_ROOT'],
            ['--create-one-alipay-hk', '--create-one-alipay-hk'], ['--environment=production'],
            ['--read-one-sandbox-mock=false'], ['--read-one-sandbox-mock', '--read-one-sandbox-mock']] as $extra
        ) {
            $this->assertCliRejected($extra);
        }
        $this->assertCliRejected(['--sdk-root=/PRIVATE_ROOT'], true);
    }

    /** @param list<string> $extra */
    private function assertCliRejected(array $extra, bool $withoutArgv = false): void
    {
        $process = proc_open([
            PHP_BINARY, '-d', 'register_argc_argv=' . ($withoutArgv ? '0' : '1'),
            dirname(__DIR__, 4) . '/tools/alipay-redirect-preflight.php',
            '--environment=sandbox', '--credentials-file=/absent-synthetic-credentials',
            '--state-dir=' . $this->directory, ...$extra,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        [$output, $errors] = self::outputPipes($pipes);
        $contents = stream_get_contents($output);
        $diagnostics = stream_get_contents($errors);
        fclose($output);
        fclose($errors);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $diagnostics);
        self::assertIsString($contents);
        $safe = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($safe);
        self::assertSame('configuration', $safe['stage']);
        self::assertSame('fail', $safe['result']);
        self::assertArrayNotHasKey('run_id', $safe);
        self::assertArrayNotHasKey('source', $safe);
        self::assertStringNotContainsString('PRIVATE_', $contents);
        self::assertSame(hash_file('sha256', dirname(__DIR__, 4)
            . '/tools/alipay-redirect-preflight.php'), $safe['redirect_tool_sha256']);
    }

    /** @return array{resource,resource} */
    private static function outputPipes(mixed $pipes): array
    {
        if (!is_array($pipes) || !is_resource($pipes[1] ?? null) || !is_resource($pipes[2] ?? null)) {
            throw new RuntimeException('Fixture output pipes are unavailable.');
        }
        return [$pipes[1], $pipes[2]];
    }

    /** @param Closure(RequestInterface):ResponseInterface $send */
    private function reader(Closure $send): ClientInterface
    {
        return new class ($send) implements ClientInterface {
            /** @param Closure(RequestInterface):ResponseInterface $send */
            public function __construct(private readonly Closure $send)
            {
            }
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->send)($request);
            }
        };
    }
}
