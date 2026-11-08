<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookConfigCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookConfigObservation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use stdClass;

final class WebhookConfigObservationTest extends TestCase
{
    private string $directory;
    private string $credentials;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sdk-webhook-fixture-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->credentials = $this->directory . '/credentials';
        file_put_contents($this->credentials, "AIRWALLEX_CLIENT_ID=SYNTHETIC_PRIVATE_CLIENT\n"
            . "AIRWALLEX_SANDBOX_API_KEY_MASTER=SYNTHETIC_PRIVATE_KEY\n"
            . "AIRWALLEX_ACCOUNT_ID=SYNTHETIC_PRIVATE_ACCOUNT\n");
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

    /** @return array<string,string> */
    private function options(): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $this->credentials,
            'state-dir' => $this->directory];
    }

    /** @return array<string,mixed> */
    private static function item(): array
    {
        return ['id' => 'SYNTHETIC_PRIVATE_ID', 'secret' => 'SYNTHETIC_PRIVATE_SECRET',
            'url' => 'https://synthetic-private-receiver.test/?token=SYNTHETIC_PRIVATE_QUERY',
            'version' => '2026-08-21', 'events' => ['subscription.created', 'payment_intent.succeeded',
                'SYNTHETIC_PRIVATE_EVENT'], 'request_id' => 'SYNTHETIC_PRIVATE_REQUEST',
            'extra' => ['raw_headers' => 'SYNTHETIC_PRIVATE_HEADER']];
    }

    /** @param list<array<string,mixed>>|null $items */
    private static function page(?array $items = null): string
    {
        return json_encode(
            ['items' => $items ?? [self::item()], 'page_before' => null, 'page_after' => null],
            JSON_THROW_ON_ERROR
        );
    }

    private static function client(string $page, int $status = 200): WebhookConfigFixtureClient
    {
        return new WebhookConfigFixtureClient([
            new Response(201, [], '{"token":"SYNTHETIC_PRIVATE_TOKEN","expires_at":"2100-01-01T00:00:00Z"}'),
            new Response($status, [], $page),
        ]);
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
        $value = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        return $value;
    }

    /** @param array<string,mixed> $safe */
    private static function assertSanitized(array $safe): void
    {
        $json = json_encode($safe, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $json);
        self::assertStringNotContainsString('sdk-webhook-fixture-', $json);
        self::assertFalse($safe['mutation_sent']);
        self::assertFalse($safe['receiver_followed']);
        self::assertFalse($safe['global_absence_proven']);
        self::assertFalse($safe['raw_delivery_observed']);
        self::assertFalse($safe['signatures_verified']);
    }

    public function testDurableBeforeAuthBareAccountReadAndSeparateExactPrivateSubset(): void
    {
        $http = self::client(self::page());
        $http->intercept = function (): void {
            $ledger = self::readJson($this->ledgerFile());
            self::assertIsArray($ledger['actions']);
            self::assertArrayHasKey('webhook_configuration_read', $ledger['actions']);
            self::assertSame([], $ledger['resources']);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($ledger, JSON_THROW_ON_ERROR));
        };
        $safe = WebhookConfigCommand::run($this->options(), $http);
        self::assertSame('pass', $safe['result']);
        self::assertSame('completed', $safe['phase']);
        self::assertSame(1, $safe['returned_count']);
        self::assertSame(1, $safe['secret_present_count']);
        self::assertSame(1, $safe['version_match_count']);
        self::assertSame(1, $safe['https_url_count']);
        self::assertSame(0, $safe['other_url_count']);
        self::assertSame(1, $safe['known_billing_family_count']);
        self::assertSame(1, $safe['known_payments_family_count']);
        self::assertSame(1, $safe['unrecognized_event_count']);
        self::assertTrue($safe['private_snapshot_retained']);
        self::assertTrue($safe['pagination_complete']);
        self::assertFalse($safe['organization_manager_verified']);
        self::assertCount(2, $http->requests);
        [$login, $read] = $http->requests;
        self::assertSame('SYNTHETIC_PRIVATE_ACCOUNT', $login->getHeaderLine('x-login-as'));
        self::assertSame('/api/v1/authentication/login', $login->getUri()->getPath());
        self::assertSame('GET', $read->getMethod());
        self::assertSame('https://api.sandbox.airwallex.com/api/v1/webhooks', (string) $read->getUri());
        self::assertSame('Bearer SYNTHETIC_PRIVATE_TOKEN', $read->getHeaderLine('Authorization'));
        self::assertSame('2026-08-21', $read->getHeaderLine('x-api-version'));
        self::assertSame('application/json', $read->getHeaderLine('Accept'));
        self::assertSame('', (string) $read->getBody());
        foreach (['x-on-behalf-of', 'x-login-as', 'request-id', 'x-request-id', 'Cookie', 'Referer'] as $header) {
            self::assertFalse($read->hasHeader($header));
        }
        $ledgerPath = $this->ledgerFile();
        $snapshotPath = dirname($ledgerPath) . '/webhook-config.json';
        $snapshot = self::readJson($snapshotPath);
        self::assertSame(0600, fileperms($snapshotPath) & 0777);
        self::assertSame(0700, fileperms(dirname($snapshotPath)) & 0777);
        self::assertSame(
            [array_intersect_key(self::item(), array_flip(['id', 'secret', 'url', 'version', 'events']))],
            $snapshot['items']
        );
        self::assertSame($safe['source'], $snapshot['source']);
        self::assertSame($safe['context_fingerprint'], $snapshot['context_fingerprint']);
        self::assertSame(
            hash_file('sha256', dirname(__DIR__, 4) . '/tools/webhook-config-observation.php'),
            $snapshot['tool_sha256']
        );
        $ledger = self::readJson($ledgerPath);
        self::assertIsArray($ledger['observations']);
        self::assertSame(
            ['sha256' => hash_file('sha256', $snapshotPath)],
            $ledger['observations']['webhook_configuration_snapshot']
        );
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($ledger, JSON_THROW_ON_ERROR));
        self::assertSanitized($safe);
    }

    public function testUnknownVersionEventsAndNonHttpsPreservedPrivatelyWithoutInventingOwnership(): void
    {
        $item = self::item();
        $item['id'] = '0123';
        $item['secret'] = '';
        $item['version'] = 'SYNTHETIC_PRIVATE_FUTURE_VERSION';
        $item['url'] = 'http://SYNTHETIC_PRIVATE_RECEIVER.test';
        $item['events'] = ['payment_intent.unknown', '*', 'subscription.unknown'];
        $safe = WebhookConfigCommand::run($this->options(), self::client(self::page([$item])));
        self::assertSame('pass', $safe['result']);
        self::assertSame(0, $safe['secret_present_count']);
        self::assertSame(0, $safe['version_match_count']);
        self::assertSame(0, $safe['https_url_count']);
        self::assertSame(1, $safe['other_url_count']);
        self::assertSame(0, $safe['known_billing_family_count']);
        self::assertSame(0, $safe['known_payments_family_count']);
        self::assertSame(3, $safe['unrecognized_event_count']);
        $snapshot = self::readJson(dirname($this->ledgerFile()) . '/webhook-config.json');
        self::assertIsArray($snapshot['items']);
        self::assertIsArray($snapshot['items'][0]);
        self::assertSame('0123', $snapshot['items'][0]['id']);
        self::assertSame($item['events'], $snapshot['items'][0]['events']);
        self::assertSanitized($safe);
    }

    /** @return iterable<string,array{string,bool}> */
    public static function receiverUrlCategories(): iterable
    {
        yield 'uppercase HTTPS' => ['HTTPS://synthetic-private.test/hook?token=SYNTHETIC_PRIVATE', true];
        yield 'mixed HTTPS' => ['hTtPs://synthetic-private.test/hook?key=SYNTHETIC_PRIVATE', true];
        yield 'space in host' => ['https://bad host/hook', false];
        yield 'invalid DNS host' => ['https://-bad.test/hook', false];
        yield 'missing host' => ['https:///hook', false];
        yield 'raw control' => ["https://example.test/\nPRIVATE", false];
        yield 'relative' => ['/SYNTHETIC_PRIVATE', false];
        yield 'HTTP' => ['http://SYNTHETIC_PRIVATE.test/hook', false];
    }

    #[DataProvider('receiverUrlCategories')]
    public function testUrlCategoryUsesSyntaxAndCaseWithoutChangingOrFollowingPrivateValue(
        string $url,
        bool $https,
    ): void {
        $item = self::item();
        $item['url'] = $url;
        $http = self::client(self::page([$item]));
        $safe = WebhookConfigCommand::run($this->options(), $http);
        self::assertSame('pass', $safe['result']);
        self::assertSame($https ? 1 : 0, $safe['https_url_count']);
        self::assertSame($https ? 0 : 1, $safe['other_url_count']);
        $snapshot = self::readJson(dirname($this->ledgerFile()) . '/webhook-config.json');
        self::assertIsArray($snapshot['items']);
        self::assertIsArray($snapshot['items'][0]);
        self::assertSame($url, $snapshot['items'][0]['url']);
        self::assertCount(2, $http->requests);
        self::assertSanitized($safe);
    }

    /** @return iterable<string,array{string,string,string,bool}> */
    public static function paginationPages(): iterable
    {
        yield 'both omitted' => ['{"items":[]}', 'missing', 'missing', false];
        yield 'both null' => ['{"items":[],"page_after":null,"page_before":null}', 'null', 'null', true];
        yield 'next unknown string' => ['{"items":[],"page_after":"SYNTHETIC_PRIVATE_CURSOR"}',
            'nonempty', 'missing', false];
        yield 'next null prior omitted' => ['{"items":[],"page_after":null}', 'null', 'missing', false];
        yield 'empty is not documented terminal' => ['{"items":[],"page_after":"","page_before":null}',
            'empty', 'null', false];
        yield 'invalid does not prove absence' => ['{"items":[],"page_after":42,"page_before":{}}',
            'invalid', 'invalid', false];
    }

    #[DataProvider('paginationPages')]
    public function testOnlyFirstPageCursorCategoriesNeverGlobalAbsence(
        string $page,
        string $after,
        string $before,
        bool $complete,
    ): void {
        $http = self::client($page);
        $safe = WebhookConfigCommand::run($this->options(), $http);
        self::assertSame('pass', $safe['result']);
        self::assertSame($after, $safe['page_after_state']);
        self::assertSame($before, $safe['page_before_state']);
        self::assertSame($complete, $safe['pagination_complete']);
        self::assertTrue($safe['no_records_on_page']);
        self::assertTrue($safe['first_page_only']);
        self::assertCount(2, $http->requests);
        self::assertSanitized($safe);
    }

    /** @return iterable<string,array{string}> */
    public static function malformedPages(): iterable
    {
        foreach (
            ['[]', '{}', '{"items":{}}', '{"items":null}', '{"items":[[]]}',
            '{"items":[],"items":[]}', '{"items":[],"\\u0069tems":[]}', 'SYNTHETIC_PRIVATE_NOT_JSON'] as $index => $raw
        ) {
            yield 'shape ' . $index => [$raw];
        }
        foreach (['id', 'secret', 'url', 'version', 'events'] as $field) {
            $item = self::item();
            unset($item[$field]);
            yield 'missing ' . $field => [self::page([$item])];
            $item = self::item();
            $item[$field] = 123;
            yield 'numeric ' . $field => [self::page([$item])];
        }
        $item = self::item();
        $item['events'] = new stdClass();
        yield 'object events' => [self::page([$item])];
        $item['events'] = [null];
        yield 'null event' => [self::page([$item])];
        $item['events'] = [str_repeat('x', 257)];
        yield 'long event' => [self::page([$item])];
        $item['events'] = array_fill(0, 257, 'created');
        yield 'many events' => [self::page([$item])];
        foreach (['id' => 1025, 'secret' => 8193, 'url' => 8193, 'version' => 129] as $field => $length) {
            $item = self::item();
            $item[$field] = str_repeat('x', $length);
            yield 'long ' . $field => [self::page([$item])];
        }
        yield 'duplicate ids' => [self::page([self::item(), self::item()])];
        $item = self::item();
        $item['id'] = '';
        yield 'empty id' => [self::page([$item])];
        yield 'more than default page' => [self::page(array_fill(0, 21, self::item()))];
    }

    #[DataProvider('malformedPages')]
    public function testMalformedOrOversizedFieldsFailWithoutRetainingSubset(string $raw): void
    {
        $http = self::client($raw);
        $safe = WebhookConfigCommand::run($this->options(), $http);
        self::assertSame('fail', $safe['result']);
        self::assertFalse($safe['private_snapshot_retained']);
        self::assertSame([], glob(dirname($this->ledgerFile()) . '/webhook-config.json') ?: []);
        self::assertCount(2, $http->requests);
        self::assertSanitized($safe);
    }

    /** @return iterable<string,array{int,string,string|null}> */
    public static function failedReads(): iterable
    {
        yield 'forbidden no enablement diagnosis' => [403,
            '{"code":"SYNTHETIC_PRIVATE_CODE","message":"SYNTHETIC_PRIVATE_MESSAGE"}', 'unlisted'];
        yield 'allowlisted exact code' => [400, '{"code":"invalid_argument"}', 'invalid_argument'];
        yield 'suffix is not allowlisted' => [400, '{"code":"invalid_argument_SYNTHETIC_PRIVATE"}', 'unlisted'];
        yield 'auth expired no re-login' => [401, '{"code":"credentials_expired"}', 'credentials_expired'];
        yield 'server no retry' => [503, '{"code":"SYNTHETIC_PRIVATE_ERROR"}', 'unlisted'];
        yield 'redirect no receiver follow' => [302, 'SYNTHETIC_PRIVATE_LOCATION', 'unlisted'];
        yield 'different success' => [201, '{}', null];
    }

    #[DataProvider('failedReads')]
    public function testFailureCategoriesAreFixedWithNoRetryOrInference(int $status, string $raw, ?string $code): void
    {
        $http = self::client($raw, $status);
        $safe = WebhookConfigCommand::run($this->options(), $http);
        self::assertSame('fail', $safe['result']);
        self::assertSame($status, $safe['http_status']);
        self::assertSame($code, $safe['code'] ?? null);
        self::assertFalse($safe['private_snapshot_retained']);
        self::assertCount(2, $http->requests);
        self::assertSanitized($safe);
    }

    public function testOversizedLoginFailsBeforeConfigurationGet(): void
    {
        $stream = new WebhookConfigFixtureStream(str_repeat('SYNTHETIC_PRIVATE', 10000));
        $http = new WebhookConfigFixtureClient([new Response(201, [], $stream)]);
        $safe = WebhookConfigCommand::run($this->options(), $http);
        self::assertSame('fail', $safe['result']);
        self::assertSame('authentication', $safe['phase']);
        self::assertCount(1, $http->requests);
        self::assertTrue($stream->closed);
        self::assertSame(65537, $stream->bytesRead);
        self::assertSanitized($safe);
    }

    public function testMismatchedContextFailsBeforeAuthentication(): void
    {
        $http = self::client(self::page());
        $ledger = RunLedger::create(str_repeat('0', 64), $this->directory);
        $safe = WebhookConfigObservation::execute(
            Configuration::load('sandbox', $this->credentials),
            $ledger,
            $this->directory,
            $http
        );
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $http->requests);
        self::assertSanitized($safe);
    }

    /** @return iterable<string,array{int}> */
    public static function failedInitialSyncs(): iterable
    {
        yield 'parent directory' => [1];
        yield 'new ledger' => [2];
        yield 'new run directory' => [3];
    }

    #[DataProvider('failedInitialSyncs')]
    public function testFailedInitialProvenancePreventsAuthentication(int $failure): void
    {
        $calls = 0;
        $http = self::client(self::page());
        $safe = WebhookConfigCommand::run(
            $this->options(),
            $http,
            static function (string $fingerprint, string $directory) use (&$calls, $failure): RunLedger {
                return RunLedger::create($fingerprint, $directory, synchronize:
                    static function () use (&$calls, $failure): bool {
                        return ++$calls !== $failure;
                    });
            }
        );
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $http->requests);
        self::assertSame($failure, $calls);
        self::assertSanitized($safe);
    }

    public function testGitStateRejectedWithCredentialsOutsideThatCheckout(): void
    {
        $state = $this->directory . '/state';
        mkdir($state, 0700);
        mkdir($state . '/.git');
        $options = $this->options();
        $options['state-dir'] = $state;
        $http = self::client(self::page());
        $safe = WebhookConfigCommand::run($options, $http);
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $http->requests);
        self::assertSanitized($safe);
    }

    public function testFailedDurableReadIntentPreventsEvenAuthentication(): void
    {
        $calls = 0;
        $http = self::client(self::page());
        $safe = WebhookConfigCommand::run(
            $this->options(),
            $http,
            static function (string $fingerprint, string $directory) use (&$calls): RunLedger {
                return RunLedger::create($fingerprint, $directory, synchronize: static function () use (&$calls): bool {
                    return ++$calls < 4;
                });
            }
        );
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $http->requests);
        self::assertSame(4, $calls);
        self::assertSanitized($safe);
    }

    /** @return iterable<string,array{int}> */
    public static function failedSnapshotSyncs(): iterable
    {
        yield 'file' => [1];
        yield 'directory' => [2];
    }

    #[DataProvider('failedSnapshotSyncs')]
    public function testPrivateFileOrDirectoryDurabilityFailureIsNotReportedAsRetention(int $failure): void
    {
        $calls = 0;
        $safe = WebhookConfigCommand::run(
            $this->options(),
            self::client(self::page()),
            synchronize: static function () use (&$calls, $failure): bool {
                return ++$calls !== $failure;
            }
        );
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['configuration_read_succeeded']);
        self::assertFalse($safe['private_snapshot_retained']);
        $ledger = self::readJson($this->ledgerFile());
        self::assertSame([], $ledger['observations']);
        self::assertSanitized($safe);
    }

    public function testFailedPostRetentionLedgerWriteCannotClaimCompleteObservation(): void
    {
        $calls = 0;
        $safe = WebhookConfigCommand::run(
            $this->options(),
            self::client(self::page()),
            static function (string $fingerprint, string $directory) use (&$calls): RunLedger {
                return RunLedger::create($fingerprint, $directory, synchronize: static function () use (&$calls): bool {
                    return ++$calls !== 6;
                });
            }
        );
        self::assertSame('fail', $safe['result']);
        self::assertSame(6, $calls);
        self::assertFalse($safe['private_snapshot_retained']);
        self::assertFileExists(dirname($this->ledgerFile()) . '/webhook-config.json');
        self::assertSanitized($safe);
    }

    /** @return iterable<string,array{string}> */
    public static function retentionTampering(): iterable
    {
        foreach (['existing', 'symlink', 'temporary', 'permissions', 'git-ancestor', 'identity'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('retentionTampering')]
    public function testRetentionRechecksPrivateIdentityAndNeverOverwrites(string $kind): void
    {
        $http = self::client(self::page());
        $http->intercept = function (RequestInterface $request) use ($kind): void {
            if ($request->getMethod() !== 'GET') {
                return;
            }
            $folder = dirname($this->ledgerFile());
            match ($kind) {
                'existing' => file_put_contents($folder . '/webhook-config.json', 'DO_NOT_REPLACE'),
                'temporary' => file_put_contents($folder . '/webhook-config.json.writing', 'DO_NOT_REPLACE'),
                'symlink' => symlink($this->credentials, $folder . '/webhook-config.json'),
                'permissions' => chmod($folder, 0755),
                'git-ancestor' => mkdir($this->directory . '/.git'),
                'identity' => $this->replaceFolder($folder),
                default => throw new RuntimeException('Unknown fixture tampering.'),
            };
        };
        $safe = WebhookConfigCommand::run($this->options(), $http);
        self::assertSame('fail', $safe['result']);
        self::assertFalse($safe['private_snapshot_retained']);
        self::assertCount(2, $http->requests);
        if ($kind === 'existing') {
            self::assertSame(
                'DO_NOT_REPLACE',
                file_get_contents(dirname($this->ledgerFile()) . '/webhook-config.json')
            );
        }
        self::assertSanitized($safe);
    }

    private function replaceFolder(string $folder): bool
    {
        rename($folder, $folder . '-original');
        return mkdir($folder, 0700);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function badOptions(): iterable
    {
        yield 'production' => ['environment', 'production'];
        yield 'duplicate environment' => ['environment', ['sandbox', 'sandbox']];
        yield 'override account' => ['account-id', 'SYNTHETIC_PRIVATE_ACCOUNT'];
        yield 'override context' => ['context', 'organization'];
        yield 'override root' => ['sdk-root', '/PRIVATE_ROOT'];
        yield 'caller url' => ['url', 'https://SYNTHETIC_PRIVATE_RECEIVER.test'];
        yield 'pagination' => ['page', 'SYNTHETIC_PRIVATE_CURSOR'];
        yield 'relative credentials' => ['credentials-file', 'relative'];
        yield 'stream credentials' => ['credentials-file', 'data:text/plain,SYNTHETIC_PRIVATE'];
    }

    #[DataProvider('badOptions')]
    public function testBadOptionsRejectWithoutAuthentication(string $name, mixed $value): void
    {
        $options = $this->options();
        $options[$name] = $value;
        $http = self::client(self::page());
        $safe = WebhookConfigCommand::run($options, $http);
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $http->requests);
        self::assertSanitized($safe);
    }

    public function testCredentialsInsideGitRejectedBeforeAuthentication(): void
    {
        mkdir($this->directory . '/.git');
        $http = self::client(self::page());
        $safe = WebhookConfigCommand::run($this->options(), $http);
        self::assertSame('fail', $safe['result']);
        self::assertSame([], $http->requests);
        self::assertSanitized($safe);
    }
}
