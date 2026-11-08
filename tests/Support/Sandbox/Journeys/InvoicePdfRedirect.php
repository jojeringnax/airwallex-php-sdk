<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use Throwable;

/** One strict RFC reference resolution; returned private targets never enter operator evidence. */
final class InvoicePdfRedirect
{
    /** @return array{policy:string,target:?string,location_sha256:?string,target_scheme:string,target_origin_family:string} */
    public static function inspect(
        #[SensitiveParameter] string $base,
        #[SensitiveParameter] ResponseInterface $response,
    ): array {
        $decision = self::withheld('not_redirect');
        if (!in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true)) {
            return $decision;
        }
        if ($response->hasHeader('Retry-After')) {
            return self::withheld('retry_after');
        }
        $values = $response->getHeader('Location');
        if ($values === [] || $values === ['']) {
            return self::withheld('missing_location');
        }
        if (count($values) !== 1) {
            return self::withheld('multiple_locations');
        }
        $reference = $values[0];
        if (strlen($reference) > 32768) {
            return self::withheld('oversize');
        }
        if (str_contains($reference, ',')) {
            return self::withheld('ambiguous_location');
        }
        if (
            preg_match('/[^\x21-\x7e]|[\\\\#]/', $reference) === 1
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $reference) === 1
        ) {
            return self::withheld('lexical');
        }
        if (preg_match('/%(?![a-fA-F0-9]{2})/', $reference) === 1) {
            return self::withheld('uri');
        }
        try {
            if (InvoiceAccessPolicy::pdf($base) !== $base) {
                return self::withheld('uri');
            }
            $baseUri = new Uri($base);
            $relative = new Uri($reference);
            if ((string) $relative !== $reference) {
                return self::withheld('uri');
            }
            $parts = parse_url($reference);
            $diagnostic = self::diagnostic($baseUri, $relative);
            if (
                $parts === false || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['fragment'])
                || ($relative->getScheme() !== '' && $relative->getScheme() !== 'https')
                || ($relative->getHost() !== '' && $relative->getHost() !== 'invoice.sandbox.airwallex.com')
            ) {
                return self::withheld('origin', $diagnostic);
            }
            if (self::dotSegments($baseUri->getPath()) || self::dotSegments($relative->getPath())) {
                return self::withheld('dot_segments', $diagnostic);
            }
            $resolved = (string) UriResolver::resolve($baseUri, $relative);
            $resolvedPolicy = InvoiceAccessPolicy::pdfPolicy($resolved);
            if ($resolvedPolicy !== 'admitted') {
                return self::withheld($resolvedPolicy, $diagnostic);
            }
            if ($resolved === $base) {
                return self::withheld('self_reference', $diagnostic);
            }
            return ['policy' => 'admitted', 'target' => $resolved, 'location_sha256' => hash('sha256', $reference),
                'target_scheme' => $diagnostic['scheme'], 'target_origin_family' => $diagnostic['origin_family']];
        } catch (Throwable) {
            return self::withheld('uri');
        }
    }

    /**
     * @param array{scheme:string,origin_family:string}|null $diagnostic
     * @return array{policy:string,target:null,location_sha256:null,target_scheme:string,target_origin_family:string}
     */
    private static function withheld(string $policy, ?array $diagnostic = null): array
    {
        return ['policy' => $policy, 'target' => null, 'location_sha256' => null,
            'target_scheme' => $diagnostic === null ? 'unavailable' : $diagnostic['scheme'],
            'target_origin_family' => $diagnostic === null ? 'unavailable' : $diagnostic['origin_family']];
    }

    /**
     * Pure closed diagnostics. These fields do not authorize or alter the private target.
     * @return array{scheme:string,origin_family:string}
     */
    private static function diagnostic(Uri $base, Uri $reference): array
    {
        $unavailable = ['scheme' => 'unavailable', 'origin_family' => 'unavailable'];
        try {
            if (self::dotSegments($base->getPath()) || self::dotSegments($reference->getPath())) {
                return $unavailable;
            }
            $resolved = UriResolver::resolve($base, $reference);
            $bytes = (string) $resolved;
            if (strlen($bytes) > 32768 || (string) new Uri($bytes) !== $bytes) {
                return $unavailable;
            }
            $scheme = match ($resolved->getScheme()) {
                'https' => 'https', 'http' => 'http', '' => 'unavailable', default => 'other',
            };
            $host = $resolved->getHost();
            if (
                strlen($host) > 253 || preg_match(
                    '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',
                    $host
                ) !== 1
            ) {
                return ['scheme' => $scheme, 'origin_family' => 'unavailable'];
            }
            $family = match (true) {
                $host === 'invoice.sandbox.airwallex.com' => 'same_named_sandbox',
                $host === 'invoice.airwallex.com' => 'shared_invoice',
                $host === 'airwallex.com' || str_ends_with($host, '.airwallex.com') => 'other_airwallex',
                default => 'other',
            };
            return ['scheme' => $scheme, 'origin_family' => $family];
        } catch (Throwable) {
            return $unavailable;
        }
    }

    private static function dotSegments(#[SensitiveParameter] string $path): bool
    {
        // Inspect one decoded path view only for ambiguity; never use it as a request target.
        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }
        return false;
    }
}
