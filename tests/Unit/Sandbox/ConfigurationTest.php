<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\GuardedTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

final class ConfigurationTest extends TestCase
{
    public function testExternalValuesAreDataWithoutShellEvaluationOrInterpolation(): void
    {
        $contents = <<<'ENV'
# sanitized, inert examples
export AIRWALLEX_CLIENT_ID='client-placeholder'
AIRWALLEX_SANDBOX_API_KEY_MASTER="literal-$(do-not-execute)" # comment
AIRWALLEX_ACCOUNT_ID=account-placeholder
UNUSED=${AIRWALLEX_CLIENT_ID}
ENV;
        self::assertSame([
            'AIRWALLEX_CLIENT_ID' => 'client-placeholder',
            'AIRWALLEX_SANDBOX_API_KEY_MASTER' => 'literal-$(do-not-execute)',
            'AIRWALLEX_ACCOUNT_ID' => 'account-placeholder',
            'UNUSED' => '${AIRWALLEX_CLIENT_ID}',
        ], Configuration::parse($contents));
    }

    public function testLoadingExternalDataLeavesItsFileUnchangedAndDebugRedacted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'airwallex-sandbox-config-');
        self::assertNotFalse($path);
        $contents = "AIRWALLEX_CLIENT_ID=client-placeholder\n";
        file_put_contents($path, $contents);
        try {
            $configuration = Configuration::load('sandbox', $path);
            self::assertSame($contents, file_get_contents($path));
            self::assertSame(['configuration' => '[redacted]'], $configuration->__debugInfo());
            $this->expectException(RuntimeException::class);
            serialize($configuration);
        } finally {
            unlink($path);
        }
    }

    public function testProductionConfigurationFailsBeforeReadingCredentials(): void
    {
        $this->expectException(RuntimeException::class);
        Configuration::load('production', '/nonexistent-credential-file');
    }

    public function testMissingCredentialsFileHasSafeFailure(): void
    {
        $this->expectExceptionMessage('The external credential file is unavailable or too large.');
        Configuration::load('sandbox', '/nonexistent-credential-file');
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedCredentialSources(): iterable
    {
        yield 'remote' => ['https://example.test/credentials'];
        yield 'stream' => ['php://stdin'];
        yield 'data wrapper' => ['data://text/plain,AIRWALLEX_CLIENT_ID=placeholder'];
        yield 'file wrapper' => ['file:///tmp/credentials'];
        yield 'relative' => ['.env'];
    }

    #[DataProvider('rejectedCredentialSources')]
    public function testCredentialsRequireRegularAbsoluteLocalFiles(string $path): void
    {
        $this->expectExceptionMessage('Credentials require an absolute regular local file.');
        Configuration::load('sandbox', $path);
    }

    public function testOversizedCredentialSourceIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'airwallex-sandbox-size-');
        self::assertNotFalse($path);
        file_put_contents($path, str_repeat('x', 65537));
        try {
            $this->expectExceptionMessage('The external credential file is unavailable or too large.');
            Configuration::load('sandbox', $path);
        } finally {
            unlink($path);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'command' => ['source /tmp/not-a-config'];
        yield 'duplicate' => ["AIRWALLEX_CLIENT_ID=a\nAIRWALLEX_CLIENT_ID=b"];
        yield 'quote' => ['AIRWALLEX_CLIENT_ID="unterminated'];
        yield 'trailing command' => ['AIRWALLEX_CLIENT_ID="placeholder"; execute'];
    }

    #[DataProvider('invalidConfigurations')]
    public function testMalformedConfigurationFailsWithoutDisplayingData(string $contents): void
    {
        $this->expectException(RuntimeException::class);
        Configuration::parse($contents);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedUrls(): iterable
    {
        yield 'production' => ['https://api.airwallex.com/api/v1/authentication/login'];
        yield 'plaintext' => ['http://api.sandbox.airwallex.com/api/v1/authentication/login'];
        yield 'lookalike' => ['https://api.sandbox.airwallex.com.example.test/api/v1/authentication/login'];
        yield 'port' => ['https://api.sandbox.airwallex.com:444/api/v1/authentication/login'];
        yield 'userinfo' => ['https://user@api.sandbox.airwallex.com/api/v1/authentication/login'];
        yield 'other path' => ['https://api.sandbox.airwallex.com/not-an-api'];
    }

    #[DataProvider('rejectedUrls')]
    public function testHostGuardRejectsBeforeTransportIsInvoked(string $url): void
    {
        $transport = $this->createMock(ClientInterface::class);
        $transport->expects(self::never())->method('sendRequest');
        $this->expectException(RuntimeException::class);
        (new GuardedTransport($transport))->sendRequest(new Request('POST', $url));
    }

    public function testSandboxRequestUsesGuardAndRecordsOnlyLoginCount(): void
    {
        $request = new Request('POST', 'https://api.sandbox.airwallex.com/api/v1/authentication/login');
        $response = new Response(201);
        $transport = $this->createMock(ClientInterface::class);
        $transport->expects(self::once())->method('sendRequest')->with($request)->willReturn($response);
        $guard = new GuardedTransport($transport);
        self::assertSame($response, $guard->sendRequest($request));
        self::assertSame(1, $guard->loginRequests);
    }
}
