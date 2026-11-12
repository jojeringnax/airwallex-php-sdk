<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Application authorizes the original finalized unpaid invoice and selected adjustment first.
// Persist each request ID; reconcile any uncertain earlier action before proceeding.
return static function (
    Client $client,
    string $invoiceId,
    string $amount,
    string $createRequestId,
    string $itemsRequestId,
): array {
    $notes = $client->billing()->creditNotes();
    $draft = $notes->create([
        'request_id' => $createRequestId, 'invoice_id' => $invoiceId,
        'type' => 'BEFORE_PAYMENT', 'reason' => 'GOODWILL_GESTURE',
    ]);
    if (!is_string($draft['id'] ?? null) || $draft['id'] === '') {
        throw new RuntimeException('Credit Note creation requires reconciliation before another action.');
    }
    $notes->addLineItems($draft['id'], [
        'request_id' => $itemsRequestId, 'line_items' => [['amount' => $amount]],
    ]);
    // A separate explicit financial decision; BEFORE_PAYMENT creates an invoice adjustment, not a refund.
    $notes->finalize($draft['id'], []);

    return [
        'credit_note' => $notes->retrieve($draft['id']),
        'transactions' => $client->billing()->transactions()->list(['credit_note_id' => $draft['id']]),
    ];
};
