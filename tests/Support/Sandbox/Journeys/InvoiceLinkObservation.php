<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use RuntimeException;
use SensitiveParameter;

/** Shared own-Invoice checks; the default string/clock observation never follows returned capabilities. */
final class InvoiceLinkObservation
{
    private bool $used = false;

    /** @param array{base_commit:string,sdk_digest:string,harness_digest:string} $priorSource */
    private function __construct(
        #[SensitiveParameter] private readonly string $customer,
        #[SensitiveParameter] private readonly string $subscription,
        #[SensitiveParameter] private readonly string $marker,
        private readonly string $contextFingerprint,
        private readonly string $priorDigest,
        private readonly array $priorSource,
    ) {
    }

    /** Validate the entire supported ledger boundary before authentication. Reads only an operator-supplied file. */
    public static function load(#[SensitiveParameter] string $path, SandboxSession $session): self
    {
        if (!$session->isOrganization() || !str_starts_with($path, '/') || is_link($path)) {
            throw new RuntimeException('A private absolute manual-lifecycle ledger is required.');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('The prior private ledger is unavailable.');
        }
        for ($ancestor = dirname($resolved);; $ancestor = dirname($ancestor)) {
            if (file_exists($ancestor . '/.git') || is_link($ancestor . '/.git')) {
                throw new RuntimeException('Prior private state must remain outside Git checkouts.');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
        $fileMode = @fileperms($resolved);
        $directoryMode = @fileperms(dirname($resolved));
        if (
            PHP_OS_FAMILY !== 'Windows' && ($fileMode === false || $directoryMode === false
            || ($fileMode & 0077) !== 0 || ($directoryMode & 0077) !== 0)
        ) {
            throw new RuntimeException('Prior private state requires private file and directory permissions.');
        }
        $raw = @file_get_contents($resolved, length: 1048577);
        if ($raw === false || strlen($raw) > 1048576) {
            throw new RuntimeException('The prior private ledger is unavailable.');
        }
        $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (
            !is_array($state) || ($state['api_version'] ?? null) !== '2026-08-21'
            || !is_string($state['context_fingerprint'] ?? null)
            || !hash_equals($session->contextFingerprint(), $state['context_fingerprint'])
        ) {
            throw new RuntimeException('The prior version or organization context does not match.');
        }
        $marker = $state['run_id'] ?? null;
        $resources = $state['resources'] ?? null;
        $actions = $state['actions'] ?? null;
        $source = $state['source'] ?? null;
        if (
            !is_string($marker) || preg_match('/^sdk-validation-[a-f0-9-]{36}$/D', $marker) !== 1
            || !is_array($resources) || !is_array($actions) || !is_array($source)
        ) {
            throw new RuntimeException('The prior manual-lifecycle evidence is incomplete.');
        }
        $action = $actions['create_future_start'] ?? $actions['create_future_trial'] ?? null;
        if (
            !is_array($action) || !self::digest($action['body_hash'] ?? null)
            || !is_string($action['request_id'] ?? null)
            || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $action['request_id']) !== 1
        ) {
            throw new RuntimeException('The original manual-lifecycle create action is unavailable.');
        }
        $base = $source['base_commit'] ?? null;
        $sdk = $source['sdk_digest'] ?? null;
        $harness = $source['harness_digest'] ?? null;
        if (
            !is_string($base) || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $base) !== 1
            || !is_string($sdk) || !self::digest($sdk) || !is_string($harness) || !self::digest($harness)
        ) {
            throw new RuntimeException('The prior source identity is unavailable.');
        }
        return new self(
            self::one($resources['customers'] ?? null),
            self::one($resources['subscriptions'] ?? null),
            $marker,
            $session->contextFingerprint(),
            hash('sha256', $raw),
            ['base_commit' => $base, 'sdk_digest' => $sdk, 'harness_digest' => $harness]
        );
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function one(mixed $value): string
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 1) {
            throw new RuntimeException('Exactly one prior Customer and Subscription are required.');
        }
        return self::id($value[0]);
    }

