<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingWebhookApi;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingWebhookProbe;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Jojeringnax\Airwallex\Webhook\WebhookSchema;

final class BillingWebhookProbeTest extends TestCase
{
    private string $directory;
    private BillingWebhookFixtureProvider $http;
    private ?WebhookDeliveryFixtureRuntime $runtime;
    /** @var (Closure(WebhookDeliveryFixtureRuntime):void)|null */
    private ?Closure $configure;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sdk-billing-webhook-php-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/credentials', "AIRWALLEX_CLIENT_ID=SYNTHETIC_PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=SYNTHETIC_PRIVATE_KEY\n"
            . "AIRWALLEX_ACCOUNT_ID=SYNTHETIC_PRIVATE_ACCOUNT\n");
        chmod($this->directory . '/credentials', 0600);
        $this->http = new BillingWebhookFixtureProvider();
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
            'cloudflared-bin' => '/SYNTHETIC_PRIVATE_NOT_EXECUTED', 'create-one-billing-webhook-and-checkout' => true];
    }

    /**
     * @param (Closure(string,string):RunLedger)|null $ledgers
     * @param (Closure(string,RunLedger):WebhookDeliveryStore)|null $stores
     * @return array<string,mixed>
     */
    private function observe(?Closure $ledgers = null, ?Closure $stores = null): array
    {
        return BillingWebhookProbe::run($this->options(), $this->http, function (array $config) {
            $runtime = new WebhookDeliveryFixtureRuntime($config);
            $this->runtime = $runtime;
            $this->http->runtime = $runtime;
            if ($this->configure !== null) {
                ($this->configure)($runtime);
            }
            return $runtime;
        }, static fn (): int => WebhookDeliveryFixtureRuntime::NOW, static function (int $milliseconds): void {
        }, $ledgers, $stores);
    }

    public function testSignedPairKeepsAccountAndOrganizationRolesAndUnknownOwnerProofSeparate(): void
    {
        $safe = $this->observe();
        self::assertSame('pending', $safe['result'], json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertTrue($safe['signed_resource_pair_observed']);
        self::assertTrue($safe['version_pair_verified']);
        self::assertTrue($safe['sdk_active_verified']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['registration_deleted']);
        self::assertFalse($safe['reconciliation_required']);
        self::assertFalse($safe['typed_owner_verified']);
        self::assertFalse($safe['organization_manager_verified']);
        self::assertFalse($safe['financial_completion_verified']);
        self::assertSame('unavailable', $safe['organization_comparison']);
        self::assertIsArray($safe['delivery']);
        self::assertSame(2, $safe['delivery']['mac_verified_count']);
        self::assertSame(2, $safe['delivery']['receipt_freshness_verified_count']);
        self::assertSame(2, $safe['delivery']['current_freshness_verified_count']);
        self::assertIsArray($safe['context_fingerprints']);
        self::assertNotSame($safe['context_fingerprints']['management'], $safe['context_fingerprints']['producer']);
        foreach ($this->http->requests as $request) {
            $path = $request->getUri()->getPath();
            self::assertSame('https', $request->getUri()->getScheme());
            self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
            self::assertFalse($request->hasHeader('x-org-id'));
            self::assertFalse($request->hasHeader('Cookie'));
            self::assertSame('2026-08-21', $request->getHeaderLine('x-api-version'));
            if ($path === '/api/v1/authentication/login') {
                continue;
            }
            self::assertSame(
                str_starts_with($path, '/api/v1/billing/')
                ? 'Bearer SYNTHETIC_PRIVATE_ORG_TOKEN' : 'Bearer SYNTHETIC_PRIVATE_ACCOUNT_TOKEN',
                $request->getHeaderLine('Authorization')
            );
            self::assertFalse($request->hasHeader('x-login-as'));
            if (str_ends_with($path, '/cancel') || str_ends_with($path, '/delete')) {
                self::assertSame('', (string) $request->getBody());
            }
        }
        self::assertSame(1, $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT/cancel'));
        self::assertSame(1, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testNoDeliveryRemainsPendingAndStillCancelsOwnCheckoutAndDeletesRegistration(): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->emit = false;
        };
        $safe = $this->observe();
        self::assertSame('pending', $safe['result']);
        self::assertFalse($safe['signed_resource_pair_observed']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['registration_deleted']);
    }

    public function testAccountPrerequisiteFailureDoesNotStartReceiverOrCreateRegistration(): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            $request->getUri()->getPath() === '/api/v1/account'
                ? BillingWebhookFixtureProvider::json(['id' => 'foreign']) : null;
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertNull($this->runtime);
        self::assertSame(0, $this->http->count('/api/v1/webhooks/create'));
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_customers/create'));
    }

    public function testUnknownRegistrationCreateStopsTriggersAndNeverGuessesDelete(): void
    {
        $this->http->intercept = static function (RequestInterface $request): ?ResponseInterface {
            if ($request->getUri()->getPath() === '/api/v1/webhooks/create') {
                throw new RuntimeException('SYNTHETIC_PRIVATE_AMBIGUOUS');
            }
            return null;
        };
        $safe = $this->observe();
        self::assertTrue($safe['registration_outcome_unknown']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(1, $this->http->count('/api/v1/webhooks/create'));
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_customers/create'));
        self::assertSame(0, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function malformedRegistration(): iterable
    {
        yield 'dot' => ['id', '..'];
        yield 'slash' => ['id', 'foreign/id'];
        yield 'version' => ['version', '2020-01-01'];
        yield 'foreign destination' => ['url', 'https://foreign.invalid/private'];
        yield 'wrong pair' => ['events', ['payment_intent.created', 'payment_intent.cancelled']];
        yield 'missing secret' => ['secret', ''];
        yield 'wrong request' => ['request_id', 'foreign'];
    }

    #[DataProvider('malformedRegistration')]
    public function testMalformedRegistrationCannotSelectAnyFollowup(string $field, mixed $value): void
    {
        $this->http->intercept = static function (RequestInterface $request) use ($field, $value): ?ResponseInterface {
            if ($request->getUri()->getPath() !== '/api/v1/webhooks/create') {
                return null;
            }
            $body = (new JsonCodec())->decodeObject((string) $request->getBody());
            return BillingWebhookFixtureProvider::json(array_replace($body, ['id' => 'SYNTHETIC_PRIVATE_WEBHOOK',
                'secret' => 'SYNTHETIC_PRIVATE_SECRET'], [$field => $value]), 201);
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['registration_outcome_unknown']);
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_customers/create'));
        self::assertSame(0, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function wrongCheckout(): iterable
    {
        yield 'buyer' => ['billing_customer_id', 'foreign'];
        yield 'entity' => ['legal_entity_id', 'foreign'];
        yield 'collector' => ['linked_payment_account_id', 'foreign'];
        yield 'mode' => ['mode', 'PAYMENT'];
        yield 'UI' => ['ui_mode', 'ELEMENTS'];
        yield 'currency' => ['currency', 'HKD'];
        yield 'run' => ['metadata', ['sdk_validation_run' => 'foreign']];
        yield 'state' => ['status', 'OPEN'];
    }

    #[DataProvider('wrongCheckout')]
    public function testOwnershipFailureNeverAuthorizesCancellation(string $field, mixed $value): void
    {
        $this->http->intercept = static function (RequestInterface $request) use ($field, $value): ?ResponseInterface {
            if ($request->getUri()->getPath() !== '/api/v1/billing/billing_checkouts/create') {
                return null;
            }
            $body = (new JsonCodec())->decodeObject((string) $request->getBody());
            return BillingWebhookFixtureProvider::json(array_replace(
                $body,
                ['id' => 'SYNTHETIC_PRIVATE_CHECKOUT', 'status' => 'ACTIVE'],
                [$field => $value]
            ), 201);
        };
        $safe = $this->observe();
        self::assertFalse($safe['sdk_active_verified']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertTrue($safe['registration_deleted']);
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT/cancel'));
        self::assertSame(
            0,
            $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT', 'GET')
        );
    }

    public function testAmbiguousCancelCannotReplayOrGuessAnotherAction(): void
    {
        $this->http->intercept = static function (RequestInterface $request): ?ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/cancel')) {
                throw new RuntimeException('SYNTHETIC_PRIVATE_SEND_UNKNOWN');
            }
            return null;
        };
        $safe = $this->observe();
        self::assertTrue($safe['producer_outcome_unknown']);
        self::assertFalse($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['registration_deleted']);
        self::assertSame(1, $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT/cancel'));
        self::assertSame(1, $this->http->count('/api/v1/billing/billing_checkouts/create'));
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testRegistrationPersistenceFailureNeverTriggersOrGuessesDelete(): void
    {
        $calls = 0;
        $safe = $this->observe(stores: static function (string $directory, RunLedger $ledger) use (&$calls) {
            return new WebhookDeliveryStore($directory, $ledger, static function () use (&$calls): bool {
                return ++$calls !== 7; // registration file sync, after three exclusive private files.
            });
        });
        self::assertTrue($safe['registration_outcome_unknown']);
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_customers/create'));
        self::assertSame(0, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
    }

    public function testInitialDurabilityFailurePreventsAuthenticationAndReceiver(): void
    {
        $safe = $this->observe(ledgers: static fn (string $context, string $directory): RunLedger =>
            RunLedger::create($context, $directory, synchronize: static fn (): bool => false));
        self::assertSame('fail', $safe['result']);
        self::assertCount(0, $this->http->requests);
        self::assertNull($this->runtime);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function unknownEnvelope(): iterable
    {
        yield 'no version' => ['version', null];
        yield 'old version' => ['version', '2020-01-01'];
        yield 'unknown org' => ['org_id', 'SYNTHETIC_PRIVATE_ORG'];
        yield 'wrong org type' => ['org_id', ['SYNTHETIC_PRIVATE']];
        yield 'wrong account' => ['account_id', 'SYNTHETIC_PRIVATE_FOREIGN'];
        yield 'empty account' => ['account_id', ''];
        yield 'unknown event' => ['name', 'SYNTHETIC_PRIVATE_EVENT_NAME'];
    }

    #[DataProvider('unknownEnvelope')]
    public function testUnknownEnvelopeFactsStayClosedWithoutManufacturingOwnerProof(string $field, mixed $value): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime) use ($field, $value): void {
            $runtime->eventFilter = static fn (array $event): array => array_replace($event, [$field => $value]);
        };
        $safe = $this->observe();
        self::assertSame('pending', $safe['result']);
        self::assertFalse($safe['typed_owner_verified']);
        self::assertFalse($safe['organization_manager_verified']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['registration_deleted']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testDirectBillingShapeIsPreservedAndMissingVersionIsNotSuppliedFromRegistration(): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->eventFilter = static function (array $event): array {
                $data = $event['data'];
                if (!is_array($data)) {
                    throw new RuntimeException('Invalid synthetic event.');
                }
                $event['data'] = $data['object'];
                unset($event['version']);
                return $event;
            };
        };
        $safe = $this->observe();
        self::assertTrue($safe['signed_resource_pair_observed']);
        self::assertFalse($safe['version_pair_verified']);
        self::assertIsArray($safe['delivery']);
        self::assertIsArray($safe['delivery']['events']);
        self::assertIsArray($safe['delivery']['events'][0]);
        self::assertSame('direct', $safe['delivery']['events'][0]['shape']);
        self::assertSame('absent', $safe['delivery']['events'][0]['version']);
    }

    public function testInvalidMacToxicJsonIsNeverParsed(): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->bodyFilter = static fn (string $body): string => 'SYNTHETIC_PRIVATE_TOXIC_JSON';
            $runtime->metadataFilter = self::corruptSignature(...);
        };
        $safe = $this->observe();
        self::assertFalse($safe['signed_resource_pair_observed']);
        self::assertIsArray($safe['delivery']);
        self::assertSame(2, $safe['delivery']['invalid_signature_count']);
        self::assertSame(0, $safe['delivery']['unrecognized_or_foreign_count']);
        self::assertTrue($safe['registration_deleted']);
    }
    public function testEveryWireMutationAndBothLoginRolesHaveDurableCompositeProvenance(): void
    {
        $mutations = 0;
        $roles = [];
        $this->http->intercept = function (RequestInterface $request) use (&$mutations, &$roles): ?ResponseInterface {
            $path = $request->getUri()->getPath();
            $paths = glob($this->directory . '/sdk-validation-*/ledger.json');
            self::assertIsArray($paths);
            $states = [];
            foreach ($paths as $file) {
                $raw = file_get_contents($file);
                self::assertIsString($raw);
                $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                $states[] = $decoded;
            }
            self::assertNotEmpty($states);
            if ($path === '/api/v1/authentication/login') {
                $roles[] = $request->hasHeader('x-login-as') ? 'account' : 'organization';
                self::assertIsArray($states[0]['actions']);
                self::assertArrayHasKey('billing_webhook_provenance', $states[0]['actions']);
                return null;
            }
            if ($request->getMethod() !== 'POST') {
                return null;
            }
            ++$mutations;
            $hash = hash('sha256', (string) $request->getBody());
            $target = hash('sha256', $path);
            $found = false;
            foreach ($states as $state) {
                self::assertIsArray($state['observations']);
                foreach ($state['observations'] as $observation) {
                    self::assertIsArray($observation);
                    if (
                        ($observation['wire_sha256'] ?? null) === $hash
                        && ($observation['target_sha256'] ?? null) === $target
                    ) {
                        $found = true;
                    }
                }
            }
            self::assertTrue($found, 'A wire mutation was invoked before exact durable provenance.');
            return null;
        };
        $safe = $this->observe();
        self::assertSame(5, $mutations);
        self::assertSame(['account', 'account', 'organization'], $roles);
        self::assertTrue($safe['registration_deleted']);
    }

    public function testConflictingSignedEventIdentityCannotProveThePair(): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->eventFilter = static fn (array $event): array => array_replace(
                $event,
                ['id' => 'SYNTHETIC_PRIVATE_SHARED_EVENT']
            );
        };
        $safe = $this->observe();
        self::assertFalse($safe['signed_resource_pair_observed']);
        self::assertFalse($safe['version_pair_verified']);
        self::assertIsArray($safe['delivery']);
        self::assertSame(1, $safe['delivery']['conflicting_event_count']);
        self::assertTrue($safe['sdk_cancelled_verified']);
    }

    public function testReceiverFailureAfterOwnActiveReadUsesFreshGuardedCleanupWithoutCallbackActions(): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->eventFilter = static function (array $event) use ($runtime): array {
                $runtime->failPoll = true;
                return $event;
            };
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['sdk_active_verified']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['registration_deleted']);
        self::assertSame(1, $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT/cancel'));
    }

    public function testPostCreateProjectionPersistenceFailureRetainsCheckoutAndDoesNotInventACompletedRun(): void
    {
        $calls = 0;
        $safe = $this->observe(stores: static function (string $directory, RunLedger $ledger) use (&$calls) {
            return new WebhookDeliveryStore($directory, $ledger, static function () use (&$calls): bool {
                return ++$calls !== 9; // first ACTIVE projection file, after registration is durable.
            });
        });
        self::assertTrue($safe['checkout_created']);
        self::assertFalse($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertTrue($safe['registration_deleted']);
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT/cancel'));
    }

    public function testDeletionFailureNeverRetriesAndAlwaysStopsTheOwnedProcesses(): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            str_ends_with($request->getUri()->getPath(), '/delete')
                ? new Response(500, [], '{"code":"unlisted"}') : null;
        $safe = $this->observe();
        self::assertFalse($safe['registration_deleted']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(1, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
    }

    public function testUnknownCheckoutCreateRetainsIntentAndNeverRecreatesOrGuessesCancellation(): void
    {
        $this->http->intercept = static function (RequestInterface $request): ?ResponseInterface {
            if ($request->getUri()->getPath() === '/api/v1/billing/billing_checkouts/create') {
                throw new RuntimeException('SYNTHETIC_PRIVATE_CREATE_UNKNOWN');
            }
            return null;
        };
        $safe = $this->observe();
        self::assertTrue($safe['producer_outcome_unknown']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertTrue($safe['registration_deleted']);
        self::assertSame(1, $this->http->count('/api/v1/billing/billing_checkouts/create'));
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT/cancel'));
    }

    public function testChangedRegistrationProofCannotAuthorizeDeletionOfAnotherConfiguration(): void
    {
        $reads = 0;
        $this->http->intercept = static function (RequestInterface $request) use (&$reads): ?ResponseInterface {
            if (
                $request->getUri()->getPath() === '/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK'
                && ++$reads === 2
            ) {
                return BillingWebhookFixtureProvider::json(['id' => 'SYNTHETIC_PRIVATE_WEBHOOK',
                    'url' => 'https://foreign.invalid/private', 'version' => '2026-08-21',
                    'secret' => 'SYNTHETIC_PRIVATE_SECRET', 'request_id' => 'foreign',
                    'events' => ['billing_checkout.created', 'billing_checkout.cancelled']]);
            }
            return null;
        };
        $safe = $this->observe();
        self::assertFalse($safe['registration_deleted']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(0, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
    }

    public function testLatePrerequisiteStopsReceiverAndProducerAdmissionsWithoutExtendingBudget(): void
    {
        $now = 0.0;
        $this->http->intercept = static function (RequestInterface $request) use (&$now): ?ResponseInterface {
            if ($request->getUri()->getPath() === '/api/v1/account') {
                $now = 220.0;
            }
            return null;
        };
        $called = false;
        $safe = BillingWebhookProbe::run(
            $this->options(),
            $this->http,
            static function (array $config) use (&$called): WebhookDeliveryFixtureRuntime {
                $called = true;
                return new WebhookDeliveryFixtureRuntime($config);
            },
            static fn (): int => WebhookDeliveryFixtureRuntime::NOW,
            elapsed: static function () use (&$now): float {
                return $now;
            }
        );
        self::assertSame('fail', $safe['result']);
        self::assertFalse($called);
        self::assertSame(0, $this->http->count('/api/v1/webhooks/create'));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function badOptions(): iterable
    {
        yield 'production' => ['environment', 'production'];
        yield 'false selection' => ['create-one-billing-webhook-and-checkout', false];
        yield 'string selection' => ['create-one-billing-webhook-and-checkout', 'true'];
        yield 'URL override' => ['url', 'SYNTHETIC_PRIVATE'];
        yield 'event override' => ['events', ['billing_checkout.completed']];
        yield 'organization guess' => ['organization-id', 'SYNTHETIC_PRIVATE'];
        yield 'SDK root override' => ['sdk-root', 'SYNTHETIC_PRIVATE'];
    }

    #[DataProvider('badOptions')]
    public function testInvalidOptionsFailBeforeAnyAuthenticationOrReceiver(string $key, mixed $value): void
    {
        $called = false;
        $safe = BillingWebhookProbe::run(
            array_replace($this->options(), [$key => $value]),
            $this->http,
            static function (array $config) use (&$called): WebhookDeliveryFixtureRuntime {
                $called = true;
                return new WebhookDeliveryFixtureRuntime($config);
            }
        );
        self::assertSame('fail', $safe['result']);
        self::assertFalse($called);
        self::assertCount(0, $this->http->requests);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testProducerUnauthorizedReadCannotDispatchAutomaticLoginOrReadReplay(): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            $request->getUri()->getPath() === '/api/v1/billing/billing_customers/SYNTHETIC_PRIVATE_CUSTOMER'
                ? new Response(401, [], '{"code":"unauthorized"}') : null;
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertSame(
            1,
            $this->http->count('/api/v1/billing/billing_customers/SYNTHETIC_PRIVATE_CUSTOMER', 'GET')
        );
        self::assertSame(3, $this->http->count('/api/v1/authentication/login'));
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_checkouts/create'));
        self::assertTrue($safe['registration_deleted']);
    }

    public function testTrustedExternalOrganizationReferenceCanVerifyOnlyTheScopedGenericPair(): void
    {
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_ORGANIZATION_ID=SYNTHETIC_PRIVATE_ORG\n",
            FILE_APPEND
        );
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->eventFilter = static function (array $event): array {
                unset($event['account_id']);
                $event['org_id'] = 'SYNTHETIC_PRIVATE_ORG';
                self::assertNotEmpty(WebhookSchema::integerPaths($event));
                return $event;
            };
        };
        $safe = $this->observe();
        self::assertSame('pass', $safe['result']);
        self::assertSame('available', $safe['organization_comparison']);
        self::assertTrue($safe['typed_owner_verified']);
        self::assertTrue($safe['selected_envelope_pair_verified']);
        self::assertFalse($safe['organization_manager_verified']);
        self::assertTrue($safe['signed_resource_pair_observed']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function conflictingTrustedOwner(): iterable
    {
        yield 'missing org' => ['org_id', null];
        yield 'empty org' => ['org_id', ''];
        yield 'wrong type org' => ['org_id', ['SYNTHETIC_PRIVATE_ORG']];
        yield 'foreign org' => ['org_id', 'SYNTHETIC_PRIVATE_FOREIGN_ORG'];
        yield 'matching other account still conflicts' => ['account_id', 'SYNTHETIC_PRIVATE_ACCOUNT'];
        yield 'matching legacy still conflicts' => ['accountId', 'SYNTHETIC_PRIVATE_ACCOUNT'];
        yield 'foreign legacy' => ['accountId', 'SYNTHETIC_PRIVATE_FOREIGN_ACCOUNT'];
        yield 'nonstring legacy' => ['accountId', ['SYNTHETIC_PRIVATE_ACCOUNT']];
        yield 'foreign account' => ['account_id', 'SYNTHETIC_PRIVATE_FOREIGN_ACCOUNT'];
        yield 'malformed account' => ['account_id', false];
    }

    #[DataProvider('conflictingTrustedOwner')]
    public function testTrustedReferenceCannotBeReplacedByEnvelopeData(string $field, mixed $value): void
    {
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_ORGANIZATION_ID=SYNTHETIC_PRIVATE_ORG\n",
            FILE_APPEND
        );
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime) use ($field, $value): void {
            $runtime->eventFilter = static function (array $event) use ($field, $value): array {
                unset($event['account_id']);
                $event = array_replace($event, ['org_id' => 'SYNTHETIC_PRIVATE_ORG'], [$field => $value]);
                if (
                    in_array($field, ['account_id', 'accountId'], true)
                    && !in_array($value, [null, ''], true)
                ) {
                    self::assertSame([], WebhookSchema::integerPaths($event));
                }
                return $event;
            };
        };
        $safe = $this->observe();
        self::assertSame('pending', $safe['result']);
        self::assertFalse($safe['typed_owner_verified']);
        self::assertFalse($safe['selected_envelope_pair_verified']);
        self::assertTrue($safe['signed_resource_pair_observed']);
        self::assertTrue($safe['sdk_cancelled_verified']);
    }

    public function testDirectShapeStillCannotClaimGenericEnvelopeAgreementWithMatchingOwner(): void
    {
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_ORGANIZATION_ID=SYNTHETIC_PRIVATE_ORG\n",
            FILE_APPEND
        );
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->eventFilter = static function (array $event): array {
                $data = $event['data'];
                if (!is_array($data)) {
                    throw new RuntimeException('Invalid synthetic data.');
                }
                $event['data'] = $data['object'];
                $event['org_id'] = 'SYNTHETIC_PRIVATE_ORG';
                unset($event['account_id']);
                return $event;
            };
        };
        $safe = $this->observe();
        self::assertSame('pending', $safe['result']);
        self::assertTrue($safe['typed_owner_verified']);
        self::assertTrue($safe['version_pair_verified']);
        self::assertFalse($safe['selected_envelope_pair_verified']);
    }

    public function testMalformedOptionalOrganizationReferenceStopsBeforeAuthentication(): void
    {
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_ORGANIZATION_ID='SYNTHETIC PRIVATE'\n",
            FILE_APPEND
        );
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertCount(0, $this->http->requests);
        self::assertNull($this->runtime);
        self::assertStringNotContainsString('SYNTHETIC PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testOrganizationReferenceBindsCompositeButCannotChangeAuthenticationRoleFingerprints(): void
    {
        $file = $this->directory . '/credentials';
        $original = file_get_contents($file);
        self::assertIsString($original);
        $load = static fn (): Configuration =>
            Configuration::load('sandbox', $file);
        $absent = BillingWebhookApi::contexts($load());
        file_put_contents($file, $original . "AIRWALLEX_ORGANIZATION_ID=SYNTHETIC_PRIVATE_ORG_A\n");
        $first = BillingWebhookApi::contexts($load());
        file_put_contents($file, $original . "AIRWALLEX_ORGANIZATION_ID=SYNTHETIC_PRIVATE_ORG_B\n");
        $second = BillingWebhookApi::contexts($load());
        self::assertSame($first['management'], $second['management']);
        self::assertSame($first['producer'], $second['producer']);
        self::assertNotSame($absent['composite'], $first['composite']);
        self::assertNotSame($first['composite'], $second['composite']);
        self::assertNotSame($first['organization_reference'], $second['organization_reference']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($first, JSON_THROW_ON_ERROR));
    }

    public function testMissingDocumentedOrgCannotBeSuppliedByTheExternalReferenceOrAccount(): void
    {
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_ORGANIZATION_ID=SYNTHETIC_PRIVATE_ORG\n",
            FILE_APPEND
        );
        $safe = $this->observe(); // Synthetic generic events have account_id, but no org_id.
        self::assertSame('pending', $safe['result']);
        self::assertSame('available', $safe['organization_comparison']);
        self::assertFalse($safe['typed_owner_verified']);
        self::assertFalse($safe['selected_envelope_pair_verified']);
        self::assertTrue($safe['signed_resource_pair_observed']);
        self::assertIsArray($safe['delivery']);
        self::assertIsArray($safe['delivery']['events']);
        self::assertIsArray($safe['delivery']['events'][0]);
        self::assertSame('absent', $safe['delivery']['events'][0]['org_id']);
    }

    /** @return iterable<string,array{string,mixed,string}> */
    public static function neutralOtherOwners(): iterable
    {
        yield 'null account' => ['account_id', null, 'wrong_type'];
        yield 'empty account' => ['account_id', '', 'empty'];
        yield 'null legacy' => ['accountId', null, 'wrong_type'];
        yield 'empty legacy' => ['accountId', '', 'empty'];
    }

    #[DataProvider('neutralOtherOwners')]
    public function testSdkNeutralOtherOwnersKeepLiteralDiagnosticsWithoutNormalization(
        string $field,
        mixed $value,
        string $classification,
    ): void {
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_ORGANIZATION_ID=SYNTHETIC_PRIVATE_ORG\n",
            FILE_APPEND
        );
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime) use ($field, $value): void {
            $runtime->eventFilter = static function (array $event) use ($field, $value): array {
                unset($event['account_id']);
                $event = array_replace($event, ['org_id' => 'SYNTHETIC_PRIVATE_ORG'], [$field => $value]);
                if (
                    in_array($field, ['account_id', 'accountId'], true)
                    && !in_array($value, [null, ''], true)
                ) {
                    self::assertSame([], WebhookSchema::integerPaths($event));
                }
                return $event;
            };
        };
        $safe = $this->observe();
        self::assertSame('pass', $safe['result']);
        self::assertTrue($safe['typed_owner_verified']);
        self::assertIsArray($safe['delivery']);
        self::assertIsArray($safe['delivery']['events']);
        self::assertIsArray($safe['delivery']['events'][0]);
        self::assertSame($classification, $safe['delivery']['events'][0][
            $field === 'account_id' ? 'account_id_configured_manager' : 'legacy_accountId']);
    }

    public function testBillingManagementCompositionCannotSendOrBindAnyPaymentsProducerRole(): void
    {
        $api = new BillingWebhookApi(Configuration::load('sandbox', $this->directory . '/credentials'), $this->http);
        try {
            $api->managementTransport->sendRequest(new \GuzzleHttp\Psr7\Request(
                'POST',
                'https://api.sandbox.airwallex.com/api/v1/pa/payment_intents/create',
                ['x-api-version' => '2026-08-21'],
                '{}'
            ));
            self::fail('Billing management acquired Payments producer authority.');
        } catch (\Jojeringnax\Airwallex\Exception\ProtocolException) {
            self::assertCount(0, $this->http->requests);
        }
        $this->expectException(\Jojeringnax\Airwallex\Exception\ProtocolException::class);
        $api->managementTransport->allowIntent('SYNTHETIC_PRIVATE_INTENT');
    }

    public function testClearCheckoutCreateRejectionProjectsOnlyClosedFactsAndNeverGuessesCleanup(): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            $request->getUri()->getPath() === '/api/v1/billing/billing_checkouts/create'
                ? new Response(
                    400,
                    ['Content-Type' => 'application/json'],
                    '{"code":"validation_error","message":"SYNTHETIC_PRIVATE_MESSAGE",'
                    . '"request_id":"SYNTHETIC_PRIVATE_REMOTE_ID"}'
                ) : null;
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertSame('checkout_create', $safe['phase']);
        self::assertSame(400, $safe['http_status']);
        self::assertSame('validation_error', $safe['code']);
        self::assertFalse($safe['checkout_created']);
        self::assertTrue($safe['producer_outcome_unknown']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertTrue($safe['registration_deleted']);
        self::assertSame(1, $this->http->count('/api/v1/billing/billing_checkouts/create'));
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT/cancel'));
        foreach ($this->http->requests as $request) {
            self::assertFalse($request->getMethod() === 'GET'
                && str_starts_with($request->getUri()->getPath(), '/api/v1/billing/billing_checkouts/'));
        }
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,string}> */
    public static function ambiguousRegistrationKeys(): iterable
    {
        yield 'duplicate ID' => ['"id"', 'SYNTHETIC_PRIVATE_WEBHOOK'];
        yield 'duplicate secret' => ['"secret"', 'SYNTHETIC_PRIVATE_SECRET'];
        yield 'escaped secret alias' => ['"s\u0065cret"', 'SYNTHETIC_PRIVATE_SECRET'];
    }

    #[DataProvider('ambiguousRegistrationKeys')]
    public function testDuplicateSuccessfulRegistrationKeysCannotAuthorizeAnyFollowup(string $key, string $value): void
    {
        $this->http->intercept = static function (RequestInterface $request) use ($key, $value): ?ResponseInterface {
            if ($request->getUri()->getPath() !== '/api/v1/webhooks/create') {
                return null;
            }
            $body = (new JsonCodec())->decodeObject((string) $request->getBody());
            $raw = json_encode($body + ['id' => 'SYNTHETIC_PRIVATE_WEBHOOK',
                'secret' => 'SYNTHETIC_PRIVATE_SECRET'], JSON_THROW_ON_ERROR);
            return new Response(
                201,
                ['Content-Type' => 'application/json'],
                substr($raw, 0, -1) . ',' . $key . ':' . json_encode($value, JSON_THROW_ON_ERROR) . '}'
            );
        };
        $safe = $this->observe();
        self::assertFalse($safe['registration_created']);
        self::assertTrue($safe['registration_outcome_unknown']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_customers/create'));
        self::assertSame(0, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK', 'GET'));
        self::assertSame(0, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testDuplicateFreshRegistrationReadCannotAuthorizeDeletionEvenWithIdenticalValues(): void
    {
        $configuration = [];
        $reads = 0;
        $this->http->intercept = static function (
            RequestInterface $request
        ) use (
            &$configuration,
            &$reads
): ?ResponseInterface {
            $path = $request->getUri()->getPath();
            if ($path === '/api/v1/webhooks/create') {
                $configuration = (new JsonCodec())->decodeObject((string) $request->getBody())
                    + ['id' => 'SYNTHETIC_PRIVATE_WEBHOOK', 'secret' => 'SYNTHETIC_PRIVATE_SECRET'];
            }
            if ($path === '/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK' && ++$reads === 2) {
                $raw = json_encode($configuration, JSON_THROW_ON_ERROR);
                return new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    substr($raw, 0, -1) . ',"secret":"SYNTHETIC_PRIVATE_SECRET"}'
                );
            }
            return null;
        };
        $safe = $this->observe();
        self::assertTrue($safe['registration_created']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::assertFalse($safe['registration_deleted']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(0, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
    }

    /** @return iterable<string,array{string}> */
    public static function ambiguousSdkSuccess(): iterable
    {
        yield 'Customer create identity' => ['customer_create'];
        yield 'Customer read entity' => ['customer_read'];
        yield 'Checkout create identity' => ['checkout_create'];
        yield 'Checkout read collector' => ['checkout_read'];
        yield 'Cancelled read identity' => ['cancelled_read'];
    }

    #[DataProvider('ambiguousSdkSuccess')]
    public function testMatchingLastWinsSdkFieldsCannotAuthorizeDependentWork(string $phase): void
    {
        $customer = [];
        $checkout = [];
        $cancelled = false;
        $this->http->intercept = static function (RequestInterface $request) use (
            $phase,
            &$customer,
            &$checkout,
            &$cancelled,
        ): ?ResponseInterface {
            $path = $request->getUri()->getPath();
            $status = 200;
            $data = null;
            $key = 'id';
            $value = 'SYNTHETIC_PRIVATE_CHECKOUT';
            if ($path === '/api/v1/billing/billing_customers/create') {
                $customer = (new JsonCodec())->decodeObject((string) $request->getBody())
                    + ['id' => 'SYNTHETIC_PRIVATE_CUSTOMER'];
                if ($phase === 'customer_create') {
                    $data = $customer;
                    $status = 201;
                    $value = 'SYNTHETIC_PRIVATE_CUSTOMER';
                }
            } elseif (
                $path === '/api/v1/billing/billing_customers/SYNTHETIC_PRIVATE_CUSTOMER'
                && $phase === 'customer_read'
            ) {
                $data = $customer;
                $key = 'default_legal_entity_id';
                $value = 'SYNTHETIC_PRIVATE_ENTITY';
            } elseif ($path === '/api/v1/billing/billing_checkouts/create') {
                $checkout = (new JsonCodec())->decodeObject((string) $request->getBody())
                    + ['id' => 'SYNTHETIC_PRIVATE_CHECKOUT', 'status' => 'ACTIVE', 'url' => null];
                if ($phase === 'checkout_create') {
                    $data = $checkout;
                    $status = 201;
                }
            } elseif (str_ends_with($path, '/cancel')) {
                $cancelled = true;
            } elseif ($path === '/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT') {
                if ($phase === 'checkout_read') {
                    $data = $checkout;
                    $key = 'linked_payment_account_id';
                    $value = 'SYNTHETIC_PRIVATE_ACCOUNT';
                } elseif ($phase === 'cancelled_read' && $cancelled) {
                    $data = array_replace($checkout, ['status' => 'CANCELLED', 'url' => null]);
                }
            }
            if ($data === null) {
                return null;
            }
            $raw = json_encode($data, JSON_THROW_ON_ERROR);
            return new Response($status, ['Content-Type' => 'application/json'], substr($raw, 0, -1)
                . ',' . json_encode($key, JSON_THROW_ON_ERROR) . ':' . json_encode($value, JSON_THROW_ON_ERROR) . '}');
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertFalse($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['registration_deleted']);
        self::assertSame(
            $phase === 'cancelled_read' ? 1 : 0,
            $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT/cancel')
        );
        if (str_starts_with($phase, 'customer_')) {
            self::assertSame(0, $this->http->count('/api/v1/billing/billing_checkouts/create'));
        }
        if ($phase === 'customer_create') {
            self::assertFalse($safe['customer_created']);
            self::assertSame(
                0,
                $this->http->count('/api/v1/billing/billing_customers/SYNTHETIC_PRIVATE_CUSTOMER', 'GET')
            );
        }
        if ($phase === 'checkout_create') {
            self::assertFalse($safe['checkout_created']);
            self::assertSame(
                0,
                $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT', 'GET')
            );
        }
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testAmbiguousLoginCannotAuthorizeAnyPrerequisiteOrProducerMutation(): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            $request->getUri()->getPath() === '/api/v1/authentication/login'
                ? new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    '{"token":"SYNTHETIC_PRIVATE_TOKEN","token":"SYNTHETIC_PRIVATE_TOKEN",'
                    . '"expires_at":"2099-01-01T00:00:00Z"}'
                ) : null;
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertNull($this->runtime);
        self::assertCount(1, $this->http->requests);
        self::assertSame(0, $this->http->count('/api/v1/account', 'GET'));
        self::assertSame(0, $this->http->count('/api/v1/webhooks/create'));
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{bool}> */
    public static function literalLegalEntityReferences(): iterable
    {
        yield 'quoted numeric reference' => [true];
        yield 'raw numeric is not an opaque string' => [false];
    }

    #[DataProvider('literalLegalEntityReferences')]
    public function testAccountLegalEntityProofKeepsRawJsonStringType(bool $quoted): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            $request->getUri()->getPath() === '/api/v1/account'
                ? BillingWebhookFixtureProvider::json(['id' => 'SYNTHETIC_PRIVATE_ACCOUNT',
                    'account_details' => ['legal_entity_id' => $quoted ? '123' : 123]]) : null;
        $safe = $this->observe();
        if ($quoted) {
            self::assertSame('pending', $safe['result']);
            self::assertTrue($safe['sdk_active_verified']);
            self::assertTrue($safe['sdk_cancelled_verified']);
            self::assertTrue($safe['registration_deleted']);
        } else {
            self::assertSame('fail', $safe['result']);
            self::assertNull($this->runtime);
            self::assertSame(0, $this->http->count('/api/v1/webhooks/create'));
            self::assertSame(0, $this->http->count('/api/v1/billing/billing_customers/create'));
        }
    }

    /** @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private static function corruptSignature(array $metadata): array
    {
        return array_replace($metadata, ['signature' => str_repeat('0', 64)]);
    }
}
