<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryTransport;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryEvents;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookManagementProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;
use RuntimeException;

final class WebhookDeliveryTransportTest extends TestCase
{
    private const string HOST = 'https://api.sandbox.airwallex.com';

    public function testNonfiniteOverallDeadlineCannotPermitAnUnboundedSend(): void
    {
        $this->expectException(ProtocolException::class);
        new WebhookDeliveryTransport('account', overallDeadline: NAN);
    }

    public function testDefaultUsesRealStreamingAndVerifiedTlsWithoutCookiesOrRedirects(): void
    {
        $client = (new ReflectionProperty(WebhookDeliveryTransport::class, 'http'))
            ->getValue(new WebhookDeliveryTransport('fixture_account'));
        self::assertInstanceOf(Client::class, $client);
        self::assertInstanceOf(StreamHandler::class, $client->getConfig('handler'));
        foreach (
            ['stream' => true, 'verify' => true, 'cookies' => false, 'allow_redirects' => false,
            'http_errors' => false, 'read_timeout' => 5] as $key => $value
        ) {
            self::assertSame($value, $client->getConfig($key));
        }
    }

    /** @return iterable<string,array{string,string,array<string,string>}> */
    public static function forbidden(): iterable
    {
        yield 'production' => ['GET', 'https://api.airwallex.com/api/v1/webhooks/fixture', []];
        yield 'lookalike' => ['GET', 'https://api.sandbox.airwallex.com.evil.test/api/v1/webhooks/fixture', []];
        yield 'otherpath' => ['POST', self::HOST . '/api/v1/pa/payment_intents/fixture/confirm', []];
        yield 'discovery' => ['GET', self::HOST . '/api/v1/webhooks', []];
        yield 'foreignID' => ['GET', self::HOST . '/api/v1/webhooks/foreign', []];
        yield 'query' => ['GET', self::HOST . '/api/v1/webhooks/fixture?page=PRIVATE', []];
        yield 'fragment' => ['GET', self::HOST . '/api/v1/webhooks/fixture#PRIVATE', []];
        yield 'port' => ['GET', 'https://api.sandbox.airwallex.com:444/api/v1/webhooks/fixture', []];
        yield 'userinfo' => ['GET', 'https://PRIVATE@api.sandbox.airwallex.com/api/v1/webhooks/fixture', []];
        yield 'OBO' => ['GET', self::HOST . '/api/v1/webhooks/fixture', ['x-on-behalf-of' => 'PRIVATE']];
        yield 'cookie' => ['GET', self::HOST . '/api/v1/webhooks/fixture', ['Cookie' => 'PRIVATE']];
        yield 'referer' => ['GET', self::HOST . '/api/v1/webhooks/fixture', ['Referer' => 'PRIVATE']];
        yield 'wrongversion' => ['GET', self::HOST . '/api/v1/webhooks/fixture', ['x-api-version' => '2020-01-01']];
        yield 'keyonresource' => ['GET', self::HOST . '/api/v1/webhooks/fixture', ['x-api-key' => 'PRIVATE']];
        yield 'wronglogin' => ['POST', self::HOST . '/api/v1/authentication/login',
            ['x-login-as' => 'foreign_account']];
    }

    /** @param array<string,string> $headers */
    #[DataProvider('forbidden')]
    public function testScopeAndCredentialIsolationRejectsBeforeSending(
        string $method,
        string $url,
        array $headers,
    ): void {
        $client = new WebhookConfigFixtureClient([]);
        $transport = new WebhookDeliveryTransport('fixture_account', $client);
        $transport->allowWebhook('fixture');
        try {
            $transport->sendRequest(new Request($method, $url, $headers + ['x-api-version' => '2026-08-21']));
            self::fail('An unsupported request escaped the fixed delivery scope.');
        } catch (ProtocolException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('PRIVATE', (string) $error);
        }
        self::assertSame([], $client->requests);
    }

