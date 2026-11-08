<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Payments;

use SensitiveParameter;

final class Customers extends Resource
{
    protected function contractName(): string
    {
        return 'customers';
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function create(#[SensitiveParameter] array $body): array
    {
        return $this->call('createCustomer', body: $body);
    }

    /** @return array<array-key, mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->call('retrieveCustomer', ids: ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        return $this->call('listCustomers', query: $query);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function update(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('updateCustomer', ids: ['id' => $id], body: $body);
    }
}
