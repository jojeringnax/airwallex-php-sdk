<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Internal\Schema;

use SensitiveParameter;
use Jojeringnax\Airwallex\Exception\ValidationException;
use stdClass;

/** @internal Validates the request side of the selected provider contract. */
final class Validator
{
    /**
     * @param array<string, mixed> $schema
     * @param array<string, array<string, mixed>> $definitions
     */
    public static function validate(
        #[SensitiveParameter] mixed $value,
        array $schema,
        array $definitions,
        bool $clearable = false,
        bool $root = false,
        bool $allowNull = false,
    ): void {
        $schema = self::resolve($schema, $definitions);
        if ($value === null) {
            if ($allowNull || ($schema['nullable'] ?? false) === true) {
                return;
            }
            self::fail();
        }
        $type = $schema['type'] ?? null;
        if ($type === 'object') {
            if ($value instanceof stdClass) {
                $value = get_object_vars($value);
                $additional = $schema['additionalProperties'] ?? null;
                $stringMap = is_array($additional) && ($additional['type'] ?? null) === 'string';
                if ($value !== [] && !$stringMap) {
                    self::fail();
                }
            } elseif (!is_array($value) || (array_is_list($value) && (!$root || $value !== []))) {
                self::fail();
            }
            /** @var array<string, array<string, mixed>> $properties */
            $properties = $schema['properties'] ?? [];
            /** @var list<string> $required */
            $required = $schema['required'] ?? [];
            foreach ($required as $key) {
                if (!array_key_exists($key, $value)) {
                    self::fail();
                }
            }
            foreach ($value as $key => $child) {
                if (array_key_exists($key, $properties)) {
                    self::validate(
                        $child,
                        $properties[$key],
                        $definitions,
                        $clearable,
                        false,
                        $clearable && !in_array($key, $required, true),
                    );
                } elseif (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
                    /** @var array<string, mixed> $additional */
                    $additional = $schema['additionalProperties'];
                    self::validate($child, $additional, $definitions);
                } else {
                    self::fail();
                }
            }
        } elseif ($type === 'array') {
            if (!is_array($value) || !array_is_list($value)) {
                self::fail();
            }
            if (
                (isset($schema['minItems']) && count($value) < $schema['minItems'])
                || (isset($schema['maxItems']) && count($value) > $schema['maxItems'])
            ) {
                self::fail();
            }
            /** @var array<string, mixed> $items */
            $items = $schema['items'] ?? [];
            foreach ($value as $item) {
                // Field clearing does not make a list element nullable.
                self::validate($item, $items, $definitions, $clearable);
            }
        } elseif ($type === 'string') {
            if (!is_string($value) || !preg_match('//u', $value)) {
                self::fail();
            }
            $length = preg_match_all('/./us', $value);
            if (
                (isset($schema['minLength']) && $length < $schema['minLength'])
                || (isset($schema['maxLength']) && $length > $schema['maxLength'])
            ) {
                self::fail();
            }
            if (
                isset($schema['pattern']) && is_string($schema['pattern'])
                && preg_match('~' . str_replace('~', '\\~', $schema['pattern']) . '~D', $value) !== 1
            ) {
                self::fail();
            }
            if (($schema['format'] ?? null) === 'date-time') {
                self::timestamp($value);
            }
        } elseif ($type === 'integer') {
            if (!is_int($value)) {
                self::fail();
            }
            if (($schema['format'] ?? null) === 'int32' && ($value < -2147483648 || $value > 2147483647)) {
                self::fail();
            }
            if (
                (isset($schema['minimum']) && $value < $schema['minimum'])
                || (isset($schema['maximum']) && $value > $schema['maximum'])
            ) {
                self::fail();
            }
        } elseif ($type === 'number') {
            if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
                self::fail();
            }
            foreach (['minimum', 'maximum'] as $bound) {
                if (isset($schema[$bound]) && (is_int($schema[$bound]) || is_string($schema[$bound]))) {
                    $comparison = self::compareDecimals($value, (string) $schema[$bound]);
                    if (($bound === 'minimum' && $comparison < 0) || ($bound === 'maximum' && $comparison > 0)) {
                        self::fail();
                    }
                }
            }
        } elseif ($type === 'boolean') {
            if (!is_bool($value)) {
                self::fail();
            }
        } else {
            self::fail();
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            self::fail();
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, array<string, mixed>> $definitions
     * @return array<string, mixed>
     */
    public static function resolve(array $schema, array $definitions): array
    {
        $visited = [];
        while (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $reference = $schema['$ref'];
            $name = substr($reference, strlen('#/components/schemas/'));
            if (
                !str_starts_with($reference, '#/components/schemas/') || isset($visited[$name])
                || !isset($definitions[$name])
            ) {
                throw new \LogicException('Invalid bundled contract reference.');
            }
            $visited[$name] = true;
            unset($schema['$ref']);
            $schema = array_replace($definitions[$name], $schema);
        }
        return $schema;
    }

    public static function timestamp(#[SensitiveParameter] string $value): void
    {
        if (
            preg_match(
                '/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d):([0-5]\d)'
                . '(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)$/D',
                $value,
                $parts,
            ) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            self::fail();
        }
    }

    /** Compare already validated decimal strings without floating-point conversion. */
    public static function compareDecimals(string $left, string $right): int
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');
        $left = ltrim($left, '-');
        $right = ltrim($right, '-');
        $leftParts = explode('.', $left, 2);
        $rightParts = explode('.', $right, 2);
        $leftWhole = $leftParts[0];
        $leftFraction = $leftParts[1] ?? '';
        $rightWhole = $rightParts[0];
        $rightFraction = $rightParts[1] ?? '';
        if (trim($leftWhole . $leftFraction, '0') === '') {
            $leftNegative = false;
        }
        if (trim($rightWhole . $rightFraction, '0') === '') {
            $rightNegative = false;
        }
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }
        $length = max(strlen($leftFraction), strlen($rightFraction));
        $comparison = strlen($leftWhole) <=> strlen($rightWhole);
        if ($comparison === 0) {
            $comparison = strcmp($leftWhole, $rightWhole);
        }
        if ($comparison === 0) {
            $comparison = strcmp(str_pad($leftFraction, $length, '0'), str_pad($rightFraction, $length, '0'));
        }
        return $leftNegative ? -$comparison : $comparison;
    }

    public static function fail(): never
    {
        throw new ValidationException('Request does not match the selected operation contract.');
    }
}
