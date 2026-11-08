<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingWebhookTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class BillingWebhookTransportTest extends TestCase
{
    private const string HOST = 'https://api.sandbox.airwallex.com';

    public function testNonfiniteOverallDeadlineCannotPermitAnUnboundedSend(): void
    {
        $this->expectException(ProtocolException::class);
        new BillingWebhookTransport('account', true, overallDeadline: NAN);
    }

    public function testDefaultUsesRealStreamingAndVerifiedTlsWithoutCookiesOrRedirects(): void
    {
        $client = (new ReflectionProperty(BillingWebhookTransport::class, 'http'))
            ->getValue(new BillingWebhookTransport('fixture_account', true));
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
        yield 'production' => ['GET', 'https://api.airwallex.com/api/v1/billing/billing_checkouts/fixture', []];
        yield 'lookalike' => [
            'GET', 'https://api.sandbox.airwallex.com.evil.test/api/v1/billing/billing_checkouts/fixture', []];
        yield 'otherpath' => ['POST', self::HOST . '/api/v1/pa/payment_intents/fixture/confirm', []];
        yield 'discovery' => ['GET', self::HOST . '/api/v1/webhooks', []];
        yield 'foreignID' => ['GET', self::HOST . '/api/v1/billing/billing_checkouts/foreign', []];
        yield 'query' => ['GET', self::HOST . '/api/v1/billing/billing_checkouts/fixture?page=PRIVATE', []];
        yield 'fragment' => ['GET', self::HOST . '/api/v1/billing/billing_checkouts/fixture#PRIVATE', []];
        yield 'port' => ['GET', 'https://api.sandbox.airwallex.com:444/api/v1/billing/billing_checkouts/fixture', []];
        yield 'userinfo' => [
            'GET', 'https://PRIVATE@api.sandbox.airwallex.com/api/v1/billing/billing_checkouts/fixture', []];
        yield 'OBO' => [
            'GET', self::HOST . '/api/v1/billing/billing_checkouts/fixture', ['x-on-behalf-of' => 'PRIVATE']];
        yield 'cookie' => ['GET', self::HOST . '/api/v1/billing/billing_checkouts/fixture', ['Cookie' => 'PRIVATE']];
        yield 'referer' => ['GET', self::HOST . '/api/v1/billing/billing_checkouts/fixture', ['Referer' => 'PRIVATE']];
        yield 'wrongversion' => [
            'GET', self::HOST . '/api/v1/billing/billing_checkouts/fixture', ['x-api-version' => '2020-01-01']];
        yield 'keyonresource' => [
            'GET', self::HOST . '/api/v1/billing/billing_checkouts/fixture', ['x-api-key' => 'PRIVATE']];
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
        $transport = new BillingWebhookTransport('fixture_account', true, $client);
        $transport->allowCheckout('fixture');
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
        $transport = new BillingWebhookTransport('fixture_account', true, $client);
        foreach (['allowCheckout', 'allowCustomer'] as $method) {
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
        $transport = new BillingWebhookTransport('fixture_account', true, $client);
        try {
            $transport->sendRequest(new Request(
                'GET',
                self::HOST . '/api/v1/billing/billing_checkouts/fixture',
                ['x-api-version' => '2026-08-21']
            ));
            self::fail('An unbound target was followed.');
        } catch (ProtocolException) {
            self::assertSame([], $client->requests);
        }
        $transport->allowCheckout('fixture');
        $this->expectException(ProtocolException::class);
        $transport->allowCheckout('foreign');
    }

    public function testMutationFailureLatchesBeforeSendAndCannotReplay(): void
    {
        $client = new WebhookConfigFixtureClient([new RuntimeException('SYNTHETIC_PRIVATE_FAILURE')]);
        $transport = new BillingWebhookTransport('fixture_account', true, $client);
        $request = new Request(
            'POST',
            self::HOST . '/api/v1/billing/billing_checkouts/create',
            ['x-api-version' => '2026-08-21'],
            '{}'
        );
        foreach ([1, 2] as $attempt) {
            try {
                $transport->sendRequest($request);
                self::fail('The failed mutation was accepted/replayed.');
            } catch (\Throwable $error) {
                self::assertStringNotContainsString('SYNTHETIC_PRIVATE', (string) $error);
                self::assertTrue($transport->sent('create_checkout'));
                self::assertCount(1, $client->requests);
            }
        }
    }

    public function testInsufficientGlobalRequestBudgetRejectsBeforeMutationLatch(): void
    {
        $client = new WebhookConfigFixtureClient([]);
        $transport = new BillingWebhookTransport('fixture_account', true, $client, static fn (): float => 0.0, 44.0);
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/billing/billing_checkouts/create',
                ['x-api-version' => '2026-08-21'],
                '{}'
            ));
            self::fail('An unbounded late mutation was sent.');
        } catch (ProtocolException) {
            self::assertFalse($transport->sent('create_checkout'));
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
        $transport = new BillingWebhookTransport('fixture_account', true, $client);
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/billing/billing_checkouts/create',
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
        self::assertTrue($transport->sent('create_checkout'));
    }

    public function testSlowPositiveProgressStillHitsElapsedReadDeadline(): void
    {
        $clock = 0.0;
        $stream = new WebhookConfigFixtureStream(str_repeat('x', 100), chunk: 1);
        $transport = new BillingWebhookTransport(
            'fixture_account',
            true,
            new WebhookConfigFixtureClient([new Response(201, [], $stream)]),
            static function () use (&$clock): float {
                return $clock += 2;
            }
        );
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/billing/billing_checkouts/create',
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
    public function testAccountPrerequisiteRoleCannotBorrowOrganizationProducerAuthority(): void
    {
        $client = new WebhookConfigFixtureClient([]);
        $transport = new BillingWebhookTransport('fixture_account', false, $client);
        $transport->allowCheckout('fixture');
        try {
            $transport->sendRequest(new Request(
                'GET',
                self::HOST . '/api/v1/billing/billing_checkouts/fixture',
                ['x-api-version' => '2026-08-21']
            ));
            self::fail('The prerequisite borrowed producer authority.');
        } catch (ProtocolException) {
            self::assertSame([], $client->requests);
        }
    }

    public function testOversizedParsedHeadersCloseBeforeReadingAnyBody(): void
    {
        $stream = new WebhookConfigFixtureStream('PRIVATE');
        $client = new WebhookConfigFixtureClient([new Response(201, ['X-Fixture' => str_repeat('x', 16385)], $stream)]);
        $transport = new BillingWebhookTransport('fixture_account', true, $client);
        try {
            $transport->sendRequest(new Request(
                'POST',
                self::HOST . '/api/v1/billing/billing_checkouts/create',
                ['x-api-version' => '2026-08-21'],
                '{}'
            ));
            self::fail('An oversized header passed.');
        } catch (ProtocolException $error) {
            self::assertNull($error->getPrevious());
            self::assertSame(0, $stream->bytesRead);
            self::assertTrue($stream->closed);
        }
    }
    public function testBothSuccessResponseProofBoundariesCloseTheStreamWhenDuplicateKeysAreRejected(): void
    {
        foreach ([true, false] as $billing) {
            $stream = new WebhookConfigFixtureStream('{"id":"PRIVATE","id":"PRIVATE"}');
            $client = new WebhookConfigFixtureClient(
                [new Response(201, ['Content-Type' => 'application/json'], $stream)]
            );
            $transport = $billing ? new BillingWebhookTransport('fixture_account', true, $client)
                : new \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookDeliveryTransport(
                    'fixture_account',
                    $client
                );
            $path = $billing ? '/api/v1/billing/billing_checkouts/create' : '/api/v1/pa/payment_intents/create';
            try {
                $transport->sendRequest(new Request(
                    'POST',
                    self::HOST . $path,
                    ['x-api-version' => '2026-08-21'],
                    '{}'
                ));
                self::fail('An ambiguous success body reached its dependent proof.');
            } catch (ProtocolException $error) {
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('PRIVATE', (string) $error);
                self::assertTrue($stream->closed);
                self::assertGreaterThan(0, $stream->bytesRead);
                self::assertTrue($transport->sent($billing ? 'create_checkout' : 'create_intent'));
                self::assertCount(1, $client->requests);
            }
        }
    }
    /** @return iterable<string,array{string,bool}> */
    public static function literalNumericStringFields(): iterable
    {
        foreach (['id', 'billing_customer_id', 'legal_entity_id', 'linked_payment_account_id'] as $field) {
            yield $field . ' quoted numeric' => [$field, true];
            yield $field . ' raw number' => [$field, false];
        }
    }

    #[DataProvider('literalNumericStringFields')]
    public function testQuotedOpaqueNumericSuccessFieldsAreAllowedWhileRawNumbersCannotBecomeStrings(
        string $field,
        bool $quoted,
    ): void {
        $data = ['id' => '123', 'billing_customer_id' => '123', 'legal_entity_id' => '123',
            'linked_payment_account_id' => '123', 'mode' => 'SETUP', 'ui_mode' => 'HOSTED',
            'currency' => 'EUR', 'status' => 'ACTIVE', 'url' => null, 'decimal_probe' => '0.100000000000000000001'];
        if (!$quoted) {
            $data[$field] = 123;
        }
        $raw = str_replace(
            '"0.100000000000000000001"',
            '0.100000000000000000001',
            json_encode($data, JSON_THROW_ON_ERROR)
        );
        $stream = new WebhookConfigFixtureStream($raw);
        $client = new WebhookConfigFixtureClient([new Response(200, ['Content-Type' => 'application/json'], $stream)]);
        $transport = new BillingWebhookTransport('123', true, $client);
        $transport->allowCheckout('123');
        try {
            $response = $transport->sendRequest(new Request(
                'GET',
                self::HOST . '/api/v1/billing/billing_checkouts/123',
                ['x-api-version' => '2026-08-21']
            ));
            self::assertTrue($quoted, 'A raw number acquired string-type success proof.');
            self::assertSame($raw, (string) $response->getBody());
        } catch (ProtocolException $error) {
            self::assertFalse($quoted, 'A quoted opaque string was refused by a prefix assumption.');
            self::assertNull($error->getPrevious());
        }
        self::assertTrue($stream->closed);
        self::assertCount(1, $client->requests);
    }
}
