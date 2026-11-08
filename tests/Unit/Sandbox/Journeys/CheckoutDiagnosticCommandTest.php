<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\CheckoutDiagnosticCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class CheckoutDiagnosticCommandTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'missing' => [[]];
        yield 'production' => [['environment' => 'production', 'credentials-file' => '/unread-PRIVATE-fixture']];
        yield 'implicit environment' => [['credentials-file' => '/unread-PRIVATE-fixture']];
        yield 'missing source' => [['environment' => 'sandbox']];
        yield 'relative source' => [['environment' => 'sandbox', 'credentials-file' => 'PRIVATE-fixture']];
        yield 'URL source' => [['environment' => 'sandbox', 'credentials-file' => 'https://PRIVATE.example/source']];
        yield 'absent source' => [['environment' => 'sandbox', 'credentials-file' => '/absent-PRIVATE-fixture']];
        yield 'wrong types' => [['environment' => 'sandbox', 'credentials-file' => [], 'state-dir' => []]];
        foreach (['sdk-root', 'ledger', 'context', 'scenario', 'base-url'] as $name) {
            yield $name => [[
                'environment' => 'sandbox', 'credentials-file' => '/unread-PRIVATE-fixture', $name => 'PRIVATE_VALUE',
            ]];
        }
    }

    /** @param array<string,mixed> $options */
    #[DataProvider('invalidConfiguration')]
    public function testInvalidOrOverrideConfigurationCannotSendRequests(array $options): void
    {
        $inner = $this->createStub(ClientInterface::class);
        $inner->method('sendRequest')->willThrowException(new RuntimeException('Unexpected fixture request.'));
        $http = new RecordingClient($inner);
        $result = CheckoutDiagnosticCommand::run($options, $http);
        self::assertSame('fail', $result['result']);
        self::assertSame([], $http->requests);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{?string}> */
    public static function errorCodes(): iterable
    {
        yield 'successful fresh matrix' => [null];
        yield 'known error' => ['validation_error'];
        yield 'opaque error' => ['private_customer_identifier'];
    }

    #[DataProvider('errorCodes')]
    public function testCommandExecutesOnlyFreshGuardedMatrixAndSuppressesNestedSdkErrorFields(?string $code): void
    {
        $directory = sys_get_temp_dir() . '/airwallex-checkout-command-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $credentials = $directory . '/synthetic-credentials';
        file_put_contents($credentials, "AIRWALLEX_CLIENT_ID=fixture_client\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_key\nAIRWALLEX_ACCOUNT_ID=fixture_account\n");
        $fixture = new CheckoutServerFixture('');
        $inner = $this->createStub(ClientInterface::class);
        $inner->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use ($fixture, $code): ResponseInterface {
                if ($request->getUri()->getPath() === '/api/v1/account') {
                    return new Response(200, [], '{"id":"fixture_account",'
                        . '"account_details":{"legal_entity_id":"fixture_entity"}}');
                }
                if ($code !== null && str_ends_with($request->getUri()->getPath(), '/billing_checkouts/create')) {
                    return new Response(400, [], json_encode([
                        'code' => $code, 'source' => 'linked_payment_account_id',
                        'message' => 'currency PRIVATE_CUSTOMER and metadata.PRIVATE_KEY.currency',
                    ], JSON_THROW_ON_ERROR));
                }
                return $fixture->respond($request);
            },
        );
        $http = new RecordingClient($inner);
        try {
            $result = CheckoutDiagnosticCommand::run([
                'environment' => 'sandbox', 'credentials-file' => $credentials, 'state-dir' => $directory,
            ], $http);
            self::assertSame($code === null ? 'pass' : 'fail', $result['result']);
            self::assertSame('organization', $result['context']);
            self::assertIsArray($result['diagnostics']);
            self::assertIsArray($result['diagnostics']['entries']);
            self::assertCount(9, $result['diagnostics']['entries']);
            self::assertSame(0, $result['diagnostics']['entries_dropped']);
            if ($code !== null) {
                $entry = $result['diagnostics']['entries'][0];
                self::assertIsArray($entry);
                self::assertSame($code === 'validation_error' ? $code : 'unlisted', $entry['public_code']);
                self::assertSame(['currency', 'linked_payment_account_id'], $entry['validation_fields']);
                self::assertIsArray($result['steps']);
                foreach ($result['steps'] as $step) {
                    self::assertIsArray($step);
                    self::assertArrayNotHasKey('provider_code', $step);
                    self::assertArrayNotHasKey('provider_source', $step);
                }
            }
            self::assertSame('fixture_account', $http->requests[0]->getHeaderLine('x-login-as'));
            self::assertSame('', $http->requests[2]->getHeaderLine('x-login-as'));
            foreach ($http->requests as $request) {
                self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
                self::assertSame('', $request->getHeaderLine('x-on-behalf-of'));
            }
            $json = json_encode($result, JSON_THROW_ON_ERROR);
            foreach (['PRIVATE', 'fixture_', 'private_customer_identifier', 'https://'] as $private) {
                self::assertStringNotContainsString($private, $json);
            }
            if ($code === null) {
                $other = CheckoutDiagnosticCommand::run([
                    'environment' => 'sandbox', 'credentials-file' => $credentials, 'state-dir' => $directory,
                ], $http);
                self::assertNotSame($result['run_id'], $other['run_id']);
            }
        } finally {
            self::remove($directory);
        }
    }

    public function testTrackedCliRejectsMissingAndAlternateRootOptionsWithoutProviderAccess(): void
    {
        $arguments = [
            [[], 'checkout-diagnostic'],
            [['--environment=sandbox', '--credentials-file=/unread-PRIVATE', '--sdk-root=/PRIVATE'],
                'checkout-diagnostic'],
            [['--environment=sandbox', '--credentials-file=/absent-PRIVATE-fixture'], 'billing-checkouts'],
        ];
        foreach ($arguments as [$args, $scenario]) {
            $process = proc_open([
                PHP_BINARY, dirname(__DIR__, 4) . '/tools/checkout-diagnostic.php', ...$args,
            ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                self::fail('Cannot execute the diagnostic argument fixture.');
            }
            [$input, $output, $errors] = self::pipes($pipes);
            fclose($input);
            $stdout = stream_get_contents($output);
            $stderr = stream_get_contents($errors);
            fclose($output);
            fclose($errors);
            self::assertSame(1, proc_close($process));
            self::assertSame('', $stderr);
            self::assertIsString($stdout);
            $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            self::assertSame('fail', $result['result']);
            self::assertSame($scenario, $result['scenario']);
            self::assertSame(
                hash_file('sha256', dirname(__DIR__, 4) . '/tools/checkout-diagnostic.php'),
                $result['diagnostic_tool_sha256']
            );
            self::assertStringNotContainsString('PRIVATE', $stdout);
        }
    }

    /** @return array{resource,resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (!is_array($pipes)) {
            self::fail('Diagnostic argument fixture pipes are unavailable.');
        }
        $input = $pipes[0] ?? null;
        $output = $pipes[1] ?? null;
        $errors = $pipes[2] ?? null;
        if (!is_resource($input) || !is_resource($output) || !is_resource($errors)) {
            self::fail('Diagnostic argument fixture pipes are unavailable.');
        }
        return [$input, $output, $errors];
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
}
