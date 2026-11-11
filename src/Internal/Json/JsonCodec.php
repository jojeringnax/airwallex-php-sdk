<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Internal\Json;

use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use SensitiveParameter;
use stdClass;

/** @internal */
final class JsonCodec
{
    /**
     * @param array<array-key, mixed> $body
     * @param list<string> $decimalPaths JSON Pointer patterns; * matches one segment.
     */
    public function encode(#[SensitiveParameter] array $body, array $decimalPaths = []): string
    {
        // A resource request is an object, including an empty request body.
        return $this->encodeValue((object) $body, '', $decimalPaths, 0);
    }

    /**
     * @param list<string> $decimalPaths
     * @param list<string> $integerPaths
     * @return array<array-key, mixed>
     */
    public function decodeObject(
        #[SensitiveParameter] string $json,
        array $decimalPaths = [],
        array $integerPaths = [],
        bool $preserveObjects = false,
    ): array {
        $parser = new JsonParser($json, $decimalPaths, $integerPaths, $preserveObjects);
        $result = $parser->parse();
        if (!$parser->rootIsObject() || (!is_array($result) && !$result instanceof stdClass)) {
            throw new ProtocolException('Expected a JSON object response.');
        }

        return $result instanceof stdClass ? get_object_vars($result) : $result;
    }

    /** @param list<string> $patterns */
    public static function matches(#[SensitiveParameter] string $path, array $patterns): bool
    {
        $segments = explode('/', $path);
        foreach ($patterns as $pattern) {
            $expected = explode('/', $pattern);
            if (count($segments) !== count($expected)) {
                continue;
            }
            foreach ($expected as $index => $part) {
                if ($part !== '*' && $part !== $segments[$index]) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    public static function childPath(
        #[SensitiveParameter] string $path,
        #[SensitiveParameter] string|int $key,
    ): string {
        return $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key);
    }

    /** @param list<string> $decimalPaths */
    private function encodeValue(
        #[SensitiveParameter] mixed $value,
        #[SensitiveParameter] string $path,
        array $decimalPaths,
        int $depth,
    ): string {
        if ($depth > 128) {
            throw new ValidationException('Request nesting exceeds the supported depth.');
        }
        if (self::matches($path, $decimalPaths) && $value !== null) {
            if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
                throw new ValidationException('Decimal fields require plain base-10 strings.');
            }

            return $value;
        }
        if (is_array($value) || $value instanceof stdClass) {
            $object = $value instanceof stdClass || !array_is_list($value);
            $entries = [];
            foreach ((array) $value as $key => $child) {
                $encoded = $this->encodeValue($child, self::childPath($path, $key), $decimalPaths, $depth + 1);
                $entries[] = $object ? $this->encodeScalar((string) $key) . ':' . $encoded : $encoded;
            }

            return $object ? '{' . implode(',', $entries) . '}' : '[' . implode(',', $entries) . ']';
        }
        if (is_object($value) || is_resource($value)) {
            throw new ValidationException('Request contains an unsupported JSON value.');
        }

        return $this->encodeScalar($value);
    }

    private function encodeScalar(#[SensitiveParameter] mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            throw new ValidationException('Request contains an invalid JSON value.');
        }
    }
}
