<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookConfigCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookConfigObservation;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookConfigProfile;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookConfigTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WebhookDefaultLoginReadTest extends TestCase
{
    private const string URL = 'https://api.sandbox.airwallex.com/api/v1/webhooks';
    private string $directory;
    private string $credentials;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sdk-default-webhook-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->credentials = $this->directory . '/credentials';
        file_put_contents($this->credentials, "AIRWALLEX_CLIENT_ID=SYNTHETIC_PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=SYNTHETIC_PRIVATE_KEY\n");
        chmod($this->credentials, 0600);
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
            is_dir($path) && !is_link($path) ? self::remove($path) : unlink($path);
        }
        rmdir($directory);
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $this->credentials,
            'state-dir' => $this->directory, 'read-one-page' => true];
    }

    private function ledgerFile(): string
    {
        $paths = glob($this->directory . '/sdk-validation-*/ledger.json') ?: [];
        self::assertCount(1, $paths);
        return $paths[0];
    }

    /** @return array<array-key,mixed> */
    private static function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    private static function client(string $page, int $status = 200): WebhookConfigFixtureClient
    {
        return new WebhookConfigFixtureClient([
            new Response(201, [], '{"token":"SYNTHETIC_PRIVATE_TOKEN","expires_at":"2100-01-01T00:00:00Z"}'),
            new Response($status, [], $page),
        ]);
    }

    /** @param array<string,mixed> $safe */
    private static function safe(array $safe): void
    {
        self::assertSame('DEFAULT', $safe['login_profile']);
        self::assertSame('default-login', $safe['context']);
        self::assertSame('webhook-default-login-read', $safe['scenario']);
        foreach (
            ['mutation_sent', 'receiver_followed', 'raw_delivery_observed',
            'signatures_verified', 'global_absence_proven'] as $key
        ) {
            self::assertFalse($safe[$key]);
        }
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testDefaultNeedsNoAccountAndBindsDurableProfileBeforeAuthentication(): void
    {
        $http = self::client('{"items":[{"id":"123","secret":"SYNTHETIC_PRIVATE_SECRET",'
            . '"url":"https://synthetic-private.test/hook","version":"2026-08-21",'
            . '"events":["billing_checkout.created"]}],"page_after":null,"page_before":null}');
        $http->intercept = function (RequestInterface $request): void {
            if ($request->getMethod() !== 'POST') {
                return;
            }
            $ledger = self::readJson($this->ledgerFile());
            self::assertIsArray($ledger['actions']);
            self::assertSame(['webhook_configuration_read'], array_keys($ledger['actions']));
            self::assertIsArray($ledger['actions']['webhook_configuration_read']);
            $body = ['method' => 'GET', 'path' => '/api/v1/webhooks', 'api_version' => '2026-08-21',
                'tool_sha256' => hash_file('sha256', dirname(__DIR__, 4) . '/tools/webhook-default-login-read.php'),
                'login_profile' => 'DEFAULT', 'x_login_as_present' => false];
            self::assertSame(
                hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)),
                $ledger['actions']['webhook_configuration_read']['body_hash']
            );
            self::assertSame([], $ledger['resources']);
            self::assertFalse($request->hasHeader('x-login-as'));
        };
        $safe = WebhookConfigCommand::run($this->options(), $http, profile: WebhookConfigProfile::DefaultLogin);
        self::assertSame('pass', $safe['result']);
        self::assertSame(1, $safe['returned_count']);
        self::assertSame(1, $safe['known_billing_family_count']);
        self::assertFalse($safe['organization_manager_verified']);
        self::assertTrue($safe['pagination_complete']);
        self::assertCount(2, $http->requests);
        [$login, $read] = $http->requests;
        self::assertSame('/api/v1/authentication/login', $login->getUri()->getPath());
        self::assertSame(self::URL, (string) $read->getUri());
        self::assertSame('GET', $read->getMethod());
        self::assertSame('Bearer SYNTHETIC_PRIVATE_TOKEN', $read->getHeaderLine('Authorization'));
        self::assertSame('2026-08-21', $read->getHeaderLine('x-api-version'));
        self::assertSame('', (string) $read->getBody());
        foreach ([$login, $read] as $request) {
            foreach (['x-login-as', 'x-on-behalf-of', 'x-org-id', 'Cookie', 'Referer', 'request-id'] as $header) {
                self::assertFalse($request->hasHeader($header));
            }
        }
        self::assertFalse($read->hasHeader('x-api-key'));
        $snapshotPath = dirname($this->ledgerFile()) . '/webhook-config.json';
        $snapshot = self::readJson($snapshotPath);
        self::assertSame('DEFAULT', $snapshot['login_profile']);
        self::assertIsArray($snapshot['items']);
        self::assertIsArray($snapshot['items'][0]);
        self::assertSame('123', $snapshot['items'][0]['id']);
        self::assertSame(0600, fileperms($snapshotPath) & 0777);
        self::assertSame(0700, fileperms(dirname($snapshotPath)) & 0777);
        self::assertStringContainsString('SYNTHETIC_PRIVATE_SECRET', file_get_contents($snapshotPath) ?: '');
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', file_get_contents($this->ledgerFile()) ?: '');
        self::safe($safe);
    }

    public function testDefaultFingerprintIsIndependentOfUnusedAccountButNeverMatchesAccountProfile(): void
    {
        $first = Configuration::load('sandbox', $this->credentials);
        $default = WebhookConfigObservation::contextFingerprint($first, WebhookConfigProfile::DefaultLogin);
        file_put_contents($this->credentials, "AIRWALLEX_CLIENT_ID=SYNTHETIC_PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=SYNTHETIC_PRIVATE_KEY\nAIRWALLEX_ACCOUNT_ID=SYNTHETIC_PRIVATE_A\n");
        $second = Configuration::load('sandbox', $this->credentials);
        self::assertSame(
            $default,
            WebhookConfigObservation::contextFingerprint($second, WebhookConfigProfile::DefaultLogin)
        );
        $account = WebhookConfigObservation::contextFingerprint($second);
        self::assertNotSame($default, $account);
        $ledger = RunLedger::create($account, $this->directory);
        $http = self::client('{"items":[]}');
        $safe = WebhookConfigObservation::execute(
            $second,
            $ledger,
            $this->directory,
            $http,
            profile: WebhookConfigProfile::DefaultLogin
        );
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $http->requests);
        self::safe($safe);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function badOptions(): iterable
    {
        yield 'opt-in absent' => [['read-one-page' => null]];
        yield 'opt-in false' => [['read-one-page' => false]];
        yield 'opt-in string' => [['read-one-page' => 'true']];
        yield 'opt-in duplicate' => [['read-one-page' => [true, true]]];
        yield 'production' => [['environment' => 'production']];
        yield 'context' => [['context' => 'organization']];
        yield 'profile' => [['profile' => 'ACCOUNT']];
        yield 'ID' => [['id' => 'SYNTHETIC_PRIVATE_ID']];
        yield 'old ledger' => [['ledger' => '/SYNTHETIC_PRIVATE_LEDGER']];
        yield 'page' => [['page' => 'SYNTHETIC_PRIVATE_CURSOR']];
        yield 'root' => [['sdk-root' => '/SYNTHETIC_PRIVATE_ROOT']];
    }

    /** @param array<string,mixed> $override */
    #[DataProvider('badOptions')]
    public function testUnknownOrUnselectedAuthorityFailsBeforeAuth(array $override): void
    {
        $http = self::client('{"items":[]}');
        $safe = WebhookConfigCommand::run(
            array_replace($this->options(), $override),
            $http,
            profile: WebhookConfigProfile::DefaultLogin
        );
        self::assertSame('configuration', $safe['phase']);
        self::assertSame([], $http->requests);
        self::safe($safe);
    }

    /** @return iterable<string,array{string}> */
    public static function ambiguousPages(): iterable
    {
        yield 'escaped duplicate items' => ['{"items":[],"it\\u0065ms":[]}'];
        yield 'numeric ID is not a string' => ['{"items":[{"id":123,"secret":"","url":"",'
            . '"version":"2026-08-21","events":[]}]}'];
        yield 'duplicate inner secret' => ['{"items":[{"id":"123","secret":"A","secr\\u0065t":"B",'
            . '"url":"","version":"2026-08-21","events":[]}]}'];
        yield 'object not list' => ['{"items":{}}'];
        yield 'null list' => ['{"items":null}'];
        yield 'invalid UTF8' => ["{\"items\":[],\"extra\":\"\xff\"}"];
    }

    #[DataProvider('ambiguousPages')]
    public function testNativeAndUniqueProofPrecedesCountsAndRetention(string $raw): void
    {
        $http = self::client($raw);
        $safe = WebhookConfigCommand::run($this->options(), $http, profile: WebhookConfigProfile::DefaultLogin);
        self::assertSame('fail', $safe['result']);
        self::assertArrayNotHasKey('returned_count', $safe);
        self::assertFalse($safe['private_snapshot_retained']);
        self::assertCount(2, $http->requests);
        self::safe($safe);
    }

    /** @return iterable<string,array{string,string}> */
    public static function cursors(): iterable
    {
        yield 'missing' => ['{"items":[]}', 'missing'];
        yield 'null' => ['{"items":[],"page_after":null,"page_before":null}', 'null'];
        yield 'more' => ['{"items":[],"page_after":"SYNTHETIC_PRIVATE_PAGE"}', 'nonempty'];
        yield 'numeric is not string' => ['{"items":[],"page_after":123}', 'invalid'];
    }

    #[DataProvider('cursors')]
    public function testCursorFactsNeverAuthorizeTraversalOrGlobalAbsence(string $raw, string $state): void
    {
        $http = self::client($raw);
        $safe = WebhookConfigCommand::run($this->options(), $http, profile: WebhookConfigProfile::DefaultLogin);
        self::assertSame('pass', $safe['result']);
        self::assertSame($state, $safe['page_after_state']);
        self::assertCount(2, $http->requests);
        self::safe($safe);
    }

    public function testDurableProfileFailureStopsEvenAuthentication(): void
    {
        $calls = 0;
        $http = self::client('{"items":[]}');
        $safe = WebhookConfigCommand::run(
            $this->options(),
            $http,
            static function (string $fingerprint, string $directory) use (&$calls): RunLedger {
                return RunLedger::create($fingerprint, $directory, synchronize:
                    static function () use (&$calls): bool {
                        return ++$calls < 4;
                    });
            },
            profile: WebhookConfigProfile::DefaultLogin
        );
        self::assertSame([], $http->requests);
        self::assertSame('fail', $safe['result']);
        self::assertSame(4, $calls);
        self::safe($safe);
    }

    #[DataProviderExternal(WebhookConfigTransportTest::class, 'streamFailures')]
    public function testDefaultProfileKeepsBoundedStreamingFailureBehavior(
        string $mode,
        int $status,
        string $body,
    ): void {
        $stream = new WebhookConfigFixtureStream($body, $mode);
        $http = new WebhookConfigFixtureClient([
            new Response(201, [], '{"token":"SYNTHETIC_PRIVATE_TOKEN","expires_at":"2100-01-01T00:00:00Z"}'),
            new Response($status, [], $stream),
        ]);
        $safe = WebhookConfigCommand::run($this->options(), $http, profile: WebhookConfigProfile::DefaultLogin);
        self::assertSame('fail', $safe['result']);
        self::assertTrue($stream->closed);
        self::assertLessThanOrEqual(65537, $stream->bytesRead);
        self::assertCount(2, $http->requests);
        self::assertFalse($safe['private_snapshot_retained']);
        self::safe($safe);
    }

    /** @return iterable<string,array{string}> */
    public static function badLogins(): iterable
    {
        yield 'numeric token' => ['{"token":123,"expires_at":"2100-01-01T00:00:00Z"}'];
        yield 'duplicate escaped token' => ['{"token":"A","tok\\u0065n":"B","expires_at":"2100-01-01T00:00:00Z"}'];
        yield 'expired' => ['{"token":"SYNTHETIC_PRIVATE_TOKEN","expires_at":"2000-01-01T00:00:00Z"}'];
        yield 'invalid offset' => ['{"token":"SYNTHETIC_PRIVATE_TOKEN","expires_at":"2100-01-01T00:00:00+24:00"}'];
    }

    #[DataProvider('badLogins')]
    public function testDefaultAuthenticationAmbiguityCannotReachConfigurationRead(string $body): void
    {
        $http = new WebhookConfigFixtureClient([new Response(201, [], $body)]);
        $safe = WebhookConfigCommand::run($this->options(), $http, profile: WebhookConfigProfile::DefaultLogin);
        self::assertSame('fail', $safe['result']);
        self::assertSame('authentication', $safe['phase']);
        self::assertCount(1, $http->requests);
        self::safe($safe);
    }

    /** @return iterable<string,array{int}> */
    public static function authFailures(): iterable
    {
        foreach ([400, 401, 403, 503] as $status) {
            yield 'auth ' . $status => [$status];
        }
    }

    #[DataProvider('authFailures')]
    public function testAuthenticationFailureNeverTriggersAReadOrRelogin(int $status): void
    {
        $http = new WebhookConfigFixtureClient([new Response(
            $status,
            [],
            '{"code":"SYNTHETIC_PRIVATE_CODE","message":"SYNTHETIC_PRIVATE_MESSAGE"}'
        )]);
        $safe = WebhookConfigCommand::run($this->options(), $http, profile: WebhookConfigProfile::DefaultLogin);
        self::assertSame('fail', $safe['result']);
        self::assertSame('authentication', $safe['phase']);
        self::assertSame($status, $safe['http_status']);
        self::assertCount(1, $http->requests);
        self::safe($safe);
    }

    public function testFailedSnapshotDurabilityCannotClaimACompletedDefaultObservation(): void
    {
        $safe = WebhookConfigCommand::run(
            $this->options(),
            self::client('{"items":[]}'),
            synchronize: static fn (): bool => false,
            profile: WebhookConfigProfile::DefaultLogin
        );
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['configuration_read_succeeded']);
        self::assertFalse($safe['private_snapshot_retained']);
        self::safe($safe);
    }

    #[DataProviderExternal(WebhookConfigObservationTest::class, 'failedReads')]
    public function testDefaultReadStatusIsAnObservationWithoutRetryOrCreationInference(
        int $status,
        string $body,
        ?string $code,
    ): void {
        $http = self::client($body, $status);
        $safe = WebhookConfigCommand::run($this->options(), $http, profile: WebhookConfigProfile::DefaultLogin);
        self::assertSame('fail', $safe['result']);
        self::assertSame($status, $safe['http_status']);
        self::assertSame($code, $safe['code'] ?? null);
        self::assertCount(2, $http->requests);
        self::safe($safe);
    }

    /** @return iterable<string,array{string}> */
    public static function forbiddenHeaders(): iterable
    {
        foreach (['x-login-as', 'x-org-id', 'x-organization-id', 'x-on-behalf-of', 'Cookie', 'Referer'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('forbiddenHeaders')]
    public function testDefaultTransportRejectsEveryAlternateContextBeforeSend(string $name): void
    {
        $client = new WebhookConfigFixtureClient([]);
        $http = new WebhookConfigTransport($client, profile: WebhookConfigProfile::DefaultLogin);
        $this->expectException(ProtocolException::class);
        try {
            $http->sendRequest(new Request(
                'POST',
                'https://api.sandbox.airwallex.com/api/v1/authentication/login',
                ['Accept' => 'application/json', 'x-api-version' => '2026-08-21',
                    'x-api-key' => 'SYNTHETIC_PRIVATE_KEY', 'x-client-id' => 'SYNTHETIC_PRIVATE_CLIENT',
                $name => 'SYNTHETIC_PRIVATE_CONTEXT']
            ));
        } finally {
            self::assertSame([], $client->requests);
        }
    }

    public function testDefaultTransportRequiresAuthenticationBeforeTheRead(): void
    {
        $client = new WebhookConfigFixtureClient([]);
        $http = new WebhookConfigTransport($client, profile: WebhookConfigProfile::DefaultLogin);
        $this->expectException(ProtocolException::class);
        try {
            $http->sendRequest(new Request('GET', self::URL, ['Accept' => 'application/json',
                'x-api-version' => '2026-08-21', 'Authorization' => 'Bearer SYNTHETIC_PRIVATE_TOKEN']));
        } finally {
            self::assertSame([], $client->requests);
        }
    }

    public function testDefaultTransportRejectsLateBodyCloseAndCannotRepeatTheRead(): void
    {
        $calls = 0;
        $stream = new WebhookConfigFixtureStream('{"items":[]}');
        $client = new WebhookConfigFixtureClient([
            new Response(201, [], '{"token":"SYNTHETIC_PRIVATE_TOKEN","expires_at":"2100-01-01T00:00:00Z"}'),
            new Response(200, [], $stream),
        ]);
        $http = new WebhookConfigTransport(
            $client,
            static function () use (&$calls): float {
                return ++$calls >= 9 ? 11.0 : 0.0;
            },
            WebhookConfigProfile::DefaultLogin
        );
        $http->sendRequest(new Request(
            'POST',
            'https://api.sandbox.airwallex.com/api/v1/authentication/login',
            ['Accept' => 'application/json', 'x-api-version' => '2026-08-21',
            'x-api-key' => 'SYNTHETIC_PRIVATE_KEY',
            'x-client-id' => 'SYNTHETIC_PRIVATE_CLIENT']
        ));
        $read = new Request('GET', self::URL, ['Accept' => 'application/json',
            'x-api-version' => '2026-08-21', 'Authorization' => 'Bearer SYNTHETIC_PRIVATE_TOKEN']);
        foreach ([1, 2] as $attempt) {
            try {
                $http->sendRequest($read);
                self::fail('A late or repeated read succeeded.');
            } catch (ProtocolException $error) {
                self::assertStringNotContainsString('SYNTHETIC_PRIVATE', (string) $error);
                self::assertCount(2, $client->requests);
            }
        }
        self::assertTrue($stream->closed);
    }
}
