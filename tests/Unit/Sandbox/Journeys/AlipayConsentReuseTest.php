<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReuseCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class AlipayConsentReuseTest extends TestCase
{
    private string $directory;
    private PaymentsProvider $provider;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/consent-reuse-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $resolved = realpath($directory);
        self::assertIsString($resolved);
        $this->directory = $resolved;
        file_put_contents($resolved . '/credentials', "AIRWALLEX_CLIENT_ID=PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=PRIVATE_KEY\nAIRWALLEX_ACCOUNT_ID=PRIVATE_ACCOUNT\n");
        chmod($resolved . '/credentials', 0600);
        $this->provider = new PaymentsProvider();
        $this->provider->response = self::normal(...);
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

    /** @param array<array-key,mixed> $resource
     * @return array<string,mixed>
     */
    public static function normal(RequestInterface $request, array $resource): array
    {
        $checked = [];
        foreach ($resource as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException('A fixture resource requires object keys.');
            }
            $checked[$key] = $value;
        }
        $resource = $checked;
        if (str_contains($request->getUri()->getPath(), '/payment_consents/')) {
            $resource['payment_method'] = ['type' => 'alipayhk']; // Deliberately no Method ID.
        }
        if (str_contains($request->getUri()->getPath(), '/payment_intents/')) {
            $resource['captured_amount'] ??= '0';
        }
        return $resource;
    }

    /** @param (Closure(string,string):RunLedger)|null $ledgers
     * @return array<string,mixed>
     */
    private function execute(?Closure $ledgers = null): array
    {
        return AlipayConsentReuseCommand::run(
            ['environment' => 'sandbox',
            'credentials-file' => $this->directory . '/credentials', 'state-dir' => $this->directory,
            'verify-and-charge-one-alipay-hk-consent' => true],
            $this->provider,
            $ledgers,
            static function (int $seconds): void {
                self::assertContains($seconds, [1, 2, 4, 8, 7]);
            },
            attempts: 2
        );
    }

    /** @return list<RequestInterface> */
    private function requests(string $suffix): array
    {
        return array_values(array_filter($this->provider->requests, static fn (RequestInterface $request): bool =>
            str_ends_with($request->getUri()->getPath(), $suffix)));
    }

    /** @return array<string,mixed> */
    private function journal(): array
    {
        $paths = glob($this->directory . '/sdk-validation-*/ledger.json') ?: [];
        self::assertCount(1, $paths);
        $raw = file_get_contents($paths[0]);
        self::assertIsString($raw);
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $result = [];
        foreach ($data as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }
        return $result;
    }

    public function testOriginalUrlAndroidSameRunReferenceChargeRefundWithoutMethodId(): void
    {
        $this->provider->beforeMutation = function (RequestInterface $request): void {
            $state = $this->journal();
            self::assertIsArray($state['observations']);
            $hash = hash('sha256', (string) $request->getBody());
            $matches = array_filter($state['observations'], static fn (mixed $entry): bool =>
                is_array($entry) && ($entry['body_sha256'] ?? null) === $hash);
            self::assertCount(2, $matches); // Prepared and possible-send are durable before the fixture sees bytes.
        };
        $result = $this->execute();
        self::assertSame('pass', $result['result']);
        self::assertTrue($result['simulation_acknowledged']);
        self::assertTrue($result['consent_verified']);
        self::assertTrue($result['captured_exact_amount']);
        self::assertTrue($result['refund_verified']);
        self::assertSame('ACCEPTED', $result['refund_state']);
        self::assertFalse($result['method_id_present']);
        self::assertFalse($result['source_mapping_performed']);
        self::assertFalse($result['reconciliation_required']);
        self::assertSame(['intent' => 'pass', 'consent' => 'pass', 'customer' => 'retained'], $result['cleanup']);
        $verify = $this->requests('/verify');
        self::assertCount(2, $verify);
        $wire = json_decode((string) $verify[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($wire);
        self::assertSame(
            ['type' => 'alipayhk', 'alipayhk' => ['flow' => 'mobile_web', 'os_type' => 'android']],
            $wire['payment_method']
        );
        $simulation = json_decode((string) $verify[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($simulation);
        self::assertSame(
            ['url' => 'https://api.sandbox.airwallex.com/pa/sandbox/verify/synthetic-payment_consents-2'],
            $simulation
        );
        $confirm = json_decode((string) $this->requests('/confirm')[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($confirm);
        self::assertSame('merchant', $confirm['triggered_by']);
        self::assertSame('synthetic-payment_consents-2', $confirm['payment_consent_id']);
        self::assertCount(1, $this->requests('/refunds/create'));
        self::assertCount(1, $this->requests('/disable'));
        self::assertCount(0, $this->requests('/cancel'));
        $identities = [];
        foreach ($this->provider->requests as $request) {
            self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
            self::assertStringNotContainsString('/pay/', $request->getUri()->getPath());
            self::assertStringNotContainsString('/payment_methods/', $request->getUri()->getPath());
            self::assertFalse($request->hasHeader('Cookie'));
            if (
                $request->getMethod() === 'POST' && !str_contains($request->getUri()->getPath(), '/authentication/')
                && !str_contains($request->getUri()->getPath(), '/simulation/')
            ) {
                $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                self::assertIsString($body['request_id']);
                self::assertNotContains($body['request_id'], $identities);
                $identities[] = $body['request_id'];
            }
        }
        $raw = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE', 'synthetic-', 'https:', 'checksum', $this->directory] as $private) {
            self::assertStringNotContainsString($private, $raw);
        }
    }

    /** @return iterable<string,array{mixed,bool,bool}> */
    public static function methodIds(): iterable
    {
        yield 'null' => [null, false, false];
        yield 'number' => [123, false, false];
        yield 'object' => [new \stdClass(), false, false];
        yield 'array' => [[], false, false];
        yield 'string' => ['PRIVATE_METHOD', true, true];
        yield 'blank string' => ['', true, false];
        yield 'unsafe string' => ['../PRIVATE', true, false];
    }

    #[DataProvider('methodIds')]
    public function testOptionalMethodIdIsNativeObservationNotAChargeGate(mixed $id, bool $string, bool $usable): void
    {
        $this->provider->response = static function (RequestInterface $request, array $resource) use ($id): array {
            $resource = self::normal($request, $resource);
            if (str_contains($request->getUri()->getPath(), '/payment_consents/')) {
                $resource['payment_method'] = ['type' => 'alipayhk', 'id' => $id];
            }
            return $resource;
        };
        $result = $this->execute();
        self::assertSame('pass', $result['result']);
        self::assertTrue($result['method_id_present']);
        self::assertSame($string, $result['method_id_native_string']);
        self::assertSame($usable, $result['method_id_usable']);
        self::assertFalse($result['source_mapping_performed']);
    }

    public function testGuideRpmRedirectDoesNotRequireRca(): void
    {
        $this->provider->response = static function (RequestInterface $request, array $resource): array {
            $resource = self::normal($request, $resource);
            if (
                str_contains($request->getUri()->getPath(), '/payment_consents/')
                && ($resource['status'] ?? null) === 'REQUIRES_CUSTOMER_ACTION'
            ) {
                $resource['status'] = 'REQUIRES_PAYMENT_METHOD';
            }
            return $resource;
        };
        self::assertSame('pass', $this->execute()['result']);
    }

    public function testSynchronousVerificationDoesNotInventSimulation(): void
    {
        $this->provider->response = function (RequestInterface $request, array $resource): array {
            $resource = self::normal($request, $resource);
            if (str_ends_with($request->getUri()->getPath(), '/verify')) {
                $id = $resource['id'];
                self::assertIsString($id);
                $this->provider->resources[$id]['status'] = 'VERIFIED';
                unset($this->provider->resources[$id]['next_action']);
                $resource['status'] = 'VERIFIED';
                unset($resource['next_action']);
            }
            return $resource;
        };
        $result = $this->execute();
        self::assertSame('pass', $result['result']);
        self::assertFalse($result['simulation_acknowledged']);
        self::assertCount(1, $this->requests('/verify'));
    }

    public function testAlreadyVerifiedOwnConsentNeedsNeitherVerifyNorSimulation(): void
    {
        $this->provider->response = function (RequestInterface $request, array $resource): array {
            $resource = self::normal($request, $resource);
            if (
                str_contains($request->getUri()->getPath(), '/payment_consents/')
                && ($resource['status'] ?? null) === 'REQUIRES_PAYMENT_METHOD'
            ) {
                $id = $resource['id'];
                self::assertIsString($id);
                $this->provider->resources[$id]['status'] = $resource['status'] = 'VERIFIED';
            }
            return $resource;
        };
        $result = $this->execute();
        self::assertSame('pass', $result['result']);
        self::assertFalse($result['simulation_acknowledged']);
        self::assertCount(0, $this->requests('/verify'));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function ownershipFailures(): iterable
    {
        yield 'foreign customer' => ['customer_id', 'PRIVATE_FOREIGN'];
        yield 'numeric customer' => ['customer_id', 123];
        yield 'wrong initiator' => ['next_triggered_by', 'customer'];
        yield 'wrong reason' => ['merchant_trigger_reason', 'scheduled'];
        yield 'wrong marker' => ['metadata', ['sdk_validation_run' => 'PRIVATE_FOREIGN']];
        yield 'missing marker' => ['metadata', []];
        yield 'wrong type' => ['payment_method', ['type' => 'card']];
        yield 'missing type' => ['payment_method', []];
        yield 'numeric type' => ['payment_method', ['type' => 123]];
        yield 'unknown state' => ['status', 'PRIVATE_STATE'];
    }

    #[DataProvider('ownershipFailures')]
    public function testBadVerifiedConsentCannotAuthorizeCharge(string $field, mixed $value): void
    {
        $this->provider->response = static function (
            RequestInterface $request,
            array $resource
        ) use (
            $field,
            $value,
        ): array {
            $resource = self::normal($request, $resource);
            if (
                str_contains($request->getUri()->getPath(), '/payment_consents/')
                && ($resource['status'] ?? null) === 'VERIFIED'
            ) {
                $resource[$field] = $value;
            }
            return $resource;
        };
        $result = $this->execute();
        self::assertNotSame('pass', $result['result']);
        self::assertCount(0, $this->requests('/payment_intents/create'));
        self::assertCount(0, $this->requests('/disable'));
    }

    public function testEmptySimulationSuccessIsNotVerifiedProof(): void
    {
        $this->provider->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            $request->getUri()->getPath() === '/api/v1/simulation/pa/shopper_actions/verify' ? new Response(200) : null;
        $result = $this->execute();
        self::assertSame('pending', $result['result']);
        self::assertTrue($result['simulation_acknowledged']);
        self::assertFalse($result['consent_verified']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(0, $this->requests('/payment_intents/create'));
        self::assertCount(0, $this->requests('/disable'));
    }

    public function testKnownCustomerActionIsNotCompletedAndHasOnlyCleanUnusedCancellation(): void
    {
        $this->provider->response = function (RequestInterface $request, array $resource): array {
            $resource = self::normal($request, $resource);
            if (str_ends_with($request->getUri()->getPath(), '/confirm')) {
                $id = $resource['id'];
                self::assertIsString($id);
                $this->provider->resources[$id]['status'] = $resource['status'] = 'REQUIRES_CUSTOMER_ACTION';
                $this->provider->resources[$id]['captured_amount'] = $resource['captured_amount'] = '0';
            }
            return $resource;
        };
        $result = $this->execute();
        self::assertSame('pending', $result['result']);
        self::assertTrue($result['customer_action_required']);
        self::assertFalse($result['captured_exact_amount']);
        self::assertCount(1, $this->requests('/cancel'));
        self::assertCount(0, $this->requests('/refunds/create'));
        self::assertIsArray($result['cleanup']);
        self::assertSame('pass', $result['cleanup']['intent']);
        self::assertSame('pass', $result['cleanup']['consent']);
    }

    /** @return iterable<string,array{string}> */
    public static function failedSends(): iterable
    {
        foreach (['/verify', '/confirm', '/refunds/create', '/disable'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('failedSends')]
    public function testFailedSentActionNeverReplaysOrUsesSubstituteCleanup(string $suffix): void
    {
        $this->provider->intercept = static function (RequestInterface $request) use ($suffix): ?ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), $suffix)) {
                throw new RuntimeException('PRIVATE body URL credentials should never escape');
            }
            return null;
        };
        $result = $this->execute();
        self::assertSame($suffix === '/disable' ? 'pass' : 'fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(1, $this->requests($suffix));
        self::assertCount(0, $this->requests('/cancel'));
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function refundFailures(): iterable
    {
        yield 'wrong parent' => ['payment_intent_id', 'PRIVATE_OTHER'];
        yield 'wrong currency' => ['currency', 'EUR'];
        yield 'missing currency' => ['currency', null];
        yield 'wrong amount' => ['amount', '1.24'];
        yield 'pending' => ['status', 'RECEIVED'];
    }

    #[DataProvider('refundFailures')]
    public function testRefundNeedsOwnExactAcceptedReadBeforeDisable(string $field, mixed $value): void
    {
        $this->provider->response = static function (
            RequestInterface $request,
            array $resource
        ) use (
            $field,
            $value,
        ): array {
            $resource = self::normal($request, $resource);
            if ($request->getMethod() === 'GET' && str_contains($request->getUri()->getPath(), '/refunds/')) {
                $resource[$field] = $value;
            }
            return $resource;
        };
        $result = $this->execute();
        self::assertNotSame('pass', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(1, $this->requests('/refunds/create'));
        self::assertCount(0, $this->requests('/disable'));
        self::assertCount(0, $this->requests('/cancel'));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function intentFailures(): iterable
    {
        yield 'foreign identity' => ['id', 'PRIVATE_FOREIGN'];
        yield 'foreign customer' => ['customer_id', 'PRIVATE_FOREIGN'];
        yield 'wrong order' => ['merchant_order_id', 'PRIVATE_ORDER'];
        yield 'wrong marker' => ['metadata', ['sdk_validation_run' => 'PRIVATE_OTHER']];
        yield 'wrong currency' => ['currency', 'EUR'];
        yield 'wrong amount' => ['amount', '1.2301'];
        yield 'wrong capture' => ['captured_amount', '1.22'];
        yield 'missing capture' => ['captured_amount', null];
        yield 'wrong Consent' => ['payment_consent_id', 'PRIVATE_OTHER'];
        yield 'embedded foreign Consent' => ['payment_consent', ['id' => 'PRIVATE_OTHER']];
        yield 'embedded numeric Consent' => ['payment_consent', ['id' => 123]];
        yield 'wrong attempt parent' => ['latest_payment_attempt', ['id' => 'PRIVATE_ATTEMPT',
            'payment_intent_id' => 'PRIVATE_OTHER']];
        yield 'wrong attempt currency' => ['latest_payment_attempt', ['id' => 'PRIVATE_ATTEMPT', 'currency' => 'EUR']];
        yield 'wrong attempt capture' => ['latest_payment_attempt',
            ['id' => 'PRIVATE_ATTEMPT', 'captured_amount' => '0']];
        yield 'unexpected authorization' => ['status', 'REQUIRES_CAPTURE'];
    }

    #[DataProvider('intentFailures')]
    public function testBadIntentCannotAuthorizeRefundOrFallback(string $field, mixed $value): void
    {
        $this->provider->response = static function (
            RequestInterface $request,
            array $resource
        ) use (
            $field,
            $value,
        ): array {
            $resource = self::normal($request, $resource);
            if (
                str_contains($request->getUri()->getPath(), '/payment_intents/')
                && ($resource['status'] ?? null) === 'SUCCEEDED'
            ) {
                $resource[$field] = $value;
            }
            return $resource;
        };
        $result = $this->execute();
        self::assertNotSame('pass', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(0, $this->requests('/refunds/create'));
        self::assertCount(0, $this->requests('/cancel'));
        self::assertCount(0, $this->requests('/disable'));
    }

    public function testForeignOriginalRedirectIsWithheldWithoutCapabilityGetOrSimulationFallback(): void
    {
        $this->provider->response = static function (RequestInterface $request, array $resource): array {
            $resource = self::normal($request, $resource);
            if (($resource['status'] ?? null) === 'REQUIRES_CUSTOMER_ACTION') {
                $resource['next_action'] = ['type' => 'redirect', 'url' => 'https://PRIVATE.example/wallet'];
            }
            return $resource;
        };
        $result = $this->execute();
        self::assertSame('fail', $result['result']);
        self::assertCount(1, $this->requests('/verify')); // Only the owned SDK verify, no simulation send.
        self::assertCount(0, $this->requests('/payment_intents/create'));
        foreach ($this->provider->requests as $request) {
            self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
        }
    }

    public function testSimulationBodyMustBeTrulyEmptyNotOnlyDeclaredEmpty(): void
    {
        $this->provider->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            $request->getUri()->getPath() === '/api/v1/simulation/pa/shopper_actions/verify'
            ? new Response(200, ['Content-Length' => '0'], 'x') : null;
        $result = $this->execute();
        self::assertSame('fail', $result['result']);
        self::assertFalse($result['simulation_acknowledged']);
        self::assertTrue($result['reconciliation_required']);
        self::assertCount(0, $this->requests('/payment_intents/create'));
    }

    public function testUnrecordedCreateOutcomeIsNotReportedAsResourceAbsence(): void
    {
        $this->provider->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            str_ends_with($request->getUri()->getPath(), '/customers/create')
            ? new Response(201, [], '{"id":123}') : null;
        $result = $this->execute();
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['reconciliation_required']);
        self::assertIsArray($result['cleanup']);
        self::assertSame('not_recorded', $result['cleanup']['customer']);
        self::assertCount(1, $this->requests('/customers/create'));
        self::assertCount(0, $this->requests('/payment_consents/create'));
    }

    public function testApiDiagnosticProjectionNeverRetainsUnknownCodeMessageOrSecretFields(): void
    {
        $this->provider->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            str_ends_with($request->getUri()->getPath(), '/confirm') ? new Response(
                400,
                [],
                '{"code":"PRIVATE_CODE","message":"PRIVATE_URL token https://PRIVATE",'
                . '"source":"PRIVATE_SOURCE","details":{"id":"PRIVATE_ID"}}'
            ) : null;
        $result = $this->execute();
        self::assertSame('fail', $result['result']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertCount(1, $this->requests('/confirm'));
        self::assertCount(0, $this->requests('/refunds/create'));
        self::assertCount(0, $this->requests('/cancel'));
        self::assertCount(0, $this->requests('/disable'));
    }

    /** @return iterable<string,array{string}> */
    public static function unresolvedConfirmActions(): iterable
    {
        yield 'succeeded with contradictory action' => ['SUCCEEDED'];
        yield 'pending with action' => ['PENDING'];
    }

    #[DataProvider('unresolvedConfirmActions')]
    public function testAnyReturnedConfirmActionStopsCompletionWithoutInventingItsMeaning(string $state): void
    {
        $this->provider->response = static function (RequestInterface $request, array $resource) use ($state): array {
            $resource = self::normal($request, $resource);
            if (str_ends_with($request->getUri()->getPath(), '/confirm')) {
                $resource['status'] = $state;
                $resource['next_action'] = ['type' => 'PRIVATE_UNKNOWN', 'url' => 'https://PRIVATE'];
            }
            return $resource;
        };
        $result = $this->execute();
        self::assertSame('pending', $result['result']);
        self::assertTrue($result['confirm_action_present']);
        self::assertTrue($result['reconciliation_required']);
        self::assertFalse($result['captured_exact_amount']);
        self::assertCount(0, $this->requests('/refunds/create'));
        self::assertCount(0, $this->requests('/cancel'));
        self::assertCount(0, $this->requests('/disable'));
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }
}
