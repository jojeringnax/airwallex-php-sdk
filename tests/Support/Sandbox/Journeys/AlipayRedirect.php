<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use DOMDocument;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;

/** One credential-free customer GET; HTML is observed, never executed or submitted. */
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
        if (
            !is_string($value) || strlen($value) > 8192
            || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1
            || preg_match('/%(?![a-fA-F0-9]{2})/', $value) === 1
        ) {
            return false;
        }
        $url = parse_url($value);
        return is_array($url) && ($url['scheme'] ?? null) === 'https'
            && ($url['host'] ?? null) === 'api.sandbox.airwallex.com'
            && !isset($url['user']) && !isset($url['pass']) && !isset($url['port']) && !isset($url['fragment'])
            && preg_match('~^/pa/redirect/hk/[A-Za-z0-9_-]+$~D', $url['path'] ?? '') === 1;
    }

    /** @return array<string,int|bool|string> */
    public function inspect(#[SensitiveParameter] string $url): array
    {
        if ($this->sent || !self::eligible($url)) {
            throw new RuntimeException('The customer redirect is unavailable or outside the verified Sandbox path.');
        }
        try {
            $request = new Request('GET', $url, ['Accept' => 'text/html, application/xhtml+xml']);
            if ((string) $request->getUri() !== $url) {
                throw new RuntimeException('The customer redirect cannot be preserved exactly.');
            }
            $this->sent = true;
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
                    'http_status' => $response->getStatusCode(), 'origin_category' => 'sandbox_api_hk_redirect',
                    'html_content_type' => $html, 'location_present' => $locations !== [],
                    'location_category' => self::location($locations[0] ?? null),
                    'form_present' => false, 'form_action_present' => false, 'post_form_present' => false,
                ];
                if ($html && $body !== '') {
                    $safe = array_replace($safe, self::forms($body));
                }
                return $safe;
            } finally {
                $stream->close();
            }
        } catch (\Throwable) {
            throw new RuntimeException('The single customer redirect read could not be safely observed.');
        }
    }

    private static function location(#[SensitiveParameter] ?string $value): string
    {
        if ($value === null || $value === '') {
            return 'absent';
        }
        if (self::eligible($value)) {
            return 'sandbox_api_hk_redirect';
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
