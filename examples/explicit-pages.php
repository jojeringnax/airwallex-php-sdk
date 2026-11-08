<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Client;

// Application examples: each invocation fetches exactly one explicitly selected page.
// Retain the non-page filters and the same authorized client. Inspect terminal indicators before another call.
return [
    'billing_customers' => static function (Client $billing, array $filters, ?string $bookmark = null): array {
        $query = [];
        foreach ($filters as $name => $value) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('List filters must use schema field names.');
            }
            $query[$name] = $value;
        }
        unset($query['page']);
        if ($bookmark !== null) {
            $query['page'] = $bookmark;
        }
        return $billing->billing()->customers()->list($query);
    },
    'payment_intents' => static function (Client $payments, array $filters, int $pageNumber = 0): array {
        $query = [];
        foreach ($filters as $name => $value) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('List filters must use schema field names.');
            }
            $query[$name] = $value;
        }
        $query['page_num'] = $pageNumber;
        return $payments->payments()->paymentIntents()->list($query);
    },
];
