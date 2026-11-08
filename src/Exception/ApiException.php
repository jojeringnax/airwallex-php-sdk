<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Exception;

final class ApiException extends \RuntimeException implements SdkException
{
    use RedactedException;

    public function __construct(
        private readonly int $status,
        private readonly ?string $providerErrorCode = null,
        private readonly ?string $providerErrorSource = null,
    ) {
        parent::__construct('Airwallex API request failed (HTTP ' . $status . ').');
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'http_status' => $this->status,
            'provider_code' => $this->providerErrorCode,
            'provider_source' => $this->providerErrorSource,
        ];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        if (
            !is_int($data['http_status'] ?? null)
            || $data['http_status'] < 100 || $data['http_status'] > 599
            || !(is_string($data['provider_code'] ?? null) || ($data['provider_code'] ?? null) === null)
            || !(is_string($data['provider_source'] ?? null) || ($data['provider_source'] ?? null) === null)
        ) {
            throw new ConfigurationException('Invalid serialized API exception diagnostics.');
        }
        $this->__construct($data['http_status'], $data['provider_code'] ?? null, $data['provider_source'] ?? null);
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public function providerCode(): ?string
    {
        return $this->providerErrorCode;
    }

    public function providerSource(): ?string
    {
        return $this->providerErrorSource;
    }
}
