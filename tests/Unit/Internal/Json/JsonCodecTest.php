<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Internal\Json;

use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class JsonCodecTest extends TestCase
{
    public function testEncodesOnlySchemaDecimalPositionsAsExactNumbers(): void
    {
        $codec = new JsonCodec();
        $body = [
            'amount' => '999999999999999999999.9900',
            'currency' => 'EUR',
            'id' => '00123',
            'metadata' => ['amount' => '19.90'],
            'items' => [['unit_amount' => '0.000000000001', 'quantity' => 3]],
            'false' => false,
            'zero' => 0,
            'empty' => '',
            'array' => [],
            'object' => new stdClass(),
            'nullable' => null,
        ];
        self::assertSame(
            '{"amount":999999999999999999999.9900,"currency":"EUR","id":"00123",'
            . '"metadata":{"amount":"19.90"},"items":[{"unit_amount":0.000000000001,"quantity":3}],'
            . '"false":false,"zero":0,"empty":"","array":[],"object":{},"nullable":null}',
            $codec->encode($body, ['/amount', '/items/*/unit_amount']),
        );
        self::assertSame('{}', $codec->encode([]));
        self::assertSame('{"amount":null}', $codec->encode(['amount' => null], ['/amount']));
    }

    public function testRetainsCurrencySpecificScaleWithoutArithmetic(): void
    {
        $codec = new JsonCodec();
        foreach (['JPY' => '100', 'EUR' => '0.00', 'KWD' => '0.001'] as $currency => $amount) {
            $body = ['amount' => $amount, 'currency' => $currency];
            $wire = $codec->encode($body, ['/amount']);
            self::assertSame('{"amount":' . $amount . ',"currency":"' . $currency . '"}', $wire);
            self::assertSame($body, $codec->decodeObject($wire, ['/amount']));
        }
    }

    public function testDecodesNumbersBeforeFloatConversionAndPreservesShapes(): void
    {
        $result = (new JsonCodec())->decodeObject(
            '{"amount":999999999999999999999.9900,"zero":0,"count":3,"big_count":9223372036854775808,'
            . '"unknown":1.234567890123456789e+200,"numeric_id":"00123","state":"NEW_STATE",'
            . '"timestamp":"2026-11-08T12:34:56.123456+01:00","items":[{"quantity":2.00}],'
            . '"object":{},"array":[],"nested":{"null":null,"false":false}}',
            ['/amount', '/zero', '/items/*/quantity'],
            ['/count', '/big_count'],
        );
        self::assertSame('999999999999999999999.9900', $result['amount']);
        self::assertSame('0', $result['zero']);
        self::assertSame(3, $result['count']);
        self::assertSame('9223372036854775808', $result['big_count']);
        self::assertSame('1.234567890123456789e+200', $result['unknown']);
        self::assertSame('00123', $result['numeric_id']);
        self::assertSame('NEW_STATE', $result['state']);
        self::assertSame('2026-11-08T12:34:56.123456+01:00', $result['timestamp']);
        self::assertSame([['quantity' => '2.00']], $result['items']);
        self::assertInstanceOf(stdClass::class, $result['object']);
        self::assertSame([], $result['array']);
        self::assertSame(['null' => null, 'false' => false], $result['nested']);
        self::assertSame([], (new JsonCodec())->decodeObject('{}'));
    }

    public function testPointerEscapingAndWildcardsDoNotMatchUnrelatedProperties(): void
    {
        $codec = new JsonCodec();
        self::assertSame(
            '{"a/b":{"~amount":1.50},"a":{"b":{"~amount":"1.50"}}}',
            $codec->encode(['a/b' => ['~amount' => '1.50'], 'a' => ['b' => ['~amount' => '1.50']]], ['/a~1b/~0amount']),
        );
        $json = <<<'JSON'
{"0":"zero","text":"quote\" slash\\ \n \u20ac"}
JSON;
        self::assertSame(
            ['0' => 'zero', 'text' => "quote\" slash\\ \n \u{20ac}"],
            $codec->decodeObject($json),
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDecimals(): iterable
    {
        $values = [1.25, 0, 1, true, '', '01', '+1', '.1', '1.', '1e2', ' 1', 'NaN', [], new stdClass()];
        foreach ($values as $index => $value) {
            yield (string) $index => [$value];
        }
    }

    #[DataProvider('invalidDecimals')]
    public function testRejectsInvalidDecimalInputsWithoutEchoingValues(mixed $value): void
    {
        $this->expectException(ValidationException::class);
        (new JsonCodec())->encode(['amount' => $value], ['/amount']);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedObjects(): iterable
    {
        foreach (
            ['', '[]', 'null', '1', 'true', '"text"', '{', '{"a":}', '{"a":01}', '{"a":1,}',
            '{"a":[1,]}', '{"a":1}x', '{"a":NaN}', '{"a":"\\uD800"}', "{\"a\":\"\xFF\"}",
            "{\"a\":\"line\nline\"}", '{"a":1e}', '{"a":falsee}', '{"a":"unterminated}'] as $index => $json
        ) {
            yield (string) $index => [$json];
        }
    }

    #[DataProvider('malformedObjects')]
    public function testRejectsMalformedJsonOrNonObjectRoots(string $json): void
    {
        $this->expectException(ProtocolException::class);
        (new JsonCodec())->decodeObject($json);
    }

    public function testRejectsExcessiveDepthAndCycles(): void
    {
        $body = [];
        $body['cycle'] = &$body;
        $this->expectException(ValidationException::class);
        (new JsonCodec())->encode($body);
    }

    public function testRejectsExcessiveResponseDepth(): void
    {
        $this->expectException(ProtocolException::class);
        (new JsonCodec())->decodeObject('{"nested":' . str_repeat('[', 130) . '0' . str_repeat(']', 130) . '}');
    }

    public function testKnownIntegerBoundsAndDecimalPriority(): void
    {
        $codec = new JsonCodec();
        $result = $codec->decodeObject(
            '{"min":-9223372036854775808,"max":9223372036854775807,"overflow":-9223372036854775809,'
            . '"amount":1,"unknown":0,"exponent_count":1e0}',
            ['/amount'],
            ['/min', '/max', '/overflow', '/amount', '/exponent_count'],
        );
        self::assertSame(PHP_INT_MIN, $result['min']);
        self::assertSame(PHP_INT_MAX, $result['max']);
        self::assertSame('-9223372036854775809', $result['overflow']);
        self::assertSame('1', $result['amount']);
        self::assertSame('0', $result['unknown']);
        self::assertSame('1e0', $result['exponent_count']);
    }
}
