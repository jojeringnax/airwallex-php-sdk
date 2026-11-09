<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedBrowser;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedSecurityMode;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedRiskJournal;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
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

    public function testSeparateSecurityProfileRejectsReadyClaimsAndKeepsItsFixedEntry(): void
    {
        $base = ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false, 'instrument_security_requested' => true,
            'instrument_ready' => false, 'financial_permission' => false, 'security_graph_complete' => false];
        $safe = HostedBrowser::sanitize(
            $base,
            true,
            true,
            true,
            true,
            false,
            false,
            false,
            HostedSecurityMode::InstrumentSecurity
        );
        self::assertFalse($safe['instrument_ready']);
        self::assertSame('hosted-instrument-security.cjs', HostedSecurityMode::InstrumentSecurity->entry());
        $old = HostedBrowser::sourceHashes();
        $new = HostedBrowser::sourceHashes(HostedSecurityMode::InstrumentSecurity);
        self::assertSame($old, array_intersect_key($new, $old));
        self::assertCount(count($old) + 3, $new);
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize(
            array_replace($base, ['instrument_ready' => true]),
            true,
            true,
            true,
            true,
            false,
            false,
            false,
            HostedSecurityMode::InstrumentSecurity
        );
    }

    public function testInstrumentLoaderDiagnosticsAreClosedOptionalAndDoNotChangeLegacyOutput(): void
    {
        $base = ['result' => 'fail', 'reason' => 'browser_preflight_failed', 'card_entry_enabled' => false,
            'instrument_security_requested' => true, 'instrument_ready' => false,
            'financial_permission' => false, 'security_graph_complete' => false];
        $diagnostics = ['security_loader_validation' => 'withheld',
            'security_loader_first_failure_phase' => 'response_validation',
            'security_loader_first_failure_gate' => 'scope_window_not_positive',
            'security_source_first_stop' => 'teardown',
            'security_loader_scope_stop_at_failure' => 'not_observed'];
        $safe = HostedBrowser::sanitize(
            $base + $diagnostics,
            true,
            true,
            true,
            true,
            mode: HostedSecurityMode::InstrumentSecurity
        );
        foreach ($diagnostics as $field => $value) {
            self::assertSame($value, $safe[$field]);
        }
        self::assertSame('fail', $safe['result']);
        self::assertFalse($safe['instrument_ready']);
        self::assertFalse($safe['financial_permission']);
        self::assertSame(
            HostedBrowser::sanitize($base, true, true, true, true),
            HostedBrowser::sanitize($base + $diagnostics, true, true, true, true)
        );
        foreach (
            ['SYNTHETIC_PRIVATE_URL', str_repeat('SYNTHETIC_PRIVATE', 1000), true, 1, null, [], new \stdClass()] as $bad
        ) {
            $invalid = array_fill_keys(array_keys($diagnostics), $bad);
            $safe = HostedBrowser::sanitize(
                $base + $invalid,
                true,
                true,
                true,
                true,
                mode: HostedSecurityMode::InstrumentSecurity
            );
            foreach (array_keys($diagnostics) as $field) {
                self::assertArrayNotHasKey($field, $safe);
            }
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        }
    }

    public function testPassiveBoundDocumentFactsAreNativeClosedAndAbsentFromLegacyOutput(): void
    {
        $base = ['result' => 'fail', 'reason' => 'browser_preflight_failed', 'card_entry_enabled' => false,
            'instrument_security_requested' => true, 'instrument_ready' => false,
            'financial_permission' => false, 'security_graph_complete' => false];
        $facts = ['security_bound_navigation_original_relation' => 'fragment_only_change',
            'security_bound_navigation_previous_relation' => 'exact_repeat',
            'security_bound_document_reference' => 'captured_before_stop',
            'security_bound_document_continuity' => 'same', 'security_bound_document_cleanup' => 'complete'];
        $safe = HostedBrowser::sanitize(
            $base + $facts + ['raw_url' => 'PRIVATE_URL', 'raw_document' => 'PRIVATE_DOM'],
            true,
            true,
            true,
            true,
            mode: HostedSecurityMode::InstrumentSecurity
        );
        foreach ($facts as $key => $value) {
            self::assertSame($value, $safe[$key]);
        }
        self::assertFalse($safe['financial_permission']);
        self::assertSame('fail', $safe['result']);
        self::assertStringNotContainsString('PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertSame(
            HostedBrowser::sanitize($base, true, true, true, true),
            HostedBrowser::sanitize($base + $facts, true, true, true, true)
        );
        foreach (['PRIVATE_URL', str_repeat('PRIVATE', 5000), true, 1, null, [], new \stdClass()] as $bad) {
            $safe = HostedBrowser::sanitize(
                $base + array_fill_keys(array_keys($facts), $bad),
                true,
                true,
                true,
                true,
                mode: HostedSecurityMode::InstrumentSecurity
            );
            foreach (array_keys($facts) as $key) {
                self::assertArrayNotHasKey($key, $safe);
            }
        }
    }

    public function testSameDocumentCannotBeReportedWithoutAnEstablishedOriginalReference(): void
    {
        $base = ['result' => 'fail', 'reason' => 'browser_preflight_failed', 'card_entry_enabled' => false,
            'instrument_security_requested' => true, 'instrument_ready' => false,
            'financial_permission' => false, 'security_graph_complete' => false];
        foreach (['not_established', 'unavailable', 'PRIVATE', true, null] as $reference) {
            $safe = HostedBrowser::sanitize(
                $base + ['security_bound_document_reference' => $reference,
                    'security_bound_document_continuity' => 'same'],
                true,
                true,
                true,
                true,
                mode: HostedSecurityMode::InstrumentSecurity
            );
            self::assertArrayNotHasKey('security_bound_document_continuity', $safe);
        }
        $safe = HostedBrowser::sanitize(
            $base + ['security_bound_document_reference' => 'not_established',
                'security_bound_document_continuity' => 'not_captured',
                'security_bound_document_cleanup' => 'unavailable'],
            true,
            true,
            true,
            true,
            mode: HostedSecurityMode::InstrumentSecurity
        );
        self::assertSame('not_captured', $safe['security_bound_document_continuity']);
        self::assertSame('unavailable', $safe['security_bound_document_cleanup']);
    }

    public function testRejectedInstrumentSourceOrStateNeverSpawnsAChild(): void
    {
        foreach (['source_book', 'poisoned_state'] as $fault) {
            $marker = $this->directory . '/child-started';
            $bridge = $this->bridge('file_put_contents(' . var_export($marker, true) . ', "started");');
            $context = hash('sha256', 'synthetic_context');
            $healthy = true;
            $ledger = RunLedger::create(
                $context,
                $this->directory,
                synchronize: static function () use (&$healthy): bool {
                    return $healthy;
                },
            );
            $hashes = HostedBrowser::sourceHashes(HostedSecurityMode::InstrumentSecurity);
            if ($fault === 'source_book') {
                $hashes['hosted-browser.cjs'] = str_repeat('0', 64);
            }
            $journal = new HostedRiskJournal(
                $ledger,
                $context,
                ['checkout_id' => 'fixture', 'mode' => 'SETUP', 'currency' => 'EUR'],
                HostedBrowser::sourceHashes(),
                $hashes
            );
            if ($fault === 'poisoned_state') {
                $healthy = false;
                try {
                    $ledger->requestId('synthetic_failed_persistence', [], $context);
                    self::fail('Synthetic state failure was not triggered.');
                } catch (RuntimeException) {
                    // The retained state is intentionally poisoned before adapter creation.
                }
            }
            try {
                $bridge->inspect(
                    'https://checkout.sandbox.airwallex.com/pay?s=PRIVATE',
                    ['checkout_id' => 'fixture', 'mode' => 'SETUP', 'currency' => 'EUR'],
                    true,
                    true,
                    true,
                    true,
                    $journal,
                    false,
                    false,
                    false,
                    HostedSecurityMode::InstrumentSecurity
                );
                self::fail('Rejected adapter configuration cannot start a child.');
            } catch (RuntimeException $error) {
                self::assertStringNotContainsString('PRIVATE', $error->getMessage());
                self::assertFileDoesNotExist($marker);
            } finally {
                foreach (glob($this->directory . '/sdk-validation-*/*') ?: [] as $path) {
                    unlink($path);
                }
                foreach (glob($this->directory . '/sdk-validation-*') ?: [] as $path) {
                    rmdir($path);
                }
            }
        }
    }

    public function testNewProfileReapsAnUncooperativeChildAfterPrivateOutputFailure(): void
    {
        if (!function_exists('pcntl_signal') || !defined('SIGTERM') || !defined('SIG_IGN')) {
            self::markTestSkipped('Synthetic signal fixture requires pcntl.');
        }
        $bridge = $this->bridge(<<<'PHP'
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, SIG_IGN);
        file_get_contents('php://stdin');
        echo str_repeat('x', 9000);
        fflush(STDOUT);
        while (true) { usleep(10000); }
        PHP);
        $context = hash('sha256', 'synthetic_context');
        $ledger = RunLedger::create($context, $this->directory);
        $journal = new HostedRiskJournal(
            $ledger,
            $context,
            ['checkout_id' => 'fixture', 'mode' => 'SETUP', 'currency' => 'EUR'],
            HostedBrowser::sourceHashes(),
            HostedBrowser::sourceHashes(HostedSecurityMode::InstrumentSecurity),
        );
        $started = microtime(true);
        try {
            $bridge->inspect(
                'https://checkout.sandbox.airwallex.com/pay?s=PRIVATE',
                ['checkout_id' => 'fixture', 'mode' => 'SETUP', 'currency' => 'EUR'],
                true,
                true,
                true,
                true,
                $journal,
                false,
                false,
                false,
                HostedSecurityMode::InstrumentSecurity
            );
            self::fail('Oversized child output must fail.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            self::assertLessThan(5, microtime(true) - $started);
        } finally {
            foreach (glob($this->directory . '/sdk-validation-*/*') ?: [] as $path) {
                unlink($path);
            }
            foreach (glob($this->directory . '/sdk-validation-*') ?: [] as $path) {
                rmdir($path);
            }
        }
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

    /** @return array<string,mixed> */
    private static function normalEvidence(): array
    {
        return ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false, 'normal_reads_requested' => true, 'normal_read_window_closed' => true,
            'normal_read_inspection_completed' => true, 'normal_checkout_status_poisoned' => false,
            'normal_customer_attempts' => 1, 'normal_customer_sends' => 1, 'normal_customer_delivered' => 1,
            'normal_customer_observation' => 'matched', 'normal_customer_http_status' => 200,
            'normal_consent_list_attempts' => 1, 'normal_consent_list_sends' => 1,
            'normal_consent_list_delivered' => 1, 'normal_consent_list_observation' => 'matched',
            'normal_consent_list_http_status' => 200, 'normal_consent_list_items' => 0,
            'normal_consent_list_pagination_known' => false, 'normal_consent_list_has_more' => false,
            'candidate_origin' => 'sandbox', 'payer_payload_delivered' => true, 'payer_http_status' => 200,
            'payer_observation' => 'matched', 'checkout_token_matches' => true,
            'payer_request_scope_verified' => true, 'payer_get_attempted' => true,
            'payer_response_observed' => true, 'payer_mode_matches' => true, 'payer_currency_matches' => true,
            'bound_render_frames' => 1, 'card_readiness_observed' => true];
    }

    /** @return array<string,mixed> */
    private static function nativeCardEvidence(): array
    {
        return array_replace(self::normalEvidence(), [
            'normal_consent_list_pagination_known' => true,
            'normal_checkout_status_attempts' => 1, 'normal_checkout_status_sends' => 1,
            'normal_checkout_status_delivered' => 1, 'normal_checkout_status_observation' => 'matched',
            'normal_checkout_status_http_status' => 200, 'normal_checkout_status_state' => 'REQUESTED',
            'native_card_selection_requested' => true, 'native_card_selection' => 'completed',
            'render_frame_binding_available' => true,
            'native_card_radio_count' => 'one', 'native_card_radio_visible' => true,
            'native_card_radio_enabled' => true, 'native_card_radio_checked' => false,
            'native_card_action_attempts' => 1, 'native_card_action_invoked' => true,
            'native_card_action_invoked_before_capture' => true, 'native_card_action_completed' => true,
            'native_card_action_completed_before_capture' => true,
            'cardnumber_match_count' => 'multiple', 'cardnumber_unique' => false,
            'expiry_match_count' => 'zero', 'expiry_unique' => false,
            'cvc_match_count' => 'one', 'cvc_unique' => true,
        ]);
    }

    public function testNativeCardEvidenceIsScopedClosedAndPreservesHonestSnapshotCounts(): void
    {
        $data = self::nativeCardEvidence() + ['native_card_selector' => 'PRIVATE_SELECTOR',
            'native_card_value' => 'PRIVATE_VALUE', 'native_card_error' => 'PRIVATE_ERROR'];
        $safe = HostedBrowser::sanitize($data, true, true, true, false, false, false, true);
        self::assertSame(1, $safe['native_card_action_attempts']);
        self::assertTrue($safe['native_card_action_completed_before_capture']);
        self::assertSame('multiple', $safe['cardnumber_match_count']);
        self::assertSame('zero', $safe['expiry_match_count']);
        self::assertSame('one', $safe['cvc_match_count']);
        self::assertFalse($safe['card_entry_enabled']);
        self::assertArrayNotHasKey('method_config_payload_delivered', $safe);
        self::assertStringNotContainsString('PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        $late = HostedBrowser::sanitize(array_replace($data, [
            'native_card_action_completed_before_capture' => false,
        ]), true, true, true, false, false, false, true);
        self::assertFalse($late['native_card_action_completed_before_capture']);
        self::assertSame('zero', $late['expiry_match_count']);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidNativeCardProof(): iterable
    {
        yield 'SDK vocabulary cannot replace gateway state' => ['normal_checkout_status_state', 'ACTIVE'];
        yield 'Consent pagination unknown' => ['normal_consent_list_pagination_known', false];
        yield 'Consent nonempty' => ['normal_consent_list_items', 1];
        yield 'already checked' => ['native_card_radio_checked', true];
        yield 'hidden' => ['native_card_radio_visible', false];
        yield 'ambiguous' => ['native_card_radio_count', 'multiple'];
        yield 'second invocation' => ['native_card_action_attempts', 2];
        yield 'late invocation' => ['native_card_action_invoked_before_capture', false];
        yield 'count cannot claim uniqueness' => ['cardnumber_match_count', 'one'];
    }

    #[DataProvider('invalidNativeCardProof')]
    public function testNativeCardEvidenceCannotManufactureItsProof(string $field, mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize(
            array_replace(self::nativeCardEvidence(), [$field => $value]),
            true,
            true,
            true,
            false,
            false,
            false,
            true
        );
    }

    public function testUnrequestedCardActionFailsClosedAndOpaqueStartupFailureInventsNoAction(): void
    {
        $safe = HostedBrowser::sanitize(
            ['result' => 'fail', 'reason' => 'browser_preflight_failed',
            'card_entry_enabled' => false, 'browser_failure_phase' => 'tooling_load'],
            true,
            true,
            true,
            false,
            false,
            false,
            true
        );
        self::assertArrayNotHasKey('native_card_action_attempts', $safe);
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize(self::nativeCardEvidence(), true, true, true);
    }

    public function testRadioFactsCannotBorrowAnUnboundFrameEvenWhenNoActionWasInvoked(): void
    {
        $data = array_replace(self::nativeCardEvidence(), ['normal_read_inspection_completed' => false,
            'bound_render_frames' => 0, 'native_card_selection' => 'already_selected',
            'native_card_action_invoked' => false, 'native_card_action_attempts' => 0]);
        unset($data['native_card_action_completed'], $data['native_card_action_completed_before_capture']);
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize($data, true, true, true, false, false, false, true);
    }

    public function testRadioStateCannotInventItsUniqueCount(): void
    {
        $data = self::nativeCardEvidence();
        unset($data['native_card_radio_count']);
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize($data, true, true, true, false, false, false, true);
    }

    public function testPartialRiskEvidenceIsExplicitClosedAndDoesNotCreateCountsAfterAnOpaqueFailure(): void
    {
        $data = self::normalEvidence() + [
            'risk_bootstrap_requested' => true, 'risk_graph_complete' => false, 'risk_financial_ready' => false,
            'risk_loader_attempted' => true, 'risk_loader_verified' => true,
            'risk_partial_reason' => 'unknown_dependency', 'risk_telemetry_poisoned' => true,
            'risk_visitor_attempts' => 1, 'risk_visitor_client_invocations' => 1, 'risk_visitor_delivered' => 0,
            'risk_visitor_http_status' => 503, 'risk_journal_prepared' => 1, 'risk_journal_results' => 1,
            'risk_denied_dependencies' => ['risk_document' => 1, 'forter' => 2, 'PRIVATE_URL' => 3],
            'risk_raw_error' => 'PRIVATE_ERROR', 'risk_nonce' => 'PRIVATE_NONCE', 'risk_cookie' => 'PRIVATE_COOKIE',
        ];
        $safe = HostedBrowser::sanitize($data, true, true, true, true);
        self::assertFalse($safe['risk_graph_complete']);
        self::assertFalse($safe['risk_financial_ready']);
        self::assertSame(503, $safe['risk_visitor_http_status']);
        self::assertSame(['risk_document' => 1, 'forter' => 2], $safe['risk_denied_dependencies']);
        self::assertStringNotContainsString('PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('risk_bootstrap_requested', HostedBrowser::sanitize($data, true, true, true));
        $opaque = HostedBrowser::sanitize([
            'result' => 'fail', 'reason' => 'browser_preflight_failed', 'card_entry_enabled' => false,
        ], true, true, true, true);
        self::assertArrayNotHasKey('risk_visitor_client_invocations', $opaque);
        self::assertArrayNotHasKey('risk_loader_verified', $opaque);
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize(array_replace($data, ['risk_financial_ready' => true]), true, true, true, true);
    }

    public function testRiskCountsCannotClaimMoreThanFiniteAttemptsOrLoseTheirOwnScope(): void
    {
        foreach (
            [
            ['risk_event_attempts' => 9],
            ['risk_visitor_attempts' => 1, 'risk_visitor_client_invocations' => 2],
            ['risk_visitor_attempts' => 1, 'risk_visitor_client_invocations' => 0, 'risk_visitor_delivered' => 1],
            ['risk_loader_verified' => true, 'risk_loader_attempted' => false],
            ] as $extra
        ) {
            try {
                HostedBrowser::sanitize(self::normalEvidence() + $extra, true, true, true, true);
                self::fail('Invalid risk evidence was retained.');
            } catch (RuntimeException $error) {
                self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            }
        }
    }

    public function testNormalEvidenceKeepsOnlyClosedFactsAndDoesNotInventUnavailableCounts(): void
    {
        $data = self::normalEvidence() + ['normal_customer_url' => 'PRIVATE_URL', 'countryCode' => 'PRIVATE',
            'normal_consent_list_payload' => 'PRIVATE_CONSENT', 'normal_unknown_observation' => 'PRIVATE'];
        $safe = HostedBrowser::sanitize($data, true, true, true);
        self::assertSame(1, $safe['normal_customer_delivered']);
        self::assertFalse($safe['normal_consent_list_pagination_known']);
        self::assertArrayNotHasKey('normal_country_sends', $safe);
        self::assertStringNotContainsString('PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize($data, true, true);
    }

    /** @return iterable<string,array{string,bool}> */
    public static function gatewayStates(): iterable
    {
        foreach (['REQUESTED', 'PENDING', 'COMPLETED', 'EXPIRED', 'unlisted', 'unavailable'] as $state) {
            yield $state => [$state, $state === 'REQUESTED'];
        }
    }

    #[DataProvider('gatewayStates')]
    public function testWebStateAllowsPriorDeliveredPolls(string $state, bool $matched): void
    {
        $data = self::normalEvidence() + [
            'normal_checkout_status_attempts' => 2, 'normal_checkout_status_sends' => 2,
            'normal_checkout_status_delivered' => 1, 'normal_checkout_status_http_status' => 200,
            'normal_checkout_status_observation' => $matched ? 'matched' : 'invalid_response',
            'normal_checkout_status_state' => $state,
        ];
        $safe = HostedBrowser::sanitize($data, true, true, true);
        self::assertSame($state, $safe['normal_checkout_status_state']);
        self::assertSame(1, $safe['normal_checkout_status_delivered']);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function unsafeGatewayStates(): iterable
    {
        foreach (['ACTIVE', 'OPEN', 'PRIVATE_STATE', true, null, []] as $index => $state) {
            yield 'foreign or raw state ' . $index => ['normal_checkout_status_state', $state];
        }
        yield 'unproved HTTP response' => ['normal_checkout_status_http_status', 302];
        yield 'unproved send' => ['normal_checkout_status_sends', 0];
        yield 'unmatched state claimed matched' => ['normal_checkout_status_state', 'PENDING'];
    }

    #[DataProvider('unsafeGatewayStates')]
    public function testGatewayClassificationCannotExposeForeignStatesOrInventItsProof(string $key, mixed $value): void
    {
        $data = self::normalEvidence() + [
            'normal_checkout_status_attempts' => 1, 'normal_checkout_status_sends' => 1,
            'normal_checkout_status_delivered' => 0, 'normal_checkout_status_http_status' => 200,
            'normal_checkout_status_observation' => 'matched', 'normal_checkout_status_state' => 'REQUESTED',
        ];
        $data[$key] = $value;
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize($data, true, true, true);
    }

    /** @return array<string,mixed> */
    private static function currencyEvidence(): array
    {
        return self::normalEvidence() + ['currency_settings_requested' => true,
            'currency_settings_secret_available' => true, 'currency_settings_window_closed' => true,
            'currency_settings_headers_withheld' => false, 'currency_settings_xsrf_withheld' => false,
            'currency_settings_attempts' => 1, 'currency_settings_sends' => 1, 'currency_settings_delivered' => 1,
            'currency_settings_http_status' => 200, 'currency_settings_outcome' => 'matched',
            'currency_settings_items_state' => 'array', 'currency_settings_items' => 1,
            'currency_settings_currency_entries' => 2];
    }

    public function testCurrencyProjectionKeepsClosedCountsWithoutAnyPrivateSourceValues(): void
    {
        $data = self::currencyEvidence() + ['currency_settings_raw' => 'PRIVATE_SECRET',
            'currency_settings_country' => 'PRIVATE_COUNTRY', 'currency_settings_request_timeout_ms' => 7900];
        $safe = HostedBrowser::sanitize($data, true, true, true, false, false, true);
        self::assertSame(1, $safe['currency_settings_delivered']);
        self::assertSame(2, $safe['currency_settings_currency_entries']);
        self::assertSame(7900, $safe['currency_settings_request_timeout_ms']);
        self::assertStringNotContainsString('PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('currency_settings_delivered', HostedBrowser::sanitize($data, true, true, true));
    }

    public function testCurrencySourceFallbackDoesNotInventAnEmptySettingsInventory(): void
    {
        $data = self::currencyEvidence();
        $data['currency_settings_outcome'] = 'source_fallback';
        $data['currency_settings_items_state'] = 'missing';
        $safe = HostedBrowser::sanitize($data, true, true, true, false, false, true);
        self::assertSame('source_fallback', $safe['currency_settings_outcome']);
        self::assertArrayNotHasKey('currency_settings_items', $safe);
        self::assertArrayNotHasKey('currency_settings_currency_entries', $safe);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function badCurrencyFacts(): iterable
    {
        yield 'scope' => ['payer_request_scope_verified', false];
        yield 'undelivered payer' => ['payer_payload_delivered', false];
        yield 'mode' => ['payer_mode_matches', false];
        yield 'currency' => ['payer_currency_matches', false];
        yield 'origin' => ['candidate_origin', 'shared'];
        yield 'secret' => ['currency_settings_secret_available', false];
        yield 'second slot' => ['currency_settings_attempts', 2];
        yield 'uninovked delivery' => ['currency_settings_sends', 0];
        yield 'redirect' => ['currency_settings_http_status', 302];
        yield 'withheld' => ['currency_settings_headers_withheld', true];
        yield 'missing shape' => ['currency_settings_items_state', 'not_observed'];
        yield 'config mismatch' => ['currency_settings_requested', false];
        yield 'bad response' => ['currency_settings_outcome', 'unsupported_response_shape'];
    }

    #[DataProvider('badCurrencyFacts')]
    public function testCurrencyCannotInventScopeHeaderOrDeliveryProof(string $key, mixed $value): void
    {
        $data = self::currencyEvidence();
        $data[$key] = $value;
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize($data, true, true, true, false, false, true);
    }

    public function testCurrencyXsrfWithholdingKeepsNoSendProofWithoutInventingHttpOrItems(): void
    {
        $data = self::currencyEvidence();
        $data['currency_settings_sends'] = 0;
        $data['currency_settings_delivered'] = 0;
        $data['currency_settings_headers_withheld'] = true;
        $data['currency_settings_xsrf_withheld'] = true;
        $data['currency_settings_outcome'] = 'headers_withheld';
        $data['currency_settings_items_state'] = 'not_observed';
        unset($data['currency_settings_http_status']);
        $safe = HostedBrowser::sanitize($data, true, true, true, false, false, true);
        self::assertTrue($safe['currency_settings_xsrf_withheld']);
        self::assertSame(0, $safe['currency_settings_sends']);
        self::assertArrayNotHasKey('currency_settings_http_status', $safe);
        self::assertArrayNotHasKey('currency_settings_items', $safe);
    }

    public function testCurrencyFlagRequiresExistingNormalReadOptInBeforeStartingAChild(): void
    {
        $started = $this->directory . '/child-started';
        $bridge = $this->bridge('file_put_contents(' . var_export($started, true) . ', "started"); exit(9);');
        $this->expectException(RuntimeException::class);
        try {
            $bridge->inspect('https://checkout.sandbox.airwallex.com/pay?s=PRIVATE', [
                'checkout_id' => 'PRIVATE', 'mode' => 'SETUP', 'currency' => 'EUR',
            ], render: true, observeConfig: true, observeCurrencySettings: true);
        } finally {
            self::assertFileDoesNotExist($started);
        }
    }

    /** @return array<string,mixed> */
    private static function parentCountryEvidence(): array
    {
        return ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false, 'candidate_origin' => 'sandbox', 'payer_request_scope_verified' => true,
            'checkout_token_matches' => true,
            'parent_country_requested' => true, 'parent_country_startup_started' => true,
            'parent_country_window_closed' => true, 'parent_country_attempts' => 1, 'parent_country_sends' => 1,
            'parent_country_delivered' => 1, 'parent_country_http_status' => 200,
            'parent_country_outcome' => 'matched'];
    }

    public function testParentCountryCanPrecedePayerAndRetainsOnlyClosedPrivateFacts(): void
    {
        $data = self::parentCountryEvidence() + ['countryCode' => 'PRIVATE', 'parent_country_url' => 'PRIVATE',
            'parent_country_request_timeout_ms' => 7990];
        $safe = HostedBrowser::sanitize($data, true, true, true, false, true);
        self::assertSame(1, $safe['parent_country_delivered']);
        self::assertSame(7990, $safe['parent_country_request_timeout_ms']);
        self::assertArrayNotHasKey('payer_payload_delivered', $safe);
        self::assertArrayNotHasKey('bound_render_frames', $safe);
        self::assertStringNotContainsString('PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('parent_country_delivered', HostedBrowser::sanitize($data, true, true, true));
    }

    public function testRiskAndParentSanitizersRetainOnlyTheirIndependentlySelectedFacts(): void
    {
        $data = self::normalEvidence() + self::parentCountryEvidence() + [
            'risk_bootstrap_requested' => true, 'risk_graph_complete' => false, 'risk_financial_ready' => false,
            'risk_loader_attempted' => false, 'risk_partial_reason' => 'scope_unavailable',
        ];
        $both = HostedBrowser::sanitize($data, true, true, true, true, true);
        self::assertSame(1, $both['parent_country_delivered']);
        self::assertTrue($both['risk_bootstrap_requested']);
        self::assertFalse($both['risk_financial_ready']);
        self::assertArrayNotHasKey(
            'parent_country_delivered',
            HostedBrowser::sanitize($data, true, true, true, true),
        );
        self::assertArrayNotHasKey(
            'risk_bootstrap_requested',
            HostedBrowser::sanitize($data, true, true, true, false, true),
        );
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidParentFacts(): iterable
    {
        yield 'unscoped document' => ['payer_request_scope_verified', false];
        yield 'uncorrelated token' => ['checkout_token_matches', false];
        yield 'wrong origin' => ['candidate_origin', 'shared'];
        yield 'startup absent' => ['parent_country_startup_started', false];
        yield 'second attempt' => ['parent_country_attempts', 2];
        yield 'uninvoked delivery' => ['parent_country_sends', 0];
        yield 'bad HTTP proof' => ['parent_country_http_status', 302];
        yield 'bad body proof' => ['parent_country_outcome', 'invalid_response'];
    }

    #[DataProvider('invalidParentFacts')]
    public function testParentCountryCannotInventScopeBudgetOrDelivery(string $key, mixed $value): void
    {
        $data = self::parentCountryEvidence();
        $data[$key] = $value;
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize($data, true, true, true, false, true);
    }

    public function testOpaqueParentFailureDoesNotInventUnavailableCountsAndRejectsPartialCounts(): void
    {
        $safe = HostedBrowser::sanitize(['result' => 'fail', 'reason' => 'browser_preflight_failed',
            'card_entry_enabled' => false, 'parent_country_failure_phase' => 'get'], true, true, true, false, true);
        self::assertSame('get', $safe['parent_country_failure_phase']);
        self::assertArrayNotHasKey('parent_country_attempts', $safe);
        $data = self::parentCountryEvidence();
        unset($data['parent_country_attempts']);
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize($data, true, true, true, false, true);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function unsafeNormalFacts(): iterable
    {
        foreach (
            ['normal_customer_attempts' => 2, 'normal_customer_sends' => 2,
            'normal_customer_delivered' => 2, 'normal_customer_http_status' => 302,
            'normal_customer_observation' => 'invalid_response', 'payer_payload_delivered' => false,
            'bound_render_frames' => 0, 'normal_read_window_closed' => false,
            'card_readiness_observed' => false] as $key => $value
        ) {
            yield $key => [$key, $value];
        }
        yield 'status fourth slot' => ['normal_checkout_status_attempts', 4];
        yield 'unproved status send' => ['normal_checkout_status_sends', 1];
    }

    #[DataProvider('unsafeNormalFacts')]
    public function testNormalEvidenceCannotClaimUnboundedOrUnownedDelivery(string $key, mixed $value): void
    {
        $data = self::normalEvidence();
        $data[$key] = $value;
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize($data, true, true, true);
    }

    /** @return array<string,mixed> */
    private static function normalFailure(): array
    {
        return ['role' => 'customer', 'phase' => 'normal_get', 'window_remaining_ms' => 7965,
            'elapsed_ms' => 35, 'request_timeout_ms' => 8000, 'request_elapsed_ms' => 35,
            'deadline_fired' => false, 'shutdown_started' => false, 'browser_context_close_started' => false,
            'browser_context_closed' => false, 'request_context_dispose_started' => false,
            'request_context_disposed' => false, 'window_closed' => false];
    }

    public function testNormalFailureKeepsOnlyClosedOwnedFactsAndNeverDefaultsMissingTiming(): void
    {
        $failure = self::normalFailure();
        $failure['message'] = 'PRIVATE_URL_SECRET';
        $failure['headers'] = ['PRIVATE' => 'PRIVATE'];
        unset($failure['request_elapsed_ms']);
        $data = self::normalEvidence();
        $data['normal_read_failure'] = $failure;
        $safe = HostedBrowser::sanitize($data, true, true, true);
        self::assertIsArray($safe['normal_read_failure']);
        self::assertSame('customer', $safe['normal_read_failure']['role']);
        self::assertSame(7965, $safe['normal_read_failure']['window_remaining_ms']);
        self::assertFalse($safe['normal_read_failure']['shutdown_started']);
        self::assertArrayNotHasKey('request_elapsed_ms', $safe['normal_read_failure']);
        self::assertStringNotContainsString('PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        $minimal = ['result' => 'fail', 'reason' => 'browser_preflight_failed', 'card_entry_enabled' => false,
            'normal_read_failure' => $failure];
        self::assertArrayNotHasKey('normal_read_failure', HostedBrowser::sanitize($minimal));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function unsafeNormalFailures(): iterable
    {
        foreach (
            ['role' => 'PRIVATE', 'phase' => 'PRIVATE_URL', 'window_remaining_ms' => 8001,
            'elapsed_ms' => -1, 'request_timeout_ms' => 0, 'request_elapsed_ms' => 60001,
            'deadline_fired' => 'false', 'shutdown_started' => null,
            'browser_context_closed' => 1, 'request_context_disposed' => true] as $key => $value
        ) {
            yield $key => [$key, $value];
        }
        yield 'float time' => ['elapsed_ms', 1.5];
        yield 'nonfinite time' => ['elapsed_ms', NAN];
    }

    #[DataProvider('unsafeNormalFailures')]
    public function testNormalFailureRejectsUnboundedOrInventedLifecycleFacts(string $key, mixed $value): void
    {
        $data = self::normalEvidence();
        $data['normal_read_failure'] = array_replace(self::normalFailure(), [$key => $value]);
        $this->expectException(RuntimeException::class);
        HostedBrowser::sanitize($data, true, true, true);
    }

    public function testNormalFailureNeedsAnAdmittedRoleAndSentTimeoutProof(): void
    {
        foreach (['normal_customer_attempts', 'normal_customer_sends'] as $key) {
            $data = self::normalEvidence();
            $data['normal_read_failure'] = self::normalFailure();
            $data[$key] = 0;
            $data['normal_customer_delivered'] = 0;
            try {
                HostedBrowser::sanitize($data, true, true, true);
                self::fail('Failure evidence invented an unadmitted or unsent operation.');
            } catch (RuntimeException $error) {
                self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            }
        }
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

    public function testSparseReadDenialsKeepOnlyPositiveClosedFactsAndPreserveAbsence(): void
    {
        $base = ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
            'card_entry_enabled' => false];
        self::assertArrayNotHasKey('read_denial_reasons', HostedBrowser::sanitize($base, true, true));
        $input = $base + ['read_denial_reasons' => [
            'method_config' => ['header_api_version' => 2, 'PRIVATE_REASON' => 'PRIVATE_SECRET'],
            'customer' => [], 'country' => ['initiator_not_bound' => 1, 'header_client_secret' => 7],
            'PRIVATE_ROLE' => ['PRIVATE_RAW' => 'PRIVATE_VALUE'],
        ]];
        $safe = HostedBrowser::sanitize($input, true, true);
        self::assertSame(['method_config' => ['header_api_version' => 2],
            'country' => ['initiator_not_bound' => 1]], $safe['read_denial_reasons']);
        self::assertStringNotContainsString('PRIVATE', json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('read_denial_counts_capped', $safe);
        self::assertArrayNotHasKey('read_denial_reasons', HostedBrowser::sanitize($input, true));
        $unknown = $base + ['read_denial_reasons' => ['customer' => [], 'PRIVATE_ROLE' => ['PRIVATE' => 1]]];
        self::assertArrayNotHasKey('read_denial_reasons', HostedBrowser::sanitize($unknown, true, true));
        $capped = HostedBrowser::sanitize($base + ['read_denial_reasons' => [
            'customer' => ['slot_consumed' => 1000000]], 'read_denial_counts_capped' => true], true, true);
        self::assertTrue($capped['read_denial_counts_capped']);
        self::assertIsArray($capped['read_denial_reasons']);
        self::assertIsArray($capped['read_denial_reasons']['customer']);
        self::assertSame(1000000, $capped['read_denial_reasons']['customer']['slot_consumed']);
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidSparseReadDenialCounts(): iterable
    {
        foreach (
            ['numeric_string' => '1', 'zero' => 0, 'negative' => -1, 'overflow' => 1000001,
            'float' => 1.0, 'boolean' => true, 'null' => null, 'array' => [],
            'private_string' => 'PRIVATE_VALUE'] as $name => $value
        ) {
            yield $name => [$value];
        }
    }

    #[DataProvider('invalidSparseReadDenialCounts')]
    public function testMalformedKnownReadDenialCountIsRejectedWithoutCoercion(mixed $value): void
    {
        try {
            HostedBrowser::sanitize(['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
                'card_entry_enabled' => false, 'read_denial_reasons' => [
                    'method_config' => ['header_api_version' => $value]]], true, true);
            self::fail('Malformed positive counts cannot become zero or a valid fact.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
        }
    }

    public function testSparseMapAndSaturationRequireNativeShapeAndAnObservedCap(): void
    {
        foreach (
            [['read_denial_reasons' => 'PRIVATE'], ['read_denial_reasons' => ['customer' => 'PRIVATE']],
            ['read_denial_counts_capped' => true], ['read_denial_counts_capped' => false],
            ['read_denial_reasons' => ['customer' => ['slot_consumed' => 1]],
            'read_denial_counts_capped' => true]] as $invalid
        ) {
            try {
                HostedBrowser::sanitize(['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
                    'card_entry_enabled' => false] + $invalid, true, true);
                self::fail('Invalid sparse evidence was accepted.');
            } catch (RuntimeException $error) {
                self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            }
        }
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
