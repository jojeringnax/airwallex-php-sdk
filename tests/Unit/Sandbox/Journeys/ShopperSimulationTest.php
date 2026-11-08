<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShopperSimulationTest extends TestCase
{
    /**
     * @param list<Response> $responses
     * @return array{SandboxSession,RecordingClient}
     */
    private function session(array $responses, string $context = 'account'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'airwallex-simulation-fixture-');
        self::assertNotFalse($path);
        try {
            file_put_contents(
                $path,
                "AIRWALLEX_CLIENT_ID=fixture_client\nAIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_key\n"
                . "AIRWALLEX_ACCOUNT_ID=fixture_account\n",
            );
            $recording = new RecordingClient(new Client(['handler' => new MockHandler($responses)]));
            return [new SandboxSession(Configuration::load('sandbox', $path), $context, $recording), $recording];
        } finally {
            unlink($path);
        }
    }

    public function testAccountSimulationAcceptsEmptySuccessAndReusesItsToken(): void
    {
        [$session, $recording] = $this->session([
            new Response(201, [], '{"token":"synthetic-token","expires_at":"2100-01-01T00:00:00Z"}'),
            new Response(200), new Response(200),
        ]);
        $url = 'https://api.sandbox.airwallex.com/pa/sandbox/sg/synthetic-owned-session';
        $session->simulateShopperAction('verify', $url);
        $session->simulateShopperAction('pay', $url);
        self::assertCount(3, $recording->requests);
        self::assertSame('fixture_account', $recording->requests[0]->getHeaderLine('x-login-as'));
        self::assertSame(1, $session->loginRequests());
        foreach (array_slice($recording->requests, 1) as $request) {
            self::assertSame('POST', $request->getMethod());
            self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
            self::assertSame('2026-08-21', $request->getHeaderLine('x-api-version'));
            self::assertSame(
                ['url' => $url],
                json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR),
            );
        }
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function rejectedRequests(): iterable
    {
        yield 'organization context' => [
            'pay', 'https://api.sandbox.airwallex.com/pa/sandbox/sg/session', 'organization',
        ];
        yield 'unverified legacy origin' => ['pay', 'https://api-demo.airwallex.com/pa/sandbox/sg/session', 'account'];
        yield 'production host' => ['pay', 'https://api.airwallex.com/pa/sandbox/sg/session', 'account'];
        yield 'arbitrary host' => ['pay', 'https://example.test/pa/sandbox/sg/session', 'account'];
        yield 'HTTP URL' => ['pay', 'http://api.sandbox.airwallex.com/pa/sandbox/sg/session', 'account'];
        yield 'user info' => ['pay', 'https://private@api.sandbox.airwallex.com/pa/sandbox/sg/session', 'account'];
        yield 'unexpected port' => ['pay', 'https://api.sandbox.airwallex.com:444/pa/sandbox/sg/session', 'account'];
        yield 'URL whitespace' => ['pay', 'https://api.sandbox.airwallex.com/private path', 'account'];
        yield 'unknown action' => ['capture', 'https://api.sandbox.airwallex.com/pa/sandbox/sg/session', 'account'];
    }

    #[DataProvider('rejectedRequests')]
    public function testUnknownOrForeignSimulationInputsFailBeforeEvenAuthentication(
        string $action,
        string $url,
        string $context,
    ): void {
        [$session, $recording] = $this->session([], $context);
        try {
            $this->expectException(ValidationException::class);
            $session->simulateShopperAction($action, $url);
        } finally {
            self::assertSame([], $recording->requests);
        }
    }

    public function testUnexpectedSuccessPayloadIsRejectedWithoutPrintingIt(): void
    {
        [$session, $recording] = $this->session([
            new Response(201, [], '{"token":"synthetic-token","expires_at":"2100-01-01T00:00:00Z"}'),
            new Response(200, [], 'SYNTHETIC_PRIVATE_PAYLOAD'),
        ]);
        try {
            $session->simulateShopperAction('pay', 'https://api.sandbox.airwallex.com/pa/sandbox/sg/session');
            self::fail('Unexpected response was accepted.');
        } catch (ProtocolException $error) {
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE_PAYLOAD', (string) $error);
            self::assertCount(2, $recording->requests);
        }
    }

    public function testUnauthorizedSimulationIsNotReplayedAndNextIndependentActionLogsInAgain(): void
    {
        $login = new Response(201, [], '{"token":"synthetic-token","expires_at":"2100-01-01T00:00:00Z"}');
        [$session, $recording] = $this->session([
            $login, new Response(401, [], '{"code":"unauthorized"}'), $login, new Response(200),
        ]);
        $url = 'https://api.sandbox.airwallex.com/pa/sandbox/sg/session';
        try {
            $session->simulateShopperAction('verify', $url);
            self::fail('Unauthorized mutation was accepted.');
        } catch (ApiException $error) {
            self::assertSame(401, $error->httpStatus());
            self::assertCount(2, $recording->requests);
        }
        $session->simulateShopperAction('pay', $url);
        self::assertCount(4, $recording->requests);
        self::assertSame(2, $session->loginRequests());
        self::assertStringEndsWith('/verify', $recording->requests[1]->getUri()->getPath());
        self::assertStringEndsWith('/pay', $recording->requests[3]->getUri()->getPath());
    }

    /** @return iterable<string,array{string}> */
    public static function documentedSandboxRedirects(): iterable
    {
        yield 'Sandbox checkout fragment' => [
            'https://checkout.sandbox.airwallex.com/#/page/test/private?token=sentinel',
        ];
        yield 'opaque Sandbox API path' => ['https://api.sandbox.airwallex.com/future/path?private=sentinel#state'];
    }

    #[DataProvider('documentedSandboxRedirects')]
    public function testReturnedSandboxUrlRemainsOpaqueBodyDataWhileHttpTargetStaysFixed(string $url): void
    {
        [$session, $recording] = $this->session([
            new Response(201, [], '{"token":"synthetic-token","expires_at":"2100-01-01T00:00:00Z"}'),
            new Response(200),
        ]);
        $session->simulateShopperAction('pay', $url);
        self::assertCount(2, $recording->requests);
        $request = $recording->requests[1];
        self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
        self::assertSame('/api/v1/simulation/pa/shopper_actions/pay', $request->getUri()->getPath());
        self::assertSame('', $request->getUri()->getQuery());
        self::assertSame('', $request->getUri()->getFragment());
        self::assertSame(['url' => $url], json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }
}
