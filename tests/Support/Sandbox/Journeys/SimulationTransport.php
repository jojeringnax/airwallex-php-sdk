<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\TransportException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use Throwable;

/** Test-only streaming transport: fixed authentication/simulation paths and bounded private response copies. */
final readonly class SimulationTransport implements ClientInterface
{
    private ClientInterface $http;
    /** @var Closure():float */
    private Closure $clock;

    /**
     * @param ClientInterface|null $http Fixture or already-streaming client; arbitrary PSR clients may prebuffer.
     * @param (Closure():float)|null $clock Monotonic seconds, injectable for deterministic deadline tests.
     */
    public function __construct(?ClientInterface $http = null, ?Closure $clock = null)
    {
        $this->http = $http ?? new Client([
            'handler' => new StreamHandler(),
            'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'cookies' => false,
            'stream' => true, 'read_timeout' => 5, 'timeout' => 30, 'connect_timeout' => 10,
        ]);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    public static function assertAvailable(): void
    {
        if (
            !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)
            || !in_array('https', stream_get_wrappers(), true)
        ) {
            throw new ProtocolException('Streaming Sandbox observation is unavailable in this PHP configuration.');
        }
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $simulation = preg_match(
            '~^/api/v1/simulation/pa/shopper_actions/(?:pay|reject|verify)$~D',
            $uri->getPath()
        ) === 1;
        if (
            $request->getMethod() !== 'POST' || $uri->getScheme() !== 'https'
            || $uri->getHost() !== 'api.sandbox.airwallex.com' || $uri->getPort() !== null
            || $uri->getUserInfo() !== '' || $uri->getQuery() !== '' || $uri->getFragment() !== ''
            || (!$simulation && $uri->getPath() !== '/api/v1/authentication/login')
        ) {
            throw new ProtocolException('Simulation transport requires a fixed Sandbox operation.');
        }
        try {
            $response = $this->http->sendRequest($request);
        } catch (Throwable) {
            throw new TransportException('Simulation request failed without a usable HTTP response.');
        }
        try {
            $stream = $response->getBody();
            try {
                $started = ($this->clock)();
                $limit = $simulation && $response->getStatusCode() === 200 ? 0 : 65536;
                $body = '';
                while (strlen($body) <= $limit) {
                    $this->withinDeadline($started);
                    $part = $stream->read(min(8192, $limit + 1 - strlen($body)));
                    $this->withinDeadline($started);
                    if ($part === '') {
                        if ($stream->eof()) {
                            break;
                        }
                        throw new ProtocolException('Simulation response made no bounded progress.');
                    }
                    $body .= $part;
                    if ($stream->eof()) {
                        break;
                    }
                }
                if (strlen($body) > $limit) {
                    throw new ProtocolException('Simulation response exceeded its private boundary.');
                }
                $bounded = $response->withBody(Utils::streamFor($body));
            } finally {
                $stream->close();
            }
            $this->withinDeadline($started);
            return $bounded;
        } catch (Throwable) {
            throw new ProtocolException('Simulation response could not be safely observed.');
        }
    }

    private function withinDeadline(float $started): void
    {
        $now = ($this->clock)();
        if (!is_finite($started) || !is_finite($now) || $now < $started || $now - $started >= 10) {
            throw new ProtocolException('Simulation response exceeded its observation deadline.');
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['http' => '[redacted]', 'clock' => '[redacted]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Simulation transports cannot be serialized.');
    }
}
