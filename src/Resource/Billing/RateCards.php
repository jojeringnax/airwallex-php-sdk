<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Billing;

use Jojeringnax\Airwallex\Internal\Schema\Validator;
use SensitiveParameter;

final class RateCards extends Resource
{
    protected function contractName(): string
    {
        return 'rate_cards';
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function create(#[SensitiveParameter] array $body): array
    {
        return $this->call('createRateCard', body: $body);
    }

    /** @return array<array-key,mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->call('retrieveRateCard', ids: ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>
     */
    public function list(#[SensitiveParameter] array $query = []): array
    {
        return $this->call('listRateCards', query: $query);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function update(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('updateRateCard', ids: ['id' => $id], body: $body);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<array-key,mixed>
     */
    public function createVersion(#[SensitiveParameter] string $id, #[SensitiveParameter] array $body): array
    {
        return $this->call('createRateCardVersion', ids: ['id' => $id], body: $body);
    }

    /** @return array<array-key,mixed> */
    public function retrieveVersion(#[SensitiveParameter] string $id, int $version): array
    {
        return $this->call('retrieveRateCardVersion', ids: ['id' => $id, 'version' => self::version($version)]);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>
     */
    public function listVersions(#[SensitiveParameter] string $id, #[SensitiveParameter] array $query = []): array
    {
        return $this->call('listRateCardVersions', ids: ['id' => $id], query: $query);
    }

    /** @return array<array-key,mixed> */
    public function retrieveVersionItem(
        #[SensitiveParameter] string $id,
        int $version,
        #[SensitiveParameter] string $itemId,
    ): array {
        return $this->call('retrieveRateCardVersionItem', ids: [
            'id' => $id, 'version' => self::version($version), 'item_id' => $itemId,
        ]);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>
     */
    public function listVersionItems(
        #[SensitiveParameter] string $id,
        int $version,
        #[SensitiveParameter] array $query = [],
    ): array {
        return $this->call(
            'listRateCardVersionItems',
            ids: ['id' => $id, 'version' => self::version($version)],
            query: $query,
        );
    }

    private static function version(int $version): string
    {
        Validator::validate($version, ['type' => 'integer', 'format' => 'int32'], []);
        return (string) $version;
    }
}
