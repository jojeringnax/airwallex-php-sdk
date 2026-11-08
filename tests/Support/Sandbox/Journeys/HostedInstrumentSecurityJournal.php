<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use RuntimeException;
use SensitiveParameter;

/** Native framing and helper provenance around the unchanged concrete BWS journal. */
final class HostedInstrumentSecurityJournal
{
    private bool $poisoned = false;
    private int $sequence = 0;
    private int $delegatedSequence = 0;
    private bool $loaderUsed = false;
    private bool $loaderMatched = false;
    private ?string $scopeHash = null;
    private ?string $sessionHash = null;
    /** @var array{body_sha256:string,target_sha256:string,local_id:string}|null */
    private ?array $pending = null;

    /**
     * @param array<string,string> $hashes
     * @param array{checkout_id:string,mode:string,currency:string} $expected
     */
    public function __construct(
        private readonly HostedRiskJournal $journal,
        private readonly array $hashes,
        private readonly RunLedger $ledger,
        #[SensitiveParameter] private readonly string $context,
        #[SensitiveParameter] private readonly array $expected,
    ) {
        $ledger->assertContext($context);
    }

    /** @return array{version:int,nonce:string} */
    public function bootstrap(): array
    {
        return $this->journal->bootstrap();
    }

    public function accept(#[SensitiveParameter] string $line): string
    {
        try {
            if ($this->poisoned || strlen($line) > 4096 || str_contains($line, "\n")) {
                throw new RuntimeException('Private security framing is unavailable.');
            }
            $view = WebhookDeliveryApi::assertUnambiguousObject($line);
            foreach (['version', 'sequence', 'ordinal', 'body_bytes', 'http_status', 'response_bytes'] as $field) {
                if (property_exists($view, $field) && !is_int($view->{$field})) {
                    throw new RuntimeException('Private security integer framing is invalid.');
                }
            }
            foreach (get_object_vars($view) as $field => $value) {
                if (
                    !in_array(
                        $field,
                        ['version', 'sequence', 'ordinal', 'body_bytes', 'http_status', 'response_bytes'],
                        true
                    )
                    && !is_string($value)
                ) {
                    throw new RuntimeException('Private security string framing is invalid.');
                }
            }
            if (HostedBrowser::sourceHashes(HostedSecurityMode::InstrumentSecurity) !== $this->hashes) {
                throw new RuntimeException('Private security tooling changed.');
            }
            $data = [];
            foreach (get_object_vars($view) as $key => $value) {
                if (!is_string($key)) {
                    throw new RuntimeException('Private security fields must be named.');
                }
                $data[$key] = $value;
            }
            if (
                ($data['sequence'] ?? null) !== $this->sequence + 1
                || ($data['version'] ?? null) !== 1 || !is_string($data['nonce'] ?? null)
                || !hash_equals($this->bootstrap()['nonce'], $data['nonce'])
            ) {
                throw new RuntimeException('Private security sequence changed.');
            }
            $this->ledger->assertContext($this->context);
            if ($this->ledger->evidence()['source'] !== SourceEvidence::capture()) {
                throw new RuntimeException('Private security source changed.');
            }
            if (($data['role'] ?? null) === 'loader') {
                $this->loader($data);
                $ack = ['version' => 1, 'nonce' => $data['nonce'], 'sequence' => $data['sequence'],
                    'phase' => $data['phase'], 'role' => 'loader', 'ordinal' => 0, 'durable' => true];
            } else {
                if ($this->pending !== null || !$this->loaderMatched) {
                    throw new RuntimeException('Loader outcome is unfinished.');
                }
                if (
                    ($data['phase'] ?? null) === 'prepare'
                    && (($data['scope_sha256'] ?? null) !== $this->scopeHash
                        || ($data['session_sha256'] ?? null) !== $this->sessionHash)
                ) {
                    throw new RuntimeException('Private loader scope changed.');
                }
                // Translate only private control sequence; never HTTP target/body/header bytes.
                $data['sequence'] = ++$this->delegatedSequence;
                $ack = get_object_vars(WebhookDeliveryApi::assertUnambiguousObject(
                    rtrim($this->journal->accept(json_encode($data, JSON_THROW_ON_ERROR)), "\n"),
                ));
                $ack['sequence'] = $this->sequence + 1;
            }
            $this->sequence++;
            return json_encode($ack, JSON_THROW_ON_ERROR) . "\n";
        } catch (\Throwable) {
            $this->poisoned = true;
            throw new RuntimeException('Private security journal could not acknowledge safely.');
        }
    }

    /** @param array<string,mixed> $data */
    private function loader(#[SensitiveParameter] array $data): void
    {
        $base = ['version', 'nonce', 'sequence', 'phase', 'role', 'ordinal', 'body_sha256', 'target_sha256'];
        $phase = $data['phase'] ?? null;
        if (!is_string($phase)) {
            throw new RuntimeException('Private security phase is unavailable.');
        }
        $fields = match ($phase) {
            'prepare' => [...$base, 'target_uri', 'headers_sha256', 'scope_sha256', 'session_sha256', 'body_bytes'],
            'observed' => [...$base, 'http_status', 'response_bytes', 'response_sha256', 'response_validation'],
            'failed' => $base,
            default => [],
        };
        $keys = array_keys($data);
        sort($keys);
        sort($fields);
        if (
            $keys !== $fields || ($data['ordinal'] ?? null) !== 0
            || $data['body_sha256'] !== hash('sha256', '')
        ) {
            throw new RuntimeException('Private loader framing changed.');
        }
        $target = self::digest($data['target_sha256']);
        if ($phase === 'prepare') {
            $uri = $data['target_uri'];
            if (
                $this->loaderUsed || $this->sequence !== 0 || $data['body_bytes'] !== 0 || !is_string($uri)
                || !hash_equals(hash('sha256', $uri), $target)
                || preg_match(
                    '~^https://static\.sandbox\.airwallex\.com/webapp/fraud/device-fingerprint/index\.js'
                    . '\?sessionId=([A-Za-z0-9_-]{1,128})&hasScriptId=false&disable3rdTracking=false$~D',
                    $uri,
                    $match
                ) !== 1
                || self::digest($data['session_sha256']) !== hash('sha256', $match[1])
                || $data['headers_sha256'] !== hash('sha256', '{"accept":"*/*"}')
            ) {
                throw new RuntimeException('Private loader target changed.');
            }
            $body = ['role' => 'loader', 'method' => 'GET', 'local_identity_only' => true,
                'target_sha256' => $target, 'wire_body_sha256' => hash('sha256', ''),
                'headers_sha256' => self::digest($data['headers_sha256']),
                'scope_sha256' => self::digest($data['scope_sha256']),
                'session_sha256' => self::digest($data['session_sha256']),
                'checkout' => $this->expected, 'tool_hashes' => $this->hashes];
            $id = $this->ledger->requestId('instrument_security_loader', $body, $this->context);
            $this->ledger->recordObservation(
                'instrument_security_loader_possible_send',
                ['local_id' => $id, 'target_sha256' => $target, 'possible_send' => 'true'],
                $this->context
            );
            $this->pending = ['body_sha256' => hash('sha256', ''), 'target_sha256' => $target, 'local_id' => $id];
            $this->loaderUsed = true;
            $this->scopeHash = self::digest($data['scope_sha256']);
            $this->sessionHash = self::digest($data['session_sha256']);
            return;
        }
        if ($this->pending === null || $this->pending['target_sha256'] !== $target) {
            throw new RuntimeException('Private loader outcome has no original send.');
        }
        $observed = ['local_id' => $this->pending['local_id'], 'outcome' => $phase];
        if ($phase === 'observed') {
            if (
                !is_int($data['http_status']) || $data['http_status'] < 100 || $data['http_status'] > 599
                || !is_int($data['response_bytes']) || $data['response_bytes'] < 0 || $data['response_bytes'] > 262144
                || !in_array($data['response_validation'], ['matched', 'withheld'], true)
            ) {
                throw new RuntimeException('Private loader response is unavailable.');
            }
            if (
                $data['response_validation'] === 'matched'
                && ($data['http_status'] !== 200 || $data['response_bytes'] !== 137456
                    || $data['response_sha256'] !== '8198d811842296bb2ab7e339c0f5f4309c39df77d20837e459c09e350c990f8b')
            ) {
                throw new RuntimeException('Private loader integrity proof changed.');
            }
            $observed += ['http_status' => (string) $data['http_status'],
                'response_bytes' => (string) $data['response_bytes'],
                'response_sha256' => self::digest($data['response_sha256']),
                'response_validation' => $data['response_validation']];
        }
        $this->ledger->recordObservation('instrument_security_loader_' . $phase, $observed, $this->context);
        $this->pending = null;
        $this->loaderMatched = $phase === 'observed' && $data['http_status'] === 200
            && $data['response_validation'] === 'matched';
        if ($phase !== 'observed' || $data['http_status'] !== 200 || $data['response_validation'] !== 'matched') {
            $this->poisoned = true;
        }
    }

    private static function digest(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new RuntimeException('Private security hash is unavailable.');
        }
        return $value;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['state' => 'private'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new RuntimeException('Private security journal cannot be serialized.');
    }
}
