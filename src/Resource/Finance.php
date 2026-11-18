<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource;

use Jojeringnax\Airwallex\Internal\Http\ApiTransport;

final readonly class Finance
{
    /** @internal */
    public function __construct(private ApiTransport $transport)
    {
    }

    public function settlements(): Finance\Settlements
    {
        return new Finance\Settlements($this->transport);
    }

    public function balanceHistory(): Finance\BalanceHistory
    {
        return new Finance\BalanceHistory($this->transport);
    }

    public function transfers(): Finance\Transfers
    {
        return new Finance\Transfers($this->transport);
    }
}
