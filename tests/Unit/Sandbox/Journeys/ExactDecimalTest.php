<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\ExactDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExactDecimalTest extends TestCase
{
    /** @return iterable<string,array{mixed,string,bool}> */
    public static function comparisons(): iterable
    {
        yield 'unchanged' => ['1.23', '1.23', true];
        yield 'trailing zeros' => ['1.230000', '1.23', true];
        yield 'scientific negative exponent' => ['123e-2', '1.23', true];
        yield 'scientific positive exponent' => ['0.012300E+0002', '1.23', true];
        yield 'large integer exact' => ['123456789012345678901234567890.00', '123456789012345678901234567890', true];
        yield 'high precision mismatch' => ['1.2300000000000000000000001', '1.23', false];
        yield 'wrong exponent' => ['123e-3', '1.23', false];
        yield 'opposite sign' => ['-1.23', '1.23', false];
        yield 'negative values' => ['-123E-2', '-1.2300', true];
        yield 'signed zero with huge exponent' => ['-0.000e99999999999999999999999999', '0', true];
        yield 'huge exponent remains textual' => ['1.23e99999999999999999999999999', '1.23', false];
        yield 'float rejected' => [1.23, '1.23', false];
        yield 'missing value' => [null, '1.23', false];
        yield 'malformed text' => ['1.23oops', '1.23', false];
        yield 'JSON leading zero rejected' => ['01.23', '1.23', false];
    }

    #[DataProvider('comparisons')]
    public function testExactValueComparisonWithoutFloatConversion(mixed $value, string $literal, bool $expected): void
    {
        self::assertSame($expected, ExactDecimal::equalsLiteral($value, $literal));
    }
}
