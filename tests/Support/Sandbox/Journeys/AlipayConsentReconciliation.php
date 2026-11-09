<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/** Current ownership observation only; original Verify uncertainty can never be cleared here. */
final class AlipayConsentReconciliation
{
    public const string ORIGINAL = 'e089ff21de8d255990fc704506898201546c5467';
    public const string SDK = '3ab42a8d0a22da3ad2d8dac3e561ccb29747ec370f517a4258579b72ad46d9a9';
    public const string HARNESS = 'bda8d96be16cdf14cbf9b2b2ad7379c32dcf7d2a584605757d413449d423febb';
    public const string PRODUCER = 'c895488a4dac675e86cc8f2b16f8ff83180f9ee22de91efcf654d08a6798352a';
    private const string ROOT = '/api/v1/pa/';

    private function __construct(
        private readonly string $path,
        private readonly string $directory,
        private readonly string $digest,
        private readonly string $marker,
        private readonly string $customer,
        private readonly string $consent,
        private readonly string $context,
    ) {
    }

    public static function load(
        #[SensitiveParameter] string $path,
        #[SensitiveParameter] string $directory,
        SandboxSession $session,
    ): self {
        if ($session->isOrganization()) {
            throw new RuntimeException('Only the original account profile is accepted.');
        }
        $raw = self::readPrior($path, $directory);
        $native = KlarnaIntentReconciliation::assertUniqueJsonObject($raw);
        self::keys($native, ['run_id', 'api_version', 'context_fingerprint', 'source', 'actions',
            'resources', 'observations']);
        self::same($native, ['api_version' => '2026-08-21', 'context_fingerprint' => $session->contextFingerprint()]);
        $marker = $native->run_id ?? null;
        if (
            !is_string($marker)
            || preg_match('/^sdk-validation-[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $marker) !== 1
            || basename(dirname($path)) !== $marker || basename($path) !== 'ledger.json'
        ) {
            throw new RuntimeException('The original run identity is unavailable.');
        }
        $source = self::object($native->source ?? null);
        self::keys($source, ['base_commit', 'sdk_digest', 'harness_digest']);
        self::same($source, ['base_commit' => self::ORIGINAL, 'sdk_digest' => self::SDK,
            'harness_digest' => self::HARNESS]);
        // The reviewed direct-webhook numeric selector leaves Customer/Consent read contracts unchanged.
        if (
            !in_array(SourceEvidence::capture()['sdk_digest'], [
                self::SDK, '184cbc2ac51ee6498f45b5aa50a8ec82c89e66231cb2fabacc862b8a579e48ae',
            ], true)
        ) {
            throw new RuntimeException('The approved SDK source has changed.');
        }
        self::sourceProof();
        $resources = self::object($native->resources ?? null);
        self::keys($resources, ['payment_customers', 'payment_consents']);
        $customer = self::one($resources->payment_customers ?? null);
        $consent = self::one($resources->payment_consents ?? null);
        if ($customer === $consent) {
            throw new RuntimeException('The original resource relationship is ambiguous.');
        }
        $actions = self::object($native->actions ?? null);
        self::keys($actions, ['consent_reuse_preflight', 'customer_create', 'consent_create', 'consent_verify']);
        $ids = [];
        foreach (get_object_vars($actions) as $action) {
            $action = self::object($action);
            self::keys($action, ['request_id', 'body_hash']);
            $id = $action->request_id ?? null;
            if (
                !is_string($id) || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $id) !== 1
                || in_array($id, $ids, true) || !self::hash($action->body_hash ?? null)
            ) {
                throw new RuntimeException('The original action identities are invalid.');
            }
            $ids[] = $id;
        }
        $preflight = self::object($actions->consent_reuse_preflight);
        self::same($preflight, ['body_hash' => self::intentHash(self::preflight())]);
        $observations = self::object($native->observations ?? null);
        $phases = [];
        foreach (self::bodies($marker, $customer) as $name => $body) {
            $action = self::object($actions->{$name});
            $pathForAction = match ($name) {
                'customer_create' => self::ROOT . 'customers/create',
                'consent_create' => self::ROOT . 'payment_consents/create',
                'consent_verify' => self::ROOT . 'payment_consents/' . $consent . '/verify',
                default => throw new RuntimeException('An original action is outside the fixed case.'),
            };
            self::same($action, ['body_hash' => self::intentHash(['path' => $pathForAction, 'payload' => $body])]);
            $wire = (new JsonCodec())->encode($body + ['request_id' => $action->request_id]);
            $prepared = self::object($observations->{$name . '_wire_prepared'} ?? null);
            $possible = self::object($observations->{$name . '_possible_send'} ?? null);
            $result = self::object($observations->{$name . '_wire_result'} ?? null);
            self::keys($prepared, ['method', 'target_sha256', 'body_sha256']);
            self::same($prepared, ['method' => 'POST', 'target_sha256' => hash(
                'sha256',
                'https://api.sandbox.airwallex.com' . $pathForAction
            ), 'body_sha256' => hash('sha256', $wire)]);
            self::keys($possible, ['invocation', 'body_sha256']);
            self::same($possible, ['invocation' => 'possible', 'body_sha256' => hash('sha256', $wire)]);
            self::keys($result, ['http_status', 'response_sha256']);
            self::same($result, ['http_status' => $name === 'consent_verify' ? '400' : '201']);
            if (!self::hash($result->response_sha256 ?? null)) {
                throw new RuntimeException('The original response hash record is unavailable.');
            }
            foreach (['_wire_prepared', '_possible_send', '_wire_result'] as $suffix) {
                $phases[] = $name . $suffix;
            }
        }
        self::keys($observations, $phases);
        return new self(
            $path,
            $directory,
            hash('sha256', $raw),
            $marker,
            $customer,
            $consent,
            $session->contextFingerprint()
        );
    }

