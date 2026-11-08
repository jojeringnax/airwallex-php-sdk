<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use RuntimeException;
use SensitiveParameter;

/** Private stdin handoff to development-only Node tooling; no URL enters argv or the environment. */
final readonly class HostedBrowser
{
    public function __construct(private string $node, private string $playwright)
    {
        if (
            !str_starts_with($node, '/') || !is_file($node) || !is_executable($node)
            || !str_starts_with($playwright, '/') || !is_file($playwright . '/index.js')
        ) {
            throw new RuntimeException('Explicit local browser tooling is unavailable.');
        }
    }

    public static function candidate(#[SensitiveParameter] mixed $value): bool
    {
        if (
            !is_string($value) || strlen($value) > 16384
            || preg_match('/[\x00-\x20\\\\]/', $value) === 1
        ) {
            return false;
        }
        $url = parse_url($value);
        if (
            !is_array($url) || ($url['scheme'] ?? null) !== 'https'
            || !in_array($url['host'] ?? null, ['checkout.airwallex.com', 'checkout.sandbox.airwallex.com'], true)
            || isset($url['user']) || isset($url['pass']) || (($url['port'] ?? 443) !== 443)
            || ($url['path'] ?? null) !== '/pay'
        ) {
            return false;
        }
        parse_str($url['query'] ?? '', $query);
        return is_string($query['s'] ?? null) && $query['s'] !== '';
    }

    /**
     * @param array{checkout_id:string,mode:string,currency:string} $expected SDK-verified own Checkout.
     * @return array<string,string|int|bool|array<string,int>>
     */
    public function inspect(
        #[SensitiveParameter] string $url,
        #[SensitiveParameter] array $expected,
        bool $render = false,
        bool $observeConfig = false,
        bool $observeNormal = false,
        bool $observeRisk = false,
        ?HostedRiskJournal $riskJournal = null,
    ): array {
        $keys = array_keys($expected);
        sort($keys);
        if (
            ($observeConfig && !$render) || ($observeNormal && !$observeConfig)
            || ($observeRisk && !$observeNormal) || ($observeRisk !== ($riskJournal !== null))
            || !self::candidate($url) || $keys !== ['checkout_id', 'currency', 'mode']
            || $expected['checkout_id'] === '' || strlen($expected['checkout_id']) > 256
            || preg_match('/[\x00-\x20]/', $expected['checkout_id']) === 1
            || $expected['mode'] !== 'SETUP' || preg_match('/^[A-Z]{3}$/D', $expected['currency']) !== 1
        ) {
            throw new RuntimeException('Checkout preflight URL is not an approved candidate.');
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($observeRisk) {
            // Anonymous pipes only: child writes 3, reads 4. Chrome receives neither descriptor.
            $descriptors[3] = ['pipe', 'w'];
            $descriptors[4] = ['pipe', 'r'];
        }
        $process = @proc_open(
            [$this->node, dirname(__DIR__, 4) . '/tools/browser/hosted-browser.cjs', $this->playwright],
            $descriptors,
            $pipes,
            null,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'LANG' => 'en_US.UTF-8'],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Browser preflight could not start.');
        }
        [$input, $output, $errors, $journalRead, $journalAck] = self::pipes($pipes);
        try {
            $handoff = [
                'url' => $url, 'expected' => $expected, 'render' => $render,
                'observeConfig' => $observeConfig, 'observeNormal' => $observeNormal,
            ];
            if ($riskJournal !== null) {
                if (!is_resource($journalRead) || !is_resource($journalAck)) {
                    throw new RuntimeException('Private risk pipes are unavailable.');
                }
                stream_set_blocking($journalRead, false);
                stream_set_blocking($journalAck, false);
                $handoff['observeRisk'] = true;
                $handoff['riskJournal'] = $riskJournal->bootstrap();
            }
            $payload = json_encode($handoff, JSON_THROW_ON_ERROR);
            if (@fwrite($input, $payload) !== strlen($payload)) {
                throw new RuntimeException('Private browser handoff failed.');
            }
            fclose($input);
            stream_set_blocking($output, false);
            stream_set_blocking($errors, false);
            $contents = '';
            $hadErrors = false;
            $journalBuffer = '';
            $journalFailed = false;
            $deadline = microtime(true) + ($render ? 60 : 40);
            do {
                if ($riskJournal !== null && is_resource($journalRead) && is_resource($journalAck)) {
                    try {
                        self::journalStep($journalRead, $journalAck, $journalBuffer, $riskJournal);
                    } catch (\Throwable) {
                        $journalFailed = true;
                        // EOF denies any later send while the worker closes its own browser/relay normally.
                        fclose($journalAck);
                        fclose($journalRead);
                    }
                }
                $chunk = stream_get_contents($output, 8193);
                $discard = stream_get_contents($errors, 8193);
                if ($chunk === false || $discard === false || microtime(true) > $deadline) {
                    throw new RuntimeException('Browser preflight did not complete safely.');
                }
                $contents .= $chunk;
                $hadErrors = $hadErrors || $discard !== '';
                if (strlen($contents) > 8192) {
                    throw new RuntimeException('Browser preflight output exceeds its safe boundary.');
                }
                $status = proc_get_status($process);
                if (feof($output) && feof($errors) && !$status['running']) {
                    if (!in_array($status['exitcode'], [0, 1], true)) {
                        throw new RuntimeException('Browser preflight process failed.');
                    }
                    break;
                }
                usleep(10000);
            } while (true);
            if ($hadErrors) {
                throw new RuntimeException('Browser preflight emitted unexpected diagnostics.');
            }
            $safe = self::sanitize(
                json_decode($contents, true, 16, JSON_THROW_ON_ERROR),
                $render,
                $observeConfig,
                $observeNormal,
                $observeRisk,
            );
            if ($journalFailed) {
                $safe['risk_parent_journal_failed'] = true;
                $safe['result'] = 'fail';
                $safe['reason'] = 'browser_preflight_failed';
            }
            if ($safe['result'] === 'blocked' && $status['exitcode'] !== 0) {
                throw new RuntimeException('Browser preflight process failed.');
            }
            return $safe;
        } finally {
            foreach ([$input, $output, $errors, $journalRead, $journalAck] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }

    /** @return array<string,string|int|bool|array<string,int>> */
    public static function sanitize(
        #[SensitiveParameter] mixed $data,
        bool $render = false,
        bool $observeConfig = false,
        bool $observeNormal = false,
        bool $observeRisk = false,
    ): array {
        if (($observeConfig && !$render) || ($observeNormal && !$observeConfig) || ($observeRisk && !$observeNormal)) {
            throw new RuntimeException('Method observation requires explicit rendering.');
        }
        if (!is_array($data) || ($data['card_entry_enabled'] ?? null) !== false) {
            throw new RuntimeException('Browser preflight returned invalid evidence.');
        }
        $result = $data['result'] ?? null;
        $reason = $data['reason'] ?? null;
        if (
            !in_array($result, ['blocked', 'fail'], true)
            || !in_array($reason, ['financial_sandbox_boundary_unverified', 'browser_preflight_failed'], true)
        ) {
            throw new RuntimeException('Browser preflight returned invalid evidence.');
        }
        $safe = ['result' => $result, 'reason' => $reason, 'card_entry_enabled' => false];
        $failurePhases = ['configuration', 'tooling_load', 'browser_startup', 'proxy_start', 'browser_launch',
            'browser_context', 'page_setup', 'navigation', 'route_action', 'watchdog', 'visible_label',
            'method_context', 'method_get', 'method_status', 'method_headers', 'method_body', 'method_fulfill',
            'method_response_dispose', 'method_abort', 'websocket_close', 'dialog_dismiss',
            'shutdown_browser_context', 'shutdown_browser', 'shutdown_request_context', 'shutdown_proxy',
            'shutdown_directory', 'readiness_attachment', 'unclassified', 'normal_observation_deadline',
            'normal_context', 'normal_get', 'normal_status', 'normal_headers', 'normal_body',
            'normal_fulfill', 'normal_response_dispose', 'normal_abort'];
        $failurePhases[] = 'risk_shutdown';
        foreach (['cardnumber', 'expiry', 'cvc'] as $field) {
            foreach (['locator', 'count', 'visible', 'enabled'] as $step) {
                $failurePhases[] = 'readiness_' . $field . '_' . $step;
            }
        }
        if ($result === 'fail' && in_array($data['browser_failure_phase'] ?? null, $failurePhases, true)) {
            $safe['browser_failure_phase'] = $data['browser_failure_phase'];
        }

        if (in_array($data['candidate_origin'] ?? null, ['shared', 'sandbox'], true)) {
            $safe['candidate_origin'] = $data['candidate_origin'];
        }
        foreach (
            ['navigation_passed', 'test_mode_visible',
            'checkout_token_matches', 'payer_request_scope_verified', 'payer_get_attempted',
            'payer_response_observed', 'payer_mode_matches', 'payer_currency_matches',
            'render_requested', 'render_frame_binding_available'] as $key
        ) {
            if (isset($data[$key]) && is_bool($data[$key])) {
                $safe[$key] = $data[$key];
            }
        }
        foreach (
            ['sandbox_origin_verified', 'payer_customer_mapping_verified',
                'financial_routing_verified'] as $key
        ) {
            if (array_key_exists($key, $data)) {
                if ($data[$key] !== false) {
                    throw new RuntimeException('Browser preflight exceeded its read-only evidence boundary.');
                }
                $safe[$key] = false;
            }
        }
        if (array_key_exists('render_requested', $data) && $data['render_requested'] !== $render) {
            throw new RuntimeException('Browser rendering did not match its explicit configuration.');
        }
        if (array_key_exists('payer_payload_delivered', $data)) {
            $delivered = $data['payer_payload_delivered'];
            if ($delivered !== false) {
                if (
                    $delivered !== true || !$render || ($data['candidate_origin'] ?? null) !== 'sandbox'
                    || ($data['payer_http_status'] ?? null) !== 200
                    || ($data['payer_observation'] ?? null) !== 'matched'
                ) {
                    throw new RuntimeException('Browser rendering lacked the verified own response.');
                }
                foreach (
                    ['checkout_token_matches', 'payer_request_scope_verified', 'payer_get_attempted',
                    'payer_response_observed', 'payer_mode_matches', 'payer_currency_matches'] as $key
                ) {
                    if (($data[$key] ?? null) !== true) {
                        throw new RuntimeException('Browser rendering lacked the verified own response.');
                    }
                }
            }
            $safe['payer_payload_delivered'] = $delivered;
        }
        if (array_key_exists('method_config_requested', $data) && $data['method_config_requested'] !== $observeConfig) {
            throw new RuntimeException('Method observation did not match its explicit configuration.');
        }
        $methodDelivered = $data['method_config_payload_delivered'] ?? false;
        if ($methodDelivered !== false) {
            if (
                $methodDelivered !== true || !$observeConfig
                || ($safe['payer_payload_delivered'] ?? false) !== true
                || ($data['method_config_http_status'] ?? null) !== 200
                || ($data['method_config_observation'] ?? null) !== 'matched'
                || ($data['method_config_get_attempted'] ?? null) !== true
                || ($data['method_config_response_observed'] ?? null) !== true
                || !is_int($data['bound_render_frames'] ?? null) || $data['bound_render_frames'] < 1
            ) {
                throw new RuntimeException('Method observation lacked the bound read-only response.');
            }
        }
        $detachStage = $data['readiness_detach_stage'] ?? null;
        if (($data['card_readiness_unavailable_reason'] ?? null) === 'frame_detached') {
            if (
                !$observeConfig || ($data['card_readiness_observed'] ?? null) !== false
                || ($data['readiness_frame_detached'] ?? null) !== true
                || !in_array($detachStage, ['before_count', 'after_count'], true)
                || !is_int($data['bound_render_frames'] ?? null) || $data['bound_render_frames'] < 1
                || !in_array($data['readiness_attachment_field'] ?? null, ['cardnumber', 'expiry', 'cvc'], true)
                || ($detachStage === 'before_count'
                    ? ($data['readiness_frame_detached_before_count'] ?? null) !== true
                    : ($data['readiness_frame_detached_after_count'] ?? null) !== true)
            ) {
                throw new RuntimeException('Detached-frame evidence lacked its read-only observation.');
            }
            $safe['card_readiness_unavailable_reason'] = 'frame_detached';
            $safe['readiness_frame_detached'] = true;
            $safe['readiness_detach_stage'] = $detachStage;
        }
        if ($observeConfig) {
            if (in_array($data['readiness_attachment_field'] ?? null, ['cardnumber', 'expiry', 'cvc'], true)) {
                $safe['readiness_attachment_field'] = $data['readiness_attachment_field'];
            }
            foreach (
                ['method_config_requested', 'method_config_get_attempted', 'method_config_send_started',
                'method_config_response_observed',
                'method_config_payload_delivered', 'method_config_pagination_known', 'method_config_has_more',
                'method_config_card_advertised', 'card_readiness_observed',
                'readiness_frame_detached_before_count', 'readiness_frame_detached_after_count',
                'cardnumber_unique', 'cardnumber_visible',
                'cardnumber_enabled', 'expiry_unique', 'expiry_visible', 'expiry_enabled',
                'cvc_unique', 'cvc_visible', 'cvc_enabled'] as $key
            ) {
                if (
                    preg_match('/^(?:cardnumber|expiry|cvc)_/', $key) === 1
                    && ($data['card_readiness_observed'] ?? null) !== true
                ) {
                    continue;
                }
                if (is_bool($data[$key] ?? null)) {
                    $safe[$key] = $data[$key];
                }
            }
            $observation = $data['method_config_observation'] ?? null;
            if (
                in_array($observation, ['not_observed', 'matched', 'invalid_response',
                'redirect_rejected', 'http_rejected'], true)
            ) {
                $safe['method_config_observation'] = $observation;
            }
            $status = $data['method_config_http_status'] ?? null;
            if (is_int($status) && $status >= 100 && $status <= 599) {
                $safe['method_config_http_status'] = $status;
            }
            $items = $data['method_config_items'] ?? null;
            if (is_int($items) && $items >= 0 && $items <= 200) {
                $safe['method_config_items'] = $items;
            }
            if (is_array($data['denied_read_families'] ?? null)) {
                $families = [];
                foreach (
                    ['method_config', 'country', 'customer', 'consent_list', 'checkout_status',
                    'other_pa'] as $key
                ) {
                    $count = $data['denied_read_families'][$key] ?? null;
                    if (is_int($count) && $count >= 0 && $count <= 1000000) {
                        $families[$key] = $count;
                    }
                }
                $safe['denied_read_families'] = $families;
            }
        }
        if (
            in_array($data['payer_observation'] ?? null, [
            'not_observed', 'matched', 'mode_or_currency_mismatch', 'invalid_response',
            'redirect_rejected', 'http_rejected',
            ], true)
        ) {
            $safe['payer_observation'] = $data['payer_observation'];
        }
        if (array_key_exists('normal_reads_requested', $data) && $data['normal_reads_requested'] !== $observeNormal) {
            throw new RuntimeException('Normal reads did not match the explicit configuration.');
        }
        if ($observeNormal) {
            $safe['normal_reads_requested'] = true;
            foreach (
                ['normal_read_window_closed', 'normal_read_inspection_completed',
                'normal_checkout_status_poisoned'] as $key
            ) {
                if (is_bool($data[$key] ?? null)) {
                    $safe[$key] = $data[$key];
                }
            }
            if (
                ($data['normal_read_inspection_completed'] ?? null) === true
                && (($data['card_readiness_observed'] ?? null) !== true
                    || ($data['normal_read_window_closed'] ?? null) !== true
                    || ($safe['payer_payload_delivered'] ?? null) !== true
                    || ($data['bound_render_frames'] ?? 0) < 1)
            ) {
                throw new RuntimeException('Normal-read inspection lacked its completed bounded observation.');
            }
            foreach (['customer', 'consent_list', 'country', 'checkout_status'] as $role) {
                $limit = $role === 'checkout_status' ? 3 : 1;
                $key = 'normal_' . $role . '_';
                if (
                    !array_key_exists($key . 'attempts', $data)
                    && !array_key_exists($key . 'sends', $data) && !array_key_exists($key . 'delivered', $data)
                ) {
                    continue;
                }
                $counts = ['attempts' => 0, 'sends' => 0, 'delivered' => 0];
                foreach (['attempts', 'sends', 'delivered'] as $fact) {
                    $count = $data[$key . $fact] ?? null;
                    if (!is_int($count) || $count < 0 || $count > $limit) {
                        throw new RuntimeException('Normal-read evidence exceeded its finite budget.');
                    }
                    $counts[$fact] = $count;
                    $safe[$key . $fact] = $count;
                }
                if (
                    $counts['delivered'] > $counts['sends'] || $counts['sends'] > $counts['attempts']
                    || ($counts['sends'] > 0 && (($safe['payer_payload_delivered'] ?? null) !== true
                        || ($role !== 'checkout_status' && ($data['bound_render_frames'] ?? 0) < 1)))
                ) {
                    throw new RuntimeException('Normal reads lacked the verified own handoff.');
                }
                $observation = $data[$key . 'observation'] ?? null;
                if (
                    in_array($observation, ['not_observed', 'matched', 'invalid_response', 'unavailable',
                    'redirect_rejected', 'http_rejected'], true)
                ) {
                    $safe[$key . 'observation'] = $observation;
                }
                $status = $data[$key . 'http_status'] ?? null;
                if (is_int($status) && $status >= 100 && $status <= 599) {
                    $safe[$key . 'http_status'] = $status;
                }
                if (
                    $role !== 'checkout_status' && $counts['delivered'] > 0
                    && ($observation !== 'matched' || $status !== 200)
                ) {
                    throw new RuntimeException('Normal delivery lacked its validated response.');
                }
            }
            $items = $data['normal_consent_list_items'] ?? null;
            if (is_int($items) && $items >= 0 && $items <= 100) {
                $safe['normal_consent_list_items'] = $items;
            }
            foreach (['normal_consent_list_pagination_known', 'normal_consent_list_has_more'] as $key) {
                if (is_bool($data[$key] ?? null)) {
                    $safe[$key] = $data[$key];
                }
            }
        }
        $counters = ['blocked_requests', 'blocked_websockets', 'returns_intercepted',
            'proxy_rejections', 'proxy_connections', 'proxy_external_connections',
            'verified_static_assets', 'integrity_rejections', 'verified_render_assets', 'bound_render_frames',
            'controller_asset_requests', 'verified_controller_assets', 'controller_replays_blocked'];
        foreach ($counters as $key) {
            if (isset($data[$key]) && is_int($data[$key]) && $data[$key] >= 0 && $data[$key] <= 1000000) {
                $safe[$key] = $data[$key];
            }
        }
        if ($render && is_array($data['render_frame_checks'] ?? null)) {
            $checks = [];
            foreach (
                ['document_candidates', 'method_mismatch', 'frame_unavailable', 'parent_mismatch',
                'request_origin_mismatch', 'request_path_or_query_mismatch', 'source_unavailable',
                'scope_unverified', 'payer_not_delivered', 'reference_unavailable', 'limit_reached',
                'invalid_source_url', 'source_origin_mismatch', 'source_path_or_query_mismatch',
                'fragment_route_mismatch', 'fragment_keys_mismatch', 'build_mismatch', 'language_mismatch',
                'invalid_options', 'mode_mismatch', 'currency_mismatch', 'secret_mismatch',
                'customer_mismatch', 'intent_mismatch', 'qualified', 'document_redirect',
                'document_http_or_build_mismatch', 'fulfilled'] as $key
            ) {
                $count = $data['render_frame_checks'][$key] ?? null;
                if (is_int($count) && $count >= 0 && $count <= 1000000) {
                    $checks[$key] = $count;
                }
            }
            $safe['render_frame_checks'] = $checks;
        }
        $controllerStatus = $data['controller_http_status'] ?? null;
        if (is_int($controllerStatus) && $controllerStatus >= 100 && $controllerStatus <= 599) {
            $safe['controller_http_status'] = $controllerStatus;
        }
        $payerStatus = $data['payer_http_status'] ?? null;
        if (is_int($payerStatus) && $payerStatus >= 100 && $payerStatus <= 599) {
            $safe['payer_http_status'] = $payerStatus;
        }
        if ($observeRisk) {
            $safe += self::riskEvidence($data, $safe);
        }
        $version = $data['browser_version'] ?? null;
        if (is_string($version) && preg_match('/^[0-9]+(?:\.[0-9]+){1,3}$/D', $version) === 1) {
            $safe['browser_version'] = $version;
        }
        return $safe;
    }

    /** @return array<string,string> */
    public static function sourceHashes(): array
    {
        $root = dirname(__DIR__, 4);
        $hashes = [];
        foreach (
            ['hosted-policy.cjs', 'hosted-browser.cjs', 'hosted-smoke.cjs', 'hosted-policy.test.cjs',
                'hosted-risk-policy.cjs', 'hosted-risk-journal.cjs', 'hosted-risk-relay.cjs',
                'hosted-risk-observer.cjs'] as $file
        ) {
            $hash = hash_file('sha256', $root . '/tools/browser/' . $file);
            if ($hash === false) {
                throw new RuntimeException('Browser preflight source is unavailable.');
            }
            $hashes[$file] = $hash;
        }
        $hash = hash_file('sha256', $root . '/tools/hosted-setup-preflight.php');
        if ($hash === false) {
            throw new RuntimeException('Browser preflight source is unavailable.');
        }
        $hashes['hosted-setup-preflight.php'] = $hash;
        return $hashes;
    }

    /**
     * @param resource $input
     * @param resource $output
     */
    private static function journalStep($input, $output, string &$buffer, HostedRiskJournal $journal): void
    {
        $chunk = stream_get_contents($input, 4097);
        if ($chunk === false) {
            throw new RuntimeException('Private risk framing is unavailable.');
        }
        $buffer .= $chunk;
        if (strlen($buffer) > 4096 || (feof($input) && $buffer !== '')) {
            throw new RuntimeException('Private risk framing is invalid.');
        }
        $newline = strpos($buffer, "\n");
        if ($newline === false) {
            return;
        }
        if ($newline !== strlen($buffer) - 1) {
            throw new RuntimeException('Private risk framing is invalid.');
        }
        $ack = $journal->accept(substr($buffer, 0, $newline));
        $buffer = '';
        $written = 0;
        $deadline = microtime(true) + 0.1;
        do {
            $count = @fwrite($output, substr($ack, $written));
            if ($count === false || $count < 1 || microtime(true) >= $deadline) {
                throw new RuntimeException('Private risk acknowledgement is unavailable.');
            }
            $written += $count;
        } while ($written < strlen($ack));
    }

    /**
     * @param array<array-key,mixed> $data
     * @param array<string,mixed> $base
     * @return array<string,string|int|bool|array<string,int>>
     */
    private static function riskEvidence(array $data, array $base): array
    {
        $safe = ['risk_bootstrap_requested' => true, 'risk_graph_complete' => false, 'risk_financial_ready' => false];
        if (isset($data['risk_bootstrap_requested']) && $data['risk_bootstrap_requested'] !== true) {
            throw new RuntimeException('Risk evidence did not match its explicit configuration.');
        }
        foreach (['risk_graph_complete', 'risk_financial_ready'] as $key) {
            if (isset($data[$key]) && $data[$key] !== false) {
                throw new RuntimeException('Risk observation exceeded its partial evidence boundary.');
            }
        }
        foreach (
            ['risk_loader_attempted', 'risk_loader_verified', 'risk_telemetry_poisoned',
                'risk_journal_poisoned', 'risk_journal_closed', 'risk_parent_journal_failed'] as $key
        ) {
            if (is_bool($data[$key] ?? null)) {
                $safe[$key] = $data[$key];
            }
        }
        if (($safe['risk_loader_verified'] ?? false) === true) {
            if (
                ($safe['risk_loader_attempted'] ?? false) !== true
                || ($base['payer_payload_delivered'] ?? false) !== true
                || ($base['candidate_origin'] ?? null) !== 'sandbox' || ($base['bound_render_frames'] ?? 0) < 1
            ) {
                throw new RuntimeException('Risk loader lacked the owned read-only scope.');
            }
        }
        $reasons = ['not_observed', 'scope_unavailable', 'session_mismatch', 'loader_replay',
            'loader_integrity',
            'cookie_unbound', 'unknown_dependency', 'budget', 'journal', 'response', 'window',
            'document_changed', 'shutdown', 'cors', 'headers', 'body'];
        if (in_array($data['risk_partial_reason'] ?? null, $reasons, true)) {
            $safe['risk_partial_reason'] = $data['risk_partial_reason'];
        }
        foreach (['visitor' => 1, 'preflight' => 1, 'event' => 8] as $role => $cap) {
            $previous = $cap;
            foreach (['attempts', 'client_invocations', 'delivered'] as $kind) {
                $key = 'risk_' . $role . '_' . $kind;
                if (!array_key_exists($key, $data)) {
                    continue;
                }
                $value = $data[$key];
                if (!is_int($value) || $value < 0 || $value > $previous) {
                    throw new RuntimeException('Risk observation returned invalid finite counts.');
                }
                $safe[$key] = $value;
                $previous = $value;
            }
            $status = $data['risk_' . $role . '_http_status'] ?? null;
            if (is_int($status) && $status >= 100 && $status <= 599) {
                $safe['risk_' . $role . '_http_status'] = $status;
            }
        }
        foreach (['risk_journal_prepared', 'risk_journal_results'] as $key) {
            $value = $data[$key] ?? null;
            if (is_int($value) && $value >= 0 && $value <= 10) {
                $safe[$key] = $value;
            }
        }
        $dependencies = $data['risk_denied_dependencies'] ?? null;
        if (is_array($dependencies)) {
            $closed = [];
            foreach (['risk_document', 'captcha', 'forter', 'sardine', 'monitoring', 'other'] as $key) {
                $value = $dependencies[$key] ?? null;
                if (is_int($value) && $value >= 0 && $value <= 1000000) {
                    $closed[$key] = $value;
                }
            }
            $safe['risk_denied_dependencies'] = $closed;
        }
        return $safe;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['browser' => 'private preflight process'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Browser preflight cannot be serialized.');
    }

    /** @return array{resource,resource,resource,resource|null,resource|null} */
    private static function pipes(mixed $pipes): array
    {
        if (!is_array($pipes)) {
            throw new RuntimeException('Browser preflight pipes are unavailable.');
        }
        $input = $pipes[0] ?? null;
        $output = $pipes[1] ?? null;
        $errors = $pipes[2] ?? null;
        if (!is_resource($input) || !is_resource($output) || !is_resource($errors)) {
            throw new RuntimeException('Browser preflight pipes are unavailable.');
        }
        $journalRead = $pipes[3] ?? null;
        $journalAck = $pipes[4] ?? null;
        return [$input, $output, $errors,
            is_resource($journalRead) ? $journalRead : null, is_resource($journalAck) ? $journalAck : null];
    }
}
