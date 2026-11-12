<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Billing;

use SensitiveParameter;

final class CreditNotes extends Resource
{
    protected function contractName(): string
    {
        return 'credit_notes';
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function create(#[SensitiveParameter] array $body): array
    {
        return $this->call('createCreditNote', body: $body);
    }

    /** @return array<array-key,mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->call('retrieveCreditNote', ids: ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        return $this->call('listCreditNotes', query: $query);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function addLineItems(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('creditNotes_addLineItems', ids: ['id' => $id], body: $body);
    }

    /** @return array<array-key,mixed> */
    public function retrieveLineItem(#[SensitiveParameter] string $id, #[SensitiveParameter] string $itemId): array
    {
        return $this->call('creditNotes_retrieveLineItem', ids: ['id' => $id, 'line_item_id' => $itemId]);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>
     */
    public function listLineItems(#[SensitiveParameter] string $id, #[SensitiveParameter] array $query = []): array
    {
        return $this->call('creditNotes_listLineItems', ids: ['id' => $id], query: $query);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function finalize(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('creditNotes_finalize', ids: ['id' => $id], body: $body);
    }

    /** @return array<array-key,mixed> */
    public function void(#[SensitiveParameter] string $id): array
    {
        return $this->call('creditNotes_void', ids: ['id' => $id]);
    }
}
