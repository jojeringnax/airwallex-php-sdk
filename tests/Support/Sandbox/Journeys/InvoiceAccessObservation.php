<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\Request;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use stdClass;
use RuntimeException;

/** Bounded private bytes/data from exactly the freshly SDK-verified Invoice; no browser or financial action. */
final class InvoiceAccessObservation
{
    private bool $used = false;

    /** @param (Closure():float)|null $clock */
    public function __construct(
        private readonly bool $readPdf = false,
        private readonly bool $readPayer = false,
        private readonly ?ClientInterface $http = null,
        private readonly ?Closure $clock = null,
    ) {
    }

    /**
     * @param array<array-key,mixed> $invoice Private Invoice after the shared owner's two exact SDK checks.
     * @return array<string,mixed>
     */
    public function inspect(
        #[SensitiveParameter] array $invoice,
        RunLedger $ledger,
        string $fingerprint,
        string $priorDigest,
    ): array {
        $safe = ['result' => 'blocked', 'phase' => 'capability_policy',
            'pdf_read_requested' => $this->readPdf, 'payer_read_requested' => $this->readPayer,
            'pdf_outcome' => 'not_requested', 'payer_outcome' => 'not_requested',
            'pdf_send_started' => false, 'payer_send_started' => false,
            'pdf_prefix_matched' => false, 'payer_invoice_matched' => false,
            'payer_customer_direct_match_verified' => false, 'payer_customer_indirect_anchor_matched' => false,
            'complete_pdf_verified' => false, 'rendering_verified' => false,
            'expiry_verified' => false, 'renewal_verified' => false, 'signature_verified' => false,
            'mutation_sent' => false, 'redirect_followed' => false, 'third_party_followed' => false];
        if ($this->used) {
            return array_replace($safe, ['result' => 'fail', 'phase' => 'observation_already_used']);
        }
        $this->used = true;
        try {
            $ledger->assertContext($fingerprint);
            if (
                preg_match('/^[a-f0-9]{64}$/D', $priorDigest) !== 1
                || !is_string($invoice['id'] ?? null) || trim($invoice['id']) === ''
                || !is_string($invoice['subscription_id'] ?? null) || trim($invoice['subscription_id']) === ''
                || ($invoice['currency'] ?? null) !== 'EUR' || ($invoice['collection_method'] ?? null) !== 'OUT_OF_BAND'
            ) {
                throw new ProtocolException('A verified own Invoice anchor is required.');
            }
            if (!$this->readPdf && !$this->readPayer) {
                return $safe;
            }
            $ledger->recordObservation('invoice_access_anchor', ['invoice_id' => $invoice['id'],
                'prior_ledger_sha256' => $priorDigest], $fingerprint);
            $pdf = $this->readPdf ? InvoiceAccessPolicy::pdf($invoice['pdf_url'] ?? null) : null;
            $hosted = is_string($invoice['hosted_url'] ?? null) ? $invoice['hosted_url'] : null;
            $token = $this->readPayer ? InvoiceAccessPolicy::hostedToken($hosted) : null;
            if (!in_array($invoice['status'] ?? null, ['DRAFT', 'FINALIZED', 'VOIDED'], true)) {
                $token = null;
            }
            $http = new InvoiceAccessTransport($pdf, $token, $this->http, $this->clock);
            if ($this->readPdf) {
                $safe['pdf_policy'] = InvoiceAccessPolicy::pdfPolicy($invoice['pdf_url'] ?? null);
                $safe['pdf_outcome'] = 'withheld';
                if ($pdf !== null) {
                    $safe['phase'] = 'pdf_read';
                    try {
                        $ledger->requestId('invoice_pdf_prefix_read', ['method' => 'GET',
                            'invoice_id' => $invoice['id'], 'prior_ledger_sha256' => $priorDigest,
                            'url_sha256' => hash('sha256', $pdf), 'range' => 'bytes=0-1023'], $fingerprint);
                        $safe['pdf_send_started'] = true;
                        $response = $http->sendRequest(new Request('GET', $pdf, [
                            'Accept' => 'application/pdf', 'Accept-Encoding' => 'identity', 'Range' => 'bytes=0-1023',
                        ]));
                        $safe += self::pdf($response);
                        $safe['pdf_outcome'] = $safe['pdf_observed_outcome'];
                        $safe['pdf_prefix_matched'] = $safe['pdf_outcome'] === 'bounded_prefix_observed';
                        unset($safe['pdf_observed_outcome']);
                    } catch (\Throwable) {
                        $safe['pdf_outcome'] = 'unavailable';
                    }
                }
            }
            if ($this->readPayer) {
                $safe['payer_outcome'] = 'withheld';
                if ($token !== null && $hosted !== null) {
                    $safe['phase'] = 'payer_read';
                    try {
                        $ledger->requestId('invoice_payer_read', ['method' => 'GET',
                            'path' => '/billing/payer-web-api/invoice', 'invoice_id' => $invoice['id'],
                            'prior_ledger_sha256' => $priorDigest,
                            'hosted_url_sha256' => hash('sha256', $hosted)], $fingerprint);
                        $safe['payer_send_started'] = true;
                        $response = $http->sendRequest(new Request('GET', InvoiceAccessPolicy::PAYER, [
                            'Accept' => 'application/json', 'Accept-Encoding' => 'identity',
                            'Authorization' => 'Bearer ' . $token,
                        ]));
                        $safe['payer_http_status'] = $response->getStatusCode();
                        $safe += self::payer($response, $invoice);
                        $safe['payer_outcome'] = $safe['payer_observed_outcome'];
                        $safe['payer_invoice_matched'] = $safe['payer_outcome'] === 'payer_invoice_matched';
                        $safe['payer_customer_indirect_anchor_matched'] = $safe['payer_invoice_matched'];
                        unset($safe['payer_observed_outcome']);
                    } catch (\Throwable) {
                        $safe['payer_outcome'] = 'unavailable';
                    }
                }
            }
            $matched = (!$this->readPdf || $safe['pdf_prefix_matched'])
                && (!$this->readPayer || $safe['payer_invoice_matched']);
            return array_replace($safe, ['result' => $matched ? 'pass' : 'pending',
                'phase' => $matched ? 'access_now_observed' : 'access_not_verified']);
        } catch (\Throwable) {
            return array_replace($safe, ['result' => 'fail', 'phase' => 'read_provenance']);
        }
    }

