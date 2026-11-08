<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedBrowser;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedRiskJournal;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class HostedRiskJournalTest extends TestCase
{
    private string $directory;
    private string $context;
    private HostedRiskJournal $journal;
    private int $sequence = 0;
    private bool $durable = true;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-risk-journal-fixture-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->context = hash('sha256', 'synthetic_organization_context');
        $ledger = RunLedger::create($this->context, $this->directory, synchronize: fn (): bool => $this->durable);
        $this->journal = new HostedRiskJournal($ledger, $this->context, [
            'checkout_id' => 'fixture_checkout', 'mode' => 'SETUP', 'currency' => 'EUR',
        ], HostedBrowser::sourceHashes());
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            self::assertInstanceOf(SplFileInfo::class, $item);
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->directory);
    }

    /** @return array<string,mixed> */
    private function prepare(string $role = 'visitor', int $ordinal = 0, int $bytes = 24): array
    {
        return $this->journal->bootstrap() + [
            'sequence' => ++$this->sequence, 'phase' => 'prepare', 'role' => $role, 'ordinal' => $ordinal,
            'body_sha256' => hash('sha256', $role === 'preflight' ? '' : $role . '_' . $ordinal),
            'target_sha256' => hash('sha256', $role === 'event'
                ? 'https://bws.sandbox.airwallex.com/bws/v1/private_synthetic_session'
                : 'https://bws.sandbox.airwallex.com/bws/v3/visit'),
            'target_uri' => $role === 'event'
                ? 'https://bws.sandbox.airwallex.com/bws/v1/private_synthetic_session'
                : 'https://bws.sandbox.airwallex.com/bws/v3/visit',
            'headers_sha256' => hash('sha256', 'synthetic_headers'),
            'scope_sha256' => hash('sha256', 'synthetic_document_and_session'),
            'session_sha256' => hash('sha256', 'private_synthetic_session'), 'body_bytes' => $bytes,
        ];
    }

    /** @param array<string,mixed> $data */
    private function accept(array $data): void
    {
        $reply = json_decode(
            $this->journal->accept(json_encode($data, JSON_THROW_ON_ERROR)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($reply);
        self::assertTrue($reply['durable']);
        self::assertSame($data['sequence'], $reply['sequence']);
    }

    /**
     * @param array<string,mixed> $prepared
     * @return array<string,mixed>
     */
    private function observed(array $prepared, int $status = 200): array
    {
        return array_intersect_key($prepared, array_flip([
            'version', 'nonce', 'role', 'ordinal', 'body_sha256', 'target_sha256',
        ])) + ['sequence' => ++$this->sequence, 'phase' => 'observed',
            'http_status' => $status, 'response_bytes' => 2, 'response_sha256' => hash('sha256', '{}'),
            'response_validation' => 'matched'];
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $paths = glob($this->directory . '/*/ledger.json');
        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        $raw = file_get_contents($paths[0]);
        self::assertIsString($raw);
        self::assertStringNotContainsString('private_synthetic_session', $raw);
        self::assertStringNotContainsString('PRIVATE_BODY_SENTINEL', $raw);
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $named = [];
        foreach ($data as $key => $value) {
            self::assertIsString($key);
            $named[$key] = $value;
        }
        return $named;
    }

    /** @return array<string,string> */
    private function observation(string $phase): array
    {
        $data = $this->state();
        self::assertIsArray($data['observations']);
        $observation = $data['observations'][$phase] ?? null;
        self::assertIsArray($observation);
        $named = [];
        foreach ($observation as $key => $value) {
            self::assertIsString($key);
            self::assertIsString($value);
            $named[$key] = $value;
        }
        return $named;
    }

    public function testAckFollowsDurableExactWireIntentAndPossibleSendThenResponseFacts(): void
    {
        $prepared = $this->prepare();
        $this->accept($prepared);
        $possible = $this->observation('hosted_risk_visitor_0_possible_send');
        self::assertSame($prepared['body_sha256'], $possible['wire_body_sha256']);
        self::assertSame('true', $possible['possible_send']);
        $this->accept($this->observed($prepared));
        $state = $this->state();
        self::assertSame('200', $this->observation('hosted_risk_visitor_0_result')['http_status']);
        self::assertIsString($prepared['nonce']);
        self::assertStringNotContainsString($prepared['nonce'], json_encode($state, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($prepared['nonce'], print_r($this->journal, true));
    }

    public function testDurabilityFailureNeverReturnsAnAckAndPermanentlyPoisonsJournal(): void
    {
        $prepared = $this->prepare();
        $this->durable = false;
        try {
            $this->accept($prepared);
            self::fail('An undurable send was acknowledged.');
        } catch (RuntimeException $error) {
            self::assertSame('Private risk journal could not acknowledge safely.', $error->getMessage());
        }
        $this->durable = true;
        $this->expectException(RuntimeException::class);
        $this->accept($prepared);
    }

    public function testNoProviderRequestIdIsInventedAndNoOverlappingPrepareIsAcknowledged(): void
    {
        $this->accept($this->prepare());
        $this->expectException(RuntimeException::class);
        $this->accept($this->prepare('event'));
    }

    public function testFiniteEventCountAndCumulativeWireByteBudget(): void
    {
        for ($ordinal = 0; $ordinal < 8; $ordinal++) {
            $prepared = $this->prepare('event', $ordinal, 32768);
            $this->accept($prepared);
            $this->accept($this->observed($prepared, 204));
        }
        $this->expectException(RuntimeException::class);
        $this->accept($this->prepare('event', 8));
    }

    public function testCumulativeEventBytesStopBeforeTheFifthMaximumBody(): void
    {
        for ($ordinal = 0; $ordinal < 4; $ordinal++) {
            $prepared = $this->prepare('event', $ordinal, 65536);
            $this->accept($prepared);
            $this->accept($this->observed($prepared, 204));
        }
        $this->expectException(RuntimeException::class);
        $this->accept($this->prepare('event', 4));
    }

    public function testOnlyAnEmptyOncePreflightCanPrecedeVisitorWithoutAWireRequestId(): void
    {
        $prepared = $this->prepare('preflight', bytes: 0);
        $ack = json_decode(
            $this->journal->accept(json_encode($prepared, JSON_THROW_ON_ERROR)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($ack);
        self::assertTrue($ack['durable']);
        self::assertArrayNotHasKey('request_id', $ack);
        $this->accept($this->observed($prepared, 204));
        $this->accept($this->prepare());
    }

    public function testEventTargetMustMatchTheExactLocallyBoundSession(): void
    {
        $prepared = $this->prepare('event');
        $prepared['session_sha256'] = hash('sha256', 'another_synthetic_session');
        $this->expectException(RuntimeException::class);
        $this->accept($prepared);
    }

    public function testSameBeaconBodyCannotBecomeAnotherOrdinalOrFallbackSend(): void
    {
        $prepared = $this->prepare('event');
        $this->accept($prepared);
        $this->accept($this->observed($prepared));
        $replay = $this->prepare('event', 1);
        $replay['body_sha256'] = $prepared['body_sha256'];
        $this->expectException(RuntimeException::class);
        $this->accept($replay);
    }

    public function testFailedPossibleSendRetainsResultAndPreventsAnotherTelemetryInvocation(): void
    {
        $prepared = $this->prepare();
        $this->accept($prepared);
        $failed = array_intersect_key($prepared, array_flip([
            'version', 'nonce', 'role', 'ordinal', 'body_sha256', 'target_sha256',
        ])) + ['sequence' => ++$this->sequence, 'phase' => 'failed'];
        $this->accept($failed);
        self::assertSame('failed', $this->observation('hosted_risk_visitor_0_result')['outcome']);
        $this->expectException(RuntimeException::class);
        $this->accept($this->prepare('event'));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidPrepare(): iterable
    {
        yield 'wrong nonce' => ['nonce', 'PRIVATE_BODY_SENTINEL'];
        yield 'wrong sequence' => ['sequence', 2];
        yield 'fractional sequence' => ['sequence', 1.5];
        yield 'unknown financial role' => ['role', 'verify'];
        yield 'wrong ordinal' => ['ordinal', 1];
        yield 'wrong version' => ['version', 2];
        yield 'oversize body' => ['body_bytes', 65537];
        yield 'no visitor body' => ['body_bytes', 0];
        yield 'raw body field' => ['body', 'PRIVATE_BODY_SENTINEL'];
        yield 'malformed hash' => ['body_sha256', 'PRIVATE_BODY_SENTINEL'];
        yield 'arbitrary target' => ['target_sha256', hash('sha256', 'https://unknown.invalid/private')];
    }

    #[DataProvider('invalidPrepare')]
    public function testUntrustedFramingCannotAcquireSendPermission(string $key, mixed $value): void
    {
        $prepared = $this->prepare();
        $prepared[$key] = $value;
        $this->expectException(RuntimeException::class);
        $this->accept($prepared);
    }

    public function testRealAnonymousBridgeServicesDurabilityWhileDrainingOnlySafeStdout(): void
    {
        $script = <<<'PHP'
        $private = json_decode(file_get_contents(__DIR__ . '/handoff'), true, flags: JSON_THROW_ON_ERROR);
        if (($private['observeRisk'] ?? null) !== true || isset($private['credentials'])) {
            exit(3);
        }
        $request = STDOUT;
        $ack = STDIN;
        $message = $private['riskJournal'] + ['sequence' => 1, 'phase' => 'prepare',
            'role' => 'visitor', 'ordinal' => 0, 'body_sha256' => hash('sha256', 'PRIVATE_RAW_BODY'),
            'target_uri' => 'https://bws.sandbox.airwallex.com/bws/v3/visit',
            'target_sha256' => hash('sha256', 'https://bws.sandbox.airwallex.com/bws/v3/visit'),
            'headers_sha256' => hash('sha256', 'PRIVATE_HEADERS'), 'scope_sha256' => hash('sha256', 'PRIVATE_SCOPE'),
            'session_sha256' => hash('sha256', 'PRIVATE_SESSION'), 'body_bytes' => 16];
        fwrite($request, json_encode($message, JSON_THROW_ON_ERROR) . "\n");
        $reply = json_decode(fgets($ack, 4097), true, flags: JSON_THROW_ON_ERROR);
        if (($reply['durable'] ?? null) !== true || $reply['sequence'] !== 1) {
            exit(4);
        }
        // A synthetic invocation marker is created only after the actual parent's durability ACK.
        file_put_contents(__DIR__ . '/invoked', 'once');
        $message = array_intersect_key($message, array_flip([
            'version', 'nonce', 'role', 'ordinal', 'body_sha256', 'target_sha256',
        ])) + ['sequence' => 2, 'phase' => 'observed', 'http_status' => 200,
            'response_bytes' => 2, 'response_sha256' => hash('sha256', '{}'), 'response_validation' => 'matched'];
        fwrite($request, json_encode($message, JSON_THROW_ON_ERROR) . "\n");
        $reply = json_decode(fgets($ack, 4097), true, flags: JSON_THROW_ON_ERROR);
        if (($reply['durable'] ?? null) !== true || $reply['sequence'] !== 2) {
            exit(5);
        }
        fclose($request);
        fclose($ack);
        file_put_contents(__DIR__ . '/response',
            '{"result":"blocked","reason":"financial_sandbox_boundary_unverified","card_entry_enabled":false,
            "risk_journal_prepared":1,"risk_journal_results":1,"risk_journal_closed":true}');
        PHP;
        $node = $this->directory . '/fixture-node';
        file_put_contents($this->directory . '/fixture-worker.php', "<?php\n" . $script);
        $quoted = escapeshellarg($this->directory);
        file_put_contents($node, "#!/bin/sh\nset -eu\numask 077\ncat > " . $quoted . "/handoff\n"
            . escapeshellarg(PHP_BINARY) . ' ' . $quoted . "/fixture-worker.php 0<&4 1>&3 3>&- 4>&-\n"
            . 'cat ' . $quoted . "/response\n");
        chmod($node, 0700);
        mkdir($this->directory . '/module', 0700);
        file_put_contents($this->directory . '/module/index.js', 'module.exports = {};');
        $bridge = new HostedBrowser($node, $this->directory . '/module');
        $safe = $bridge->inspect('https://checkout.sandbox.airwallex.com/pay?s=PRIVATE_CAPABILITY', [
            'checkout_id' => 'fixture_checkout', 'mode' => 'SETUP', 'currency' => 'EUR',
        ], true, true, true, true, $this->journal);
        self::assertSame('once', file_get_contents($this->directory . '/invoked'));
        self::assertSame('observed', $this->observation('hosted_risk_visitor_0_result')['outcome']);
        self::assertSame(1, $safe['risk_journal_prepared']);
        self::assertSame(1, $safe['risk_journal_results']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($safe, JSON_THROW_ON_ERROR));
    }
}
