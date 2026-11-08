<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Payments;

use SensitiveParameter;

final class PaymentIntents extends Resource
{
    protected function contractName(): string
    {
        return 'payment_intents';
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function create(#[SensitiveParameter] array $body): array
    {
        return $this->call('createPaymentIntent', body: $body);
    }

    /** @return array<array-key, mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->call('retrievePaymentIntent', ids: ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        return $this->call('listPaymentIntents', query: $query);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function confirm(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('paymentIntents_confirm', ids: ['id' => $id], body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function continueConfirmation(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('confirmContinue', ids: ['id' => $id], body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function capture(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('capture', ids: ['id' => $id], body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function cancel(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('paymentIntents_cancel', ids: ['id' => $id], body: $body);
    }
}
