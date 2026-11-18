<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource;

use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Internal\Http\ApiTransport;
use Jojeringnax\Airwallex\Internal\Schema\Endpoint;
use SensitiveParameter;

/** @internal Transport and validation for account-scoped JSON reads. */
abstract class AccountReadResource
{
    protected const string FAMILY = '';
    protected const string CONTRACT = '';

    final public function __construct(private readonly ApiTransport $transport)
    {
    }

    /**
     * @param array<string, string> $ids
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    final protected function read(
        string $operation,
        #[SensitiveParameter] array $ids = [],
        #[SensitiveParameter] array $query = [],
    ): array {
        $endpoint = Endpoint::load(static::FAMILY, static::CONTRACT, $operation);
        $endpoint->validateRequest(null, $query);
        return $this->transport->request($endpoint->operation($ids), query: $query);
    }

    /** @return array<string, string> */
    final public function __debugInfo(): array
    {
        return ['resource' => static::CONTRACT];
    }

    /** @return array<string, never> */
    final public function __serialize(): array
    {
        throw new ConfigurationException('Configured resources cannot be serialized.');
    }
}