    /** @return array<string,mixed> */
    public static function preflight(): array
    {
        return ['profile' => 'ACCOUNT', 'amount' => '1.23', 'currency' => 'HKD', 'os' => 'android',
            'simulation' => 'original_verify_url_only', 'tool_sha256' => self::PRODUCER, 'api_version' => '2026-08-21'];
    }

    /** @return array<string,array<string,mixed>> */
    public static function bodies(#[SensitiveParameter] string $marker, #[SensitiveParameter] string $customer): array
    {
        return [
            'customer_create' => ['merchant_customer_id' => $marker, 'metadata' => ['sdk_validation_run' => $marker]],
            'consent_create' => ['customer_id' => $customer, 'next_triggered_by' => 'merchant',
                'merchant_trigger_reason' => 'unscheduled', 'metadata' => ['sdk_validation_run' => $marker]],
            'consent_verify' => ['payment_method' => ['type' => 'alipayhk',
                'alipayhk' => ['flow' => 'mobile_web', 'os_type' => 'android']],
                'return_url' => 'https://example.com/'],
        ];
    }

    /** @return array<string,mixed> */
    public static function initial(): array
    {
        return ['scenario' => 'alipay-consent-readonly-reconciliation', 'profile' => 'ACCOUNT',
            'api_version' => '2026-08-21', 'result' => 'fail', 'observation_result' => 'unavailable',
            'phase' => 'configuration', 'reason' => 'observation_not_established',
            'historical_reconciliation_required' => true,
            'historical_verification_or_financial_disposition_unresolved' => true,
            'original_pipeline_result' => 'fail', 'original_pipeline_rewritten' => false,
            'current_consent_state' => 'UNAVAILABLE', 'owner_chain_matched' => false,
            'current_terminal_consent_capability_observed' => false, 'consent_cleanup' => 'pending_not_attempted',
            'customer_cleanup' => 'retained', 'cleanup_writes_sent' => 0, 'financial_mutation_sent' => false,
            'prior_bytes_unchanged' => false, 'method_object_state' => 'UNAVAILABLE',
            'method_type_state' => 'UNAVAILABLE', 'method_type_matches' => false,
            'method_id_state' => 'UNAVAILABLE', 'method_id_usable' => false,
            'initial_intent_reference_state' => 'UNAVAILABLE', 'initial_intent_reference_usable' => false,
            'next_action_state' => 'UNAVAILABLE'];
    }

    /** @return array<string,mixed> */
    public function execute(
        SandboxSession $session,
        RunLedger $ledger,
        AlipayConsentReconciliationTransport $transport,
        #[SensitiveParameter] string $toolHash,
        \Closure $externalProof,
    ): array {
        $safe = self::initial();
        $source = SourceEvidence::capture();
        $gate = function () use ($ledger, $session, $source, $toolHash, $externalProof): void {
            $this->unchanged();
            $externalProof();
            $ledger->assertContext($this->context);
            if (
                $session->contextFingerprint() !== $this->context || SourceEvidence::capture() !== $source
                || $ledger->evidence()['source'] !== $source
                || @hash_file('sha256', dirname(__DIR__, 4) . '/tools/alipay-consent-reconciliation.php') !== $toolHash
            ) {
                throw new RuntimeException('The read-only source or context changed.');
            }
        };
        try {
            $safe['phase'] = 'preflight';
            $gate();
            $ledger->recordObservation('consent_reconciliation_binding', ['prior_sha256' => $this->digest,
                'prior_commit' => self::ORIGINAL, 'prior_sdk' => self::SDK, 'prior_harness' => self::HARNESS,
                'prior_tool' => self::PRODUCER, 'customer_id' => $this->customer, 'consent_id' => $this->consent,
                'tool_sha256' => $toolHash], $this->context);
            foreach (
                ['customer' => self::ROOT . 'customers/' . $this->customer,
                'consent' => self::ROOT . 'payment_consents/' . $this->consent] as $role => $path
            ) {
                $ledger->requestId(
                    'consent_reconciliation_' . $role . '_intent',
                    ['method' => 'GET', 'path' => $path, 'prior_sha256' => $this->digest],
                    $this->context
                );
            }
            $gate();
            $transport->bind($this->customer, $this->consent, $ledger, $this->context, $gate);
            $safe['phase'] = 'customer_read';
            $session->client->payments()->customers()->retrieve($this->customer);
            $customer = $transport->native('customer');
            self::same($customer, ['id' => $this->customer, 'merchant_customer_id' => $this->marker]);
            $this->metadata($customer);
            $ledger->recordObservation('consent_reconciliation_customer_owned', ['matched' => 'true'], $this->context);
            $gate();
            $transport->customerVerified();
            $safe['phase'] = 'consent_read';
            $session->client->payments()->paymentConsents()->retrieve($this->consent);
            $consent = $transport->native('consent');
            self::same($consent, ['id' => $this->consent, 'customer_id' => $this->customer,
                'next_triggered_by' => 'merchant', 'merchant_trigger_reason' => 'unscheduled']);
            $this->metadata($consent);
            $state = $consent->status ?? null;
            if (!is_string($state)) {
                throw new RuntimeException('The current Consent state is not literal.');
            }
            $known = ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION',
                'PENDING', 'PAUSED', 'VERIFIED', 'DISABLED'];
            $safe['current_consent_state'] = in_array($state, $known, true) ? $state : 'UNLISTED';
            if ($safe['current_consent_state'] === 'UNLISTED') {
                throw new RuntimeException('The current Consent state is outside the closed projection.');
            }
            $method = $consent->payment_method ?? null;
            $safe['method_object_state'] = self::kind($consent, 'payment_method', 'object');
            if ($method instanceof stdClass) {
                $safe['method_type_state'] = self::kind($method, 'type');
                $safe['method_type_matches'] = ($method->type ?? null) === 'alipayhk';
                $safe['method_id_state'] = self::kind($method, 'id');
                $safe['method_id_usable'] = self::usable($method->id ?? null);
            }
            $safe['initial_intent_reference_state'] = self::kind($consent, 'initial_payment_intent_id');
            $safe['initial_intent_reference_usable'] = self::usable($consent->initial_payment_intent_id ?? null);
            $safe['next_action_state'] = self::kind($consent, 'next_action', 'object');
            $safe['phase'] = 'observation_persist';
            $ledger->recordObservation(
                'consent_reconciliation_owned',
                ['state' => $state, 'matched' => 'true'],
                $this->context
            );
            $gate();
            $transport->assertTime();
            $safe['current_terminal_consent_capability_observed'] = $state === 'DISABLED';
            $safe['consent_cleanup'] = $state === 'DISABLED'
                ? 'already_disabled_current_observation' : 'pending_not_attempted';
            $safe['owner_chain_matched'] = true;
            $safe['observation_result'] = 'pass';
            $safe['result'] = 'pending';
            $safe['reason'] = 'historical_disposition_unresolved';
            $safe['phase'] = 'observed';
        } catch (Throwable) {
            $safe['observation_result'] = 'fail';
        }
        try {
            $this->unchanged();
            $safe['prior_bytes_unchanged'] = true;
        } catch (Throwable) {
            $safe['result'] = 'fail';
            $safe['observation_result'] = 'fail';
            $safe['owner_chain_matched'] = false;
            $safe['current_terminal_consent_capability_observed'] = false;
            $safe['consent_cleanup'] = 'pending_not_attempted';
        }
        return $safe + ['source' => $source, 'tool_sha256' => $toolHash, ...$transport->report()];
    }

