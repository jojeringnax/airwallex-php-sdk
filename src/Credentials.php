<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex;

use Jojeringnax\Airwallex\Exception\ConfigurationException;
use SensitiveParameter;

final readonly class Credentials
{
    public function __construct(
        #[SensitiveParameter] private string $clientId,
        #[SensitiveParameter] private string $apiKey,
    ) {
        foreach ([$clientId, $apiKey] as $value) {
            if ($value === '' || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
                throw new ConfigurationException('Credentials must be nonempty header values without whitespace.');
            }
        }
    }

    /**
     * @internal
     * @return array<string, string>
     */
    public function headers(): array
    {
        return ['x-client-id' => $this->clientId, 'x-api-key' => $this->apiKey];
    }

    /** @internal */
    public function fingerprint(): string
    {
        return hash('sha256', strlen($this->clientId) . ':' . $this->clientId . ':' . $this->apiKey);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['clientId' => '[redacted]', 'apiKey' => '[redacted]'];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new ConfigurationException('Credentials cannot be serialized.');
    }
}
