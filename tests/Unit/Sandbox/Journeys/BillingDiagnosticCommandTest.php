<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingDiagnosticCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class BillingDiagnosticCommandTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'missing arguments' => [[]];
        yield 'production' => [['environment' => 'production', 'credentials-file' => '/unread-private-fixture']];
        yield 'implicit environment' => [['credentials-file' => '/unread-private-fixture']];
        yield 'missing credential source' => [['environment' => 'sandbox']];
        yield 'relative credential source' => [['environment' => 'sandbox', 'credentials-file' => 'private-fixture']];
        yield 'URL credential source' => [[
            'environment' => 'sandbox', 'credentials-file' => 'https://example.test/private-fixture',
        ]];
        yield 'nonexistent credential source' => [[
            'environment' => 'sandbox', 'credentials-file' => '/nonexistent-private-fixture',
        ]];
        yield 'wrong argument types' => [['environment' => 'sandbox', 'credentials-file' => [], 'state-dir' => []]];
        yield 'unsupported scenario' => [[
            'environment' => 'sandbox', 'credentials-file' => '/unread-private-fixture', 'scenario' => 'other',
        ]];
    }

    /** @param array<string,mixed> $options */
    #[DataProvider('invalidConfiguration')]
    public function testInvalidConfigurationDoesNotAuthenticateOrEmitPrivateConfiguration(array $options): void
    {
        $inner = $this->createStub(ClientInterface::class);
        $inner->method('sendRequest')->willThrowException(new RuntimeException('Unexpected fixture request.'));
        $http = new RecordingClient($inner);
        $result = BillingDiagnosticCommand::run($options, $http);
        self::assertSame('fail', $result['result']);
        self::assertSame('2026-08-21', $result['api_version']);
        self::assertSame([], $http->requests);
        foreach (['private-fixture', 'example.test'] as $private) {
            self::assertStringNotContainsString($private, json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    /** @return iterable<string,array{string,?string,string,bool}> */
    public static function providerCodes(): iterable
    {
        yield 'public code' => ['validation_error', 'validation_error', 'billing-lifecycle', false];
        yield 'opaque lowercase code' => ['private_provider_identifier', null, 'billing-lifecycle', false];
        yield 'separate no-trial scenario' => [
            'validation_error', 'validation_error', 'billing-lifecycle-no-trial', false,
        ];
        yield 'opaque later no-trial read failure' => [
            'private_provider_identifier', null, 'billing-lifecycle-no-trial', true,
        ];
    }

    #[DataProvider('providerCodes')]
    public function testFreshCommandUsesGuardedOrganizationLifecycleAndOnlyWhitelistedErrorEvidence(
        string $code,
        ?string $expectedCode,
        string $scenario,
        bool $failAfterCreate,
    ): void {
        $directory = sys_get_temp_dir() . '/airwallex-billing-diagnostic-fixture-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $credentials = $directory . '/synthetic-credentials';
        file_put_contents(
            $credentials,
            "AIRWALLEX_CLIENT_ID=fixture_client\nAIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_key\n"
            . "AIRWALLEX_ACCOUNT_ID=fixture_account\n",
        );
        /** @var array<string,array<array-key,mixed>> $objects */
        $objects = [];
        $inner = $this->createStub(ClientInterface::class);
        $inner->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use (&$objects, $code, $failAfterCreate): ResponseInterface {
                $path = $request->getUri()->getPath();
                if ($path === '/api/v1/authentication/login') {
                    return new Response(201, [], '{"token":"fixture_token","expires_at":"2100-01-01T00:00:00Z"}');
                }
                if ($path === '/api/v1/account') {
                    return new Response(200, [], '{"id":"fixture_account",'
                        . '"account_details":{"legal_entity_id":"fixture_entity"}}');
                }
                if ($path === '/api/v1/billing/subscriptions/create') {
                    if ($failAfterCreate) {
                        return new Response(201, [], '{"id":"fixture-returned-subscription"}');
                    }
                    return new Response(400, [], json_encode([
                        'code' => $code, 'source' => 'duration.period',
                        'message' => 'duration contains SENTINEL_PRIVATE_DATA',
                    ], JSON_THROW_ON_ERROR));
                }
                if ($path === '/api/v1/billing/subscriptions/fixture-returned-subscription') {
                    return new Response(400, [], json_encode([
                        'code' => $code, 'source' => 'customer_id', 'message' => 'SENTINEL_PRIVATE_DATA',
                    ], JSON_THROW_ON_ERROR));
                }
                if ($request->getMethod() === 'POST' && str_ends_with($path, '/create')) {
                    $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    if (!is_array($body)) {
                        throw new RuntimeException('Invalid synthetic request.');
                    }
                    unset($body['request_id']);
                    $id = 'fixture-created-' . count($objects);
                    $objects[substr($path, 0, -7) . '/' . $id] = $body + ['id' => $id];
                    return new Response(201, [], json_encode(['id' => $id], JSON_THROW_ON_ERROR));
                }
                if ($request->getMethod() === 'GET' && isset($objects[$path])) {
                    return new Response(200, [], json_encode($objects[$path], JSON_THROW_ON_ERROR));
                }
                throw new RuntimeException('Unexpected synthetic request.');
            },
        );
        $http = new RecordingClient($inner);
        try {
            $result = BillingDiagnosticCommand::run([
                'environment' => 'sandbox', 'credentials-file' => $credentials, 'state-dir' => $directory,
                'scenario' => $scenario,
            ], $http);
            self::assertSame('fail', $result['result']);
            self::assertSame($scenario, $result['scenario']);
            self::assertSame($scenario === 'billing-lifecycle', $result['trial_requested']);
            self::assertSame('organization', $result['context']);
            self::assertSame(!$failAfterCreate, $result['ambiguous_mutation']);
            self::assertIsArray($result['steps']);
            self::assertIsArray($result['diagnostics']);
            self::assertSame('pass', $result['steps']['fresh_catalogue']);
            if ($failAfterCreate) {
                self::assertSame([], $result['diagnostics']);
                self::assertSame(400, $result['http_status']);
            } else {
                self::assertSame($expectedCode, $result['diagnostics']['provider_code']);
                self::assertSame(['duration', 'period'], $result['diagnostics']['mentioned_fields']);
                self::assertSame('fresh_subscription_create', $result['diagnostics']['phase']);
            }
            self::assertArrayNotHasKey('provider_code', $result);
            self::assertArrayNotHasKey('provider_source', $result);
            self::assertCount($failAfterCreate ? 14 : 12, $http->requests);
            foreach ($http->requests as $request) {
                self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
                self::assertFalse($request->hasHeader('x-on-behalf-of'));
            }
            // The Account configuration token is separate from the organization Billing token.
            self::assertSame('fixture_account', $http->requests[0]->getHeaderLine('x-login-as'));
            self::assertSame('', $http->requests[2]->getHeaderLine('x-login-as'));
            $create = json_decode((string) $http->requests[11]->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($create);
            self::assertSame($scenario === 'billing-lifecycle', array_key_exists('trial_ends_at', $create));
            $json = json_encode($result, JSON_THROW_ON_ERROR);
            foreach (
                ['SENTINEL_PRIVATE_DATA', 'fixture_', 'fixture-returned', 'private_provider_identifier'] as $private
            ) {
                self::assertStringNotContainsString($private, $json);
            }
        } finally {
            unlink($credentials);
            foreach (glob($directory . '/*') ?: [] as $folder) {
                foreach (glob($folder . '/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($folder);
            }
            rmdir($directory);
        }
    }
}
