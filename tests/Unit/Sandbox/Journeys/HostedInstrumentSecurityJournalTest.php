<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedBrowser;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedInstrumentSecurityJournal;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedRiskJournal;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedSecurityMode;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HostedInstrumentSecurityJournalTest extends TestCase
{
    private string $directory;
    private HostedRiskJournal $original;
    private HostedInstrumentSecurityJournal $journal;
    private bool $durable = true;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/airwallex-security-journal-fixture-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $context = hash('sha256', 'synthetic_context');
        $ledger = RunLedger::create($context, $this->directory, synchronize: fn (): bool => $this->durable);
        $this->original = new HostedRiskJournal(
            $ledger,
            $context,
            ['checkout_id' => 'synthetic_checkout', 'mode' => 'SETUP', 'currency' => 'EUR'],
            HostedBrowser::sourceHashes(),
            HostedBrowser::sourceHashes(HostedSecurityMode::InstrumentSecurity),
        );
        $this->journal = $this->original->instrumentAdapter(
            HostedBrowser::sourceHashes(HostedSecurityMode::InstrumentSecurity),
        );
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            self::assertInstanceOf(\SplFileInfo::class, $item);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    private function frame(string $role = 'loader', int $sequence = 1): string
    {
        $uri = $role === 'loader'
            ? 'https://static.sandbox.airwallex.com/webapp/fraud/device-fingerprint/index.js'
                . '?sessionId=PRIVATE_SESSION&hasScriptId=false&disable3rdTracking=false'
            : 'https://bws.sandbox.airwallex.com/bws/v3/visit';
        return json_encode($this->original->bootstrap() + ['sequence' => $sequence, 'phase' => 'prepare',
            'role' => $role, 'ordinal' => 0, 'body_bytes' => $role === 'loader' ? 0 : 2,
            'body_sha256' => hash('sha256', $role === 'loader' ? '' : '{}'),
            'target_uri' => $uri, 'target_sha256' => hash('sha256', $uri),
            'headers_sha256' => hash('sha256', '{"accept":"*/*"}'), 'scope_sha256' => hash('sha256', 'PRIVATE_SCOPE'),
            'session_sha256' => hash('sha256', 'PRIVATE_SESSION')], JSON_THROW_ON_ERROR);
    }

    public function testExistingConcreteDurabilityRunsBehindStrictFraming(): void
    {
        $reply = json_decode($this->journal->accept($this->frame()), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $reply);
        self::assertTrue($reply->durable);
        $prepared = json_decode($this->frame(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($prepared);
        $observed = array_intersect_key($prepared, array_flip(['version', 'nonce', 'role', 'ordinal',
            'body_sha256', 'target_sha256'])) + ['sequence' => 2, 'phase' => 'observed', 'http_status' => 200,
            'response_bytes' => 137456,
            'response_sha256' => '8198d811842296bb2ab7e339c0f5f4309c39df77d20837e459c09e350c990f8b',
            'response_validation' => 'matched'];
        $this->journal->accept(json_encode($observed, JSON_THROW_ON_ERROR));
        $visitor = json_decode($this->journal->accept($this->frame('visitor', 3)), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $visitor);
        self::assertSame(3, $visitor->sequence);
        $visitorPrepared = json_decode($this->frame('visitor', 3), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($visitorPrepared);
        $finished = array_intersect_key($visitorPrepared, array_flip(['version', 'nonce', 'role', 'ordinal',
            'body_sha256', 'target_sha256'])) + ['sequence' => 4, 'phase' => 'observed', 'http_status' => 200,
            'response_bytes' => 2, 'response_sha256' => hash('sha256', '{}'), 'response_validation' => 'matched'];
        $this->journal->accept(json_encode($finished, JSON_THROW_ON_ERROR));
        $eventFrame = str_replace(
            ['"role":"visitor"', 'https:\/\/bws.sandbox.airwallex.com\/bws\/v3\/visit',
            hash('sha256', 'https://bws.sandbox.airwallex.com/bws/v3/visit')],
            ['"role":"event"', 'https:\/\/bws.sandbox.airwallex.com\/bws\/v1\/PRIVATE_SESSION',
            hash('sha256', 'https://bws.sandbox.airwallex.com/bws/v1/PRIVATE_SESSION')],
            $this->frame('visitor', 5)
        );
        $eventAck = json_decode($this->journal->accept($eventFrame), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $eventAck);
        self::assertSame(5, $eventAck->sequence);

        $paths = glob($this->directory . '/*/ledger.json');
        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        $raw = file_get_contents($paths[0]);
        self::assertIsString($raw);
        self::assertStringContainsString('instrument_security_loader_possible_send', $raw);
        self::assertStringContainsString('instrument_security_loader_observed', $raw);
        self::assertStringContainsString('hosted_risk_visitor_0_possible_send', $raw);
        self::assertStringNotContainsString('PRIVATE_', $raw);
        self::assertStringNotContainsString('PRIVATE_', print_r($this->journal, true));
        try {
            serialize($this->journal);
            self::fail('Private serialization must be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('cannot be serialized', $error->getMessage());
        }
    }

    /** @return iterable<string,array{string}> */
    public static function ambiguousFrames(): iterable
    {
        foreach (
            ['duplicate', 'escaped_duplicate', 'numeric_string', 'float', 'oversize', 'nested', 'unknown',
            'nonce', 'sequence', 'ordinal', 'role', 'headers', 'session', 'source_book'] as $fault
        ) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('ambiguousFrames')]
    public function testFramingFailurePoisonsBeforeAnyAcknowledgement(string $fault): void
    {
        $frame = $this->frame();
        $frame = match ($fault) {
            'duplicate' => str_replace('"sequence":1', '"sequence":1,"sequence":1', $frame),
            'escaped_duplicate' => str_replace('"sequence":1', '"sequence":1,"seque\\u006ece":1', $frame),
            'numeric_string' => str_replace('"sequence":1', '"sequence":"1"', $frame),
            'float' => str_replace('"sequence":1', '"sequence":1.0', $frame),
            'oversize' => str_repeat(' ', 4097) . $frame,
            'nested' => str_replace('"phase":"prepare"', '"phase":{"PRIVATE":"value"}', $frame),
            'nonce' => str_replace($this->original->bootstrap()['nonce'], str_repeat('b', 64), $frame),
            'sequence' => str_replace('"sequence":1', '"sequence":2', $frame),
            'ordinal' => str_replace('"ordinal":0', '"ordinal":1', $frame),
            'role' => str_replace('"role":"loader"', '"role":"event"', $frame),
            'headers' => str_replace(hash('sha256', '{"accept":"*/*"}'), hash('sha256', 'PRIVATE_AUTH'), $frame),
            'session' => str_replace(hash('sha256', 'PRIVATE_SESSION'), hash('sha256', 'OTHER_SESSION'), $frame),
            'source_book' => $frame,
            default => substr($frame, 0, -1) . ',"extra":"PRIVATE"}',
        };
        if ($fault === 'source_book') {
            $hashes = HostedBrowser::sourceHashes(HostedSecurityMode::InstrumentSecurity);
            $hashes['hosted-browser.cjs'] = str_repeat('0', 64);
            $this->expectException(RuntimeException::class);
            $this->original->instrumentAdapter($hashes);
            return;
        }
        foreach ([$frame, $this->frame()] as $attempt) {
            try {
                $this->journal->accept($attempt);
                self::fail('No acknowledgement is allowed.');
            } catch (RuntimeException $error) {
                self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            }
        }
    }

    public function testFailedResultPersistenceWithholdsBwsAndCannotReplayTheLoader(): void
    {
        $this->journal->accept($this->frame());
        $prepared = json_decode($this->frame(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($prepared);
        $result = array_intersect_key($prepared, array_flip(['version', 'nonce', 'role', 'ordinal',
            'body_sha256', 'target_sha256'])) + ['sequence' => 2, 'phase' => 'observed', 'http_status' => 200,
            'response_bytes' => 137456,
            'response_sha256' => '8198d811842296bb2ab7e339c0f5f4309c39df77d20837e459c09e350c990f8b',
            'response_validation' => 'matched'];
        $this->durable = false;
        foreach ([json_encode($result, JSON_THROW_ON_ERROR), $this->frame('visitor', 3), $this->frame()] as $frame) {
            try {
                $this->journal->accept($frame);
                self::fail('Failed result persistence cannot grant a dependent acknowledgement.');
            } catch (RuntimeException $error) {
                self::assertStringNotContainsString('PRIVATE_', $error->getMessage());
            }
        }
    }

    public function testFailedDurabilityReturnsNoAcknowledgement(): void
    {
        $this->durable = false;
        $this->expectException(RuntimeException::class);
        $this->journal->accept($this->frame());
    }
}
