<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HostedBrowserTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-browser-bridge-fixture-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/module', 0700);
        file_put_contents($this->directory . '/module/index.js', 'module.exports = {};');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                unlink($path . '/index.js');
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($this->directory);
    }

    /** @return iterable<string,array{mixed,bool}> */
    public static function candidates(): iterable
    {
        yield 'official shared example candidate' => ['https://checkout.airwallex.com/pay?s=PRIVATE_SESSION', true];
        yield 'Sandbox named candidate only' => ['https://checkout.sandbox.airwallex.com/pay?s=PRIVATE_SESSION', true];
        yield 'plain HTTP' => ['http://checkout.airwallex.com/pay?s=PRIVATE_SESSION', false];
        yield 'userinfo' => ['https://user:pass@checkout.airwallex.com/pay?s=PRIVATE_SESSION', false];
        yield 'lookalike' => ['https://checkout.airwallex.com.evil.test/pay?s=PRIVATE_SESSION', false];
        yield 'alternate port' => ['https://checkout.airwallex.com:444/pay?s=PRIVATE_SESSION', false];
        yield 'missing token' => ['https://checkout.airwallex.com/pay', false];
        yield 'array token' => ['https://checkout.airwallex.com/pay?s[]=PRIVATE_SESSION', false];
        yield 'control input' => ["https://checkout.airwallex.com/pay?s=PRIVATE_SESSION\n", false];
        yield 'backslash confusion' => ['https://checkout.airwallex.com\\evil.test/pay?s=x', false];
        yield 'non-string' => [[], false];
        yield 'oversized' => ['https://checkout.airwallex.com/pay?s=' . str_repeat('x', 16385), false];
    }

    #[DataProvider('candidates')]
    public function testOnlyRecognizedCandidatesAreAccepted(mixed $value, bool $expected): void
    {
        self::assertSame($expected, HostedBrowser::candidate($value));
    }

    public function testPrivateUrlTravelsOnlyThroughStdinAndChildEnvironmentIsRestricted(): void
    {
        $script = <<<'PHP'
        $contents = file_get_contents('php://stdin');
        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (str_contains(json_encode($argv), 'PRIVATE_SESSION') || getenv('AIRWALLEX_BRIDGE_SENTINEL') !== false
            || ($data['url'] ?? null) !== 'https://checkout.airwallex.com/pay?s=PRIVATE_SESSION') {
            exit(3);
        }
        echo '{"result":"blocked","reason":"financial_sandbox_boundary_unverified","card_entry_enabled":false}';
        PHP;
        $bridge = $this->bridge($script);
        putenv('AIRWALLEX_BRIDGE_SENTINEL=PRIVATE_ENVIRONMENT');
        try {
            $safe = $bridge->inspect('https://checkout.airwallex.com/pay?s=PRIVATE_SESSION');
        } finally {
            putenv('AIRWALLEX_BRIDGE_SENTINEL');
        }
        self::assertSame('blocked', $safe['result']);
        self::assertSame(false, $safe['card_entry_enabled']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
        ob_start();
        var_dump($bridge);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString($this->directory, $debug);
        $this->expectException(RuntimeException::class);
        serialize($bridge);
    }

    /** @return iterable<string,array{string}> */
    public static function unsafeChildren(): iterable
    {
        yield 'raw output' => ['echo "PRIVATE_SESSION";'];
        yield 'stderr' => ['fwrite(STDERR, "PRIVATE_SESSION"); echo "{}";'];
        yield 'excess output' => ['echo str_repeat("x", 9000);'];
        yield 'fake passed journey' => ['echo \'{"result":"pass","reason":"browser_preflight_failed",'
            . '"card_entry_enabled":false}\';'];
        yield 'card entry enabled' => ['echo \'{"result":"blocked","reason":"financial_sandbox_boundary_unverified",'
            . '"card_entry_enabled":true}\';'];
        yield 'nonzero exit with safe-looking output' => ['echo \'{"result":"blocked",'
            . '"reason":"financial_sandbox_boundary_unverified","card_entry_enabled":false}\'; exit(3);'];
    }

    #[DataProvider('unsafeChildren')]
    public function testUnsafeChildOutputNeverBecomesEvidence(string $script): void
    {
        $bridge = $this->bridge($script);
        $failure = null;
        try {
            $bridge->inspect('https://checkout.airwallex.com/pay?s=PRIVATE_SESSION');
        } catch (\Throwable $error) {
            $failure = $error;
            self::assertStringNotContainsString('PRIVATE_', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertNotNull($failure, 'Unsafe child output must reject.');
    }

    public function testOnlyFixedSafeFieldsSurviveSanitization(): void
    {
        $safe = HostedBrowser::sanitize(['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false, 'blocked_requests' => 3, 'blocked_websockets' => -1,
            'returns_intercepted' => 'PRIVATE_SESSION', 'browser_version' => '157.0.8103.38',
            'message' => 'PRIVATE_SESSION', 'url' => 'PRIVATE_SESSION', 'headers' => ['PRIVATE_SESSION']]);
        self::assertSame(3, $safe['blocked_requests']);
        self::assertSame('157.0.8103.38', $safe['browser_version']);
        self::assertArrayNotHasKey('blocked_websockets', $safe);
        self::assertArrayNotHasKey('returns_intercepted', $safe);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testCandidateOriginIsAnAllowlistedObservationSeparateFromFinancialVerification(): void
    {
        $base = ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false, 'sandbox_origin_verified' => false];
        foreach (['shared', 'sandbox'] as $origin) {
            $safe = HostedBrowser::sanitize($base + ['candidate_origin' => $origin,
                'verified_static_assets' => 3, 'integrity_rejections' => 0]);
            self::assertSame($origin, $safe['candidate_origin']);
            self::assertFalse($safe['sandbox_origin_verified']);
            self::assertFalse($safe['card_entry_enabled']);
            self::assertSame(3, $safe['verified_static_assets']);
        }
        $safe = HostedBrowser::sanitize($base + ['candidate_origin' => 'PRIVATE_HOST']);
        self::assertArrayNotHasKey('candidate_origin', $safe);
        self::assertStringNotContainsString('PRIVATE_HOST', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testFalseCreationFlagCannotBecomeOptInAtTheCliBoundary(): void
    {
        $process = proc_open([
            PHP_BINARY, dirname(__DIR__, 4) . '/tools/hosted-setup-preflight.php',
            '--environment=sandbox', '--credentials-file=/absent-synthetic-credentials',
            '--state-dir=' . $this->directory, '--node-bin=' . PHP_BINARY,
            '--playwright-module=' . $this->directory . '/module', '--create-one-hosted-setup=false',
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        [$output, $errors] = self::outputPipes($pipes);
        $contents = stream_get_contents($output);
        $diagnostics = stream_get_contents($errors);
        fclose($output);
        fclose($errors);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $diagnostics);
        self::assertIsString($contents);
        self::assertSame([
            'scenario' => 'hosted-setup-preflight', 'api_version' => '2026-08-21',
            'result' => 'fail', 'phase' => 'configuration',
        ], json_decode($contents, true, flags: JSON_THROW_ON_ERROR));
    }

    private function bridge(string $body): HostedBrowser
    {
        $node = $this->directory . '/fixture-node';
        file_put_contents($node, '#!' . PHP_BINARY . "\n<?php\n" . $body);
        chmod($node, 0700);
        return new HostedBrowser($node, $this->directory . '/module');
    }

    /** @return array{resource,resource} */
    private static function outputPipes(mixed $pipes): array
    {
        if (!is_array($pipes)) {
            throw new RuntimeException('Fixture process pipes are unavailable.');
        }
        $output = $pipes[1] ?? null;
        $errors = $pipes[2] ?? null;
        if (!is_resource($output) || !is_resource($errors)) {
            throw new RuntimeException('Fixture process pipes are unavailable.');
        }
        return [$output, $errors];
    }
}
