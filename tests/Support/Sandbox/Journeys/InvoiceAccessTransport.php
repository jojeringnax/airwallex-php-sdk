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
    private bool $redirectSent = false;
    private ?string $redirectCandidate = null;
    private ?string $redirectTarget = null;

    /**
     * @param ClientInterface|null $http Fixture or already-streaming seam; arbitrary clients may prebuffer.
     * @param (Closure():float)|null $clock Monotonic seconds for deterministic fixtures.
     */
    public function __construct(
        #[SensitiveParameter] private readonly ?string $pdf,
        #[SensitiveParameter] private readonly ?string $payerToken,
        ?ClientInterface $http = null,
        ?Closure $clock = null,
        private readonly bool $allowPdfRedirect = false,
        private readonly bool $allowAirwallexPdfRedirect = false,
    ) {
        if (
            ($allowPdfRedirect && $allowAirwallexPdfRedirect)
            || ($pdf !== null && InvoiceAccessPolicy::pdf($pdf) !== $pdf)
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

    /** @throws ProtocolException|TransportException */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $uri = (string) $request->getUri();
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values;
        }
        $pdfHeaders = self::headers($headers, ['accept' => 'application/pdf', 'accept-encoding' => 'identity',
            'host' => 'invoice.sandbox.airwallex.com', 'range' => 'bytes=0-1023']);
        $initial = $this->pdf !== null && $uri === $this->pdf && $pdfHeaders;
        $redirect = $this->redirectTarget !== null && $uri === $this->redirectTarget
            && self::headers($headers, ['accept' => 'application/pdf', 'accept-encoding' => 'identity',
                'host' => $request->getUri()->getHost(), 'range' => 'bytes=0-1023']);
        $payer = $this->payerToken !== null && $uri === InvoiceAccessPolicy::PAYER
            && self::headers($headers, ['accept' => 'application/json', 'accept-encoding' => 'identity',
                'host' => 'invoice.sandbox.airwallex.com', 'authorization' => 'Bearer ' . $this->payerToken]);
        if (
            (!$initial && !$redirect && !$payer) || $request->getMethod() !== 'GET'
            || $request->getBody()->getSize() !== 0 || ($initial && $this->pdfSent)
            || ($redirect && $this->redirectSent) || ($payer && $this->payerSent)
        ) {
            throw new ProtocolException('Only the separately bound Invoice reads are permitted once.');
        }
        $this->pdfSent = $this->pdfSent || $initial;
        $this->redirectSent = $this->redirectSent || $redirect;
        $this->payerSent = $this->payerSent || $payer;
        $pdf = $initial || $redirect;
        try {
            $response = $this->http->sendRequest($request);
        } catch (Throwable) {
            throw new TransportException('Invoice access request failed without a usable response.');
        }
        try {
            $stream = $response->getBody();
            try {
                if ($pdf && $this->allowAirwallexPdfRedirect) {
                    self::boundHeaders($response);
                }
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
            if ($initial && ($this->allowPdfRedirect || $this->allowAirwallexPdfRedirect)) {
                $this->rememberPdfRedirect($bounded);
            }
            return $bounded;
        } catch (Throwable) {
            throw new ProtocolException('Invoice access response could not be safely observed.');
        }
    }

    /** HTTP stream headers are already buffered; this cap bounds acceptance before body observation. */
    private static function boundHeaders(#[SensitiveParameter] ResponseInterface $response): void
    {
        $bytes = 0;
        $fields = 0;
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $bytes += strlen($name) + strlen($value) + 4;
                if (++$fields > 64 || $bytes > 65536) {
                    throw new ProtocolException('The private PDF headers exceeded their observation bound.');
                }
            }
        }
    }

    private function rememberPdfRedirect(#[SensitiveParameter] ResponseInterface $response): void
    {
        if ($this->pdf === null) {
            throw new ProtocolException('An original PDF capability is required for redirect binding.');
        }
        $decision = InvoicePdfRedirect::inspect($this->pdf, $response, $this->allowAirwallexPdfRedirect);
        $this->redirectCandidate = $decision['target'];
    }

    /** @throws ProtocolException */
    public function bindPdfRedirect(#[SensitiveParameter] string $target): void
    {
        if (
            (!$this->allowPdfRedirect && !$this->allowAirwallexPdfRedirect) || !$this->pdfSent
            || $this->redirectCandidate === null
            || $target !== $this->redirectCandidate || $this->redirectTarget !== null || $this->redirectSent
        ) {
            throw new ProtocolException('Only the original response can select the one PDF redirect target.');
        }
        $this->redirectTarget = $target;
    }

    /**
     * @param array<string,array<array-key,string>> $actual
     * @param array<string,string> $expected
     */
    private static function headers(#[SensitiveParameter] array $actual, #[SensitiveParameter] array $expected): bool
    {
        if (count($actual) !== count($expected)) {
            return false;
        }
        foreach ($expected as $name => $value) {
            if (($actual[$name] ?? null) !== [$value]) {
                return false;
            }
        }
        return true;
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
