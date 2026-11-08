<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\PaymentsDiagnostics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

final class PaymentsDiagnosticsTest extends TestCase
{
    private function diagnostic(Response $response): PaymentsDiagnostics
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);
        return new PaymentsDiagnostics($client);
    }

    public function testOnlyExactPublicCodesAndFieldTermsSurviveWithoutChangingResponseBytes(): void
    {
        $raw = '{"code":"configuration_error","source":"payment_method.card.cvc",'
            . '"message":"currency and payment_method.card.cvc: SENTINEL_PRIVATE customer-secret",'
            . '"details":{"metadata.private_customer_key":"SENTINEL_PRIVATE"}}';
        $response = new Response(400, [], $raw);
        $response->getBody()->seek(5);
        $diagnostic = $this->diagnostic($response);
        $returned = $diagnostic->sendRequest(new Request(
            'POST',
            'https://api.sandbox.airwallex.com/api/v1/pa/payment_intents/private-provider-id/confirm',
            ['Authorization' => 'Bearer PRIVATE_TOKEN'],
            'PRIVATE_REQUEST_BODY'
        ));
        self::assertSame($response, $returned);
        self::assertSame(5, $returned->getBody()->tell());
        self::assertSame($raw, (string) $returned->getBody());
        self::assertSame([
            'entries' => [[
                'operation' => 'intent_confirm', 'http_status' => 400, 'public_code' => 'configuration_error',
                'validation_fields' => ['currency', 'payment_method.card.cvc'],
            ]], 'entries_dropped' => 0,
        ], $diagnostic->report());
        $report = json_encode($diagnostic->report(), JSON_THROW_ON_ERROR);
        foreach (['PRIVATE', 'private-provider-id', 'customer-secret', 'private_customer_key'] as $secret) {
            self::assertStringNotContainsString($secret, $report);
        }
        self::assertStringNotContainsString('PRIVATE', print_r($diagnostic, true));
    }

    public function testOpaqueProviderCodesAndDynamicMetadataSourcesAreSuppressed(): void
    {
        $diagnostic = $this->diagnostic(new Response(
            400,
            [],
            '{"code":"SECRET_PROVIDER_VALUE","source":"metadata.SECRET_FIELD.currency",'
            . '"message":"SECRET_WORD","details":{"SECRET_KEY":"SECRET_VALUE"}}'
        ));
        $diagnostic->sendRequest(new Request(
            'POST',
            'https://api.sandbox.airwallex.com/api/v1/pa/payment_consents/private-id/verify'
        ));
        self::assertSame('unlisted', $diagnostic->report()['entries'][0]['public_code']);
        self::assertSame([], $diagnostic->report()['entries'][0]['validation_fields']);
        self::assertStringNotContainsString('SECRET', json_encode($diagnostic->report(), JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,string}> */
    public static function redirectOrigins(): iterable
    {
        yield 'current API' => [
            'https://api.sandbox.airwallex.com/PRIVATE_PATH?SECRET_QUERY#SECRET_FRAGMENT', 'sandbox_api',
        ];
        yield 'unverified legacy name' => [
            'https://api-demo.airwallex.com/PRIVATE_PATH?SECRET_QUERY', 'legacy_named_origin_unverified',
        ];
        yield 'Sandbox checkout' => [
            'https://checkout.sandbox.airwallex.com/#PRIVATE_FRAGMENT', 'documented_sandbox_checkout',
        ];
        yield 'unknown origin' => ['https://PRIVATE_HOST.example/PRIVATE_PATH?SECRET_QUERY', 'other_origin'];
    }

    #[DataProvider('redirectOrigins')]
    public function testUrlEvidenceContainsCategoriesAndBooleansWithoutAnyOriginIdentifier(
        string $url,
        string $origin,
    ): void {
        $diagnostic = $this->diagnostic(new Response(200, [], json_encode([
            'id' => 'PRIVATE_PROVIDER_ID', 'client_secret' => 'PRIVATE_CLIENT_SECRET',
            'next_action' => ['type' => 'redirect', 'url' => $url],
        ], JSON_THROW_ON_ERROR)));
        $diagnostic->sendRequest(new Request(
            'POST',
            'https://api.sandbox.airwallex.com/api/v1/pa/payment_intents/PRIVATE_ID/confirm'
        ));
        $shape = $diagnostic->report()['entries'][0]['url_shape'];
        self::assertIsArray($shape);
        self::assertSame($origin, $shape['origin_category']);
        $json = json_encode($diagnostic->report(), JSON_THROW_ON_ERROR);
        foreach (['PRIVATE', 'SECRET', 'airwallex.com', '.example', 'https://', '?', '#'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }

    public function testNonseekableAndOversizedStreamsAreNotConsumedForDiagnostics(): void
    {
        $raw = '{"code":"validation_error","message":"PRIVATE"}';
        $stream = new NoSeekStream(Utils::streamFor($raw));
        $diagnostic = $this->diagnostic(new Response(400, [], $stream));
        $response = $diagnostic->sendRequest(new Request(
            'POST',
            'https://api.sandbox.airwallex.com/api/v1/pa/payment_intents/private/confirm'
        ));
        self::assertSame(0, $response->getBody()->tell());
        self::assertSame($raw, (string) $response->getBody());
        self::assertSame('unavailable_or_not_object', $diagnostic->report()['entries'][0]['body_observation']);
        $oversized = '{"code":"validation_error","message":"' . str_repeat('PRIVATE', 12000) . '"}';
        $diagnostic = $this->diagnostic(new Response(400, [], $oversized));
        $response = $diagnostic->sendRequest(new Request(
            'POST',
            'https://api.sandbox.airwallex.com/api/v1/pa/payment_intents/private/confirm'
        ));
        self::assertSame(0, $response->getBody()->tell());
        self::assertStringNotContainsString('PRIVATE', json_encode($diagnostic->report(), JSON_THROW_ON_ERROR));
    }

    public function testAuthenticationAndOtherPayloadsAreNeverInspectedAndRecordCountIsBounded(): void
    {
        $diagnostic = $this->diagnostic(new Response(201, [], '{"token":"PRIVATE_TOKEN"}'));
        for ($index = 0; $index < 100; ++$index) {
            $diagnostic->sendRequest(new Request(
                'POST',
                'https://api.sandbox.airwallex.com/api/v1/authentication/login',
            ));
        }
        self::assertCount(96, $diagnostic->report()['entries']);
        self::assertSame(4, $diagnostic->report()['entries_dropped']);
        self::assertSame(['operation' => 'authentication', 'http_status' => 201], $diagnostic->report()['entries'][0]);
        self::assertStringNotContainsString('PRIVATE', json_encode($diagnostic->report(), JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        serialize($diagnostic);
    }
}
