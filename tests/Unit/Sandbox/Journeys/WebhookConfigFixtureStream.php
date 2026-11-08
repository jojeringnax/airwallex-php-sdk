<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** Adversarial streams must be read incrementally and closed without leaking errors. */
final class WebhookConfigFixtureStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;
    public bool $closed = false;
    public int $bytesRead = 0;
    public int $reads = 0;

    public function __construct(string $body, private string $mode = 'normal', private int $chunk = 8192)
    {
        $this->stream = Utils::streamFor($body);
    }

    public function __toString(): string
    {
        throw new RuntimeException('SYNTHETIC_PRIVATE_STRINGIFICATION');
    }

    public function read(int $length): string
    {
        ++$this->reads;
        if ($this->mode === 'read-failure') {
            throw new RuntimeException('SYNTHETIC_PRIVATE_READ');
        }
        $part = $this->mode === 'stalled' ? '' : $this->stream->read(min($length, $this->chunk));
        $this->bytesRead += strlen($part);
        return $part;
    }

    public function eof(): bool
    {
        return $this->mode !== 'stalled' && $this->stream->eof();
    }

    public function close(): void
    {
        $this->closed = true;
        $this->stream->close();
        if ($this->mode === 'close-failure') {
            throw new RuntimeException('SYNTHETIC_PRIVATE_CLOSE');
        }
    }
}
