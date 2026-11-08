<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingCatalogue;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;

final class BillingCatalogueTest extends TestCase
{
    private const string RUN_ID = 'sdk-validation-00000000-0000-4000-8000-000000000001';
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-catalogue-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_CLIENT_ID=test_client_id\nAIRWALLEX_SANDBOX_API_KEY_MASTER=test_api_key\n"
            . "AIRWALLEX_ACCOUNT_ID=test_account_id\n"
        );
    }

    protected function tearDown(): void
    {
        unlink($this->directory . '/credentials');
        foreach (glob($this->directory . '/*') ?: [] as $folder) {
            foreach (glob($folder . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($folder);
        }
        rmdir($this->directory);
    }

    /**
     * @param list<array{int,string}> $responses
     * @return array{SandboxSession,RecordingClient}
     */
    private function session(array $responses): array
    {
        $handler = new MockHandler(array_map(
            static fn (array $item): Response => new Response($item[0], [], $item[1]),
            $responses,
        ));
        $stack = HandlerStack::create($handler);
        $recording = new RecordingClient(new HttpClient(['handler' => $stack]));
        $session = new SandboxSession(
            Configuration::load('sandbox', $this->directory . '/credentials'),
            'organization',
            $recording,
            static fn (): int => 100,
        );
        return [$session, $recording];
    }

    /** @param list<RequestInterface> $requests */
    private function request(array $requests, int $index): RequestInterface
    {
        $request = $requests[$index] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request);
        return $request;
    }

    /** @param (Closure(resource):bool)|null $synchronize */
    private function ledger(SandboxSession $session, ?Closure $synchronize = null): RunLedger
    {
        $index = 0;
        return RunLedger::create(
            $session->contextFingerprint(),
            $this->directory,
            static function () use (&$index): string {
                return sprintf('00000000-0000-4000-8000-%012d', ++$index);
            },
            $synchronize,
        );
    }

    /** @param array<string,mixed> $resource */
    private static function response(array $resource): string
    {
        // Fixture monetary values enter the response as JSON numbers without PHP float conversion.
        $amount = $resource['unit_amount'] ?? null;
        unset($resource['unit_amount']);
        $json = json_encode($resource, JSON_THROW_ON_ERROR);
        return is_string($amount) ? substr($json, 0, -1) . ',"unit_amount":' . $amount . '}' : $json;
    }

    /** @return array<string,mixed> */
    private static function price(string $id, string $amount): array
    {
        return [
            'id' => $id, 'unit_amount' => $amount, 'currency' => 'EUR', 'product_id' => 'created-product',
            'pricing_model' => 'PER_UNIT', 'recurring' => ['period' => 1, 'period_unit' => 'MONTH'],
            'active' => true, 'metadata' => ['sdk_validation_run' => self::RUN_ID],
        ];
    }

    /** @return array<string,mixed> */
    private static function customer(): array
    {
        return [
            'id' => 'created-customer', 'description' => 'SDK sandbox validation: ' . self::RUN_ID,
            'name' => self::RUN_ID, 'email' => self::RUN_ID . '@example.test', 'default_billing_currency' => 'EUR',
            'default_legal_entity_id' => 'legal-entity-fixture', 'metadata' => ['sdk_validation_run' => self::RUN_ID],
        ];
    }

    /** @return list<array{int,string}> */
    private function successfulResponses(): array
    {
        return [
            [201, '{"token":"test_token","expires_at":"1970-01-01T01:00:00Z"}'],
            [201, '{"id":"created-customer"}'], [200, '{"id":"created-customer"}'],
            [200, self::response(self::customer())],
            [201, '{"id":"created-product"}'],
            [201, '{"id":"created-monthly-price","unit_amount":1.23}'],
            [200, self::response(self::price('created-monthly-price', '12300e-4'))],
            [201, '{"id":"created-replacement-price","unit_amount":2.34}'],
            [200, self::response(self::price('created-replacement-price', '2.340000'))],
            [200, self::response([
                'id' => 'created-product', 'name' => self::RUN_ID,
                'metadata' => ['sdk_validation_run' => self::RUN_ID], 'active' => true,
            ])],
            [200, '{"id":"created-monthly-price","active":false}'],
            [200, '{"id":"created-replacement-price","active":false}'],
            [200, '{"id":"created-product","active":false}'],
        ];
    }

    public function testCatalogueExecutesPersistedMutationsAndArchivesOnlyItsCreatedResources(): void
    {
        [$session, $recording] = $this->session($this->successfulResponses());
        $ledger = $this->ledger($session);
        $result = BillingCatalogue::execute($session, $ledger, 'legal-entity-fixture');
        self::assertSame('pass', $result['result']);
        self::assertSame(['customers' => 1, 'products' => 1, 'prices' => 2], $result['created_counts']);
        self::assertSame(['prices_0' => 'pass', 'prices_1' => 'pass', 'products_0' => 'pass'], $result['cleanup']);
        self::assertCount(13, $recording->requests);
        $customerWire = json_decode(
            (string) $this->request($recording->requests, 1)->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($customerWire);
        self::assertSame('legal-entity-fixture', $customerWire['default_legal_entity_id']);
        self::assertSame('00000000-0000-4000-8000-000000000002', $customerWire['request_id']);
        self::assertStringNotContainsString('request_id', (string) $this->request($recording->requests, 2)->getBody());
        self::assertStringContainsString(
            '"unit_amount":1.23',
            (string) $this->request($recording->requests, 5)->getBody(),
        );
        self::assertStringContainsString(
            '/prices/created-monthly-price/update',
            (string) $this->request($recording->requests, 10)->getUri(),
        );
        $publicResult = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['created-customer', 'created-product', 'created-monthly-price', 'legal-entity-fixture'] as $value) {
            self::assertStringNotContainsString($value, $publicResult);
        }
    }

    public function testAccountContextCannotStartBillingMutations(): void
    {
        $recording = new RecordingClient(new HttpClient(['handler' => new MockHandler([])]));
        $session = new SandboxSession(
            Configuration::load('sandbox', $this->directory . '/credentials'),
            'account',
            $recording,
        );
        try {
            $this->expectException(RuntimeException::class);
            BillingCatalogue::execute($session, $this->ledger($session), null);
        } finally {
            self::assertSame([], $recording->requests);
        }
    }

    public function testFailedCreationIsNotReplayedAndCleanupCannotTargetAnUncreatedPrice(): void
    {
        $responses = array_slice($this->successfulResponses(), 0, 5);
        $responses[] = [500, '{"code":"transient_failure","message":"SENTINEL_DO_NOT_LOG"}'];
        $responses[] = [200, '{"id":"created-product","active":false}'];
        [$session, $recording] = $this->session($responses);
        $ledger = $this->ledger($session);
        $result = BillingCatalogue::execute($session, $ledger, null);
        self::assertSame('fail', $result['result']);
        self::assertSame(500, $result['http_status']);
        self::assertSame([], $ledger->created('prices'));
        self::assertSame(['products_0' => 'pass'], $result['cleanup']);
        self::assertCount(7, $recording->requests);
        self::assertStringNotContainsString('SENTINEL_DO_NOT_LOG', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,string,mixed,bool}> */
    public static function incorrectReadBacks(): iterable
    {
        $fields = [
            'customer' => [
                'description' => 'not-updated', 'name' => 'wrong-name', 'email' => 'wrong@example.test',
                'default_billing_currency' => 'USD', 'default_legal_entity_id' => 'wrong-entity',
                'metadata' => ['sdk_validation_run' => 'wrong-run'],
            ],
            'product' => [
                'name' => 'wrong-name', 'metadata' => ['sdk_validation_run' => 'wrong-run'], 'active' => false,
            ],
            'price' => [
                'unit_amount' => '1.2300000000000000001', 'currency' => 'USD', 'product_id' => 'unrelated-product',
                'pricing_model' => 'FLAT', 'recurring' => ['period' => 2, 'period_unit' => 'MONTH'],
                'active' => false, 'metadata' => ['sdk_validation_run' => 'wrong-run'],
            ],
        ];
        foreach ($fields as $resource => $values) {
            foreach ($values as $field => $value) {
                yield $resource . '-' . $field . '-wrong' => [$resource, $field, $value, false];
                yield $resource . '-' . $field . '-missing' => [$resource, $field, null, true];
            }
        }
        yield 'price-wrong-period-unit' => ['price', 'recurring', ['period' => 1, 'period_unit' => 'DAY'], false];
        yield 'price-missing-period' => ['price', 'recurring', ['period_unit' => 'MONTH'], false];
    }

    #[DataProvider('incorrectReadBacks')]
    public function testIncorrectOrMissingReadBackNeverProducesPassingEvidence(
        string $resource,
        string $field,
        mixed $value,
        bool $missing,
    ): void {
        $index = match ($resource) {
            'customer' => 3,
            'product' => 9,
            default => 6,
        };
        $body = match ($resource) {
            'customer' => self::customer(),
            'product' => [
                'id' => 'created-product', 'name' => self::RUN_ID,
                'metadata' => ['sdk_validation_run' => self::RUN_ID], 'active' => true,
            ],
            default => self::price('created-monthly-price', '1.23'),
        };
        if ($missing) {
            unset($body[$field]);
        } else {
            $body[$field] = $value;
        }
        $responses = array_slice($this->successfulResponses(), 0, $index);
        $responses[] = [200, self::response($body)];
        if ($resource !== 'customer') {
            $responses[] = [200, '{"id":"created-monthly-price","active":false}'];
            if ($resource === 'product') {
                $responses[] = [200, '{"id":"created-replacement-price","active":false}'];
            }
            $responses[] = [200, '{"id":"created-product","active":false}'];
        }
        [$session, $recording] = $this->session($responses);
        $result = BillingCatalogue::execute($session, $this->ledger($session), 'legal-entity-fixture');
        self::assertSame('fail', $result['result']);
        self::assertSame('local_protocol_or_transport', $result['failure']);
        self::assertIsArray($result['steps']);
        self::assertArrayNotHasKey(match ($resource) {
            'customer' => 'customer_update_retrieve',
            'product' => 'product_retrieve',
            default => 'monthly_price_create_retrieve',
        }, $result['steps']);
        self::assertCount(count($responses), $recording->requests);
    }

    public function testFailedPreMutationFlushPreventsEvenAuthenticationOrCleanup(): void
    {
        [$session, $recording] = $this->session([]);
        $calls = 0;
        $ledger = $this->ledger($session, static function ($stream) use (&$calls): bool {
            return ++$calls !== 4 && fsync($stream);
        });
        $result = BillingCatalogue::execute($session, $ledger, null);
        self::assertSame('fail', $result['result']);
        self::assertSame([], $recording->requests);
        self::assertSame([], $ledger->counts());
    }

    public function testAnotherCredentialContextCannotUseOrCleanUpThisLedger(): void
    {
        [$session, $recording] = $this->session([]);
        $ledger = RunLedger::create(str_repeat('b', 64), $this->directory);
        $ledger->recordCreated('products', 'a-different-owner-product');
        $this->expectExceptionMessage('Run state is unavailable or belongs to another account context.');
        try {
            BillingCatalogue::execute($session, $ledger, null);
        } finally {
            self::assertSame([], $recording->requests);
        }
    }
}
