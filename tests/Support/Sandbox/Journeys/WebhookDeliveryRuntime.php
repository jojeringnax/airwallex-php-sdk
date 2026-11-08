<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use SensitiveParameter;

/** Test-only process seam; ordinary PHP tests never start a public tunnel. */
interface WebhookDeliveryRuntime
{
    public function destination(): string;

    public function arm(#[SensitiveParameter] string $registrationFingerprint): void;

    /** @return array<string,int|bool> */
    public function poll(): array;

    /** @return array{receiver_closed:bool,tunnel_stopped:bool,logs_closed:bool,failed:bool} */
    public function stop(): array;
}