    private function metadata(stdClass $object): void
    {
        $metadata = self::object($object->metadata ?? null);
        self::same($metadata, ['sdk_validation_run' => $this->marker]);
        if (isset($object->connected_account_id) && $object->connected_account_id !== '') {
            throw new RuntimeException('The original ordinary account context changed.');
        }
    }

    private static function kind(stdClass $object, string $key, string $wanted = 'string'): string
    {
        if (!property_exists($object, $key)) {
            return 'absent';
        }
        $value = $object->{$key};
        return $value === null ? 'null' : (($wanted === 'string' ? is_string($value) : $value instanceof stdClass)
            ? $wanted : 'other');
    }

    private static function usable(mixed $id): bool
    {
        try {
            AlipayConsentReuseTransport::identity($id);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function unchanged(): void
    {
        if (!hash_equals($this->digest, hash('sha256', self::readPrior($this->path, $this->directory)))) {
            throw new RuntimeException('The original private record changed.');
        }
    }

    private static function readPrior(
        #[SensitiveParameter] string $path,
        #[SensitiveParameter] string $directory,
    ): string {
        clearstatcache();
        if (
            !str_starts_with($path, '/') || realpath($path) !== $path || realpath($directory) !== $directory
            || dirname(dirname($path)) !== $directory || !is_file($path)
            || !is_dir(dirname($path)) || !is_dir($directory)
            || is_link($path) || is_link(dirname($path)) || is_link($directory)
        ) {
            throw new RuntimeException('The original canonical private root is required.');
        }
        foreach ([$path => 0600, dirname($path) => 0700, $directory => 0700] as $name => $mode) {
            $permissions = @fileperms($name);
            if ($permissions === false || ($permissions & 0777) !== $mode) {
                throw new RuntimeException('The original state permissions changed.');
            }
        }
        for ($parent = dirname($path);; $parent = dirname($parent)) {
            if (file_exists($parent . '/.git') || is_link($parent . '/.git')) {
                throw new RuntimeException('Private state must remain outside Git.');
            }
            if ($parent === dirname($parent)) {
                break;
            }
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The original record cannot be read.');
        }
        try {
            $opened = fstat($stream);
            $named = @lstat($path);
            $raw = stream_get_contents($stream, 1048577);
            if (
                $opened === false || $named === false || $opened['dev'] !== $named['dev']
                || $opened['ino'] !== $named['ino'] || $opened['mode'] !== $named['mode']
                || ($opened['mode'] & 0170000) !== 0100000 || ($opened['mode'] & 0777) !== 0600
                || !is_string($raw) || strlen($raw) > 1048576
            ) {
                throw new RuntimeException('The bounded original file proof failed.');
            }
            return $raw;
        } finally {
            fclose($stream);
        }
    }

    private static function sourceProof(): void
    {
        $root = dirname(__DIR__, 4);
        $proofs = [
            'tools/alipay-hk-consent-reuse.php' => self::PRODUCER,
            'tests/Support/Sandbox/Journeys/AlipayConsentReuse.php'
                => '29e7518dcbb16f5cfa066dcfb757ef62980f0356a9dc452314af40d07a0a92ed',
            'tests/Support/Sandbox/Journeys/AlipayConsentReuseTransport.php'
                => '032a502055958fdf2836268c98a760e165b09d1ec42d046307a7b02681202267',
            'tests/Support/Sandbox/Journeys/AlipayConsentReuseCommand.php'
                => 'b4e0e09388932ed1135e2d173b30327c307a5a666d083e5aed040eaa4644df51',
        ];
        self::git($root, ['merge-base', '--is-ancestor', self::ORIGINAL, 'HEAD']);
        foreach ($proofs as $path => $hash) {
            if (hash('sha256', self::git($root, ['show', self::ORIGINAL . ':' . $path])) !== $hash) {
                throw new RuntimeException('The immutable original source proof failed.');
            }
        }
    }

    /** @param list<string> $arguments */
    private static function git(string $root, array $arguments): string
    {
        $process = @proc_open(
            ['git', '-C', $root, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'LANG' => 'C',
                'GIT_ALLOW_PROTOCOL' => '', 'GIT_NO_LAZY_FETCH' => '1', 'GIT_NO_REPLACE_OBJECTS' => '1',
                'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null']
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The approved source object is unavailable.');
        }
        try {
            [$input, $output, $errors] = self::pipes($pipes);
            fclose($input);
            $raw = stream_get_contents($output, 65537);
            stream_get_contents($errors, 4097);
            fclose($output);
            fclose($errors);
            if (proc_close($process) !== 0 || !is_string($raw) || strlen($raw) > 65536) {
                throw new RuntimeException('The bounded committed source proof failed.');
            }
            return $raw;
        } catch (Throwable) {
            self::closePipes($pipes);
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            throw new RuntimeException('The approved source pipes failed.');
        }
    }

    /** @return array{resource,resource,resource} */
    private static function pipes(mixed $pipes): array
    {
        if (
            !is_array($pipes) || !is_resource($pipes[0] ?? null)
            || !is_resource($pipes[1] ?? null) || !is_resource($pipes[2] ?? null)
        ) {
            throw new RuntimeException('Source proof requires three owned streams.');
        }
        return [$pipes[0], $pipes[1], $pipes[2]];
    }

    private static function closePipes(mixed $pipes): void
    {
        if (is_array($pipes)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe) && get_resource_type($pipe) === 'stream') {
                    fclose($pipe);
                }
            }
        }
    }

    private static function object(mixed $value): stdClass
    {
        if (!$value instanceof stdClass) {
            throw new RuntimeException('A literal original object is required.');
        }
        return $value;
    }

    /** @param list<string> $names */
    private static function keys(stdClass $object, array $names): void
    {
        $actual = array_keys(get_object_vars($object));
        sort($actual);
        sort($names);
        if ($actual !== $names) {
            throw new RuntimeException('The original fixed record shape changed.');
        }
    }

    /** @param array<string,mixed> $expected */
    private static function same(stdClass $object, array $expected): void
    {
        foreach ($expected as $key => $value) {
            if (!property_exists($object, $key) || $object->{$key} !== $value) {
                throw new RuntimeException('The literal owned record does not match.');
            }
        }
    }

    private static function one(mixed $value): string
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 1) {
            throw new RuntimeException('Exactly one recorded original resource is required.');
        }
        return AlipayConsentReuseTransport::identity($value[0]);
    }

    private static function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** @param array<string,mixed> $value */
    private static function intentHash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['reconciliation' => '[private original Consent observation]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Private prior state cannot be serialized.');
    }
}
