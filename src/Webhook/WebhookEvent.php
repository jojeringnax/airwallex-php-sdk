<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Webhook;

use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use SensitiveParameter;
use stdClass;

final readonly class WebhookEvent
{
    /** @param array<array-key, mixed> $envelope */
    private function __construct(private array $envelope)
    {
    }

    public static function fromJson(#[SensitiveParameter] string $rawBody): self
    {
        $codec = new JsonCodec();
        $event = new self($codec->decodeObject($rawBody, preserveObjects: true));
        $integerPaths = WebhookSchema::integerPaths($event->toArray());
        if ($integerPaths !== []) {
            return new self($codec->decodeObject($rawBody, integerPaths: $integerPaths, preserveObjects: true));
        }

        return $event;
    }

    /** @return array<array-key, mixed> */
    public function toArray(bool $preserveObjects = false): array
    {
        return array_map(
            static fn (mixed $value): mixed => self::copyValue($value, $preserveObjects),
            $this->envelope,
        );
    }

    private static function copyValue(#[SensitiveParameter] mixed $value, bool $preserveObjects): mixed
    {
        if ($value instanceof stdClass || is_array($value)) {
            $properties = array_map(
                static fn (mixed $child): mixed => self::copyValue($child, $preserveObjects),
                $value instanceof stdClass ? get_object_vars($value) : $value,
            );
            return $value instanceof stdClass && ($preserveObjects || $properties === [])
                ? (object) $properties : $properties;
        }

        return $value;
    }
}
