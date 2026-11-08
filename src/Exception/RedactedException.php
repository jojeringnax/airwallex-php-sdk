<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Exception;

/** @internal Default diagnostics omit stack arguments, previous failures and raw payloads. */
trait RedactedException
{
    public function __toString(): string
    {
        return static::class . ': ' . $this->getMessage() . ' in ' . $this->getFile() . ':' . $this->getLine();
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array
    {
        return ['message' => $this->getMessage(), 'file' => $this->getFile(), 'line' => $this->getLine()];
    }

    /** @return array<string, int|string|null> */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        parent::__construct('Airwallex SDK operation failed.');
    }
}
