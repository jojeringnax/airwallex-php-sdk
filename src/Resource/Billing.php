<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource;

use Jojeringnax\Airwallex\Internal\Http\ApiTransport;

final readonly class Billing
{
    /** @internal */
    public function __construct(private ApiTransport $transport)
    {
    }

    /** @internal */
    public function transport(): ApiTransport
    {
        return $this->transport;
    }
}
