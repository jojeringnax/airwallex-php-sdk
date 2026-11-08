<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingCatalogueCleanup;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingCatalogueCleanupCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class BillingCatalogueCleanupTest extends TestCase
{
    private string $directory;
    private string $prior;
    private string $marker;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-cleanup-fixture-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/credentials', "AIRWALLEX_CLIENT_ID=fixture_client\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_key\n");
        [$session] = $this->session([]);
        $original = RunLedger::create($session->contextFingerprint(), $this->directory);
        $original->requestId('create_future_trial', ['fixture' => true], $session->contextFingerprint());
        $original->recordCreated('customers', 'fixture-customer');
        $original->recordCreated('products', 'fixture-product');
        $original->recordCreated('prices', 'fixture-price-0');
        $original->recordCreated('prices', 'fixture-price-1');
        $this->marker = $original->runId();
        $this->prior = $this->directory . '/' . $original->runId() . '/ledger.json';
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
    private function session(array $responses): array
    {
        $http = new RecordingClient(new HttpClient(['handler' => new MockHandler($responses)]));
        return [new SandboxSession(
            Configuration::load('sandbox', $this->directory . '/credentials'),
            'organization',
            $http,
            static fn (): int => 100,
        ), $http];
    }

    /** @return array<string,mixed> */
    private function object(string $kind, int $index = 0, bool $active = true): array
    {
        $body = [
            'id' => $kind === 'prices' ? 'fixture-price-' . $index : 'fixture-' . rtrim($kind, 's'),
            'metadata' => ['sdk_validation_run' => $this->marker],
        ];
        if ($kind !== 'customers') {
            $body['active'] = $active;
        }
        if ($kind === 'prices') {
            $body += [
                'product_id' => 'fixture-product', 'currency' => 'EUR', 'pricing_model' => 'PER_UNIT',
                'unit_amount' => $index === 0 ? '1.2300' : '2.34', 'billing_type' => 'IN_ADVANCE',
                'recurring' => ['period' => 1, 'period_unit' => 'MONTH'],
            ];
        }
        return $body;
    }

    /** @param array<string,mixed> $body */
    private static function response(array $body): Response
    {
        $json = preg_replace(
            '/"unit_amount":"([0-9.]+)"/',
            '"unit_amount":$1',
            json_encode($body, JSON_THROW_ON_ERROR),
        );
        if ($json === null) {
            throw new RuntimeException('Invalid synthetic response.');
        }
        return new Response(200, [], $json);
    }

    /** @return list<Response> */
    private function responses(bool $active = true): array
    {
        $responses = [
            new Response(201, [], '{"token":"fixture_token","expires_at":"2100-01-01T00:00:00Z"}'),
            self::response($this->object('customers')),
            self::response($this->object('products', active: $active)),
            self::response($this->object('prices', active: $active)),
            self::response($this->object('prices', 1, $active)),
        ];
        if ($active) {
            foreach ([['prices', 0], ['prices', 1], ['products', 0]] as [$kind, $index]) {
                $object = $this->object($kind, $index, false);
                $responses[] = self::response($object);
                $responses[] = self::response($object);
            }
        }
        return $responses;
    }

    public function testCleanupVerifiesAllTargetsThenArchivesOnlyRecordedPricesAndProductWithBoundIntents(): void
    {
        [$session, $http] = $this->session($this->responses());
        $original = file_get_contents($this->prior);
        self::assertIsString($original);
        $cleanup = BillingCatalogueCleanup::load($this->prior, $session);
        $ledger = RunLedger::create($session->contextFingerprint(), $this->directory);
        $result = $cleanup->execute($session, $ledger);
        self::assertSame('pass', $result['result']);
        self::assertTrue($result['catalogue_verified']);
        self::assertSame(2, $result['prices_archived']);
        self::assertTrue($result['product_archived']);
        self::assertFalse($result['ambiguous_mutation']);
        self::assertFalse($result['subscription_reconciliation_complete']);
        self::assertSame($original, file_get_contents($this->prior));
        self::assertCount(11, $http->requests);
        foreach (array_slice($http->requests, 1, 4) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
        $state = json_decode((string) file_get_contents(
            $this->directory . '/' . $ledger->runId() . '/ledger.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsArray($state['actions']);
        self::assertCount(3, $state['actions']);
        foreach (
            [5 => ['prices', 0, 'fixture-price-0'], 7 => ['prices', 1, 'fixture-price-1'],
            9 => ['products', 0, 'fixture-product']] as $position => [$kind, $index, $id]
        ) {
            $request = $http->requests[$position];
            self::assertSame('POST', $request->getMethod());
            self::assertStringEndsWith('/' . $id . '/update', $request->getUri()->getPath());
            $wire = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($wire);
            self::assertSame(['active', 'request_id'], array_keys($wire));
            $action = $state['actions']['cleanup_' . $this->marker . '_' . $kind . '_' . $index];
            self::assertIsArray($action);
            self::assertSame($wire['request_id'], $action['request_id']);
            self::assertSame(hash('sha256', json_encode([
                'original_run' => $this->marker, 'original_ledger_sha256' => hash('sha256', $original),
                'resource_kind' => $kind, 'resource_id' => $id, 'active' => false,
            ], JSON_THROW_ON_ERROR)), $action['body_hash']);
        }
        foreach ($http->requests as $request) {
            self::assertStringNotContainsString('/subscriptions', $request->getUri()->getPath());
            self::assertFalse($request->hasHeader('x-login-as'));
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
        }
        $safe = json_encode($result, JSON_THROW_ON_ERROR) . print_r($cleanup, true);
        foreach (['fixture-customer', 'fixture-price', 'fixture-product', 'fixture_token', $this->marker] as $private) {
            self::assertStringNotContainsString($private, $safe);
        }
    }

    public function testAlreadyInactiveCatalogueNeedsOnlyReadsAndNoMutationIntent(): void
    {
        [$session, $http] = $this->session($this->responses(false));
        $ledger = RunLedger::create($session->contextFingerprint(), $this->directory);
        $result = BillingCatalogueCleanup::load($this->prior, $session)->execute($session, $ledger);
        self::assertSame('pass', $result['result']);
        self::assertSame(0, $result['prices_archived']);
        self::assertSame(2, $result['prices_already_inactive']);
        self::assertTrue($result['product_already_inactive']);
        self::assertCount(5, $http->requests);
    }

    public function testDevelopmentCommandUsesTheSameFixedOrganizationCleanupAndSafeEnvelope(): void
    {
        [, $http] = $this->session($this->responses());
        $result = BillingCatalogueCleanupCommand::run([
            'environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'prior-ledger' => $this->prior, 'state-dir' => $this->directory,
        ], $http);
        self::assertSame('pass', $result['result']);
        self::assertSame('billing-catalogue-cleanup', $result['scenario']);
        self::assertSame('organization', $result['context']);
        self::assertSame(2, $result['prices_archived']);
        self::assertCount(11, $http->requests);
        self::assertStringNotContainsString('fixture-', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{int,string,mixed}> */
    public static function mismatches(): iterable
    {
        yield 'Customer marker' => [1, 'metadata', ['sdk_validation_run' => 'foreign-marker']];
        yield 'Product marker' => [2, 'metadata', []];
        yield 'Price marker' => [3, 'metadata', ['sdk_validation_run' => 'foreign-marker']];
        yield 'Price Product relation' => [4, 'product_id', 'unrecorded-product'];
        yield 'Price currency' => [3, 'currency', 'USD'];
        yield 'Price amount' => [3, 'unit_amount', '1.24'];
        yield 'Price active type' => [3, 'active', null];
        yield 'Product identity' => [2, 'id', 'foreign-product'];
    }

    #[DataProvider('mismatches')]
    public function testWrongPreflightEvidenceStopsBeforeAnyCatalogueWrite(
        int $position,
        string $field,
        mixed $value,
    ): void {
        $object = $this->object(
            $position === 1 ? 'customers' : ($position === 2 ? 'products' : 'prices'),
            $position === 4 ? 1 : 0
        );
        $object[$field] = $value;
        $responses = [];
        foreach ($this->responses() as $index => $response) {
            $responses[] = $index === $position ? self::response($object) : $response;
        }
        [$session, $http] = $this->session($responses);
        $ledger = RunLedger::create($session->contextFingerprint(), $this->directory);
        $result = BillingCatalogueCleanup::load($this->prior, $session)->execute($session, $ledger);
        self::assertSame('fail', $result['result']);
        self::assertFalse($result['catalogue_verified']);
        self::assertFalse($result['ambiguous_mutation']);
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidOriginalState(): iterable
    {
        yield 'wrong context' => ['context_fingerprint', str_repeat('b', 64)];
        yield 'wrong version' => ['api_version', '2000-01-01'];
        yield 'returned Subscription outside cleanup scope' => ['resources', [
            'customers' => ['fixture-customer'], 'products' => ['fixture-product'],
            'prices' => ['fixture-price-0', 'fixture-price-1'], 'subscriptions' => ['fixture-subscription'],
        ]];
    }

    #[DataProvider('invalidOriginalState')]
    public function testInvalidOriginalVersionContextOrScopeIsRejectedBeforeAuthentication(
        string $field,
        mixed $value,
    ): void {
        $state = json_decode((string) file_get_contents($this->prior), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        $state[$field] = $value;
        file_put_contents($this->prior, json_encode($state, JSON_THROW_ON_ERROR));
        [$session, $http] = $this->session([]);
        $this->expectException(RuntimeException::class);
        try {
            BillingCatalogueCleanup::load($this->prior, $session);
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function testFailedPersistenceCannotSendTheFirstArchive(): void
    {
        [$session, $http] = $this->session($this->responses());
        $synchronizations = 0;
        $ledger = RunLedger::create($session->contextFingerprint(), $this->directory, synchronize:
            static function ($stream) use (&$synchronizations): bool {
                return ++$synchronizations !== 4 && fsync($stream);
            });
        $result = BillingCatalogueCleanup::load($this->prior, $session)->execute($session, $ledger);
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['catalogue_verified']);
        self::assertFalse($result['ambiguous_mutation']);
        self::assertSame(0, $result['prices_archived']);
        self::assertCount(5, $http->requests);
    }

    public function testPartialFailureStopsAllLaterArchivesAndKeepsPriorLedgerUnchanged(): void
    {
        $responses = array_slice($this->responses(), 0, 7);
        $responses[] = new Response(400, [], '{"code":"validation_error","message":"PRIVATE"}');
        [$session, $http] = $this->session($responses);
        $before = file_get_contents($this->prior);
        $ledger = RunLedger::create($session->contextFingerprint(), $this->directory);
        $result = BillingCatalogueCleanup::load($this->prior, $session)->execute($session, $ledger);
        self::assertSame('fail', $result['result']);
        self::assertSame(1, $result['prices_archived']);
        self::assertFalse($result['product_archived']);
        self::assertTrue($result['ambiguous_mutation']);
        self::assertSame('archive_price_1', $result['phase']);
        self::assertCount(8, $http->requests);
        self::assertSame($before, file_get_contents($this->prior));
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testAmbiguousFirstArchiveTransportCannotBeReplayedOrFollowedByCleanup(): void
    {
        $responses = array_slice($this->responses(), 0, 5);
        $responses[] = new RuntimeException('PRIVATE transport uncertainty');
        [$session, $http] = $this->session($responses);
        $ledger = RunLedger::create($session->contextFingerprint(), $this->directory);
        $result = BillingCatalogueCleanup::load($this->prior, $session)->execute($session, $ledger);
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['ambiguous_mutation']);
        self::assertSame(0, $result['prices_archived']);
        self::assertCount(6, $http->requests);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testUnverifiedArchiveReadbackStopsRatherThanCountingSuccess(): void
    {
        $responses = array_slice($this->responses(), 0, 6);
        $responses[] = self::response($this->object('prices', active: true));
        [$session, $http] = $this->session($responses);
        $ledger = RunLedger::create($session->contextFingerprint(), $this->directory);
        $result = BillingCatalogueCleanup::load($this->prior, $session)->execute($session, $ledger);
        self::assertSame('fail', $result['result']);
        self::assertTrue($result['ambiguous_mutation']);
        self::assertSame(0, $result['prices_archived']);
        self::assertCount(7, $http->requests);
    }

    public function testProductionAndMissingConfigurationCannotSendEvenAuthentication(): void
    {
        foreach (
            [[], ['environment' => 'production', 'credentials-file' => '/unread-private-fixture',
            'prior-ledger' => '/unread-private-ledger', 'state-dir' => $this->directory]] as $options
        ) {
            [, $http] = $this->session([]);
            $result = BillingCatalogueCleanupCommand::run($options, $http);
            self::assertSame('fail', $result['result']);
            self::assertSame([], $http->requests);
            self::assertStringNotContainsString('private-', json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    private function recordSubscriptions(): void
    {
        $state = json_decode((string) file_get_contents($this->prior), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsArray($state['resources']);
        $state['resources']['subscriptions'] = ['fixture-subscription-0', 'fixture-subscription-1'];
        file_put_contents($this->prior, json_encode($state, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function cancelledSubscription(int $index): array
    {
        return [
            'id' => 'fixture-subscription-' . $index, 'billing_customer_id' => 'fixture-customer',
            'metadata' => ['sdk_validation_run' => $this->marker], 'status' => 'CANCELLED', 'duration' => null,
        ];
    }

    /** @return list<Response> */
    private function cancelledResponses(): array
    {
        $base = $this->responses();
        return [
            $base[0], $base[1], self::response($this->cancelledSubscription(0)),
            self::response($this->cancelledSubscription(1)), ...array_slice($base, 2),
        ];
    }

    public function testExplicitModeReadsEveryRecordedCancelledSubscriptionBeforeAnyCatalogueWrite(): void
    {
        $this->recordSubscriptions();
        [, $http] = $this->session($this->cancelledResponses());
        $before = file_get_contents($this->prior);
        $result = BillingCatalogueCleanupCommand::run([
            'environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'prior-ledger' => $this->prior, 'state-dir' => $this->directory,
            'mode' => 'verify-cancelled-subscriptions',
        ], $http);
        self::assertSame('pass', $result['result']);
        self::assertSame('verify-cancelled-subscriptions', $result['cleanup_mode']);
        self::assertSame(2, $result['cancelled_subscriptions_verified']);
        self::assertFalse($result['subscription_mutation_sent']);
        self::assertFalse($result['subscription_reconciliation_complete']);
        self::assertSame($before, file_get_contents($this->prior));
        self::assertCount(13, $http->requests);
        foreach (array_slice($http->requests, 1, 6) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
        foreach ($http->requests as $request) {
            if (str_contains($request->getUri()->getPath(), '/subscriptions/')) {
                self::assertSame('GET', $request->getMethod());
            }
        }
        self::assertStringNotContainsString('fixture-', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function cancelledMismatches(): iterable
    {
        yield 'active state' => ['status', 'ACTIVE'];
        yield 'unknown state' => ['status', 'FUTURE_STATE'];
        yield 'missing state' => ['status', null];
        yield 'wrong Customer' => ['billing_customer_id', 'other-private-customer'];
        yield 'wrong marker' => ['metadata', ['sdk_validation_run' => 'other-run']];
        yield 'wrong identity' => ['id', 'other-private-subscription'];
        yield 'API failure' => ['http_status', 403];
    }

    #[DataProvider('cancelledMismatches')]
    public function testAnyUnverifiedRecordedSubscriptionPreventsEveryCatalogueWrite(string $field, mixed $value): void
    {
        $this->recordSubscriptions();
        $object = $this->cancelledSubscription(1);
        $object[$field] = $value;
        $responses = [];
        foreach ($this->cancelledResponses() as $index => $response) {
            $responses[] = $index === 3 ? ($field === 'http_status'
                ? new Response(403, [], '{"code":"forbidden","message":"PRIVATE"}')
                : self::response($object)) : $response;
        }
        [$session, $http] = $this->session($responses);
        $ledger = RunLedger::create($session->contextFingerprint(), $this->directory);
        $result = BillingCatalogueCleanup::load($this->prior, $session, true)->execute($session, $ledger);
        self::assertSame('fail', $result['result']);
        self::assertFalse($result['catalogue_verified']);
        self::assertSame(0, $result['prices_archived']);
        self::assertFalse($result['ambiguous_mutation']);
        self::assertCount(4, $http->requests);
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }
}
