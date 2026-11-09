<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use RuntimeException;
use SensitiveParameter;

/** Fresh private process and independently read durable browser receipts; no SDK credentials cross stdin. */
final readonly class InstrumentBrowser
{
    public function __construct(private string $node, private string $playwright)
    {
        if (
            !str_starts_with($node, '/') || realpath($node) !== $node || !is_file($node) || !is_executable($node)
            || !str_starts_with($playwright, '/') || realpath($playwright) !== $playwright
            || !is_file($playwright . '/index.js') || is_link($playwright . '/index.js')
        ) {
            throw new RuntimeException('Explicit canonical instrument tooling is unavailable.');
        }
        $raw = @file_get_contents($playwright . '/package.json', length: 16385);
        if ($raw === false || strlen($raw) > 16384) {
            throw new RuntimeException('The bounded runtime metadata is unavailable.');
        }
        $package = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($package) || ($package['version'] ?? null) !== '1.62.1') {
            throw new RuntimeException('The reviewed Playwright runtime is required.');
        }
    }

    /** @return array<string,string> */
    public static function sourceBook(): array
    {
        $root = dirname(__DIR__, 4);
        $files = glob($root . '/tools/browser/instrument-*.cjs');
        if ($files === false) {
            throw new RuntimeException('Instrument provenance is unavailable.');
        }
        $names = [];
        foreach ($files as $file) {
            if (!str_contains($file, '.test.')) {
                $names[] = 'tools/browser/' . basename($file);
            }
        }
        array_push(
            $names,
            'tools/browser/hosted-instrument-workflow.cjs',
            'tools/browser/hosted-policy.cjs',
            'tools/browser/hosted-risk-policy.cjs'
        );
        sort($names, SORT_STRING);
        $names[] = 'tools/hosted-instrument-workflow.php';
        $book = [];
        foreach ($names as $name) {
            $path = $root . '/' . $name;
            $hash = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            if ($hash === false) {
                throw new RuntimeException('Instrument source must be regular local files.');
            }
            $book[$name] = $hash;
        }
        return $book;
    }

    /**
     * @param array<string,mixed> $packet Private capability and ownership proof, never an API key.
     * @return array<string,mixed>
     */
    public function collect(#[SensitiveParameter] array $packet, string $folder): array
    {
        $environment = ['PATH' => '/usr/bin:/bin', 'LANG' => 'en_US.UTF-8', 'HOME' => $folder . '/home',
            'TMPDIR' => $folder . '/tmp'];
        foreach (['home', 'tmp'] as $name) {
            if (!mkdir($folder . '/' . $name, 0700)) {
                throw new RuntimeException('The disposable browser environment is unavailable.');
            }
        }
        $process = @proc_open(
            [$this->node, dirname(__DIR__, 4) . '/tools/browser/hosted-instrument-workflow.cjs',
            $this->playwright],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $folder,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The instrument process could not start.');
        }
        $clean = false;
        $input = $output = $errorPipe = null;
        try {
            [$input, $output, $errorPipe] = self::pipes($pipes);
            $payload = json_encode($packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($payload) > 32768 || fwrite($input, $payload) !== strlen($payload)) {
                throw new RuntimeException('The private process handoff failed.');
            }
            fclose($input);
            stream_set_blocking($output, false);
            stream_set_blocking($errorPipe, false);
            $deadline = hrtime(true) / 1_000_000_000 + 240;
            $raw = '';
            $hadErrors = false;
            do {
                $chunk = stream_get_contents($output, 16385);
                $discard = stream_get_contents($errorPipe, 16385);
                if ($chunk === false || $discard === false || hrtime(true) / 1_000_000_000 >= $deadline) {
                    throw new RuntimeException('The instrument process exceeded its parent deadline.');
                }
                $raw .= $chunk;
                $hadErrors = $hadErrors || $discard !== '';
                if (strlen($raw) > 16384) {
                    throw new RuntimeException('The instrument receipt exceeded its boundary.');
                }
                $status = proc_get_status($process);
                if (!$status['running'] && feof($output) && feof($errorPipe)) {
                    $clean = true;
                    if ($hadErrors || !in_array($status['exitcode'], [0, 1], true)) {
                        throw new RuntimeException('The instrument process did not close cleanly.');
                    }
                    break;
                }
                usleep(10000);
            } while (true);
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            $safe = self::sanitize($data);
            if (($safe['result'] === 'completed') !== ($status['exitcode'] === 0)) {
                throw new RuntimeException('The process and receipt disagree.');
            }
            return $safe + ['process_reaped' => true];
        } finally {
            foreach ([$input, $output, $errorPipe] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (!$clean) {
                proc_terminate($process);
                $deadline = hrtime(true) / 1_000_000_000 + 2;
                do {
                    $running = proc_get_status($process)['running'];
                    if ($running) {
                        usleep(10000);
                    }
                } while ($running && hrtime(true) / 1_000_000_000 < $deadline);
                if ($running) {
                    proc_terminate($process, 9);
                }
            }
            proc_close($process);
        }
    }

    /** @return array{resource,resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (!is_array($pipes)) {
            throw new RuntimeException('The private process pipes are unavailable.');
        }
        $input = $pipes[0] ?? null;
        $output = $pipes[1] ?? null;
        $errors = $pipes[2] ?? null;
        if (!is_resource($input) || !is_resource($output) || !is_resource($errors)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            throw new RuntimeException('The private process pipes are unavailable.');
        }
        return [$input, $output, $errors];
    }

    /** @return array<string,mixed> */
    public static function sanitize(#[SensitiveParameter] mixed $data): array
    {
        if (
            !is_array($data) || !in_array($data['result'] ?? null, ['completed', 'fail'], true)
            || !in_array($data['phase'] ?? null, ['configuration', 'startup', 'bootstrap', 'input', 'submit',
                'complete',
                'dependency', 'document', 'transport', 'response', 'deadline', 'shutdown'], true)
            || ($data['security_graph_complete'] ?? null) !== false
        ) {
            throw new RuntimeException('The closed instrument receipt is unavailable.');
        }
        $safe = ['result' => $data['result'], 'phase' => $data['phase'], 'security_graph_complete' => false];
        foreach (
            ['http_invocations' => 240, 'withheld_cosmetic' => 49, 'withheld_monitoring' => 34,
                'direct_connection_attempts' => 97,
            'security_requests' => 96, 'feature_requests' => 21,
            'direct_connections_forwarded' => 0, 'journal_records' => 600, 'unresolved_actions' => 240,
            'native_pointer_invocations' => 2] as $key => $cap
        ) {
            if (array_key_exists($key, $data)) {
                if (!is_int($data[$key]) || $data[$key] < 0 || $data[$key] > $cap) {
                    throw new RuntimeException('The finite instrument counts are unavailable.');
                }
                $safe[$key] = $data[$key];
            }
        }
        foreach (
            ['possible_financial_send', 'journal_closed_cleanly', 'cleanup_complete', 'input_attempted',
            'submit_attempted', 'card_choice_attempted', 'loader_verified'] as $key
        ) {
            if (array_key_exists($key, $data)) {
                if (!is_bool($data[$key])) {
                    throw new RuntimeException('The closed instrument fact is invalid.');
                }
                $safe[$key] = $data[$key];
            }
        }
        if ($safe['result'] === 'completed') {
            foreach (['journal_closed_cleanly', 'cleanup_complete', 'input_attempted', 'submit_attempted'] as $key) {
                if (($safe[$key] ?? null) !== true) {
                    throw new RuntimeException('Instrument completion lacks its original action or shutdown proof.');
                }
            }
            if (($safe['unresolved_actions'] ?? null) !== 0 || ($safe['direct_connections_forwarded'] ?? null) !== 0) {
                throw new RuntimeException('Instrument completion has an unresolved native action.');
            }
        }
        return $safe;
    }

    /**
     * Read only after process reaping. Native IDs are returned privately to the SDK controller.
     * @param array<string,mixed> $provenance
     * @return array{identities:array<string,string>,possible_financial_send:bool,unresolved_actions:int,journal_records:int}
     */
    public static function inventory(string $folder, array $provenance): array
    {
        self::privatePath($folder, true);
        $names = scandir($folder);
        if ($names === false) {
            throw new RuntimeException('The private browser journal is unavailable.');
        }
        $names = array_values(array_diff($names, ['.', '..']));
        if ($names === [] || count($names) > 600) {
            throw new RuntimeException('The private browser journal count is invalid.');
        }
        $pending = [];
        $seen = [];
        $received = [];
        $identities = [];
        $financial = false;
        foreach ($names as $index => $name) {
            if ($name !== sprintf('%04d.json', $index)) {
                throw new RuntimeException('The contiguous browser journal is incomplete.');
            }
            $path = $folder . '/' . $name;
            self::privatePath($path, false);
            $raw = file_get_contents($path, length: 16385);
            if ($raw === false || strlen($raw) > 16384) {
                throw new RuntimeException('The bounded browser record is unavailable.');
            }
            $record = (array) WebhookConfigObservation::nativeObject($raw);
            if ($index === 0) {
                $actual = json_decode(
                    json_encode($record['provenance'] ?? null, JSON_THROW_ON_ERROR),
                    true,
                    16,
                    JSON_THROW_ON_ERROR
                );
                if (($record['phase'] ?? null) !== 'profile' || $actual !== $provenance) {
                    throw new RuntimeException('The browser journal provenance changed.');
                }
                continue;
            }
            $action = $record['action'] ?? null;
            $role = $record['role'] ?? null;
            $ordinal = $record['ordinal'] ?? null;
            if (
                !is_string($action) || preg_match('/^[a-f0-9-]{36}$/D', $action) !== 1
                || !is_string($role) || preg_match('/^[a-z_]{1,32}$/D', $role) !== 1
                || !is_int($ordinal) || $ordinal < 0 || $ordinal > 239
            ) {
                throw new RuntimeException('The browser journal action is unbound.');
            }
            $key = $role . ':' . $ordinal;
            if (in_array($record['phase'] ?? null, ['possible_action', 'possible_send'], true)) {
                $isFinancial = in_array($role, ['collect', 'consent_create', 'captcha_submit',
                    'consent_verify', 'complete'], true);
                $isUi = in_array($role, ['card_choice', 'card_input', 'card_submit'], true);
                if (
                    isset($pending[$action]) || isset($seen[$key]) || ($record['financial'] ?? null) !== $isFinancial
                    || ($record['phase'] === 'possible_action') !== $isUi
                    || ($isUi && ($record['method'] ?? null) !== 'UI')
                    || (!$isUi && !in_array($record['method'] ?? null, ['GET', 'POST', 'OPTIONS'], true))
                    || ($isFinancial && (($record['method'] ?? null) !== 'POST' || $ordinal !== 0))
                    || !is_int($record['body_bytes'] ?? null) || $record['body_bytes'] < 0
                    || $record['body_bytes'] > 65536
                ) {
                    throw new RuntimeException('The browser journal contains a replay.');
                }
                foreach (['target_sha256', 'body_sha256', 'headers_sha256', 'scope_sha256'] as $field) {
                    if (
                        !is_string($record[$field] ?? null)
                        || preg_match('/^[a-f0-9]{64}$/D', $record[$field]) !== 1
                    ) {
                        throw new RuntimeException('The original browser wire hashes are unavailable.');
                    }
                }
                $requestId = $record['source_request_id'] ?? null;
                if (
                    (in_array($role, ['consent_create', 'captcha_submit', 'consent_verify'], true)
                    || $requestId !== null) && (!is_string($requestId)
                    || preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $requestId) !== 1)
                ) {
                    throw new RuntimeException('The original source request identity is unavailable.');
                }
                $pending[$action] = $key;
                $seen[$key] = true;
                $financial = $financial || $isFinancial;
            } elseif (($record['phase'] ?? null) === 'observed' && ($pending[$action] ?? null) === $key) {
                if (
                    isset($received[$action]) || !is_string($record['response_sha256'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $record['response_sha256']) !== 1
                    || !is_int($record['response_bytes'] ?? null) || $record['response_bytes'] < 0
                    || $record['response_bytes'] > 2097152 || !is_int($record['http_status'] ?? null)
                    || ($record['http_status'] !== 0 && ($record['http_status'] < 200 || $record['http_status'] > 599))
                ) {
                    throw new RuntimeException('The original browser result is unavailable or repeated.');
                }
                $received[$action] = true;
                $values = $record['identities'] ?? null;
                if (
                    !$values instanceof \stdClass || !in_array(
                        $record['response_validation'] ?? null,
                        ['matched', 'withheld'],
                        true
                    )
                ) {
                    throw new RuntimeException('The browser identity inventory is unavailable.');
                }
                foreach ((array) $values as $kind => $id) {
                    if (
                        !in_array($kind, ['consent_id', 'payments_customer_id', 'method_id', 'initial_intent_id',
                        'checkout_id', 'source_id'], true)
                    ) {
                        throw new RuntimeException('An unknown identity family was returned.');
                    }
                    $id = InstrumentApiTransport::identity($id);
                    if (isset($identities[$kind]) && $identities[$kind] !== $id) {
                        throw new RuntimeException('The original browser identity changed.');
                    }
                    $identities[$kind] = $id;
                }
                if ($record['response_validation'] === 'matched') {
                    unset($pending[$action]);
                }
            } else {
                throw new RuntimeException('The browser journal contains an unmatched result.');
            }
        }
        return ['identities' => $identities, 'possible_financial_send' => $financial,
            'unresolved_actions' => count($pending), 'journal_records' => count($names)];
    }

    public static function privatePath(string $path, bool $directory): void
    {
        $stat = @lstat($path);
        if (
            realpath($path) !== $path || is_link($path) || $stat === false
            || ($directory ? !is_dir($path) : !is_file($path))
            || ($stat['mode'] & 0777) !== ($directory ? 0700 : 0600)
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
        ) {
            throw new RuntimeException('The canonical private instrument path is unavailable.');
        }
        for ($ancestor = $directory ? $path : dirname($path);; $ancestor = dirname($ancestor)) {
            if (file_exists($ancestor . '/.git') || is_link($ancestor . '/.git')) {
                throw new RuntimeException('Private instrument state must remain outside Git.');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
    }
}
