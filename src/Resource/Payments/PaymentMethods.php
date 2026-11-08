<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Payments;

use SensitiveParameter;

final class PaymentMethods extends Resource
{
    protected function contractName(): string
    {
        return 'payment_methods';
    }

    /** @return array<array-key, mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->call('retrievePaymentMethod', ids: ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        return $this->call('listPaymentMethods', query: $query);
    }
}
