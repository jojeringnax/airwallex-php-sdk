<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InstrumentApiTransport;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InstrumentBrowser;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InstrumentWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class InstrumentWorkflowTest extends TestCase
{
    private string $directory;
    /** @var list<RequestInterface> */
    private array $requests = [];
    /** @var array<array-key,mixed> */
    private array $buyer = [];
    /** @var array<array-key,mixed> */
    private array $checkout = [];
    private bool $browserRan = false;
    private int $cancels = 0;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/airwallex-instrument-fixture-' . bin2hex(random_bytes(8));
        mkdir($path, 0700);
        $real = realpath($path);
        self::assertIsString($real);
        $this->directory = $real;
        mkdir($real . '/module', 0700);
        file_put_contents($real . '/module/index.js', 'module.exports = {};');
        file_put_contents($real . '/module/package.json', '{"version":"1.62.1"}');
        file_put_contents($real . '/credentials', "AIRWALLEX_CLIENT_ID=fixture_client\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_key\nAIRWALLEX_ACCOUNT_ID=fixture_account\n");
        chmod($real . '/credentials', 0600);
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
    private function options(): array
    {
        $node = realpath(PHP_BINARY); // Browser fixture is injected; no process or network is started.
        self::assertIsString($node);
        return ['environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'state-dir' => $this->directory, 'node-bin' => $node, 'playwright-module' => $this->directory . '/module',
            'collect-one-synthetic-hosted-instrument' => true];
    }

    /**
     * @param array<string,mixed>|null $options
     * @return array<string,mixed>
     */
    private function execute(string $fault = '', ?array $options = null): array
    {
        $http = new Client(['handler' => function (RequestInterface $request) use ($fault): Response {
            $this->requests[] = $request;
            $paths = glob($this->directory . '/sdk-validation-*/ledger.json');
            self::assertIsArray($paths);
            self::assertCount(1, $paths);
            $raw = file_get_contents($paths[0]);
            self::assertIsString($raw);
            self::assertStringContainsString('instrument_sdk_possible_send_', $raw);
            $path = $request->getUri()->getPath();
            if ($path === '/api/v1/authentication/login') {
                if (in_array($fault, ['headers', 'encoding'], true)) {
                    return new Response(
                        200,
                        ['content-type' => 'application/json'] + ($fault === 'headers'
                        ? ['x-many' => array_fill(0, 65, 'synthetic')] : ['content-encoding' => 'gzip']),
                        '{"token":"fixture_account","expires_at":"2099-01-01T00:00:00Z"}'
                    );
                }
                return self::json(['token' => 'fixture_'
                    . ($request->hasHeader('x-login-as') ? 'account' : 'organization'),
                    'expires_at' => '2099-01-01T00:00:00Z']);
            }
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
            if ($path === '/api/v1/account') {
                self::assertSame('Bearer fixture_account', $request->getHeaderLine('Authorization'));
                return self::json(['id' => 'fixture_account',
                    'account_details' => ['legal_entity_id' => 'fixture_entity']]);
            }
            if (str_ends_with($path, '/billing_customers/create')) {
                $this->buyer = self::body($request);
                unset($this->buyer['request_id']);
                return self::json(['id' => 'bcus_synthetic'], 201);
            }
            if ($path === '/api/v1/billing/billing_customers/bcus_synthetic') {
                return self::json(['id' => 'bcus_synthetic'] + $this->buyer);
            }
            if (str_ends_with($path, '/billing_checkouts/create')) {
                $this->checkout = self::body($request);
                unset($this->checkout['request_id']);
                if ($fault === 'ambiguous_create') {
                    throw new RuntimeException('Private synthetic request failure');
                }
                $this->checkout += ['id' => 'checkout_synthetic', 'status' => 'ACTIVE',
                    'url' => 'https://checkout.sandbox.airwallex.com/pay?s=SYNTHETIC_CAPABILITY'];
                return self::json(['id' => 'checkout_synthetic'], 201);
            }
            if ($path === '/api/v1/billing/billing_checkouts/checkout_synthetic/cancel') {
                $this->cancels++;
                $this->checkout['status'] = 'CANCELLED';
                $this->checkout['url'] = null;
                return self::json($this->checkout);
            }
            if ($path === '/api/v1/billing/billing_checkouts/checkout_synthetic') {
                if (
                    $this->browserRan && !in_array($fault, ['clean_stop', 'uncertain', 'missing_journal',
                    'bad_profile'], true)
                ) {
                    return self::json(array_replace($this->checkout, ['status' => 'COMPLETED',
                        'payment_source_id' => 'psrc_synthetic']));
                }
                return self::json($this->checkout);
            }
            if ($path === '/api/v1/billing/payment_sources/psrc_synthetic') {
                self::assertSame('Bearer fixture_organization', $request->getHeaderLine('Authorization'));
                return self::json(['id' => 'psrc_synthetic', 'billing_customer_id' => 'bcus_synthetic',
                    'linked_payment_account_id' => 'fixture_account',
                        'external_id' => $fault === 'foreign_source' ? 'pmt_foreign' : 'pmt_synthetic']);
            }
            self::assertSame('Bearer fixture_account', $request->getHeaderLine('Authorization'));
            if ($path === '/api/v1/pa/customers/cus_synthetic') {
                return self::json(['id' => 'cus_synthetic', 'email' => $this->buyer['email']]);
            }
            if ($path === '/api/v1/pa/payment_methods/pmt_synthetic') {
                if ($fault === 'native_number') {
                    return new Response(
                        200,
                        ['content-type' => 'application/json'],
                        '{"id":123,"type":"card","customer_id":"cus_synthetic"}'
                    );
                }
                return self::json(['id' => 'pmt_synthetic', 'type' => 'card', 'customer_id' => 'cus_synthetic',
                    'card' => ['last4' => '0008']]);
            }
            if ($path === '/api/v1/pa/payment_consents/cst_synthetic') {
                if ($fault === 'foreign_agreement') {
                    return self::json(['id' => 'cst_synthetic', 'status' => 'VERIFIED',
                        'next_triggered_by' => 'customer',
                        'merchant_trigger_reason' => 'unscheduled', 'customer_id' => 'cus_synthetic',
                        'payment_method' => ['id' => 'pmt_synthetic', 'type' => 'card'],
                        'initial_payment_intent_id' => 'int_synthetic']);
                }
                return self::json(['id' => 'cst_synthetic', 'status' => 'VERIFIED', 'next_triggered_by' => 'customer',
                    'customer_id' => 'cus_synthetic', 'payment_method' => ['id' => 'pmt_synthetic', 'type' => 'card'],
                    'initial_payment_intent_id' => 'int_synthetic']);
            }
            if ($path === '/api/v1/pa/payment_intents/int_synthetic') {
                if (in_array($fault, ['malformed_amount', 'negative_amount'], true)) {
                    return self::json(['id' => 'int_synthetic', 'customer_id' => 'cus_synthetic',
                        'status' => 'SUCCEEDED',
                        'amount' => $fault === 'malformed_amount' ? 'not-a-number' : '-0.01',
                        'captured_amount' => '0.00', 'currency' => 'EUR']);
                }
                if ($fault === 'foreign_attempt') {
                    return self::json(['id' => 'int_synthetic', 'customer_id' => 'cus_synthetic',
                        'status' => 'SUCCEEDED',
                        'amount' => '0.01', 'captured_amount' => '0.00', 'currency' => 'EUR',
                        'latest_payment_attempt' => ['payment_method' => ['id' => 'pmt_foreign', 'type' => 'card']]]);
                }
                return new Response(
                    200,
                    ['content-type' => 'application/json'],
                    '{"id":"int_synthetic","customer_id":"cus_synthetic",'
                    . '"payment_consent":{"next_triggered_by":"customer"},'
                    . '"latest_payment_attempt":{"payment_method":{"id":"pmt_synthetic",'
                    . '"customer_id":"cus_synthetic","type":"card"}},'
                    . '"status":"SUCCEEDED","amount":0.01,"captured_amount":'
                    . ($fault === 'capture_underflow' ? '1e-9999' : '0.00') . ',"currency":"EUR"}'
                );
            }
            throw new RuntimeException('Fixture does not admit unknown SDK targets.');
        }]);
        return InstrumentWorkflow::run(
            $options ?? $this->options(),
            $http,
            function (array $packet, string $folder) use ($fault): array {
                $metadata = $this->buyer['metadata'] ?? null;
                self::assertIsArray($metadata);
                self::assertIsString($metadata['sdk_validation_run'] ?? null);
                self::assertSame($this->directory . '/' . $metadata['sdk_validation_run'], $folder);
                self::assertSame(['url', 'expected', 'buyer_email', 'context_sha256', 'ownership_sha256',
                    'source_hashes',
                    'journal_directory'], array_keys($packet));
                $this->browserRan = true;
                if ($fault === 'missing_journal') {
                    throw new RuntimeException('Synthetic lost process');
                }
                $provenance = ['profile' => 'bounded_hosted_instrument_v1',
                    'context_sha256' => $packet['context_sha256'],
                    'ownership_sha256' => $packet['ownership_sha256'],
                        'source_hashes' => $packet['source_hashes'], 'expected' => $packet['expected']];
                if ($fault === 'bad_profile') {
                    $provenance['context_sha256'] = str_repeat('0', 64);
                }
                $this->record($packet['journal_directory'], 0, ['phase' => 'profile', 'provenance' => $provenance]);
                $financial = $fault !== 'clean_stop';
                if ($financial) {
                    $action = '11111111-1111-4111-8111-111111111111';
                    $this->record($packet['journal_directory'], 1, ['phase' => 'possible_send', 'action' => $action,
                        'role' => 'consent_verify', 'ordinal' => 0, 'financial' => true, 'method' => 'POST',
                        'target_sha256' => str_repeat('a', 64), 'body_sha256' => str_repeat('b', 64),
                        'headers_sha256' => str_repeat('c', 64), 'scope_sha256' => str_repeat('d', 64),
                        'body_bytes' => 100, 'source_request_id' => '22222222-2222-4222-8222-222222222222']);
                    if ($fault !== 'uncertain') {
                        $this->record($packet['journal_directory'], 2, ['phase' => 'observed', 'action' => $action,
                            'role' => 'consent_verify', 'ordinal' => 0, 'response_validation' => 'matched',
                            'http_status' => 200, 'response_sha256' => str_repeat('e', 64), 'response_bytes' => 100,
                            'identities' => ['consent_id' => 'cst_synthetic', 'payments_customer_id' => 'cus_synthetic',
                                'method_id' => 'pmt_synthetic', 'initial_intent_id' => 'int_synthetic']]);
                    }
                }
                return ['result' => in_array(
                    $fault,
                    ['clean_stop', 'uncertain', 'browser_fail'],
                    true
                ) ? 'fail' : 'completed',
                    'phase' => 'complete', 'security_graph_complete' => false, 'process_reaped' => true,
                    'journal_closed_cleanly' => $fault !== 'uncertain', 'cleanup_complete' => true,
                    'input_attempted' => $financial, 'submit_attempted' => $financial,
                        'unresolved_actions' => $fault === 'uncertain' ? 1 : 0,
                    'direct_connections_forwarded' => 0,
                        'journal_records' => $financial ? ($fault === 'uncertain' ? 2 : 3) : 1,
                    'possible_financial_send' => $financial];
            },
            static fn (): int => 1794182400
        );
    }

    public function testFreshCompletionRequiresIndependentOrganizationAndAccountReads(): void
    {
        $result = $this->execute();
        self::assertSame('pass', $result['result'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertTrue($result['instrument_verified']);
        self::assertFalse($result['reconciliation_required']);
        self::assertSame(0, $this->cancels);
        self::assertCount(2, array_filter($this->requests, static fn (RequestInterface $request): bool =>
            $request->getUri()->getPath() === '/api/v1/authentication/login' && $request->hasHeader('x-login-as')));
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (
            ['fixture_key', 'SYNTHETIC_CAPABILITY', 'cst_synthetic', 'pmt_synthetic', 'int_synthetic',
            'cus_synthetic'] as $private
        ) {
            self::assertStringNotContainsString($private, $encoded);
        }
    }

    public function testCleanStopCancelsOnlyFreshStillOwnedActiveCheckoutOnce(): void
    {
        $result = $this->execute('clean_stop');
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['owned_active_cancelled']);
        self::assertSame(1, $this->cancels);
    }

    #[DataProvider('failures')]
    public function testUncertaintyOrOwnershipFailureCannotCancelOrReportSuccess(string $fault): void
    {
        $result = $this->execute($fault);
        self::assertSame('fail', $result['result']);
        self::assertSame(0, $this->cancels);
        if ($fault !== 'browser_fail') {
            self::assertTrue($result['reconciliation_required']);
        } else {
            // Reconciliation does not erase the original browser failure.
            self::assertTrue($result['instrument_verified']);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function failures(): iterable
    {
        foreach (
            ['uncertain', 'missing_journal', 'bad_profile', 'foreign_source', 'native_number', 'capture_underflow',
            'ambiguous_create', 'browser_fail', 'malformed_amount', 'negative_amount', 'foreign_attempt',
            'foreign_agreement'] as $fault
        ) {
            yield $fault => [$fault];
        }
    }

    public function testWrongProfileFailsBeforeAuthentication(): void
    {
        $options = $this->options();
        $options['environment'] = 'production';
        $result = $this->execute(options: $options);
        self::assertSame('configuration', $result['phase']);
        self::assertSame([], $this->requests);
    }

    #[DataProvider('earlyResponseFailures')]
    public function testSdkResponseBoundsStopBeforeCustomerCreation(string $fault): void
    {
        $result = $this->execute($fault);
        self::assertSame('fail', $result['result']);
        self::assertSame('account_prerequisite', $result['phase']);
        self::assertFalse($this->browserRan);
        self::assertSame(0, $this->cancels);
        self::assertCount(1, $this->requests);
    }

    /** @return iterable<string,array{string}> */
    public static function earlyResponseFailures(): iterable
    {
        yield 'represented header fields' => ['headers'];
        yield 'unsupported encoding' => ['encoding'];
    }

    public function testTokenExpiryDuringDurabilityWithholdsNativeMutationWithoutReplay(): void
    {
        $expires = (new \DateTimeImmutable('2099-01-01T00:00:00Z'))->getTimestamp();
        $clock = new class ($expires) {
            public int $unix;
            public bool $expireOnSync = false;

            public function __construct(private readonly int $expiry)
            {
                $this->unix = $expiry - 1;
            }

            public function now(): int
            {
                return $this->unix;
            }

            /** @param resource $stream */
            public function sync($stream): bool
            {
                if ($this->expireOnSync) {
                    $this->unix = $this->expiry;
                }
                return fsync($stream);
            }
        };
        $nativeCalls = 0;
        $http = new Client(['handler' => static function () use (&$nativeCalls): Response {
            $nativeCalls++;
            return new Response(
                200,
                ['content-type' => 'application/json'],
                '{"token":"fixture_org","expires_at":"2099-01-01T00:00:00Z"}'
            );
        }]);
        $configuration = Configuration::load('sandbox', $this->directory . '/credentials');
        $transport = new InstrumentApiTransport($configuration, $http, unixClock: $clock->now(...));
        $context = str_repeat('a', 64);
        $ledger = RunLedger::create($context, $this->directory, synchronize: $clock->sync(...));
        $transport->bind($ledger, $context, static function (): void {
        });
        $transport->sendRequest(new Request(
            'POST',
            'https://api.sandbox.airwallex.com/api/v1/authentication/login',
            $configuration->credentials()->headers() + ['Accept' => 'application/json', 'x-api-version' => '2026-08-21']
        ));
        $path = '/api/v1/billing/billing_customers/create';
        $body = ['name' => 'SDK Sandbox Buyer', 'request_id' => '11111111-1111-4111-8111-111111111111'];
        $transport->arm('customer_create', $path, $body);
        $clock->expireOnSync = true;
        $request = new Request(
            'POST',
            'https://api.sandbox.airwallex.com' . $path,
            ['Accept' => 'application/json', 'x-api-version' => '2026-08-21',
                'Content-Type' => 'application/json', 'Authorization' => 'Bearer fixture_org'],
            json_encode($body, JSON_THROW_ON_ERROR)
        );
        try {
            $transport->sendRequest($request);
            self::fail('Expired post-durability SDK token acquired a native send.');
        } catch (ProtocolException) {
            self::assertSame(1, $nativeCalls); // Login only; mutation remains a durable possible send.
        }
        $this->expectException(ProtocolException::class);
        $transport->arm('customer_create', $path, $body);
    }

    public function testPrivateProcessHandoffClosesInputAndReapsWithMinimalEnvironment(): void
    {
        $script = '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
$packet = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$expected = 'https://checkout.sandbox.airwallex.com/pay?s=SYNTHETIC_CAPABILITY';
if (!is_array($packet) || ($packet['url'] ?? null) !== $expected
    || getenv('AIRWALLEX_INSTRUMENT_TEST_PARENT') !== false
    || str_contains(implode(' ', $argv), 'SYNTHETIC_CAPABILITY')
    || !is_dir(getenv('HOME')) || !is_dir(getenv('TMPDIR'))) {
    fwrite(STDERR, 'closed_handoff_failed');
    exit(2);
}
usleep(50000);
echo '{"result":"fail","phase":"configuration","security_graph_complete":false}';
exit(1);
PHP;
        $binary = $this->directory . '/fixture-runtime';
        file_put_contents($binary, $script);
        chmod($binary, 0700);
        $folder = $this->directory . '/process';
        mkdir($folder, 0700);
        $original = getenv('AIRWALLEX_INSTRUMENT_TEST_PARENT');
        putenv('AIRWALLEX_INSTRUMENT_TEST_PARENT=SYNTHETIC_PARENT_VALUE');
        try {
            $bridge = new InstrumentBrowser($binary, $this->directory . '/module');
            $result = $bridge->collect(['url' =>
                'https://checkout.sandbox.airwallex.com/pay?s=SYNTHETIC_CAPABILITY'], $folder);
            self::assertSame('fail', $result['result']);
            self::assertSame('configuration', $result['phase']);
            self::assertTrue($result['process_reaped']);
        } finally {
            $original === false ? putenv('AIRWALLEX_INSTRUMENT_TEST_PARENT')
                : putenv('AIRWALLEX_INSTRUMENT_TEST_PARENT=' . $original);
        }
    }

    /** @param array<string,mixed> $record */
    private function record(mixed $directory, int $ordinal, array $record): void
    {
        self::assertIsString($directory);
        $path = $directory . '/' . sprintf('%04d.json', $ordinal);
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
        chmod($path, 0600);
    }

    /** @return array<array-key,mixed> */
    private static function body(RequestInterface $request): array
    {
        $value = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        return $value;
    }

    /** @param array<array-key,mixed> $body */
    private static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['content-type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
