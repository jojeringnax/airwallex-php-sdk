<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingWebhookApi;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SubscriptionWebhookProbe;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SubscriptionWebhookApi;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SourceEvidence;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use ReflectionMethod;
use ReflectionProperty;
use Jojeringnax\Airwallex\Webhook\WebhookSchema;

final class SubscriptionWebhookProbeTest extends TestCase
{
    private string $directory;
    private SubscriptionWebhookFixtureProvider $http;
    private ?WebhookDeliveryFixtureRuntime $runtime;
    /** @var (Closure(WebhookDeliveryFixtureRuntime):void)|null */
    private ?Closure $configure;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sdk-subscription-webhook-php-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/credentials', "AIRWALLEX_CLIENT_ID=SYNTHETIC_PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=SYNTHETIC_PRIVATE_KEY\n"
            . "AIRWALLEX_ACCOUNT_ID=SYNTHETIC_PRIVATE_ACCOUNT\nAIRWALLEX_ORGANIZATION_ID=SYNTHETIC_PRIVATE_ORG\n");
        chmod($this->directory . '/credentials', 0600);
        $this->http = new SubscriptionWebhookFixtureProvider();
        $this->runtime = null;
        $this->configure = null;
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
    private function options(): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'state-dir' => $this->directory, 'node-bin' => realpath(PHP_BINARY),
            'cloudflared-bin' => '/SYNTHETIC_PRIVATE_NOT_EXECUTED', 'create-one-subscription-webhook-pair' => true];
    }

    /**
     * @param (Closure(string,string):RunLedger)|null $ledgers
     * @param (Closure(string,RunLedger):WebhookDeliveryStore)|null $stores
     * @return array<string,mixed>
     */
    private function observe(?Closure $ledgers = null, ?Closure $stores = null): array
    {
        return SubscriptionWebhookProbe::run($this->options(), $this->http, function (array $config) {
            $runtime = new WebhookDeliveryFixtureRuntime($config);
            $this->runtime = $runtime;
            $this->http->runtime = $runtime;
            $runtime->eventFilter = static function (array $event): array {
                unset($event['account_id']);
                $event['org_id'] = 'SYNTHETIC_PRIVATE_ORG';
                return $event;
            };
            if ($this->configure !== null) {
                ($this->configure)($runtime);
            }
            return $runtime;
        }, static fn (): int => WebhookDeliveryFixtureRuntime::NOW, static function (int $milliseconds): void {
        }, $ledgers, $stores);
    }

    public function testFreshDefaultSubscriptionPairAndExactCleanup(): void
    {
        $safe = $this->observe();
        self::assertSame('pass', $safe['result'], json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertTrue($safe['sdk_pending_verified']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['typed_owner_verified']);
        self::assertTrue($safe['price_archived']);
        self::assertTrue($safe['product_archived']);
        self::assertTrue($safe['registration_deleted']);
        self::assertFalse($safe['reconciliation_required']);
        self::assertSame('unknown', $safe['inventory_completeness']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertLessThanOrEqual(39, count($this->http->requests));
    }
    public function testPreparedWireAndSeparateIntentAreDurableBeforeEveryDelegate(): void
    {
        $seen = [];
        $this->http->intercept = function (RequestInterface $request) use (&$seen): ?ResponseInterface {
            $path = $request->getUri()->getPath();
            if ($request->getMethod() !== 'POST' || !str_starts_with($path, '/api/v1/billing/')) {
                return null;
            }
            $action = match ($path) {
                '/api/v1/billing/billing_customers/create' => 'create_customer',
                '/api/v1/billing/products/create' => 'create_product',
                '/api/v1/billing/prices/create' => 'create_price',
                '/api/v1/billing/subscriptions/create' => 'create_subscription',
                '/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel' => 'cancel_subscription',
                '/api/v1/billing/prices/SYNTHETIC_PRIVATE_PRICE/update' => 'archive_price',
                '/api/v1/billing/products/SYNTHETIC_PRIVATE_PRODUCT/update' => 'archive_product',
                default => throw new RuntimeException('Unexpected synthetic write.'),
            };
            $files = glob($this->directory . '/sdk-validation-*/ledger.json');
            self::assertIsArray($files);
            self::assertCount(1, $files);
            $raw = file_get_contents($files[0]);
            self::assertIsString($raw);
            $ledger = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($ledger);
            self::assertIsArray($ledger['actions']);
            self::assertIsArray($ledger['observations']);
            $intent = $ledger['actions'][$action];
            $wire = $ledger['observations'][$action . '_wire'];
            self::assertIsArray($intent);
            self::assertIsArray($wire);
            $body = (string) $request->getBody();
            self::assertSame(hash('sha256', $body), $wire['wire_sha256']);
            self::assertSame(hash('sha256', (string) $request->getUri()), $wire['target_sha256']);
            self::assertSame(
                hash(
                    'sha256',
                    json_encode(
                        $request->getHeaders(),
                        JSON_THROW_ON_ERROR
                    )
                ),
                $wire['headers_sha256']
            );
            self::assertNotSame($intent['body_hash'], $wire['wire_sha256']);
            $native = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($native);
            self::assertSame($intent['request_id'], $native['request_id']);
            self::assertSame($request->getMethod(), $wire['method']);
            self::assertSame($request->getProtocolVersion(), $wire['protocol']);
            if ($action === 'create_subscription') {
                self::assertSame('OUT_OF_BAND', $native['collection_method']);
                self::assertSame('2028-01-02T00:00:00Z', $native['starts_at']);
                self::assertSame([['price_id' => 'SYNTHETIC_PRIVATE_PRICE', 'quantity' => 1]], $native['items']);
                foreach (
                    ['trial_period_days',
                    'trial_ends_at',
                    'payment_source_id',
                    'ends_at',
                    'recurring'] as $forbidden
                ) {
                    self::assertArrayNotHasKey($forbidden, $native);
                }
            }
            if ($action === 'cancel_subscription') {
                self::assertSame('NONE', $native['proration_behavior']);
                self::assertCount(2, $native);
            }
            $seen[] = $action;
            return null;
        };
        self::assertSame('pass', $this->observe()['result']);
        self::assertCount(7, $seen);
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
        foreach ($this->http->requests as $request) {
            self::assertFalse($request->hasHeader('Cookie'));
            self::assertFalse($request->hasHeader('Referer'));
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
            if (
                !$request->hasHeader('x-login-as')
                && $request->getUri()->getPath() !== '/api/v1/authentication/login'
            ) {
                self::assertSame(
                    $request->getUri()->getPath() === '/api/v1/account' ? 'Bearer SYNTHETIC_PRIVATE_ACCOUNT_TOKEN'
                    : (str_starts_with($request->getUri()->getPath(), '/api/v1/webhooks/')
                        ? 'Bearer SYNTHETIC_PRIVATE_DEFAULT_TOKEN' : 'Bearer SYNTHETIC_PRIVATE_ORG_TOKEN'),
                    $request->getHeaderLine('Authorization')
                );
            }
        }
    }

    private function preparedApi(WebhookConfigFixtureClient $http, Closure $clock): SubscriptionWebhookApi
    {
        $root = dirname(__DIR__, 4);
        $tools = [];
        foreach (
            ['entry' => '/tools/billing-subscription-webhook-probe.php',
            'receiver' => '/tools/webhooks/webhook-receiver.cjs',
            'supervisor' => '/tools/webhooks/webhook-supervisor.cjs'] as $name => $path
        ) {
            $hash = hash_file('sha256', $root . $path);
            self::assertIsString($hash);
            $tools[$name] = $hash;
        }
        return new SubscriptionWebhookApi(
            Configuration::load('sandbox', $this->directory . '/credentials'),
            $http,
            300,
            $clock,
            $tools,
            SourceEvidence::capture()
        );
    }

    /** @return iterable<string,array{string}> */
    public static function wirePhases(): iterable
    {
        yield 'create' => ['create_product'];
        yield 'cancel with post-reservation read' => ['cancel_subscription'];
    }

    #[DataProvider('wirePhases')]
    public function testActualWireFsyncAndRemoteGuardCannotSubstituteTheCallerPacket(string $action): void
    {
        $context = hash('sha256', 'synthetic prepared context');
        $state = new class {
            public ?RequestInterface $held = null;
            public bool $changed = false;
        };
        $ledger = RunLedger::create($context, $this->directory, synchronize: static function ($stream) use (
            $state,
            $action
        ): bool {
            $path = stream_get_meta_data($stream)['uri'] ?? null;
            if (
                !$state->changed && $state->held instanceof RequestInterface && is_string($path)
                && str_ends_with($path, 'ledger.json.writing')
            ) {
                $raw = file_get_contents($path);
                if (is_string($raw) && str_contains($raw, '"' . $action . '_wire"')) {
                    $body = $state->held->getBody();
                    $body->rewind();
                    $body->write('CHANGED_PRIVATE_WIRE');
                    $body->seek(2);
                    $state->changed = true;
                }
            }
            return fsync($stream);
        });
        $responses = [new Response(200, [], '{"id":"own"}')];
        if ($action === 'cancel_subscription') {
            $responses[] = new Response(200, [], '{"id":"own"}');
        }
        $http = new WebhookConfigFixtureClient($responses);
        $api = $this->preparedApi($http, static fn (): float => 0);
        $api->producerTransport->bind('subscription', 'own');
        $api->producerTransport->bind('customer', 'own');
        $body = $action === 'create_product'
            ? ['name' => $ledger->runId(), 'active' => true, 'metadata' => ['sdk_validation_run' => $ledger->runId()]]
            : ['proration_behavior' => 'NONE'];
        $postReads = 0;
        $headers = ['Accept' => 'application/json', 'Authorization' => 'Bearer SYNTHETIC_PRIVATE_TOKEN',
            'x-api-version' => '2026-08-21', 'Content-Type' => 'application/json'];
        (new ReflectionMethod(SubscriptionWebhookProbe::class, 'mutate'))->invoke(
            null,
            $api,
            $ledger,
            $context,
            $action,
            $body,
            static function (array $wire) use ($api, $action, $headers, $state): array {
                $path = $action === 'create_product' ? 'products/create' : 'subscriptions/own/cancel';
                $state->held = new \GuzzleHttp\Psr7\Request('POST', 'https://api.sandbox.airwallex.com/api/v1/billing/'
                    . $path, $headers, json_encode($wire, JSON_THROW_ON_ERROR));
                $api->producerTransport->sendRequest($state->held);
                return [];
            },
            $action === 'cancel_subscription' ? static function () use ($api, $headers, &$postReads): void {
                $api->producerTransport->sendRequest(new \GuzzleHttp\Psr7\Request(
                    'GET',
                    'https://api.sandbox.airwallex.com/api/v1/billing/billing_customers/own',
                    $headers
                ));
                ++$postReads;
            } : null
        );
        self::assertTrue($state->changed);
        self::assertSame($action === 'cancel_subscription' ? 1 : 0, $postReads);
        self::assertCount($postReads + 1, $http->requests);
        $actual = $http->requests[$postReads];
        self::assertNotSame($state->held, $actual);
        self::assertSame(0, $actual->getBody()->tell());
        $raw = file_get_contents($this->directory . '/' . $ledger->runId() . '/ledger.json');
        self::assertIsString($raw);
        $native = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($native);
        self::assertIsArray($native['observations']);
        self::assertIsArray($native['actions']);
        $wire = $native['observations'][$action . '_wire'];
        $intent = $native['actions'][$action];
        self::assertIsArray($wire);
        self::assertIsArray($intent);
        $sent = (string) $actual->getBody();
        self::assertSame(hash('sha256', $sent), $wire['wire_sha256']);
        self::assertSame(hash('sha256', (string) $actual->getUri()), $wire['target_sha256']);
        self::assertSame(
            hash('sha256', json_encode($actual->getHeaders(), JSON_THROW_ON_ERROR)),
            $wire['headers_sha256']
        );
        self::assertSame($actual->getMethod(), $wire['method']);
        self::assertSame($actual->getProtocolVersion(), $wire['protocol']);
        $decoded = json_decode($sent, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame($intent['request_id'], $decoded['request_id']);
        self::assertNotSame($intent['body_hash'], $wire['wire_sha256']);
        self::assertStringNotContainsString('CHANGED_PRIVATE_WIRE', $sent);
    }

    /** @return iterable<string,array{string}> */
    public static function postWireFailures(): iterable
    {
        foreach (['source', 'context', 'clock', 'failed_fsync'] as $failure) {
            yield $failure => [$failure];
        }
    }

    #[DataProvider('postWireFailures')]
    public function testActualPostWireProofFailureConsumesReservationWithoutDelegate(string $failure): void
    {
        $context = hash('sha256', 'synthetic failure context');
        $time = new class {
            public float $now = 0.0;
        };
        $changed = false;
        $ledger = null;
        $ledger = RunLedger::create($context, $this->directory, synchronize: static function ($stream) use (
            &$ledger,
            $time,
            &$changed,
            $failure
        ): bool {
            $path = stream_get_meta_data($stream)['uri'] ?? null;
            if (
                !$changed && $ledger instanceof RunLedger && is_string($path)
                && str_ends_with($path, 'ledger.json.writing')
            ) {
                $raw = file_get_contents($path);
                if (is_string($raw) && str_contains($raw, '"create_product_wire"')) {
                    $changed = true;
                    if ($failure === 'failed_fsync') {
                        return false;
                    }
                    if ($failure === 'clock') {
                        $time->now = 256;
                    } else {
                        $property = new ReflectionProperty(RunLedger::class, 'state');
                        $state = $property->getValue($ledger);
                        self::assertIsArray($state);
                        $state[$failure === 'source' ? 'source' : 'context_fingerprint'] =
                            $failure === 'source' ? [] : hash('sha256', 'different context');
                        $property->setValue($ledger, $state);
                    }
                }
            }
            return fsync($stream);
        });
        $http = new WebhookConfigFixtureClient([]);
        $api = $this->preparedApi($http, static function () use ($time): float {
            return $time->now;
        });
        try {
            (new ReflectionMethod(SubscriptionWebhookProbe::class, 'mutate'))->invoke(
                null,
                $api,
                $ledger,
                $context,
                'create_product',
                ['name' => $ledger->runId(), 'active' => true,
                    'metadata' => ['sdk_validation_run' => $ledger->runId()]],
                static function (array $wire) use ($api): array {
                    $api->producerTransport->sendRequest(new \GuzzleHttp\Psr7\Request(
                        'POST',
                        'https://api.sandbox.airwallex.com/api/v1/billing/products/create',
                        ['Accept' => 'application/json', 'Authorization' => 'Bearer SYNTHETIC_PRIVATE_TOKEN',
                            'x-api-version' => '2026-08-21', 'Content-Type' => 'application/json'],
                        json_encode($wire, JSON_THROW_ON_ERROR)
                    ));
                    return [];
                }
            );
            self::fail('A failed post-wire proof escaped.');
        } catch (\Throwable $error) {
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', (string) $error);
            self::assertTrue($changed);
            self::assertSame([], $http->requests);
            self::assertFalse($api->producerTransport->sent('create_product'));
        }
        $this->expectException(\Jojeringnax\Airwallex\Exception\ProtocolException::class);
        $api->producerTransport->reserve('create_product', static function (): void {
        });
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function cursors(): iterable
    {
        yield 'absent' => [[], 'absent'];
        yield 'observed null' => [['page_before' => null, 'page_after' => null], 'null_observed'];
        yield 'opaque string' => [['page_before' => 'SYNTHETIC_PRIVATE_CURSOR',
            'page_after' => 'SYNTHETIC_PRIVATE_CURSOR'],
            'string_present'];
        yield 'malformed integer' => [['page_before' => 5, 'page_after' => 5], 'wrong_type'];
    }

    /** @param array<string,mixed> $cursors */
    #[DataProvider('cursors')]
    public function testOptionalPaginationDoesNotInventCompletenessOrReplaceItemProof(
        array $cursors,
        string $classification
    ): void {
        $this->http->cursors = $cursors;
        $safe = $this->observe();
        self::assertSame('pass', $safe['result']);
        self::assertSame(['page_before' => $classification, 'page_after' => $classification], $safe['pagination']);
        self::assertSame('unknown', $safe['inventory_completeness']);
        self::assertSame(3, $this->http->itemPage);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function badItems(): iterable
    {
        yield 'wrong parent' => ['subscription_id', 'foreign'];
        yield 'wrong quantity' => ['quantity', 2];
        yield 'wrong native quantity' => ['quantity', '1'];
        yield 'wrong price only' => ['price_id', 'foreign'];
        yield 'wrong Product only' => ['product_id', 'foreign'];
        yield 'wrong amount only' => ['price_amount', '1.24'];
        yield 'wrong Price marker only' => ['price_marker', ['sdk_validation_run' => 'foreign']];
        yield 'no Item' => ['missing', null];
        yield 'multiple Items' => ['multiple', null];
    }

    #[DataProvider('badItems')]
    public function testBadOriginalItemBlocksCancelAndSubstituteArchive(string $field, mixed $value): void
    {
        $this->http->responseFilter = static function (
            ResponseInterface $response,
            RequestInterface $request
        ) use (
            $field,
            $value
): ResponseInterface {
            if (!str_ends_with($request->getUri()->getPath(), '/items')) {
                return $response;
            }
            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertIsArray($body['items']);
            self::assertIsArray($body['items'][0]);
            if ($field === 'missing') {
                $body['items'] = [];
            } elseif ($field === 'multiple') {
                $body['items'][] = $body['items'][0];
            } elseif (in_array($field, ['price_id', 'price_amount', 'price_marker', 'product_id'], true)) {
                self::assertIsArray($body['items'][0]['price']);
                if ($field === 'product_id') {
                    self::assertIsArray($body['items'][0]['price']['product']);
                    $body['items'][0]['price']['product']['id'] = $value;
                } else {
                    $key = match ($field) {
                        'price_id' => 'id', 'price_amount' => 'unit_amount', default => 'metadata'
                    };
                    $body['items'][0]['price'][$key] = $value;
                }
            } else {
                $body['items'][0][$field] = $value;
            }
            return SubscriptionWebhookFixtureProvider::json($body);
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(0, $this->http->count('/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel'));
        self::assertSame(0, $this->http->count('/api/v1/billing/prices/SYNTHETIC_PRIVATE_PRICE/update'));
    }

    public function testNeverCancelledDoesNotRepeatOrArchive(): void
    {
        $this->http->cancelEffective = false;
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertFalse($safe['sdk_cancelled_verified']);
        self::assertSame(1, $this->http->count('/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel'));
        self::assertSame(7, $this->http->count('/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION', 'GET'));
        self::assertSame(0, $this->http->count('/api/v1/billing/prices/SYNTHETIC_PRIVATE_PRICE/update'));
    }

    public function testUnknownCancelMayReconcileButNeverChangesOriginalFailureOrResends(): void
    {
        $this->http->responseFilter = static function (
            ResponseInterface $response,
            RequestInterface $request
): ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/cancel')) {
                throw new RuntimeException('SYNTHETIC_PRIVATE_AFTER_SEND');
            }
            return $response;
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertSame('unknown', $safe['cancel_action_outcome']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['price_archived']);
        self::assertFalse($safe['reconciliation_required']);
        self::assertSame(1, $this->http->count('/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel'));
    }

    public function testMissingDeliveryPendingStillPerformsStrictOwnCleanup(): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->emit = false;
        };
        $safe = $this->observe();
        self::assertSame('pending', $safe['result']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['registration_deleted']);
        self::assertFalse($safe['signed_resource_pair_observed']);
    }

    /** @return iterable<string,array{string}> */
    public static function writeActions(): iterable
    {
        foreach (
            ['create_customer', 'create_product', 'create_price', 'create_subscription',
            'cancel_subscription',
                'archive_price',
                'archive_product',
                'create_webhook',
                'delete_own_webhook'] as $action
        ) {
            yield $action => [$action];
        }
    }

    #[DataProvider('writeActions')]
    public function testDurabilityFailurePreventsTheSelectedMutationAndAllReplay(string $action): void
    {
        $failed = false;
        $safe = $this->observe(static function (string $context, string $directory) use ($action, &$failed): RunLedger {
            return RunLedger::create(
                $context,
                $directory,
                synchronize: static function ($stream) use (
                    $action,
                    &$failed
                ): bool {
                    $metadata = stream_get_meta_data($stream);
                    $path = $metadata['uri'] ?? null;
                    if (is_string($path) && str_ends_with($path, 'ledger.json.writing')) {
                        $raw = file_get_contents($path);
                        if (is_string($raw) && str_contains($raw, '"' . $action . '"')) {
                            $failed = true;
                            return false;
                        }
                    }
                    return fsync($stream);
                }
            );
        });
        self::assertTrue($failed);
        self::assertSame('fail', $safe['result']);
        $path = match ($action) {
            'create_customer' => '/api/v1/billing/billing_customers/create',
            'create_product' => '/api/v1/billing/products/create',
            'create_price' => '/api/v1/billing/prices/create',
            'create_subscription' => '/api/v1/billing/subscriptions/create',
            'cancel_subscription' => '/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel',
            'archive_price' => '/api/v1/billing/prices/SYNTHETIC_PRIVATE_PRICE/update',
            'archive_product' => '/api/v1/billing/products/SYNTHETIC_PRIVATE_PRODUCT/update',
            'create_webhook' => '/api/v1/webhooks/create',
            default => '/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete',
        };
        self::assertSame(0, $this->http->count($path));
    }

    /** @return iterable<string,array{int}> */
    public static function invalidClocks(): iterable
    {
        yield 'negative' => [-1];
        yield 'overflow' => [PHP_INT_MAX];
    }

    #[DataProvider('invalidClocks')]
    public function testInvalidFutureClockFailsBeforeAnyAuthOrCatalogue(int $now): void
    {
        $safe = SubscriptionWebhookProbe::run($this->options(), $this->http, clock: static fn (): int => $now);
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $this->http->requests);
    }

    public function testRealEntryRejectsSelectorsAndNonBareOrDuplicateFlagsWithoutReadingConfiguration(): void
    {
        $entry = dirname(__DIR__, 4) . '/tools/billing-subscription-webhook-probe.php';
        foreach (
            [[], ['--environment=production'], ['--create-one-subscription-webhook-pair=true'],
            ['--create-one-subscription-webhook-pair', '--create-one-subscription-webhook-pair'],
            ['--environment=sandbox', '--event=subscription.created'], ['--resume=SYNTHETIC_PRIVATE']] as $arguments
        ) {
            $process = proc_open([PHP_BINARY, $entry, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $out = $pipes[1] ?? null;
            $err = $pipes[2] ?? null;
            self::assertIsResource($out);
            self::assertIsResource($err);
            $stdout = stream_get_contents($out);
            $stderr = stream_get_contents($err);
            fclose($out);
            fclose($err);
            self::assertSame(1, proc_close($process));
            self::assertSame('', $stderr);
            self::assertIsString($stdout);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $stdout);
            $safe = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($safe);
            self::assertSame('configuration', $safe['phase']);
            self::assertSame('fail', $safe['result']);
        }
    }

    public function testFourTerminalReadsUseTheWhole28ResourceBudgetWithoutAnotherCancel(): void
    {
        $reads = 0;
        $this->http->responseFilter = function (
            ResponseInterface $response,
            RequestInterface $request
        ) use (&$reads): ResponseInterface {
            if (
                $request->getMethod() === 'GET'
                    && $request->getUri()->getPath() === '/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION'
                && $this->http->count('/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel') === 1
                    && ++$reads < 4
            ) {
                $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                $body['status'] = 'PENDING';
                return SubscriptionWebhookFixtureProvider::json($body);
            }
            return $response;
        };
        $safe = $this->observe();
        self::assertSame('pass', $safe['result']);
        self::assertSame(4, $reads);
        $resources = array_filter($this->http->requests, static fn (RequestInterface $request): bool =>
            str_starts_with($request->getUri()->getPath(), '/api/v1/billing/'));
        self::assertCount(28, $resources);
        // Three actual single logins + account GET + five management operations +28 resource calls.
        self::assertCount(37, $this->http->requests);
        // Two independently capped role-token refreshes give the source-derived maximum39.
        self::assertSame(39, count($this->http->requests) + 2);
    }

    /** @return iterable<string,array{bool}> */
    public static function reservationChanges(): iterable
    {
        yield 'owner changed' => [false];
        yield 'future start elapsed' => [true];
    }

    #[DataProvider('reservationChanges')]
    public function testPostDurabilityGuardChangePreventsCancelAndAnySubstitute(bool $time): void
    {
        $state = new class {
            public bool $changed = false;
            public int $now = WebhookDeliveryFixtureRuntime::NOW;
        };
        $this->http->responseFilter = static function (
            ResponseInterface $response,
            RequestInterface $request
        ) use (
            $state,
            $time
): ResponseInterface {
            if (
                $state->changed && !$time && $request->getMethod() === 'GET'
                && str_ends_with($request->getUri()->getPath(), '/SYNTHETIC_PRIVATE_SUBSCRIPTION')
            ) {
                $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                $body['billing_customer_id'] = 'foreign';
                return SubscriptionWebhookFixtureProvider::json($body);
            }
            return $response;
        };
        $factory = static function (string $context, string $directory) use ($state, $time): RunLedger {
            return RunLedger::create(
                $context,
                $directory,
                synchronize: static function ($stream) use (
                    $state,
                    $time
                ): bool {
                    $metadata = stream_get_meta_data($stream);
                    $path = $metadata['uri'] ?? null;
                    if (is_string($path) && str_ends_with($path, 'ledger.json.writing')) {
                        $raw = file_get_contents($path);
                        if (is_string($raw) && str_contains($raw, '"cancel_subscription_wire"')) {
                            $state->changed = true;
                            if ($time) {
                                $state->now = WebhookDeliveryFixtureRuntime::NOW + 86400000;
                            }
                        }
                    }
                    return fsync($stream);
                }
            );
        };
        $safe = SubscriptionWebhookProbe::run($this->options(), $this->http, function (array $config) {
            $runtime = new WebhookDeliveryFixtureRuntime($config);
            $this->runtime = $runtime;
            $this->http->runtime = $runtime;
            return $runtime;
        }, static function () use ($state): int {
            return $state->now;
        }, static function (int $delay): void {
        }, $factory);
        self::assertTrue($state->changed);
        self::assertTrue($safe['cancel_reserved']);
        self::assertSame('fail', $safe['result']);
        self::assertSame(0, $this->http->count('/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel'));
        self::assertSame(0, $this->http->count('/api/v1/billing/prices/SYNTHETIC_PRIVATE_PRICE/update'));
    }

    public function testPostCreateInventoryPersistenceFailureCannotInventAnotherTargetOrDependentWrite(): void
    {
        $safe = $this->observe(static function (string $context, string $directory): RunLedger {
            return RunLedger::create($context, $directory, synchronize: static function ($stream): bool {
                $metadata = stream_get_meta_data($stream);
                $path = $metadata['uri'] ?? null;
                if (is_string($path) && str_ends_with($path, 'ledger.json.writing')) {
                    $raw = file_get_contents($path);
                    if (is_string($raw)) {
                        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                        if (
                            is_array($data) && is_array($data['resources'] ?? null)
                            && array_key_exists('subscriptions', $data['resources'])
                        ) {
                            return false;
                        }
                    }
                }
                return fsync($stream);
            });
        });
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['producer_outcome_unknown']);
        self::assertSame(1, $this->http->count('/api/v1/billing/subscriptions/create'));
        self::assertSame(0, $this->http->count('/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel'));
        self::assertSame(0, $this->http->count('/api/v1/billing/prices/SYNTHETIC_PRIVATE_PRICE/update'));
    }

    public function testUnknownDeleteReconcilesOwn404BeforeStoppingWithoutRepeatingDelete(): void
    {
        $this->http->responseFilter = static function (
            ResponseInterface $response,
            RequestInterface $request
): ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/delete')) {
                throw new RuntimeException('PRIVATE_AFTER_DELETE');
            }
            return $response;
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['registration_deleted']);
        self::assertSame('unknown', $safe['registration_delete_outcome']);
        self::assertSame(1, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
        self::assertFalse($safe['reconciliation_required']);
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
    }
    #[DataProvider('writeActions')]
    public function testPreparedWireFsyncFailureCannotReachTheSelectedWrite(string $action): void
    {
        $phase = match ($action) {
            'create_webhook' => 'registration_create', 'delete_own_webhook' => 'registration_delete', default => $action
        };
        $failed = false;
        $safe = $this->observe(static function (string $context, string $directory) use ($phase, &$failed): RunLedger {
            return RunLedger::create(
                $context,
                $directory,
                synchronize: static function ($stream) use (
                    $phase,
                    &$failed
                ): bool {
                    $metadata = stream_get_meta_data($stream);
                    $path = $metadata['uri'] ?? null;
                    if (is_string($path) && str_ends_with($path, 'ledger.json.writing')) {
                        $raw = file_get_contents($path);
                        if (is_string($raw) && str_contains($raw, '"' . $phase . '_wire"')) {
                            $failed = true;
                            return false;
                        }
                    }
                    return fsync($stream);
                }
            );
        });
        self::assertTrue($failed);
        self::assertSame('fail', $safe['result']);
        $path = match ($action) {
            'create_customer' => '/api/v1/billing/billing_customers/create',
            'create_product' => '/api/v1/billing/products/create',
            'create_price' => '/api/v1/billing/prices/create',
            'create_subscription' => '/api/v1/billing/subscriptions/create',
            'cancel_subscription' => '/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel',
            'archive_price' => '/api/v1/billing/prices/SYNTHETIC_PRIVATE_PRICE/update',
            'archive_product' => '/api/v1/billing/products/SYNTHETIC_PRIVATE_PRODUCT/update',
            'create_webhook' => '/api/v1/webhooks/create',
            default => '/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete',
        };
        self::assertSame(0, $this->http->count($path));
    }

    /** @return iterable<string,array{string,int}> */
    public static function failures(): iterable
    {
        foreach (
            ['/api/v1/webhooks/create', '/api/v1/billing/billing_customers/create',
            '/api/v1/billing/products/create', '/api/v1/billing/prices/create', '/api/v1/billing/subscriptions/create',
            '/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel',
            '/api/v1/billing/prices/SYNTHETIC_PRIVATE_PRICE/update',
                '/api/v1/billing/products/SYNTHETIC_PRIVATE_PRODUCT/update'] as $path
        ) {
            foreach ([400, 500, 0] as $status) {
                yield $path . ':' . $status => [$path, $status];
            }
        }
    }

    #[DataProvider('failures')]
    public function testPossiblySentFailureNeverReplaysOrClaimsAFullPipeline(string $path, int $status): void
    {
        $this->http->intercept = static function (RequestInterface $request) use ($path, $status): ?ResponseInterface {
            if ($request->getUri()->getPath() !== $path) {
                return null;
            }
            if ($status === 0) {
                throw new RuntimeException('SYNTHETIC_PRIVATE_TRANSPORT');
            }
            return new Response($status, ['Content-Type' => 'application/json'], '{"code":"invalid_argument"}');
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertSame(1, $this->http->count($path));
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        if ($path === '/api/v1/webhooks/create') {
            self::assertSame(0, $this->http->count('/api/v1/billing/billing_customers/create'));
            self::assertSame(0, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
        }
    }

    public function testRegistrationSnapshotFailureStopsTriggersAndNeverGuessesDelete(): void
    {
        $safe = $this->observe(stores: static function (string $directory, RunLedger $ledger): WebhookDeliveryStore {
            return new WebhookDeliveryStore($directory, $ledger, static function ($stream): bool {
                $metadata = stream_get_meta_data($stream);
                $path = $metadata['uri'] ?? null;
                return !(is_string($path) && str_ends_with($path, 'registration.json.writing')) && fsync($stream);
            });
        });
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['registration_outcome_unknown']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_customers/create'));
        self::assertSame(0, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
    }

    public function testEquivalentSourceTimeZoneDoesNotRewriteTheCreateIntent(): void
    {
        $this->http->responseFilter = static function (
            ResponseInterface $response,
            RequestInterface $request
): ResponseInterface {
            if (
                str_starts_with($request->getUri()->getPath(), '/api/v1/billing/subscriptions/')
                && !str_contains($request->getUri()->getPath(), '/items')
            ) {
                $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($data);
                if (is_string($data['starts_at'] ?? null)) {
                    $data['starts_at'] = str_replace('Z', '+0000', $data['starts_at']);
                }
                return SubscriptionWebhookFixtureProvider::json($data, $response->getStatusCode());
            }
            return $response;
        };
        self::assertSame('pass', $this->observe()['result']);
    }

    public function testOwnedToolSourceMutationDuringWireDurabilityBlocksSendAndRestoresBytes(): void
    {
        $entry = dirname(__DIR__, 4) . '/tools/billing-subscription-webhook-probe.php';
        $original = file_get_contents($entry);
        self::assertIsString($original);
        $changed = false;
        try {
            $safe = $this->observe(static function (
                string $context,
                string $directory
            ) use (
                $entry,
                &$changed
): RunLedger {
                return RunLedger::create(
                    $context,
                    $directory,
                    synchronize: static function ($stream) use (
                        $entry,
                        &$changed
                    ): bool {
                        $metadata = stream_get_meta_data($stream);
                        $path = $metadata['uri'] ?? null;
                        if (!$changed && is_string($path) && str_ends_with($path, 'ledger.json.writing')) {
                            $raw = file_get_contents($path);
                            if (is_string($raw) && str_contains($raw, '"create_customer_wire"')) {
                                file_put_contents($entry, "\n// synthetic provenance change\n", FILE_APPEND);
                                $changed = true;
                            }
                        }
                        return fsync($stream);
                    }
                );
            });
            self::assertTrue($changed);
            self::assertSame('fail', $safe['result']);
            self::assertSame(0, $this->http->count('/api/v1/billing/billing_customers/create'));
        } finally {
            file_put_contents($entry, $original);
        }
        self::assertSame($original, file_get_contents($entry));
    }
    public function testReceiverFailureAtTheNextDurableCreateGuardPreventsThatCreate(): void
    {
        $this->http->responseFilter = function (
            ResponseInterface $response,
            RequestInterface $request
): ResponseInterface {
            if ($request->getUri()->getPath() === '/api/v1/billing/billing_customers/SYNTHETIC_PRIVATE_CUSTOMER') {
                self::assertNotNull($this->runtime);
                $this->runtime->failPoll = true;
            }
            return $response;
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertSame(0, $this->http->count('/api/v1/billing/products/create'));
        self::assertTrue($safe['registration_deleted']);
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
    }

    public function testTeardownFailureDoesNotEraseSdkOrDeletionFactsOrBecomeAPass(): void
    {
        $this->configure = function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->failStop = true;
            $runtime->onStop = function (): void {
                self::assertSame(1, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
            };
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['registration_deleted']);
        self::assertTrue($safe['reconciliation_required']);
    }

    public function testReturnDoesNotClaimCompletionWhenOrganizationReferenceIsUnavailable(): void
    {
        file_put_contents($this->directory . '/credentials', "AIRWALLEX_CLIENT_ID=SYNTHETIC_PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=SYNTHETIC_PRIVATE_KEY\n"
            . "AIRWALLEX_ACCOUNT_ID=SYNTHETIC_PRIVATE_ACCOUNT\n");
        $safe = $this->observe();
        self::assertSame('pending', $safe['result']);
        self::assertFalse($safe['typed_owner_verified']);
        self::assertTrue($safe['server_lifecycle_verified']);
        self::assertTrue($safe['registration_deleted']);
        self::assertFalse($safe['organization_manager_verified']);
    }
    /** @return iterable<string,array{string}> */
    public static function archives(): iterable
    {
        yield 'price' => ['/api/v1/billing/prices/SYNTHETIC_PRIVATE_PRICE/update'];
        yield 'product' => ['/api/v1/billing/products/SYNTHETIC_PRIVATE_PRODUCT/update'];
    }

    #[DataProvider('archives')]
    public function testAmbiguousArchiveSuccessNeverBecomesAnOverallPass(string $path): void
    {
        $this->http->responseFilter = static fn (
            ResponseInterface $response,
            RequestInterface $request
        ): ResponseInterface =>
            $request->getUri()->getPath() === $path ? new Response(200, [], '{"id":"foreign"}') : $response;
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(1, $this->http->count($path));
    }

    public function testPreflightAndProducerTokensDoNotShareAnAccountAuthority(): void
    {
        $http = new WebhookConfigFixtureClient([]);
        $t = new \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SubscriptionWebhookTransport(
            $http,
            accountId: 'SYNTHETIC_PRIVATE_ACCOUNT'
        );
        $t->bind('customer', 'own');
        $this->expectException(\Jojeringnax\Airwallex\Exception\ProtocolException::class);
        try {
            $t->sendRequest(new \GuzzleHttp\Psr7\Request(
                'GET',
                'https://api.sandbox.airwallex.com/api/v1/billing/billing_customers/own'
            ));
        } finally {
            self::assertSame([], $http->requests);
        }
    }
    /** @return iterable<string,array{float}> */
    public static function invalidElapsed(): iterable
    {
        yield 'negative' => [-1.0];
        yield 'not finite' => [NAN];
        yield 'infinite' => [INF];
        yield 'window overflow' => [PHP_FLOAT_MAX];
    }

    #[DataProvider('invalidElapsed')]
    public function testNonfiniteOrUnrepresentableOriginalCaseClockFailsBeforeLogin(float $value): void
    {
        $safe = SubscriptionWebhookProbe::run(
            $this->options(),
            $this->http,
            static fn (array $config): WebhookDeliveryFixtureRuntime => new WebhookDeliveryFixtureRuntime($config),
            clock: static fn (): int => WebhookDeliveryFixtureRuntime::NOW,
            elapsed: static fn (): float => $value
        );
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $this->http->requests);
    }
    public function testMissingRequiredDiscountProofBlocksCancellationAndSubstituteCleanup(): void
    {
        $this->http->responseFilter = static function (
            ResponseInterface $response,
            RequestInterface $request
        ): ResponseInterface {
            if (
                $request->getMethod() === 'GET'
                && str_ends_with($request->getUri()->getPath(), '/SYNTHETIC_PRIVATE_SUBSCRIPTION')
            ) {
                $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                unset($body['applied_discounts']);
                return SubscriptionWebhookFixtureProvider::json($body);
            }
            return $response;
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertFalse($safe['sdk_pending_verified']);
        self::assertSame(0, $this->http->count('/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel'));
        self::assertSame(0, $this->http->count('/api/v1/billing/prices/SYNTHETIC_PRIVATE_PRICE/update'));
    }

    #[DataProvider('archives')]
    public function testArchivePostFsyncOwnerChangePreventsTheWrite(string $path): void
    {
        $state = new class {
            public bool $changed = false;
        };
        $action = str_contains($path, '/prices/') ? 'archive_price' : 'archive_product';
        $target = substr($path, 0, -7);
        $this->http->responseFilter = static function (
            ResponseInterface $response,
            RequestInterface $request
        ) use (
            $state,
            $target
): ResponseInterface {
            if ($state->changed && $request->getMethod() === 'GET' && $request->getUri()->getPath() === $target) {
                $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                $body['id'] = 'foreign';
                return SubscriptionWebhookFixtureProvider::json($body);
            }
            return $response;
        };
        $safe = $this->observe(static function (string $context, string $directory) use ($state, $action): RunLedger {
            return RunLedger::create($context, $directory, synchronize: static function ($stream) use (
                $state,
                $action
            ): bool {
                $metadata = stream_get_meta_data($stream);
                $path = $metadata['uri'] ?? null;
                if (is_string($path) && str_ends_with($path, 'ledger.json.writing')) {
                    $raw = file_get_contents($path);
                    if (is_string($raw) && str_contains($raw, '"' . $action . '_wire"')) {
                        $state->changed = true;
                    }
                }
                return fsync($stream);
            });
        });
        self::assertTrue($state->changed);
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::assertSame(0, $this->http->count($path));
    }
    public function testLiteralEmptyDiscountObjectCannotAuthorizeSubscriptionCancellation(): void
    {
        $this->http->responseFilter = static function (
            ResponseInterface $response,
            RequestInterface $request
        ): ResponseInterface {
            if (
                $request->getMethod() === 'GET'
                && str_ends_with($request->getUri()->getPath(), '/SYNTHETIC_PRIVATE_SUBSCRIPTION')
            ) {
                return $response->withBody(\GuzzleHttp\Psr7\Utils::streamFor(str_replace(
                    '"applied_discounts":[]',
                    '"applied_discounts":{}',
                    (string) $response->getBody()
                )));
            }
            return $response;
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertSame(0, $this->http->count('/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel'));
    }
    public function testCompleteControllerWith16ObservationsStaysBelow64KiB(): void
    {
        $this->http->responseFilter = function (
            ResponseInterface $response,
            RequestInterface $request
        ): ResponseInterface {
            $path = $request->getUri()->getPath();
            if (
                in_array($path, ['/api/v1/billing/subscriptions/create',
                '/api/v1/billing/subscriptions/SYNTHETIC_PRIVATE_SUBSCRIPTION/cancel'], true)
            ) {
                $rawBody = (string) $response->getBody();
                $body = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                self::assertNotNull($this->runtime);
                for ($index = 0; $index < 7; ++$index) {
                    $this->runtime->capture($body, str_ends_with($path, '/create')
                        ? 'subscription.created' : 'subscription.cancelled');
                }
                return $response->withBody(\GuzzleHttp\Psr7\Utils::streamFor($rawBody));
            }
            return $response;
        };
        $safe = $this->observe();
        self::assertSame('pass', $safe['result'], json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertIsArray($safe['delivery']);
        self::assertSame(16, $safe['delivery']['captured_count']);
        $raw = json_encode($safe, JSON_THROW_ON_ERROR);
        self::assertLessThan(65536, strlen($raw));
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $raw);
    }
}
