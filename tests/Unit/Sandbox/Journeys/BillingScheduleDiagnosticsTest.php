<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingScheduleCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\BillingScheduleDiagnostics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

final class BillingScheduleDiagnosticsTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidOptions(): iterable
    {
        $base = ['environment' => 'sandbox', 'context' => 'organization', 'case' => 'flag-one-day',
            'credentials-file' => '/unread-private-fixture', 'state-dir' => '/tmp'];
        yield 'empty' => [[]];
        yield 'production' => [array_replace($base, ['environment' => 'production'])];
        yield 'implicit environment' => [array_diff_key($base, ['environment' => true])];
        yield 'account owner' => [array_replace($base, ['context' => 'account'])];
        yield 'all cascade' => [array_replace($base, ['case' => 'all'])];
        yield 'private case echo' => [array_replace($base, ['case' => 'PRIVATE_CASE'])];
        yield 'relative config' => [array_replace($base, ['credentials-file' => 'PRIVATE_CONFIG'])];
        yield 'URL config' => [array_replace($base, ['credentials-file' => 'https://private.test/file'])];
        yield 'nonexistent config' => [$base];
        yield 'missing state directory' => [array_diff_key($base, ['state-dir' => true])];
        yield 'wrong type' => [array_replace($base, ['state-dir' => []])];
        yield 'old ledger' => [$base + ['ledger' => '/PRIVATE_LEDGER']];
        yield 'root override' => [$base + ['sdk-root' => '/PRIVATE_ROOT']];
        yield 'arbitrary URL' => [$base + ['base-url' => 'https://private.test']];
    }

    /** @param array<string,mixed> $options */
    #[DataProvider('invalidOptions')]
    public function testCommandFailsWithoutNetworkAndWithoutConfigurationOrIdentifierEcho(array $options): void
    {
        $inner = $this->createStub(ClientInterface::class);
        $inner->method('sendRequest')->willThrowException(new RuntimeException('Unexpected synthetic call.'));
        $http = new RecordingClient($inner);
        $result = BillingScheduleCommand::run($options, $http);
        self::assertSame('fail', $result['result']);
        self::assertSame('2026-08-21', $result['api_version']);
        self::assertSame([], $http->requests);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE', 'private.test', 'private-fixture'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    /** @return iterable<string,array{string,string,?string}> */
    public static function codes(): iterable
    {
        yield 'create known' => ['create', 'currency_not_supported', 'currency_not_supported'];
        yield 'update known' => ['update', 'invalid_status_for_operation', 'invalid_status_for_operation'];
        yield 'cancel known' => ['cancel', 'invalid_status_for_operation', 'invalid_status_for_operation'];
        yield 'create-only code not update' => ['update', 'currency_not_supported', null];
        yield 'unknown code' => ['cancel', 'opaque_private_identifier', null];
    }

    #[DataProvider('codes')]
    public function testOnlyExactOperationCodesAndLiteralFieldTermsAreRetainedAndStreamIsRestored(
        string $operation,
        string $code,
        ?string $expected,
    ): void {
        $body = json_encode(['code' => $code, 'source' => 'duration.period',
            'message' => 'remaining_duration invalid; PRIVATE_VALUE https://private.test/id',
            'private_field' => 'ends_at', 'metadata' => ['trial_ends_at' => 'PRIVATE_ID']], JSON_THROW_ON_ERROR);
        $response = new Response(400, [], $body);
        $response->getBody()->seek(7);
        $inner = $this->createStub(ClientInterface::class);
        $inner->method('sendRequest')->willReturn($response);
        $diagnostics = new BillingScheduleDiagnostics($inner);
        $path = $operation === 'create' ? 'create' : 'private_id/' . $operation;
        self::assertSame($response, $diagnostics->sendRequest(new Request(
            'POST',
            'https://api.sandbox.airwallex.com/api/v1/billing/subscriptions/' . $path
        )));
        self::assertSame(7, $response->getBody()->tell());
        self::assertSame([
            'operation' => $operation, 'http_status' => 400, 'body_inspected' => true,
            'code_recognized' => $expected !== null, 'provider_code' => $expected,
            'mentioned_fields' => ['remaining_duration', 'duration'],
        ], $diagnostics->evidence()[0]);
        $json = json_encode($diagnostics->evidence(), JSON_THROW_ON_ERROR);
        foreach (
            ['PRIVATE', 'private.test', 'private_id', 'opaque_private_identifier', 'trial_ends_at', 'ends_at'] as $p
        ) {
            self::assertStringNotContainsString($p, $json);
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function unrelatedRequests(): iterable
    {
        yield 'login' => ['POST', 'https://api.sandbox.airwallex.com/api/v1/authentication/login'];
        yield 'GET' => ['GET', 'https://api.sandbox.airwallex.com/api/v1/billing/subscriptions/private_id'];
        yield 'production' => ['POST', 'https://api.airwallex.com/api/v1/billing/subscriptions/create'];
        yield 'different route' => ['POST', 'https://api.sandbox.airwallex.com/api/v1/billing/prices/create'];
    }

    #[DataProvider('unrelatedRequests')]
    public function testAuthenticationAndUnrelatedResponsesAreNeverInspected(string $method, string $url): void
    {
        $body = FnStream::decorate(Utils::streamFor('PRIVATE_TOKEN'), [
            'read' => static fn (): string => throw new RuntimeException('Do not inspect this payload.'),
        ]);
        $inner = $this->createStub(ClientInterface::class);
        $inner->method('sendRequest')->willReturn(new Response(400, [], $body));
        $diagnostics = new BillingScheduleDiagnostics($inner);
        $diagnostics->sendRequest(new Request($method, $url));
        self::assertSame([], $diagnostics->evidence());
    }

    public function testOversizeAndNonseekableBodiesAreNotInspected(): void
    {
        foreach ([Utils::streamFor(str_repeat('x', 65537)), new NoSeekStream(Utils::streamFor('duration'))] as $body) {
            $inner = $this->createStub(ClientInterface::class);
            $inner->method('sendRequest')->willReturn(new Response(400, [], $body));
            $diagnostics = new BillingScheduleDiagnostics($inner);
            $diagnostics->sendRequest(new Request(
                'POST',
                'https://api.sandbox.airwallex.com/api/v1/billing/subscriptions/private_id/update'
            ));
            self::assertFalse($diagnostics->evidence()[0]['body_inspected']);
            self::assertArrayNotHasKey('mentioned_fields', $diagnostics->evidence()[0]);
        }
    }

    public function testMessageBoundAndPrivateCompoundDoNotLeakDynamicMetadataTerms(): void
    {
        $inner = $this->createStub(ClientInterface::class);
        $inner->method('sendRequest')->willReturn(new Response(400, [], json_encode([
            'code' => 'validation_error', 'message' => str_repeat('x', 8193) . 'duration',
            'source' => 'metadata.remaining_duration PRIVATE_duration duration_PRIVATE',
        ], JSON_THROW_ON_ERROR)));
        $diagnostics = new BillingScheduleDiagnostics($inner);
        $diagnostics->sendRequest(new Request(
            'POST',
            'https://api.sandbox.airwallex.com/api/v1/billing/subscriptions/private_id/update'
        ));
        self::assertSame([], $diagnostics->evidence()[0]['mentioned_fields']);
    }

    public function testFailedPositionRestoreIsRedactedProtocolFailure(): void
    {
        $body = FnStream::decorate(Utils::streamFor('{"code":"validation_error"}'), [
            'seek' => static fn (): never => throw new RuntimeException('PRIVATE_STREAM_FAILURE'),
        ]);
        $inner = $this->createStub(ClientInterface::class);
        $inner->method('sendRequest')->willReturn(new Response(400, [], $body));
        $diagnostics = new BillingScheduleDiagnostics($inner);
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('A diagnostic response position could not be restored.');
        $diagnostics->sendRequest(new Request(
            'POST',
            'https://api.sandbox.airwallex.com/api/v1/billing/subscriptions/private_id/cancel'
        ));
    }
}
