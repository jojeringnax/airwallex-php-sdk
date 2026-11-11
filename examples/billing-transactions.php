<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// First authorize the buyer and resolve the invoice/transaction IDs in the owning Billing organization.
// These reads expose observations; the application verifies linkage and owns accounting allocations.
return static function (Client $billing, string $invoiceId, string $transactionId, ?string $page = null): array {
    $query = ['invoice_id' => $invoiceId, 'page_size' => 20];
    if ($page !== null) {
        $query['page'] = $page;
    }
    return [
        'transaction' => $billing->billing()->transactions()->retrieve(id: $transactionId),
        'page' => $billing->billing()->transactions()->list(query: $query),
    ];
};
