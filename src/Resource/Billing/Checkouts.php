<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Billing;

use SensitiveParameter;

final class Checkouts extends Resource
{
    protected function contractName(): string
    {
        return 'billing_checkouts';
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function create(#[SensitiveParameter] array $body): array
    {
        return $this->call('createBillingCheckout', body: $body);
    }

    /** @return array<array-key,mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->call('retrieveBillingCheckout', ids: ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function update(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('updateBillingCheckout', ids: ['id' => $id], body: $body);
    }

    /** @return array<array-key,mixed> */
    public function cancel(#[SensitiveParameter] string $id): array
    {
        return $this->call('billingCheckouts_cancel', ids: ['id' => $id]);
    }
}
