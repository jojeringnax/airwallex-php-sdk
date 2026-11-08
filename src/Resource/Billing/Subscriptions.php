<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Billing;

use SensitiveParameter;

final class Subscriptions extends Resource
{
    protected function contractName(): string
    {
        return 'subscriptions';
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function create(#[SensitiveParameter] array $body): array
    {
        return $this->call('createSubscription', body: $body);
    }

    /** @return array<array-key,mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->call('retrieveSubscription', ids: ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        return $this->call('listSubscriptions', query: $query);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function update(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('updateSubscription', ids: ['id' => $id], body: $body);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function cancel(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('subscriptions_cancel', ids: ['id' => $id], body: $body);
    }

    /** @return array<array-key,mixed> */
    public function retrieveItem(#[SensitiveParameter] string $id, #[SensitiveParameter] string $itemId): array
    {
        return $this->call('retrieveItem', ids: ['id' => $id, 'item_id' => $itemId]);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>
     */
    public function listItems(#[SensitiveParameter] string $id, #[SensitiveParameter] array $query = []): array
    {
        return $this->call('subscriptions_listItems', ids: ['id' => $id], query: $query);
    }
}
