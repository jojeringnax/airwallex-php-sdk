<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingWebhookApi;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingWebhookProbe;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryApi;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryEvents;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryStore;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryTransport;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookManagementProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class BillingWebhookDefaultLoginTest extends TestCase
{
    private string $directory;
    private BillingWebhookFixtureProvider $http;
    private ?WebhookDeliveryFixtureRuntime $runtime = null;
    /** @var (Closure(WebhookDeliveryFixtureRuntime):void)|null */
    private ?Closure $configure = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sdk-billing-default-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/credentials', "AIRWALLEX_CLIENT_ID=SYNTHETIC_PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=SYNTHETIC_PRIVATE_KEY\n"
            . "AIRWALLEX_ACCOUNT_ID=SYNTHETIC_PRIVATE_ACCOUNT\n");
        chmod($this->directory . '/credentials', 0600);
        $this->http = new BillingWebhookFixtureProvider(true);
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
    private function observe(
        ?Closure $ledgers = null,
        ?Closure $stores = null,
        WebhookManagementProfile $managementProfile = WebhookManagementProfile::DefaultLogin,
    ): array {
        return BillingWebhookProbe::run($this->options(), $this->http, function (array $config) {
            $runtime = new WebhookDeliveryFixtureRuntime($config);
            $this->runtime = $runtime;
            $this->http->runtime = $runtime;
            if ($this->configure !== null) {
                ($this->configure)($runtime);
            }
            return $runtime;
        }, static fn (): int => WebhookDeliveryFixtureRuntime::NOW, static function (int $milliseconds): void {
        }, $ledgers, $stores, managementProfile: $managementProfile);
    }

    private function trustedOwner(): void
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
                return $event;
            };
        };
    }

    /** @param array<string,mixed> $safe */
    private static function safe(array $safe): void
    {
        self::assertSame('billing-webhook-default-pair', $safe['scenario']);
        self::assertSame('DEFAULT', $safe['management_profile']);
        self::assertSame('default-login', $safe['management_context']);
        self::assertSame('organization', $safe['producer_context']);
        self::assertSame('account', $safe['collector_context']);
        self::assertFalse($safe['organization_manager_verified']);
        self::assertFalse($safe['financial_completion_verified']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('_configured_manager', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testFreshPairSeparatesAllThreeTokensAndPreservesNativeOwnerGates(): void
    {
        $this->trustedOwner();
        $safe = $this->observe();
        self::assertSame('pass', $safe['result']);
        foreach (
            ['signed_resource_pair_observed', 'selected_envelope_pair_verified', 'typed_owner_verified',
            'sdk_active_verified', 'sdk_cancelled_verified', 'registration_deleted'] as $key
        ) {
            self::assertTrue($safe[$key]);
        }
        self::assertFalse($safe['reconciliation_required']);
        $logins = [];
        foreach ($this->http->requests as $request) {
            $path = $request->getUri()->getPath();
            if ($path === '/api/v1/authentication/login') {
                $logins[] = $request->getHeaderLine('x-login-as');
                continue;
            }
            $token = $path === '/api/v1/account' ? 'SYNTHETIC_PRIVATE_ACCOUNT_TOKEN'
                : (str_starts_with($path, '/api/v1/webhooks/')
                    ? 'SYNTHETIC_PRIVATE_DEFAULT_TOKEN' : 'SYNTHETIC_PRIVATE_ORG_TOKEN');
            self::assertSame('Bearer ' . $token, $request->getHeaderLine('Authorization'));
            self::assertFalse($request->hasHeader('x-login-as'));
            self::assertFalse($request->hasHeader('x-org-id'));
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
            self::assertNotSame('/api/v1/webhooks', $path);
        }
        self::assertSame(['SYNTHETIC_PRIVATE_ACCOUNT', '', ''], $logins);
        self::assertSame(1, $this->http->count('/api/v1/webhooks/create'));
        self::assertSame(1, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
        self::assertSame(1, $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT/cancel'));
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
        self::safe($safe);
    }

    public function testHeaderOmissionAndMatchingMacNeverSubstituteForMissingOrganizationReference(): void
    {
        $safe = $this->observe();
        self::assertSame('pending', $safe['result']);
        self::assertTrue($safe['signed_resource_pair_observed']);
        self::assertFalse($safe['typed_owner_verified']);
        self::assertTrue($safe['registration_deleted']);
        self::safe($safe);
    }

    public function testNoDeliveryStillCleansOnlyItsFreshOwnedResources(): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->emit = false;
        };
        $safe = $this->observe();
        self::assertSame('pending', $safe['result']);
        self::assertFalse($safe['signed_resource_pair_observed']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['registration_deleted']);
        self::safe($safe);
    }

    public function testAccountProfileLedgerCannotAuthorizeDefaultManagement(): void
    {
        $configuration = Configuration::load('sandbox', $this->directory . '/credentials');
        $account = BillingWebhookApi::contexts($configuration);
        $default = BillingWebhookApi::contexts($configuration, WebhookManagementProfile::DefaultLogin);
        foreach (['producer', 'collector', 'organization_reference'] as $key) {
            self::assertSame($account[$key], $default[$key]);
        }
        self::assertNotSame($account['management'], $default['management']);
        self::assertNotSame($default['management'], $default['producer']);
        self::assertNotSame($account['composite'], $default['composite']);
        $safe = $this->observe(ledgers: fn (string $fingerprint, string $directory): RunLedger =>
            RunLedger::create($account['composite'], $directory));
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $this->http->requests);
        self::assertNull($this->runtime);
        self::safe($safe);
    }

    public function testDefaultProfileAndTransportMustAgreeBeforeAuthentication(): void
    {
        $transport = new WebhookDeliveryTransport('SYNTHETIC_PRIVATE_ACCOUNT', $this->http, managementOnly: true);
        $this->expectException(ProtocolException::class);
        try {
            new WebhookDeliveryApi(
                Configuration::load('sandbox', $this->directory . '/credentials'),
                $transport,
                WebhookDeliveryEvents::BillingCheckout,
                WebhookManagementProfile::DefaultLogin
            );
        } finally {
            self::assertSame([], $this->http->requests);
        }
    }

    /** @return iterable<string,array{int,string}> */
    public static function failedRegistrations(): iterable
    {
        yield 'definite400 remains unknown' => [400, '{"code":"invalid_argument"}'];
        yield 'unexpected success' => [200, '{}'];
        yield 'missing ID' => [201, '{"url":"SYNTHETIC_PRIVATE_URL","version":"2026-08-21",'
            . '"events":["billing_checkout.created","billing_checkout.cancelled"],'
            . '"secret":"SYNTHETIC_PRIVATE_SECRET","request_id":"SYNTHETIC_PRIVATE_REQUEST"}'];
        yield 'escaped duplicate ID' => [201, '{"id":"A","i\\u0064":"B"}'];
        yield 'native numeric ID' => [201, '{"id":123}'];
    }

    #[DataProvider('failedRegistrations')]
    public function testNoDurableOwnedRegistrationNeverStartsProducerOrGuessesDelete(int $status, string $body): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            $request->getUri()->getPath() === '/api/v1/webhooks/create' ? new Response($status, [], $body) : null;
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['registration_outcome_unknown']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(1, $this->http->count('/api/v1/webhooks/create'));
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_customers/create'));
        self::assertSame(0, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
        self::safe($safe);
    }

    public function testRegistrationSecretPersistenceFailureCannotArmOrDelete(): void
    {
        $safe = $this->observe(stores: static function (string $directory, RunLedger $ledger): WebhookDeliveryStore {
            $writing = $directory . '/' . $ledger->runId() . '/registration.json.writing';
            return new WebhookDeliveryStore($directory, $ledger, static fn (): bool => !file_exists($writing));
        });
        self::assertTrue($safe['registration_outcome_unknown']);
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_customers/create'));
        self::assertSame(0, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
        self::safe($safe);
    }

    public function testWireIntentAndPrivateReturnedSecretPrecedeEveryDependentProducer(): void
    {
        $this->http->intercept = function (RequestInterface $request): ?ResponseInterface {
            $path = $request->getUri()->getPath();
            if (!in_array($path, ['/api/v1/webhooks/create', '/api/v1/billing/billing_customers/create'], true)) {
                return null;
            }
            $paths = glob($this->directory . '/sdk-validation-*/ledger.json') ?: [];
            self::assertCount(1, $paths);
            $raw = file_get_contents($paths[0]);
            self::assertIsString($raw);
            $ledger = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($ledger);
            self::assertIsArray($ledger['observations']);
            if ($path === '/api/v1/webhooks/create') {
                self::assertIsArray($ledger['observations']['registration_create_wire']);
                self::assertSame(
                    hash('sha256', (string) $request->getBody()),
                    $ledger['observations']['registration_create_wire']['wire_sha256']
                );
            } else {
                $snapshot = dirname($paths[0]) . '/registration.json';
                self::assertSame(0600, fileperms($snapshot) & 0777);
                $secret = file_get_contents($snapshot);
                self::assertIsString($secret);
                self::assertStringContainsString('SYNTHETIC_PRIVATE_SECRET', $secret);
                self::assertStringContainsString('SYNTHETIC_PRIVATE_WEBHOOK', $secret);
            }
            return null;
        };
        $safe = $this->observe();
        self::assertTrue($safe['registration_created']);
        self::assertTrue($safe['registration_deleted']);
        self::safe($safe);
    }

    public function testInitialDurabilityFailurePreventsAllAuthenticationAndReceiverWork(): void
    {
        $safe = $this->observe(ledgers: static fn (string $fingerprint, string $directory): RunLedger =>
            RunLedger::create($fingerprint, $directory, synchronize: static fn (): bool => false));
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $this->http->requests);
        self::assertNull($this->runtime);
        self::safe($safe);
    }

    public function testProducerFailureStillDeletesOnlyTheDurablyRecordedRegistration(): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            $request->getUri()->getPath() === '/api/v1/billing/billing_customers/create'
                ? new Response(400, [], '{"code":"invalid_argument"}') : null;
        $safe = $this->observe();
        self::assertTrue($safe['registration_deleted']);
        self::assertTrue($safe['producer_outcome_unknown']);
        self::assertSame(1, $this->http->count('/api/v1/billing/billing_customers/create'));
        self::assertSame(0, $this->http->count('/api/v1/billing/billing_checkouts/create'));
        self::safe($safe);
    }

    public function testPossiblySentCancelNeverReplaysAndRegistrationCleanupRemainsIndependent(): void
    {
        $this->http->intercept = static function (RequestInterface $request): ?ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/cancel')) {
                throw new RuntimeException('SYNTHETIC_PRIVATE_AMBIGUOUS');
            }
            return null;
        };
        $safe = $this->observe();
        self::assertTrue($safe['producer_outcome_unknown']);
        self::assertTrue($safe['registration_deleted']);
        self::assertSame(1, $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT/cancel'));
        self::safe($safe);
    }

    public function testPossiblySentDeleteNeverRetries(): void
    {
        $this->http->intercept = static function (RequestInterface $request): ?ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/delete')) {
                throw new RuntimeException('SYNTHETIC_PRIVATE_AMBIGUOUS');
            }
            return null;
        };
        $safe = $this->observe();
        self::assertTrue($safe['reconciliation_required']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::assertFalse($safe['registration_deleted']);
        self::assertSame(1, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
        self::safe($safe);
    }

    #[DataProviderExternal(BillingWebhookProbeTest::class, 'conflictingTrustedOwner')]
    public function testDefaultDoesNotRelaxTheExistingTypedCallbackOwnerRule(string $field, mixed $value): void
    {
        $this->trustedOwner();
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime) use ($field, $value): void {
            $runtime->eventFilter = static function (array $event) use ($field, $value): array {
                unset($event['account_id']);
                return array_replace($event, ['org_id' => 'SYNTHETIC_PRIVATE_ORG'], [$field => $value]);
            };
        };
        $safe = $this->observe();
        self::assertNotSame('pass', $safe['result']);
        self::assertFalse($safe['typed_owner_verified']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['registration_deleted']);
        self::safe($safe);
    }

    /** @return iterable<string,array{mixed}> */
    public static function wrongCallbackVersions(): iterable
    {
        yield 'different version' => ['2024-06-14'];
        yield 'missing version' => [null];
        yield 'numeric version' => [20260821];
    }

    #[DataProvider('wrongCallbackVersions')]
    public function testDefaultCannotTurnUnknownCallbackVersionIntoASelectedPair(mixed $version): void
    {
        $this->trustedOwner();
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime) use ($version): void {
            $runtime->eventFilter = static function (array $event) use ($version): array {
                unset($event['account_id']);
                $event['org_id'] = 'SYNTHETIC_PRIVATE_ORG';
                if ($version === null) {
                    unset($event['version']);
                } else {
                    $event['version'] = $version;
                }
                return $event;
            };
        };
        $safe = $this->observe();
        self::assertNotSame('pass', $safe['result']);
        self::assertFalse($safe['version_pair_verified']);
        self::assertTrue($safe['registration_deleted']);
        self::assertTrue($safe['sdk_cancelled_verified']);
        self::safe($safe);
    }

    /** @return iterable<string,array{WebhookManagementProfile,bool,mixed,bool}> */
    public static function terminalUrls(): iterable
    {
        foreach (WebhookManagementProfile::cases() as $profile) {
            foreach (
                ['omitted' => [false, null, true], 'null compatibility' => [true, null, true],
                'stale URL' => [true, 'https://checkout.sandbox.airwallex.com/pay?s=SYNTHETIC_PRIVATE_STALE', false],
                'empty string' => [true, '', false], 'boolean' => [true, false, false],
                'number' => [true, 0, false], 'array' => [true, [], false],
                'object' => [true, new \stdClass(), false]] as $label => $case
            ) {
                yield $profile->value . ' ' . $label => [$profile, $case[0], $case[1], $case[2]];
            }
        }
    }

    #[DataProvider('terminalUrls')]
    public function testBothProfilesRequireNoPresentUrlAfterOwnedCancellation(
        WebhookManagementProfile $profile,
        bool $present,
        mixed $url,
        bool $accepted,
    ): void {
        $this->http = new BillingWebhookFixtureProvider($profile === WebhookManagementProfile::DefaultLogin);
        $this->trustedOwner();
        $this->http->cancelledProjection = static fn (array $checkout): array =>
            self::terminalFixture($checkout, 'url', $present, $url);
        $safe = $this->observe(managementProfile: $profile);
        self::assertSame($profile->scenario(), $safe['scenario']);
        self::assertSame($accepted ? 'pass' : 'fail', $safe['result']);
        self::assertSame($accepted, $safe['sdk_cancelled_verified']);
        self::assertSame(!$accepted, $safe['reconciliation_required']);
        self::assertTrue($safe['sdk_active_verified']);
        self::assertTrue($safe['registration_deleted']);
        self::assertSame(1, $this->http->count('/api/v1/billing/billing_checkouts/create'));
        self::assertSame(1, $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT/cancel'));
        self::assertSame(1, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{WebhookManagementProfile,string,bool,mixed}> */
    public static function terminalFieldMismatches(): iterable
    {
        foreach (WebhookManagementProfile::cases() as $profile) {
            foreach (
                ['id' => 'SYNTHETIC_PRIVATE_FOREIGN', 'billing_customer_id' => 'SYNTHETIC_PRIVATE_FOREIGN',
                'legal_entity_id' => 'SYNTHETIC_PRIVATE_FOREIGN',
                'linked_payment_account_id' => 'SYNTHETIC_PRIVATE_FOREIGN', 'mode' => 'PAYMENT',
                'ui_mode' => 'ELEMENTS', 'currency' => 'HKD', 'metadata' => ['sdk_validation_run' => 'foreign'],
                'status' => 'COMPLETED'] as $field => $wrong
            ) {
                yield $profile->value . ' wrong ' . $field => [$profile, $field, true, $wrong];
                yield $profile->value . ' missing ' . $field => [$profile, $field, false, null];
            }
            yield $profile->value . ' still ACTIVE' => [$profile, 'status', true, 'ACTIVE'];
            yield $profile->value . ' unknown state' => [$profile, 'status', true, 'FUTURE'];
        }
    }

    #[DataProvider('terminalFieldMismatches')]
    public function testOmittedUrlCannotReplaceAnyTerminalOwnerResourceOrStateProof(
        WebhookManagementProfile $profile,
        string $field,
        bool $present,
        mixed $value,
    ): void {
        $this->http = new BillingWebhookFixtureProvider($profile === WebhookManagementProfile::DefaultLogin);
        $this->trustedOwner();
        $this->http->cancelledProjection = static fn (array $checkout): array =>
            self::terminalFixture($checkout, $field, $present, $value);
        $safe = $this->observe(managementProfile: $profile);
        self::assertSame('fail', $safe['result']);
        self::assertFalse($safe['sdk_cancelled_verified']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertTrue($safe['registration_deleted']);
        self::assertSame(1, $this->http->count('/api/v1/billing/billing_checkouts/SYNTHETIC_PRIVATE_CHECKOUT/cancel'));
        self::assertSame(1, $this->http->count('/api/v1/webhooks/SYNTHETIC_PRIVATE_WEBHOOK/delete'));
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<array-key,mixed> $checkout
     * @return array<string,mixed>
     */
    private static function terminalFixture(array $checkout, string $field, bool $present, mixed $value): array
    {
        $proof = [];
        foreach ($checkout as $key => $entry) {
            if (!is_string($key)) {
                throw new RuntimeException('The synthetic Checkout requires named fields.');
            }
            $proof[$key] = $entry;
        }
        if ($present) {
            $proof[$field] = $value;
        } else {
            unset($proof[$field]);
        }
        return $proof;
    }

    public function testActiveHelperMatchingRetainsItsExistingUrlAndFieldBehavior(): void
    {
        $expected = ['id' => 'SYNTHETIC_PRIVATE_CHECKOUT', 'billing_customer_id' => 'SYNTHETIC_PRIVATE_CUSTOMER',
            'legal_entity_id' => 'SYNTHETIC_PRIVATE_ENTITY', 'linked_payment_account_id' => 'SYNTHETIC_PRIVATE_ACCOUNT',
            'mode' => 'SETUP', 'ui_mode' => 'HOSTED', 'currency' => 'EUR', 'status' => 'ACTIVE',
            'metadata' => ['sdk_validation_run' => 'SYNTHETIC_PRIVATE_MARKER']];
        foreach ([$expected, $expected + ['url' => null], $expected + ['url' => 'SYNTHETIC_PRIVATE_URL']] as $active) {
            self::assertTrue(BillingWebhookApi::checkout($active, $expected));
            foreach (array_keys($expected) as $field) {
                $missing = $active;
                unset($missing[$field]);
                self::assertFalse(BillingWebhookApi::checkout($missing, $expected));
            }
        }
    }
}
