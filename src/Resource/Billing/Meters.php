<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Billing;

use SensitiveParameter;

final class Meters extends Resource
{
    protected function contractName(): string
    {
        return 'meters';
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function create(#[SensitiveParameter] array $body): array
    {
        return $this->call('createMeter', body: $body);
    }

    /** @return array<array-key,mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->call('retrieveMeter', ids: ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        return $this->call('listMeters', query: $query);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function update(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('updateMeter', ids: ['id' => $id], body: $body);
    }

    /** @return array<array-key,mixed> */
    public function archive(#[SensitiveParameter] string $id): array
    {
        return $this->call('archive', ids: ['id' => $id]);
    }

    /** @return array<array-key,mixed> */
    public function restore(#[SensitiveParameter] string $id): array
    {
        return $this->call('restore', ids: ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>
     */
    public function listSummaries(#[SensitiveParameter] string $id, #[SensitiveParameter] array $query): array
    {
        return $this->call('listSummaries', ids: ['id' => $id], query: $query);
    }
}
