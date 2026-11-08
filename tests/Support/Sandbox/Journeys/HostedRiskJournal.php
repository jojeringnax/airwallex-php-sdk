<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use RuntimeException;
use SensitiveParameter;

/** A concrete private durability handshake for this probe's bounded BWS telemetry sends. */
final class HostedRiskJournal
{
    private int $sequence = 0;
    private int $events = 0;
    private int $eventBytes = 0;
    private bool $visitor = false;
    private bool $preflight = false;
    private bool $poisoned = false;
    private ?string $scope = null;
    private ?string $sessionDigest = null;
    /** @var array<string,true> */
    private array $wireIdentities = [];
    /** @var array{role:string,ordinal:int,body_sha256:string,target_sha256:string,local_id:string}|null */
    private ?array $pending = null;
    private readonly string $nonce;

    /**
     * @param array{checkout_id:string,mode:string,currency:string} $expected
     * @param array<string,string> $toolHashes Fixed source book captured before handoff.
     * @param array<string,string>|null $instrumentHashes Extended book captured before authentication.
     */
    public function __construct(
        private readonly RunLedger $ledger,
        #[SensitiveParameter] private readonly string $context,
        #[SensitiveParameter] private readonly array $expected,
        private readonly array $toolHashes,
        private readonly ?array $instrumentHashes = null,
    ) {
        $ledger->assertContext($context);
        $this->nonce = bin2hex(random_bytes(32));
    }

    /** @return array{version:int,nonce:string} Private bootstrap, never operator output. */
    public function bootstrap(): array
    {
        return ['version' => 1, 'nonce' => $this->nonce];
    }

    /** @param array<string,string> $toolHashes Fixed extended helper book; no caller target selector. */
    public function instrumentAdapter(array $toolHashes): HostedInstrumentSecurityJournal
    {
        if ($this->instrumentHashes === null || $toolHashes !== $this->instrumentHashes) {
            throw new RuntimeException('Instrument source book does not match its original profile.');
        }
        return new HostedInstrumentSecurityJournal(
            $this,
            $this->instrumentHashes,
            $this->ledger,
            $this->context,
            $this->expected,
        );
    }

