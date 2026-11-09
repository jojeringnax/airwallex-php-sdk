<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentDisableCleanupCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AlipayConsentDisableCleanupCommandTest extends TestCase
{
    /**
     * @return iterable<string,array{list<string>}> */
    public static function invalidArguments(): iterable
    {
        yield 'empty' => [[]];
        yield 'production' => [['--environment=production', '--disable-own-alipay-hk-consent-once']];
        yield 'valued flag' => [['--disable-own-alipay-hk-consent-once=true']];
        yield 'duplicate flag' => [['--disable-own-alipay-hk-consent-once', '--disable-own-alipay-hk-consent-once']];
        yield 'duplicate environment' => [['--environment=sandbox', '--environment=sandbox']];
        yield 'arbitrary target' => [['--consent-id=PRIVATE_TARGET']];
        yield 'GET-only flag' => [['--read-own-alipay-hk-consent']];
        yield 'missing private paths' => [['--environment=sandbox', '--disable-own-alipay-hk-consent-once']];
        yield 'nonexistent paths' => [['--environment=sandbox', '--disable-own-alipay-hk-consent-once',
            '--credentials-file=/nonexistent-cleanup-credential', '--state-dir=/nonexistent-cleanup-state',
            '--prior-ledger=/nonexistent-cleanup-prior']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('invalidArguments')]
    public function testFixedCliRejectsBeforeAuthenticationAndAlwaysPreservesPendingDisposition(array $arguments): void
    {
        [$exit, $raw, $errors] = self::child([PHP_BINARY, dirname(__DIR__, 4)
            . '/tools/alipay-consent-disable-cleanup.php', ...$arguments]);
        self::assertSame(1, $exit);
        self::assertSame('', $errors);
        $safe = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($safe);
        self::assertSame('fail', $safe['result']);
        self::assertTrue($safe['historical_verification_or_financial_disposition_unresolved']);
        self::assertFalse($safe['financial_mutation_sent']);
        self::assertStringNotContainsString('PRIVATE_TARGET', $raw);
    }

    public function testInvalidOriginalIntakeAndSymlinkClaimsSendNothing(): void
    {
        $f = AlipayConsentDisableCleanupTest::fixture();
        try {
            $original = file_get_contents($f['prior']);
            self::assertIsString($original);
            file_put_contents($f['prior'], '{}');
            $r = AlipayConsentDisableCleanupCommand::run(AlipayConsentDisableCleanupTest::options($f), $f['provider']);
            self::assertSame('prior_validation', $r['phase']);
            self::assertSame([], $f['provider']->requests);
            file_put_contents($f['prior'], $original);
            $reflection = new \ReflectionMethod(
                \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliation::class,
                'load'
            );
            $configuration = \Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration::load(
                'sandbox',
                $f['credentials']
            );
            $session = new \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession(
                $configuration,
                'account',
                $f['provider']
            );
            $prior = $reflection->invoke(null, $f['prior'], $f['state'], $session);
            self::assertInstanceOf(
                \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliation::class,
                $prior
            );
            symlink($f['prior'], $prior->cleanupClaimPath());
            $r = AlipayConsentDisableCleanupCommand::run(AlipayConsentDisableCleanupTest::options($f), $f['provider']);
            self::assertSame('claim_precheck', $r['phase']);
            self::assertSame([], $f['provider']->requests);
            self::assertSame($original, file_get_contents($f['prior']));
        } finally {
            AlipayConsentReconciliationTest::remove($f['base']);
        }
    }

    /** Keep only the three known upstream notices observable inside this synthetic child protocol. */
    private static function childDiagnosticCollector(): string
    {
        return <<<'PHP'
        $deprecations = [];
        set_error_handler(static function (
            int $severity, string $message, string $file
        ) use (&$deprecations, $argv): bool {
            if ($severity !== E_DEPRECATED
                || $file !== dirname($argv[1]) . '/psr/http-factory/src/UploadedFileFactoryInterface.php') {
                return false;
            }
            $prefix = 'Psr\\Http\\Message\\UploadedFileFactoryInterface::createUploadedFile(): '
                . 'Implicitly marking parameter $';
            $suffix = ' as nullable is deprecated, the explicit nullable type must be used instead';
            foreach (['size', 'clientFilename', 'clientMediaType'] as $parameter) {
                if ($message === $prefix . $parameter . $suffix && !in_array($parameter, $deprecations, true)) {
                    $deprecations[] = $parameter;
                    return true;
                }
            }
            return false;
        });
        PHP;
    }

    public function testChildDiagnosticsAcceptOnlyTheThreeExactUpstreamNoticesOnce(): void
    {
        $code = self::childDiagnosticCollector() . <<<'PHP'
        $handler = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        $file = dirname($argv[1]) . '/psr/http-factory/src/UploadedFileFactoryInterface.php';
        $message = 'Psr\\Http\\Message\\UploadedFileFactoryInterface::createUploadedFile(): '
            . 'Implicitly marking parameter $size as nullable is deprecated, '
            . 'the explicit nullable type must be used instead';
        $checks = [
            $handler(E_WARNING, $message, $file),
            $handler(E_DEPRECATED, $message, '/other/dependency.php'),
            $handler(E_DEPRECATED, 'unexpected deprecation', $file),
            $handler(E_DEPRECATED, $message, $file),
            $handler(E_DEPRECATED, $message, $file),
        ];
        restore_error_handler();
        echo json_encode([$checks, $deprecations], JSON_THROW_ON_ERROR);
        PHP;
        [$exit, $raw, $errors] = self::child([
            PHP_BINARY, '-r', $code, dirname(__DIR__, 4) . '/vendor/autoload.php',
        ]);
        self::assertSame(0, $exit);
        self::assertSame('', $errors);
        self::assertSame(
            [[false, false, false, true, false], ['size']],
            json_decode($raw, true, flags: JSON_THROW_ON_ERROR)
        );
    }

    public function testTwoFreshProcessesRaceOneImmutableOriginalClaimAndOnlyOneCanInvokeDisable(): void
    {
        $f = AlipayConsentDisableCleanupTest::fixture();
        $processes = [];
        $allPipes = [];
        $before = file_get_contents($f['prior']);
        try {
            // Only synthetic fixture data is copied for the child provider; the helper writes no raw snapshot file.
            $data = $f;
            unset($data['provider']);
            $data['resources'] = $f['provider']->resources;
            $fixture = $f['base'] . '/child-fixture.json';
            file_put_contents($fixture, json_encode($data, JSON_THROW_ON_ERROR));
            chmod($fixture, 0600);
            $code = self::childDiagnosticCollector() . <<<'CHILD'
            require $argv[1];
            $f = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
            $index = $argv[3];
            $provider = new class ($f, $index) implements \Psr\Http\Client\ClientInterface {
                public function __construct(private array $f, private string $index) {}
                private bool $initial = true;
                private bool $disabled = false;
                public function sendRequest(
                    \Psr\Http\Message\RequestInterface $request
                ): \Psr\Http\Message\ResponseInterface {
                    $path = $request->getUri()->getPath();
                    if (str_ends_with($path, '/login')) {
                        return new \GuzzleHttp\Psr7\Response(201, ['Content-Type'=>'application/json'],
                            '{"token":"synthetic","expires_at":"2100-01-01T00:00:00Z"}');
                    }
                    $isConsent = str_contains($path, '/payment_consents/');
                    if ($isConsent && $this->initial) {
                        $this->initial = false;
                        file_put_contents($this->f['base'] . '/ready-' . $this->index, 'ready');
                        $until = microtime(true) + 20;
                        while (count(glob($this->f['base'] . '/ready-*')) < 2) {
                            if (microtime(true) > $until) { throw new \RuntimeException('fixture barrier expired'); }
                            usleep(10000);
                        }
                    }
                    if (str_ends_with($path, '/disable')) {
                        file_put_contents($this->f['base'] . '/disable-' . $this->index, 'one');
                        $this->disabled = true;
                    }
                    $object = $this->f['resources'][$this->f[$isConsent ? 'consent' : 'customer']];
                    if ($isConsent && $this->disabled) { $object['status'] = 'DISABLED'; }
                    return new \GuzzleHttp\Psr7\Response(200, ['Content-Type'=>'application/json'],
                        (new \Jojeringnax\Airwallex\Internal\Json\JsonCodec())->encode($object));
                }
            };
            $r = \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentDisableCleanupCommand::run([
                'environment'=>'sandbox', 'credentials-file'=>$f['credentials'], 'state-dir'=>$f['state'],
                'prior-ledger'=>$f['prior'], 'disable-own-alipay-hk-consent-once'=>true], $provider);
            restore_error_handler();
            echo json_encode(['result' => $r, 'dependency_deprecations' => $deprecations], JSON_THROW_ON_ERROR);
            CHILD;
            foreach (['one', 'two'] as $index) {
                $process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__, 4) . '/vendor/autoload.php',
                    $fixture, $index], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $processes[] = $process;
                $allPipes[] = $pipes;
            }
            $results = [];
            foreach ($processes as $i => $process) {
                [$input, $output, $errors] = self::pipes($allPipes[$i]);
                fclose($input);
                stream_set_timeout($output, 60);
                stream_set_timeout($errors, 5);
                $raw = stream_get_contents($output, 65537);
                self::assertIsString($raw);
                self::assertLessThanOrEqual(65536, strlen($raw));
                self::assertSame('', stream_get_contents($errors, 4097));
                self::assertSame(0, proc_close($process));
                $result = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                self::assertSame(['result', 'dependency_deprecations'], array_keys($result));
                self::assertContains(
                    $result['dependency_deprecations'],
                    [[], ['size', 'clientFilename', 'clientMediaType']]
                );
                self::assertIsArray($result['result']);
                $results[] = $result['result'];
            }
            self::assertSame(1, array_sum(array_column($results, 'disable_invocations')));
            self::assertSame(['fail', 'pending'], (static function (array $results): array {
                $values = array_column($results, 'result');
                sort($values);
                return $values;
            })($results));
            self::assertCount(1, glob($f['base'] . '/disable-*') ?: []);
            $claims = glob($f['state'] . '/.consent-disable-*.claim') ?: [];
            self::assertCount(1, $claims);
            self::assertLessThanOrEqual(4096, filesize($claims[0]));
            self::assertSame($before, file_get_contents($f['prior']));
            self::assertStringNotContainsString('PRIVATE', json_encode($results, JSON_THROW_ON_ERROR));
        } finally {
            foreach ($allPipes as $pipes) {
                self::closePipes($pipes);
            }
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            AlipayConsentReconciliationTest::remove($f['base']);
        }
    }


    public function testNativeLocalHttpReceivesTheExactPreparedDisableBytesAndOnlyTheFiniteRoles(): void
    {
        $f = AlipayConsentDisableCleanupTest::fixture();
        $process = null;
        $pipes = [];
        try {
            $objects = [
                ['token' => 'synthetic-native-token', 'expires_at' => '2100-01-01T00:00:00Z'],
                $f['provider']->resources[$f['customer']], $f['provider']->resources[$f['consent']],
                $f['provider']->resources[$f['customer']], $f['provider']->resources[$f['consent']],
                array_replace($f['provider']->resources[$f['consent']], ['status' => 'DISABLED']),
                array_replace($f['provider']->resources[$f['consent']], ['status' => 'DISABLED']),
            ];
            $code = <<<'CHILD'
            $objects = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if ($server === false) { exit(1); }
            echo stream_socket_get_name($server, false) . "\n"; fflush(STDOUT);
            $facts = [];
            foreach ($objects as $i => $object) {
                $c = stream_socket_accept($server, 15);
                if ($c === false) { exit(2); }
                stream_set_timeout($c, 5);
                $headers = '';
                while (!str_ends_with($headers, "\r\n\r\n") && strlen($headers) <= 16384) {
                    $part = fread($c, 1); if ($part === false || $part === '') { exit(3); }
                    $headers .= $part;
                }
                preg_match('/\r\nContent-Length: ([0-9]+)\r\n/i', $headers, $m);
                $length = (int) ($m[1] ?? 0); $raw = '';
                while (strlen($raw) < $length) {
                    $part = fread($c, $length - strlen($raw));
                    if ($part === false || $part === '') { exit(4); } $raw .= $part;
                }
                $facts[] = ['method' => explode(' ', $headers, 2)[0], 'body_sha256' => hash('sha256', $raw),
                    'cookie_seen' => stripos($headers, "\r\nCookie:") !== false,
                    'host_seen' => stripos($headers, "\r\nHost: api.sandbox.airwallex.com\r\n") !== false];
                $body = json_encode($object, JSON_THROW_ON_ERROR);
                fwrite($c, 'HTTP/1.1 ' . ($i === 0 ? '201 Created' : '200 OK')
                    . "\r\nContent-Type: application/json\r\n"
                    . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
                fclose($c);
            }
            fclose($server); echo json_encode($facts, JSON_THROW_ON_ERROR);
            CHILD;
            $process = proc_open(
                [PHP_BINARY, '-r', $code, json_encode($objects, JSON_THROW_ON_ERROR)],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($process);
            [$input, $output, $errors] = self::pipes($pipes);
            fclose($input);
            stream_set_timeout($output, 30);
            $address = fgets($output, 128);
            self::assertIsString($address);
            $address = trim($address);
            self::assertMatchesRegularExpression('/^127\.0\.0\.1:[0-9]+$/D', $address);
            $handler = new \GuzzleHttp\Handler\StreamHandler();
            $packets = [];
            $nativeOptions = [];
            $client = new \GuzzleHttp\Client(['handler' => static function (
                \Psr\Http\Message\RequestInterface $request,
                array $options
            ) use (
                $handler,
                $address,
                &$packets,
                &$nativeOptions
): \GuzzleHttp\Promise\PromiseInterface {
                $packets[] = $request;
                $nativeOptions[] = $options;
                // Fixture-only address rewrite; no production HTTP target is exposed.
                return $handler(
                    $request->withUri(new \GuzzleHttp\Psr7\Uri('http://' . $address . '/fixture'), true),
                    $options
                );
            }]);
            $r = AlipayConsentDisableCleanupCommand::run(AlipayConsentDisableCleanupTest::options($f), $client);
            self::assertSame('pass', $r['cleanup_observation_result'], json_encode($r, JSON_THROW_ON_ERROR));
            $received = stream_get_contents($output, 65537);
            self::assertIsString($received);
            $facts = json_decode($received, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($facts);
            self::assertCount(7, $facts);
            self::assertSame(['POST', 'GET', 'GET', 'GET', 'GET', 'POST', 'GET'], array_column($facts, 'method'));
            self::assertSame(array_fill(0, 7, false), array_column($facts, 'cookie_seen'));
            self::assertSame(array_fill(0, 7, true), array_column($facts, 'host_seen'));
            self::assertSame('', stream_get_contents($errors, 4097));
            self::assertSame(0, proc_close($process));
            foreach ($nativeOptions as $options) {
                self::assertTrue($options['verify']);
                self::assertSame('', $options['proxy']);
                foreach (['allow_redirects', 'cookies', 'http_errors', 'decode_content'] as $name) {
                    self::assertFalse($options[$name]);
                }
                self::assertGreaterThan(0, $options['timeout']);
                self::assertLessThanOrEqual(10, $options['timeout']);
                self::assertLessThanOrEqual(5, $options['read_timeout']);
            }
            $journals = array_values(array_filter(
                glob($f['state'] . '/*/ledger.json') ?: [],
                static fn (string $p): bool => $p !== $f['prior']
            ));
            self::assertCount(1, $journals);
            $state = json_decode((string) file_get_contents($journals[0]), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($facts[5]);
            self::assertIsArray($state);
            self::assertIsArray($state['observations']);
            self::assertIsArray($state['observations']['consent_cleanup_disable_prepared']);
            self::assertIsArray($state['observations']['consent_cleanup_disable_possible_invocation']);
            $bodyHash = $facts[5]['body_sha256'];
            self::assertSame($state['observations']['consent_cleanup_disable_prepared']['body_sha256'], $bodyHash);
            self::assertSame(
                $state['observations']['consent_cleanup_disable_possible_invocation']['body_sha256'],
                $bodyHash
            );
            self::assertSame(hash('sha256', (string) self::packet($packets, 5)->getBody()), $bodyHash);
            self::assertSame([], $state['resources']);
            self::assertStringNotContainsString('synthetic-native-token', (string) file_get_contents($journals[0]));
        } finally {
            self::closePipes($pipes);
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            AlipayConsentReconciliationTest::remove($f['base']);
        }
    }

    private static function packet(mixed $packets, int $index): \Psr\Http\Message\RequestInterface
    {
        self::assertIsArray($packets);
        $packet = $packets[$index] ?? null;
        self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $packet);
        return $packet;
    }

    public function testNativePacketWitnessRejectsMissingOrWrongPacket(): void
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', 'https://synthetic.invalid/');
        self::assertSame($request, self::packet([5 => $request], 5));
        foreach ([null, [], [0 => $request], [5 => new \stdClass()]] as $bad) {
            $rejected = false;
            try {
                self::packet($bad, 5);
            } catch (\PHPUnit\Framework\AssertionFailedError) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'A native packet must be present with its actual request type.');
        }
    }

    /**
     * @param list<string> $command
     * @return array{int,string,string} */
    private static function child(array $command): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            [$input, $output, $errors] = self::pipes($pipes);
            fclose($input);
            stream_set_timeout($output, 10);
            stream_set_timeout($errors, 10);
            $raw = stream_get_contents($output, 65537);
            $error = stream_get_contents($errors, 4097);
            self::assertIsString($raw);
            self::assertIsString($error);
            self::assertLessThanOrEqual(65536, strlen($raw));
            self::assertLessThanOrEqual(4096, strlen($error));
            return [proc_close($process), $raw, $error];
        } finally {
            self::closePipes($pipes);
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    /**
     * @return array{resource,resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        self::assertIsArray($pipes);
        $a = $pipes[0] ?? null;
        $b = $pipes[1] ?? null;
        $c = $pipes[2] ?? null;
        self::assertIsResource($a);
        self::assertIsResource($b);
        self::assertIsResource($c);
        return [$a, $b, $c];
    }

    private static function closePipes(mixed $pipes): void
    {
        if (is_array($pipes)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }
}
