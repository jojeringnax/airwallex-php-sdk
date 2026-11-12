<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource;

use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Internal\Http\ApiTransport;
use Jojeringnax\Airwallex\Resource\Billing\Checkouts;
use Jojeringnax\Airwallex\Resource\Billing\Coupons;
use Jojeringnax\Airwallex\Resource\Billing\Customers;
use Jojeringnax\Airwallex\Resource\Billing\Invoices;
use Jojeringnax\Airwallex\Resource\Billing\Meters;
use Jojeringnax\Airwallex\Resource\Billing\PaymentSources;
use Jojeringnax\Airwallex\Resource\Billing\Prices;
use Jojeringnax\Airwallex\Resource\Billing\Products;
use Jojeringnax\Airwallex\Resource\Billing\Subscriptions;
use Jojeringnax\Airwallex\Resource\Billing\Transactions;
use Jojeringnax\Airwallex\Resource\Billing\UsageEvents;

final readonly class Billing
{
    /** @internal Use Client::billing(). */
    public function __construct(private ApiTransport $transport)
    {
    }

    /** @internal */
    public function transport(): ApiTransport
    {
        return $this->transport;
    }

    public function customers(): Customers
    {
        return new Customers($this->transport);
    }

    public function products(): Products
    {
        return new Products($this->transport);
    }

    public function prices(): Prices
    {
        return new Prices($this->transport);
    }

    public function paymentSources(): PaymentSources
    {
        return new PaymentSources($this->transport);
    }

    public function checkouts(): Checkouts
    {
        return new Checkouts($this->transport);
    }

    public function subscriptions(): Subscriptions
    {
        return new Subscriptions($this->transport);
    }

    public function invoices(): Invoices
    {
        return new Invoices($this->transport);
    }

    public function transactions(): Transactions
    {
        return new Transactions($this->transport);
    }

    public function coupons(): Coupons
    {
        return new Coupons($this->transport);
    }

    public function meters(): Meters
    {
        return new Meters($this->transport);
    }

    public function usageEvents(): UsageEvents
    {
        return new UsageEvents($this->transport);
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new ConfigurationException('Configured resource groups cannot be serialized.');
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['family' => 'billing'];
    }
}
