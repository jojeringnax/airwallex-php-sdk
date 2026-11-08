<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use DOMDocument;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;

/** Credential-free bounded reads; the optional single mock hop cannot execute HTML or submit a form. */
final class AlipayRedirect
{
    private bool $sent = false;
    private readonly ClientInterface $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client([
            'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
            'cookies' => false, 'stream' => true, 'timeout' => 30, 'connect_timeout' => 10,
        ]);
    }

    public static function eligible(#[SensitiveParameter] mixed $value): bool
    {
        return self::diagnose($value)['eligible'] === true;
    }

    /** Fixed categories only; the opaque URI and all query names/values stay private.
     * @return array<string,bool|string>
     */
    public static function diagnose(#[SensitiveParameter] mixed $value): array
    {
        $safe = [
            'input_is_string' => is_string($value), 'size_within_limit' => false,
            'lexically_valid' => false, 'absolute_uri' => false, 'https' => false,
            'sandbox_host_matches' => false, 'userinfo_absent' => false, 'explicit_port_absent' => false,
            'fragment_absent' => false, 'path_identifier_valid' => false, 'exact_uri_preserved' => false,
            'origin_category' => 'unclassified', 'path_family' => 'unclassified',
            'query_present' => false, 'query_checksum_present' => false, 'query_unlisted_keys_present' => false,
            'query_repeated_key_present' => false, 'query_scan_complete' => false, 'eligible' => false,
        ];
        if (!is_string($value) || strlen($value) > 8192) {
            return $safe;
        }
        $safe['size_within_limit'] = true;
        $safe['lexically_valid'] = preg_match('/[\x00-\x20\x7f\\\\]/', $value) !== 1
            && preg_match('/%(?![a-fA-F0-9]{2})/', $value) !== 1;
        if (!$safe['lexically_valid']) {
            return $safe;
        }
        $url = parse_url($value);
        if (!is_array($url) || !isset($url['scheme'], $url['host']) || $url['host'] === '') {
            return $safe;
        }
        $safe['absolute_uri'] = true;
        $safe['https'] = $url['scheme'] === 'https';
        $safe['sandbox_host_matches'] = $url['host'] === 'api.sandbox.airwallex.com';
        $safe['userinfo_absent'] = !isset($url['user']) && !isset($url['pass']);
        $safe['explicit_port_absent'] = !isset($url['port']);
        $safe['fragment_absent'] = !isset($url['fragment']);
        $origin = $safe['https'] && $safe['sandbox_host_matches'] && $safe['explicit_port_absent'];
        $safe['origin_category'] = $origin ? 'sandbox_api' : 'unverified_origin';
        $path = $url['path'] ?? '';
        if (preg_match('~^/pa/(redirect|sandbox)/(hk|sg)/~D', $path, $family) === 1) {
            $safe['path_family'] = $family[1] . '_' . $family[2];
        } else {
            $safe['path_family'] = $origin ? 'other_sandbox_path' : 'unverified_origin';
        }
        $safe['path_identifier_valid'] = preg_match(
            '~^/pa/(?:redirect|sandbox)/(?:hk|sg)/[A-Za-z0-9_-]+$~D',
            $path
        ) === 1;
        $safe['query_present'] = isset($url['query']);
        $parts = ($url['query'] ?? '') === '' ? [] : explode('&', $url['query'], 65);
        $safe['query_scan_complete'] = count($parts) <= 64;
        $seen = [];
        foreach (array_slice($parts, 0, 64) as $part) {
            $name = rawurldecode(explode('=', $part, 2)[0]);
            $safe['query_checksum_present'] = $safe['query_checksum_present'] || $name === 'checksum';
            $safe['query_unlisted_keys_present'] = $safe['query_unlisted_keys_present'] || $name !== 'checksum';
            $safe['query_repeated_key_present'] = $safe['query_repeated_key_present'] || isset($seen[$name]);
            $seen[$name] = true;
        }
        try {
            $safe['exact_uri_preserved'] = (string) (new Request('GET', $value))->getUri() === $value;
        } catch (\Throwable) {
            // Neither parser errors nor private URL values leave this diagnostic boundary.
        }
        $safe['eligible'] = $origin && $safe['userinfo_absent'] && $safe['fragment_absent']
            && $safe['path_identifier_valid'] && $safe['exact_uri_preserved']
            && in_array($safe['path_family'], ['redirect_hk', 'redirect_sg'], true);
        return $safe;
    }

    /**
     * @param (Closure(string):void)|null $beforeMockRead Explicit opt-in and durable private reservation.
     * @return array<string,int|bool|string|array<string,int|bool|string>>
     */
    public function inspect(#[SensitiveParameter] string $url, ?Closure $beforeMockRead = null): array
    {
        if ($this->sent || !self::eligible($url)) {
            throw new RuntimeException('The customer redirect is unavailable or outside the verified Sandbox path.');
        }
        $this->sent = true;
        try {
            $first = $this->observe($url);
            $safe = $first['safe'] + ['mock_read_requested' => $beforeMockRead !== null,
                'mock_read_attempted' => false, 'mock_read_reservation_failed' => false, 'mock_read_failed' => false];
            $location = $first['location'];
            if (
                $beforeMockRead === null || $safe['http_status'] !== 302
                || $location === null || !self::mockReadable($location)
            ) {
                return $safe;
            }
            try {
                $beforeMockRead($location);
            } catch (\Throwable) {
                $safe['mock_read_reservation_failed'] = true;
                return $safe; // The second transport is unreachable without the durable reservation.
            }
            $safe['location_follow_allowed'] = true;
            $safe['mock_read_attempted'] = true;
            try {
                $second = $this->observe($location);
                $safe['sandbox_mock'] = $second['safe'];
            } catch (\Throwable) {
                $safe['mock_read_failed'] = true; // No raw exception, retry or third request.
            }
            return $safe;
        } catch (\Throwable) {
            throw new RuntimeException('The customer redirect read could not be safely observed.');
        }
    }

    /** @return array{safe:array<string,int|bool|string>,location:?string} Private Location never enters evidence. */
    private function observe(#[SensitiveParameter] string $url): array
    {
        $request = new Request('GET', $url, ['Accept' => 'text/html, application/xhtml+xml']);
        if ((string) $request->getUri() !== $url) {
            throw new RuntimeException('The customer redirect cannot be preserved exactly.');
        }
        $response = $this->http->sendRequest($request);
        $stream = $response->getBody();
        try {
            $body = '';
            while (strlen($body) <= 65536) {
                $part = $stream->read(min(8192, 65537 - strlen($body)));
                if ($part === '') {
                    if ($stream->eof()) {
                        break;
                    }
                    throw new RuntimeException('The customer response did not make bounded progress.');
                }
                $body .= $part;
                if ($stream->eof()) {
                    break;
                }
            }
            if (strlen($body) > 65536) {
                throw new RuntimeException('The customer response exceeds the observation boundary.');
            }
            $locations = $response->getHeader('Location');
            if (count($locations) > 1 || strlen($locations[0] ?? '') > 8192) {
                throw new RuntimeException('The customer response has an unsupported redirect header.');
            }
            $type = $response->getHeaderLine('Content-Type');
            if (strlen($type) > 256) {
                throw new RuntimeException('The customer response has an unsupported content type.');
            }
            $html = preg_match('~^(?:text/html|application/xhtml\+xml)(?:\s*;|$)~i', $type) === 1;
            $safe = [
                'http_status' => $response->getStatusCode(), 'origin_category' => self::simulationShape($url)
                    ? 'sandbox_api_sg_mock' : self::location($url),
                'html_content_type' => $html, 'location_present' => $locations !== [],
                'location_category' => self::location($locations[0] ?? null),
                'form_present' => false, 'form_action_present' => false, 'post_form_present' => false,
            ];
            foreach (self::diagnose($locations[0] ?? null) as $key => $value) {
                $safe['location_uri_' . $key] = $value;
            }
            // The guide shape alone is simulation-body evidence; any customer GET needs the explicit inference policy.
            $safe['location_documented_sg_simulation_shape'] = self::simulationShape($locations[0] ?? null);
            $safe['location_follow_allowed'] = false;
            if ($html && $body !== '') {
                $safe = array_replace($safe, self::forms($body));
            }
            return ['safe' => $safe, 'location' => $locations[0] ?? null];
        } finally {
            $stream->close();
        }
    }

    private static function mockReadable(#[SensitiveParameter] string $url): bool
    {
        if (!self::simulationShape($url)) {
            return false;
        }
        $safe = self::diagnose($url);
        // Public example has no query; the observed own 302 has a single checksum. Other keys stay unverified.
        return $safe['query_scan_complete'] === true && $safe['query_unlisted_keys_present'] === false
            && $safe['query_repeated_key_present'] === false
            && ($safe['query_present'] === false || $safe['query_checksum_present'] === true);
    }

    private static function simulationShape(#[SensitiveParameter] ?string $value): bool
    {
        $diagnostic = self::diagnose($value);
        if (
            !$diagnostic['lexically_valid'] || !$diagnostic['https'] || !$diagnostic['sandbox_host_matches']
            || !$diagnostic['userinfo_absent'] || !$diagnostic['explicit_port_absent']
            || !$diagnostic['fragment_absent'] || !$diagnostic['exact_uri_preserved']
        ) {
            return false;
        }
        $parts = parse_url($value ?? '');
        return is_array($parts)
            && preg_match('~^/pa/sandbox/sg/mck_[A-Za-z0-9_-]+$~D', $parts['path'] ?? '') === 1;
    }

    private static function location(#[SensitiveParameter] ?string $value): string
    {
        if ($value === null || $value === '') {
            return 'absent';
        }
        $diagnostic = self::diagnose($value);
        if ($diagnostic['eligible']) {
            return $diagnostic['path_family'] === 'redirect_sg' ? 'sandbox_api_sg_redirect' : 'sandbox_api_hk_redirect';
        }
        $url = parse_url($value);
        return is_array($url) && ($url['scheme'] ?? null) === 'https' ? 'other_https' : 'non_https_or_relative';
    }

    /** @return array<string,bool> */
    private static function forms(#[SensitiveParameter] string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (!$document->loadHTML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new RuntimeException('HTML observation failed.');
            }
            $forms = $document->getElementsByTagName('form');
            $action = false;
            $post = false;
            foreach ($forms as $form) {
                $action = $action || ($form->hasAttribute('action') && $form->getAttribute('action') !== '');
                $post = $post || strtolower($form->getAttribute('method')) === 'post';
            }
            return ['form_present' => $forms->length > 0,
                'form_action_present' => $action, 'post_form_present' => $post];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['http' => '[redacted]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Customer redirect readers cannot be serialized.');
    }
}
