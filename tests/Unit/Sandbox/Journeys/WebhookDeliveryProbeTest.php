<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryProbe;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class WebhookDeliveryProbeTest extends TestCase
{
    private string $directory;
    private WebhookDeliveryFixtureProvider $http;
    private ?WebhookDeliveryFixtureRuntime $runtime;
    /** @var (Closure(WebhookDeliveryFixtureRuntime):void)|null */
    private ?Closure $configure;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sdk-webhook-delivery-php-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/credentials', "AIRWALLEX_CLIENT_ID=SYNTHETIC_PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=SYNTHETIC_PRIVATE_KEY\n"
            . "AIRWALLEX_ACCOUNT_ID=SYNTHETIC_PRIVATE_ACCOUNT\n");
        chmod($this->directory . '/credentials', 0600);
        $this->http = new WebhookDeliveryFixtureProvider();
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
            'cloudflared-bin' => '/SYNTHETIC_PRIVATE_NOT_EXECUTED', 'create-one-webhook-and-intent' => true];
    }

    /**
     * @param (Closure(string,string):RunLedger)|null $ledgers
     * @param (Closure(string,RunLedger):WebhookDeliveryStore)|null $stores
     * @return array<string,mixed>
     */
    private function observe(?Closure $ledgers = null, ?Closure $stores = null): array
    {
        return WebhookDeliveryProbe::run($this->options(), $this->http, function (array $config) {
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

    /** @param array<string,mixed> $safe */
    private static function sanitized(array $safe): void
    {
        $json = json_encode($safe, JSON_THROW_ON_ERROR);
        foreach (
            ['SYNTHETIC_PRIVATE', 'trycloudflare.com', 'sdk-webhook-delivery-php-', 'sdk-validation-'] as $private
        ) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertFalse($safe['funds_movement_verified']);
        self::assertFalse($safe['organization_manager_verified']);
    }

    public function testExactOwnCreatedCancelledDeliveryThenDeleteBeforeProcessStop(): void
    {
        $this->configure = function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->onStop = function (): void {
                self::assertTrue($this->http->deleted);
                self::assertSame('CANCELLED', $this->http->intent['status']);
            };
        };
        $safe = $this->observe();
        self::assertSame('pass', $safe['result']);
        self::assertTrue($safe['registration_created']);
        self::assertTrue($safe['registration_deleted']);
        self::assertTrue($safe['intent_cancelled']);
        self::assertTrue($safe['raw_delivery_observed']);
        self::assertTrue($safe['signatures_verified']);
        self::assertTrue($safe['version_owner_verified']);
        self::assertFalse($safe['reconciliation_required']);
        self::assertSame(2, $safe['captured_count']);
        self::assertSame(2, $safe['receipt_freshness_verified_count']);
        self::assertSame(1, $safe['matching_created_count']);
        self::assertSame(1, $safe['matching_cancelled_count']);
        self::assertSame(1, $this->http->sends('/api/v1/webhooks/create'));
        self::assertSame(1, $this->http->sends(
            '/api/v1/webhooks/' . WebhookDeliveryFixtureProvider::WEBHOOK . '/delete'
        ));
        self::assertSame(1, $this->http->sends('/api/v1/pa/payment_intents/create'));
        self::assertSame(1, $this->http->sends(
            '/api/v1/pa/payment_intents/' . WebhookDeliveryFixtureProvider::INTENT . '/cancel'
        ));
        foreach ($this->http->requests as $request) {
            self::assertSame('https', $request->getUri()->getScheme());
            self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
            self::assertSame('2026-08-21', $request->getHeaderLine('x-api-version'));
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
            if (str_ends_with($request->getUri()->getPath(), '/login')) {
                self::assertSame('SYNTHETIC_PRIVATE_ACCOUNT', $request->getHeaderLine('x-login-as'));
            }
            if (str_ends_with($request->getUri()->getPath(), '/delete')) {
                self::assertSame('', (string) $request->getBody());
            }
        }
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
        self::sanitized($safe);
    }

    public function testNoMatchingDeliveryRemainsPendingWhileOwnedCleanupStillCompletes(): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->emit = false;
        };
        $safe = $this->observe();
        self::assertSame('pending', $safe['result']);
        self::assertTrue($safe['intent_cancelled']);
        self::assertTrue($safe['registration_deleted']);
        self::assertFalse($safe['raw_delivery_observed']);
        self::assertFalse($safe['reconciliation_required']);
        self::sanitized($safe);
    }

    public function testUnknownRegistrationCreateNeverTriggersIntentOrGuessesDelete(): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            $request->getUri()->getPath() === '/api/v1/webhooks/create'
                ? new Response(400, [], '{"code":"invalid_argument","message":"SYNTHETIC_PRIVATE_ERROR"}') : null;
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['registration_outcome_unknown']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(0, $this->http->sends('/api/v1/pa/payment_intents/create'));
        self::assertSame(0, $this->http->sends(
            '/api/v1/webhooks/' . WebhookDeliveryFixtureProvider::WEBHOOK . '/delete'
        ));
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
        self::sanitized($safe);
    }
    /** @return iterable<string,array{string}> */
    public static function malformedIdentities(): iterable
    {
        foreach (['.', '..', 'parent/child', 'parent\\child', "with space", "with\ncontrol"] as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('malformedIdentities')]
    public function testMalformedRegistrationIdentityCannotSelectAnyFollowupRoute(string $id): void
    {
        $this->http->intercept = static function (RequestInterface $request) use ($id): ?ResponseInterface {
            if ($request->getUri()->getPath() !== '/api/v1/webhooks/create') {
                return null;
            }
            $wire = (new JsonCodec())->decodeObject((string) $request->getBody());
            return WebhookDeliveryFixtureProvider::response(
                201,
                ['id' => $id, 'secret' => 'SYNTHETIC_PRIVATE_SECRET', ...$wire]
            );
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['registration_outcome_unknown']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertFalse($safe['intent_created']);
        self::assertCount(2, $this->http->requests); // Login and the one original registration create only.
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
        self::sanitized($safe);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function badOptions(): iterable
    {
        yield 'production' => ['environment', 'production'];
        yield 'no explicit creation' => ['create-one-webhook-and-intent', false];
        yield 'duplicate flag' => ['create-one-webhook-and-intent', [true, true]];
        yield 'caller URL' => ['url', 'https://SYNTHETIC_PRIVATE.test'];
        yield 'old resource' => ['webhook-id', 'SYNTHETIC_PRIVATE_WEBHOOK'];
        yield 'context override' => ['context', 'organization'];
        yield 'alternate root' => ['sdk-root', '/SYNTHETIC_PRIVATE_ROOT'];
        yield 'stream credentials' => ['credentials-file', 'data:text/plain,SYNTHETIC_PRIVATE'];
        yield 'relative state' => ['state-dir', 'relative'];
        yield 'missing Node' => ['node-bin', '/no/SYNTHETIC_PRIVATE'];
    }

    #[DataProvider('badOptions')]
    public function testUnknownOrNonExplicitOptionsDoNotStartRuntimeOrApi(string $key, mixed $value): void
    {
        $options = $this->options();
        $options[$key] = $value;
        $called = false;
        $safe = WebhookDeliveryProbe::run(
            $options,
            $this->http,
            static function (array $config) use (&$called): WebhookDeliveryFixtureRuntime {
                $called = true;
                return new WebhookDeliveryFixtureRuntime($config);
            }
        );
        self::assertSame('fail', $safe['result']);
        self::assertFalse($called);
        self::assertSame([], $this->http->requests);
        self::sanitized($safe);
    }

    public function testPubliclyReadableStateBaseFailsBeforeRuntimeOrAuthentication(): void
    {
        chmod($this->directory, 0755);
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertNull($this->runtime);
        self::assertSame([], $this->http->requests);
        self::sanitized($safe);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function wrongIntentFields(): iterable
    {
        yield 'missing identity' => ['id', ''];
        yield 'other buyer' => ['customer_id', 'SYNTHETIC_PRIVATE_CUSTOMER'];
        yield 'other collector' => ['connected_account_id', 'SYNTHETIC_PRIVATE_CONNECTED'];
        yield 'other amount' => ['amount', '1.24'];
        yield 'other currency' => ['currency', 'EUR'];
        yield 'other order' => ['merchant_order_id', 'SYNTHETIC_PRIVATE_ORDER'];
        yield 'other run' => ['metadata', ['sdk_validation_run' => 'SYNTHETIC_PRIVATE_RUN']];
        yield 'captured funds' => ['captured_amount', '1.23'];
        yield 'unexpected state' => ['status', 'REQUIRES_CAPTURE'];
        yield 'other account' => ['account_id', 'SYNTHETIC_PRIVATE_FOREIGN_ACCOUNT'];
    }

    #[DataProvider('wrongIntentFields')]
    public function testMismatchedCreateNeverSelectsReturnedClaimForGetOrCancel(string $key, mixed $value): void
    {
        $this->http->intercept = static function (RequestInterface $request) use ($key, $value): ?ResponseInterface {
            if ($request->getUri()->getPath() !== '/api/v1/pa/payment_intents/create') {
                return null;
            }
            $wire = (new JsonCodec())->decodeObject((string) $request->getBody());
            $object = ['id' => WebhookDeliveryFixtureProvider::INTENT,
                'status' => 'REQUIRES_PAYMENT_METHOD', 'captured_amount' => '0', ...$wire];
            $object[$key] = $value;
            return WebhookDeliveryFixtureProvider::response(201, $object);
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['intent_outcome_unknown']);
        self::assertTrue($safe['registration_deleted']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(0, $this->http->sends('/api/v1/pa/payment_intents/' . WebhookDeliveryFixtureProvider::INTENT));
        self::assertSame(0, $this->http->sends(
            '/api/v1/pa/payment_intents/' . WebhookDeliveryFixtureProvider::INTENT . '/cancel'
        ));
        self::sanitized($safe);
    }

    /** @return iterable<string,array{int,bool}> */
    public static function cancellationFailures(): iterable
    {
        yield 'known400' => [400, false];
        yield 'server500' => [500, false];
        yield 'responsewrongidentity' => [200, true];
    }

    #[DataProvider('cancellationFailures')]
    public function testFailedSentCancelNeverReplaysOrUsesAnotherFinancialAction(int $status, bool $foreign): void
    {
        $this->http->intercept = function (RequestInterface $request) use ($status, $foreign): ?ResponseInterface {
            if (!str_ends_with($request->getUri()->getPath(), '/cancel')) {
                return null;
            }
            if ($foreign) {
                return WebhookDeliveryFixtureProvider::response(
                    200,
                    ['id' => 'SYNTHETIC_PRIVATE_FOREIGN', ...array_diff_key($this->http->intent, ['id' => true])]
                );
            }
            return new Response($status, [], '{"code":"validation_error","message":"SYNTHETIC_PRIVATE_ERROR"}');
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['intent_outcome_unknown']);
        self::assertFalse($safe['intent_cancelled']);
        self::assertTrue($safe['registration_deleted']);
        self::assertSame(1, $this->http->sends(
            '/api/v1/pa/payment_intents/' . WebhookDeliveryFixtureProvider::INTENT . '/cancel'
        ));
        foreach ($this->http->requests as $request) {
            self::assertStringNotContainsString('/capture', $request->getUri()->getPath());
            self::assertStringNotContainsString('/refund', $request->getUri()->getPath());
            self::assertStringNotContainsString('/confirm', $request->getUri()->getPath());
        }
        self::sanitized($safe);
    }

    /** @return iterable<string,array{int,string}> */
    public static function failedDeletion(): iterable
    {
        yield '200false' => [200, '{"id":"SYNTHETIC_PRIVATE_WEBHOOK","deleted":false}'];
        yield '200wrongid' => [200, '{"id":"SYNTHETIC_PRIVATE_FOREIGN","deleted":true}'];
        yield '500' => [500, '{"code":"SYNTHETIC_PRIVATE_CODE"}'];
        yield 'redirect' => [302, 'SYNTHETIC_PRIVATE_REDIRECT'];
    }

    #[DataProvider('failedDeletion')]
    public function testOwnDeletionMustBeVerifiedAndCannotRepeat(int $status, string $body): void
    {
        $this->http->intercept = static fn (RequestInterface $request): ?ResponseInterface =>
            str_ends_with($request->getUri()->getPath(), '/delete') ? new Response($status, [], $body) : null;
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertFalse($safe['registration_deleted']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertTrue($safe['intent_cancelled']);
        self::assertSame(1, $this->http->sends(
            '/api/v1/webhooks/' . WebhookDeliveryFixtureProvider::WEBHOOK . '/delete'
        ));
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
        self::sanitized($safe);
    }

    public function testCleanupRefusesChangedRegistrationAndNeverDeletesAnotherConfiguration(): void
    {
        $this->http->intercept = function (RequestInterface $request): ?ResponseInterface {
            if (
                $request->getUri()->getPath() === '/api/v1/webhooks/' . WebhookDeliveryFixtureProvider::WEBHOOK
                && ($this->http->intent['status'] ?? null) === 'CANCELLED'
            ) {
                return WebhookDeliveryFixtureProvider::response(
                    200,
                    array_replace($this->http->registration, ['url' => 'https://SYNTHETIC_PRIVATE_FOREIGN.test'])
                );
            }
            return null;
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertFalse($safe['registration_deleted']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(0, $this->http->sends(
            '/api/v1/webhooks/' . WebhookDeliveryFixtureProvider::WEBHOOK . '/delete'
        ));
        self::sanitized($safe);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function unknownEnvelopes(): iterable
    {
        yield 'version' => ['version', 'SYNTHETIC_PRIVATE_VERSION'];
        yield 'account' => ['account_id', 'SYNTHETIC_PRIVATE_FOREIGN'];
        yield 'conflictingowner' => ['org_id', 'SYNTHETIC_PRIVATE_ORG'];
        yield 'name' => ['name', 'SYNTHETIC_PRIVATE_EVENT'];
        yield 'missingdate' => ['created_at', null];
    }

    #[DataProvider('unknownEnvelopes')]
    public function testAuthenticUnknownEnvelopesStayPrivateAndDoNotBecomeKnownDelivery(string $key, mixed $value): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime) use ($key, $value): void {
            $runtime->eventFilter = static function (array $event) use ($key, $value): array {
                $event[$key] = $value;
                return $event;
            };
        };
        $safe = $this->observe();
        self::assertSame('pending', $safe['result']);
        self::assertSame(2, $safe['mac_verified_count']);
        self::assertSame(2, $safe['receipt_freshness_verified_count']);
        self::assertSame(2, $safe['unrecognized_or_foreign_count']);
        self::assertFalse($safe['version_owner_verified']);
        self::assertTrue($safe['intent_cancelled']);
        self::assertTrue($safe['registration_deleted']);
        self::sanitized($safe);
    }

    public function testInvalidMacIsNotJsonInterpretedAndCannotPassDeliveryEvidence(): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->bodyFilter = static fn (string $body): string => 'SYNTHETIC_PRIVATE_NOT_JSON';
            $runtime->metadataFilter = self::corruptSignature(...);
        };
        $safe = $this->observe();
        self::assertSame('pending', $safe['result']);
        self::assertSame(2, $safe['invalid_signature_count']);
        self::assertSame(0, $safe['mac_verified_count']);
        self::assertSame(0, $safe['unrecognized_or_foreign_count']);
        self::assertTrue($safe['intent_cancelled']);
        self::assertTrue($safe['registration_deleted']);
        self::sanitized($safe);
    }

    /** @return iterable<string,array{int}> */
    public static function failedRegistrationSyncs(): iterable
    {
        yield 'registrationfile' => [7];
        yield 'registrationdirectory' => [8];
    }

    #[DataProvider('failedRegistrationSyncs')]
    public function testUndurableReturnedIdSecretPreventsTriggersAndDeleteGuess(int $failure): void
    {
        $calls = 0;
        $safe = $this->observe(stores: static function (
            string $directory,
            RunLedger $ledger,
        ) use (
            &$calls,
            $failure
): WebhookDeliveryStore {
            return new WebhookDeliveryStore($directory, $ledger, static function () use (&$calls, $failure): bool {
                return ++$calls !== $failure;
            });
        });
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['registration_outcome_unknown']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertSame(1, $this->http->sends('/api/v1/webhooks/create'));
        self::assertSame(0, $this->http->sends('/api/v1/pa/payment_intents/create'));
        self::assertSame(0, $this->http->sends(
            '/api/v1/webhooks/' . WebhookDeliveryFixtureProvider::WEBHOOK . '/delete'
        ));
        self::assertNotNull($this->runtime);
        self::assertTrue($this->runtime->stopped);
        self::sanitized($safe);
    }

    public function testPoisonedPrimaryJournalStillAllowsIndependentProvenRegistrationCleanup(): void
    {
        $safe = $this->observe(ledgers: static function (string $fingerprint, string $directory): RunLedger {
            $calls = 0;
            return RunLedger::create($fingerprint, $directory, synchronize: static function () use (&$calls): bool {
                return ++$calls !== 10; // Primary post-create append, after the private registration proof is durable.
            });
        });
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['registration_created']);
        self::assertTrue($safe['registration_deleted']);
        self::assertFalse($safe['registration_outcome_unknown']);
        self::assertSame(0, $this->http->sends('/api/v1/pa/payment_intents/create'));
        self::sanitized($safe);
    }

    public function testRuntimeTeardownFailureCannotBeReportedAsPassedCleanup(): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->failStop = true;
        };
        $safe = $this->observe();
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['registration_deleted']);
        self::assertTrue($safe['intent_cancelled']);
        self::assertTrue($safe['reconciliation_required']);
        self::sanitized($safe);
    }
    public function testEveryMutationHasItsExactWireHashAndSeparateIdentityBeforeSend(): void
    {
        $seen = [];
        $this->http->intercept = function (RequestInterface $request) use (&$seen): ?ResponseInterface {
            $path = $request->getUri()->getPath();
            $phase = match ($path) {
                '/api/v1/webhooks/create' => 'registration_create_wire',
                '/api/v1/pa/payment_intents/create' => 'intent_create_wire',
                '/api/v1/pa/payment_intents/' . WebhookDeliveryFixtureProvider::INTENT . '/cancel'
                    => 'intent_cancel_wire',
                default => null,
            };
            if ($phase === null) {
                return null;
            }
            $found = false;
            foreach (glob($this->directory . '/*/ledger.json') ?: [] as $file) {
                $bytes = file_get_contents($file);
                self::assertIsString($bytes);
                $ledger = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($ledger);
                $observations = $ledger['observations'] ?? null;
                self::assertIsArray($observations);
                if (!isset($observations[$phase])) {
                    continue;
                }
                $record = $observations[$phase];
                self::assertIsArray($record);
                self::assertSame(hash('sha256', (string) $request->getBody()), $record['wire_sha256']);
                self::assertSame(hash('sha256', $path), $record['target_sha256']);
                self::assertStringNotContainsString('SYNTHETIC_PRIVATE_SECRET', $bytes);
                self::assertStringNotContainsString('trycloudflare.com', $bytes);
                $found = true;
            }
            self::assertTrue($found, 'Exact submitted bytes must be journaled before the send.');
            $body = (new JsonCodec())->decodeObject((string) $request->getBody());
            $id = $body['request_id'] ?? null;
            self::assertIsString($id);
            self::assertNotContains($id, $seen);
            $seen[] = $id;
            return null;
        };
        self::assertSame('pass', $this->observe()['result']);
        self::assertCount(3, $seen);
    }

    public function testInitialDurabilityFailureCannotStartRuntimeOrAuthenticate(): void
    {
        $safe = $this->observe(ledgers: static fn (string $fingerprint, string $directory): RunLedger =>
            RunLedger::create($fingerprint, $directory, synchronize: static fn (): bool => false));
        self::assertSame('fail', $safe['result']);
        self::assertNull($this->runtime);
        self::assertSame([], $this->http->requests);
        self::sanitized($safe);
    }

    public function testPostReceiptCheckpointFailureRetainsOwnIntentWithoutSubstituteMutation(): void
    {
        $safe = $this->observe(ledgers: static function (string $fingerprint, string $directory): RunLedger {
            return RunLedger::create($fingerprint, $directory, synchronize: static function ($stream): bool {
                $uri = null;
                foreach (stream_get_meta_data($stream) as $name => $value) {
                    if ($name === 'uri') {
                        $uri = $value;
                    }
                }
                if ($uri === null || !is_file($uri)) {
                    return true; // Parent directory sync.
                }
                $bytes = file_get_contents($uri);
                return is_string($bytes) && !str_contains($bytes, 'created_capture_checkpoint');
            });
        });
        self::assertSame('fail', $safe['result']);
        self::assertSame(1, $safe['matching_created_count']);
        self::assertTrue($safe['intent_created']);
        self::assertFalse($safe['intent_cancelled']);
        self::assertFalse($safe['intent_outcome_unknown']);
        self::assertTrue($safe['reconciliation_required']);
        self::assertTrue($safe['registration_deleted']);
        self::assertSame(0, $this->http->sends(
            '/api/v1/pa/payment_intents/' . WebhookDeliveryFixtureProvider::INTENT . '/cancel'
        ));
        self::sanitized($safe);
    }

    public function testConflictingSignedEventIdentityCannotProveBothEventTransitions(): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->eventFilter = static function (array $event): array {
                $event['id'] = 'SYNTHETIC_PRIVATE_DUPLICATE_EVENT';
                return $event;
            };
        };
        $safe = $this->observe();
        self::assertSame('pending', $safe['result']);
        self::assertSame(1, $safe['matching_created_count']);
        self::assertSame(0, $safe['matching_cancelled_count']);
        self::assertSame(1, $safe['unrecognized_or_foreign_count']);
        self::assertTrue($safe['intent_cancelled']);
        self::sanitized($safe);
    }

    public function testConflictingObjectAccountNeverBecomesOwnedDelivery(): void
    {
        $this->configure = static function (WebhookDeliveryFixtureRuntime $runtime): void {
            $runtime->eventFilter = static function (array $event): array {
                $data = $event['data'] ?? null;
                self::assertIsArray($data);
                $object = $data['object'] ?? null;
                self::assertIsArray($object);
                $object['account_id'] = 'SYNTHETIC_PRIVATE_FOREIGN_ACCOUNT';
                $event['data'] = ['object' => $object];
                return $event;
            };
        };
        $safe = $this->observe();
        self::assertSame('pending', $safe['result']);
        self::assertSame(2, $safe['unrecognized_or_foreign_count']);
        self::assertFalse($safe['version_owner_verified']);
        self::sanitized($safe);
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private static function corruptSignature(array $metadata): array
    {
        $metadata['signature'] = str_repeat('a', 64);
        return $metadata;
    }
}
