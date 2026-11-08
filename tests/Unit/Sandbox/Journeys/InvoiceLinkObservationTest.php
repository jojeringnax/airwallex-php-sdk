<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoiceLinkCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoiceLinkObservation;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class InvoiceLinkObservationTest extends TestCase
{
    private string $directory;
    private string $prior;
    private string $marker;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/invoice-link-fixture-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/credentials', "AIRWALLEX_CLIENT_ID=fixture_client\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_key\nAIRWALLEX_ACCOUNT_ID=fixture_account\n");
        [$session] = $this->session([]);
        $ledger = RunLedger::create($session->contextFingerprint(), $this->directory);
        $ledger->requestId('create_future_start', ['synthetic' => true], $session->contextFingerprint());
        $ledger->recordCreated('customers', 'fixture-customer');
        $ledger->recordCreated('subscriptions', 'fixture-subscription');
        $this->marker = $ledger->runId();
        $this->prior = $this->directory . '/' . $ledger->runId() . '/ledger.json';
    }

    protected function tearDown(): void
    {
        self::remove($this->directory);
    }

    private static function remove(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                self::remove($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    /**
     * @param list<Response|Throwable> $responses
     * @return array{SandboxSession,RecordingClient}
     */
    private function session(array $responses, string $context = 'organization'): array
    {
        $http = new RecordingClient(new HttpClient(['handler' => new MockHandler($responses)]));
        return [new SandboxSession(
            Configuration::load('sandbox', $this->directory . '/credentials'),
            $context,
            $http
        ), $http];
    }

    /** @return array<string,mixed> */
    private function customer(): array
    {
        return ['id' => 'fixture-customer', 'metadata' => ['sdk_validation_run' => $this->marker],
            'default_billing_currency' => 'EUR', 'default_legal_entity_id' => 'fixture-entity'];
    }

    /** @return array<string,mixed> */
    private function subscription(): array
    {
        return ['id' => 'fixture-subscription', 'metadata' => ['sdk_validation_run' => $this->marker],
            'billing_customer_id' => 'fixture-customer', 'currency' => 'EUR', 'collection_method' => 'OUT_OF_BAND',
            'legal_entity_id' => 'fixture-entity', 'status' => 'CANCELLED', 'latest_invoice_id' => 'fixture-invoice'];
    }

    /** @return array<string,mixed> */
    private function invoice(): array
    {
        return ['id' => 'fixture-invoice', 'billing_customer_id' => 'fixture-customer',
            'subscription_id' => 'fixture-subscription', 'currency' => 'EUR', 'collection_method' => 'OUT_OF_BAND',
            'legal_entity_id' => 'fixture-entity', 'due_at' => gmdate('Y-m-d\TH:i:s\Z', 1800000000),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', 1799999999),
            'finalized_at' => gmdate('Y-m-d\TH:i:s\Z', 1799999998),
            'pdf_url' => 'https://PRIVATE.example.test/PRIVATE.pdf?token=PRIVATE_PDF_CAPABILITY',
            'hosted_url' => InvoiceLinkClaimsTest::link('{"exp":1803024000,"private":"PRIVATE_HOSTED_CAPABILITY"}')];
    }

    /** @param array<string,mixed> $data */
    private static function response(array $data): Response
    {
        return new Response(200, [], json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return list<Response> */
    private function responses(): array
    {
        return [new Response(201, [], '{"token":"fixture_token","expires_at":"2100-01-01T00:00:00Z"}'),
            self::response($this->customer()), self::response($this->subscription()),
            self::response($this->invoice()), self::response($this->invoice())];
    }

    /**
     * @param list<Response> $responses
     * @return list<Response>
     */
    private static function replaceResponse(array $responses, int $index, Response $replacement): array
    {
        $result = [];
        foreach ($responses as $position => $response) {
            $result[] = $position === $index ? $replacement : $response;
        }
        return $result;
    }

    private static function clock(): Closure
    {
        $values = [1800000000, 1800000001, 1800000003, 1800000004];
        $index = 0;
        return static function () use ($values, &$index): int {
            foreach ($values as $position => $value) {
                if ($position === $index) {
                    ++$index;
                    return $value;
                }
            }
            throw new RuntimeException('Unexpected observation-clock access.');
        };
    }

    /** @return array<string,string> */
    private function options(): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'prior-ledger' => $this->prior];
    }

    public function testCommandMakesOnlyFixedOwnedGetsAndPreservesOriginalLedgerBytesAndPermissions(): void
    {
        $before = file_get_contents($this->prior);
        self::assertIsString($before);
        $mode = fileperms($this->prior);
        [, $http] = $this->session($this->responses());
        $waits = 0;
        $result = InvoiceLinkCommand::run(
            $this->options(),
            $http,
            self::clock(),
            static function () use (&$waits): void {
                ++$waits;
            }
        );
        self::assertSame('pass', $result['result']);
        self::assertSame('organization', $result['context']);
        self::assertSame(1, $waits);
        self::assertSame($before, file_get_contents($this->prior));
        self::assertSame($mode, fileperms($this->prior));
        self::assertSame(hash('sha256', $before), $result['prior_ledger_sha256']);
        self::assertFalse($result['mutation_sent']);
        self::assertFalse($result['link_followed']);
        self::assertFalse($result['link_usability_verified']);
        self::assertFalse($result['renewal_verified']);
        self::assertFalse($result['signature_verified']);
        self::assertTrue($result['clock_progressed_between_reads']);
        self::assertIsArray($result['hosted']);
        self::assertTrue($result['hosted']['url_same']);
        self::assertTrue($result['hosted']['first_due_at_plus_35_utc_days_matches_exp']);
        self::assertCount(5, $http->requests);
        self::assertSame(
            ['/api/v1/authentication/login', '/api/v1/billing/billing_customers/fixture-customer',
            '/api/v1/billing/subscriptions/fixture-subscription', '/api/v1/billing/invoices/fixture-invoice',
            '/api/v1/billing/invoices/fixture-invoice'],
            array_map(static fn ($request): string => $request->getUri()->getPath(), $http->requests)
        );
        foreach ($http->requests as $index => $request) {
            self::assertSame('https', $request->getUri()->getScheme());
            self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
            self::assertFalse($request->hasHeader('x-login-as'));
            if ($index > 0) {
                self::assertSame('GET', $request->getMethod());
                self::assertSame('', (string) $request->getBody());
                self::assertSame('2026-08-21', $request->getHeaderLine('x-api-version'));
            }
        }
        self::assertIsArray($result['frontend']);
        self::assertSame('2.272.0', $result['frontend']['build']);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (
            ['PRIVATE', 'fixture', $this->marker, 'https:', 'private_header', 'private_signature', '1803024000'] as $p
        ) {
            self::assertStringNotContainsString($p, $json);
        }
    }

    /** @return iterable<string,array{Closure(array<array-key,mixed>):array<array-key,mixed>}> */
    public static function invalidLedgers(): iterable
    {
        yield 'version' => [static fn (array $s): array => array_replace($s, ['api_version' => '2025-01-01'])];
        yield 'owner context' => [static fn (array $s): array => array_replace(
            $s,
            ['context_fingerprint' => str_repeat('0', 64)],
        )];
        yield 'schedule collector fingerprint' => [static function (array $s): array {
            $fingerprint = $s['context_fingerprint'] ?? null;
            if (!is_string($fingerprint)) {
                throw new RuntimeException('Invalid synthetic fingerprint.');
            }
            return array_replace($s, ['context_fingerprint' => hash('sha256', $fingerprint . "\0fixture_account")]);
        }];
        yield 'missing source' => [static fn (array $s): array => array_diff_key($s, ['source' => true])];
        yield 'bad source digest' => [static fn (array $s): array => array_replace(
            $s,
            ['source' => ['base_commit' => str_repeat('a', 40), 'sdk_digest' => 'PRIVATE',
                'harness_digest' => str_repeat('a', 64)]]
        )];
        yield 'no Customer' => [static fn (array $s): array => array_replace(
            $s,
            ['resources' => ['customers' => [], 'subscriptions' => ['fixture-subscription']]]
        )];
        yield 'two Subscriptions' => [static fn (array $s): array => array_replace(
            $s,
            ['resources' => ['customers' => ['fixture-customer'], 'subscriptions' => ['fixture-subscription', 'other']]]
        )];
        yield 'no Subscription' => [static fn (array $s): array => array_replace(
            $s,
            ['resources' => ['customers' => ['fixture-customer'], 'subscriptions' => []]]
        )];
        yield 'blank ID' => [static fn (array $s): array => array_replace(
            $s,
            ['resources' => ['customers' => [' '], 'subscriptions' => ['fixture-subscription']]]
        )];
        yield 'unknown create scope' => [static fn (array $s): array => array_replace(
            $s,
            ['actions' => ['create_subscription' => ['request_id' => 'PRIVATE']]]
        )];
        yield 'invalid run marker' => [static fn (array $s): array => array_replace($s, ['run_id' => 'PRIVATE'])];
        yield 'invalid request evidence' => [static fn (array $s): array => array_replace(
            $s,
            ['actions' => ['create_future_start' => ['request_id' => 'PRIVATE', 'body_hash' => str_repeat('a', 64)]]]
        )];
    }

    /** @param Closure(array<array-key,mixed>):array<array-key,mixed> $change */
    #[DataProvider('invalidLedgers')]
    public function testInvalidPriorEvidenceFailsBeforeAuthenticationWithoutEchoingItsValues(Closure $change): void
    {
        $state = json_decode((string) file_get_contents($this->prior), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        $changed = $change($state);
        file_put_contents($this->prior, json_encode($changed, JSON_THROW_ON_ERROR));
        [, $http] = $this->session([]);
        $result = InvoiceLinkCommand::run($this->options(), $http);
        self::assertSame('fail', $result['result']);
        self::assertSame([], $http->requests);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string}> */
    public static function fileFailures(): iterable
    {
        foreach (['public_file', 'public_directory', 'malformed', 'oversize', 'symlink', 'git_ancestor'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('fileFailures')]
    public function testPrivateFileBoundariesAreCheckedBeforeAnyRequest(string $kind): void
    {
        if ($kind === 'public_file') {
            chmod($this->prior, 0644);
        } elseif ($kind === 'public_directory') {
            chmod(dirname($this->prior), 0755);
        } elseif ($kind === 'malformed') {
            file_put_contents($this->prior, '{PRIVATE');
        } elseif ($kind === 'oversize') {
            file_put_contents($this->prior, str_repeat('x', 1048577));
        } elseif ($kind === 'symlink') {
            symlink($this->prior, $this->directory . '/linked-ledger');
            $this->prior = $this->directory . '/linked-ledger';
        } else {
            mkdir($this->directory . '/.git');
        }
        [, $http] = $this->session([]);
        $result = InvoiceLinkCommand::run($this->options(), $http);
        self::assertSame('fail', $result['result']);
        self::assertSame([], $http->requests);
    }

    /** @return iterable<string,array{string,string,mixed,int}> */
    public static function ownershipMismatches(): iterable
    {
        yield 'Customer id' => ['customer', 'id', 'PRIVATE_FOREIGN', 2];
        yield 'Customer marker' => ['customer', 'metadata', ['sdk_validation_run' => 'PRIVATE_FOREIGN'], 2];
        yield 'Customer entity' => ['customer', 'default_legal_entity_id', 'PRIVATE_FOREIGN', 3];
        yield 'Sub id' => ['subscription', 'id', 'PRIVATE_FOREIGN', 3];
        yield 'Sub Customer' => ['subscription', 'billing_customer_id', 'PRIVATE_FOREIGN', 3];
        yield 'Sub marker' => ['subscription', 'metadata', ['sdk_validation_run' => 'PRIVATE_FOREIGN'], 3];
        yield 'Sub currency' => ['subscription', 'currency', 'USD', 3];
        yield 'Sub collection' => ['subscription', 'collection_method', 'AUTO_CHARGE', 3];
        yield 'Invoice id' => ['invoice', 'id', 'PRIVATE_FOREIGN', 4];
        yield 'Invoice Sub' => ['invoice', 'subscription_id', 'PRIVATE_FOREIGN', 4];
        yield 'Invoice Customer' => ['invoice', 'billing_customer_id', 'PRIVATE_FOREIGN', 4];
        yield 'Invoice entity' => ['invoice', 'legal_entity_id', 'PRIVATE_FOREIGN', 4];
        yield 'Invoice currency' => ['invoice', 'currency', 'USD', 4];
        yield 'Invoice collection' => ['invoice', 'collection_method', 'AUTO_CHARGE', 4];
        yield 'second Invoice' => ['second', 'subscription_id', 'PRIVATE_FOREIGN', 5];
    }

    #[DataProvider('ownershipMismatches')]
    public function testOwnershipMismatchStopsDependentReadsAndDoesNotInspectOrFollowForeignLinks(
        string $kind,
        string $field,
        mixed $value,
        int $requests,
    ): void {
        $responses = $this->responses();
        $index = match ($kind) {
            'customer' => 1, 'subscription' => 2, 'invoice' => 3, default => 4
        };
        $body = match ($kind) {
            'customer' => $this->customer(), 'subscription' => $this->subscription(),
            default => $this->invoice()
        };
        $responses = self::replaceResponse(
            $responses,
            $index,
            self::response(array_replace($body, [$field => $value])),
        );
        [, $http] = $this->session($responses);
        $result = InvoiceLinkCommand::run($this->options(), $http, self::clock(), static fn () => null);
        self::assertSame('fail', $result['result']);
        self::assertCount($requests, $http->requests);
        self::assertArrayNotHasKey('hosted', $result);
        self::assertArrayNotHasKey('pdf', $result);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testMissingLatestInvoiceIsPendingWithoutListDiscoveryOrExtraRequests(): void
    {
        $responses = $this->responses();
        $responses = self::replaceResponse(
            $responses,
            2,
            self::response(array_diff_key($this->subscription(), ['latest_invoice_id' => true]))
        );
        [, $http] = $this->session($responses);
        $result = InvoiceLinkCommand::run($this->options(), $http);
        self::assertSame('pending', $result['result']);
        self::assertSame('latest_invoice_unavailable', $result['phase']);
        self::assertCount(3, $http->requests);
        self::assertArrayNotHasKey('hosted', $result);
    }

    public function testApiRejectionEmitsOnlyStatusAndSafeAvailableEvidenceWithoutReplay(): void
    {
        $responses = $this->responses();
        $responses = self::replaceResponse(
            $responses,
            4,
            new Response(403, [], '{"code":"PRIVATE_OPAQUE_CODE","source":"PRIVATE_ID",'
            . '"message":"PRIVATE_MESSAGE https://private.test/path"}')
        );
        [, $http] = $this->session($responses);
        $result = InvoiceLinkCommand::run($this->options(), $http, self::clock(), static fn () => null);
        self::assertSame('fail', $result['result']);
        self::assertSame(403, $result['http_status']);
        self::assertCount(5, $http->requests);
        self::assertArrayHasKey('prior_ledger_sha256', $result);
        self::assertArrayHasKey('source', $result);
        self::assertArrayNotHasKey('provider_code', $result);
        self::assertArrayNotHasKey('provider_source', $result);
        self::assertArrayNotHasKey('hosted', $result);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testObserverCannotBeReusedOrCrossContextAfterLoadingPrivateReferences(): void
    {
        [$session, $http] = $this->session($this->responses());
        $observer = InvoiceLinkObservation::load($this->prior, $session);
        self::assertSame('pass', $observer->inspect($session, self::clock(), static fn () => null)['result']);
        try {
            $observer->inspect($session);
            self::fail('Observer was reused.');
        } catch (RuntimeException) {
            self::assertCount(5, $http->requests);
        }
        [$account, $accountHttp] = $this->session([], 'account');
        $this->expectException(RuntimeException::class);
        try {
            InvoiceLinkObservation::load($this->prior, $account);
        } finally {
            self::assertSame([], $accountHttp->requests);
            self::assertStringNotContainsString('fixture', print_r($observer, true));
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidOptions(): iterable
    {
        $base = ['environment' => 'sandbox', 'credentials-file' => '/unread-private-fixture',
            'prior-ledger' => '/unread-private-ledger'];
        yield 'missing' => [[]];
        yield 'production' => [array_replace($base, ['environment' => 'production'])];
        yield 'relative credentials' => [array_replace($base, ['credentials-file' => 'PRIVATE'])];
        yield 'URL credentials' => [array_replace($base, ['credentials-file' => 'https://private.test'])];
        yield 'missing ledger' => [array_diff_key($base, ['prior-ledger' => true])];
        yield 'wrong types' => [array_replace($base, ['prior-ledger' => []])];
        foreach (['context', 'invoice-id', 'customer-id', 'subscription-id', 'url', 'sdk-root', 'base-url'] as $key) {
            yield $key . ' override' => [$base + [$key => 'PRIVATE']];
        }
    }

    /** @param array<string,mixed> $options */
    #[DataProvider('invalidOptions')]
    public function testConfigurationAndOverrideFailuresNeverAuthenticateOrEchoValues(array $options): void
    {
        [, $http] = $this->session([]);
        $result = InvoiceLinkCommand::run($options, $http);
        self::assertSame('fail', $result['result']);
        self::assertSame([], $http->requests);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }
}
