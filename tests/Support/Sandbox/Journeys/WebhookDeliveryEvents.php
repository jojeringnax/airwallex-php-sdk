<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

/** Closed test-only pairs; no caller-provided event subscription. */
enum WebhookDeliveryEvents
{
    case Payments;
    case BillingCheckout;

    /** @return list<string> */
    public function names(): array
    {
        return match ($this) {
            self::Payments => ['payment_intent.created', 'payment_intent.cancelled'],
            self::BillingCheckout => ['billing_checkout.created', 'billing_checkout.cancelled'],
        };
    }
}
