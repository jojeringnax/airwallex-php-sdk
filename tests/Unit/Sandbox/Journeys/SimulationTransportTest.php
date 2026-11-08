<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SimulationTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class SimulationTransportTest extends TestCase
{
    private const string URL = 'https://api.sandbox.airwallex.com/api/v1/simulation/pa/shopper_actions/pay';

    /** @return iterable<string,array{int,string,bool,int}> */
    public static function bodies(): iterable
    {
        yield 'documented empty acknowledgement' => [200, '', true, 0];
        yield 'acknowledgement cannot buffer a nonempty body' => [200, str_repeat('P', 65537), false, 1];
        yield 'bounded error copy' => [400, str_repeat('P', 65536), true, 65536];
        yield 'oversized error' => [400, str_repeat('P', 65537), false, 65537];
        yield 'bounded login copy' => [201, 'PRIVATE_TOKEN', true, 13];
    }

    #[DataProvider('bodies')]
    public function testBodyIsBoundedBeforeAnyDecoderOrStringCast(
        int $status,
        string $body,
        bool $accepted,
        int $bytes,
    ): void {
        $stream = new SimulationProbeStream($body);
        $transport = new SimulationTransport($this->http(new Response($status, [], $stream)));
        $error = null;
        try {
            $result = $transport->sendRequest(new Request('POST', self::URL));
            self::assertTrue($accepted);
            self::assertSame($body, (string) $result->getBody());
        } catch (ProtocolException $caught) {
            $error = $caught;
            self::assertFalse($accepted);
            self::assertNull($caught->getPrevious());
            self::assertStringNotContainsString('PRIVATE_', (string) $caught);
        }
        self::assertSame(!$accepted, $error !== null);
        self::assertSame($bytes, $stream->bytes);
        self::assertTrue($stream->closed);
    }

    /** @return iterable<string,array{string}> */
    public static function streamFailures(): iterable
    {
        yield 'zero progress' => ['stalled'];
        yield 'read failure' => ['broken'];
        yield 'close failure' => ['close_failure'];
    }

    #[DataProvider('streamFailures')]
    public function testBrokenStreamsStayPrivateAndClosed(string $mode): void
    {
        $stream = new SimulationProbeStream('PRIVATE_BODY', $mode);
        $transport = new SimulationTransport($this->http(new Response(400, [], $stream)));
        try {
            $transport->sendRequest(new Request('POST', self::URL));
            self::fail('A failed stream cannot produce usable acknowledgement evidence.');
        } catch (ProtocolException $error) {
            self::assertTrue($stream->closed);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('PRIVATE_', (string) $error);
        }
    }

    public function testPositiveProgressCannotExtendTheElapsedBodyDeadline(): void
    {
        $time = new class {
            public float $now = 100;
        };
        $stream = new SimulationProbeStream('PRIVATE_BODY', 'one_byte', static function () use ($time): void {
            $time->now += 6;
        });
        $transport = new SimulationTransport(
            $this->http(new Response(400, [], $stream)),
            static fn (): float => $time->now
        );
        try {
            $transport->sendRequest(new Request('POST', self::URL));
            self::fail('Slow positive progress must not defeat the elapsed deadline.');
        } catch (ProtocolException $error) {
            self::assertSame(2, $stream->bytes);
            self::assertTrue($stream->closed);
            self::assertStringNotContainsString('PRIVATE_', (string) $error);
        }
    }

    public function testCloseTimeAndInvalidClockCannotProduceAnAcknowledgement(): void
    {
        foreach (['slow_close', 'broken_clock'] as $mode) {
            $time = new class {
                public float $now = 100;
            };
            $stream = new SimulationProbeStream('', afterClose: static function () use ($time): void {
                $time->now += 11;
            });
            $clock = static function () use ($time, $mode): float {
                if ($mode === 'broken_clock') {
                    throw new RuntimeException('PRIVATE_CLOCK');
                }
                return $time->now;
            };
            try {
                (new SimulationTransport($this->http(new Response(200, [], $stream)), $clock))
                    ->sendRequest(new Request('POST', self::URL));
                self::fail('Close/clock failures cannot become successful evidence.');
            } catch (ProtocolException $error) {
                self::assertTrue($stream->closed);
                self::assertStringNotContainsString('PRIVATE_', (string) $error);
            }
        }
    }

    public function testOnlyFixedSandboxSimulationAndLoginPostsCanReachTransport(): void
    {
        $http = $this->http(new Response(200));
        foreach (
            [
            new Request('GET', self::URL), new Request('POST', str_replace('api.sandbox.', 'api.', self::URL)),
            new Request('POST', self::URL . '?PRIVATE_QUERY'), new Request('POST', self::URL . '#PRIVATE_FRAGMENT'),
            new Request('POST', str_replace('/pay', '/PRIVATE_PATH', self::URL)),
            new Request('POST', str_replace('.com/', '.com:444/', self::URL)),
            new Request('POST', str_replace('https://', 'https://PRIVATE_USER@', self::URL)),
            ] as $request
        ) {
            try {
                (new SimulationTransport($http))->sendRequest($request);
                self::fail('Unverified request must not reach the transport.');
            } catch (ProtocolException $error) {
                self::assertStringNotContainsString('PRIVATE_', (string) $error);
            }
        }
        self::assertCount(0, $http->requests);
        $transport = new SimulationTransport($http);
        ob_start();
        var_dump($transport);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('PRIVATE_', $debug);
        $this->expectException(ProtocolException::class);
        serialize($transport);
    }

    public function testDefaultUsesAnExplicitStreamingHandlerInsteadOfConditionalCurlFallback(): void
    {
        $transport = new SimulationTransport();
        $property = new \ReflectionProperty($transport, 'http');
        $client = $property->getValue($transport);
        self::assertInstanceOf(Client::class, $client);
        self::assertInstanceOf(StreamHandler::class, $client->getConfig('handler'));
        self::assertTrue($client->getConfig('stream'));
        self::assertTrue($client->getConfig('verify'));
        self::assertFalse($client->getConfig('allow_redirects'));
        self::assertFalse($client->getConfig('cookies'));
        self::assertSame(5, $client->getConfig('read_timeout'));
    }

    public function testUnavailableStreamingConfigurationRejectsWithoutAnyNetworkRequest(): void
    {
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . '; try {'
            . '\\Jojeringnax\\Airwallex\\Tests\\Support\\Sandbox\\Journeys\\SimulationTransport::assertAvailable();'
            . 'echo "unexpected"; exit(2); } catch (\\Throwable) { echo "rejected"; exit(0); }';
        $process = proc_open(
            [PHP_BINARY, '-d', 'allow_url_fopen=0', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        [$output, $errors] = self::pipes($pipes);
        self::assertSame('rejected', stream_get_contents($output));
        self::assertSame('', stream_get_contents($errors));
        fclose($output);
        fclose($errors);
        self::assertSame(0, proc_close($process));
    }

    /** @return array{resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (!is_array($pipes) || !is_resource($pipes[1] ?? null) || !is_resource($pipes[2] ?? null)) {
            throw new RuntimeException('Fixture pipes are unavailable.');
        }
        return [$pipes[1], $pipes[2]];
    }

    private function http(ResponseInterface $response): SimulationResponseClient
    {
        return new SimulationResponseClient($response);
    }
}
