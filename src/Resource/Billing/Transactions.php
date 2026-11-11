<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Resource\Billing;

use Jojeringnax\Airwallex\Internal\Schema\Validator;
use SensitiveParameter;

final class Transactions extends Resource
{
    protected function contractName(): string
    {
        return 'billing_transactions';
    }

    /** @return array<array-key,mixed> */
    public function retrieve(#[SensitiveParameter] string $id): array
    {
        return $this->call('retrieveBillingTransaction', ids: ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>
     */
    public function list(#[SensitiveParameter] array $query): array
    {
        // The selected contract requires exactly one parent, even though neither parameter is individually required.
        if (array_key_exists('invoice_id', $query) === array_key_exists('credit_note_id', $query)) {
            Validator::fail();
        }
        $parent = $query['invoice_id'] ?? $query['credit_note_id'] ?? null;
        if (!is_string($parent) || $parent === '' || preg_match('/[\x00-\x1f\x7f]/', $parent) === 1) {
            Validator::fail();
        }
        return $this->call('listBillingTransactions', query: $query);
    }
}
