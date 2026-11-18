<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Payments;

use Jojeringnax\Airwallex\Internal\Schema\Validator;
use Jojeringnax\Airwallex\Resource\AccountReadResource;
use SensitiveParameter;

final class SettlementRecords extends AccountReadResource
{
    protected const string FAMILY = 'payments';
    protected const string CONTRACT = 'settlement_records';

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        if (!isset($query['settlement_id']) && !isset($query['payment_intent_id'])) {
            Validator::fail();
        }

        return $this->read('listSettlementRecords', query: $query);
    }
}
