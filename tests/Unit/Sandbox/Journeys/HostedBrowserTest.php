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
            || str_contains(json_encode($argv), 'PRIVATE_CHECKOUT')
            || ($data['expected'] ?? null) !== [
                'checkout_id' => 'PRIVATE_CHECKOUT', 'mode' => 'SETUP', 'currency' => 'EUR',
            ]
            || ($data['render'] ?? null) !== true
            || ($data['url'] ?? null) !== 'https://checkout.airwallex.com/pay?s=PRIVATE_SESSION') {
            exit(3);
        }
        echo '{"result":"blocked","reason":"financial_sandbox_boundary_unverified","card_entry_enabled":false}';
        PHP;
        $bridge = $this->bridge($script);
        putenv('AIRWALLEX_BRIDGE_SENTINEL=PRIVATE_ENVIRONMENT');
        try {
            $safe = $bridge->inspect('https://checkout.airwallex.com/pay?s=PRIVATE_SESSION', [
                'checkout_id' => 'PRIVATE_CHECKOUT', 'mode' => 'SETUP', 'currency' => 'EUR',
            ], true);
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
        foreach (
            ['payer_customer_mapping_verified', 'payer_payload_delivered', 'financial_routing_verified',
            'sandbox_origin_verified'] as $field
        ) {
            yield $field => ['echo \'{"result":"blocked","reason":"financial_sandbox_boundary_unverified",'
                . '"card_entry_enabled":false,"' . $field . '":true}\';'];
        }
    }

    #[DataProvider('unsafeChildren')]
    public function testUnsafeChildOutputNeverBecomesEvidence(string $script): void
    {
        $bridge = $this->bridge($script);
        $failure = null;
        try {
            $bridge->inspect('https://checkout.airwallex.com/pay?s=PRIVATE_SESSION', [
                'checkout_id' => 'PRIVATE_CHECKOUT', 'mode' => 'SETUP', 'currency' => 'EUR',
            ]);
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

    public function testReadOnlyBindingEvidenceCannotRetainOpaqueObservationOrPayloadFields(): void
    {
        $safe = HostedBrowser::sanitize(['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false, 'checkout_token_matches' => true, 'payer_request_scope_verified' => true,
            'payer_get_attempted' => true, 'payer_response_observed' => true, 'payer_observation' => 'matched',
            'payer_mode_matches' => true, 'payer_currency_matches' => true, 'payer_customer_mapping_verified' => false,
            'payer_payload_delivered' => false, 'financial_routing_verified' => false,
            'payer_body' => ['PRIVATE_NAME'], 'checkout_id' => 'PRIVATE_ID', 'authorization' => 'PRIVATE_TOKEN']);
        self::assertSame('matched', $safe['payer_observation']);
        self::assertTrue($safe['payer_request_scope_verified']);
        self::assertFalse($safe['payer_customer_mapping_verified']);
        self::assertFalse($safe['payer_payload_delivered']);
        self::assertFalse($safe['financial_routing_verified']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
        $safe['payer_observation'] = 'PRIVATE_RESPONSE';
        self::assertArrayNotHasKey('payer_observation', HostedBrowser::sanitize($safe));
    }

    public function testOnlyBoundedIntegerPayerHttpStatusesBecomeEvidence(): void
    {
        $base = ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false];
        foreach ([100, 200, 302, 401, 403, 404, 503, 599] as $status) {
            $safe = HostedBrowser::sanitize($base + ['payer_http_status' => $status]);
            self::assertSame($status, $safe['payer_http_status']);
        }
        foreach ([99, 600, -1, 'PRIVATE_STATUS', '401', 401.0, []] as $status) {
            self::assertArrayNotHasKey('payer_http_status', HostedBrowser::sanitize($base + [
                'payer_http_status' => $status,
            ]));
        }
    }

    public function testPayerDeliveryRequiresExplicitRenderAndTheCompleteOwnedResponseTuple(): void
    {
        $evidence = ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false, 'candidate_origin' => 'sandbox', 'render_requested' => true,
            'checkout_token_matches' => true, 'payer_request_scope_verified' => true, 'payer_get_attempted' => true,
            'payer_response_observed' => true, 'payer_mode_matches' => true, 'payer_currency_matches' => true,
            'payer_http_status' => 200, 'payer_observation' => 'matched', 'payer_payload_delivered' => true,
            'financial_routing_verified' => false, 'payer_customer_mapping_verified' => false,
            'bound_render_frames' => 1, 'verified_render_assets' => 43, 'url' => 'PRIVATE_URL'];
        $safe = HostedBrowser::sanitize($evidence, true);
        self::assertTrue($safe['payer_payload_delivered']);
        self::assertFalse($safe['financial_routing_verified']);
        self::assertSame(1, $safe['bound_render_frames']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
        foreach (
            ['candidate_origin', 'checkout_token_matches', 'payer_request_scope_verified', 'payer_get_attempted',
            'payer_response_observed', 'payer_mode_matches', 'payer_currency_matches', 'payer_http_status',
            'payer_observation'] as $field
        ) {
            $invalid = $evidence;
            unset($invalid[$field]);
            $caught = null;
            try {
                HostedBrowser::sanitize($invalid, true);
            } catch (RuntimeException $error) {
                $caught = $error;
                self::assertStringNotContainsString('PRIVATE_', $error->getMessage());
            }
            self::assertNotNull($caught, 'Every response-binding fact must be present before delivery.');
        }
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize($evidence);
    }

    public function testPublicControllerEvidenceIsOnlyBoundedCounts(): void
    {
        $base = ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false];
        $safe = HostedBrowser::sanitize($base + ['controller_asset_requests' => 1,
            'verified_controller_assets' => 1, 'controller_replays_blocked' => 2,
            'controller_http_status' => 200, 'controller_url' => 'PRIVATE_URL']);
        self::assertSame(1, $safe['controller_asset_requests']);
        self::assertSame(1, $safe['verified_controller_assets']);
        self::assertSame(2, $safe['controller_replays_blocked']);
        self::assertSame(200, $safe['controller_http_status']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
        foreach ([-1, 1000001, 'PRIVATE_VALUE', 1.0] as $invalid) {
            $safe = HostedBrowser::sanitize($base + ['verified_controller_assets' => $invalid,
                'controller_http_status' => $invalid]);
            self::assertArrayNotHasKey('verified_controller_assets', $safe);
            self::assertArrayNotHasKey('controller_http_status', $safe);
        }
    }

    public function testRenderDiagnosticsRetainOnlyFixedBoundedCountsWithoutPrivateKeysOrValues(): void
    {
        $data = ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false, 'render_frame_checks' => [
                'document_candidates' => 2, 'qualified' => 0, 'customer_mismatch' => 1,
                'fulfilled' => 1000000, 'secret_mismatch' => 'PRIVATE_SECRET', 'intent_mismatch' => -1,
                'build_mismatch' => 1000001, 'language_mismatch' => 1.0, 'PRIVATE_FIELD' => 1,
            ]];
        $safe = HostedBrowser::sanitize($data, true);
        self::assertSame(['document_candidates' => 2, 'customer_mismatch' => 1, 'qualified' => 0,
            'fulfilled' => 1000000], $safe['render_frame_checks']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('render_frame_checks', HostedBrowser::sanitize($data));
        foreach (['PRIVATE_BODY', null, 1, false] as $invalid) {
            $data['render_frame_checks'] = $invalid;
            self::assertArrayNotHasKey('render_frame_checks', HostedBrowser::sanitize($data, true));
        }
    }

    public function testRenderCliRejectsValueAndDuplicateFlagsBeforeAnyConfigurationRead(): void
    {
        foreach ([['--render-owned-data=false'], ['--render-owned-data', '--render-owned-data']] as $flags) {
            $process = proc_open([
                PHP_BINARY, dirname(__DIR__, 4) . '/tools/hosted-setup-preflight.php',
                '--environment=sandbox', '--credentials-file=/nonexistent-synthetic-config',
                '--state-dir=/nonexistent-synthetic-state', '--node-bin=' . PHP_BINARY,
                '--playwright-module=/nonexistent-synthetic-module', '--create-one-hosted-setup', ...$flags,
            ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            [$output, $errors] = self::outputPipes($pipes);
            $contents = stream_get_contents($output);
            self::assertSame('', stream_get_contents($errors));
            fclose($output);
            fclose($errors);
            self::assertSame(1, proc_close($process));
            self::assertIsString($contents);
            $safe = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($safe);
            self::assertSame('configuration', $safe['phase']);
            self::assertArrayNotHasKey('source', $safe);
            self::assertArrayNotHasKey('run_id', $safe);
        }
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
