<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;

/** Explicit fixed-root access-now reads; no URL, resource, context or financial-action options. */
final class InvoiceAccessCommand
{
    /**
     * @param array<string,mixed> $options
     * @param (Closure(string,string):RunLedger)|null $ledgers Test-only durability seam.
     * @return array<string,mixed>
     */
    public static function run(
        #[SensitiveParameter] array $options,
        ?ClientInterface $sdkHttp = null,
        ?ClientInterface $linkHttp = null,
        ?Closure $ledgers = null,
    ): array {
        $safe = ['scenario' => 'invoice-access-observation', 'api_version' => '2026-08-21',
            'result' => 'fail', 'phase' => 'configuration', 'owner_chain_verified' => false,
            'mutation_sent' => false, 'redirect_followed' => false, 'third_party_followed' => false,
            'rendering_verified' => false, 'complete_pdf_verified' => false,
            'expiry_verified' => false, 'renewal_verified' => false];
        $flags = ['read-one-pdf-prefix', 'read-one-payer-invoice'];
        foreach ($options as $name => $value) {
            if (
                !in_array($name, ['environment', 'credentials-file', 'prior-ledger', 'state-dir', ...$flags], true)
                || (in_array($name, $flags, true) ? !is_bool($value) : !is_string($value) || $value === '')
            ) {
                return $safe;
            }
        }
        $pdf = $options['read-one-pdf-prefix'] ?? false;
        $payer = $options['read-one-payer-invoice'] ?? false;
        if (!is_bool($pdf) || !is_bool($payer)) {
            return $safe;
        }
        $safe['pdf_read_requested'] = $pdf;
        $safe['payer_read_requested'] = $payer;
        if (!$pdf && !$payer) {
            return array_replace($safe, ['result' => 'blocked', 'phase' => 'no_access_read_selected']);
        }
        if (($options['environment'] ?? null) !== 'sandbox') {
            return $safe;
        }
        $strings = [];
        foreach (['credentials-file', 'prior-ledger', 'state-dir'] as $name) {
            $value = $options[$name] ?? null;
            if (!is_string($value) || $value === '') {
                return $safe;
            }
            $strings[$name] = $value;
        }
        try {
            InvoiceAccessTransport::assertAvailable();
            self::externalCredentials($strings['credentials-file']);
            $safe['source'] = SourceEvidence::capture();
            $safe['frontend'] = InvoiceLinkClaims::provenance();
            $toolHash = hash_file('sha256', dirname(__DIR__, 4) . '/tools/invoice-access-observation.php');
            if ($toolHash === false) {
                throw new RuntimeException('Invoice access tool provenance is unavailable.');
            }
            $safe['access_tool_sha256'] = $toolHash;
            $session = new SandboxSession(
                Configuration::load('sandbox', $strings['credentials-file']),
                'organization',
                $sdkHttp
            );
            $fingerprint = $session->contextFingerprint();
            $safe['context'] = 'organization';
            $safe['context_fingerprint'] = $fingerprint;
            $safe['phase'] = 'prior_ledger';
            $owner = InvoiceLinkObservation::load($strings['prior-ledger'], $session);
            $prior = $owner->provenance();
            $safe += $prior;
            $safe['phase'] = 'read_provenance';
            $ledger = $ledgers === null ? RunLedger::create($fingerprint, $strings['state-dir'])
                : $ledgers($fingerprint, $strings['state-dir']);
            $ledger->requestId('invoice_owner_reads', ['prior_ledger_sha256' => $prior['prior_ledger_sha256'],
                'api_version' => '2026-08-21', 'tool_sha256' => $toolHash], $fingerprint);
            $access = new InvoiceAccessObservation($pdf, $payer, $linkHttp);
            $observed = $owner->inspect(
                $session,
                wait: static fn (): null => null,
                access: static fn (array $invoice): array => $access->inspect(
                    $invoice,
                    $ledger,
                    $fingerprint,
                    $prior['prior_ledger_sha256']
                )
            );
            $data = $observed['access'] ?? null;
            if (is_array($data)) {
                foreach ($data as $name => $value) {
                    if (!is_string($name)) {
                        throw new RuntimeException('Invoice access evidence has an invalid key.');
                    }
                    $safe[$name] = $value;
                }
                $safe['owner_chain_verified'] = true;
            } else {
                $safe['phase'] = $observed['phase'];
                $safe['result'] = $observed['result'];
                if (isset($observed['http_status'])) {
                    $safe['http_status'] = $observed['http_status'];
                }
            }
            $safe['prior_ledger_unchanged'] = @hash_file('sha256', $strings['prior-ledger'])
                === $prior['prior_ledger_sha256'];
            if (!$safe['prior_ledger_unchanged']) {
                $safe['result'] = 'fail';
            }
        } catch (\Throwable) {
            $safe['result'] = 'fail';
        }
        return $safe;
    }

    private static function externalCredentials(#[SensitiveParameter] string $path): void
    {
        if (!str_starts_with($path, '/') || is_link($path)) {
            throw new RuntimeException('Absolute external credentials are required.');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('External credentials are unavailable.');
        }
        for ($ancestor = dirname($resolved);; $ancestor = dirname($ancestor)) {
            if (file_exists($ancestor . '/.git') || is_link($ancestor . '/.git')) {
                throw new RuntimeException('Credentials must remain outside Git checkouts.');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
    }
}
