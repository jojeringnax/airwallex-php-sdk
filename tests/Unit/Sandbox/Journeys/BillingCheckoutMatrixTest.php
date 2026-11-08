<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingCheckoutMatrix;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingCheckoutMatrixTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-checkout-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/credentials', "AIRWALLEX_CLIENT_ID=fixture\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_key\nAIRWALLEX_ACCOUNT_ID=fixture_account\n");
    }

    protected function tearDown(): void
    {
        self::remove($this->directory);
    }

    private static function remove(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path)) {
                self::remove($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    /** @return array{array<string,mixed>,CheckoutServerFixture} */
    private function runScenario(string $fault = ''): array
    {
        $fixture = new CheckoutServerFixture($fault);
        $handler = new MockHandler(array_fill(0, 100, $fixture->respond(...)));
        $session = new SandboxSession(
            Configuration::load('sandbox', $this->directory . '/credentials'),
            'organization',
            new Client(['handler' => HandlerStack::create($handler)]),
            static fn (): int => 100,
        );
        $ledger = RunLedger::create($session->contextFingerprint(), $this->directory);
        return [BillingCheckoutMatrix::execute($session, $ledger, 'fixture_entity', 'fixture_account'), $fixture];
    }

    public function testAllNineModesReadUpdateAndCancelWithNoProviderIdentityInvented(): void
    {
        [$result, $fixture] = $this->runScenario();
        self::assertSame('pass', $result['result']);
        self::assertSame(array_fill_keys([
            'setup_hosted', 'setup_embedded', 'setup_elements', 'payment_hosted', 'payment_embedded',
            'payment_elements', 'subscription_hosted', 'subscription_embedded', 'subscription_elements',
        ], 'pass'), $result['steps']);
        self::assertSame(
            ['customers' => 1, 'products' => 1, 'prices' => 2, 'checkouts' => 9],
            $result['created_counts'],
        );
        self::assertIsArray($result['cleanup']);
        self::assertNotContains('fail', $result['cleanup']);
        self::assertCount(9, $fixture->cancels);
        self::assertSame(['fixture_price_1', 'fixture_price_2', 'fixture_product'], $fixture->archives);
        foreach ($fixture->requests as $request) {
            $path = $request->getUri()->getPath();
            if (str_contains($path, '/billing_checkouts/') && str_ends_with($path, '/cancel')) {
                self::assertSame('', (string) $request->getBody());
            } elseif (str_contains($path, '/billing_checkouts/') && str_ends_with($path, '/update')) {
                self::assertStringNotContainsString('request_id', (string) $request->getBody());
            }
        }
        self::assertStringNotContainsString('fixture_secret', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('fixture_checkout', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string}> */
    public static function semanticFaults(): iterable
    {
        foreach (
            ['currency', 'secret', 'expiry', 'relative_time', 'invalid_date',
            'quantity', 'metadata', 'cancelled_url'] as $fault
        ) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('semanticFaults')]
    public function testWrongReadbackCannotPass(string $fault): void
    {
        [$result] = $this->runScenario($fault);
        self::assertSame('fail', $result['result']);
        self::assertIsArray($result['steps']);
        self::assertNotSame(array_fill(0, 9, 'pass'), array_values($result['steps']));
    }

    public function testAnAmbiguousCancelIsNotRetriedByCleanup(): void
    {
        [$result, $fixture] = $this->runScenario('ambiguous_cancel');
        self::assertSame('fail', $result['result']);
        self::assertSame(1, $fixture->cancels['fixture_checkout_1']);
        self::assertIsArray($result['cleanup']);
        self::assertSame('fail', $result['cleanup']['checkout_0']);
    }

    public function testAbsoluteBasicTimezoneOffsetsAreAccepted(): void
    {
        [$result] = $this->runScenario('basic_offset');
        self::assertSame('pass', $result['result']);
    }

    public function testSetupRejectionDoesNotSkipIndependentPaymentAndSubscriptionModes(): void
    {
        [$result] = $this->runScenario('setup_rejected');
        self::assertSame('fail', $result['result']);
        self::assertIsArray($result['steps']);
        self::assertSame('pass', $result['steps']['payment_hosted']);
        self::assertSame('pass', $result['steps']['subscription_elements']);
    }
}
