<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

/** Compares provider decimal text with a fixed plain-decimal scenario expectation. */
final class ExactDecimal
{
    public static function equalsLiteral(mixed $value, string $literal): bool
    {
        if (!is_string($value) || strpbrk($literal, 'eE') !== false) {
            return false;
        }
        $actual = self::parts($value);
        $expected = self::parts($literal);
        if ($actual === null || $expected === null || $actual['digits'] !== $expected['digits']) {
            return false;
        }
        if ($actual['digits'] === '') {
            return true; // All signed/scaled zero representations have the same value.
        }
        return $actual['negative'] === $expected['negative']
            && $actual['exponent'] === (string) ($expected['scale'] - $actual['scale']);
    }

    /** @return array{digits:string,negative:bool,scale:int,exponent:string}|null */
    private static function parts(string $value): ?array
    {
        if (preg_match('/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D', $value, $parts) !== 1) {
            return null;
        }
        $fraction = $parts[3] ?? '';
        $digits = $parts[2] . $fraction;
        $trimmed = rtrim($digits, '0');
        $exponent = $parts[4] ?? '0';
        $exponentDigits = ltrim(ltrim($exponent, '+-'), '0');
        return [
            'digits' => ltrim($trimmed, '0'),
            'negative' => $parts[1] === '-',
            'scale' => strlen($digits) - strlen($trimmed) - strlen($fraction),
            'exponent' => $exponentDigits === '' ? '0'
                : (str_starts_with($exponent, '-') ? '-' : '') . $exponentDigits,
        ];
    }
}
