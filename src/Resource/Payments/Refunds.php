<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Payments;

use SensitiveParameter;

final class Refunds extends Resource
{
    protected function contractName(): string
    {
        return 'refunds';
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function create(#[SensitiveParameter] array $body): array
    {
        return $this->call('createRefund', body: $body);
    }

    /** @return array<array-key, mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->call('retrieveRefund', ids: ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        return $this->call('listRefunds', query: $query);
    }
}
