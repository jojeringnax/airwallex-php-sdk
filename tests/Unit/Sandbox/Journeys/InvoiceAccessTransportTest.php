<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\Uri;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\TransportException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoiceAccessPolicy;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoiceAccessTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;
use RuntimeException;

final class InvoiceAccessTransportTest extends TestCase
{
    private const string PDF = InvoiceAccessPolicy::ORIGIN . '/billing/invoices/pdf?s=PRIVATE_PDF';

    private static function request(bool $pdf = true): Request
    {
        return new Request('GET', $pdf ? self::PDF : InvoiceAccessPolicy::PAYER, $pdf ? [
            'Accept' => 'application/pdf', 'Accept-Encoding' => 'identity', 'Range' => 'bytes=0-1023',
        ] : ['Accept' => 'application/json', 'Accept-Encoding' => 'identity',
            'Authorization' => 'Bearer PRIVATE_CAPABILITY']);
    }

    public function testDefaultIsExplicitStreamingWithNoCredentialCookieRedirectOrDecodeMiddleware(): void
    {
        $property = new ReflectionProperty(InvoiceAccessTransport::class, 'http');
        $client = $property->getValue(new InvoiceAccessTransport(null, null));
        self::assertInstanceOf(Client::class, $client);
        self::assertInstanceOf(StreamHandler::class, $client->getConfig('handler'));
        foreach (
            ['stream' => true, 'verify' => true, 'cookies' => false, 'allow_redirects' => false,
            'http_errors' => false, 'decode_content' => false, 'read_timeout' => 5,
            'timeout' => 30, 'connect_timeout' => 10] as $name => $value
        ) {
            self::assertSame($value, $client->getConfig($name));
        }
    }

    public function testPdfPrefixStopsAtSentinelAndClosesWithoutUnboundedStringificationOrReplay(): void
    {
        $stream = new SimulationProbeStream(str_repeat('x', 100000));
        $client = new RecordingClient(new Client(['handler' => new MockHandler([new Response(200, [], $stream)])]));
        $http = new InvoiceAccessTransport(self::PDF, null, $client);
        $response = $http->sendRequest(self::request());
        self::assertSame(1025, strlen((string) $response->getBody()));
        self::assertSame(1025, $stream->bytes);
        self::assertTrue($stream->closed);
        $this->expectException(ProtocolException::class);
        $http->sendRequest(self::request());
    }

    public function testErrorOrRedirectClosesWithoutReadingOrFollowingBody(): void
    {
        $stream = new SimulationProbeStream('PRIVATE_ERROR', 'broken');
        $client = new RecordingClient(new Client(['handler' => new MockHandler([
            new Response(302, ['Location' => 'https://PRIVATE_THIRD_PARTY.test'], $stream),
        ])]));
        $response = (new InvoiceAccessTransport(self::PDF, null, $client))->sendRequest(self::request());
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(0, $stream->bytes);
        self::assertTrue($stream->closed);
        self::assertCount(1, $client->requests);
    }

    public function testRedirectTargetRequiresOptInItsActualFirstResponseAndOnePrivateBinding(): void
    {
        $target = InvoiceAccessPolicy::ORIGIN . '/next?cap=PRIVATE';
        foreach ([false, true] as $allow) {
            $client = new RecordingClient(new Client(['handler' => new MockHandler([
                new Response(301, ['Location' => '/next?cap=PRIVATE']),
                new Response(200, ['Content-Type' => 'application/pdf'], str_repeat('x', 100000)),
            ])]));
            $http = new InvoiceAccessTransport(self::PDF, null, $client, allowPdfRedirect: $allow);
            try {
                $http->bindPdfRedirect($target);
                self::fail('A target was bound before its original response.');
            } catch (ProtocolException) {
                self::assertSame([], $client->requests);
            }
            $http->sendRequest(self::request());
            try {
                $http->bindPdfRedirect(InvoiceAccessPolicy::ORIGIN . '/unreturned?PRIVATE');
                self::fail('A caller target replaced the original response.');
            } catch (ProtocolException) {
                self::assertCount(1, $client->requests);
            }
            if (!$allow) {
                try {
                    $http->bindPdfRedirect($target);
                    self::fail('A default request acquired a redirect allowance.');
                } catch (ProtocolException) {
                    self::assertCount(1, $client->requests);
                }
                continue;
            }
            $http->bindPdfRedirect($target);
            $request = self::request()->withUri(new Uri($target));
            $response = $http->sendRequest($request);
            self::assertSame(1025, strlen((string) $response->getBody()));
            self::assertCount(2, $client->requests);
            try {
                $http->sendRequest($request);
                self::fail('A completed redirect read was replayed.');
            } catch (ProtocolException) {
                self::assertCount(2, $client->requests);
            }
            try {
                $http->bindPdfRedirect($target);
                self::fail('A redirect allowance was reset.');
            } catch (ProtocolException) {
                self::assertCount(2, $client->requests);
            }
        }
    }

