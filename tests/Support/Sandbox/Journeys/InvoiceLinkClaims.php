<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use DateTimeImmutable;
use LogicException;
use SensitiveParameter;

/** Private capability inspection only. These observations do not authenticate or validate a link. */
final class InvoiceLinkClaims
{
    private const int THIRTY_FIVE_UTC_DAYS = 3024000;
    private const int URL_LIMIT = 32768;

    /** @return array<string,string|bool> */
    public static function provenance(): array
    {
        return ['build' => '2.272.0', 'verified_on' => '2026-11-08',
            'main_sha256' => '81522e42a261e67932e1ca2970cdddfe530752268db6e9f28dc866e28669a221',
            'public_and_named_sandbox_build_equal' => true];
    }

    /**
     * @param array<array-key,mixed> $first
     * @param array<array-key,mixed> $second
     * @param array{int,int}|null $firstWindow
     * @param array{int,int}|null $secondWindow
     * @return array<string,string|bool>
     */
    public static function compare(
        #[SensitiveParameter] array $first,
        #[SensitiveParameter] array $second,
        string $field,
        ?array $firstWindow = null,
        ?array $secondWindow = null,
    ): array {
        if (!in_array($field, ['pdf_url', 'hosted_url'], true)) {
            throw new LogicException('Only the two selected Invoice link fields can be observed.');
        }
        $a = $first[$field] ?? null;
        $b = $second[$field] ?? null;
        $available = is_string($a) && is_string($b) && trim($a) !== '' && trim($b) !== ''
            && strlen($a) <= self::URL_LIMIT && strlen($b) <= self::URL_LIMIT;
        $result = ['first_state' => self::state($first, $field), 'second_state' => self::state($second, $field),
            'comparison_available' => $available, 'url_same' => $available && $a === $b,
            'url_different' => $available && $a !== $b];
        if ($field === 'pdf_url') {
            return $result; // No PDF URL component, token, file or expiry inspection.
        }
        $firstClaim = self::claim($a);
        $secondClaim = self::claim($b);
        $result += self::clockEvidence('first', $firstClaim, $first, $firstWindow);
        $result += self::clockEvidence('second', $secondClaim, $second, $secondWindow);
        $both = $firstClaim !== null && $secondClaim !== null;
        return $result + ['exp_comparison_available' => $both, 'exp_same' => $both && $firstClaim === $secondClaim,
            'exp_increased' => $both && $secondClaim > $firstClaim,
            'exp_decreased' => $both && $secondClaim < $firstClaim];
    }

