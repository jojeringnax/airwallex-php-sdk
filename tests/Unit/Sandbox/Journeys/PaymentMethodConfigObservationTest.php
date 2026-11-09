<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\PaymentMethodConfigCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\PaymentMethodConfigObservation;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\PaymentMethodConfigTransport;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\WebhookConfigObservation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use stdClass;

final class PaymentMethodConfigObservationTest extends TestCase
{
    /** @return array{base:string,state:string,credentials:string} */
    public static function fixture(
        string $client = 'SYNTHETIC_PRIVATE_CLIENT',
        string $key = 'SYNTHETIC_PRIVATE_KEY'
    ): array {
        $temporary = realpath(sys_get_temp_dir());
        if ($temporary === false) {
            throw new RuntimeException('The synthetic temporary directory is unavailable.');
        }
        $base = $temporary . '/payment-config-' . bin2hex(random_bytes(8));
        mkdir($base, 0700);
        $state = $base . '/state';
        mkdir($state, 0700);
        $credentials = $base . '/sandbox.env';
        file_put_contents($credentials, 'AIRWALLEX_CLIENT_ID=' . $client . "\n"
            . 'AIRWALLEX_SANDBOX_API_KEY_MASTER=' . $key . "\nAIRWALLEX_ACCOUNT_ID=SYNTHETIC_PRIVATE_ACCOUNT\n");
        chmod($credentials, 0600);
        return compact('base', 'state', 'credentials');
    }

    public static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /** @return array<string,mixed> */
    public static function row(): array
    {
        return ['name' => 'alipayhk', 'active' => true, 'transaction_mode' => 'recurring',
            'flows' => ['mobile_web'], 'transaction_currencies' => ['HKD']];
    }