    #[DataProvider('rejectedData')]
    public function testRedirectCannotForwardCredentialsOrAnyUnreviewedApplicationHeader(string $name): void
    {
        $target = InvoiceAccessPolicy::ORIGIN . '/next?cap=PRIVATE';
        $client = new RecordingClient(new Client(['handler' => new MockHandler([
            new Response(301, ['Location' => $target]),
        ])]));
        $http = new InvoiceAccessTransport(self::PDF, null, $client, allowPdfRedirect: true);
        $http->sendRequest(self::request());
        $http->bindPdfRedirect($target);
        try {
            $http->sendRequest(self::request()->withUri(new Uri($target))->withHeader($name, 'PRIVATE'));
            self::fail('An extra header entered the redirect read.');
        } catch (ProtocolException) {
            self::assertCount(1, $client->requests);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function redirectReadFailures(): iterable
    {
        foreach (['transport', 'stalled', 'broken', 'close_failure', 'slow'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('redirectReadFailures')]
    public function testFailedRedirectReadIsBoundedClosedPrivateAndNeverReplayable(string $mode): void
    {
        $target = InvoiceAccessPolicy::ORIGIN . '/next?cap=PRIVATE';
        $stream = new SimulationProbeStream(str_repeat('x', 100000), $mode === 'slow' ? 'one_byte' : $mode);
        $clockCalls = 0;
        $client = new RecordingClient(new Client(['handler' => new MockHandler([
            new Response(301, ['Location' => $target]),
            $mode === 'transport' ? new RuntimeException('PRIVATE_TRANSPORT') : new Response(200, [], $stream),
        ])]));
        $http = new InvoiceAccessTransport(
            self::PDF,
            null,
            $client,
            static function () use (&$clockCalls, $mode): float {
                return $mode === 'slow' ? ++$clockCalls * 2.0 : 0.0;
            },
            allowPdfRedirect: true
        );
        $http->sendRequest(self::request());
        $http->bindPdfRedirect($target);
        foreach ([1, 2] as $attempt) {
            try {
                $http->sendRequest(self::request()->withUri(new Uri($target)));
                self::fail('A failed redirect read passed or replayed.');
            } catch (ProtocolException | TransportException $error) {
                self::assertStringNotContainsString('PRIVATE', (string) $error);
                self::assertNull($error->getPrevious());
                self::assertCount(2, $client->requests);
            }
        }
        if ($mode !== 'transport') {
            self::assertTrue($stream->closed);
            self::assertLessThanOrEqual(1025, $stream->bytes);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function rejectedData(): iterable
    {
        foreach (
            ['Authorization', 'Cookie', 'Referer', 'x-api-version', 'x-on-behalf-of',
            'x-client-id', 'x-api-key', 'x-login-as', 'client-secret'] as $name
        ) {
            yield $name => [$name];
        }
    }

    #[DataProvider('rejectedData')]
    public function testPdfNeverReceivesCredentialsOrUnreviewedHeaders(string $name): void
    {
        $client = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        $http = new InvoiceAccessTransport(self::PDF, 'PRIVATE_CAPABILITY', $client);
        try {
            $http->sendRequest(self::request()->withHeader($name, 'PRIVATE_CONTEXT'));
            self::fail('An unreviewed header was accepted.');
        } catch (ProtocolException $error) {
            self::assertNull($error->getPrevious());
            self::assertSame([], $client->requests);
        }
    }

    /** @return iterable<string,array{RequestInterface}> */
    public static function unboundRequests(): iterable
    {
        yield 'method' => [self::request()->withMethod('POST')];
        yield 'body' => [self::request()->withBody(Utils::streamFor('PRIVATE_BODY'))];
        yield 'production' => [new Request('GET', 'https://invoice.airwallex.com/PRIVATE')];
        yield 'query' => [new Request('GET', InvoiceAccessPolicy::PAYER . '?PRIVATE')];
        yield 'API bearer' => [self::request(false)->withHeader('Authorization', 'Bearer PRIVATE_SDK_TOKEN')];
    }

    #[DataProvider('unboundRequests')]
    public function testDifferentUrlsBodiesMethodsAndBearersNeverSend(RequestInterface $request): void
    {
        $client = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        $http = new InvoiceAccessTransport(self::PDF, 'PRIVATE_CAPABILITY', $client);
        $this->expectException(ProtocolException::class);
        try {
            $http->sendRequest($request);
        } finally {
            self::assertSame([], $client->requests);
        }
    }

    public function testDefaultTransportBindsNoCapability(): void
    {
        $client = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        $this->expectException(ProtocolException::class);
        (new InvoiceAccessTransport(null, null, $client))->sendRequest(self::request());
    }

    /** @return iterable<string,array{string,int}> */
    public static function failedStreams(): iterable
    {
        yield 'oversize' => ['', 100000];
        yield 'stalled' => ['stalled', 10];
        yield 'broken' => ['broken', 10];
        yield 'close' => ['close_failure', 10];
    }

    #[DataProvider('failedStreams')]
    public function testPayerStreamsAreBoundedClosedRedactedAndNeverRepeated(string $mode, int $size): void
    {
        $stream = new SimulationProbeStream(str_repeat('x', $size), $mode);
        $client = new RecordingClient(new Client(['handler' => new MockHandler([new Response(200, [], $stream)])]));
        $http = new InvoiceAccessTransport(null, 'PRIVATE_CAPABILITY', $client);
        try {
            $http->sendRequest(self::request(false));
            self::fail('An unsafe private stream was accepted.');
        } catch (ProtocolException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('PRIVATE', (string) $error);
            self::assertTrue($stream->closed);
            self::assertLessThanOrEqual(65537, $stream->bytes);
        }
        try {
            $http->sendRequest(self::request(false));
            self::fail('An incomplete read was replayed.');
        } catch (ProtocolException) {
            self::assertCount(1, $client->requests);
        }
    }

    public function testPositiveProgressAndCloseTimeCannotEscapeDeadline(): void
    {
        $now = 0.0;
        $stream = new SimulationProbeStream(str_repeat('x', 100), 'one_byte');
        $client = new RecordingClient(new Client(['handler' => new MockHandler([new Response(200, [], $stream)])]));
        $http = new InvoiceAccessTransport(null, 'PRIVATE_CAPABILITY', $client, static function () use (&$now): float {
            return $now += 2;
        });
        try {
            $http->sendRequest(self::request(false));
            self::fail('Slow positive progress was not bounded.');
        } catch (ProtocolException) {
            self::assertGreaterThan(0, $stream->bytes);
            self::assertLessThan(100, $stream->bytes);
            self::assertTrue($stream->closed);
        }
        $stream = new SimulationProbeStream('x', afterClose: static function () use (&$now): void {
            $now = 20;
        });
        $now = 0;
        $http = new InvoiceAccessTransport(null, 'PRIVATE_CAPABILITY', new Client([
            'handler' => new MockHandler([new Response(200, [], $stream)]),
        ]), static function () use (&$now): float {
            return $now;
        });
        $this->expectException(ProtocolException::class);
        $http->sendRequest(self::request(false));
    }

    public function testTransportErrorsAndDebugCannotExposePrivateBoundCapabilities(): void
    {
        $client = new RecordingClient(new Client(['handler' => new MockHandler([
            new RuntimeException('PRIVATE_URL_CONTEXT'),
        ])]));
        $http = new InvoiceAccessTransport(self::PDF, 'PRIVATE_CAPABILITY', $client);
        try {
            $http->sendRequest(self::request());
            self::fail('A send failure was ignored.');
        } catch (TransportException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('PRIVATE', (string) $error);
        }
        ob_start();
        var_dump($http);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('PRIVATE', $debug);
        $this->expectException(ProtocolException::class);
        serialize($http);
    }
    public function testCompanyTargetRequiresItsOwnOptInActualResponseAndSingleUseBinding(): void
    {
        $target = 'https://documents.airwallex.com/PRIVATE%2fpdf?x=PRIVATE%3D';
        foreach ([false, true] as $company) {
            $client = new RecordingClient(new Client(['handler' => new MockHandler([
                new Response(301, ['Location' => $target]), new Response(200, [], 'prefix'),
            ])]));
            $http = new InvoiceAccessTransport(
                self::PDF,
                null,
                $client,
                allowPdfRedirect: !$company,
                allowAirwallexPdfRedirect: $company
            );
            try {
                $http->bindPdfRedirect($target);
                self::fail('A company target was bound before its original response.');
            } catch (ProtocolException) {
                self::assertSame([], $client->requests);
            }
            $http->sendRequest(self::request());
            try {
                $http->bindPdfRedirect('https://documents.airwallex.com/PRIVATE_UNRETURNED');
                self::fail('An unreturned company target was bound.');
            } catch (ProtocolException) {
                self::assertCount(1, $client->requests);
            }
            if (!$company) {
                try {
                    $http->bindPdfRedirect($target);
                    self::fail('The same-origin mode gained a company-domain permission.');
                } catch (ProtocolException) {
                    self::assertCount(1, $client->requests);
                }
                continue;
            }
            $http->bindPdfRedirect($target);
            $request = self::request()->withUri(new Uri($target));
            $http->sendRequest($request);
            self::assertCount(2, $client->requests);
            try {
                $http->sendRequest($request);
                self::fail('A company redirect was replayed.');
            } catch (ProtocolException) {
                self::assertCount(2, $client->requests);
            }
        }
    }

    #[DataProvider('rejectedData')]
    public function testCompanyRedirectHasNoCookieCredentialOrContextHeaderAllowance(string $name): void
    {
        $target = 'https://documents.airwallex.com/PRIVATE.pdf';
        $client = new RecordingClient(new Client(['handler' => new MockHandler([
            new Response(301, ['Location' => $target]),
        ])]));
        $http = new InvoiceAccessTransport(self::PDF, null, $client, allowAirwallexPdfRedirect: true);
        $http->sendRequest(self::request());
        $http->bindPdfRedirect($target);
        try {
            $http->sendRequest(self::request()->withUri(new Uri($target))->withHeader($name, 'PRIVATE'));
            self::fail('A company redirect acquired an extra header.');
        } catch (ProtocolException) {
            self::assertCount(1, $client->requests);
        }
    }

    #[DataProvider('redirectReadFailures')]
    public function testCompanyReadKeepsStreamingDeadlineCloseAndNoReplayGuards(string $mode): void
    {
        $target = 'https://documents.airwallex.com/PRIVATE.pdf';
        $stream = new SimulationProbeStream(str_repeat('x', 100000), $mode === 'slow' ? 'one_byte' : $mode);
        $ticks = 0;
        $client = new RecordingClient(new Client(['handler' => new MockHandler([
            new Response(301, ['Location' => $target]),
            $mode === 'transport' ? new RuntimeException('PRIVATE_TRANSPORT') : new Response(200, [], $stream),
        ])]));
        $http = new InvoiceAccessTransport(
            self::PDF,
            null,
            $client,
            static function () use (&$ticks, $mode): float {
                return $mode === 'slow' ? ++$ticks * 2.0 : 0.0;
            },
            allowAirwallexPdfRedirect: true
        );
        $http->sendRequest(self::request());
        $http->bindPdfRedirect($target);
        foreach ([1, 2] as $attempt) {
            try {
                $http->sendRequest(self::request()->withUri(new Uri($target)));
                self::fail('A failed company read was accepted or replayed.');
            } catch (ProtocolException | TransportException $error) {
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('PRIVATE', (string) $error);
                self::assertCount(2, $client->requests);
            }
        }
        if ($mode !== 'transport') {
            self::assertTrue($stream->closed);
            self::assertLessThanOrEqual(1025, $stream->bytes);
        }
    }

    public function testConflictingPrivateTransportScopesRejectBeforeAnySend(): void
    {
        $client = new RecordingClient(new Client(['handler' => new MockHandler([])]));
        try {
            new InvoiceAccessTransport(
                self::PDF,
                null,
                $client,
                allowPdfRedirect: true,
                allowAirwallexPdfRedirect: true
            );
            self::fail('Two redirect scopes were accepted.');
        } catch (ProtocolException) {
            self::assertSame([], $client->requests);
        }
    }
    /** @return iterable<string,array{bool,bool}> */
    public static function oversizedCompanyHeaders(): iterable
    {
        yield 'first byte budget' => [false, false];
        yield 'first field budget' => [false, true];
        yield 'follow byte budget' => [true, false];
        yield 'follow field budget' => [true, true];
    }

    #[DataProvider('oversizedCompanyHeaders')]
    public function testCompanyHeaderBudgetsCloseBeforeReadingAnyBodyAndDoNotReplay(bool $follow, bool $fields): void
    {
        $target = 'https://documents.airwallex.com/PRIVATE.pdf';
        $stream = new SimulationProbeStream('PRIVATE_BODY', 'broken');
        $headers = ['Content-Type' => 'application/pdf', 'PRIVATE_NAME' => $fields
            ? array_fill(0, 64, 'PRIVATE_VALUE') : str_repeat('PRIVATE_VALUE', 6000)];
        $responses = $follow ? [new Response(301, ['Location' => $target]), new Response(200, $headers, $stream)]
            : [new Response(200, $headers, $stream)];
        $client = new RecordingClient(new Client(['handler' => new MockHandler($responses)]));
        $http = new InvoiceAccessTransport(self::PDF, null, $client, allowAirwallexPdfRedirect: true);
        if ($follow) {
            $http->sendRequest(self::request());
            $http->bindPdfRedirect($target);
        }
        $request = $follow ? self::request()->withUri(new Uri($target)) : self::request();
        foreach ([1, 2] as $attempt) {
            try {
                $http->sendRequest($request);
                self::fail('Oversized company headers passed or replayed.');
            } catch (ProtocolException $error) {
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('PRIVATE', (string) $error);
                self::assertCount($follow ? 2 : 1, $client->requests);
            }
        }
        self::assertSame(0, $stream->bytes);
        self::assertTrue($stream->closed);
    }
}