    /** @return array<string,string|int|bool> */
    private static function pdf(#[SensitiveParameter] ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $media = self::media($response);
        $safe = ['pdf_http_status' => $status, 'pdf_media' => $media,
            'pdf_observed_outcome' => $status >= 300 && $status < 400 ? 'redirect_unfollowed' : 'http_rejected'];
        if (!in_array($status, [200, 206], true)) {
            return $safe;
        }
        $body = (string) $response->getBody();
        $range = $status !== 206 || self::range($response, strlen($body));
        $match = $range && self::identityEncoding($response) && $media === 'pdf'
            && preg_match('/\A%PDF-[12]\.[0-9](?:[\r\n]|$)/', substr($body, 0, 16)) === 1;
        return array_replace($safe, ['pdf_observed_outcome' => $match ? 'bounded_prefix_observed' : 'invalid_response',
            'pdf_prefix_bytes' => min(strlen($body), 1024), 'pdf_prefix_cut' => strlen($body) > 1024,
            'pdf_range_coherent' => $range]);
    }

    private static function range(#[SensitiveParameter] ResponseInterface $response, int $length): bool
    {
        $ranges = $response->getHeader('Content-Range');
        return count($ranges) === 1
            && preg_match('/^bytes 0-([0-9]{1,4})\/([0-9]{1,16})$/D', $ranges[0], $parts) === 1
            && (int) $parts[1] <= 1023 && (int) $parts[2] > (int) $parts[1]
            && $length === (int) $parts[1] + 1;
    }

    /** @param array<array-key,mixed> $invoice
     * @return array<string,string|int|bool>
     */
    private static function payer(#[SensitiveParameter] ResponseInterface $response, array $invoice): array
    {
        $status = $response->getStatusCode();
        $media = self::media($response);
        $safe = ['payer_http_status' => $status, 'payer_media' => $media,
            'payer_observed_outcome' => $status >= 300 && $status < 400 ? 'redirect_unfollowed' : 'http_rejected'];
        if ($status !== 200) {
            return $safe;
        }
        if ($media !== 'json' || !self::identityEncoding($response)) {
            return array_replace($safe, ['payer_observed_outcome' => 'invalid_response']);
        }
        $raw = (string) $response->getBody();
        (new JsonCodec())->decodeObject($raw);
        $object = json_decode($raw, depth: 64, flags: JSON_THROW_ON_ERROR);
        self::uniqueKeys($raw);
        $data = $object instanceof stdClass ? ($object->invoice ?? null) : null;
        if (!$data instanceof stdClass) {
            return array_replace($safe, ['payer_observed_outcome' => 'invalid_response']);
        }
        $expected = ['id' => ['id', $invoice['id']],
            'subscriptionId' => ['subscription_id', $invoice['subscription_id']],
            'currency' => ['currency', 'EUR'], 'collectionMethod' => ['collection_method', 'OUT_OF_BAND'],
            'status' => ['status', $invoice['status']]];
        $matched = true;
        foreach ($expected as $field => [$label, $value]) {
            $present = property_exists($data, $field);
            $string = $present && is_string($data->{$field});
            $fieldMatched = $string && $data->{$field} === $value;
            $safe['payer_' . $label . '_present'] = $present;
            $safe['payer_' . $label . '_string'] = $string;
            $safe['payer_' . $label . '_matched'] = $fieldMatched;
            if (!$fieldMatched) {
                $matched = false;
            }
        }
        $payment = in_array($invoice['payment_status'] ?? null, ['PAID', 'UNPAID'], true);
        $paymentMatched = $payment && ($data->paymentStatus ?? null) === $invoice['payment_status'];
        $matched = $matched && (!$payment || $paymentMatched);
        return array_replace($safe, [
            'payer_observed_outcome' => $matched ? 'payer_invoice_matched' : 'invalid_response',
            'payer_payment_status_comparison_available' => $payment,
            'payer_payment_status_matched' => $paymentMatched]);
    }

    private static function media(#[SensitiveParameter] ResponseInterface $response): string
    {
        $values = $response->getHeader('Content-Type');
        if (count($values) !== 1 || strlen($values[0]) > 128) {
            return 'unavailable';
        }
        return match (strtolower(trim(explode(';', $values[0], 2)[0]))) {
            'application/pdf' => 'pdf', 'application/json' => 'json', default => 'other',
        };
    }

    private static function identityEncoding(#[SensitiveParameter] ResponseInterface $response): bool
    {
        $values = $response->getHeader('Content-Encoding');
        return $values === [] || (count($values) === 1 && strtolower($values[0]) === 'identity');
    }

    /** JSON syntax/depth is already bounded; reject duplicate object keys without retaining labels. */
    private static function uniqueKeys(#[SensitiveParameter] string $raw): void
    {
        $scopes = [];
        for ($offset = 0, $length = strlen($raw); $offset < $length;) {
            $character = $raw[$offset];
            if ($character === '{' || $character === '[') {
                $scopes[] = $character === '{' ? [] : null;
            } elseif ($character === '}' || $character === ']') {
                array_pop($scopes);
            } elseif ($character === '"') {
                if (preg_match('/\G"(?:[^"\\\\]|\\\\.)*"/s', $raw, $matches, 0, $offset) !== 1) {
                    throw new ProtocolException('Private Invoice keys could not be observed.');
                }
                $offset += strlen($matches[0]);
                $next = $offset + strspn($raw, " \t\r\n", $offset);
                if (($raw[$next] ?? '') === ':') {
                    $key = json_decode($matches[0], flags: JSON_THROW_ON_ERROR);
                    $index = count($scopes) - 1;
                    if (!is_string($key) || !isset($scopes[$index]) || array_key_exists($key, $scopes[$index])) {
                        throw new ProtocolException('Private Invoice keys are ambiguous.');
                    }
                    $scopes[$index][$key] = true;
                }
                continue;
            }
            ++$offset;
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
        throw new RuntimeException('Invoice access observations cannot be serialized.');
    }
}
