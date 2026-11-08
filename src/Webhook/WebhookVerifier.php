<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Webhook;

use Closure;
use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Exception\WebhookVerificationException;
use SensitiveParameter;
use Throwable;

final readonly class WebhookVerifier
{
    /** @param Closure(): mixed $clock Must return current Unix milliseconds as an integer. */
    public function __construct(
        #[SensitiveParameter] private string $secret,
        private int $toleranceMilliseconds,
        #[SensitiveParameter] private Closure $clock,
    ) {
        if ($secret === '' || $toleranceMilliseconds < 0) {
            throw new ConfigurationException('Webhook secret and non-negative tolerance are required.');
        }
    }

    public function verify(
        #[SensitiveParameter] string $rawBody,
        #[SensitiveParameter] string $timestamp,
        #[SensitiveParameter] string $signature,
    ): void {
        if (preg_match('/^[0-9]+$/D', $timestamp) !== 1 || preg_match('/^[a-fA-F0-9]{64}$/D', $signature) !== 1) {
            throw new WebhookVerificationException('Webhook headers are malformed.');
        }
        // Keep the exact timestamp bytes in the HMAC, including any leading zeroes.
        $expected = hash_hmac('sha256', $timestamp . $rawBody, $this->secret);
        if (!hash_equals($expected, strtolower($signature))) {
            throw new WebhookVerificationException('Webhook signature is invalid.');
        }
        $normalized = ltrim($timestamp, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string) PHP_INT_MAX;
        if (
            strlen($normalized) > strlen($maximum) || (strlen($normalized) === strlen($maximum)
            && strcmp($normalized, $maximum) > 0)
        ) {
            throw new WebhookVerificationException('Webhook timestamp is outside the supported range.');
        }
        try {
            $now = ($this->clock)();
        } catch (Throwable) {
            throw new ConfigurationException('Webhook clock failed.');
        }
        if (!is_int($now) || $now < 0) {
            throw new ConfigurationException('Webhook clock must return non-negative Unix milliseconds.');
        }
        $received = (int) $normalized;
        // Both integers are non-negative, so ordered subtraction cannot overflow.
        $difference = $now >= $received ? $now - $received : $received - $now;
        if ($difference > $this->toleranceMilliseconds) {
            throw new WebhookVerificationException('Webhook timestamp is outside the configured tolerance.');
        }
    }

    /** @return array{toleranceMilliseconds: int} */
    public function __debugInfo(): array
    {
        return ['toleranceMilliseconds' => $this->toleranceMilliseconds];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new ConfigurationException('Webhook verifiers cannot be serialized.');
    }
}
