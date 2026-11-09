<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedSetupPreflight;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedBrowser;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedRiskJournal;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedSecurityMode;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class HostedSetupPreflightTest extends TestCase
{
    private string $directory;
    /** @var list<RequestInterface> */
    private array $requests = [];
    /** @var array<array-key,mixed> */
    private array $checkout = [];
    private int $cancels = 0;
    private int $browserCalls = 0;
    private int $simulatedTelemetrySends = 0;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-hosted-fixture-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/module', 0700);
        file_put_contents($this->directory . '/module/index.js', 'module.exports = {};');
        file_put_contents(
            $this->directory . '/credentials',
            "AIRWALLEX_CLIENT_ID=fixture_client\nAIRWALLEX_SANDBOX_API_KEY_MASTER=fixture_api_key\n"
            . "AIRWALLEX_ACCOUNT_ID=fixture_account\n",
        );
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $this->directory . '/credentials',
            'state-dir' => $this->directory, 'node-bin' => PHP_BINARY,
            'playwright-module' => $this->directory . '/module',
            'create-one-hosted-setup' => true];
    }

    /**
     * @param array<string,mixed>|null $options
     * @return array<string,mixed>
     */
    private function execute(
        string $fault = '',
        ?array $options = null,
        HostedSecurityMode $mode = HostedSecurityMode::Preflight,
    ): array {
        $originalOptions = $options;
        if ($mode === HostedSecurityMode::InstrumentSecurity) {
            $options = $this->options() + ['render-owned-data' => true, 'observe-one-method-config' => true,
                'observe-normal-reads' => true, 'observe-risk-bootstrap' => true];
        }
        $transport = new HttpClient(['handler' => function (RequestInterface $request) use (
            $fault,
            $options,
            $originalOptions,
        ): Response {
            $this->requests[] = $request;
            $path = $request->getUri()->getPath();
            if ($path === '/api/v1/authentication/login') {
                if ($fault === 'late_tool_change') {
                    $path = dirname(__DIR__, 4) . '/tools/browser/hosted-risk-relay.cjs';
                    $original = file_get_contents($path);
                    self::assertIsString($original);
                    file_put_contents($path, $original . "\n// synthetic post-capture change; never executed\n");
                }
                if (($options['observe-risk-bootstrap'] ?? false) === true) {
                    $paths = glob($this->directory . '/sdk-validation-*/ledger.json');
                    self::assertIsArray($paths);
                    self::assertCount(1, $paths);
                    $private = file_get_contents($paths[0]);
                    self::assertIsString($private);
                    $state = json_decode($private, true, flags: JSON_THROW_ON_ERROR);
                    self::assertIsArray($state);
                    self::assertIsArray($state['actions']);
                    self::assertArrayHasKey('preflight_risk_bootstrap', $state['actions']);
                    if (($originalOptions['retain-one-exact-same-document'] ?? false) === true) {
                        self::assertArrayHasKey('instrument_security_profile', $state['actions']);
                    }
                }
                return $this->json(['token' => 'fixture_token', 'expires_at' => '2099-01-01T00:00:00Z']);
            }
            if ($path === '/api/v1/account') {
                return $this->json([
                    'id' => 'fixture_account', 'account_details' => ['legal_entity_id' => 'fixture_entity'],
                ]);
            }
            if (str_ends_with($path, '/billing_customers/create')) {
                return $this->json(['id' => 'fixture_customer']);
            }
            if (str_ends_with($path, '/billing_checkouts/create')) {
                if ($fault === 'create_rejected') {
                    return new Response(400, [], '{"code":"PRIVATE_CODE","source":"PRIVATE_SOURCE"}');
                }
                $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                unset($body['request_id']);
                $this->checkout = $body + ['id' => 'fixture_checkout', 'status' => 'ACTIVE',
                    'url' => 'https://checkout.airwallex.com/pay?s=PRIVATE_SYNTHETIC_SESSION'];
                return $this->json(['id' => 'fixture_checkout']);
            }
            if (str_ends_with($path, '/fixture_checkout/cancel')) {
                $this->cancels++;
                if ($fault === 'cancel_ambiguous') {
                    throw new RuntimeException('PRIVATE_BROWSER_ERROR');
                }
                $this->checkout['status'] = 'CANCELLED';
                unset($this->checkout['url']);
                return $this->json($this->checkout);
            }
            if (str_ends_with($path, '/fixture_checkout')) {
                $data = $this->checkout;
                if ($this->cancels === 0 && $this->browserCalls === 0) {
                    $field = str_starts_with($fault, 'mismatch_') ? substr($fault, 9) : null;
                    if ($field !== null) {
                        $data[$field] = 'PRIVATE_WRONG_VALUE';
                    }
                }
                if (
                    ($fault === 'cleanup_owner_mismatch' && $this->browserCalls > 0 && $this->cancels === 0)
                    || ($fault === 'cancel_owner_mismatch' && $this->cancels > 0)
                ) {
                    $data['billing_customer_id'] = 'PRIVATE_WRONG_VALUE';
                }
                return $this->json($data);
            }
            throw new RuntimeException('Unexpected synthetic request.');
        }]);
        return HostedSetupPreflight::run(
            $mode === HostedSecurityMode::InstrumentSecurity
                ? (array_diff_key($this->options(), ['create-one-hosted-setup' => true])
                    + ['observe-first-party-security' => true] + ($originalOptions ?? []))
                : ($options ?? $this->options()),
            static fn (Configuration $config, string $context): SandboxSession
                => new SandboxSession($config, $context, $transport),
            function (
                string $url,
                array $expected,
                bool $render,
                bool $observeConfig,
                bool $observeNormal,
                bool $observeRisk,
                ?HostedRiskJournal $riskJournal,
                bool $observeParentCountry,
                bool $observeCurrencySettings,
                bool $selectCard,
                HostedSecurityMode $actualMode,
                bool $retainSameDocument,
            ) use (
                $fault,
                $options,
                $mode,
                $originalOptions,
            ): array {
                self::assertSame($mode, $actualMode);
                self::assertSame(
                    ($originalOptions['retain-one-exact-same-document'] ?? false) === true,
                    $retainSameDocument
                );
                self::assertSame(($options['render-owned-data'] ?? false) === true, $render);
                self::assertSame(($options['observe-one-method-config'] ?? false) === true, $observeConfig);
                self::assertSame(($options['observe-normal-reads'] ?? false) === true, $observeNormal);
                self::assertSame(($options['observe-risk-bootstrap'] ?? false) === true, $observeRisk);
                self::assertSame($observeRisk, $riskJournal !== null);
                self::assertSame(($options['observe-parent-country'] ?? false) === true, $observeParentCountry);
                self::assertSame(
                    ($options['observe-one-currency-settings'] ?? false) === true,
                    $observeCurrencySettings
                );
                self::assertSame(($options['select-one-native-card'] ?? false) === true, $selectCard);
                self::assertSame([
                    'checkout_id' => 'fixture_checkout', 'mode' => 'SETUP', 'currency' => 'EUR',
                ], $expected);
                $paths = glob($this->directory . '/sdk-validation-*/ledger.json');
                self::assertIsArray($paths);
                self::assertCount(1, $paths);
                $contents = file_get_contents($paths[0]);
                self::assertIsString($contents);
                $state = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($state);
                self::assertIsArray($state['actions']);
                if ($mode === HostedSecurityMode::InstrumentSecurity) {
                    self::assertArrayHasKey('instrument_security_profile', $state['actions']);
                    $intent = ['profile' => $mode->value, 'browser_case_max_ms' => 40000,
                        'new_admission_max_ms' => 30000, 'response_release_max_ms' => 38000,
                        'loader_wait_max_ms' => 2000, 'security_stage_max_ms' => 15000,
                        'loader_limit_bytes' => 262144, 'loader_encoding' => 'identity',
                        'input_authority' => false, 'financial_authority' => false,
                        'browser_tools' => HostedBrowser::sourceHashes($mode)];
                    if ($retainSameDocument) {
                        $intent['native_navigation'] = ['profile' => 'one_exact_same_document',
                            'decision' => 120, 'limit' => 1, 'playwright_version' => '1.62.1',
                            'core_bundle_bytes' => 3425603,
                            'core_bundle_sha256' => '9393fa79e1c67c74edc26b610d65a4f7ed73d345a762465cc88340a33a2454ac'];
                    }
                    self::assertIsArray($state['actions']['instrument_security_profile']);
                    self::assertSame(
                        hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)),
                        $state['actions']['instrument_security_profile']['body_hash']
                    );
                    self::assertArrayNotHasKey('preflight_native_card_choice', $state['actions']);
                }
                if ($observeCurrencySettings) {
                    self::assertIsArray($state['actions']['preflight_currency_settings']);
                    self::assertSame(hash('sha256', json_encode($expected + [
                    'method' => 'GET', 'path' => '/api/v1/pa/config/currency_settings',
                    'scope' => 'original_main_and_matched_payer_transaction_secret', 'limit' => 1,
                    'normal_window_max_ms' => 8000,
                    ], JSON_THROW_ON_ERROR)), $state['actions']['preflight_currency_settings']['body_hash']);
                } else {
                    self::assertArrayNotHasKey('preflight_currency_settings', $state['actions']);
                }
                if ($observeRisk) {
                    self::assertIsArray($state['actions']['preflight_risk_bootstrap']);
                    self::assertNotNull($riskJournal);
                    self::assertSame(1, $riskJournal->bootstrap()['version']);
                } else {
                    self::assertArrayNotHasKey('preflight_risk_bootstrap', $state['actions']);
                }
                if ($observeParentCountry) {
                    self::assertIsArray($state['actions']['preflight_parent_country']);
                    self::assertSame(hash('sha256', json_encode($expected + [
                        'method' => 'GET', 'role' => 'parent_country',
                        'scope' => 'original_sdk_correlated_main_document',
                        'startup_window_max_ms' => 8000, 'normal_window_max_ms' => 8000, 'limit' => 1,
                    ], JSON_THROW_ON_ERROR)), $state['actions']['preflight_parent_country']['body_hash']);
                } else {
                    self::assertArrayNotHasKey('preflight_parent_country', $state['actions']);
                }
                if ($selectCard) {
                    self::assertIsArray($state['actions']['preflight_native_card_choice']);
                    self::assertSame(hash('sha256', json_encode($expected + [
                        'action' => 'one_unforced_native_card_radio', 'scope' => 'original_qualified_dropin_document',
                        'financial_authority' => false, 'capture_window_max_ms' => 5500,
                    ], JSON_THROW_ON_ERROR)), $state['actions']['preflight_native_card_choice']['body_hash']);
                } else {
                    self::assertArrayNotHasKey('preflight_native_card_choice', $state['actions']);
                }
                self::assertIsArray($state['actions']['preflight_payer_read']);
                self::assertSame(hash('sha256', json_encode($expected + [
                    'method' => 'GET', 'path' => '/billing/payer-web-api/checkout',
                ], JSON_THROW_ON_ERROR)), $state['actions']['preflight_payer_read']['body_hash']);
                if ($observeConfig) {
                    self::assertIsArray($state['actions']['preflight_method_config_read']);
                    self::assertSame(hash('sha256', json_encode($expected + [
                        'method' => 'GET', 'path' => '/api/v1/pa/config/payment_method_types',
                        'query' => ['page_num' => 0, 'page_size' => 200, 'active' => true,
                            'transaction_currency' => 'EUR', '__verification_methods' => true],
                        'browser_api_version' => '2024-06-14', 'scope' => 'verified_own_dropin_frame',
                    ], JSON_THROW_ON_ERROR)), $state['actions']['preflight_method_config_read']['body_hash']);
                } else {
                    self::assertArrayNotHasKey('preflight_method_config_read', $state['actions']);
                }
                foreach (
                    ['customer', 'consent_list', 'country', 'checkout_status_0',
                    'checkout_status_1', 'checkout_status_2'] as $role
                ) {
                    if ($observeNormal) {
                        $action = $state['actions']['preflight_normal_' . $role] ?? null;
                        self::assertIsArray($action);
                        self::assertSame(hash('sha256', json_encode($expected + [
                            'method' => 'GET', 'role' => $role, 'scope' => 'fresh_payer_and_exact_original_frame',
                            'target_data' => 'bound_in_private_browser_memory', 'observation_window_max_ms' => 8000,
                        ], JSON_THROW_ON_ERROR)), $action['body_hash']);
                    } else {
                        self::assertArrayNotHasKey('preflight_normal_' . $role, $state['actions']);
                    }
                }
                self::assertStringNotContainsString('PRIVATE_SYNTHETIC_SESSION', $contents);
                $this->browserCalls++;
                self::assertSame('https://checkout.airwallex.com/pay?s=PRIVATE_SYNTHETIC_SESSION', $url);
                if (in_array($fault, ['risk_unacknowledged', 'late_tool_change'], true)) {
                    self::assertNotNull($riskJournal);
                    $riskJournal->accept(json_encode($riskJournal->bootstrap() + [
                        'sequence' => 1, 'phase' => 'prepare', 'role' => 'visitor', 'ordinal' => 0,
                        'body_sha256' => hash('sha256', 'PRIVATE_WIRE'), 'body_bytes' => 12,
                        'target_uri' => 'https://bws.sandbox.airwallex.com/bws/v3/visit',
                        'target_sha256' => hash('sha256', 'https://bws.sandbox.airwallex.com/bws/v3/visit'),
                        'headers_sha256' => hash('sha256', 'PRIVATE_HEADERS'),
                        'session_sha256' => hash('sha256', 'PRIVATE_SESSION'),
                        'scope_sha256' => hash('sha256', 'PRIVATE_SCOPE'),
                    ], JSON_THROW_ON_ERROR));
                    $this->simulatedTelemetrySends++;
                    throw new RuntimeException('PRIVATE_POSSIBLE_TELEMETRY_SEND');
                }
                if ($fault === 'browser_throws') {
                    throw new RuntimeException('PRIVATE_BROWSER_ERROR');
                }
                if ($fault === 'browser_safe_failure') {
                    return ['result' => 'fail', 'reason' => 'browser_preflight_failed', 'card_entry_enabled' => false,
                        'browser_failure_phase' => 'method_get', 'bound_render_frames' => 1,
                        'blocked_requests' => 3, 'private_error' => 'PRIVATE_BROWSER_ERROR'];
                }
                return ['result' => 'blocked', 'reason' => 'financial_sandbox_boundary_unverified',
                    'card_entry_enabled' => false, 'test_mode_visible' => true,
                    ...($mode === HostedSecurityMode::InstrumentSecurity ? ['instrument_security_requested' => true,
                        'instrument_ready' => false, 'financial_permission' => false,
                        'security_graph_complete' => false,
                        'security_loader_candidate' => true, 'security_loader_promoted' => false,
                        'security_loader_wait_reason' => 'expired'] : []),
                    ...($retainSameDocument ? ['security_same_document_requested' => true,
                        'security_same_document_retained' => false,
                        'security_same_document_reason' => 'not_observed'] : []),
                    ...($selectCard ? ['native_card_selection_requested' => true,
                        'native_card_selection' => 'withheld', 'native_card_action_attempts' => 0,
                        'native_card_action_invoked' => false] : []),
                    'private_url' => $url, 'private_message' => 'PRIVATE_BROWSER_ERROR'];
            },
            $mode,
        );
    }

    public function testLegacyRiskJournalUsesPreauthBookAndRejectsALaterHelperChangeBeforeSend(): void
    {
        $helper = dirname(__DIR__, 4) . '/tools/browser/hosted-risk-relay.cjs';
        $original = file_get_contents($helper);
        self::assertIsString($original);
        try {
            $result = $this->execute('late_tool_change', $this->options() + [
                'render-owned-data' => true, 'observe-one-method-config' => true,
                'observe-normal-reads' => true, 'observe-risk-bootstrap' => true,
            ]);
            self::assertSame('fail', $result['result']);
            self::assertSame(1, $this->browserCalls);
            self::assertSame(0, $this->simulatedTelemetrySends);
            self::assertSame(['checkout_0' => 'pass'], $result['cleanup']);
        } finally {
            file_put_contents($helper, $original);
        }
    }

    public function testSeparateSecurityModeKeepsPrivateArgumentOrderAndNeverSelectsCard(): void
    {
        $result = $this->execute(mode: HostedSecurityMode::InstrumentSecurity);
        self::assertSame('hosted-instrument-security', $result['scenario']);
        self::assertSame('blocked', $result['result']);
        self::assertFalse($result['instrument_ready']);
        self::assertFalse($result['financial_permission']);
        self::assertSame(['checkout_0' => 'pass'], $result['cleanup']);
        self::assertSame(1, $this->cancels);
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testSelectedNativeNavigationProfileIsBoundBeforeAuthAndHandedOffOnlyInInstrument(): void
    {
        $result = $this->execute(
            options: ['retain-one-exact-same-document' => true],
            mode: HostedSecurityMode::InstrumentSecurity
        );
        self::assertSame('blocked', $result['result']);
        self::assertIsArray($result['browser']);
        self::assertTrue($result['browser']['security_same_document_requested']);
        self::assertFalse($result['browser']['security_same_document_retained']);
        self::assertSame(1, $this->browserCalls);
        self::assertSame(1, $this->cancels);
    }

    public function testNativeNavigationProfileCannotEnterLegacyOrUseAValue(): void
    {
        foreach ([HostedSecurityMode::Preflight, HostedSecurityMode::InstrumentSecurity] as $mode) {
            $result = HostedSetupPreflight::run(
                ['environment' => 'sandbox',
                'observe-first-party-security' => true, 'retain-one-exact-same-document' => 'PRIVATE_VALUE'],
                mode: $mode
            );
            self::assertSame('fail', $result['result']);
            self::assertSame('configuration', $result['phase']);
        }
        self::assertSame([], $this->requests);
    }

    public function testNewModeRejectsCardOverrideBeforeAuth(): void
    {
        $result = HostedSetupPreflight::run(
            ['environment' => 'sandbox',
            'observe-first-party-security' => true, 'select-one-native-card' => true],
            mode: HostedSecurityMode::InstrumentSecurity
        );
        self::assertSame('configuration', $result['phase']);
        self::assertSame('fail', $result['result']);
        self::assertSame([], $this->requests);
    }

    public function testOneFreshOwnedSetupPrivateHandoffAndOneCancellation(): void
    {
        $result = $this->execute();
        self::assertSame('blocked', $result['result']);
        self::assertSame(['customers' => 1, 'checkouts' => 1], $result['created_counts']);
        self::assertSame(['checkout_0' => 'pass'], $result['cleanup']);
        self::assertSame(1, $this->browserCalls);
        self::assertSame(1, $this->cancels);
        $creates = array_values(array_filter($this->requests, static fn (RequestInterface $request): bool
            => str_ends_with($request->getUri()->getPath(), '/billing_checkouts/create')));
        self::assertCount(1, $creates);
        $body = json_decode((string) $creates[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('SETUP', $body['mode']);
        self::assertSame('HOSTED', $body['ui_mode']);
        self::assertSame('EUR', $body['currency']);
        self::assertArrayNotHasKey('return_url', $body);
        self::assertArrayNotHasKey('line_items', $body);
        self::assertArrayHasKey('request_id', $body);
        foreach ($this->requests as $request) {
            self::assertSame('api.sandbox.airwallex.com', $request->getUri()->getHost());
            self::assertFalse($request->hasHeader('x-on-behalf-of'));
            if (str_ends_with($request->getUri()->getPath(), '/cancel')) {
                self::assertSame('', (string) $request->getBody());
            }
        }
        self::assertSame('fixture_account', $this->requests[0]->getHeaderLine('x-login-as'));
        self::assertSame('/api/v1/account', $this->requests[1]->getUri()->getPath());
        self::assertSame('', $this->requests[2]->getHeaderLine('x-login-as'));
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (
            ['fixture_client', 'fixture_api_key', 'fixture_account', 'fixture_entity', 'fixture_customer',
            'fixture_checkout', 'fixture_token', 'PRIVATE_SYNTHETIC_SESSION', 'PRIVATE_BROWSER_ERROR'] as $private
        ) {
            self::assertStringNotContainsString($private, $encoded);
        }
        self::assertIsArray($result['browser_tool_hashes']);
        self::assertCount(12, $result['browser_tool_hashes']);
        self::assertArrayHasKey('hosted-currency-settings.cjs', $result['browser_tool_hashes']);
        self::assertArrayHasKey('hosted-card-choice.cjs', $result['browser_tool_hashes']);
        self::assertArrayHasKey('hosted-parent-country.cjs', $result['browser_tool_hashes']);
        foreach (['policy', 'journal', 'relay', 'observer'] as $helper) {
            self::assertArrayHasKey('hosted-risk-' . $helper . '.cjs', $result['browser_tool_hashes']);
        }
    }

    public function testExplicitRenderingStillCreatesAndCancelsOnlyOneOwnedSetup(): void
    {
        $options = $this->options();
        $options['render-owned-data'] = true;
        $result = $this->execute(options: $options);
        self::assertTrue($result['render_requested']);
        self::assertSame('blocked', $result['result']);
        self::assertSame(1, $this->browserCalls);
        self::assertSame(1, $this->cancels);
        self::assertSame(['customers' => 1, 'checkouts' => 1], $result['created_counts']);
    }

    public function testFiniteNormalSlotsAreDurableWithoutPretendingUnknownWireDataWasKnown(): void
    {
        $options = $this->options();
        $options['render-owned-data'] = true;
        $options['observe-one-method-config'] = true;
        $options['observe-normal-reads'] = true;
        $result = $this->execute(options: $options);
        self::assertTrue($result['normal_reads_requested']);
        self::assertSame(1, $this->browserCalls);
        self::assertSame(['checkout_0' => 'pass'], $result['cleanup']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testPartialRiskTelemetryHasPrivatePreauthProfileAndStillCleansOnlyItsOwnedCheckout(): void
    {
        $options = $this->options();
        foreach (
            ['render-owned-data', 'observe-one-method-config',
                'observe-normal-reads', 'observe-risk-bootstrap'] as $flag
        ) {
            $options[$flag] = true;
        }
        $result = $this->execute(options: $options);
        self::assertTrue($result['risk_bootstrap_requested']);
        self::assertSame('blocked', $result['result']);
        self::assertSame(1, $this->cancels);
        self::assertSame(1, $this->browserCalls);
        self::assertIsArray($result['browser']);
        self::assertFalse($result['browser']['risk_graph_complete']);
        self::assertFalse($result['browser']['risk_financial_ready']);
    }

    public function testUnknownNonfinancialTelemetryOutcomeDoesNotInventAReasonToRetainTheOwnedActiveCheckout(): void
    {
        $options = $this->options();
        foreach (
            ['render-owned-data', 'observe-one-method-config',
                'observe-normal-reads', 'observe-risk-bootstrap'] as $flag
        ) {
            $options[$flag] = true;
        }
        $result = $this->execute('risk_unacknowledged', $options);
        self::assertSame('fail', $result['result']);
        self::assertSame(1, $this->cancels);
        self::assertSame(['checkout_0' => 'pass'], $result['cleanup']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testNativeCardChoiceAddsOnePrivateNonfinancialIntentAndPreservesOwnedCleanup(): void
    {
        $options = $this->options();
        foreach (
            ['render-owned-data', 'observe-one-method-config', 'observe-normal-reads',
            'observe-risk-bootstrap', 'observe-parent-country', 'observe-one-currency-settings',
            'select-one-native-card'] as $flag
        ) {
            $options[$flag] = true;
        }
        $result = $this->execute(options: $options);
        self::assertTrue($result['native_card_selection_requested']);
        self::assertSame('blocked', $result['result']);
        self::assertSame(1, $this->browserCalls);
        self::assertSame(['checkout_0' => 'pass'], $result['cleanup']);
        self::assertIsArray($result['browser']);
        self::assertSame('withheld', $result['browser']['native_card_selection']);
        self::assertSame(0, $result['browser']['native_card_action_attempts']);
        self::assertFalse($result['browser']['card_entry_enabled']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testParentCountryHasItsOwnPrivateSlotBeforeBrowserHandoffAndNoFinancialPermission(): void
    {
        $options = $this->options();
        foreach (
            ['render-owned-data', 'observe-one-method-config', 'observe-normal-reads',
            'observe-parent-country'] as $flag
        ) {
            $options[$flag] = true;
        }
        $result = $this->execute(options: $options);
        self::assertTrue($result['parent_country_requested']);
        self::assertSame(1, $this->browserCalls);
        self::assertSame(['checkout_0' => 'pass'], $result['cleanup']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testParentCountryAndRiskKeepIndependentPrivateArgumentsAndDurableSlots(): void
    {
        $options = $this->options();
        foreach (
            ['render-owned-data', 'observe-one-method-config', 'observe-normal-reads',
                'observe-risk-bootstrap', 'observe-parent-country'] as $flag
        ) {
            $options[$flag] = true;
        }
        $result = $this->execute(options: $options);
        self::assertTrue($result['parent_country_requested']);
        self::assertTrue($result['risk_bootstrap_requested']);
        self::assertSame(1, $this->browserCalls);
        self::assertSame(['checkout_0' => 'pass'], $result['cleanup']);
        self::assertIsArray($result['browser']);
        self::assertFalse($result['browser']['risk_financial_ready']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testCurrencyPrivateRoleIsDurableBeforeHandoffWithIndependentParentRiskSelectors(): void
    {
        $options = $this->options();
        foreach (
            ['render-owned-data', 'observe-one-method-config', 'observe-normal-reads',
            'observe-one-currency-settings', 'observe-parent-country', 'observe-risk-bootstrap'] as $flag
        ) {
            $options[$flag] = true;
        }
        $result = $this->execute(options: $options);
        self::assertTrue($result['currency_settings_requested']);
        self::assertTrue($result['parent_country_requested']);
        self::assertTrue($result['risk_bootstrap_requested']);
        self::assertSame(1, $this->browserCalls);
        self::assertSame(['checkout_0' => 'pass'], $result['cleanup']);
        self::assertIsArray($result['browser']);
        self::assertFalse($result['browser']['card_entry_enabled']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testMethodReadIntentIsDurableBeforePrivateBrowserHandoff(): void
    {
        $options = $this->options();
        $options['render-owned-data'] = true;
        $options['observe-one-method-config'] = true;
        $result = $this->execute(options: $options);
        self::assertTrue($result['method_config_requested']);
        self::assertSame('blocked', $result['result']);
        self::assertSame(1, $this->browserCalls);
        self::assertSame(1, $this->cancels);
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string}> */
    public static function mismatches(): iterable
    {
        foreach (
            ['id', 'status', 'billing_customer_id', 'legal_entity_id', 'linked_payment_account_id',
            'mode', 'ui_mode', 'currency', 'metadata', 'url'] as $field
        ) {
            yield $field => ['mismatch_' . $field];
        }
    }

    #[DataProvider('mismatches')]
    public function testWrongIdentityOrContextStopsBeforeBrowser(string $fault): void
    {
        $result = $this->execute($fault);
        self::assertSame('fail', $result['result']);
        self::assertSame('checkout_read', $result['phase']);
        self::assertSame(0, $this->browserCalls);
        if ($fault !== 'mismatch_url') {
            self::assertSame(0, $this->cancels);
            self::assertSame(['checkout_0' => 'fail'], $result['cleanup']);
        }
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testFixedBrowserFailurePhaseSurvivesWhileOwnCleanupStillRuns(): void
    {
        $result = $this->execute('browser_safe_failure');
        self::assertSame('fail', $result['result']);
        self::assertSame('browser_read_only', $result['phase']);
        self::assertIsArray($result['browser']);
        self::assertSame('method_get', $result['browser']['browser_failure_phase']);
        self::assertSame(1, $result['browser']['bound_render_frames']);
        self::assertSame(['checkout_0' => 'pass'], $result['cleanup']);
        self::assertSame(1, $this->cancels);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testBrowserFailureStillCancelsOnlyItsCreatedCheckout(): void
    {
        $result = $this->execute('browser_throws');
        self::assertSame('fail', $result['result']);
        self::assertSame('browser_read_only', $result['phase']);
        self::assertSame(['checkout_0' => 'pass'], $result['cleanup']);
        self::assertSame(1, $this->cancels);
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testAmbiguousCancellationIsNeverRepeated(): void
    {
        $result = $this->execute('cancel_ambiguous');
        self::assertSame('fail', $result['result']);
        self::assertSame(['checkout_0' => 'fail'], $result['cleanup']);
        self::assertSame(1, $this->cancels);
    }

    public function testFreshCleanupReadChecksOwnershipAgainBeforeCancel(): void
    {
        $result = $this->execute('cleanup_owner_mismatch');
        self::assertSame('fail', $result['result']);
        self::assertSame(['checkout_0' => 'fail'], $result['cleanup']);
        self::assertSame(1, $this->browserCalls);
        self::assertSame(0, $this->cancels);
    }

    public function testCancelledReadbackStillRequiresOwnedContext(): void
    {
        $result = $this->execute('cancel_owner_mismatch');
        self::assertSame('fail', $result['result']);
        self::assertSame(['checkout_0' => 'fail'], $result['cleanup']);
        self::assertSame(1, $this->cancels);
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testRejectedCreateHasNoBrowserOrInventedCleanupIdentity(): void
    {
        $result = $this->execute('create_rejected');
        self::assertSame(400, $result['http_status']);
        self::assertSame('checkout_create', $result['phase']);
        self::assertSame(['customers' => 1], $result['created_counts']);
        self::assertSame([], $result['cleanup']);
        self::assertSame(0, $this->browserCalls);
        self::assertSame(0, $this->cancels);
        self::assertStringNotContainsString('PRIVATE_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidOptions(): iterable
    {
        yield 'production' => ['environment', 'production'];
        yield 'creation not selected' => ['create-one-hosted-setup', false];
        yield 'URL injection' => ['url', 'https://private.example.test/PRIVATE_SESSION'];
        yield 'context override' => ['context', 'account'];
        yield 'old ledger injection' => ['ledger', '/private/fixture-ledger'];
        yield 'SDK root injection' => ['sdk-root', '/private/fixture-root'];
        yield 'node absent' => ['node-bin', '/nonexistent-fixture-node'];
        yield 'invalid render mode' => ['render-owned-data', 'PRIVATE_MODE'];
        yield 'method without render' => ['observe-one-method-config', true];
        yield 'invalid method mode' => ['observe-one-method-config', 'PRIVATE_MODE'];
        yield 'normal without method' => ['observe-normal-reads', true];
        yield 'invalid normal flag' => ['observe-normal-reads', 'PRIVATE_MODE'];
        yield 'currency without normal' => ['observe-one-currency-settings', true];
        yield 'currency invalid flag' => ['observe-one-currency-settings', 'PRIVATE_MODE'];
        yield 'parent without normal' => ['observe-parent-country', true];
        yield 'parent invalid flag' => ['observe-parent-country', 'PRIVATE_MODE'];
        yield 'Card choice missing normal' => ['select-one-native-card', true];
        yield 'Card choice arbitrary value' => ['select-one-native-card', 'PRIVATE_MODE'];
        yield 'risk missing normal' => ['observe-risk-bootstrap', true];
        yield 'risk arbitrary value' => ['observe-risk-bootstrap', 'PRIVATE_MODE'];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidConfigurationMakesNoRequests(string $key, mixed $value): void
    {
        $options = $this->options();
        $options[$key] = $value;
        $result = $this->execute(options: $options);
        self::assertSame('fail', $result['result']);
        self::assertSame('configuration', $result['phase']);
        self::assertSame([], $this->requests);
        self::assertSame(0, $this->browserCalls);
    }

    /** @return iterable<string,array{list<string>}> */
    public static function invalidMethodFlags(): iterable
    {
        yield 'requires rendering' => [['--observe-one-method-config']];
        yield 'normal missing method' => [['--render-owned-data', '--observe-normal-reads']];
        yield 'normal value' => [['--observe-normal-reads=true']];
        yield 'normal duplicate' => [['--observe-normal-reads', '--observe-normal-reads']];
        yield 'Card missing gates' => [['--select-one-native-card']];
        yield 'Card value' => [['--select-one-native-card=true']];
        yield 'Card duplicate' => [['--select-one-native-card', '--select-one-native-card']];
        yield 'risk missing gates' => [['--observe-risk-bootstrap']];
        yield 'risk value' => [['--observe-risk-bootstrap=true']];
        yield 'risk duplicate' => [['--observe-risk-bootstrap', '--observe-risk-bootstrap']];
        yield 'rejects a flag value' => [['--render-owned-data', '--observe-one-method-config=true']];
        yield 'rejects duplicate flags' => [['--render-owned-data', '--observe-one-method-config',
            '--observe-one-method-config']];
    }

    /** @param list<string> $flags */
    #[DataProvider('invalidMethodFlags')]
    public function testRealCliRejectsInvalidMethodFlagsBeforeConfigurationReads(array $flags): void
    {
        $process = proc_open([
            PHP_BINARY, dirname(__DIR__, 4) . '/tools/hosted-setup-preflight.php', '--environment=sandbox',
            '--credentials-file=/DO-NOT-READ-PRIVATE-fixture', '--state-dir=/DO-NOT-WRITE-PRIVATE-fixture',
            '--node-bin=/NONEXISTENT-fixture', '--playwright-module=/NONEXISTENT-fixture',
            '--create-one-hosted-setup', ...$flags,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        [$outputPipe, $errorPipe] = self::outputPipes($pipes);
        $output = stream_get_contents($outputPipe);
        $errors = stream_get_contents($errorPipe);
        fclose($outputPipe);
        fclose($errorPipe);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $errors);
        self::assertIsString($output);
        $safe = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($safe);
        self::assertSame('configuration', $safe['phase']);
        self::assertSame('fail', $safe['result']);
        self::assertArrayNotHasKey('source', $safe);
        self::assertArrayNotHasKey('context_fingerprint', $safe);
        self::assertStringNotContainsString('PRIVATE', $output);
    }

    /** @return array{resource,resource} */
    private static function outputPipes(mixed $pipes): array
    {
        if (!is_array($pipes) || !is_resource($pipes[1] ?? null) || !is_resource($pipes[2] ?? null)) {
            throw new RuntimeException('Synthetic command pipes are unavailable.');
        }
        return [$pipes[1], $pipes[2]];
    }

    /** @param array<array-key,mixed> $data */
    private function json(array $data): Response
    {
        return new Response(200, [], json_encode($data, JSON_THROW_ON_ERROR));
    }
}
