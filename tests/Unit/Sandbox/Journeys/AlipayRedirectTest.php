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

    private const string MOCK = 'https://api.sandbox.airwallex.com/pa/sandbox/sg/mck_PRIVATE_MOCK'
        . '?checksum=PRIVATE_MOCK%2f%2B+opaque';

    /** @return iterable<string,array{mixed,bool}> */
    public static function urls(): iterable
    {
        yield 'exact documented family with opaque query' => [self::URL, true];
        yield 'documented SG regional family' => [str_replace('/hk/', '/sg/', self::URL), true];
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
            'mock HK family' => str_replace('/redirect/', '/sandbox/', self::URL),
            'mock SG family' => str_replace('/redirect/hk/', '/sandbox/sg/', self::URL),
            'unknown region' => str_replace('/hk/', '/xx/', self::URL),
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

    /** @return iterable<string,array{string}> */
    public static function regionalUrls(): iterable
    {
        yield 'HK' => [self::URL];
        yield 'SG' => [str_replace('/hk/', '/sg/', self::URL)];
    }

    #[DataProvider('regionalUrls')]
    public function testExactSingleCredentialFreeGetAndOnlyHtmlPresenceBooleans(string $url): void
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
        $safe = $reader->inspect($url);
        self::assertSame(200, $safe['http_status']);
        self::assertTrue($safe['form_present']);
        self::assertTrue($safe['form_action_present']);
        self::assertTrue($safe['post_form_present']);
        self::assertSame('absent', $safe['location_category']);
        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame($url, (string) $request->getUri());
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
            $reader->inspect($url);
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
        yield 'same Sandbox SG' => [str_replace('/hk/', '/sg/', self::URL), 'sandbox_api_sg_redirect'];
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

    /** @return iterable<string,array{string,bool}> */
    public static function simulationLocations(): iterable
    {
        $url = 'https://api.sandbox.airwallex.com/pa/sandbox/sg/mck_PRIVATE_ID?checksum=PRIVATE_VALUE';
        yield 'documented shape only' => [$url, true];
        yield 'HK not documented by simulation example' => [str_replace('/sg/', '/hk/', $url), false];
        yield 'missing mock prefix' => [str_replace('mck_', '', $url), false];
        yield 'wrong host' => [str_replace('api.sandbox.', 'private.', $url), false];
        yield 'userinfo' => [str_replace('https://', 'https://PRIVATE_USER@', $url), false];
        yield 'port' => [str_replace('.com/', '.com:443/', $url), false];
        yield 'fragment' => [$url . '#PRIVATE_FRAGMENT', false];
        yield 'encoded identifier' => [str_replace('mck_PRIVATE_ID', 'mck_%41', $url), false];
    }

    #[DataProvider('simulationLocations')]
    public function testLocationShapeIsDiagnosticOnly(string $location, bool $shape): void
    {
        $mock = new MockHandler([new Response(302, ['Location' => $location])]);
        $safe = (new AlipayRedirect(new Client(['handler' => $mock])))->inspect(self::URL);
        self::assertSame($shape, $safe['location_documented_sg_simulation_shape']);
        self::assertFalse($safe['location_follow_allowed']);
        self::assertFalse($safe['location_uri_eligible']);
        self::assertSame(0, $mock->count());
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string}> */
    public static function blockedUrls(): iterable
    {
        yield 'production' => ['https://api.airwallex.com/PRIVATE_ID'];
        yield 'mock HK' => [str_replace('/redirect/', '/sandbox/', self::URL)];
        yield 'mock SG' => [str_replace('/redirect/hk/', '/sandbox/sg/', self::URL)];
        yield 'lookalike' => [str_replace('.com/', '.com.evil.test/', self::URL)];
        yield 'other Sandbox path' => ['https://api.sandbox.airwallex.com/pa/PRIVATE_ID?PRIVATE_QUERY=PRIVATE_VALUE'];
        yield 'regional traversal' => ['https://api.sandbox.airwallex.com/pa/redirect/sg/../PRIVATE_ID'];
    }

    #[DataProvider('blockedUrls')]
    public function testInvalidUrlStopsBeforeTransport(string $url): void
    {
        $mock = new MockHandler([new Response(200)]);
        try {
            (new AlipayRedirect(new Client(['handler' => $mock])))->inspect($url);
            self::fail('Production request must reject.');
        } catch (RuntimeException $error) {
            self::assertSame(1, $mock->count());
            self::assertStringNotContainsString('PRIVATE_', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    /** @return iterable<string,array{mixed,string,string,bool}> */
    public static function diagnosticUris(): iterable
    {
        yield 'HK' => [self::URL, 'sandbox_api', 'redirect_hk', true];
        yield 'SG' => [str_replace('/hk/', '/sg/', self::URL), 'sandbox_api', 'redirect_sg', true];
        yield 'mock HK' => [str_replace('/redirect/', '/sandbox/', self::URL), 'sandbox_api', 'sandbox_hk', false];
        yield 'mock SG' => [str_replace('/redirect/hk/', '/sandbox/sg/', self::URL),
            'sandbox_api', 'sandbox_sg', false];
        yield 'other Sandbox' => ['https://api.sandbox.airwallex.com/pa/PRIVATE_ID?PRIVATE_NAME=PRIVATE_VALUE',
            'sandbox_api', 'other_sandbox_path', false];
        yield 'unknown origin' => ['https://PRIVATE_HOST.test/PRIVATE_PATH?PRIVATE_NAME=PRIVATE_VALUE',
            'unverified_origin', 'unverified_origin', false];
        yield 'lookalike' => [str_replace('.com/', '.com.evil.test/', self::URL),
            'unverified_origin', 'redirect_hk', false];
        yield 'userinfo' => [str_replace('https://', 'https://PRIVATE_USER:PRIVATE_PASS@', self::URL),
            'sandbox_api', 'redirect_hk', false];
        yield 'traversal' => ['https://api.sandbox.airwallex.com/pa/redirect/sg/../PRIVATE_ID',
            'sandbox_api', 'redirect_sg', false];
        yield 'normalization' => ['https://api.sandbox.airwallex.com/pa/redirect/sg/PRIVATE_ID?checksum=PRIVATE_é',
            'sandbox_api', 'redirect_sg', false];
        yield 'malformed' => [self::URL . '%x', 'unclassified', 'unclassified', false];
        yield 'relative' => ['/PRIVATE_PATH?PRIVATE_QUERY=PRIVATE_VALUE', 'unclassified', 'unclassified', false];
        yield 'oversized' => [self::URL . str_repeat('x', 8193), 'unclassified', 'unclassified', false];
        yield 'non-string' => [['PRIVATE_NAME' => 'PRIVATE_VALUE'], 'unclassified', 'unclassified', false];
    }

    #[DataProvider('diagnosticUris')]
    public function testFixedDiagnosticsNeverExposeAnOpaqueUri(
        mixed $url,
        string $origin,
        string $family,
        bool $eligible,
    ): void {
        $safe = AlipayRedirect::diagnose($url);
        self::assertSame($origin, $safe['origin_category']);
        self::assertSame($family, $safe['path_family']);
        self::assertSame($eligible, $safe['eligible']);
        self::assertSame($eligible, AlipayRedirect::eligible($url));
        $serialized = json_encode($safe, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE_', 'https://', 'checksum=', 'synthetic_attempt', 'unchanged', '%2f'] as $private) {
            self::assertStringNotContainsString($private, $serialized);
        }
        foreach ($safe as $value) {
            if (!is_bool($value)) {
                self::assertContains($value, ['sandbox_api', 'unverified_origin', 'unclassified',
                    'redirect_hk', 'redirect_sg', 'sandbox_hk', 'sandbox_sg', 'other_sandbox_path']);
            }
        }
    }

    public function testKnownQueryPresenceAndValidationFlagsAreBoundedAndNeverRewriteTheQuery(): void
    {
        $url = 'https://api.sandbox.airwallex.com/pa/redirect/sg/PRIVATE_ID'
            . '?checksum=PRIVATE_ONE&PRIVATE_KEY=PRIVATE_VALUE&checksum=PRIVATE_TWO';
        $safe = AlipayRedirect::diagnose($url);
        foreach (
            ['lexically_valid', 'absolute_uri', 'https', 'sandbox_host_matches', 'userinfo_absent',
            'explicit_port_absent', 'fragment_absent', 'path_identifier_valid', 'exact_uri_preserved',
            'query_present', 'query_checksum_present', 'query_unlisted_keys_present',
            'query_repeated_key_present', 'query_scan_complete', 'eligible'] as $flag
        ) {
            self::assertTrue($safe[$flag]);
        }
        $many = 'https://api.sandbox.airwallex.com/pa/redirect/hk/PRIVATE_ID?'
            . implode('&', array_fill(0, 65, 'PRIVATE_KEY=PRIVATE_VALUE')) . '&checksum=PRIVATE_CHECKSUM';
        $limited = AlipayRedirect::diagnose($many);
        self::assertFalse($limited['query_scan_complete']);
        self::assertFalse($limited['query_checksum_present']);
        self::assertTrue($limited['eligible']); // Query contents remain opaque navigation data, not new requiredness.
        self::assertStringNotContainsString('PRIVATE_', json_encode($limited, JSON_THROW_ON_ERROR));
        foreach (
            [
            ['lexically_valid', $url . '%x'], ['absolute_uri', '/PRIVATE_ID'],
            ['https', str_replace('https:', 'http:', $url)],
            ['sandbox_host_matches', str_replace('.com/', '.com.evil.test/', $url)],
            ['userinfo_absent', str_replace('https://', 'https://PRIVATE_USER@', $url)],
            ['explicit_port_absent', str_replace('.com/', '.com:443/', $url)],
            ['fragment_absent', $url . '#PRIVATE_FRAGMENT'],
            ['path_identifier_valid', str_replace('/sg/PRIVATE_ID', '/sg/../PRIVATE_ID', $url)],
            ['exact_uri_preserved', $url . 'é'],
            ] as [$flag, $variant]
        ) {
            self::assertFalse(AlipayRedirect::diagnose($variant)[$flag]);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function readableMockQueries(): iterable
    {
        yield 'single opaque checksum' => [self::MOCK];
        yield 'no query as in public example' => [explode('?', self::MOCK, 2)[0]];
    }

    #[DataProvider('readableMockQueries')]
    public function testOptInReservesOneExactAnonymousMockGetAndNeverFollowsItsRedirect(string $location): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => $location, 'Set-Cookie' => 'PRIVATE_COOKIE=PRIVATE_VALUE']),
            new Response(
                302,
                ['Location' => $location, 'Content-Type' => 'text/html'],
                '<form method="POST" action="PRIVATE_ACTION">PRIVATE_NAME<script>PRIVATE_SCRIPT</script></form>'
            ),
            new Response(200, [], 'PRIVATE_FORBIDDEN_THIRD_REQUEST'),
        ]);
        $http = new RecordingClient(new Client(['handler' => $mock, 'cookies' => false]));
        $reservations = 0;
        $safe = (new AlipayRedirect($http))->inspect(self::URL, static function (string $url) use (
            &$reservations,
            $mock,
            $location,
        ): void {
            self::assertSame($location, $url);
            self::assertSame(2, $mock->count(), 'The reservation must precede the second send.');
            ++$reservations;
        });
        self::assertSame(1, $reservations);
        self::assertSame(1, $mock->count());
        self::assertCount(2, $http->requests);
        self::assertSame(self::URL, (string) $http->requests[0]->getUri());
        self::assertSame($location, (string) $http->requests[1]->getUri());
        foreach ($http->requests as $request) {
            self::assertSame('GET', $request->getMethod());
            self::assertSame('', (string) $request->getBody());
            foreach (['Authorization', 'Cookie', 'Referer', 'x-api-key', 'x-login-as', 'x-on-behalf-of'] as $header) {
                self::assertFalse($request->hasHeader($header));
            }
        }
        self::assertTrue($safe['mock_read_requested']);
        self::assertTrue($safe['mock_read_attempted']);
        self::assertTrue($safe['location_follow_allowed']);
        self::assertIsArray($safe['sandbox_mock']);
        self::assertSame(302, $safe['sandbox_mock']['http_status']);
        self::assertSame('sandbox_api_sg_mock', $safe['sandbox_mock']['origin_category']);
        self::assertTrue($safe['sandbox_mock']['post_form_present']);
        self::assertFalse($safe['sandbox_mock']['location_follow_allowed']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testDefaultKeepsTheExactMockLocationDiagnosticOnly(): void
    {
        $mock = new MockHandler([new Response(302, ['Location' => self::MOCK]), new Response(200)]);
        $safe = (new AlipayRedirect(new Client(['handler' => $mock])))->inspect(self::URL);
        self::assertFalse($safe['mock_read_requested']);
        self::assertFalse($safe['mock_read_attempted']);
        self::assertFalse($safe['location_follow_allowed']);
        self::assertSame(1, $mock->count());
    }

    /** @return iterable<string,array{int}> */
    public static function not302(): iterable
    {
        foreach ([200, 301, 303, 307, 308, 400, 500] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('not302')]
    public function testOnlyFirstResponse302CanUseTheOptIn(int $status): void
    {
        $mock = new MockHandler([new Response($status, ['Location' => self::MOCK]), new Response(200)]);
        $called = false;
        $safe = (new AlipayRedirect(new Client(['handler' => $mock])))->inspect(
            self::URL,
            static function () use (&$called): void {
                $called = true;
            }
        );
        self::assertFalse($called);
        self::assertFalse($safe['mock_read_attempted']);
        self::assertSame(1, $mock->count());
    }

    /** @return iterable<string,array{string}> */
    public static function unverifiedMockTargets(): iterable
    {
        foreach (
            [
            'production' => str_replace('api.sandbox.', 'api.', self::MOCK),
            'lookalike' => str_replace('.com/', '.com.evil.test/', self::MOCK),
            'HTTP' => str_replace('https:', 'http:', self::MOCK),
            'port443' => str_replace('.com/', '.com:443/', self::MOCK),
            'other port' => str_replace('.com/', '.com:444/', self::MOCK),
            'userinfo' => str_replace('https://', 'https://PRIVATE_USER@', self::MOCK),
            'fragment' => self::MOCK . '#PRIVATE_FRAGMENT',
            'HK' => str_replace('/sg/', '/hk/', self::MOCK),
            'no mock prefix' => str_replace('mck_', '', self::MOCK),
            'encoded ID' => str_replace('mck_PRIVATE_MOCK', 'mck_%41', self::MOCK),
            'traversal' => str_replace('mck_PRIVATE_MOCK', '../mck_PRIVATE_MOCK', self::MOCK),
            'repeated query' => self::MOCK . '&checksum=PRIVATE_SECOND',
            'unverified query' => self::MOCK . '&PRIVATE_NAME=PRIVATE_VALUE',
            'malformed' => self::MOCK . '%x',
            ] as $name => $url
        ) {
            yield $name => [$url];
        }
    }

    #[DataProvider('unverifiedMockTargets')]
    public function testOptInRejectsUnverifiedMockTargetsBeforeReservationOrSecondSend(string $url): void
    {
        $mock = new MockHandler([new Response(302, ['Location' => $url]), new Response(200)]);
        $reserved = false;
        $safe = (new AlipayRedirect(new Client(['handler' => $mock])))->inspect(
            self::URL,
            static function () use (&$reserved): void {
                $reserved = true;
            }
        );
        self::assertFalse($reserved);
        self::assertFalse($safe['mock_read_attempted']);
        self::assertFalse($safe['location_follow_allowed']);
        self::assertSame(1, $mock->count());
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testFailedReservationNeverSendsTheMockGetOrRetainsItsError(): void
    {
        $mock = new MockHandler([new Response(302, ['Location' => self::MOCK]), new Response(200)]);
        $safe = (new AlipayRedirect(new Client(['handler' => $mock])))->inspect(
            self::URL,
            static function (): void {
                throw new RuntimeException('PRIVATE_RESERVATION_ERROR');
            }
        );
        self::assertTrue($safe['mock_read_reservation_failed']);
        self::assertFalse($safe['mock_read_attempted']);
        self::assertFalse($safe['location_follow_allowed']);
        self::assertSame(1, $mock->count());
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testRepeatedLocationHeadersCannotSelectAMockTarget(): void
    {
        $mock = new MockHandler([new Response(302, ['Location' => [self::MOCK, self::MOCK]]), new Response(200)]);
        $reserved = false;
        $error = null;
        try {
            (new AlipayRedirect(new Client(['handler' => $mock])))->inspect(
                self::URL,
                static function () use (&$reserved): void {
                    $reserved = true;
                }
            );
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        self::assertNotNull($error);
        self::assertFalse($reserved);
        self::assertSame(1, $mock->count());
    }

    public function testFailedSecondStreamIsPrivateAndCannotBecomeAThirdRequest(): void
    {
        foreach (self::unsafeResponses() as [$response]) {
            $mock = new MockHandler([new Response(302, ['Location' => self::MOCK]), $response, new Response(200)]);
            $safe = (new AlipayRedirect(new Client(['handler' => $mock])))->inspect(self::URL, static fn () => null);
            self::assertTrue($safe['mock_read_attempted']);
            self::assertTrue($safe['mock_read_failed']);
            self::assertArrayNotHasKey('sandbox_mock', $safe);
            self::assertSame(1, $mock->count());
            self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
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
