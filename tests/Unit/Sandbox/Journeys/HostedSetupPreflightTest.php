<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedSetupPreflight;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class HostedSetupPreflightTest extends TestCase
{
    private string $directory;
    /** @var list<RequestInterface> */
    private array $requests = [];
    /** @var array<array-key,mixed> */
    private array $checkout = [];
    private int $cancels = 0;
    private int $browserCalls = 0;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-hosted-fixture-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/module', 0700);
        file_put_contents($this->directory . '/module/index.js', 'module.exports = {};');
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_CLIENT_ID=fixture_client\nAIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_api_key\n"
            . "AIRWALLEX_ACCOUNT_ID=fixture_account\n",
        );
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'state-dir' => $this->directory, 'node-bin' => PHP_BINARY,
            'playwright-module' => $this->directory . '/module',
            'create-one-hosted-setup' => true];
    }

    /**
     * @param array<string,mixed>|null $options
     * @return array<string,mixed>
     */
    private function execute(string $fault = '', ?array $options = null): array
    {
        $transport = new HttpClient(['handler' => function (RequestInterface $request) use ($fault): Response {
            $this->requests[] = $request;
            $path = $request->getUri()->getPath();
            if ($path === '/api/v1/authentication/login') {
                return $this->json(['token' => 'fixture_token', 'expires_at' => '2099-01-01T00:00:00Z']);
            }
            if ($path === '/api/v1/account') {
                return $this->json([
                    'id' => 'fixture_account', 'account_details' => ['legal_entity_id' => 'fixture_entity'],
                ]);
            }
            if (str_ends_with($path, '/billing_customers/create')) {
                return $this->json(['id' => 'fixture_customer']);
            }
            if (str_ends_with($path, '/billing_checkouts/create')) {
                if ($fault === 'create_rejected') {
                    return new Response(400, [], '{"code":"PRIVATE_CODE","source":"PRIVATE_SOURCE"}');
                }
                $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                unset($body['request_id']);
                $this->checkout = $body + ['id' => 'fixture_checkout', 'status' => 'ACTIVE',
                    'url' => 'https://checkout.airwallex.com/pay?s=PRIVATE_SYNTHETIC_SESSION'];
                return $this->json(['id' => 'fixture_checkout']);
            }
            if (str_ends_with($path, '/fixture_checkout/cancel')) {
                $this->cancels++;
                if ($fault === 'cancel_ambiguous') {
                    throw new RuntimeException('PRIVATE_BROWSER_ERROR');
                }
                $this->checkout['status'] = 'CANCELLED';
                unset($this->checkout['url']);
                return $this->json($this->checkout);
            }
            if (str_ends_with($path, '/fixture_checkout')) {
                $data = $this->checkout;
                if ($this->cancels === 0 && $this->browserCalls === 0) {
                    $field = str_starts_with($fault, 'mismatch_') ? substr($fault, 9) : null;
                    if ($field !== null) {
                        $data[$field] = 'PRIVATE_WRONG_VALUE';
                    }
                }
                if (
                    ($fault === 'cleanup_owner_mismatch' && $this->browserCalls > 0 && $this->cancels === 0)
                    || ($fault === 'cancel_owner_mismatch' && $this->cancels > 0)
                ) {
                    $data['billing_customer_id'] = 'PRIVATE_WRONG_VALUE';
                }
                return $this->json($data);
            }
            throw new RuntimeException('Unexpected synthetic request.');
        }]);
        return HostedSetupPreflight::run(
            $options ?? $this->options(),
            static fn (Configuration $config, string $context): SandboxSession
                => new SandboxSession($config, $context, $transport),
            function (string $url) use ($fault): array {
                $this->browserCalls++;
                self::assertSame('https://checkout.airwallex.com/pay?s=PRIVATE_SYNTHETIC_SESSION', $url);
                if ($fault === 'browser_throws') {
                    throw new RuntimeException('PRIVATE_BROWSER_ERROR');
                }
                return ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
                    'card_entry_enabled' => false, 'test_mode_visible' => true,
                    'private_url' => $url, 'private_message' => 'PRIVATE_BROWSER_ERROR'];
            },
        );
    }

    public function testOneFreshOwnedSetupPrivateHandoffAndOneCancellation(): void
    {
        $result = $this->execute();
        self::assertSame('blocked', $result['result']);
        self::assertSame(['customers' => 1, 'checkouts' => 1], $result['created_counts']);
        self::assertSame(['checkout_0' => 'pass'], $result['cleanup']);
        self::assertSame(1, $this->browserCalls);
        self::assertSame(1, $this->cancels);
        $creates = array_values(array_filter($this->requests, static fn (RequestInterface $request): bool
            => str_ends_with($request->getUri()->getPath(), '/billing_checkouts/create')));
        self::assertCount(1, $creates);
        $body = json_decode((string) $creates[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('SETUP', $body['mode']);
        self::assertSame('HOSTED', $body['ui_mode']);
        self::assertSame('EUR', $body['currency']);
        self::assertArrayNotHasKey('return_url', $body);
        self::assertArrayNotHasKey('line_items', $body);
        self::assertArrayHasKey('request_id', $body);
        foreach ($this->requests as $request) {
            self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
            if (str_ends_with($request->getUri()->getPath(), '/cancel')) {
                self::assertSame('', (string) $request->getBody());
            }
        }
        self::assertSame('fixture_account', $this->requests[0]->getHeaderLine('x-login-as'));
        self::assertSame('/api/v1/account', $this->requests[1]->getUri()->getPath());
        self::assertSame('', $this->requests[2]->getHeaderLine('x-login-as'));
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (
            ['fixture_client', 'fixture_api_key', 'fixture_account', 'fixture_entity', 'fixture_customer',
            'fixture_checkout', 'fixture_token', 'PRIVATE_SYNTHETIC_SESSION', 'PRIVATE_BROWSER_ERROR'] as $private
        ) {
            self::assertStringNotContainsString($private, $encoded);
        }
        self::assertIsArray($result['browser_tool_hashes']);
        self::assertCount(5, $result['browser_tool_hashes']);
    }

    /** @return iterable<string,array{string}> */
    public static function mismatches(): iterable
    {
        foreach (
            ['id', 'status', 'billing_customer_id', 'legal_entity_id', 'linked_payment_account_id',
            'mode', 'ui_mode', 'currency', 'metadata', 'url'] as $field
        ) {
            yield $field => ['mismatch_' . $field];
        }
    }

    #[DataProvider('mismatches')]
    public function testWrongIdentityOrContextStopsBeforeBrowser(string $fault): void
    {
        $result = $this->execute($fault);
        self::assertSame('fail', $result['result']);
        self::assertSame('checkout_read', $result['phase']);
        self::assertSame(0, $this->browserCalls);
        if ($fault !== 'mismatch_url') {
            self::assertSame(0, $this->cancels);
            self::assertSame(['checkout_0' => 'fail'], $result['cleanup']);
        }
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testBrowserFailureStillCancelsOnlyItsCreatedCheckout(): void
    {
        $result = $this->execute('browser_throws');
        self::assertSame('fail', $result['result']);
        self::assertSame('browser_read_only', $result['phase']);
        self::assertSame(['checkout_0' => 'pass'], $result['cleanup']);
        self::assertSame(1, $this->cancels);
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testAmbiguousCancellationIsNeverRepeated(): void
    {
        $result = $this->execute('cancel_ambiguous');
        self::assertSame('fail', $result['result']);
        self::assertSame(['checkout_0' => 'fail'], $result['cleanup']);
        self::assertSame(1, $this->cancels);
    }

    public function testFreshCleanupReadChecksOwnershipAgainBeforeCancel(): void
    {
        $result = $this->execute('cleanup_owner_mismatch');
        self::assertSame('fail', $result['result']);
        self::assertSame(['checkout_0' => 'fail'], $result['cleanup']);
        self::assertSame(1, $this->browserCalls);
        self::assertSame(0, $this->cancels);
    }

    public function testCancelledReadbackStillRequiresOwnedContext(): void
    {
        $result = $this->execute('cancel_owner_mismatch');
        self::assertSame('fail', $result['result']);
        self::assertSame(['checkout_0' => 'fail'], $result['cleanup']);
        self::assertSame(1, $this->cancels);
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testRejectedCreateHasNoBrowserOrInventedCleanupIdentity(): void
    {
        $result = $this->execute('create_rejected');
        self::assertSame(400, $result['http_status']);
        self::assertSame('checkout_create', $result['phase']);
        self::assertSame(['customers' => 1], $result['created_counts']);
        self::assertSame([], $result['cleanup']);
        self::assertSame(0, $this->browserCalls);
        self::assertSame(0, $this->cancels);
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidOptions(): iterable
    {
        yield 'production' => ['environment', 'production'];
        yield 'creation not selected' => ['create-one-hosted-setup', false];
        yield 'URL injection' => ['url', 'https://private.example.test/PRIVATE_SESSION'];
        yield 'context override' => ['context', 'account'];
        yield 'old ledger injection' => ['ledger', '/private/fixture-ledger'];
        yield 'SDK root injection' => ['sdk-root', '/private/fixture-root'];
        yield 'node absent' => ['node-bin', '/nonexistent-fixture-node'];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidConfigurationMakesNoRequests(string $key, mixed $value): void
    {
        $options = $this->options();
        $options[$key] = $value;
        $result = $this->execute(options: $options);
        self::assertSame('fail', $result['result']);
        self::assertSame('configuration', $result['phase']);
        self::assertSame([], $this->requests);
        self::assertSame(0, $this->browserCalls);
    }

    /** @param array<array-key,mixed> $data */
    private function json(array $data): Response
    {
        return new Response(200, [], json_encode($data, JSON_THROW_ON_ERROR));
    }
}
