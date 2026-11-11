<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Internal\Json;

use Jojeringnax\Airwallex\Exception\ProtocolException;
use SensitiveParameter;
use stdClass;

/** @internal Parses numeric tokens before any float conversion can occur. */
final class JsonParser
{
    private int $offset = 0;
    private bool $objectRoot = false;

    /**
     * @param list<string> $decimalPaths
     * @param list<string> $integerPaths
     */
    public function __construct(
        #[SensitiveParameter] private readonly string $json,
        private readonly array $decimalPaths,
        private readonly array $integerPaths,
        private readonly bool $preserveObjects = false,
    ) {
    }

    public function rootIsObject(): bool
    {
        return $this->objectRoot;
    }

    public function parse(): mixed
    {
        $this->skipWhitespace();
        $this->objectRoot = $this->peek() === '{';
        $result = $this->value('', 0);
        $this->skipWhitespace();
        if ($this->offset !== strlen($this->json)) {
            $this->invalid();
        }

        return $result;
    }

    private function value(#[SensitiveParameter] string $path, int $depth): mixed
    {
        if ($depth > 128) {
            $this->invalid();
        }
        $this->skipWhitespace();
        return match ($this->peek()) {
            '{' => $this->object($path, $depth),
            '[' => $this->list($path, $depth),
            '"' => $this->string(),
            't' => $this->literal('true', true),
            'f' => $this->literal('false', false),
            'n' => $this->literal('null', null),
            default => $this->number($path),
        };
    }

    /** @return array<array-key, mixed>|stdClass */
    private function object(#[SensitiveParameter] string $path, int $depth): array|stdClass
    {
        ++$this->offset;
        $this->skipWhitespace();
        if ($this->peek() === '}') {
            ++$this->offset;
            return new stdClass();
        }
        $result = [];
        do {
            $this->skipWhitespace();
            if ($this->peek() !== '"') {
                $this->invalid();
            }
            $key = $this->string();
            $this->skipWhitespace();
            $this->consume(':');
            $result[$key] = $this->value(JsonCodec::childPath($path, $key), $depth + 1);
            $this->skipWhitespace();
            if ($this->peek() === '}') {
                ++$this->offset;
                return $this->preserveObjects ? (object) $result : $result;
            }
            $this->consume(',');
        } while (true);
    }

    /** @return list<mixed> */
    private function list(#[SensitiveParameter] string $path, int $depth): array
    {
        ++$this->offset;
        $this->skipWhitespace();
        if ($this->peek() === ']') {
            ++$this->offset;
            return [];
        }
        $result = [];
        do {
            $result[] = $this->value(JsonCodec::childPath($path, count($result)), $depth + 1);
            $this->skipWhitespace();
            if ($this->peek() === ']') {
                ++$this->offset;
                return $result;
            }
            $this->consume(',');
        } while (true);
    }

    private function string(): string
    {
        $start = $this->offset++;
        $escaped = false;
        while ($this->offset < strlen($this->json)) {
            $character = $this->json[$this->offset++];
            if (!$escaped && $character === '"') {
                try {
                    $result = json_decode(
                        substr($this->json, $start, $this->offset - $start),
                        flags: JSON_THROW_ON_ERROR,
                    );
                } catch (\JsonException) {
                    $this->invalid();
                }
                if (!is_string($result)) {
                    $this->invalid();
                }

                return $result;
            }
            $escaped = !$escaped && $character === '\\';
        }
        $this->invalid();
    }

    private function number(#[SensitiveParameter] string $path): string|int
    {
        $matched = preg_match(
            '/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/',
            $this->json,
            $match,
            0,
            $this->offset,
        );
        if ($matched !== 1) {
            $this->invalid();
        }
        $token = $match[0];
        $this->offset += strlen($token);
        if (!JsonCodec::matches($path, $this->decimalPaths) && JsonCodec::matches($path, $this->integerPaths)) {
            if (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $token) === 1) {
                $integer = filter_var($token, FILTER_VALIDATE_INT);
                if ($integer !== false) {
                    return $integer;
                }
            }
        }

        return $token;
    }

    private function literal(string $token, mixed $value): mixed
    {
        if (substr($this->json, $this->offset, strlen($token)) !== $token) {
            $this->invalid();
        }
        $this->offset += strlen($token);
        return $value;
    }

    private function consume(string $token): void
    {
        if ($this->peek() !== $token) {
            $this->invalid();
        }
        ++$this->offset;
    }

    private function peek(): string
    {
        return $this->json[$this->offset] ?? '';
    }

    private function skipWhitespace(): void
    {
        $this->offset += strspn($this->json, " \t\n\r", $this->offset);
    }

    private function invalid(): never
    {
        throw new ProtocolException('Response contains malformed or excessively nested JSON.');
    }
}
