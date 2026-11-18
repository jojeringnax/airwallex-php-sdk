<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Finance;

use Jojeringnax\Airwallex\Resource\AccountReadResource;
use SensitiveParameter;

final class Transfers extends AccountReadResource
{
    protected const string FAMILY = 'finance';
    protected const string CONTRACT = 'transfers';

    /** @return array<array-key, mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->read('retrieveTransfer', ids: ['id' => $id]);
    }
}
