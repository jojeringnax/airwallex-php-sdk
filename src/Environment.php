<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    /** @internal */
    public function baseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://api.sandbox.airwallex.com',
            self::Production => 'https://api.airwallex.com',
        };
    }
}
