<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Webhook;

use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use SensitiveParameter;

final readonly class WebhookEvent
{
    /** @param array<array-key, mixed> $envelope */
    private function __construct(private array $envelope)
    {
    }

    public static function fromJson(#[SensitiveParameter] string $rawBody): self
    {
        $codec = new JsonCodec();
        $envelope = $codec->decodeObject($rawBody);
        $integerPaths = WebhookSchema::integerPaths($envelope);
        if ($integerPaths !== []) {
            $envelope = $codec->decodeObject($rawBody, integerPaths: $integerPaths);
        }

        return new self($envelope);
    }

    /** @return array<array-key, mixed> */
    public function toArray(): array
    {
        return $this->envelope;
    }
}
