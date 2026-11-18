<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Finance;

use Jojeringnax\Airwallex\Resource\AccountReadResource;
use SensitiveParameter;

final class Settlements extends AccountReadResource
{
    protected const string FAMILY = 'finance';
    protected const string CONTRACT = 'settlements';

    /** @return array<array-key, mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->read('retrieveSettlement', ids: ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    public function list(#[SensitiveParameter] array $query): array
    {
        return $this->read('listSettlement', query: $query);
    }
}
