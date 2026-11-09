<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Resource\Payments;

use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use RuntimeException;

final class ExamplesTest extends HttpTestCase
{
    public function testSavedConsentConsumerRecipeRunsAgainstActualResourceMethods(): void
    {
        $recipe = require dirname(__DIR__, 4) . '/examples/payments-saved-consent.php';
        self::assertInstanceOf(\Closure::class, $recipe);
        $http = $this->scenario([
            [201, '{"token":"synthetic-token","expires_at":"2030-01-01T00:00:00Z"}'],
            [200, '{"id":"consent-A","customer_id":"customer-A","status":"VERIFIED"}'],
            [201, '{"id":"intent-A","amount":19.90,"currency":"EUR"}'],
            [200, '{"id":"intent-A","amount":19.90,"status":"PENDING"}'],
        ]);
        $result = $recipe($this->client($http)->forConnectedAccount('acct_A'), 'consent-A', [
            'create' => 'persisted-create', 'confirm' => 'persisted-confirm',
        ]);
        self::assertIsArray($result);
        self::assertSame('PENDING', $result['status']);
        self::assertSame('19.90', $result['amount']);
        self::assertStringContainsString('"request_id":"persisted-create"', (string) $http->requests[2]->getBody());
        self::assertSame([
            'request_id' => 'persisted-confirm',
            'payment_consent_id' => 'consent-A',
        ], json_decode((string) $http->requests[3]->getBody(), true, flags: JSON_THROW_ON_ERROR));
        self::assertCount(4, $http->requests);
    }

    public function testSavedConsentRecipeRefusesPendingOrUnknownInstrumentState(): void
    {
        $recipe = require dirname(__DIR__, 4) . '/examples/payments-saved-consent.php';
        self::assertInstanceOf(\Closure::class, $recipe);
        $http = $this->scenario([
            [201, '{"token":"synthetic-token","expires_at":"2030-01-01T00:00:00Z"}'],
            [200, '{"id":"consent-A","customer_id":"customer-A","status":"PENDING"}'],
        ]);
        $this->expectException(RuntimeException::class);
        try {
            $recipe(
                $this->client($http),
                'consent-A',
                ['create' => 'persisted-create',
                'confirm' => 'persisted-confirm']
            );
        } finally {
            self::assertCount(2, $http->requests);
        }
    }
}