    private static function id(#[SensitiveParameter] mixed $value): string
    {
        if (
            !is_string($value) || trim($value) === '' || $value === '.' || $value === '..'
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
        ) {
            throw new ProtocolException('A recorded or returned identity is unavailable.');
        }
        return $value;
    }

    /**
     * @return array{prior_ledger_sha256:string,
     * prior_source:array{base_commit:string,sdk_digest:string,harness_digest:string}}
     */
    public function provenance(): array
    {
        return ['prior_ledger_sha256' => $this->priorDigest, 'prior_source' => $this->priorSource];
    }

    /**
     * @param (Closure(array<array-key,mixed>):array<string,mixed>)|null $access
     * Private development handoff only after the same Invoice has passed both ownership reads.
     * @return array<string,mixed>
     */
    public function inspect(
        SandboxSession $session,
        ?Closure $clock = null,
        ?Closure $wait = null,
        ?Closure $access = null,
    ): array {
        if (
            $this->used || !$session->isOrganization()
            || !hash_equals($this->contextFingerprint, $session->contextFingerprint())
        ) {
            throw new RuntimeException('An observation cannot be reused or cross organization contexts.');
        }
        $this->used = true;
        $clock ??= static fn (): int => time();
        $wait ??= static fn () => sleep(2);
        $result = ['scenario' => 'invoice-link-observation', 'api_version' => '2026-08-21', 'result' => 'fail',
            'prior_ledger_sha256' => $this->priorDigest, 'prior_source' => $this->priorSource,
            'mutation_sent' => false, 'link_followed' => false, 'link_usability_verified' => false,
            'renewal_verified' => false, 'signature_verified' => false,
            'frontend' => InvoiceLinkClaims::provenance()];
        $phase = 'verify_customer';
        try {
            $billing = $session->client->billing();
            $customer = $billing->customers()->retrieve($this->customer);
            $this->marked($customer, $this->customer);
            $phase = 'verify_subscription';
            $sub = $billing->subscriptions()->retrieve($this->subscription);
            $this->marked($sub, $this->subscription);
            $entity = self::id($sub['legal_entity_id'] ?? null);
            if (
                ($sub['billing_customer_id'] ?? null) !== $this->customer || ($sub['currency'] ?? null) !== 'EUR'
                || ($sub['collection_method'] ?? null) !== 'OUT_OF_BAND'
                || ($customer['default_billing_currency'] ?? null) !== 'EUR'
                || ($customer['default_legal_entity_id'] ?? null) !== $entity
            ) {
                throw new ProtocolException('The prior manual-lifecycle ownership does not match.');
            }
            if (!isset($sub['latest_invoice_id']) || $sub['latest_invoice_id'] === '') {
                return array_replace($result, ['result' => 'pending', 'phase' => 'latest_invoice_unavailable']);
            }
            $invoiceId = self::id($sub['latest_invoice_id']);
            $phase = 'first_invoice_read';
            $beforeFirst = self::clock($clock);
            $first = $billing->invoices()->retrieve($invoiceId);
            $afterFirst = self::clock($clock);
            $this->invoice($first, $invoiceId, $entity);
            $wait();
            $phase = 'second_invoice_read';
            $beforeSecond = self::clock($clock);
            $second = $billing->invoices()->retrieve($invoiceId);
            $afterSecond = self::clock($clock);
            $this->invoice($second, $invoiceId, $entity);
            $phase = 'compare_private_link_fields';
            $result['pdf'] = InvoiceLinkClaims::compare($first, $second, 'pdf_url');
            $result['hosted'] = InvoiceLinkClaims::compare(
                $first,
                $second,
                'hosted_url',
                [$beforeFirst, $afterFirst],
                [$beforeSecond, $afterSecond]
            );
            $result['clock_progressed_between_reads'] = $beforeSecond > $afterFirst;
            if ($access !== null) {
                $phase = 'observe_invoice_access';
                $result['access'] = $access($second);
            }
            $result['result'] = 'pass';
        } catch (\Throwable $error) {
            if ($error instanceof ApiException) {
                $result['http_status'] = $error->httpStatus();
            }
        }
        return $result + ['phase' => $phase];
    }

    private static function clock(Closure $clock): int
    {
        $value = $clock();
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException('A local Unix-second observation clock is required.');
        }
        return $value;
    }

    /** @param array<array-key,mixed> $object */
    private function marked(#[SensitiveParameter] array $object, #[SensitiveParameter] string $id): void
    {
        $metadata = $object['metadata'] ?? null;
        if (
            ($object['id'] ?? null) !== $id || !is_array($metadata)
            || ($metadata['sdk_validation_run'] ?? null) !== $this->marker
        ) {
            throw new ProtocolException('The prior owner or run marker does not match.');
        }
    }

    /** @param array<array-key,mixed> $object */
    private function invoice(
        #[SensitiveParameter] array $object,
        #[SensitiveParameter] string $id,
        #[SensitiveParameter] string $entity
    ): void {
        if (
            ($object['id'] ?? null) !== $id || ($object['billing_customer_id'] ?? null) !== $this->customer
            || ($object['subscription_id'] ?? null) !== $this->subscription || ($object['currency'] ?? null) !== 'EUR'
            || ($object['collection_method'] ?? null) !== 'OUT_OF_BAND'
            || ($object['legal_entity_id'] ?? null) !== $entity
        ) {
            throw new ProtocolException('The latest Invoice does not match the recorded owner chain.');
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['references' => '[private]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Private invoice observation references cannot be serialized.');
    }
}
