<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource;

use Jojeringnax\Airwallex\Internal\Http\ApiTransport;

final readonly class Payments
{
    /** @internal */
    public function __construct(private ApiTransport $transport)
    {
    }

    public function customers(): Payments\Customers
    {
        return new Payments\Customers($this->transport);
    }

    public function paymentConsents(): Payments\PaymentConsents
    {
        return new Payments\PaymentConsents($this->transport);
    }

    public function paymentMethods(): Payments\PaymentMethods
    {
        return new Payments\PaymentMethods($this->transport);
    }

    public function paymentIntents(): Payments\PaymentIntents
    {
        return new Payments\PaymentIntents($this->transport);
    }

    public function paymentAttempts(): Payments\PaymentAttempts
    {
        return new Payments\PaymentAttempts($this->transport);
    }

    public function refunds(): Payments\Refunds
    {
        return new Payments\Refunds($this->transport);
    }
}
