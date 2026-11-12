<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\Internal\Schema\Endpoint;
use Jojeringnax\Airwallex\Internal\Schema\Validator;
use PHPUnit\Framework\TestCase;
use stdClass;

final class BillingSchemaTest extends TestCase
{
    public function testBundledOperationsHaveIndependentOrganizationPermissionEvidence(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/resources/contracts/billing/*.json');
        self::assertNotFalse($files);
        self::assertCount(11, $files);
        $count = 0;
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertSame('2026-08-21', $decoded['version']);
            self::assertSame(
                in_array(
                    basename($file),
                    ['billing_transactions.json', 'coupons.json', 'meters.json', 'usage_events.json'],
                    true
                )
                    ? '6caa23f3759634e64859ff6aa8c37336' : 'f3dcbacb0453ce7a4808b6e97305dac6',
                $decoded['provider_md5']
            );
            self::assertIsString($decoded['source']);
            self::assertStringStartsWith('https://www.airwallex.com/docs/api/billing/', $decoded['source']);
            self::assertIsArray($decoded['endpoints']);
            foreach ($decoded['endpoints'] as $endpoint) {
                self::assertIsArray($endpoint);
                self::assertSame('org', $endpoint['x-resourceLevel']);
                self::assertFalse($endpoint['x-oboSupported']);
                self::assertIsArray($endpoint['x-scope']);
                self::assertCount(1, $endpoint['x-scope']);
                self::assertIsArray($endpoint['x-scope'][0]);
                self::assertIsString($endpoint['x-scope'][0]['scope']);
                self::assertStringStartsWith('billing.', $endpoint['x-scope'][0]['scope']);
                $count++;
            }
        }
        self::assertSame(51, $count);
    }

    public function testFieldPathsFollowResolvedSchemaTypesRatherThanPropertyNames(): void
    {
        $item = Endpoint::load('billing', 'subscriptions', 'retrieveItem')->operation([
            'id' => 'subscription', 'item_id' => 'item',
        ]);
        self::assertContains('/quantity', $item->responseDecimalPaths);
        self::assertNotContains('/quantity', $item->responseIntegerPaths);
        $prices = Endpoint::load('billing', 'prices', 'listPrices')->operation();
        self::assertContains('/items/*/unit_amount', $prices->responseDecimalPaths);
        self::assertContains('/items/*/recurring/period', $prices->responseIntegerPaths);
        self::assertSame('billing', $prices->pagination);
    }

    public function testStringValuesAndEmptyObjectRepresentationAreSchemaSpecific(): void
    {
        $endpoint = Endpoint::load('billing', 'billing_customers', 'createBillingCustomer');
        $endpoint->validateRequest(['request_id' => 'stable', 'metadata' => new stdClass()]);
        $endpoint->validateRequest(['request_id' => 'stable', 'metadata' => ['numeric' => '0019.90']]);
        $this->expectException(ValidationException::class);
        $endpoint->validateRequest(['request_id' => 'stable', 'metadata' => []]);
    }

    public function testPatternsUnicodeLengthAndExactDecimalBoundsAreEnforced(): void
    {
        Validator::validate('éé', ['type' => 'string', 'maxLength' => 2], []);
        Validator::validate('9999999999.0000000000000000000000000', [
            'type' => 'number', 'maximum' => 9999999999, 'minimum' => 0,
        ], []);
        $this->expectException(ValidationException::class);
        Validator::validate('9999999999.0000000000000000000000001', [
            'type' => 'number', 'maximum' => 9999999999, 'minimum' => 0,
        ], []);
    }

    public function testUnknownRequestKeysDoNotBecomeDiagnosticMessages(): void
    {
        try {
            Endpoint::load('billing', 'billing_customers', 'createBillingCustomer')->validateRequest([
                'request_id' => 'stable', 'fixture-sensitive-field-name' => 'fixture-sensitive-value',
            ]);
            self::fail('Expected local validation failure.');
        } catch (ValidationException $error) {
            self::assertStringNotContainsString('fixture-sensitive', $error->getMessage());
        }
    }
}
