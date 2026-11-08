<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class SimulationProbeStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;
    public int $bytes = 0;
    public bool $closed = false;

    public function __construct(
        string $body,
        private readonly string $mode = '',
        private readonly ?Closure $afterRead = null,
        private readonly ?Closure $afterClose = null,
    ) {
        $this->stream = Utils::streamFor($body);
    }

    public function __toString(): string
    {
        throw new RuntimeException('PRIVATE_UNBOUNDED_STRING_CAST');
    }

    public function read($length): string
    {
        if ($this->mode === 'stalled') {
            return '';
        }
        if ($this->mode === 'broken') {
            throw new RuntimeException('PRIVATE_STREAM');
        }
        $part = $this->stream->read($this->mode === 'one_byte' ? 1 : $length);
        $this->bytes += strlen($part);
        ($this->afterRead ?? static fn () => null)();
        return $part;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new RuntimeException('PRIVATE_NON_SEEKABLE');
    }

    public function eof(): bool
    {
        return $this->mode !== 'stalled' && $this->stream->eof();
    }

    public function close(): void
    {
        $this->closed = true;
        $this->stream->close();
        ($this->afterClose ?? static fn () => null)();
        if ($this->mode === 'close_failure') {
            throw new RuntimeException('PRIVATE_CLOSE');
        }
    }
}