    /** One line in; a bounded private ACK only after the corresponding durable writes. */
    public function accept(#[SensitiveParameter] string $line): string
    {
        try {
            if ($this->poisoned || strlen($line) > 4096 || str_contains($line, "\n")) {
                throw new RuntimeException('Private risk journal is unavailable.');
            }
            $decoded = (new JsonCodec())->decodeObject($line, [], [
                '/version', '/sequence', '/ordinal', '/body_bytes', '/http_status', '/response_bytes',
            ]);
            $data = [];
            foreach ($decoded as $key => $value) {
                if (!is_string($key)) {
                    throw new RuntimeException('Private risk journal requires named fields.');
                }
                $data[$key] = $value;
            }
            if (
                ($data['version'] ?? null) !== 1 || !is_string($data['nonce'] ?? null)
                || !hash_equals($this->nonce, $data['nonce'])
                || ($data['sequence'] ?? null) !== $this->sequence + 1
                || !in_array($data['role'] ?? null, ['visitor', 'event', 'preflight'], true)
                || !is_int($data['ordinal'] ?? null)
            ) {
                throw new RuntimeException('Private risk journal framing is invalid.');
            }
            $this->ledger->assertContext($this->context);
            if (HostedBrowser::sourceHashes() !== $this->toolHashes) {
                throw new RuntimeException('Private risk tooling changed.');
            }
            $phase = $data['phase'] ?? null;
            if ($phase === 'prepare') {
                $this->prepare($data);
            } elseif ($phase === 'observed' || $phase === 'failed') {
                $this->finish($data, $phase);
            } else {
                throw new RuntimeException('Private risk journal phase is invalid.');
            }
            $this->sequence++;
            return json_encode([
                'version' => 1, 'nonce' => $this->nonce, 'sequence' => $this->sequence,
                'phase' => $phase, 'role' => $data['role'], 'ordinal' => $data['ordinal'], 'durable' => true,
            ], JSON_THROW_ON_ERROR) . "\n";
        } catch (\Throwable) {
            $this->poisoned = true;
            throw new RuntimeException('Private risk journal could not acknowledge safely.');
        }
    }

    /** @param array<string,mixed> $data */
    private function prepare(#[SensitiveParameter] array $data): void
    {
        self::keys($data, ['version', 'nonce', 'sequence', 'phase', 'role', 'ordinal',
            'body_sha256', 'target_sha256', 'target_uri', 'headers_sha256', 'scope_sha256',
            'session_sha256', 'body_bytes']);
        $role = $data['role'];
        $ordinal = $data['ordinal'];
        $bytes = $data['body_bytes'];
        if (
            !is_string($role) || !in_array($role, ['visitor', 'event', 'preflight'], true)
            || $this->pending !== null || !is_int($ordinal) || !is_int($bytes) || $bytes > 65536
            || ($role === 'preflight' ? $bytes !== 0 : $bytes < 1)
            || ($role === 'visitor' && ($this->visitor || $ordinal !== 0))
            || ($role === 'preflight' && ($this->preflight || $ordinal !== 0 || $this->visitor))
            || ($role === 'event' && ($ordinal !== $this->events || $this->events >= 8
                || $this->eventBytes + $bytes > 262144))
        ) {
            throw new RuntimeException('Private risk send budget is unavailable.');
        }
        $bodyHash = self::digest($data['body_sha256']);
        if ($role === 'preflight' && !hash_equals(hash('sha256', ''), $bodyHash)) {
            throw new RuntimeException('Private risk preflight body changed.');
        }
        $targetHash = self::digest($data['target_sha256']);
        $headersHash = self::digest($data['headers_sha256']);
        $scope = self::digest($data['scope_sha256']);
        $session = self::digest($data['session_sha256']);
        $wireIdentity = $targetHash . ':' . $bodyHash;
        if (isset($this->wireIdentities[$wireIdentity])) {
            throw new RuntimeException('Private risk wire data cannot be sent twice.');
        }
        if ($this->scope !== null && !hash_equals($this->scope, $scope)) {
            throw new RuntimeException('Private risk scope changed.');
        }
        if ($this->sessionDigest !== null && !hash_equals($this->sessionDigest, $session)) {
            throw new RuntimeException('Private risk session changed.');
        }
        $uri = $data['target_uri'];
        if (!is_string($uri) || strlen($uri) > 512 || !hash_equals(hash('sha256', $uri), $targetHash)) {
            throw new RuntimeException('Private risk target digest changed.');
        }
        if ($role === 'event') {
            if (
                preg_match(
                    '~^https://bws\.sandbox\.airwallex\.com/bws/v1/([A-Za-z0-9_-]{1,128})$~D',
                    $uri,
                    $match,
                ) !== 1
                || !hash_equals(hash('sha256', $match[1]), $session)
            ) {
                throw new RuntimeException('Private risk event target changed.');
            }
        }
        if (
            in_array($role, ['visitor', 'preflight'], true)
            && !hash_equals(hash('sha256', 'https://bws.sandbox.airwallex.com/bws/v3/visit'), $targetHash)
        ) {
            throw new RuntimeException('Private risk visitor target changed.');
        }
        $this->scope = $scope;
        $this->sessionDigest = $session;
        $action = 'hosted_risk_' . $role . '_' . $ordinal;
        $localId = $this->ledger->requestId($action, [
            'role' => $role, 'ordinal' => $ordinal, 'method' => $role === 'preflight' ? 'OPTIONS' : 'POST',
            'local_identity_only' => true,
            'wire_body_sha256' => $bodyHash, 'target_sha256' => $targetHash,
            'headers_sha256' => $headersHash, 'scope_sha256' => $scope,
            'session_sha256' => $session, 'body_bytes' => $bytes,
            'checkout' => $this->expected, 'tool_hashes' => $this->toolHashes,
        ], $this->context);
        // This possible-send state is durable BEFORE the ACK permits client invocation.
        $this->ledger->recordObservation($action . '_possible_send', [
            'local_id' => $localId, 'wire_body_sha256' => $bodyHash,
            'target_sha256' => $targetHash, 'possible_send' => 'true',
        ], $this->context);
        $this->pending = ['role' => $role, 'ordinal' => $ordinal,
            'body_sha256' => $bodyHash, 'target_sha256' => $targetHash, 'local_id' => $localId];
        $this->wireIdentities[$wireIdentity] = true;
        if ($role === 'preflight') {
            $this->preflight = true;
        } elseif ($role === 'visitor') {
            $this->visitor = true;
        } else {
            $this->events++;
            $this->eventBytes += $bytes;
        }
    }

    /** @param array<string,mixed> $data */
    private function finish(#[SensitiveParameter] array $data, string $phase): void
    {
        $keys = ['version', 'nonce', 'sequence', 'phase', 'role', 'ordinal', 'body_sha256', 'target_sha256'];
        self::keys($data, $phase === 'observed'
            ? [...$keys, 'http_status', 'response_bytes', 'response_sha256', 'response_validation'] : $keys);
        $pending = $this->pending;
        if (
            $pending === null || $data['role'] !== $pending['role'] || $data['ordinal'] !== $pending['ordinal']
            || !hash_equals($pending['body_sha256'], self::digest($data['body_sha256']))
            || !hash_equals($pending['target_sha256'], self::digest($data['target_sha256']))
        ) {
            throw new RuntimeException('Private risk result lacks its pending send.');
        }
        $observation = ['local_id' => $pending['local_id'], 'outcome' => $phase];
        if ($phase === 'observed') {
            $status = $data['http_status'];
            $bytes = $data['response_bytes'];
            if (!is_int($status) || $status < 100 || $status > 599 || !is_int($bytes) || $bytes < 0 || $bytes > 65536) {
                throw new RuntimeException('Private risk response is outside its bound.');
            }
            $validation = $data['response_validation'];
            if (!in_array($validation, ['matched', 'withheld'], true)) {
                throw new RuntimeException('Private risk response validation is unavailable.');
            }
            $observation += ['http_status' => (string) $status, 'response_bytes' => (string) $bytes,
                'response_sha256' => self::digest($data['response_sha256']), 'response_validation' => $validation];
            if (
                $validation === 'withheld'
                || ($pending['role'] === 'visitor' ? $status !== 200 : $status < 200 || $status >= 300)
            ) {
                $this->poisoned = true;
            }
        }
        $this->ledger->recordObservation(
            'hosted_risk_' . $pending['role'] . '_' . $pending['ordinal'] . '_result',
            $observation,
            $this->context,
        );
        $this->pending = null;
        if ($phase === 'failed') {
            $this->poisoned = true;
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $expected
     */
    private static function keys(#[SensitiveParameter] array $data, array $expected): void
    {
        $keys = array_keys($data);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new RuntimeException('Private risk journal fields are invalid.');
        }
    }

    private static function digest(#[SensitiveParameter] mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new RuntimeException('Private risk digest is invalid.');
        }
        return $value;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['journal' => '[private risk telemetry handshake]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Private risk journal cannot be serialized.');
    }
}
