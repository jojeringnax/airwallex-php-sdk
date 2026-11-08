<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

/** Closed development-only profiles, never a runtime SDK setting. */
enum HostedSecurityMode: string
{
    case Preflight = 'preflight';
    case InstrumentSecurity = 'instrument_security';

    public function entry(): string
    {
        return $this === self::Preflight ? 'hosted-browser.cjs' : 'hosted-instrument-security.cjs';
    }
}
