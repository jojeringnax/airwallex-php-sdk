<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Billing;

use SensitiveParameter;

final class UsageEvents extends Resource
{
    protected function contractName(): string
    {
        return 'usage_events';
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function ingest(#[SensitiveParameter] array $body): array
    {
        return $this->call('ingest', body: $body);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function batchIngest(#[SensitiveParameter] array $body): array
    {
        return $this->call('batchIngest', body: $body);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function void(#[SensitiveParameter] array $body): array
    {
        return $this->call('usageEvents_void', body: $body);
    }
}
