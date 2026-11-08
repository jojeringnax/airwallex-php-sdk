<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Payments;

use SensitiveParameter;

final class PaymentAttempts extends Resource
{
    protected function contractName(): string
    {
        return 'payment_attempts';
    }

    /** @return array<array-key, mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->call('retrievePaymentAttempt', ids: ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        return $this->call('listPaymentAttempts', query: $query);
    }
}
