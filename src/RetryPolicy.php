<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex;

use Closure;
use Jojeringnax\Airwallex\Exception\ConfigurationException;

/** Read retries only. Public configuration is provisional; see docs/client-configuration.md. */
final readonly class RetryPolicy
{
    private Closure $sleeper;
    private Closure $randomInt;

    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMilliseconds = 1000,
        public int $maxDelayMilliseconds = 16000,
        ?Closure $sleeper = null,
        ?Closure $randomInt = null,
    ) {
        if (
            $maxAttempts < 1 || $maxAttempts > 10 || $baseDelayMilliseconds < 0
            || $maxDelayMilliseconds < $baseDelayMilliseconds || $maxDelayMilliseconds > 60000
        ) {
            throw new ConfigurationException('Invalid retry bounds.');
        }
        $this->sleeper = $sleeper ?? static fn (int $milliseconds) => usleep($milliseconds * 1000);
        $this->randomInt = $randomInt ?? static fn (int $minimum, int $maximum): int => random_int($minimum, $maximum);
    }

    /** @internal */
    public function wait(int $failedAttempt): void
    {
        $ceiling = (int) min($this->maxDelayMilliseconds, $this->baseDelayMilliseconds * (2 ** ($failedAttempt - 1)));
        try {
            $delay = ($this->randomInt)(0, $ceiling);
        } catch (\Throwable) {
            throw new ConfigurationException('The configured retry randomness failed.');
        }
        if (!is_int($delay) || $delay < 0 || $delay > $ceiling) {
            throw new ConfigurationException('Retry randomness returned an invalid delay.');
        }
        try {
            ($this->sleeper)($delay);
        } catch (\Throwable) {
            throw new ConfigurationException('The configured retry sleeper failed.');
        }
    }
}
