<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Billing;

use SensitiveParameter;

final class Invoices extends Resource
{
    protected function contractName(): string
    {
        return 'invoices';
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function create(#[SensitiveParameter] array $body): array
    {
        return $this->call('createInvoice', body: $body);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function update(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('updateInvoice', ids: ['id' => $id], body: $body);
    }

    /** @return array<array-key,mixed> */
    public function finalize(#[SensitiveParameter] string $id): array
    {
        return $this->call('invoices_finalize', ids: ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function addLineItems(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('invoices_addLineItems', ids: ['id' => $id], body: $body);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function updateLineItems(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('invoices_updateLineItems', ids: ['id' => $id], body: $body);
    }

    /** @return array<array-key,mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->call('retrieveInvoice', ids: ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        return $this->call('listInvoices', query: $query);
    }

    /** @return array<array-key,mixed> */
    public function retrieveLineItem(#[SensitiveParameter] string $id, #[SensitiveParameter] string $itemId): array
    {
        return $this->call('invoices_retrieveLineItem', ids: ['id' => $id, 'item_id' => $itemId]);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>
     */
    public function listLineItems(#[SensitiveParameter] string $id, #[SensitiveParameter] array $query = []): array
    {
        return $this->call('invoices_listLineItems', ids: ['id' => $id], query: $query);
    }
}
