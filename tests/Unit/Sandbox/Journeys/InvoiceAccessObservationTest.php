<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoiceAccessCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoiceAccessPolicy;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class InvoiceAccessObservationTest extends TestCase
{
    private string $directory;
    private string $prior;
    private string $marker;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/invoice-access-fixture-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/credentials', "AIRWALLEX_CLIENT_ID=PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=PRIVATE_KEY\nAIRWALLEX_ACCOUNT_ID=PRIVATE_ACCOUNT\n");
        chmod($this->directory . '/credentials', 0600);
        $session = new SandboxSession(
            Configuration::load('sandbox', $this->directory . '/credentials'),
            'organization'
        );
        $ledger = RunLedger::create($session->contextFingerprint(), $this->directory);
        $ledger->requestId('create_future_start', ['fixture' => true], $session->contextFingerprint());
        $ledger->recordCreated('customers', 'PRIVATE_CUSTOMER');
        $ledger->recordCreated('subscriptions', 'PRIVATE_SUBSCRIPTION');
        $this->marker = $ledger->runId();
        $this->prior = $this->directory . '/' . $this->marker . '/ledger.json';
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
            is_dir($path) && !is_link($path) ? self::remove($path) : unlink($path);
        }
        rmdir($directory);
    }

    /** @return array<string,mixed> */
    private function invoice(): array
    {
        return ['id' => 'PRIVATE_INVOICE', 'billing_customer_id' => 'PRIVATE_CUSTOMER',
            'subscription_id' => 'PRIVATE_SUBSCRIPTION', 'currency' => 'EUR', 'collection_method' => 'OUT_OF_BAND',
            'legal_entity_id' => 'PRIVATE_ENTITY', 'status' => 'FINALIZED', 'payment_status' => 'PAID',
            'hosted_url' => InvoiceAccessPolicy::ORIGIN . '/pay?s=PRIVATE_CAPABILITY',
            'pdf_url' => InvoiceAccessPolicy::ORIGIN . '/billing/invoices/pdf?s=PRIVATE_PDF'];
    }

    /** @return array{invoice:array<string,string>,other:array<string,string>} */
    private static function payer(): array
    {
        return ['invoice' => ['id' => 'PRIVATE_INVOICE', 'subscriptionId' => 'PRIVATE_SUBSCRIPTION',
            'currency' => 'EUR', 'collectionMethod' => 'OUT_OF_BAND', 'status' => 'FINALIZED',
            'paymentStatus' => 'PAID', 'billingEntityId' => 'PRIVATE_UNKNOWN_MAPPING'],
            'other' => ['bank' => 'PRIVATE_BANK', 'url' => 'https://PRIVATE.test']];
    }

    /** @param array<string,mixed> $value */
    private static function json(array $value): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($value, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed>|null $invoice
     * @param array<string,mixed>|null $first
     */
    private function sdk(?array $invoice = null, ?array $first = null): RecordingClient
    {
        return new RecordingClient(new Client(['handler' => new MockHandler([
            new Response(201, [], '{"token":"PRIVATE_API_TOKEN","expires_at":"2100-01-01T00:00:00Z"}'),
            self::json(['id' => 'PRIVATE_CUSTOMER', 'metadata' => ['sdk_validation_run' => $this->marker],
                'default_billing_currency' => 'EUR', 'default_legal_entity_id' => 'PRIVATE_ENTITY']),
            self::json(['id' => 'PRIVATE_SUBSCRIPTION', 'metadata' => ['sdk_validation_run' => $this->marker],
                'billing_customer_id' => 'PRIVATE_CUSTOMER', 'legal_entity_id' => 'PRIVATE_ENTITY',
                'currency' => 'EUR', 'collection_method' => 'OUT_OF_BAND', 'latest_invoice_id' => 'PRIVATE_INVOICE']),
            self::json($first ?? $invoice ?? $this->invoice()), self::json($invoice ?? $this->invoice()),
        ])]));
    }

    /** @return array<string,mixed> */
    private function options(bool $pdf = true, bool $payer = true): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'prior-ledger' => $this->prior, 'state-dir' => $this->directory,
            'read-one-pdf-prefix' => $pdf, 'read-one-payer-invoice' => $payer];
    }

    /** @return array<array-key,mixed> */
    private function journal(): array
    {
        $paths = glob($this->directory . '/sdk-validation-*/ledger.json') ?: [];
        $new = array_values(array_filter($paths, fn (string $path): bool => $path !== $this->prior));
        self::assertCount(1, $new);
        $raw = file_get_contents($new[0]);
        self::assertIsString($raw);
        $value = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        self::assertSame(0600, fileperms($new[0]) & 0777);
        self::assertSame(0700, fileperms(dirname($new[0])) & 0777);
        return $value;
    }

    /** @param array<string,mixed> $safe */
    private static function sanitized(array $safe): void
    {
        $raw = json_encode($safe, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE', 'invoice-access-fixture', 'https:', 'sdk-validation-'] as $value) {
            self::assertStringNotContainsString($value, $raw);
        }
        foreach (
            ['mutation_sent', 'redirect_followed', 'third_party_followed', 'renewal_verified',
            'expiry_verified', 'complete_pdf_verified', 'rendering_verified'] as $field
        ) {
            self::assertFalse($safe[$field]);
        }
    }

    public function testFreshSdkOwnerChainAndDurablePrivateIntentPrecedeEachExactIsolatedRead(): void
    {
        $before = file_get_contents($this->prior);
        $sdk = $this->sdk();
        $link = new RecordingClient(new Client(['handler' => new MockHandler([
            function (RequestInterface $request): Response {
                $state = $this->journal();
                self::assertIsArray($state['actions']);
                self::assertArrayHasKey('invoice_owner_reads', $state['actions']);
                self::assertArrayHasKey('invoice_pdf_prefix_read', $state['actions']);
                self::assertSame('bytes=0-1023', $request->getHeaderLine('Range'));
                self::assertFalse($request->hasHeader('Authorization'));
                return new Response(200, ['Content-Type' => 'application/pdf'], "%PDF-1.7\n" . str_repeat('x', 5000));
            },
            function (RequestInterface $request): Response {
                $state = $this->journal();
                self::assertIsArray($state['actions']);
                self::assertArrayHasKey('invoice_payer_read', $state['actions']);
                self::assertSame('Bearer PRIVATE_CAPABILITY', $request->getHeaderLine('Authorization'));
                return self::json(self::payer());
            },
        ])]));
        $result = InvoiceAccessCommand::run($this->options(), $sdk, $link);
        self::assertSame('pass', $result['result']);
        self::assertTrue($result['owner_chain_verified']);
        self::assertTrue($result['pdf_prefix_matched']);
        self::assertSame('admitted', $result['pdf_policy']);
        self::assertTrue($result['pdf_prefix_cut']);
        self::assertSame(1024, $result['pdf_prefix_bytes']);
        self::assertTrue($result['payer_invoice_matched']);
        self::assertTrue($result['payer_customer_indirect_anchor_matched']);
        self::assertFalse($result['payer_customer_direct_match_verified']);
        foreach (['id', 'subscription_id', 'currency', 'collection_method', 'status'] as $label) {
            foreach (['present', 'string', 'matched'] as $fact) {
                self::assertTrue($result['payer_' . $label . '_' . $fact]);
            }
        }
        self::assertTrue($result['prior_ledger_unchanged']);
        self::assertSame($before, file_get_contents($this->prior));
        self::assertCount(5, $sdk->requests);
        self::assertCount(2, $link->requests);
        self::assertSame(InvoiceAccessPolicy::PAYER, (string) $link->requests[1]->getUri());
        foreach ($link->requests as $request) {
            self::assertSame('GET', $request->getMethod());
            self::assertSame('invoice.sandbox.airwallex.com', $request->getUri()->getHost());
            self::assertSame('', (string) $request->getBody());
            foreach (
                ['Cookie', 'Referer', 'x-api-version', 'x-client-id', 'x-api-key',
                'x-login-as', 'x-on-behalf-of', 'client-secret'] as $header
            ) {
                self::assertFalse($request->hasHeader($header));
            }
        }
        $state = $this->journal();
        self::assertIsArray($state['observations']);
        self::assertSame(
            ['invoice_id' => 'PRIVATE_INVOICE', 'prior_ledger_sha256' => hash_file('sha256', $this->prior)],
            $state['observations']['invoice_access_anchor']
        );
        self::assertStringNotContainsString('PRIVATE_CAPABILITY', json_encode($state, JSON_THROW_ON_ERROR));
        self::sanitized($result);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function wrongOwners(): iterable
    {
        foreach (
            ['id', 'billing_customer_id', 'subscription_id', 'legal_entity_id', 'currency',
            'collection_method'] as $field
        ) {
            yield $field => [$field, 'PRIVATE_FOREIGN'];
        }
    }

    #[DataProvider('wrongOwners')]
    public function testWrongSdkOwnershipNeverReleasesAnyCapability(string $field, mixed $value): void
    {
        $invoice = $this->invoice();
        $invoice[$field] = $value;
        $invoice['pdf_url'] = InvoiceAccessPolicy::ORIGIN . '/opaque/PRIVATE.pdf?capability=PRIVATE';
        $links = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        $result = InvoiceAccessCommand::run($this->options(), $this->sdk($invoice), $links);
        self::assertSame('fail', $result['result']);
        self::assertSame([], $links->requests);
        self::assertFalse($result['owner_chain_verified']);
        self::sanitized($result);
    }

    public function testUnknownPdfAndSharedHostedOriginsAreWithheldWithoutAnyLinkSend(): void
    {
        $invoice = $this->invoice();
        $invoice['pdf_url'] = 'https://PRIVATE_THIRD_PARTY.test/file.pdf';
        $invoice['hosted_url'] = 'https://invoice.airwallex.com/pay?s=PRIVATE';
        $links = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        $result = InvoiceAccessCommand::run($this->options(), $this->sdk($invoice), $links);
        self::assertSame('pending', $result['result']);
        self::assertSame('withheld', $result['pdf_outcome']);
        self::assertSame('origin', $result['pdf_policy']);
        self::assertSame('withheld', $result['payer_outcome']);
        self::assertSame([], $links->requests);
        self::sanitized($result);
    }

    public function testOnlySecondFreshVerifiedInvoiceCapabilityCanBeRead(): void
    {
        $first = $this->invoice();
        $first['hosted_url'] = InvoiceAccessPolicy::ORIGIN . '/pay?s=PRIVATE_SUPERSEDED';
        $links = new RecordingClient(new Client(['handler' => new MockHandler([self::json(self::payer())])]));
        $result = InvoiceAccessCommand::run($this->options(false), $this->sdk(first: $first), $links);
        self::assertSame('pass', $result['result']);
        self::assertSame('Bearer PRIVATE_CAPABILITY', $links->requests[0]->getHeaderLine('Authorization'));
        self::sanitized($result);
    }

    public function testOnlySecondFreshOpaquePdfIsDurablyBoundAfterBothVerifiedOwnerReads(): void
    {
        $opaque = InvoiceAccessPolicy::ORIGIN . '/exports/PRIVATE%2fdocument.pdf'
            . '?signature=PRIVATE+VALUE%3D&part=one&part=two';
        $first = $this->invoice();
        $first['pdf_url'] = InvoiceAccessPolicy::ORIGIN . '/old/PRIVATE.pdf?old=PRIVATE';
        $second = $this->invoice();
        $second['pdf_url'] = $opaque;
        $sdk = $this->sdk($second, $first);
        $links = new RecordingClient(new Client(['handler' => new MockHandler([
            function (RequestInterface $request) use ($sdk, $opaque): Response {
                self::assertCount(5, $sdk->requests); // Both exact owner-checked Invoice reads already completed.
                self::assertSame($opaque, (string) $request->getUri());
                self::assertSame('bytes=0-1023', $request->getHeaderLine('Range'));
                foreach (['Authorization', 'Cookie', 'Referer', 'x-api-key', 'x-on-behalf-of'] as $header) {
                    self::assertFalse($request->hasHeader($header));
                }
                $state = $this->journal();
                self::assertIsArray($state['actions']);
                $action = $state['actions']['invoice_pdf_prefix_read'] ?? null;
                self::assertIsArray($action);
                $binding = ['method' => 'GET', 'invoice_id' => 'PRIVATE_INVOICE',
                    'prior_ledger_sha256' => hash_file('sha256', $this->prior),
                    'url_sha256' => hash('sha256', $opaque), 'range' => 'bytes=0-1023'];
                self::assertSame(hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR)), $action['body_hash']);
                return new Response(200, ['Content-Type' => 'application/pdf'], "%PDF-1.7\n");
            },
        ])]));
        $result = InvoiceAccessCommand::run($this->options(payer: false), $sdk, $links);
        self::assertSame('pass', $result['result']);
        self::assertSame('admitted', $result['pdf_policy']);
        self::assertTrue($result['owner_chain_verified']);
        self::assertTrue($result['pdf_prefix_matched']);
        self::assertCount(1, $links->requests);
        self::sanitized($result);
    }

    public function testSecondOwnershipMismatchCannotReleaseAnEarlierValidCapability(): void
    {
        $second = $this->invoice();
        $second['billing_customer_id'] = 'PRIVATE_FOREIGN';
        $second['pdf_url'] = InvoiceAccessPolicy::ORIGIN . '/opaque/PRIVATE.pdf?capability=PRIVATE';
        $links = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        $result = InvoiceAccessCommand::run($this->options(), $this->sdk($second, $this->invoice()), $links);
        self::assertSame('fail', $result['result']);
        self::assertSame([], $links->requests);
        self::sanitized($result);
    }

    /** @return iterable<string,array{string}> */
    public static function badPayerData(): iterable
    {
        foreach (['id', 'subscriptionId', 'currency', 'collectionMethod', 'status', 'paymentStatus'] as $field) {
            $data = self::payer();
            $data['invoice'][$field] = 'PRIVATE_FOREIGN';
            yield 'wrong ' . $field => [json_encode($data, JSON_THROW_ON_ERROR)];
            unset($data['invoice'][$field]);
            yield 'missing ' . $field => [json_encode($data, JSON_THROW_ON_ERROR)];
        }
        foreach (
            ['null', '[]', '{}', '{"invoice":[]}', '{"invoice":{"id":123}}',
            '{"invoice":{},"invoice":{}}',
            '{"invoice":{"id":"PRIVATE","\\u0069d":"PRIVATE"}}', "\xff", 'PRIVATE_NOT_JSON'] as $index => $raw
        ) {
            yield 'shape ' . $index => [$raw];
        }
    }

    #[DataProvider('badPayerData')]
    public function testPayerWrongMissingDuplicateAndInvalidUtf8NeverProveOwnAccess(string $body): void
    {
        $links = new RecordingClient(new Client(['handler' => new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $body),
        ])]));
        $result = InvoiceAccessCommand::run($this->options(false), $this->sdk(), $links);
        self::assertSame('pending', $result['result']);
        self::assertFalse($result['payer_invoice_matched']);
        self::assertSame(200, $result['payer_http_status']);
        self::assertCount(1, $links->requests);
        self::sanitized($result);
    }

    /** @return iterable<string,array{string,string,string,mixed}> */
    public static function payerFieldFacts(): iterable
    {
        foreach (
            ['id' => 'id', 'subscriptionId' => 'subscription_id', 'currency' => 'currency',
            'collectionMethod' => 'collection_method', 'status' => 'status'] as $field => $label
        ) {
            foreach (
                ['missing' => null, 'null' => null, 'number' => 123, 'boolean' => true,
                'array' => ['PRIVATE_VALUE'], 'wrong_string' => 'PRIVATE_WRONG'] as $kind => $value
            ) {
                yield $field . ' ' . $kind => [$field, $label, $kind, $value];
            }
        }
    }

    #[DataProvider('payerFieldFacts')]
    public function testEveryKnownPayerFieldIsIndependentlyObservedWithoutLooseningAggregateMatch(
        string $field,
        string $label,
        string $kind,
        mixed $value,
    ): void {
        $data = self::payer();
        if ($kind === 'missing') {
            unset($data['invoice'][$field]);
        } else {
            $data['invoice'][$field] = $value;
        }
        $links = new RecordingClient(new Client(['handler' => new MockHandler([self::json($data)])]));
        $result = InvoiceAccessCommand::run($this->options(false), $this->sdk(), $links);
        self::assertSame('pending', $result['result']);
        self::assertFalse($result['payer_invoice_matched']);
        self::assertFalse($result['payer_customer_indirect_anchor_matched']);
        self::assertSame($kind !== 'missing', $result['payer_' . $label . '_present']);
        self::assertSame($kind === 'wrong_string', $result['payer_' . $label . '_string']);
        self::assertFalse($result['payer_' . $label . '_matched']);
        foreach (['id', 'subscription_id', 'currency', 'collection_method', 'status'] as $other) {
            if ($other !== $label) {
                foreach (['present', 'string', 'matched'] as $fact) {
                    self::assertTrue($result['payer_' . $other . '_' . $fact]);
                }
            }
        }
        self::assertTrue($result['payer_payment_status_matched']);
        self::assertArrayNotHasKey('pdf_policy', $result);
        self::assertCount(1, $links->requests);
        self::sanitized($result);
    }

    public function testMalformedPayerJsonDoesNotManufactureFieldAbsenceObservations(): void
    {
        $links = new RecordingClient(new Client(['handler' => new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"invoice":{"id":'),
        ])]));
        $result = InvoiceAccessCommand::run($this->options(false), $this->sdk(), $links);
        foreach (['id', 'subscription_id', 'currency', 'collection_method', 'status'] as $label) {
            foreach (['present', 'string', 'matched'] as $fact) {
                self::assertArrayNotHasKey('payer_' . $label . '_' . $fact, $result);
            }
        }
        self::assertFalse($result['payer_invoice_matched']);
        self::assertCount(1, $links->requests);
        self::sanitized($result);
    }

    /** @return iterable<string,array{int,array<string,string|list<string>>,string,bool}> */
    public static function pdfResponses(): iterable
    {
        yield 'valid partial' => [206, ['Content-Type' => 'application/pdf', 'Content-Range' => 'bytes 0-8/900'],
            "%PDF-1.7\n", true];
        yield 'wrong range start' => [206, ['Content-Type' => 'application/pdf', 'Content-Range' => 'bytes 1-9/900'],
            "%PDF-1.7\n", false];
        yield 'range length mismatch' => [206,
            ['Content-Type' => 'application/pdf', 'Content-Range' => 'bytes 0-20/900'], "%PDF-1.7\n", false];
        yield 'wrong media' => [200, ['Content-Type' => 'text/html'], "%PDF-1.7\n", false];
        yield 'wrong signature' => [200, ['Content-Type' => 'application/pdf'], 'PRIVATE_HTML', false];
        yield 'duplicate media' => [200, ['Content-Type' => ['application/pdf', 'application/pdf']],
            "%PDF-1.7\n", false];
        yield 'encoded response' => [200, ['Content-Type' => 'application/pdf', 'Content-Encoding' => 'gzip'],
            "%PDF-1.7\n", false];
        yield 'redirect' => [302, ['Location' => 'https://PRIVATE.test'], 'PRIVATE', false];
        yield 'forbidden' => [403, ['Content-Type' => 'application/json'], 'PRIVATE', false];
    }

    /** @param array<string,string|list<string>> $headers */
    #[DataProvider('pdfResponses')]
    public function testPdfEvidenceRemainsPrefixOnlyWithStrictMediaRangeAndNoRedirect(
        int $status,
        array $headers,
        string $body,
        bool $matched,
    ): void {
        $links = new RecordingClient(new Client([
            'handler' => new MockHandler([new Response($status, $headers, $body)]),
        ]));
        $invoice = $this->invoice();
        $invoice['pdf_url'] = InvoiceAccessPolicy::ORIGIN . '/opaque/PRIVATE.pdf?signature=PRIVATE%3D';
        $result = InvoiceAccessCommand::run($this->options(payer: false), $this->sdk($invoice), $links);
        self::assertSame($matched ? 'pass' : 'pending', $result['result']);
        self::assertSame($matched, $result['pdf_prefix_matched']);
        self::assertCount(1, $links->requests);
        self::sanitized($result);
    }

    public function testDefaultFlagsAndWrongPriorContextFailBeforeAuthentication(): void
    {
        $sdk = $this->sdk();
        $links = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        $result = InvoiceAccessCommand::run($this->options(false, false), $sdk, $links);
        self::assertSame('blocked', $result['result']);
        self::assertSame([], $sdk->requests);
        $raw = file_get_contents($this->prior);
        self::assertIsString($raw);
        $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        $state['context_fingerprint'] = str_repeat('0', 64);
        file_put_contents($this->prior, json_encode($state, JSON_THROW_ON_ERROR));
        $result = InvoiceAccessCommand::run($this->options(), $sdk, $links);
        self::assertSame('fail', $result['result']);
        self::assertSame([], $sdk->requests);
        self::assertSame([], $links->requests);
    }

    /** @return iterable<string,array{int}> */
    public static function payerRejections(): iterable
    {
        foreach ([201, 302, 401, 403, 500] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('payerRejections')]
    public function testPayerFailureDoesNotRefreshAuthReplayFollowOrDiagnoseExpiry(int $status): void
    {
        $stream = new SimulationProbeStream('PRIVATE_ERROR', 'broken');
        $links = new RecordingClient(new Client(['handler' => new MockHandler([
            new Response($status, ['Location' => 'https://PRIVATE.test'], $stream),
        ])]));
        $result = InvoiceAccessCommand::run($this->options(false), $this->sdk(), $links);
        self::assertSame('pending', $result['result']);
        self::assertSame($status, $result['payer_http_status']);
        self::assertCount(1, $links->requests);
        self::assertSame(0, $stream->bytes);
        self::assertTrue($stream->closed);
        self::sanitized($result);
    }

    /** @return iterable<string,array{int,int}> */
    public static function failedAccessIntents(): iterable
    {
        yield 'anchor' => [6, 0];
        yield 'PDF intent' => [8, 0];
        yield 'payer intent after PDF' => [10, 1];
    }

    #[DataProvider('failedAccessIntents')]
    public function testFailedPrivatePersistenceCannotSendItsReadOrReplayPriorPartialRead(
        int $failure,
        int $sends,
    ): void {
        $calls = 0;
        $links = new RecordingClient(new Client(['handler' => new MockHandler([
            new Response(200, ['Content-Type' => 'application/pdf'], "%PDF-1.7\n"), self::json(self::payer()),
        ])]));
        $result = InvoiceAccessCommand::run(
            $this->options(),
            $this->sdk(),
            $links,
            static fn (string $fingerprint, string $directory): RunLedger => RunLedger::create(
                $fingerprint,
                $directory,
                synchronize: static function () use (&$calls, $failure): bool {
                    return ++$calls !== $failure;
                }
            )
        );
        self::assertNotSame('pass', $result['result']);
        self::assertCount($sends, $links->requests);
        self::assertFalse($result['payer_send_started']);
        self::sanitized($result);
    }

    public function testGitJournalDirectoryIsRejectedBeforeAuthentication(): void
    {
        $state = $this->directory . '/state';
        mkdir($state, 0700);
        mkdir($state . '/.git');
        $options = $this->options();
        $options['state-dir'] = $state;
        $sdk = $this->sdk();
        $result = InvoiceAccessCommand::run($options, $sdk);
        self::assertSame('fail', $result['result']);
        self::assertSame([], $sdk->requests);
        self::sanitized($result);
    }

    public function testFailedInitialDurableIntentPreventsAuthentication(): void
    {
        $calls = 0;
        $sdk = $this->sdk();
        $links = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        $result = InvoiceAccessCommand::run(
            $this->options(),
            $sdk,
            $links,
            static fn (string $fingerprint, string $directory): RunLedger => RunLedger::create(
                $fingerprint,
                $directory,
                synchronize: static function () use (&$calls): bool {
                    return ++$calls < 4;
                }
            )
        );
        self::assertSame('fail', $result['result']);
        self::assertSame([], $sdk->requests);
        self::assertSame([], $links->requests);
        self::sanitized($result);
    }

    /** @return iterable<string,array{list<string>}> */
    public static function badCli(): iterable
    {
        yield 'missing' => [[]];
        yield 'production' => [['--environment=production', '--read-one-pdf-prefix']];
        yield 'flag value' => [['--read-one-pdf-prefix=true']];
        yield 'duplicate' => [['--read-one-payer-invoice', '--read-one-payer-invoice']];
        yield 'root' => [['--sdk-root=/PRIVATE']];
        yield 'URL' => [['--url=https://PRIVATE.test']];
        yield 'no configuration' => [['--environment=sandbox', '--read-one-payer-invoice',
            '--credentials-file=/NONEXISTENT_PRIVATE', '--prior-ledger=/NONEXISTENT_PRIVATE',
            '--state-dir=/NONEXISTENT_PRIVATE']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('badCli')]
    public function testFixedCliNegativesNeverPrintInputsOrReadRealConfiguration(array $arguments): void
    {
        $this->cli($arguments);
    }

    public function testMissingStreamingSupportAndDisabledArgvFailBeforeAnyConfigurationRead(): void
    {
        $this->cli(['--environment=sandbox', '--read-one-payer-invoice',
            '--credentials-file=/NONEXISTENT_PRIVATE', '--prior-ledger=/NONEXISTENT_PRIVATE',
            '--state-dir=/NONEXISTENT_PRIVATE'], ['-d', 'allow_url_fopen=0']);
        $this->cli(['--environment=production', '--read-one-pdf-prefix'], ['-d', 'register_argc_argv=0']);
    }

    /** @param list<string> $arguments
     * @param list<string> $phpOptions
     */
    private function cli(array $arguments, array $phpOptions = []): void
    {
        $process = proc_open(
            [PHP_BINARY, ...$phpOptions, dirname(__DIR__, 4) . '/tools/invoice-access-observation.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        [$output, $errors] = self::pipes($pipes);
        $raw = stream_get_contents($output);
        self::assertSame('', stream_get_contents($errors));
        fclose($output);
        fclose($errors);
        self::assertSame(1, proc_close($process));
        self::assertIsString($raw);
        self::assertStringNotContainsString('PRIVATE', $raw);
        $result = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertFalse($result['mutation_sent']);
    }

    /** @return array{resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (!is_array($pipes) || !is_resource($pipes[1] ?? null) || !is_resource($pipes[2] ?? null)) {
            throw new \RuntimeException('Synthetic command pipes are unavailable.');
        }
        return [$pipes[1], $pipes[2]];
    }
}
