<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use RuntimeException;
use SensitiveParameter;

/** Fresh SDK proof and optional new cleanup; never resumes a previous financial journey. */
final class KlarnaIntentReconciliation
{
    private bool $used = false;
    /** @param array<string,mixed> $expected
     * @param array{base_commit:string,sdk_digest:string,harness_digest:string} $source
     * @param list<string> $priorIdentities
     */
    private function __construct(
        #[SensitiveParameter] private readonly string $path,
        #[SensitiveParameter] private readonly string $stateRoot,
        #[SensitiveParameter] private readonly string $intent,
        #[SensitiveParameter] private readonly string $marker,
        private readonly string $fingerprint,
        private readonly string $digest,
        private readonly array $source,
        private readonly string $case,
        private readonly string $amount,
        private readonly array $expected,
        private readonly bool $priorCancel,
        private readonly array $priorIdentities,
    ) {
    }

    public static function load(
        #[SensitiveParameter] string $path,
        #[SensitiveParameter] string $stateDirectory,
        SandboxSession $session,
    ): self {
        if (
            $session->isOrganization() || !str_starts_with($path, '/') || realpath($path) !== $path
            || !is_file($path) || is_link($path) || realpath($stateDirectory) !== $stateDirectory
        ) {
            throw new RuntimeException('Canonical private account state is required.');
        }
        self::privateState($path, $stateDirectory);
        $raw = @file_get_contents($path, length: 1048577);
        if ($raw === false || strlen($raw) > 1048576) {
            throw new RuntimeException('The prior private state is unavailable.');
        }
        self::priorLiteralTypes(self::assertUniqueJsonObject($raw));
        $state = (new JsonCodec())->decodeObject($raw);
        $source = $state['source'] ?? null;
        $marker = $state['run_id'] ?? null;
        $actions = $state['actions'] ?? null;
        $resources = $state['resources'] ?? null;
        $observations = $state['observations'] ?? null;
        $current = SourceEvidence::capture();
        if (
            ($state['api_version'] ?? null) !== '2026-08-21'
            || ($state['context_fingerprint'] ?? null) !== $session->contextFingerprint()
            || !is_string($marker)
            || preg_match('/^sdk-validation-[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $marker) !== 1
            || basename(dirname($path)) !== $marker || basename($path) !== 'ledger.json'
            || !is_array($source) || !is_string($source['base_commit'] ?? null)
            || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $source['base_commit']) !== 1
            || !is_string($source['sdk_digest'] ?? null) || $source['sdk_digest'] !== $current['sdk_digest']
            || !is_string($source['harness_digest'] ?? null) || !self::hashValue($source['harness_digest'])
            || !is_array($actions) || !is_array($resources) || !is_array($observations)
            || array_keys($resources) !== ['payment_intents']
            || !is_array($resources['payment_intents'])
            || !array_is_list($resources['payment_intents']) || count($resources['payment_intents']) !== 1
        ) {
            throw new RuntimeException('The original version, source, account or inventory does not match.');
        }
        $intent = self::identity($resources['payment_intents'][0]);
        $identities = [];
        $allowed = ['klarna_preflight', 'klarna_create', 'klarna_confirm',
            'klarna_clean_cancel', 'klarna_authorized_cancel'];
        foreach ($actions as $name => $action) {
            if (
                !in_array($name, $allowed, true) || !is_array($action)
                || !self::hashValue($action['body_hash'] ?? null)
            ) {
                throw new RuntimeException('The prior action inventory is outside this reconciliation.');
            }
            $id = $action['request_id'] ?? null;
            if (
                !is_string($id) || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $id) !== 1
                || in_array($id, $identities, true)
            ) {
                throw new RuntimeException('The prior action identity is invalid.');
            }
            $identities[] = $id;
        }
        foreach (['klarna_preflight', 'klarna_create', 'klarna_confirm'] as $name) {
            if (!is_array($actions[$name] ?? null)) {
                throw new RuntimeException('The original fixed Klarna intents are required.');
            }
        }
        $createObservation = $observations['klarna_create_response'] ?? null;
        $confirmTarget = $observations['klarna_confirm_target'] ?? null;
        if (
            !is_array($createObservation) || ($createObservation['id'] ?? null) !== $intent
            || !is_array($confirmTarget) || ($confirmTarget['intent_id'] ?? null) !== $intent
        ) {
            throw new RuntimeException('The original returned Intent relationship is unavailable.');
        }
        $createHash = self::actionHash($actions, 'klarna_create');
        $confirmHash = self::actionHash($actions, 'klarna_confirm');
        $preflightHash = self::actionHash($actions, 'klarna_preflight');
        $amount = null;
        foreach (['1.23', '100.00'] as $candidate) {
            if (hash_equals(self::hashBody(self::createBody($marker, $candidate)), $createHash)) {
                $amount = $candidate;
            }
        }
        $confirm = ['payment_method' => ['type' => 'klarna', 'klarna' => ['country_code' => 'DE', 'language' => 'en']],
            'payment_method_options' => ['klarna' => ['auto_capture' => false]]];
        if ($amount === null || !hash_equals(self::hashBody($confirm), $confirmHash)) {
            throw new RuntimeException('The original fixed application requests do not match.');
        }
        $toolHash = hash('sha256', self::priorTool($source['base_commit']));
        $case = null;
        foreach (['capture-refund', 'authorized-cancel'] as $candidate) {
            $legacy = ['case' => $candidate, 'tool_sha256' => $toolHash, 'api_version' => '2026-08-21'];
            $currentBinding = ['case' => $candidate, 'amount' => $amount,
                'amount_choice' => $amount === '100.00' ? 'guide' : 'default',
                'tool_sha256' => $toolHash, 'api_version' => '2026-08-21'];
            if (
                hash_equals(self::hashBody($currentBinding), $preflightHash)
                || ($amount === '1.23' && hash_equals(self::hashBody($legacy), $preflightHash))
            ) {
                $case = $candidate;
            }
        }
        if ($case === null) {
            throw new RuntimeException('The original fixed case/tool binding is unavailable.');
        }
        return new self(
            $path,
            $stateDirectory,
            $intent,
            $marker,
            $session->contextFingerprint(),
            hash('sha256', $raw),
            ['base_commit' => $source['base_commit'], 'sdk_digest' => $source['sdk_digest'],
                'harness_digest' => $source['harness_digest']],
            $case,
            $amount,
            self::createBody($marker, $amount),
            isset($actions['klarna_clean_cancel']) || isset($actions['klarna_authorized_cancel']),
            $identities
        );
    }

    /** @return array<string,mixed> */
    public static function createBody(string $marker, string $amount): array
    {
        if (!in_array($amount, ['1.23', '100.00'], true)) {
            throw new RuntimeException('Only the recorded fixed Klarna amounts are supported.');
        }
        return ['amount' => $amount, 'currency' => 'EUR',
            'merchant_order_id' => substr($marker, -36) . '-klarna',
            'metadata' => ['sdk_validation_run' => $marker],
            'return_url' => 'https://example.invalid/sdk-sandbox-klarna-return',
            'order' => ['products' => [['name' => 'SDK sandbox test product',
                'quantity' => 1, 'unit_price' => $amount]]]];
    }

    /** Bind the only transport target before the first login; no caller identity parameter exists. */
    public function bind(KlarnaReconciliationTransport $transport): void
    {
        $transport->bindIntent($this->intent);
    }

    /** @param (Closure(int):void)|null $wait
     * @return array<string,mixed>
     */
    public function execute(
        SandboxSession $session,
        RunLedger $ledger,
        KlarnaReconciliationTransport $transport,
        bool $cancel,
        ?Closure $wait = null,
        int $attempts = 7,
        ?KlarnaReconciliationClaim $claim = null
    ): array {
        if (
            $this->used || $session->isOrganization() || $session->contextFingerprint() !== $this->fingerprint
            || $attempts < 1 || $attempts > 7
        ) {
            throw new RuntimeException('Reconciliation cannot be reused or cross account contexts.');
        }
        $this->used = true;
        $ledger->assertContext($this->fingerprint);
        $wait ??= static function (int $seconds): void {
            sleep($seconds);
        };
        $claim ??= new KlarnaReconciliationClaim($this->stateRoot, $this->fingerprint, $this->marker, $this->intent);
        $safe = ['scenario' => 'klarna-intent-reconciliation', 'api_version' => '2026-08-21', 'result' => 'blocked',
            'catalogue_version' => '2026-08-21', 'endpoint_version' => '2026-08-21',
            'authentication_context' => 'account',
            'mode' => $cancel ? 'cancel-unconfirmed' : 'read-only', 'phase' => 'preflight',
            'run_id' => $ledger->runId(),
            'case' => $this->case, 'amount_choice' => $this->amount === '100.00' ? 'guide' : 'default',
            'prior_ledger_sha256' => $this->digest, 'prior_source' => $this->source,
            'own_intent_verified' => false, 'zero_captured_verified' => false, 'zero_refunds_verified' => false,
            'pre_cancel_refunds_empty' => false, 'post_cancel_refunds_empty' => false,
            'cancel_sent' => false, 'cancelled_verified' => false, 'prior_ledger_unchanged' => false,
            'reconciliation_required' => true, 'prior_journey_result_rewritten' => false] + $ledger->evidence();
        try {
            $this->observe($session, $ledger, $transport, $cancel, $wait, $attempts, $claim, $safe);
        } catch (\Throwable $error) {
            $safe['result'] = 'fail';
            if ($error instanceof ApiException) {
                $status = $error->httpStatus();
                if ($status >= 100 && $status <= 599) {
                    $safe['http_status'] = $status;
                }
            }
        } finally {
            try {
                $this->unchanged();
                $safe['prior_ledger_unchanged'] = true;
            } catch (\Throwable) {
                $safe['result'] = 'fail';
                $safe['reconciliation_required'] = true;
            }
        }
        return $safe;
    }

    /**
     * @param Closure(int):void $wait
     * @param array<string,mixed> $safe
     */
    private function observe(
        SandboxSession $session,
        RunLedger $ledger,
        KlarnaReconciliationTransport $transport,
        bool $cancel,
        Closure $wait,
        int $attempts,
        KlarnaReconciliationClaim $claim,
        array &$safe,
    ): void {
            $this->unchanged();
            $binding = ['prior_sha256' => $this->digest, 'prior_source' => $this->source,
                'context_sha256' => $this->fingerprint, 'original_run_sha256' => hash('sha256', $this->marker),
                'target_sha256' => hash('sha256', $this->intent), 'case' => $this->case,
                'amount' => $this->amount, 'mode' => $safe['mode']];
            $ledger->requestId('klarna_reconciliation_preflight', $binding, $this->fingerprint); // Before auth.
            $safe['phase'] = 'intent_read';
            $observed = $this->read($session);
            $safe['own_intent_verified'] = true;
            $state = $observed['status'];
            $safe['observed_state'] = $state;
            if (!in_array($state, ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION', 'CANCELLED'], true)) {
                return;
            }
            self::zero($observed);
            $safe['zero_captured_verified'] = true;
            $safe['phase'] = 'refund_read';
            $this->zeroRefunds($session);
            $safe['zero_refunds_verified'] = true;
            $safe['pre_cancel_refunds_empty'] = true;
            if ($state === 'CANCELLED') {
                $safe['cancelled_verified'] = true;
                $safe['result'] = 'pass';
                $safe['reconciliation_required'] = false;
            } elseif (!$cancel) {
                $safe['reason'] = 'fresh_uncaptured_state_verified';
            } elseif ($this->priorCancel || $claim->reserved()) {
                $safe['reason'] = 'cancellation_already_reserved';
            } else {
                $safe['phase'] = 'cancel_preflight';
                $latest = $this->read($session);
                self::zero($latest);
                if (!in_array($latest['status'], ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION'], true)) {
                    throw new ProtocolException('Fresh uncaptured cancellation state is unavailable.');
                }
                $this->unchanged();
                if (
                    !$claim->acquire(['prior_sha256' => $this->digest, 'context_sha256' => $this->fingerprint,
                    'original_run_sha256' => hash('sha256', $this->marker),
                    'target_sha256' => hash('sha256', $this->intent),
                    'new_run_id' => $ledger->runId(), 'action' => 'klarna_reconciliation_cancel',
                    'new_source_sha256' => self::hashBody($ledger->evidence()['source'])])
                ) {
                    $safe['reason'] = 'cancellation_claim_unavailable';
                    return;
                }
                $body = ['cancellation_reason' => 'SDK sandbox prior uncaptured Klarna cleanup'];
                $body['request_id'] = $ledger->requestId('klarna_reconciliation_cancel', $body, $this->fingerprint);
                if (in_array($body['request_id'], $this->priorIdentities, true)) {
                    throw new ProtocolException('Cleanup requires a distinct new cancellation identity.');
                }
                $wire = (new JsonCodec())->encode($body);
                $ledger->recordObservation('klarna_reconciliation_cancel_wire', ['intent_id' => $this->intent,
                    'request_id' => $body['request_id'], 'wire_sha256' => hash('sha256', $wire),
                    'target_sha256' => hash('sha256', '/api/v1/pa/payment_intents/' . $this->intent . '/cancel'),
                    'prior_sha256' => $this->digest, 'possible_send' => 'true'], $this->fingerprint);
                $ledger->assertContext($this->fingerprint);
                $this->unchanged();
                $transport->authorizeCancel(hash('sha256', $wire));
                $safe['phase'] = 'cancel';
                $safe['zero_refunds_verified'] = false;
                $safe['cancel_sent'] = true;
                $response = $session->client->payments()->paymentIntents()->cancel($this->intent, $body);
                if (($response['id'] ?? null) !== $this->intent) {
                    throw new ProtocolException('Cancellation returned an unexpected identity.');
                }
                $safe['phase'] = 'cancel_readback';
                for ($index = 0; $index < $attempts; $index++) {
                    $latest = $this->read($session);
                    self::zero($latest);
                    if ($latest['status'] === 'CANCELLED') {
                        $this->zeroRefunds($session);
                        $safe['zero_refunds_verified'] = true;
                        $safe['post_cancel_refunds_empty'] = true;
                        $safe['cancelled_verified'] = true;
                        $safe['result'] = 'pass';
                        $safe['reconciliation_required'] = false;
                        break;
                    }
                    if (!in_array($latest['status'], ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION'], true)) {
                        throw new ProtocolException('Cancellation readback has an unexpected state.');
                    }
                    if ($index + 1 < $attempts) {
                        $wait([1, 2, 4, 8, 8, 7][$index]);
                    }
                }
            }
    }

    /** @return array<array-key,mixed> */
    private function read(SandboxSession $session): array
    {
        $this->unchanged();
        $data = $session->client->payments()->paymentIntents()->retrieve($this->intent);
        $this->unchanged();
        self::same($data, ['id' => $this->intent, ...$this->expected]);
        $state = $data['status'] ?? null;
        if (
            !is_string($state) || !in_array($state, ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION',
            'REQUIRES_CAPTURE', 'PENDING', 'PENDING_REVIEW', 'SUCCEEDED', 'CANCELLED'], true)
        ) {
            throw new ProtocolException('The observed Intent state is unavailable.');
        }
        return $data;
    }

    /** @param array<array-key,mixed> $data */
    private static function zero(#[SensitiveParameter] array $data): void
    {
        if (!ExactDecimal::equalsLiteral($data['captured_amount'] ?? null, '0')) {
            throw new ProtocolException('Observed zero captured amount is required.');
        }
    }

    private function zeroRefunds(SandboxSession $session): void
    {
        $this->unchanged();
        $page = $session->client->payments()->refunds()->list(['payment_intent_id' => $this->intent,
            'page_num' => 0, 'page_size' => 100]);
        $this->unchanged();
        if (($page['items'] ?? null) !== [] || ($page['has_more'] ?? null) !== false) {
            throw new ProtocolException('An explicit terminal empty own-Intent Refund page is required.');
        }
    }

    private function unchanged(): void
    {
        self::privateState($this->path, $this->stateRoot);
        if (!hash_equals($this->digest, hash_file('sha256', $this->path) ?: '')) {
            throw new ProtocolException('The original ledger changed during reconciliation.');
        }
    }

    private static function privateState(string $path, string $stateRoot): void
    {
        clearstatcache();
        if (
            !is_file($path) || !is_dir(dirname($path)) || !is_dir($stateRoot)
            || realpath($path) !== $path || realpath($stateRoot) !== $stateRoot
            || dirname(dirname($path)) !== $stateRoot
            || is_link($path) || is_link(dirname($path)) || is_link($stateRoot)
        ) {
            throw new RuntimeException('The original canonical state root must be retained.');
        }
        foreach ([$path => 0600, dirname($path) => 0700, $stateRoot => 0700] as $name => $mode) {
            $permissions = @fileperms($name);
            if ($permissions === false || (PHP_OS_FAMILY !== 'Windows' && ($permissions & 0777) !== $mode)) {
                throw new RuntimeException('The original state boundary must retain private permissions.');
            }
        }
        for ($ancestor = $stateRoot;; $ancestor = dirname($ancestor)) {
            if (file_exists($ancestor . '/.git') || is_link($ancestor . '/.git')) {
                throw new RuntimeException('Private state must remain outside Git checkouts.');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['state' => '[private prior Klarna reconciliation]'];
    }
    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Private reconciliation cannot be serialized.');
    }

    /** Local framing policy only; the SDK codec itself does not reject duplicate object keys. */
    public static function assertUniqueJsonObject(#[SensitiveParameter] string $raw): \stdClass
    {
        $object = json_decode($raw, flags: JSON_THROW_ON_ERROR, depth: 64);
        if (!$object instanceof \stdClass) {
            throw new RuntimeException('Reconciliation requires an unambiguous JSON object.');
        }
        $frames = [];
        $length = strlen($raw);
        for ($index = 0; $index < $length; $index++) {
            $character = $raw[$index];
            if ($character === '{' || $character === '[') {
                $frames[] = ['object' => $character === '{', 'key' => true, 'names' => []];
            } elseif ($character === '}' || $character === ']') {
                array_pop($frames);
            } elseif ($character === ',' || $character === ':') {
                $top = count($frames) - 1;
                if ($top >= 0 && $frames[$top]['object']) {
                    $frames[$top]['key'] = $character === ',';
                }
            } elseif ($character === '"') {
                $end = $index + 1;
                while ($end < $length) {
                    if ($raw[$end] === '\\') {
                        $end += 2;
                    } elseif ($raw[$end] === '"') {
                        break;
                    } else {
                        $end++;
                    }
                }
                $top = count($frames) - 1;
                if ($top >= 0 && $frames[$top]['object'] && $frames[$top]['key']) {
                    $key = json_decode(substr($raw, $index, $end - $index + 1), flags: JSON_THROW_ON_ERROR);
                    if (!is_string($key) || isset($frames[$top]['names']['key:' . $key])) {
                        throw new RuntimeException('Reconciliation requires unique decoded object keys.');
                    }
                    $frames[$top]['names']['key:' . $key] = true;
                }
                $index = $end;
            }
        }
        return $object;
    }

    /** @param list<string> $names Native types only: numeric money is never compared in this view. */
    public static function assertLiteralStrings(#[SensitiveParameter] \stdClass $object, array $names): void
    {
        foreach ($names as $name) {
            if (property_exists($object, $name) && !is_string($object->{$name})) {
                throw new RuntimeException('An observed literal string field has an unsupported type.');
            }
        }
    }

    private static function priorLiteralTypes(#[SensitiveParameter] \stdClass $state): void
    {
        self::assertLiteralStrings($state, ['run_id', 'api_version', 'context_fingerprint']);
        $source = $state->source ?? null;
        $actions = $state->actions ?? null;
        $resources = $state->resources ?? null;
        $observations = $state->observations ?? null;
        if (
            !$source instanceof \stdClass || !$actions instanceof \stdClass
            || !$resources instanceof \stdClass || !$observations instanceof \stdClass
        ) {
            throw new RuntimeException('Original state requires literal object containers.');
        }
        self::assertLiteralStrings($source, ['base_commit', 'sdk_digest', 'harness_digest']);
        foreach (get_object_vars($actions) as $action) {
            if (!$action instanceof \stdClass) {
                throw new RuntimeException('An original action is not a literal object.');
            }
            self::assertLiteralStrings($action, ['request_id', 'body_hash']);
        }
        foreach (get_object_vars($resources) as $ids) {
            if (!is_array($ids)) {
                throw new RuntimeException('Original resource identities require literal arrays.');
            }
            foreach ($ids as $id) {
                if (!is_string($id)) {
                    throw new RuntimeException('Original resource identities require literal strings.');
                }
            }
        }
        foreach (get_object_vars($observations) as $observation) {
            if (!$observation instanceof \stdClass) {
                throw new RuntimeException('Original observations require literal objects.');
            }
            foreach (get_object_vars($observation) as $value) {
                if (!is_string($value)) {
                    throw new RuntimeException('Original observation references require literal strings.');
                }
            }
        }
    }

    /** @param array<array-key,mixed> $actions */
    private static function actionHash(array $actions, string $name): string
    {
        $action = $actions[$name] ?? null;
        if (
            !is_array($action) || !is_string($action['body_hash'] ?? null)
            || !self::hashValue($action['body_hash'])
        ) {
            throw new RuntimeException('A fixed original action hash is required.');
        }
        return $action['body_hash'];
    }

    private static function hashValue(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
    /** @param array<array-key,mixed> $body */
    private static function hashBody(array $body): string
    {
        return hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
    }
    private static function identity(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $value) !== 1) {
            throw new RuntimeException('One safe recorded Intent is required.');
        }
        return $value;
    }

    private static function priorTool(string $commit): string
    {
        $root = dirname(__DIR__, 4);
        $raw = '';
        $commands = [['merge-base', '--is-ancestor', $commit, 'HEAD'],
            ['show', $commit . ':tools/klarna-manual-capture.php']];
        foreach ($commands as $args) {
            $process = proc_open(['git', '-C', $root, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Original committed tool provenance is unavailable.');
            }
            [$output, $errors] = self::processPipes($pipes);
            $raw = stream_get_contents($output, 65537);
            stream_get_contents($errors, 65537);
            fclose($output);
            fclose($errors);
            if (proc_close($process) !== 0 || !is_string($raw) || strlen($raw) > 65536) {
                throw new RuntimeException('Original committed tool provenance is unavailable.');
            }
        }
        return $raw;
    }

    /** @return array{resource,resource} */
    private static function processPipes(mixed $pipes): array
    {
        if (!is_array($pipes) || !is_resource($pipes[1] ?? null) || !is_resource($pipes[2] ?? null)) {
            throw new RuntimeException('Committed source pipes are unavailable.');
        }
        return [$pipes[1], $pipes[2]];
    }

    /** @param array<array-key,mixed> $actual
     * @param array<array-key,mixed> $expected
     */
    private static function same(#[SensitiveParameter] array $actual, #[SensitiveParameter] array $expected): void
    {
        foreach ($expected as $key => $value) {
            if (in_array($key, ['amount', 'unit_price'], true)) {
                if (!is_string($value) || !ExactDecimal::equalsLiteral($actual[$key] ?? null, $value)) {
                    throw new ProtocolException('An observed exact monetary field does not match.');
                }
            } elseif ($key === 'quantity' && $value === 1) {
                if (($actual[$key] ?? null) !== 1 && !ExactDecimal::equalsLiteral($actual[$key] ?? null, '1')) {
                    throw new ProtocolException('The own product quantity does not match.');
                }
            } elseif (is_array($value)) {
                if (
                    !is_array($actual[$key] ?? null)
                    || (array_is_list($value) && count($actual[$key]) !== count($value))
                ) {
                    throw new ProtocolException('The original application object does not match.');
                }
                self::same($actual[$key], $value);
            } elseif (($actual[$key] ?? null) !== $value) {
                throw new ProtocolException('The original application field does not match.');
            }
        }
    }
}
