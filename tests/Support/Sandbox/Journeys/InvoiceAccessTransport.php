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

/** One separately bound PDF-prefix and payer GET; never uses an SDK/API credential. */
final class InvoiceAccessTransport implements ClientInterface
{
    private readonly ClientInterface $http;
    /** @var Closure():float */
    private readonly Closure $clock;
    private bool $pdfSent = false;
    private bool $payerSent = false;

    /**
     * @param ClientInterface|null $http Fixture or already-streaming seam; arbitrary clients may prebuffer.
     * @param (Closure():float)|null $clock Monotonic seconds for deterministic fixtures.
     */
    public function __construct(
        #[SensitiveParameter] private readonly ?string $pdf,
        #[SensitiveParameter] private readonly ?string $payerToken,
        ?ClientInterface $http = null,
        ?Closure $clock = null,
    ) {
        if (
            ($pdf !== null && InvoiceAccessPolicy::pdf($pdf) !== $pdf)
            || ($payerToken !== null && preg_match('/^[A-Za-z0-9._~+\/=-]{1,8192}$/D', $payerToken) !== 1)
        ) {
            throw new ProtocolException('Invoice access requires a bound first-party capability.');
        }
        $this->http = $http ?? new Client([
            'handler' => new StreamHandler(), 'stream' => true, 'verify' => true,
            'read_timeout' => 5, 'timeout' => 30, 'connect_timeout' => 10,
            'cookies' => false, 'allow_redirects' => false, 'http_errors' => false,
            'decode_content' => false,
        ]);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    public static function assertAvailable(): void
    {
        if (
            !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)
            || !in_array('https', stream_get_wrappers(), true)
        ) {
            throw new ProtocolException('Streaming Invoice access observation is unavailable.');
        }
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $uri = (string) $request->getUri();
        $pdf = $this->pdf !== null && $uri === $this->pdf;
        $payer = $this->payerToken !== null && $uri === InvoiceAccessPolicy::PAYER;
        $expected = ['accept' => $pdf ? 'application/pdf' : 'application/json', 'accept-encoding' => 'identity',
            'host' => 'invoice.sandbox.airwallex.com'];
        if ($pdf) {
            $expected['range'] = 'bytes=0-1023';
        } elseif ($payer) {
            $expected['authorization'] = 'Bearer ' . $this->payerToken;
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values;
        }
        $valid = count($headers) === count($expected);
        foreach ($expected as $name => $value) {
            $valid = $valid && ($headers[$name] ?? null) === [$value];
        }
        if (
            (!$pdf && !$payer) || !$valid || $request->getMethod() !== 'GET'
            || $request->getBody()->getSize() !== 0 || ($pdf && $this->pdfSent) || ($payer && $this->payerSent)
        ) {
            throw new ProtocolException('Only the separately bound Invoice reads are permitted once.');
        }
        $this->pdfSent = $this->pdfSent || $pdf;
        $this->payerSent = $this->payerSent || $payer;
        try {
            $response = $this->http->sendRequest($request);
        } catch (Throwable) {
            throw new TransportException('Invoice access request failed without a usable response.');
        }
        try {
            $stream = $response->getBody();
            try {
                $started = ($this->clock)();
                $body = '';
                $success = $pdf ? in_array($response->getStatusCode(), [200, 206], true)
                    : $response->getStatusCode() === 200;
                $limit = $success ? ($pdf ? 1025 : 65537) : 0;
                while (strlen($body) < $limit) {
                    $this->deadline($started);
                    $part = $stream->read(min(8192, $limit - strlen($body)));
                    $this->deadline($started);
                    if ($part === '') {
                        if ($stream->eof()) {
                            break;
                        }
                        throw new ProtocolException('The private response made no bounded progress.');
                    }
                    $body .= $part;
                    if ($stream->eof()) {
                        break;
                    }
                }
                if (!$pdf && strlen($body) > 65536) {
                    throw new ProtocolException('The private Invoice data exceeded its observation bound.');
                }
                $bounded = $response->withBody(Utils::streamFor($body));
            } finally {
                $stream->close();
            }
            $this->deadline($started);
            return $bounded;
        } catch (Throwable) {
            throw new ProtocolException('Invoice access response could not be safely observed.');
        }
    }

    private function deadline(float $started): void
    {
        $now = ($this->clock)();
        if (!is_finite($started) || !is_finite($now) || $now < $started || $now - $started >= 10) {
            throw new ProtocolException('Invoice access exceeded its observation deadline.');
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['capabilities' => '[private]', 'http' => '[redacted]', 'clock' => '[redacted]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ProtocolException('Private Invoice access transports cannot be serialized.');
    }
}
