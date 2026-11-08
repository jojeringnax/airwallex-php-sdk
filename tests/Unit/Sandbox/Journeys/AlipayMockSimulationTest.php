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

final class AlipayMockSimulationTest extends TestCase
{
    private const string URL = 'https://api.sandbox.airwallex.com/pa/redirect/sg/PRIVATE_ATTEMPT'
        . '?checksum=PRIVATE_FIRST';
    private const string MOCK = 'https://api.sandbox.airwallex.com/pa/sandbox/sg/mck_PRIVATE_TARGET'
        . '?checksum=PRIVATE_VALUE';
    private string $directory;
    private PaymentsProvider $provider;
    private RunLedger $ledger;
    /** @var list<RequestInterface> */
    private array $anonymous = [];
    /** @var list<RequestInterface> */
    private array $simulation = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-mock-pay-fixture-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/credentials', "AIRWALLEX_CLIENT_ID=PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=PRIVATE_KEY\nAIRWALLEX_ACCOUNT_ID=PRIVATE_ACCOUNT\n");
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
    private function runExperiment(string $fault = '', bool $enabled = true, ?string $location = self::MOCK): array
    {
        $config = Configuration::load('sandbox', $this->directory . '/credentials');
        $session = new SandboxSession($config, 'account', $this->provider);
        $health = new class {
            public bool $healthy = true;
        };
        $this->ledger = RunLedger::create(
            $session->contextFingerprint(),
            $this->directory,
            synchronize: static fn ($stream): bool => $health->healthy && fsync($stream)
        );
        $this->provider->pendingRefunds = $fault === 'refund_pending';
        $this->provider->response = static function (
            RequestInterface $request,
            array $data
        ) use (
            $fault,
            $health,
        ): array {
            if (str_ends_with($request->getUri()->getPath(), '/confirm')) {
                $data['next_action'] = ['type' => 'redirect', 'method' => 'GET', 'url' => self::URL];
            }
            if ($fault === 'outcome_durability' && ($data['status'] ?? null) === 'SUCCEEDED') {
                $health->healthy = false;
            }
            if (str_contains($request->getUri()->getPath(), '/refunds/')) {
                if ($fault === 'refund_missing_currency') {
                    unset($data['currency']);
                } elseif ($fault === 'refund_wrong_currency') {
                    $data['currency'] = 'USD';
                }
            }
            return $data;
        };
        $this->provider->intercept = function (RequestInterface $request) use ($fault, $health): ?Response {
            if ($request->getUri()->getPath() !== '/api/v1/simulation/pa/shopper_actions/pay') {
                return null;
            }
            $this->simulation[] = $request;
            $stored = $this->state();
            self::assertIsArray($stored['actions']);
            $action = $stored['actions']['redirect_mock_simulation'] ?? null;
            self::assertIsArray($action, 'Simulation identity must be durable before the POST.');
            self::assertSame(hash('sha256', (string) $request->getBody()), $action['body_hash']);
            self::assertSame(['url' => self::MOCK], json_decode(
                (string) $request->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR
            ));
            self::assertIsArray($stored['observations']);
            $target = $stored['observations']['redirect_mock_simulation_target'] ?? null;
            self::assertIsArray($target);
            self::assertSame(hash('sha256', self::MOCK), $target['url_sha256']);
            self::assertSame($request->getMethod(), $target['method']);
            self::assertSame($request->getUri()->getPath(), $target['path']);
            self::assertSame($action['body_hash'], $target['body_sha256']);
            self::assertTrue($request->hasHeader('Authorization'));
            self::assertSame('2026-08-21', $request->getHeaderLine('x-api-version'));
            foreach (['x-api-key', 'x-client-id', 'x-on-behalf-of', 'Cookie', 'Referer', 'request_id'] as $header) {
                self::assertFalse($request->hasHeader($header));
            }
            if ($fault === 'transport') {
                throw new RuntimeException('PRIVATE_AFTER_SEND');
            }
            if (str_starts_with($fault, 'status_')) {
                return new Response(
                    (int) substr($fault, 7),
                    [],
                    '{"code":"validation_error","message":"PRIVATE_MESSAGE ' . self::MOCK . '"}'
                );
            }
            if ($fault === 'nonempty') {
                return new Response(200, [], 'PRIVATE_BODY');
            }
            if ($fault === 'oversized') {
                return new Response(400, [], str_repeat('P', 65537));
            }
            foreach ($this->provider->resources as $id => $resource) {
                if (str_starts_with($id, 'synthetic-payment_intents-')) {
                    self::assertSame($id, $target['intent_id']);
                    $this->provider->resources[$id]['status'] = $fault === 'pending' ? 'PENDING' : 'SUCCEEDED';
                    $this->provider->resources[$id]['captured_amount'] = $fault === 'wrong_capture' ? '0.01' : '1.23';
                    if ($fault === 'post_owner') {
                        $this->provider->resources[$id]['currency'] = 'USD';
                    }
                }
            }
            if ($fault === 'ack_durability') {
                $health->healthy = false;
            }
            return new Response(200);
        };
        $anonymous = new class ($this, $fault, $location, static function () use ($health): void {
            $health->healthy = false;
        }) implements ClientInterface {
            public function __construct(
                private readonly AlipayMockSimulationTest $test,
                private readonly string $fault,
                private readonly ?string $location,
                private readonly Closure $disablePersistence,
            ) {
            }
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->test->firstRead($request, $this->fault, $this->location, $this->disablePersistence);
            }
        };
        return AlipayRedirectPreflight::execute(
            $session,
            $this->ledger,
            new AlipayRedirect($anonymous),
            static fn () => null,
            2,
            simulateReturnedSandboxMock: $enabled
        );
    }

    /** @param Closure():void $disablePersistence */
    public function firstRead(
        RequestInterface $request,
        string $fault,
        ?string $location,
        Closure $disablePersistence,
    ): ResponseInterface {
        $this->anonymous[] = $request;
        self::assertSame(
            self::URL,
            (string) $request->getUri(),
            'No canonical or unknown-origin navigation is permitted.'
        );
        self::assertCount(1, $this->anonymous);
        foreach (['Authorization', 'x-api-key', 'Cookie', 'Referer'] as $header) {
            self::assertFalse($request->hasHeader($header));
        }
        foreach ($this->provider->resources as $id => $resource) {
            if (!str_starts_with($id, 'synthetic-payment_intents-')) {
                continue;
            }
            if (str_starts_with($fault, 'owner_')) {
                $this->provider->resources[$id][substr($fault, 6)] = 'PRIVATE_WRONG';
            } elseif (str_starts_with($fault, 'state_')) {
                $this->provider->resources[$id]['status'] = substr($fault, 6);
            }
        }
        if ($fault === 'reservation') {
            $disablePersistence();
        }
        return new Response($fault === 'not302' ? 200 : 302, $location === null ? [] : ['Location' => $location]);
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $raw = file_get_contents($this->directory . '/' . $this->ledger->runId() . '/ledger.json');
        self::assertIsString($raw);
        self::assertStringNotContainsString('PRIVATE_TARGET', $raw);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $result = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }
        return $result;
    }

    public function testFreshCanonicalBodyHasDurableIdentityBeforeOneAuthenticatedPostAndOwnRefund(): void
    {
        $safe = $this->runExperiment();
        self::assertSame('pass', $safe['result']);
        self::assertSame('refund_accepted', $safe['cleanup']);
        self::assertSame('SUCCEEDED', $safe['observed_state']);
        self::assertFalse($safe['reconciliation_required']);
        self::assertIsArray($safe['simulation']);
        self::assertTrue($safe['simulation']['acknowledged']);
        self::assertSame('state_observed', $safe['simulation']['result']);
        self::assertCount(1, $this->simulation);
        self::assertCount(1, $this->anonymous);
        self::assertCount(1, $this->ledger->created('payment_refunds'));
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string}> */
    public static function failedSends(): iterable
    {
        foreach (
            ['status_400', 'status_401', 'status_403', 'status_429', 'status_500', 'status_204',
            'status_302', 'nonempty', 'oversized', 'transport', 'ack_durability',
            'outcome_durability', 'post_owner'] as $fault
        ) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('failedSends')]
    public function testSentFailureNeverReplaysOrSubstitutesFinancialCleanup(string $fault): void
    {
        $safe = $this->runExperiment($fault);
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertContains($safe['cleanup'], ['pending', 'fail']);
        self::assertCount(1, $this->simulation);
        self::assertCount(1, $this->anonymous);
        self::assertCount(0, $this->ledger->created('payment_refunds'));
        self::assertCount(2, $this->provider->mutationBodies, 'Only create/confirm; no replacement cancel/refund.');
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string}> */
    public static function preflightFailures(): iterable
    {
        foreach (
            ['owner_id', 'owner_currency', 'owner_amount', 'owner_merchant_order_id', 'owner_return_url',
            'owner_metadata', 'state_PENDING', 'state_CANCELLED', 'state_REQUIRES_CAPTURE',
            'state_SUCCEEDED', 'state_PRIVATE_UNKNOWN',
            'reservation', 'not302'] as $fault
        ) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('preflightFailures')]
    public function testPreflightFailureCannotReachSimulation(string $fault): void
    {
        $safe = $this->runExperiment($fault);
        self::assertCount(0, $this->simulation);
        self::assertNotSame('pass', $safe['result']);
        self::assertCount(1, $this->anonymous);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string}> */
    public static function incompleteStates(): iterable
    {
        foreach (
            ['pending', 'wrong_capture', 'refund_pending',
            'refund_missing_currency', 'refund_wrong_currency'] as $fault
        ) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('incompleteStates')]
    public function testAcknowledgementAloneDoesNotProveCompletedPaymentAndRefund(string $fault): void
    {
        $safe = $this->runExperiment($fault);
        self::assertNotSame('pass', $safe['result']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertCount(1, $this->simulation);
        self::assertCount(1, $this->anonymous);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testCommandDispatchesTheExplicitSimulationFlagWithoutMockNavigation(): void
    {
        $this->provider->response = static function (RequestInterface $request, array $data): array {
            if (str_ends_with($request->getUri()->getPath(), '/confirm')) {
                $data['next_action'] = ['type' => 'redirect', 'method' => 'GET', 'url' => self::URL];
            }
            return $data;
        };
        $this->provider->intercept = function (RequestInterface $request): ?Response {
            if ($request->getUri()->getPath() !== '/api/v1/simulation/pa/shopper_actions/pay') {
                return null;
            }
            $this->simulation[] = $request;
            foreach ($this->provider->resources as $id => $resource) {
                if (str_starts_with($id, 'synthetic-payment_intents-')) {
                    $this->provider->resources[$id]['status'] = 'SUCCEEDED';
                    $this->provider->resources[$id]['captured_amount'] = '1.23';
                }
            }
            return new Response(200);
        };
        $anonymous = new SimulationResponseClient(new Response(302, ['Location' => self::MOCK]));
        $safe = AlipayRedirectCommand::run([
            'environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'state-dir' => $this->directory, 'create-one-alipay-hk' => true,
            'simulate-one-returned-sandbox-mock' => true,
        ], $this->provider, $anonymous);
        self::assertTrue($safe['simulate_returned_sandbox_mock']);
        self::assertSame('pass', $safe['result']);
        self::assertSame('refund_accepted', $safe['cleanup']);
        self::assertCount(1, $this->simulation);
        self::assertCount(1, $anonymous->requests);
        self::assertSame(self::URL, (string) $anonymous->requests[0]->getUri());
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testDefaultAndMutuallyExclusiveFlagsCannotTriggerSimulation(): void
    {
        $safe = $this->runExperiment(enabled: false);
        self::assertSame('blocked', $safe['result']);
        self::assertSame('cancelled', $safe['cleanup']);
        self::assertCount(0, $this->simulation);
        foreach (
            [['read-one-sandbox-mock' => true, 'simulate-one-returned-sandbox-mock' => true],
            ['simulate-one-returned-sandbox-mock' => 'true']] as $extra
        ) {
            $provider = new PaymentsProvider();
            $safe = AlipayRedirectCommand::run($extra + ['environment' => 'sandbox',
                'credentials-file' => $this->directory . '/credentials', 'state-dir' => $this->directory,
                'create-one-alipay-hk' => true], $provider, $provider);
            self::assertSame('configuration', $safe['stage']);
            self::assertCount(0, $provider->requests);
        }
    }
}
