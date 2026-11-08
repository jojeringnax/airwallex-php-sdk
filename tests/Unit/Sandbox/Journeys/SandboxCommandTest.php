<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SandboxCommandTest extends TestCase
{
    private string $credentials;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'airwallex-command-fixture-');
        self::assertNotFalse($path);
        $this->credentials = $path;
        file_put_contents(
            $path,
            "AIRWALLEX_CLIENT_ID=fixture_client_id\nAIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_api_key\n"
            . "AIRWALLEX_ACCOUNT_ID=fixture_account_id\n",
        );
    }

    protected function tearDown(): void
    {
        unlink($this->credentials);
    }

    /** @return array<string,string> */
    private function options(): array
    {
        return [
            'environment' => 'sandbox', 'credentials-file' => $this->credentials,
            'scenario' => 'billing-catalogue', 'context' => 'organization',
        ];
    }

    /** @return iterable<string,array{int,string,string}> */
    public static function failedPrerequisites(): iterable
    {
        yield 'missing legal entity blocks' => [
            200, '{"id":"fixture_account_id","account_details":{}}', 'blocked',
        ];
        yield 'API prerequisite failure' => [
            403, '{"code":"forbidden","message":"SENTINEL_PRIVATE_MESSAGE"}', 'fail',
        ];
        yield 'wrong selected account fails' => [
            200, '{"id":"a-different-private-account","account_details":{"legal_entity_id":"private-entity"}}', 'fail',
        ];
    }

    #[DataProvider('failedPrerequisites')]
    public function testPrerequisiteOutcomeRetainsSafeVersionSourceAndOwnerEvidence(
        int $status,
        string $body,
        string $outcome,
    ): void {
        $recording = new RecordingClient(new HttpClient(['handler' => new MockHandler([
            new Response(201, [], '{"token":"fixture_token","expires_at":"2030-01-01T00:00:00Z"}'),
            new Response($status, [], $body),
        ])]));
        $result = SandboxCommand::run(
            $this->options(),
            static fn (Configuration $config, string $context): SandboxSession
                => new SandboxSession($config, $context, $recording),
        );
        self::assertSame($outcome, $result['result']);
        self::assertSame('2026-08-21', $result['api_version']);
        self::assertSame('organization', $result['context']);
        self::assertIsString($result['context_fingerprint']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['context_fingerprint']);
        self::assertIsArray($result['source']);
        self::assertSame(['base_commit', 'sdk_digest', 'harness_digest'], array_keys($result['source']));
        self::assertCount(2, $recording->requests);
        self::assertSame('GET', $recording->requests[1]->getMethod());
        if ($outcome === 'blocked') {
            self::assertSame('legal_entity_configuration_absent', $result['reason']);
        } elseif ($status === 403) {
            self::assertSame(403, $result['http_status']);
        }
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $privateValues = [
            'fixture_client_id', 'fixture_api_key', 'fixture_account_id', 'fixture_token',
            'a-different-private-account', 'private-entity', 'SENTINEL_PRIVATE_MESSAGE',
        ];
        foreach ($privateValues as $privateValue) {
            self::assertStringNotContainsString($privateValue, $json);
        }
    }

    public function testInvalidConfigurationKeepsAvailableSourceAndVersionWithoutInventingContext(): void
    {
        $options = $this->options();
        $options['credentials-file'] = '/nonexistent-sandbox-fixture-credentials';
        $calls = 0;
        $result = SandboxCommand::run(
            $options,
            static function (Configuration $configuration, string $context) use (&$calls): SandboxSession {
                ++$calls;
                throw new RuntimeException('No session should be created from invalid configuration.');
            },
        );
        self::assertSame(0, $calls);
        self::assertSame('fail', $result['result']);
        self::assertSame('2026-08-21', $result['api_version']);
        self::assertIsArray($result['source']);
        self::assertArrayNotHasKey('context_fingerprint', $result);
        self::assertArrayNotHasKey('context', $result);
    }
}