    /** @param Closure(RequestInterface):ResponseInterface $respond */
    public static function client(Closure $respond): ClientInterface
    {
        return new class ($respond) implements ClientInterface {
            /** @param Closure(RequestInterface):ResponseInterface $respond */
            public function __construct(private readonly Closure $respond)
            {
            }
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->respond)($request);
            }
        };
    }

    public static function response(string $raw, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], $raw);
    }

    public static function login(string $expiry = '2030-01-01T00:00:00Z'): ResponseInterface
    {
        return self::response(
            json_encode(['token' => 'SYNTHETIC_PRIVATE_TOKEN', 'expires_at' => $expiry], JSON_THROW_ON_ERROR),
            201
        );
    }

    /**
     * @param array{base:string,state:string,credentials:string} $fixture
     * @return array<string,mixed>
     */
    public static function options(array $fixture): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $fixture['credentials'],
            'state-dir' => $fixture['state'], 'read-one-alipay-hk-recurring-config' => true];
    }

    public function testCurrentPageProofAndPrivateSnapshotAreDistinctFromUseAuthority(): void
    {
        $f = self::fixture();
        $requests = [];
        $raw = json_encode(['has_more' => false, 'items' => [self::row()],
            'future_field' => 'SYNTHETIC_PRIVATE_UNKNOWN'], JSON_THROW_ON_ERROR);
        try {
            $client = self::client(static function (RequestInterface $request) use (
                &$requests,
                $raw
            ): ResponseInterface {
                $requests[] = $request;
                return count($requests) === 1 ? self::login() : self::response($raw);
            });
            $result = PaymentMethodConfigCommand::run(self::options($f), $client);
            self::assertSame('pass', $result['result']);
            self::assertSame('observed', $result['advertisement_state']);
            self::assertSame('observed', $result['literal_hkd_state']);
            self::assertTrue($result['filtered_page_terminal']);
            self::assertSame(1, $result['returned_count']);
            self::assertSame(2, $result['http_invocations']);
            self::assertSame(1, $result['login_attempts']);
            self::assertSame(1, $result['configuration_get_attempts']);
            self::assertTrue($result['private_snapshot_retained']);
            foreach (
                ['native_pci_proven', 'verify_permission_proven', 'eligibility_proven',
                'financial_permission', 'global_absence_proven', 'account_owner_echo_verified'] as $key
            ) {
                self::assertFalse($result[$key]);
            }
            self::assertSame('SYNTHETIC_PRIVATE_ACCOUNT', $requests[0]->getHeaderLine('x-login-as'));
            self::assertSame(PaymentMethodConfigTransport::TARGET, (string) $requests[1]->getUri());
            self::assertSame('Bearer SYNTHETIC_PRIVATE_TOKEN', $requests[1]->getHeaderLine('Authorization'));
            self::assertFalse($requests[1]->hasHeader('x-login-as'));
            $encoded = json_encode($result, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $encoded);
            self::assertLessThan(8192, strlen($encoded));
            $ledgers = glob($f['state'] . '/sdk-validation-*/ledger.json');
            self::assertIsArray($ledgers);
            self::assertCount(1, $ledgers);
            $journal = WebhookConfigObservation::nativeObject((string) file_get_contents($ledgers[0]));
            self::assertSame([], $journal->resources);
            self::assertInstanceOf(stdClass::class, $journal->observations);
            $observations = get_object_vars($journal->observations);
            self::assertInstanceOf(stdClass::class, $observations['payment_method_config_snapshot']);
            self::assertInstanceOf(stdClass::class, $observations['payment_config_page_prepared']);
            self::assertSame($raw, $observations['payment_method_config_snapshot']->private_json);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE_TOKEN', (string) file_get_contents($ledgers[0]));
            self::assertSame(hash('sha256', ''), $observations['payment_config_page_prepared']->body_sha256);
        } finally {
            self::remove($f['base']);
        }
    }

    /** @return iterable<string,array{string,string,string,?int}> */
    public static function pageCases(): iterable
    {
        yield 'empty is measured' => ['{"items":[],"has_more":false}', 'pass', 'not_observed_on_page', 0];
        yield 'more pages not followed' => ['{"items":[],"has_more":true}', 'pass', 'not_observed_on_page', 0];
        yield 'items missing' => ['{"has_more":false}', 'pending', 'unavailable', null];
        yield 'items null' => ['{"items":null,"has_more":false}', 'pending', 'unavailable', null];
        yield 'pagination missing' => ['{"items":[]}', 'pending', 'not_observed_on_page', 0];
        yield 'pagination null' => ['{"items":[],"has_more":null}', 'pending', 'not_observed_on_page', 0];
        yield 'unknown name' => ['{"items":[{}],"has_more":false}', 'pass', 'unavailable', 1];
        yield 'target optional fields absent' => [
            '{"items":[{"name":"alipayhk"}],"has_more":false}', 'pass', 'unavailable', 1
        ];
        yield 'empty flows not expanded' => [
            '{"items":[{"name":"alipayhk","active":true,"transaction_mode":"recurring","flows":[]}],'
                . '"has_more":false}', 'pass', 'not_observed_on_page', 1
        ];
    }

    #[DataProvider('pageCases')]
    public function testMissingOptionalEvidenceNeverBecomesAFalseInventory(
        string $raw,
        string $result,
        string $target,
        ?int $count
    ): void {
        $facts = PaymentMethodConfigObservation::pageFacts(WebhookConfigObservation::nativeObject($raw));
        self::assertSame($result, $facts['result']);
        self::assertSame($target, $facts['advertisement_state']);
        if ($count === null) {
            self::assertArrayNotHasKey('returned_count', $facts);
        } else {
            self::assertSame($count, $facts['returned_count']);
        }
        if (!str_contains($raw, '"has_more":false') && !str_contains($raw, '"has_more":true')) {
            self::assertArrayNotHasKey('has_more', $facts);
            self::assertArrayNotHasKey('filtered_page_terminal', $facts);
        }
    }

    public function testSameRowProofWildcardAndDuplicateRowsRemainSeparate(): void
    {
        $first = self::row();
        $first['flows'] = ['mobile_app'];
        $second = self::row();
        $second['name'] = 'unknown_private_method';
        $facts = PaymentMethodConfigObservation::pageFacts((object) [
            'items' => [(object) $first, (object) $second], 'has_more' => false
        ]);
        self::assertSame('not_observed_on_page', $facts['advertisement_state']);
        $wildcard = self::row();
        $wildcard['transaction_currencies'] = ['*'];
        $facts = PaymentMethodConfigObservation::pageFacts((object) [
            'items' => [(object) $wildcard, (object) $wildcard], 'has_more' => false
        ]);
        self::assertSame('observed', $facts['advertisement_state']);
        self::assertSame(2, $facts['qualifying_rows']);
        self::assertSame('not_observed_on_page', $facts['literal_hkd_state']);
        self::assertSame('observed', $facts['wildcard_state']);
        unset($wildcard['transaction_currencies']);
        $facts = PaymentMethodConfigObservation::pageFacts((object) [
            'items' => [(object) $wildcard], 'has_more' => false
        ]);
        self::assertSame('unavailable', $facts['literal_hkd_state']);
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function sparseCurrencyRows(): iterable
    {
        $row = self::row();
        unset($row['name']);
        yield 'unknown name' => [$row, 'unavailable'];
        $row = self::row();
        unset($row['transaction_mode']);
        yield 'incomplete target' => [$row, 'unavailable'];
        $row = self::row();
        unset($row['transaction_currencies']);
        yield 'unknown currency' => [$row, 'unavailable'];
        yield 'known non-target even with missing fields' => [['name' => 'other'], 'not_observed_on_page'];
    }

    /** @param array<string,mixed> $sparse */
    #[DataProvider('sparseCurrencyRows')]
    public function testMixedSparsePageCannotEarnNegativeCurrencies(array $sparse, string $negative): void
    {
        foreach ([['USD'], ['HKD'], ['*'], ['HKD', '*']] as $currencies) {
            $known = self::row();
            $known['transaction_currencies'] = $currencies;
            $facts = PaymentMethodConfigObservation::pageFacts((object) [
                'items' => [(object) $known, (object) $sparse], 'has_more' => false
            ]);
            self::assertSame('pass', $facts['result']);
            self::assertSame('observed', $facts['advertisement_state']);
            self::assertSame(in_array('HKD', $currencies, true) ? 'observed' : $negative, $facts['literal_hkd_state']);
            self::assertSame(in_array('*', $currencies, true) ? 'observed' : $negative, $facts['wildcard_state']);
            self::assertTrue($facts['filtered_page_terminal']);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalidPages(): iterable
    {
        foreach (
            ['{"items":{},"has_more":false}', '{"items":[],"has_more":"false"}',
            '{"items":[1],"has_more":false}', '{"items":[{"name":123}],"has_more":false}',
            '{"items":[{"name":"alipayhk","active":"true"}],"has_more":false}',
            '{"items":[{"flows":[1]}],"has_more":false}', '{"items":[{"flows":{}}],"has_more":false}',
            '{"items":[{"transaction_currencies":[1]}],"has_more":false}',
            '{"items":[],"it\\u0065ms":[]}', '{"items":[{"name":"x","n\\u0061me":"y"}]}'] as $n => $raw
        ) {
            yield (string) $n => [$raw];
        }
    }

    #[DataProvider('invalidPages')]
    public function testInvalidNativeTypesOrDuplicateDecodedKeysCannotProduceFacts(string $raw): void
    {
        $this->expectException(\Throwable::class);
        PaymentMethodConfigObservation::pageFacts(WebhookConfigObservation::nativeObject($raw));
    }

    /** @return iterable<string,array{string}> */
    public static function oversizedPages(): iterable
    {
        yield '101 items' => [json_encode(['items' => array_fill(0, 101, new \stdClass()),
            'has_more' => false], JSON_THROW_ON_ERROR)];
        foreach (
            ['name' => str_repeat('x', 129), 'transaction_mode' => str_repeat('x', 33),
            'flows' => array_fill(0, 17, 'x'), 'transaction_currencies' => array_fill(0, 257, 'x'),
            'long flow' => [str_repeat('x', 65)]] as $field => $value
        ) {
            $label = $field;
            $field = $field === 'long flow' ? 'flows' : $field;
            yield $label => [json_encode(['items' => [(object) [$field => $value]],
                'has_more' => false], JSON_THROW_ON_ERROR)];
        }
    }

    #[DataProvider('oversizedPages')]
    public function testBoundsCannotBeTurnedIntoAFalsePageFact(string $raw): void
    {
        $this->expectException(\Throwable::class);
        PaymentMethodConfigObservation::pageFacts(WebhookConfigObservation::nativeObject($raw));
    }

    public function testUnknownRowValuesRemainPrivateAtMaximumPageSize(): void
    {
        $row = (object) ['name' => 'SYNTHETIC_PRIVATE_METHOD', 'flows' => ['SYNTHETIC_PRIVATE_FLOW']];
        $facts = PaymentMethodConfigObservation::pageFacts((object) [
            'items' => array_fill(0, 100, $row), 'has_more' => true
        ]);
        self::assertSame(100, $facts['returned_count']);
        self::assertFalse($facts['filtered_page_terminal']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($facts, JSON_THROW_ON_ERROR));
    }

    public function testReadIntentsDurabilityFailurePreventsAuthentication(): void
    {
        $f = self::fixture();
        $requests = 0;
        try {
            $client = self::client(static function () use (&$requests): ResponseInterface {
                ++$requests;
                return self::login();
            });
            $ledgers = static function (string $context, string $directory): RunLedger {
                return RunLedger::create($context, $directory, synchronize: static function (
                    $stream
                ) use ($directory): bool {
                    foreach (glob($directory . '/sdk-validation-*/ledger.json.writing') ?: [] as $path) {
                        $raw = file_get_contents($path);
                        if (!is_string($raw)) {
                            return false;
                        }
                        $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                        if (
                            is_array($state) && is_array($state['actions'] ?? null)
                            && isset($state['actions']['payment_method_config_login'])
                        ) {
                            return false;
                        }
                    }
                    return fsync($stream);
                });
            };
            $result = PaymentMethodConfigCommand::run(self::options($f), $client, $ledgers);
            self::assertSame('fail', $result['result']);
            self::assertSame(0, $requests);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
        } finally {
            self::remove($f['base']);
        }
    }
}
