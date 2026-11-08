<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use Jojeringnax\Airwallex\Webhook\WebhookVerifier;

// The receiver selects its configured endpoint secret/environment. Preserve the raw bytes before parsing.
return static function (
    #[SensitiveParameter] string $secret,
    #[SensitiveParameter] string $rawBody,
    #[SensitiveParameter] string $timestamp,
    #[SensitiveParameter] string $signature,
    int $toleranceMilliseconds,
    Closure $clock,
): array {
    $verifier = new WebhookVerifier($secret, $toleranceMilliseconds, $clock);
    $verifier->verify($rawBody, $timestamp, $signature);
    // Caller retains rawBody if needed. This array grants no owner authorization or typed state transition.
    return WebhookEvent::fromJson($rawBody)->toArray();
};
