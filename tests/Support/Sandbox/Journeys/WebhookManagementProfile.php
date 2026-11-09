<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

/** Fixed fresh-pair entry points select a management profile, never a provider-scope assertion. */
enum WebhookManagementProfile: string
{
    case Account = 'ACCOUNT';
    case DefaultLogin = 'DEFAULT';

    public function context(): string
    {
        return $this === self::Account ? 'account' : 'default-login';
    }

    public function scenario(): string
    {
        return $this === self::Account ? 'billing-webhook-pair' : 'billing-webhook-default-pair';
    }

    public function entry(): string
    {
        return $this === self::Account
            ? 'billing-webhook-delivery-probe.php'
            : 'billing-webhook-default-login-probe.php';
    }
}
