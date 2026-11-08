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
            || ($data['render'] ?? null) !== true || ($data['observeConfig'] ?? null) !== true
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
            ], true, true);
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

    public function testMethodEvidenceSanitizerRequiresExplicitScopeAndKeepsOnlyFixedObservations(): void
    {
        $data = ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false, 'method_config_requested' => true,
            'method_config_get_attempted' => true, 'method_config_response_observed' => true,
            'method_config_payload_delivered' => false, 'method_config_http_status' => 403,
            'method_config_observation' => 'http_rejected', 'method_config_items' => 1,
            'method_config_pagination_known' => false, 'method_config_has_more' => false,
            'method_config_card_advertised' => true, 'card_readiness_observed' => true,
            'cardnumber_unique' => true, 'cardnumber_visible' => true, 'cardnumber_enabled' => true,
            'expiry_unique' => false, 'expiry_visible' => false, 'expiry_enabled' => false,
            'cvc_unique' => true, 'cvc_visible' => true, 'cvc_enabled' => false,
            'denied_read_families' => ['method_config' => 1, 'country' => 2, 'customer' => -1,
                'consent_list' => 1000001, 'other_pa' => 'PRIVATE_VALUE', 'PRIVATE_LABEL' => 3],
            'client-secret' => 'PRIVATE_SECRET', 'cardnumber_value' => 'PRIVATE_PAN'];
        $safe = HostedBrowser::sanitize($data, true, true);
        self::assertSame(403, $safe['method_config_http_status']);
        self::assertSame('http_rejected', $safe['method_config_observation']);
        self::assertFalse($safe['method_config_pagination_known']);
        self::assertTrue($safe['cardnumber_enabled']);
        self::assertFalse($safe['cvc_enabled']);
        self::assertSame(['method_config' => 1, 'country' => 2], $safe['denied_read_families']);
        self::assertStringNotContainsString('PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        unset($data['method_config_requested']);
        $safe = HostedBrowser::sanitize($data, true);
        self::assertArrayNotHasKey('method_config_observation', $safe);
        self::assertArrayNotHasKey('card_readiness_observed', $safe);
        self::assertArrayNotHasKey('denied_read_families', $safe);
    }

    public function testDeliveredMethodEvidenceRequiresAllPrivateReadPrerequisites(): void
    {
        $data = ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false, 'candidate_origin' => 'sandbox', 'render_requested' => true,
            'checkout_token_matches' => true, 'payer_request_scope_verified' => true, 'payer_get_attempted' => true,
            'payer_response_observed' => true, 'payer_mode_matches' => true, 'payer_currency_matches' => true,
            'payer_observation' => 'matched', 'payer_http_status' => 200, 'payer_payload_delivered' => true,
            'bound_render_frames' => 1, 'method_config_requested' => true, 'method_config_get_attempted' => true,
            'method_config_response_observed' => true, 'method_config_http_status' => 200,
            'method_config_observation' => 'matched', 'method_config_payload_delivered' => true];
        self::assertTrue(HostedBrowser::sanitize($data, true, true)['method_config_payload_delivered']);
        foreach (
            ['method_config_get_attempted', 'method_config_response_observed', 'method_config_http_status',
            'method_config_observation', 'bound_render_frames', 'payer_payload_delivered'] as $key
        ) {
            $invalid = $data;
            unset($invalid[$key]);
            $failed = false;
            try {
                HostedBrowser::sanitize($invalid, true, true);
            } catch (RuntimeException $error) {
                $failed = true;
                self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            }
            self::assertTrue($failed, $key);
        }
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize($data, true);
    }

    public function testFailedChildKeepsOnlyClosedPhaseCountersAndStatus(): void
    {
        $bridge = $this->bridge(<<<'PHP'
        echo '{"result":"fail","reason":"browser_preflight_failed","card_entry_enabled":false,
          "browser_failure_phase":"readiness_cardnumber_count","method_config_requested":true,
          "method_config_get_attempted":true,"method_config_send_started":true,"method_config_http_status":200,
          "method_config_response_observed":true,"method_config_observation":"matched","method_config_items":2,
          "method_config_card_advertised":true,"method_config_payload_delivered":true,
          "candidate_origin":"sandbox","render_requested":true,"checkout_token_matches":true,
          "payer_request_scope_verified":true,"payer_get_attempted":true,"payer_response_observed":true,
          "payer_mode_matches":true,"payer_currency_matches":true,"payer_observation":"matched",
          "payer_http_status":200,"payer_payload_delivered":true,"card_readiness_observed":false,
          "bound_render_frames":1,"blocked_requests":17,"message":"PRIVATE_URL_SECRET"}';
        exit(1);
        PHP);
        $safe = $bridge->inspect('https://checkout.sandbox.airwallex.com/pay?s=PRIVATE_SESSION', [
            'checkout_id' => 'PRIVATE_CHECKOUT', 'mode' => 'SETUP', 'currency' => 'EUR',
        ], true, true);
        self::assertSame('fail', $safe['result']);
        self::assertSame('readiness_cardnumber_count', $safe['browser_failure_phase']);
        self::assertSame(200, $safe['method_config_http_status']);
        self::assertSame(1, $safe['bound_render_frames']);
        self::assertTrue($safe['method_config_send_started']);
        self::assertSame('matched', $safe['method_config_observation']);
        self::assertTrue($safe['method_config_card_advertised']);
        self::assertTrue($safe['method_config_payload_delivered']);
        self::assertSame(2, $safe['method_config_items']);
        self::assertFalse($safe['card_readiness_observed']);

        self::assertStringNotContainsString('PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        $data = ['result' => 'fail', 'reason' => 'browser_preflight_failed', 'card_entry_enabled' => false,
            'browser_failure_phase' => 'PRIVATE_PROVIDER_PATH'];
        self::assertArrayNotHasKey('browser_failure_phase', HostedBrowser::sanitize($data));
        $data['result'] = 'blocked';
        $data['browser_failure_phase'] = 'navigation';
        self::assertArrayNotHasKey('browser_failure_phase', HostedBrowser::sanitize($data));
    }

    public function testKnownDetachedFramePreservesMethodFactsWithoutInventingFieldAbsence(): void
    {
        $data = ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false, 'method_config_requested' => true,
            'method_config_get_attempted' => true, 'method_config_send_started' => true,
            'method_config_response_observed' => true, 'method_config_http_status' => 200,
            'method_config_observation' => 'matched', 'method_config_items' => 2,
            'method_config_card_advertised' => true, 'method_config_pagination_known' => false,
            'card_readiness_observed' => false, 'card_readiness_unavailable_reason' => 'frame_detached',
            'readiness_frame_detached' => true, 'readiness_detach_stage' => 'after_count', 'bound_render_frames' => 1,
            'readiness_attachment_field' => 'cardnumber', 'readiness_frame_detached_before_count' => false,
            'readiness_frame_detached_after_count' => true,
            'cardnumber_unique' => false, 'cardnumber_visible' => false, 'cvc_enabled' => false,
            'private_error' => 'PRIVATE_FRAME_ERROR'];
        $safe = HostedBrowser::sanitize($data, true, true);
        self::assertFalse($safe['card_readiness_observed']);
        self::assertSame('frame_detached', $safe['card_readiness_unavailable_reason']);
        self::assertSame('after_count', $safe['readiness_detach_stage']);
        self::assertFalse($safe['readiness_frame_detached_before_count']);
        self::assertTrue($safe['readiness_frame_detached_after_count']);
        self::assertSame('matched', $safe['method_config_observation']);
        self::assertSame(200, $safe['method_config_http_status']);
        self::assertSame(2, $safe['method_config_items']);
        self::assertTrue($safe['method_config_card_advertised']);
        self::assertFalse($safe['method_config_pagination_known']);
        foreach (['cardnumber_unique', 'cardnumber_visible', 'cvc_enabled'] as $key) {
            self::assertArrayNotHasKey($key, $safe);
        }
        self::assertStringNotContainsString('PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        foreach (
            ['card_readiness_observed', 'readiness_frame_detached', 'readiness_detach_stage',
            'bound_render_frames'] as $key
        ) {
            $invalid = $data;
            unset($invalid[$key]);
            $failed = false;
            try {
                HostedBrowser::sanitize($invalid, true, true);
            } catch (RuntimeException) {
                $failed = true;
            }
            self::assertTrue($failed, $key);
        }
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize($data, true);
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
