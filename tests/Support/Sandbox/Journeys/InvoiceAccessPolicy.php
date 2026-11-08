<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use SensitiveParameter;

/** Closed first-party capability shapes; unknown links remain withheld, without rewriting. */
final class InvoiceAccessPolicy
{
    public const string ORIGIN = 'https://invoice.sandbox.airwallex.com';
    public const string PAYER = self::ORIGIN . '/billing/payer-web-api/invoice';

    public static function pdf(#[SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) && self::token($value, '/billing/invoices/pdf') !== null ? $value : null;
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
        // This is an opaque bounded capability, with no PDF JWT/expiry interpretation.
        return strlen($token) <= 8192 && preg_match('/^[A-Za-z0-9._~+\/=-]+$/D', $token) === 1 ? $token : null;
    }
}
