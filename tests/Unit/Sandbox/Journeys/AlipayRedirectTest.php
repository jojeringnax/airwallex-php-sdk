<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class AlipayRedirectTest extends TestCase
{
    private const string URL = 'https://api.sandbox.airwallex.com/pa/redirect/hk/synthetic_attempt'
        . '?checksum=PRIVATE_SESSION%2f%2B+opaque&unchanged=1';

    /** @return iterable<string,array{mixed,bool}> */
    public static function urls(): iterable
    {
        yield 'exact documented family with opaque query' => [self::URL, true];
        yield 'documented family without invented query requirement' => [
            'https://api.sandbox.airwallex.com/pa/redirect/hk/synthetic_attempt', true,
        ];
        foreach (
            [
            'production' => str_replace('api.sandbox.', 'api.', self::URL),
            'lookalike' => str_replace('.com/', '.com.evil.test/', self::URL),
            'shared checkout' => 'https://checkout.airwallex.com/pay?s=PRIVATE_SESSION',
            'userinfo' => str_replace('https://', 'https://user:pass@', self::URL),
            'alternative port' => str_replace('.com/', '.com:444/', self::URL),
            'explicit normalized port' => str_replace('.com/', '.com:443/', self::URL),
            'HTTP' => str_replace('https:', 'http:', self::URL),
            'wallet app' => 'alipayhk://PRIVATE_SESSION',
            'fragment' => self::URL . '#PRIVATE_SESSION',
            'wrong path' => 'https://api.sandbox.airwallex.com/api/v1/pa/payment_intents/create',
            'path traversal' => 'https://api.sandbox.airwallex.com/pa/redirect/hk/../private',
            'encoded path traversal' => 'https://api.sandbox.airwallex.com/pa/redirect/hk/%2e%2e',
            'empty ID' => 'https://api.sandbox.airwallex.com/pa/redirect/hk/',
            'bad escape' => self::URL . '%x',
            'control' => self::URL . "\n",
            'backslash' => self::URL . '\\',
            'oversized' => self::URL . str_repeat('x', 8193),
            ] as $name => $value
        ) {
            yield $name => [$value, false];
        }
        yield 'not a string' => [[], false];
    }

    #[DataProvider('urls')]
    public function testStrictUrlGate(mixed $url, bool $expected): void
    {
        self::assertSame($expected, AlipayRedirect::eligible($url));
    }

    public function testExactSingleCredentialFreeGetAndOnlyHtmlPresenceBooleans(): void
    {
        $http = new class implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                return new Response(
                    200,
                    ['Content-Type' => 'text/html; charset=utf-8'],
                    '<!doctype html><html><body>PRIVATE_NAME<script>PRIVATE_SCRIPT</script>'
                    . '<form method="POST" action="https://private.example.test/PRIVATE_ACTION">'
                    . '<input name="private_id" value="PRIVATE_ID"></form></body></html>'
                );
            }
        };
        $reader = new AlipayRedirect($http);
        $safe = $reader->inspect(self::URL);
        self::assertSame(200, $safe['http_status']);
        self::assertTrue($safe['form_present']);
        self::assertTrue($safe['form_action_present']);
        self::assertTrue($safe['post_form_present']);
        self::assertSame('absent', $safe['location_category']);
        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame(self::URL, (string) $request->getUri());
        self::assertSame('GET', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
        foreach (
            ['Authorization', 'Cookie', 'Referer', 'x-api-key', 'x-client-id', 'x-login-as',
            'x-on-behalf-of', 'x-api-version', 'request_id'] as $header
        ) {
            self::assertFalse($request->hasHeader($header));
        }
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
        ob_start();
        var_dump($reader);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('PRIVATE_', $debug);
        try {
            $reader->inspect(self::URL);
            self::fail('A customer GET must not be repeated.');
        } catch (RuntimeException) {
            self::assertCount(1, $http->requests);
        }
        $this->expectException(RuntimeException::class);
        serialize($reader);
    }

    /** @return iterable<string,array{string,string}> */
    public static function locations(): iterable
    {
        yield 'same Sandbox' => [self::URL, 'sandbox_api_hk_redirect'];
        yield 'another origin' => ['https://private.example.test/PRIVATE_ID', 'other_https'];
        yield 'wallet app' => ['alipayhk://PRIVATE_ID', 'non_https_or_relative'];
        yield 'relative target' => ['/PRIVATE_ID', 'non_https_or_relative'];
    }

    #[DataProvider('locations')]
    public function testRedirectLocationIsObservedWithoutAnotherRequest(string $location, string $category): void
    {
        $mock = new MockHandler([new Response(302, ['Location' => $location])]);
        $safe = (new AlipayRedirect(new Client(['handler' => $mock])))->inspect(self::URL);
        self::assertSame(0, $mock->count());
        self::assertSame(302, $safe['http_status']);
        self::assertTrue($safe['location_present']);
        self::assertSame($category, $safe['location_category']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testInvalidUrlStopsBeforeTransport(): void
    {
        $mock = new MockHandler([new Response(200)]);
        try {
            (new AlipayRedirect(new Client(['handler' => $mock])))->inspect('https://api.airwallex.com/PRIVATE_ID');
            self::fail('Production request must reject.');
        } catch (RuntimeException $error) {
            self::assertSame(1, $mock->count());
            self::assertStringNotContainsString('PRIVATE_', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    /** @return iterable<string,array{Response}> */
    public static function unsafeResponses(): iterable
    {
        yield 'oversized HTML' => [new Response(200, ['Content-Type' => 'text/html'], str_repeat('x', 65537))];
        yield 'oversized Location' => [new Response(302, ['Location' => str_repeat('x', 8193)])];
        yield 'multiple Locations' => [new Response(302, ['Location' => ['https://one.test', 'https://two.test']])];
        $stream = new class (Utils::streamFor('PRIVATE_BODY')) implements StreamInterface {
            use StreamDecoratorTrait;

            private StreamInterface $stream;

            public function __construct(StreamInterface $stream)
            {
                $this->stream = $stream;
            }

            public function close(): void
            {
                $this->stream->close();
            }

            public function read($length): string
            {
                throw new RuntimeException('PRIVATE_STREAM_ERROR');
            }
        };
        yield 'broken stream' => [new Response(200, [], $stream)];
    }

    #[DataProvider('unsafeResponses')]
    public function testBoundedResponseFailureCannotExposeBodyOrBeRepeated(Response $response): void
    {
        $mock = new MockHandler([$response]);
        $reader = new AlipayRedirect(new Client(['handler' => $mock]));
        $caught = null;
        try {
            $reader->inspect(self::URL);
        } catch (RuntimeException $error) {
            $caught = $error;
            self::assertStringNotContainsString('PRIVATE_', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertNotNull($caught);
        $this->expectException(RuntimeException::class);
        $reader->inspect(self::URL);
    }
}
