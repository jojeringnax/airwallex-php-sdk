<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Webhook;

use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Exception\SdkException;
use Jojeringnax\Airwallex\Exception\WebhookVerificationException;
use Jojeringnax\Airwallex\Webhook\WebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebhookVerifierTest extends TestCase
{
    private const string SECRET = 'synthetic-endpoint-secret';
    private const string BODY = '{"data": {"object": {}}, "version": "2026-08-21"}';
    private const int NOW = 1794132000000;

    public function testVerifiesExactBytesWithHexSignaturesAndInclusiveTolerance(): void
    {
        $verifier = new WebhookVerifier(self::SECRET, 100, static fn (): int => self::NOW);
        foreach ([self::NOW - 100, self::NOW, self::NOW + 100] as $milliseconds) {
            $timestamp = (string) $milliseconds;
            $signature = hash_hmac('sha256', $timestamp . self::BODY, self::SECRET);
            $verifier->verify(self::BODY, $timestamp, $signature);
            $verifier->verify(self::BODY, $timestamp, strtoupper($signature));
        }
        $this->addToAssertionCount(6);
    }

    public function testIndependentDigestVector(): void
    {
        // Generated independently with Python hmac/hashlib.sha256 from these synthetic bytes.
        $signature = 'd2bc1d8680b7897b86547938df63276c38f4cb84d03d14d2abc7803f27eaa1cc';
        (new WebhookVerifier(self::SECRET, 0, static fn (): int => self::NOW))
            ->verify(self::BODY, (string) self::NOW, $signature);
        $this->addToAssertionCount(1);
    }

    public function testRetainsLeadingZeroTimestampBytesInDigest(): void
    {
        $timestamp = '000' . self::NOW;
        $signature = hash_hmac('sha256', $timestamp . self::BODY, self::SECRET);
        (new WebhookVerifier(self::SECRET, 0, static fn (): int => self::NOW))
            ->verify(self::BODY, $timestamp, $signature);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidDeliveries(): iterable
    {
        $timestamp = (string) self::NOW;
        $signature = hash_hmac('sha256', $timestamp . self::BODY, self::SECRET);
        yield 'modified whitespace' => [self::BODY . ' ', $timestamp, $signature];
        yield 'modified bytes' => [str_replace('2026', '2025', self::BODY), $timestamp, $signature];
        yield 'wrong secret' => [self::BODY, $timestamp, hash_hmac('sha256', $timestamp . self::BODY, 'wrong-secret')];
        yield 'removed timestamp zero' => [self::BODY, '0' . $timestamp, $signature];
        foreach (['', ' ', '1.0', '-1', '+1', '1e12', $timestamp . "\n", $timestamp . ',1'] as $index => $header) {
            yield 'bad timestamp ' . $index => [self::BODY, $header, $signature];
        }
        foreach (
            ['', ' ', str_repeat('g', 64), str_repeat('a', 63), $signature . "\n", 'sha256=' . $signature,
            $signature . ',' . $signature] as $index => $header
        ) {
            yield 'bad signature ' . $index => [self::BODY, $timestamp, $header];
        }
        foreach ([self::NOW - 101, self::NOW + 101, '99999999999999999999999999'] as $index => $header) {
            $header = (string) $header;
            yield 'outside tolerance/range ' . $index => [
                self::BODY, $header, hash_hmac('sha256', $header . self::BODY, self::SECRET),
            ];
        }
    }

    #[DataProvider('invalidDeliveries')]
    public function testRejectsInvalidDeliveries(string $body, string $timestamp, string $signature): void
    {
        $this->expectException(WebhookVerificationException::class);
        (new WebhookVerifier(self::SECRET, 100, static fn (): int => self::NOW))->verify($body, $timestamp, $signature);
    }

    public function testZeroAndIntegerMaximumAreHandledWithoutOverflow(): void
    {
        foreach ([0, PHP_INT_MAX] as $now) {
            foreach (['0', (string) PHP_INT_MAX] as $timestamp) {
                $signature = hash_hmac('sha256', $timestamp . self::BODY, self::SECRET);
                (new WebhookVerifier(self::SECRET, PHP_INT_MAX, static fn (): int => $now))
                    ->verify(self::BODY, $timestamp, $signature);
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInvalidConfigurationRejectsEmptySecretAndNegativeTolerance(): void
    {
        foreach ([['', 0], [self::SECRET, -1]] as [$secret, $tolerance]) {
            try {
                new WebhookVerifier($secret, $tolerance, static fn (): int => self::NOW);
                self::fail('Invalid configuration was accepted.');
            } catch (ConfigurationException $error) {
                self::assertInstanceOf(SdkException::class, $error);
            }
        }
    }

    public function testClockFailuresDoNotExposePreviousExceptionsOrValues(): void
    {
        $clocks = [
            static fn (): int => -1,
            static fn (): string => 'synthetic-private-clock-value',
            static function (): never {
                throw new RuntimeException('synthetic-private-clock-value');
            },
        ];
        foreach ($clocks as $clock) {
            try {
                $timestamp = (string) self::NOW;
                $signature = hash_hmac('sha256', $timestamp . self::BODY, self::SECRET);
                (new WebhookVerifier(self::SECRET, 0, $clock))->verify(self::BODY, $timestamp, $signature);
                self::fail('Invalid clock was accepted.');
            } catch (ConfigurationException $error) {
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('synthetic-private-clock-value', (string) $error);
            }
        }
    }

    public function testInvalidSignatureNeverCallsClockAndDiagnosticsHidePayload(): void
    {
        $verifier = new WebhookVerifier(self::SECRET, 0, static function (): never {
            self::fail('Clock called before authenticity was established.');
        });
        try {
            $verifier->verify('synthetic-private-payload', (string) self::NOW, str_repeat('0', 64));
            self::fail('Invalid signature was accepted.');
        } catch (WebhookVerificationException $error) {
            self::assertStringNotContainsString('synthetic-private-payload', (string) $error);
            self::assertStringNotContainsString(self::SECRET, print_r($error, true));
            self::assertStringNotContainsString(self::SECRET, print_r($verifier, true));
            self::assertStringNotContainsString('synthetic-private-payload', serialize($error));
            self::assertNull($error->getPrevious());
        }
        $this->expectException(ConfigurationException::class);
        serialize($verifier);
    }
}
