<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingReconciliation;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BillingReconciliationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-reconciliation-fixture-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_CLIENT_ID=fixture_client\nAIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_key\n",
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
     * @param list<Response> $responses
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

    /** @return array{RunLedger,string,string} */
    private function state(SandboxSession $session): array
    {
        $ledger = RunLedger::create($session->contextFingerprint(), $this->directory);
        $request = $ledger->requestId('create_future_trial', ['fixture' => true], $session->contextFingerprint());
        $ledger->recordCreated('customers', 'fixture-own-customer');
        return [$ledger, $this->directory . '/' . $ledger->runId() . '/ledger.json', $request];
    }

    public function testReadOnlyProbeReportsCorrelationWithoutIdsOrPayloadsAndRetainsFiltersAcrossPages(): void
    {
        [$empty] = $this->session([]);
        [$ledger, $path, $request] = $this->state($empty);
        $page = json_encode([
            'items' => [[
                'id' => 'fixture-subscription', 'billing_customer_id' => 'fixture-own-customer',
                'request_id' => $request, 'metadata' => ['sdk_validation_run' => $ledger->runId()],
            ]],
            'page_after' => 'fixture-private-bookmark',
        ], JSON_THROW_ON_ERROR);
        [$session, $http] = $this->session([
            new Response(201, [], '{"token":"fixture_token","expires_at":"2030-01-01T00:00:00Z"}'),
            new Response(200, [], $page),
            new Response(200, [], '{"items":[{"id":"fixture-second","billing_customer_id":"fixture-own-customer",'
                . '"metadata":{"sdk_validation_run":"another-run"}}],"page_after":null}'),
        ]);
        $probe = BillingReconciliation::load($path, $session);
        $result = $probe->inspect($session);
        self::assertSame('pass', $result['result']);
        self::assertSame(2, $result['subscription_count']);
        self::assertSame(1, $result['request_id_present_count']);
        self::assertSame(1, $result['request_id_matches_count']);
        self::assertSame(2, $result['run_marker_present_count']);
        self::assertSame(1, $result['run_marker_matches_count']);
        self::assertSame(1, $result['both_match_count']);
        self::assertTrue($result['pagination_complete']);
        self::assertFalse($result['mutation_sent']);
        self::assertCount(3, $http->requests);
        foreach (array_slice($http->requests, 1) as $httpRequest) {
            self::assertSame('GET', $httpRequest->getMethod());
            self::assertStringContainsString('billing_customer_id=fixture-own-customer&page_size=100', (string)
                $httpRequest->getUri());
        }
        $json = json_encode($result, JSON_THROW_ON_ERROR) . print_r($probe, true);
        foreach (['fixture-own-customer', 'fixture-subscription', 'fixture-private-bookmark', $request] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testMismatchedPrivateContextFailsBeforeAuthentication(): void
    {
        [$session, $http] = $this->session([]);
        [$ledger, $path] = $this->state($session);
        $state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        $state['context_fingerprint'] = str_repeat('b', 64);
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
        self::assertSame(1, $ledger->counts()['customers']);
        $this->expectException(RuntimeException::class);
        try {
            BillingReconciliation::load($path, $session);
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function testMissingTerminalBookmarkDoesNotProveAnEmptyResultSetIsComplete(): void
    {
        [$empty] = $this->session([]);
        [, $path] = $this->state($empty);
        [$session] = $this->session([
            new Response(201, [], '{"token":"fixture_token","expires_at":"2030-01-01T00:00:00Z"}'),
            new Response(200, [], '{"items":[]}'),
        ]);
        $result = BillingReconciliation::load($path, $session)->inspect($session);
        self::assertSame('pending', $result['result']);
        self::assertFalse($result['pagination_complete']);
        self::assertSame(0, $result['subscription_count']);
    }

    public function testARepeatedBookmarkStopsInsteadOfRepeatingAnyRead(): void
    {
        [$empty] = $this->session([]);
        [, $path] = $this->state($empty);
        [$session, $http] = $this->session([
            new Response(201, [], '{"token":"fixture_token","expires_at":"2030-01-01T00:00:00Z"}'),
            new Response(200, [], '{"items":[],"page_after":"repeated"}'),
            new Response(200, [], '{"items":[],"page_after":"repeated"}'),
        ]);
        $this->expectException(ProtocolException::class);
        try {
            BillingReconciliation::load($path, $session)->inspect($session);
        } finally {
            self::assertCount(3, $http->requests);
        }
    }

    public function testAnUnexpectedCustomerReferenceCannotBecomeSuccessfulCorrelationEvidence(): void
    {
        [$empty] = $this->session([]);
        [, $path] = $this->state($empty);
        [$session, $http] = $this->session([
            new Response(201, [], '{"token":"fixture_token","expires_at":"2030-01-01T00:00:00Z"}'),
            new Response(200, [], '{"items":[{"id":"private-other-subscription",'
                . '"billing_customer_id":"private-other-customer"}],"page_after":null}'),
        ]);
        $result = BillingReconciliation::load($path, $session)->inspect($session);
        self::assertSame('fail', $result['result']);
        self::assertFalse($result['all_customer_references_match']);
        self::assertSame(0, $result['subscription_count']);
        self::assertCount(2, $http->requests);
        self::assertStringNotContainsString('private-other', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testLoadedReferencesCannotBeUsedByAnotherCredentialContext(): void
    {
        [$session] = $this->session([]);
        [, $path] = $this->state($session);
        $probe = BillingReconciliation::load($path, $session);
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_CLIENT_ID=other_fixture_client\nAIRWALLEX_SANDBOX_API_KEY_MASTER=other_fixture_key\n",
        );
        [$other, $http] = $this->session([]);
        $this->expectException(RuntimeException::class);
        try {
            $probe->inspect($other);
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function testRepeatedSubscriptionAcrossDistinctPagesCountsOnlyOneCorrelation(): void
    {
        [$empty] = $this->session([]);
        [$ledger, $path, $request] = $this->state($empty);
        $item = [
            'id' => 'fixture-repeated-subscription', 'billing_customer_id' => 'fixture-own-customer',
            'request_id' => $request, 'metadata' => ['sdk_validation_run' => $ledger->runId()],
        ];
        [$session, $http] = $this->session([
            new Response(201, [], '{"token":"fixture_token","expires_at":"2030-01-01T00:00:00Z"}'),
            new Response(200, [], json_encode(['items' => [$item], 'page_after' => 'next'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['items' => [$item], 'page_after' => null], JSON_THROW_ON_ERROR)),
        ]);
        $result = BillingReconciliation::load($path, $session)->inspect($session);
        self::assertSame(1, $result['subscription_count']);
        self::assertSame(1, $result['request_id_matches_count']);
        self::assertSame(1, $result['run_marker_matches_count']);
        self::assertSame(1, $result['both_match_count']);
        self::assertTrue($result['pagination_complete']);
        self::assertCount(3, $http->requests);
    }
}