    #[DataProviderExternal(WebhookDeliveryProbeTest::class, 'malformedIdentities')]
    public function testMalformedBoundIdentityCannotSelectAParentOrDecodedPath(string $id): void
    {
        $client = new WebhookConfigFixtureClient([]);
        $transport = new WebhookDeliveryTransport('fixture_account', $client);
        foreach (['allowWebhook', 'allowIntent'] as $method) {
            try {
                $transport->$method($id);
                self::fail('An unsafe local path identity was accepted.');
            } catch (ProtocolException) {
                self::assertSame([], $client->requests);
            }
        }
    }

    public function testUnboundReturnedClaimCannotBeReadAndTargetCannotBeReplaced(): void
    {
        $client = new WebhookConfigFixtureClient([]);
        $transport = new WebhookDeliveryTransport('fixture_account', $client);
        try {
            $transport->sendRequest(new Request(
                'GET',
                self::HOST . '/api/v1/webhooks/fixture',
                ['x-api-version' => '2026-08-21']
            ));
            self::fail('An unbound target was followed.');
        } catch (ProtocolException) {
            self::assertSame([], $client->requests);
        }
        $transport->allowWebhook('fixture');
        $this->expectException(ProtocolException::class);
        $transport->allowWebhook('foreign');
    }

    public function testMutationFailureLatchesBeforeSendAndCannotReplay(): void
    {
        $client = new WebhookConfigFixtureClient([new RuntimeException('SYNTHETIC_PRIVATE_FAILURE')]);
        $transport = new WebhookDeliveryTransport('fixture_account', $client);
        $request = new Request('POST', self::HOST . '/api/v1/webhooks/create', ['x-api-version' => '2026-08-21'], '{}');
        foreach ([1, 2] as $attempt) {
            try {
                $transport->sendRequest($request);
                self::fail('The failed mutation was accepted/replayed.');
            } catch (\Throwable $error) {
                self::assertStringNotContainsString('SYNTHETIC_PRIVATE', (string) $error);
                self::assertTrue($transport->sent('create_webhook'));
                self::assertCount(1, $client->requests);
            }
        }
    }

