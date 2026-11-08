<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use GuzzleHttp\Psr7\Uri;
use SensitiveParameter;
use Throwable;

/** Closed first-party capability shapes; unknown links remain withheld, without rewriting. */
final class InvoiceAccessPolicy
{
    public const string ORIGIN = 'https://invoice.sandbox.airwallex.com';
    public const string PAYER = self::ORIGIN . '/billing/payer-web-api/invoice';

    public static function pdf(#[SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) && self::pdfPolicy($value) === 'admitted' ? $value : null;
    }

    /** Closed URI decision only; PDF path/query are opaque and this never returns URL components. */
    public static function pdfPolicy(#[SensitiveParameter] mixed $value): string
    {
        if (!is_string($value)) {
            return $value === null ? 'absent_or_null' : 'wrong_type';
        }
        if (strlen($value) > 32768) {
            return 'oversize';
        }
        if (
            preg_match('/[^\x21-\x7e]|[\\\\#]/', $value) === 1
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $value) === 1
        ) {
            return 'lexical';
        }
        if (preg_match('/%(?![a-fA-F0-9]{2})/', $value) === 1) {
            return 'uri';
        }
        try {
            $parts = parse_url($value);
            if (
                !is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
                || ($parts['host'] ?? null) !== 'invoice.sandbox.airwallex.com'
                || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['fragment'])
            ) {
                return 'origin';
            }
            return (string) new Uri($value) === $value ? 'admitted' : 'uri';
        } catch (Throwable) {
            return 'uri';
        }
    }

    public static function hostedToken(#[SensitiveParameter] mixed $value): ?string
    {
        return self::token($value, '/pay');
    }

    private static function token(#[SensitiveParameter] mixed $value, string $path): ?string
    {
        $prefix = self::ORIGIN . $path . '?s=';
        if (
            !is_string($value) || strlen($value) > 32768 || !str_starts_with($value, $prefix)
            || preg_match('/[^\x21-\x7e]|[\\\\#&+]/', $value) === 1
        ) {
            return null;
        }
        $encoded = substr($value, strlen($prefix));
        if ($encoded === '' || preg_match('/%(?![a-fA-F0-9]{2})/', $encoded) === 1) {
            return null;
        }
        $token = rawurldecode($encoded);
        // This is an opaque bounded hosted capability, with no JWT/expiry interpretation.
        return strlen($token) <= 8192 && preg_match('/^[A-Za-z0-9._~+\/=-]+$/D', $token) === 1 ? $token : null;
    }
}
