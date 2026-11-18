<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Payments;

use Jojeringnax\Airwallex\Resource\AccountReadResource;
use SensitiveParameter;

final class PaymentDisputes extends AccountReadResource
{
    protected const string FAMILY = 'payments';
    protected const string CONTRACT = 'payment_disputes';

    /** @return array<array-key, mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->read('retrievePaymentDispute', ids: ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        return $this->read('listPaymentDisputes', query: $query);
    }
}
