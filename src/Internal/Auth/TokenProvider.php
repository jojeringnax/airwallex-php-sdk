<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Internal\Auth;

use Closure;
use DateTimeImmutable;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Internal\Http\HttpExchange;
use Jojeringnax\Airwallex\Internal\Http\ResponseDecoder;
use Psr\SimpleCache\CacheInterface;
use SensitiveParameter;

/** @internal Synchronous token reuse; PSR-16 does not provide a distributed lock. */
final class TokenProvider
{
    /** @var array{token: string, expires_at: string}|null */
    private ?array $memory = null;
    private readonly string $cacheKey;

    public function __construct(
        private readonly HttpExchange $exchange,
        private readonly ResponseDecoder $decoder,
        #[SensitiveParameter] private readonly Credentials $credentials,
        Environment $environment,
        #[SensitiveParameter] private readonly ?string $loginAccountId,
        #[SensitiveParameter] private readonly ?string $delegatedAccountId,
        private readonly ?CacheInterface $cache,
        private readonly Closure $clock,
    ) {
        $this->cacheKey = hash('sha256', implode('|', [
            'airwallex-sdk-token-v1', $environment->value, $credentials->fingerprint(),
            $loginAccountId === null ? '-' : strlen($loginAccountId) . ':' . $loginAccountId,
            $delegatedAccountId === null ? '-' : strlen($delegatedAccountId) . ':' . $delegatedAccountId,
        ]));
    }

    public function token(): string
    {
        $now = $this->now();
        if ($this->valid($this->memory, $now)) {
            return $this->memory['token'];
        }
        if ($this->cache !== null) {
            try {
                $cached = $this->cache->get($this->cacheKey);
            } catch (\Throwable) {
                throw new ConfigurationException('The configured token cache failed.');
            }
            if ($this->valid($cached, $now)) {
                $this->memory = $cached;
                return $cached['token'];
            }
        }
        $headers = $this->credentials->headers() + ['x-api-version' => '2026-08-21', 'Accept' => 'application/json'];
        if ($this->loginAccountId !== null) {
            $headers['x-login-as'] = $this->loginAccountId;
        }
        $data = $this->decoder->decode(
            $this->exchange->send('POST', '/api/v1/authentication/login', $headers),
            sensitiveValues: array_values($headers),
        );
        if (!$this->valid($data, $this->now())) {
            throw new ProtocolException('Airwallex login returned an invalid or expired token.');
        }
        $record = ['token' => $data['token'], 'expires_at' => $data['expires_at']];
        if ($this->cache !== null) {
            try {
                $stored = $this->cache->set(
                    $this->cacheKey,
                    $record,
                    max(1, $this->expiry($data['expires_at']) - $this->now()),
                );
            } catch (\Throwable) {
                throw new ConfigurationException('The configured token cache failed.');
            }
            if (!$stored) {
                throw new ConfigurationException('The configured token cache did not store the token.');
            }
        }
        $this->memory = $record;
        return $data['token'];
    }

    public function invalidate(): void
    {
        $this->memory = null;
        if ($this->cache !== null) {
            try {
                $deleted = $this->cache->delete($this->cacheKey);
            } catch (\Throwable) {
                throw new ConfigurationException('The configured token cache failed.');
            }
            if (!$deleted) {
                throw new ConfigurationException('The configured token cache did not invalidate the token.');
            }
        }
    }

    /** @return list<string> */
    public function sensitiveValues(): array
    {
        return [...array_values($this->credentials->headers()),
             ...($this->memory === null ? [] : [$this->memory['token']]),
             ...array_values(array_filter(
                 [$this->loginAccountId,
                 $this->delegatedAccountId],
                 static fn (?string $id): bool => $id !== null
             ))];
    }

    private function now(): int
    {
        try {
            $now = ($this->clock)();
        } catch (\Throwable) {
            throw new ConfigurationException('The configured clock failed.');
        }
        if (!is_int($now) || $now < 0) {
            throw new ConfigurationException('The clock must return Unix seconds as a non-negative integer.');
        }
        return $now;
    }

    /** @phpstan-assert-if-true array{token: string, expires_at: string} $data */
    private function valid(#[SensitiveParameter] mixed $data, int $now): bool
    {
        if (!is_array($data) || !is_string($data['token'] ?? null) || !is_string($data['expires_at'] ?? null)) {
            return false;
        }
        return $data['token'] !== '' && preg_match('/[\x00-\x20\x7f]/', $data['token']) !== 1
            && $this->expiry($data['expires_at']) > $now;
    }

    private function expiry(string $timestamp): int
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?'
            . '(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)$/D';
        if (preg_match($pattern, $timestamp) !== 1) {
            return 0;
        }
        try {
            $date = new DateTimeImmutable($timestamp);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return 0;
            }
            return $date->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['token' => '[redacted]'];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new ConfigurationException('Authentication state cannot be serialized.');
    }
}
