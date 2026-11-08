<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Payments;

use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Internal\Http\ApiTransport;
use Jojeringnax\Airwallex\Internal\Schema\Endpoint;
use SensitiveParameter;

/** @internal Shared implementation of selected account-owned Payments operations. */
abstract class Resource
{
    final public function __construct(private readonly ApiTransport $transport)
    {
    }

    abstract protected function contractName(): string;

    /**
     * @param array<string, string> $ids
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    final protected function call(
        string $operation,
        #[SensitiveParameter] array $ids = [],
        #[SensitiveParameter] ?array $body = null,
        #[SensitiveParameter] array $query = [],
    ): array {
        $endpoint = Endpoint::load('payments', $this->contractName(), $operation);
        $endpoint->validateRequest($body, $query);
        Rules::validateQuery($query);
        if ($body !== null) {
            Rules::validate($operation, $body, $this->contractName());
        }
        return $this->transport->request($endpoint->operation($ids), $body, $query);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['resource' => $this->contractName()];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new ConfigurationException('Configured resources cannot be serialized.');
    }
}
