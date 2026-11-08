<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

/** Fixed development entry points select these profiles; callers cannot supply a context selector. */
enum WebhookConfigProfile: string
{
    case Account = 'ACCOUNT';
    case DefaultLogin = 'DEFAULT';

    public function scenario(): string
    {
        return $this === self::Account ? 'webhook-config-observation' : 'webhook-default-login-read';
    }

    public function context(): string
    {
        return $this === self::Account ? 'account' : 'default-login';
    }

    public function tool(): string
    {
        return $this === self::Account ? 'webhook-config-observation.php' : 'webhook-default-login-read.php';
    }
}
