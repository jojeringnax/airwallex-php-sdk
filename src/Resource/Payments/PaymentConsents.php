<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Payments;

use SensitiveParameter;

final class PaymentConsents extends Resource
{
    protected function contractName(): string
    {
        return 'payment_consents';
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function create(#[SensitiveParameter] array $body): array
    {
        return $this->call('createPaymentConsent', body: $body);
    }

    /** @return array<array-key, mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->call('retrievePaymentConsent', ids: ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        return $this->call('listPaymentConsents', query: $query);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function update(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('updatePaymentConsent', ids: ['id' => $id], body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function verify(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('verify', ids: ['id' => $id], body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function continueVerification(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('verifyContinue', ids: ['id' => $id], body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function disable(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('paymentConsents_disable', ids: ['id' => $id], body: $body);
    }
}