    public function testInsufficientGlobalRequestBudgetRejectsBeforeMutationLatch(): void
    {
        $client = new WebhookConfigFixtureClient([]);
        $transport = new WebhookDeliveryTransport('fixture_account', $client, static fn (): float => 0.0, 44.0);
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/webhooks/create',
                ['x-api-version' => '2026-08-21'],
                '{}'
            ));
            self::fail('An unbounded late mutation was sent.');
        } catch (ProtocolException) {
            self::assertFalse($transport->sent('create_webhook'));
            self::assertSame([], $client->requests);
        }
    }

    /** @return iterable<string,array{int,string,string}> */
    public static function streams(): iterable
    {
        yield 'oversizedsuccess' => [201, 'normal', str_repeat('x', 65537)];
        yield 'oversizederror' => [400, 'normal', str_repeat('x', 65537)];
        yield 'stalled' => [201, 'stalled', 'PRIVATE'];
        yield 'readfailure' => [201, 'read-failure', 'PRIVATE'];
        yield 'closefailure' => [201, 'close-failure', 'PRIVATE'];
    }

    #[DataProvider('streams')]
    public function testResponseBoundsAndCloseFailuresStayPrivate(int $status, string $mode, string $body): void
    {
        $stream = new WebhookConfigFixtureStream($body, $mode);
        $client = new WebhookConfigFixtureClient([new Response($status, [], $stream)]);
        $transport = new WebhookDeliveryTransport('fixture_account', $client);
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/webhooks/create',
                ['x-api-version' => '2026-08-21'],
                '{}'
            ));
            self::fail('Unsafe response observation passed.');
        } catch (ProtocolException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('PRIVATE', (string) $error);
        }
        self::assertTrue($stream->closed);
        self::assertLessThanOrEqual(65537, $stream->bytesRead);
        self::assertTrue($transport->sent('create_webhook'));
    }

    public function testSlowPositiveProgressStillHitsElapsedReadDeadline(): void
    {
        $clock = 0.0;
        $stream = new WebhookConfigFixtureStream(str_repeat('x', 100), chunk: 1);
        $transport = new WebhookDeliveryTransport(
            'fixture_account',
            new WebhookConfigFixtureClient([new Response(201, [], $stream)]),
            static function () use (&$clock): float {
                return $clock += 2;
            }
        );
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/webhooks/create',
                ['x-api-version' => '2026-08-21'],
                '{}'
            ));
            self::fail('Slow positive progress escaped the deadline.');
        } catch (ProtocolException) {
            self::assertTrue($stream->closed);
            self::assertGreaterThan(0, $stream->bytesRead);
            self::assertLessThan(100, $stream->bytesRead);
        }
    }

    public function testDefaultProfileCannotAcquirePaymentsOrProducerAuthority(): void
    {
        $this->expectException(ProtocolException::class);
        new WebhookDeliveryTransport('fixture_account', managementProfile: WebhookManagementProfile::DefaultLogin);
    }

    /** @return array<string,string> */
    private static function defaultHeaders(bool $login = false): array
    {
        return ['Accept' => 'application/json', 'x-api-version' => '2026-08-21',
            ...($login ? ['x-client-id' => 'PRIVATE_CLIENT', 'x-api-key' => 'PRIVATE_KEY']
                : ['Authorization' => 'Bearer PRIVATE_TOKEN', 'Content-Type' => 'application/json'])];
    }

    /** @return array<string,mixed> */
    private static function defaultBody(): array
    {
        return ['url' => 'https://synthetic-private.trycloudflare.com/' . str_repeat('a', 64),
            'version' => '2026-08-21', 'events' => ['billing_checkout.created', 'billing_checkout.cancelled'],
            'request_id' => 'synthetic-fresh-request'];
    }

    /** @return iterable<string,array{string}> */
    public static function defaultForbiddenHeaders(): iterable
    {
        foreach (
            ['Host', 'x-login-as', 'Authorization', 'x-org-id', 'x-organization-id',
            'x-on-behalf-of', 'Cookie', 'Referer'] as $name
        ) {
            yield $name => [$name];
        }
    }

    #[DataProvider('defaultForbiddenHeaders')]
    public function testDefaultLoginDoesNotAcceptOtherHeaderContexts(string $name): void
    {
        $client = new WebhookConfigFixtureClient([]);
        $transport = new WebhookDeliveryTransport(
            'fixture_account',
            $client,
            managementOnly: true,
            managementProfile: WebhookManagementProfile::DefaultLogin
        );
        $this->expectException(ProtocolException::class);
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/authentication/login',
                array_replace(self::defaultHeaders(true), [$name => 'PRIVATE_OVERRIDE'])
            ));
        } finally {
            self::assertSame([], $client->requests);
        }
    }

    public function testDefaultInspectionPreservesWireBodyAndOneSend(): void
    {
        $body = json_encode(self::defaultBody(), JSON_THROW_ON_ERROR);
        $client = new WebhookConfigFixtureClient([new Response(201, [], '{}')]);
        $client->intercept = static function (RequestInterface $request) use ($body): void {
            self::assertSame(0, $request->getBody()->tell());
            self::assertSame($body, (string) $request->getBody());
            $request->getBody()->rewind();
        };
        $transport = new WebhookDeliveryTransport(
            'fixture_account',
            $client,
            managementOnly: true,
            managementProfile: WebhookManagementProfile::DefaultLogin
        );
        $request = new Request('POST', self::HOST . '/api/v1/webhooks/create', self::defaultHeaders(), $body);
        $transport->sendRequest($request);
        self::assertCount(1, $client->requests);
        $this->expectException(ProtocolException::class);
        $transport->sendRequest($request);
    }

    /** @return iterable<string,array{string}> */
    public static function defaultBadBodies(): iterable
    {
        foreach (
            ['url' => 'https://foreign.test/private', 'version' => '2024-06-14', 'request_id' => 123,
            'events' => ['payment_intent.created', 'payment_intent.cancelled'], 'org_id' => 'PRIVATE'] as $key => $value
        ) {
            yield $key => [json_encode(array_replace(self::defaultBody(), [$key => $value]), JSON_THROW_ON_ERROR)];
        }
        yield 'escaped duplicate version' => [substr(json_encode(self::defaultBody(), JSON_THROW_ON_ERROR), 0, -1)
            . ',"versi\\u006fn":"2026-08-21"}'];
    }

    #[DataProvider('defaultBadBodies')]
    public function testDefaultManagementCannotBroadenTheExactFreshPair(string $body): void
    {
        $client = new WebhookConfigFixtureClient([]);
        $transport = new WebhookDeliveryTransport(
            'fixture_account',
            $client,
            managementOnly: true,
            managementProfile: WebhookManagementProfile::DefaultLogin
        );
        $this->expectException(ProtocolException::class);
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/webhooks/create',
                self::defaultHeaders(),
                $body
            ));
        } finally {
            self::assertSame([], $client->requests);
            self::assertFalse($transport->sent('create_webhook'));
        }
    }

    public function testDefaultLoginCannotBypassNativeProofThroughUnexpectedSuccess(): void
    {
        $client = new WebhookConfigFixtureClient([new Response(
            200,
            [],
            '{"token":123,"expires_at":"2099-01-01T00:00:00Z"}'
        )]);
        $transport = new WebhookDeliveryTransport(
            'fixture_account',
            $client,
            managementOnly: true,
            managementProfile: WebhookManagementProfile::DefaultLogin
        );
        $this->expectException(ProtocolException::class);
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/authentication/login',
                self::defaultHeaders(true)
            ));
        } finally {
            self::assertCount(1, $client->requests);
        }
    }

    #[DataProviderExternal(WebhookConfigTransportTest::class, 'streamFailures')]
    public function testDefaultManagementRetainsStreamingFailureCaps(string $mode, int $status, string $body): void
    {
        $stream = new WebhookConfigFixtureStream($body, $mode);
        $client = new WebhookConfigFixtureClient([new Response($status, [], $stream)]);
        $transport = new WebhookDeliveryTransport(
            'fixture_account',
            $client,
            managementOnly: true,
            managementProfile: WebhookManagementProfile::DefaultLogin
        );
        $this->expectException(ProtocolException::class);
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/webhooks/create',
                self::defaultHeaders(),
                json_encode(self::defaultBody(), JSON_THROW_ON_ERROR)
            ));
        } finally {
            self::assertTrue($stream->closed);
            self::assertLessThanOrEqual(65537, $stream->bytesRead);
            self::assertCount(1, $client->requests);
            self::assertTrue($transport->sent('create_webhook'));
        }
    }
    public function testSubscriptionPairRequiresManagementOnlyAndRejectsCrossedApiBinding(): void
    {
        $pair = WebhookDeliveryEvents::Subscription;
        foreach (WebhookManagementProfile::cases() as $profile) {
            try {
                new WebhookDeliveryTransport('fixture', managementProfile: $profile, eventPair: $pair);
                self::fail('Subscription management granted a producer transport.');
            } catch (ProtocolException $error) {
                self::assertNull($error->getPrevious());
            }
            $bound = new WebhookDeliveryTransport(
                'fixture',
                managementOnly: true,
                managementProfile: $profile,
                eventPair: $pair
            );
            $bound->assertEventPair($pair);
            $bound->assertManagementProfile($profile);
            try {
                $bound->assertEventPair(WebhookDeliveryEvents::BillingCheckout);
                self::fail('Crossed explicit pair accepted.');
            } catch (ProtocolException $error) {
                self::assertNull($error->getPrevious());
            }
        }
        $old = new WebhookDeliveryTransport(
            'fixture',
            managementOnly: true,
            managementProfile: WebhookManagementProfile::DefaultLogin
        );
        $old->assertEventPair(WebhookDeliveryEvents::BillingCheckout);
        $this->expectException(ProtocolException::class);
        $old->assertEventPair($pair);
    }

    /** @return iterable<string,array{string,string|null}> */
    public static function accountSubscriptionBadLoginHeaders(): iterable
    {
        yield 'missing selector' => ['x-login-as', null];
        yield 'foreign selector' => ['x-login-as', 'foreign'];
        yield 'OBO' => ['x-on-behalf-of', 'foreign'];
        yield 'alternate context' => ['x-organization-id', 'foreign'];
        yield 'extra bearer' => ['Authorization', 'Bearer fixture'];
        yield 'cookie' => ['Cookie', 'fixture'];
        yield 'referer' => ['Referer', 'fixture'];
    }

    #[DataProvider('accountSubscriptionBadLoginHeaders')]
    public function testAccountSubscriptionLoginKeepsClosedHeaderRoles(string $name, ?string $value): void
    {
        $http = new WebhookConfigFixtureClient([]);
        $transport = new WebhookDeliveryTransport(
            'fixture',
            $http,
            managementOnly: true,
            managementProfile: WebhookManagementProfile::Account,
            eventPair: WebhookDeliveryEvents::Subscription
        );
        $request = new Request(
            'POST',
            self::HOST . '/api/v1/authentication/login',
            ['Accept' => 'application/json', 'x-api-version' => '2026-08-21', 'x-client-id' => 'client',
            'x-api-key' => 'key',
            'x-login-as' => 'fixture']
        );
        $request = $value === null ? $request->withoutHeader($name) : $request->withHeader($name, $value);
        $this->expectException(ProtocolException::class);
        try {
            $transport->sendRequest($request);
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function testAccountSubscriptionLoginRequiresSelected201WithoutChangingLegacyAccount(): void
    {
        $http = new WebhookConfigFixtureClient([new Response(200, [], '{"token":"fixture","expires_at":"2099"}')]);
        $transport = new WebhookDeliveryTransport(
            'fixture',
            $http,
            managementOnly: true,
            managementProfile: WebhookManagementProfile::Account,
            eventPair: WebhookDeliveryEvents::Subscription
        );
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/authentication/login',
                ['Accept' => 'application/json', 'x-api-version' => '2026-08-21', 'x-client-id' => 'client',
                'x-api-key' => 'key',
                'x-login-as' => 'fixture']
            ));
            self::fail('Nonselected login status was accepted.');
        } catch (ProtocolException) {
            self::assertCount(1, $http->requests);
        }
    }

    public function testNewDefaultPairCannotUseOldCheckoutBodyAndOldDefaultCannotUseSubscriptionBody(): void
    {
        $pairs = WebhookDeliveryEvents::cases();
        foreach ($pairs as $selected) {
            if ($selected === WebhookDeliveryEvents::Payments) {
                continue;
            }
            $other = $selected === WebhookDeliveryEvents::Subscription
                ? WebhookDeliveryEvents::BillingCheckout
                : WebhookDeliveryEvents::Subscription;
            $http = new WebhookConfigFixtureClient([]);
            $transport = new WebhookDeliveryTransport(
                'fixture',
                $http,
                managementOnly: true,
                managementProfile: WebhookManagementProfile::DefaultLogin,
                eventPair: $selected
            );
            $body = self::defaultBody();
            $body['events'] = $other->names();
            try {
                $transport->sendRequest(new Request(
                    'POST',
                    self::HOST . '/api/v1/webhooks/create',
                    self::defaultHeaders(),
                    json_encode($body, JSON_THROW_ON_ERROR)
                ));
                self::fail('Wrong event pair reached delegate.');
            } catch (ProtocolException) {
                self::assertSame([], $http->requests);
            }
        }
    }

    public function testSubscriptionOnlyManagementHeaderCapDoesNotRetroactivelyChangeOldDefault(): void
    {
        foreach ([false, true] as $subscription) {
            $stream = new WebhookConfigFixtureStream('{"token":"private","expires_at":"2099-01-01T00:00:00Z"}');
            $http = new WebhookConfigFixtureClient([new Response(201, ['X' => str_repeat('v', 16378)], $stream)]);
            $transport = new WebhookDeliveryTransport(
                'fixture',
                $http,
                managementOnly: true,
                managementProfile: WebhookManagementProfile::DefaultLogin,
                eventPair: $subscription ? WebhookDeliveryEvents::Subscription : null
            );
            try {
                $transport->sendRequest(new Request(
                    'POST',
                    self::HOST . '/api/v1/authentication/login',
                    self::defaultHeaders(true)
                ));
                self::assertFalse($subscription);
                self::assertGreaterThan(0, $stream->bytesRead);
            } catch (ProtocolException) {
                self::assertTrue($subscription);
                self::assertSame(0, $stream->bytesRead);
            }
            self::assertTrue($stream->closed);
        }
    }
    public function testAnExplicitPairCannotBroadenDefaultManagementToPayments(): void
    {
        $this->expectException(ProtocolException::class);
        new WebhookDeliveryTransport(
            'fixture',
            managementOnly: true,
            managementProfile: WebhookManagementProfile::DefaultLogin,
            eventPair: WebhookDeliveryEvents::Payments
        );
    }

    /** @return iterable<string,array{string}> */
    public static function subscriptionOriginStages(): iterable
    {
        foreach (
            ['equal', 'rollback', 'reserve_exact', 'reserve_late', 'native_expiry',
            'read_expiry', 'close_expiry', 'read_ten_seconds', 'close_ten_seconds'] as $stage
        ) {
            yield $stage => [$stage];
        }
    }

    #[DataProvider('subscriptionOriginStages')]
    public function testSubscriptionManagementRetainsTheActualFractionalOriginAndItsBounds(string $stage): void
    {
        $start = 100.1;
        $now = $start;
        $body = '{"token":"SYNTHETIC_TOKEN","expires_at":"2099-01-01T00:00:00Z"}';
        $stream = new SimulationProbeStream(
            $body,
            afterRead: static function () use (&$now, $stage, $start): void {
                if ($stage === 'read_expiry') {
                    $now = 400.0;
                }
                if ($stage === 'read_ten_seconds') {
                    $now = $start + 10.0;
                }
            },
            afterClose: static function () use (&$now, $stage, $start): void {
                if ($stage === 'close_expiry') {
                    $now = 400.0;
                }
                if ($stage === 'close_ten_seconds') {
                    $now = $start + 10.0;
                }
            }
        );
        $http = new WebhookConfigFixtureClient([new Response(201, [], $stream)]);
        $http->intercept = static function () use (&$now, $stage): void {
            if ($stage === 'native_expiry') {
                $now = 400.0;
            }
        };
        $reads = 0;
        $transport = new WebhookDeliveryTransport(
            'fixture',
            $http,
            static function () use (&$reads, &$now): float {
                ++$reads;
                return $now;
            },
            400.0,
            true,
            WebhookManagementProfile::DefaultLogin,
            WebhookDeliveryEvents::Subscription,
            subscriptionStarted: $start
        );
        self::assertSame(0, $reads);
        self::assertSame($start, (new ReflectionProperty($transport, 'subscriptionStarted'))->getValue($transport));
        $now = match ($stage) {
            'rollback' => 100.05, 'reserve_exact' => 355.0,
            'reserve_late' => 355.000001, default => $start
        };
        $error = null;
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/authentication/login',
                self::defaultHeaders(true)
            ));
        } catch (ProtocolException $caught) {
            $error = $caught;
        }
        if (in_array($stage, ['equal', 'reserve_exact'], true)) {
            self::assertNull($error);
        } else {
            self::assertInstanceOf(ProtocolException::class, $error);
        }
        $sent = !in_array($stage, ['rollback', 'reserve_late'], true);
        self::assertCount($sent ? 1 : 0, $http->requests);
        self::assertSame($sent, $stream->closed);
    }

    public function testSubscriptionManagementCapturesItsOwnOriginOrRequiresAnExplicitOriginal(): void
    {
        $reads = 0;
        $clock = static function () use (&$reads): float {
            ++$reads;
            return 100.1;
        };
        $http = new WebhookConfigFixtureClient([]);
        $transport = new WebhookDeliveryTransport(
            'fixture',
            $http,
            $clock,
            managementOnly: true,
            managementProfile: WebhookManagementProfile::DefaultLogin,
            eventPair: WebhookDeliveryEvents::Subscription
        );
        self::assertSame(1, $reads);
        self::assertSame(100.1, (new ReflectionProperty($transport, 'subscriptionStarted'))->getValue($transport));
        self::assertSame(400.0, (new ReflectionProperty($transport, 'overallDeadline'))->getValue($transport));
        $this->expectException(ProtocolException::class);
        try {
            new WebhookDeliveryTransport(
                'fixture',
                $http,
                $clock,
                400.0,
                true,
                WebhookManagementProfile::DefaultLogin,
                WebhookDeliveryEvents::Subscription
            );
        } finally {
            self::assertSame(1, $reads);
            self::assertSame([], $http->requests);
        }
    }
}