    /** @param array<array-key,mixed> $object */
    private static function state(#[SensitiveParameter] array $object, string $field): string
    {
        if (!array_key_exists($field, $object)) {
            return 'missing';
        }
        $value = $object[$field];
        if ($value === null) {
            return 'null';
        }
        return is_string($value) && trim($value) !== '' ? 'nonempty' : 'invalid';
    }

    /**
     * Only the verified /pay, unique literal s query and the public build's middle-segment JSON pattern.
     * This deliberately does not normalize other JWT/base64url formats or verify a signature.
     */
    private static function claim(#[SensitiveParameter] mixed $url): ?int
    {
        if (
            !is_string($url) || strlen($url) > self::URL_LIMIT
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
        ) {
            return null;
        }
        $parts = parse_url($url);
        if (
            !is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || !in_array(strtolower($parts['host'] ?? ''), [
                'invoice.airwallex.com', 'invoice.sandbox.airwallex.com',
            ], true)
            || ($parts['path'] ?? null) !== '/pay' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)
        ) {
            return null;
        }
        $query = $parts['query'] ?? '';
        // Extra/duplicate keys and ambiguous '+' encoding are outside this narrow observed format.
        if (
            !str_starts_with($query, 's=') || str_contains($query, '&') || str_contains($query, '+')
            || preg_match('/%(?![0-9a-fA-F]{2})/', $query) === 1
        ) {
            return null;
        }
        $token = rawurldecode(substr($query, 2));
        $segments = explode('.', $token);
        if (
            count($segments) !== 3 || $segments[0] === '' || $segments[2] === ''
            || strlen($segments[1]) > 12288 || strlen($segments[1]) % 4 === 1
            || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $segments[1]) !== 1
        ) {
            return null;
        }
        $json = base64_decode($segments[1], true);
        if ($json === false || strlen($json) > 8192 || !str_starts_with(ltrim($json), '{')) {
            return null;
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!is_array($data) || !self::uniqueKeys($json)) {
                return null;
            }
            $exp = $data['exp'] ?? null;
            // Integer-only evidence avoids float conversion and JavaScript's unsafe integer range.
            if (
                !is_int($exp) || $exp < 0 || strlen((string) $exp) > 16
                || (strlen((string) $exp) === 16 && strcmp((string) $exp, '9007199254740991') > 0)
            ) {
                return null;
            }
            return $exp;
        } catch (\Throwable) {
            return null;
        }
    }

    /** JSON has already been validated; this bounded lexical pass rejects duplicate object keys at any depth. */
    private static function uniqueKeys(#[SensitiveParameter] string $json): bool
    {
        /** @var list<array{object:bool,key:bool,seen:array<string,bool>}> $stack */
        $stack = [];
        for ($index = 0; $index < strlen($json); ++$index) {
            $char = $json[$index];
            if ($char === '{' || $char === '[') {
                $stack[] = ['object' => $char === '{', 'key' => $char === '{', 'seen' => []];
            } elseif ($char === '}' || $char === ']') {
                array_pop($stack);
            } elseif ($char === ',') {
                $last = count($stack) - 1;
                if ($last >= 0 && $stack[$last]['object']) {
                    $stack[$last]['key'] = true;
                }
            } elseif ($char === '"') {
                $start = $index;
                $escaped = false;
                while (++$index < strlen($json)) {
                    $current = $json[$index];
                    if (!$escaped && $current === '"') {
                        break;
                    }
                    $escaped = !$escaped && $current === '\\';
                }
                $last = count($stack) - 1;
                if ($last >= 0 && $stack[$last]['object'] && $stack[$last]['key']) {
                    $key = json_decode(substr($json, $start, $index - $start + 1), true, flags: JSON_THROW_ON_ERROR);
                    if (!is_string($key) || isset($stack[$last]['seen'][$key])) {
                        return false;
                    }
                    $stack[$last]['seen'][$key] = true;
                    $stack[$last]['key'] = false;
                }
            }
        }
        return true;
    }

    /**
     * @param array<array-key,mixed> $invoice
     * @param array{int,int}|null $window
     * @return array<string,bool>
     */
    private static function clockEvidence(
        string $prefix,
        ?int $exp,
        #[SensitiveParameter] array $invoice,
        ?array $window
    ): array {
        $clock = $window !== null && $window[0] >= 0 && $window[1] >= $window[0];
        $result = [$prefix . '_exp_claim_available' => $exp !== null,
            $prefix . '_clock_window_available' => $clock,
            $prefix . '_exp_before_window' => $exp !== null && $clock && $exp < $window[0],
            $prefix . '_exp_after_window' => $exp !== null && $clock && $exp > $window[1],
            $prefix . '_exp_within_window' => $exp !== null && $clock && $exp >= $window[0] && $exp <= $window[1]];
        foreach (['due_at', 'created_at', 'finalized_at'] as $field) {
            $instant = self::instant($invoice[$field] ?? null);
            $available = $instant !== null && $instant['seconds'] <= PHP_INT_MAX - self::THIRTY_FIVE_UTC_DAYS;
            $result[$prefix . '_' . $field . '_clock_available'] = $available;
            $result[$prefix . '_' . $field . '_plus_35_utc_days_matches_exp'] = $available && $exp !== null
                && $instant['fraction'] === '' && $instant['seconds'] + self::THIRTY_FIVE_UTC_DAYS === $exp;
        }
        return $result;
    }

    /** @return array{seconds:int,fraction:string}|null */
    private static function instant(#[SensitiveParameter] mixed $value): ?array
    {
        if (
            !is_string($value) || strlen($value) > 128 || preg_match(
                '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.([0-9]+))?(?:Z|[+-](\d{2}):?(\d{2}))$/D',
                $value,
                $parts,
            ) !== 1 || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59
            || (int) ($parts[8] ?? '0') > 23 || (int) ($parts[9] ?? '0') > 59
        ) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if (
                ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
                || $date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)
            ) {
                return null;
            }
            return ['seconds' => $date->getTimestamp(), 'fraction' => rtrim($parts[7] ?? '', '0')];
        } catch (\Throwable) {
            return null;
        }
    }
}
