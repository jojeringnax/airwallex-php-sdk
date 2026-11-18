<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Finance;

use Jojeringnax\Airwallex\Internal\Schema\Validator;
use Jojeringnax\Airwallex\Resource\AccountReadResource;
use SensitiveParameter;

final class BalanceHistory extends AccountReadResource
{
    protected const string FAMILY = 'finance';
    protected const string CONTRACT = 'balance_history';

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        if (isset($query['page'], $query['page_num'])) {
            Validator::fail();
        }

        return $this->read('listHistory', query: $query);
    }
}
