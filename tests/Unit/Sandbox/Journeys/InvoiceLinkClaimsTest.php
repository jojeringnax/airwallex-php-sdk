<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoiceLinkClaims;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceLinkClaimsTest extends TestCase
{
    public static function link(
        string $payload = '{"exp":1803024000}',
        string $host = 'invoice.sandbox.airwallex.com',
    ): string {
        return 'https://' . $host . '/pay?s=' . rawurlencode('private_header.'
            . rtrim(base64_encode($payload), '=') . '.private_signature');
    }

    public function testKnownHostedFormatHasOnlyUnverifiedClockCorrelationAndNoRawValues(): void
    {
        $url = self::link('{"exp":1803024000,"private":"PRIVATE_CLAIM_VALUE"}');
        $invoice = ['hosted_url' => $url, 'due_at' => gmdate('Y-m-d\TH:i:s\Z', 1800000000),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', 1799999999), 'finalized_at' => null];
        $evidence = InvoiceLinkClaims::compare(
            $invoice,
            $invoice,
            'hosted_url',
            [1800000000, 1800000001],
            [1800000003, 1800000004]
        );
        self::assertTrue($evidence['comparison_available']);
        self::assertTrue($evidence['url_same']);
        self::assertFalse($evidence['url_different']);
        self::assertTrue($evidence['first_exp_claim_available']);
        self::assertTrue($evidence['first_exp_after_window']);
        self::assertFalse($evidence['first_exp_before_window']);
        self::assertTrue($evidence['first_due_at_plus_35_utc_days_matches_exp']);
        self::assertFalse($evidence['first_created_at_plus_35_utc_days_matches_exp']);
        self::assertFalse($evidence['first_finalized_at_clock_available']);
        self::assertTrue($evidence['exp_same']);
        $json = json_encode($evidence, JSON_THROW_ON_ERROR);
        foreach (['1803024000', 'PRIVATE', 'private_', 'airwallex.com', 'https:', '2027-'] as $value) {
            self::assertStringNotContainsString($value, $json);
        }
    }

    public function testPublicAndVerifiedNamedSandboxHostsUseIdenticalNarrowFormat(): void
    {
        foreach (['invoice.airwallex.com', 'invoice.sandbox.airwallex.com'] as $host) {
            $invoice = ['hosted_url' => self::link(host: $host)];
            $result = InvoiceLinkClaims::compare($invoice, $invoice, 'hosted_url', [1, 2], [3, 4]);
            self::assertTrue($result['first_exp_claim_available']);
            self::assertTrue($result['second_exp_claim_available']);
        }
    }

    public function testRotationAndIncreasingClaimsAreOnlyComparisons(): void
    {
        $first = ['hosted_url' => self::link('{"exp":1803024000}')];
        $second = ['hosted_url' => self::link('{"exp":1803024002}')];
        $result = InvoiceLinkClaims::compare($first, $second, 'hosted_url', [1, 2], [3, 4]);
        self::assertTrue($result['url_different']);
        self::assertTrue($result['exp_increased']);
        self::assertFalse($result['exp_same']);
        self::assertArrayNotHasKey('renewed', $result);
        self::assertArrayNotHasKey('valid', $result);
    }

    /** @return iterable<string,array{string}> */
    public static function unsupportedLinks(): iterable
    {
        $known = self::link();
        yield 'third party' => [self::link(host: 'private.example.test')];
        yield 'sandbox lookalike' => [self::link(host: 'invoice.sandbox.airwallex.com.private.test')];
        yield 'unknown path' => [str_replace('/pay?', '/billing/invoices/payment?', $known)];
        yield 'PDF path' => [str_replace('/pay?', '/billing/invoices/pdf?', $known)];
        yield 'insecure scheme' => [str_replace('https:', 'http:', $known)];
        yield 'foreign port' => [str_replace('/pay?', ':444/pay?', $known)];
        yield 'userinfo' => [str_replace('https://', 'https://private_user@', $known)];
        yield 'fragment' => [$known . '#private_fragment'];
        yield 'duplicate s' => [$known . '&s=private_second'];
        yield 'extra query key' => [$known . '&private_key=private_value'];
        yield 'encoded key' => [str_replace('?s=', '?%73=', $known)];
        yield 'malformed percent' => [$known . '%zz'];
        yield 'ambiguous plus' => [$known . '+'];
        yield 'no token' => ['https://invoice.sandbox.airwallex.com/pay'];
        yield 'oversize link' => [$known . str_repeat('x', 33000)];
        yield 'JSON string claim' => [self::link('{"exp":"1803024000"}')];
        yield 'fractional claim' => [self::link('{"exp":1803024000.1}')];
        yield 'integral float claim' => [self::link('{"exp":1803024000.0}')];
        yield 'scientific claim' => [self::link('{"exp":1803024e3}')];
        yield 'negative claim' => [self::link('{"exp":-1}')];
        yield 'overflow claim' => [self::link('{"exp":999999999999999999999999999999}')];
        yield 'unsafe JS integer' => [self::link('{"exp":9007199254740992}')];
        yield 'only other claims' => [self::link('{"iat":1803024000,"nbf":1803024000}')];
        yield 'malformed JSON' => [self::link('{"exp":1803024000,}')];
        yield 'root array' => [self::link('[1803024000]')];
        yield 'duplicate exp' => [self::link('{"exp":1,"exp":1803024000}')];
        yield 'escaped duplicate exp' => [self::link('{"exp":1,"\\u0065xp":1803024000}')];
        yield 'nested duplicate key' => [self::link('{"exp":1803024000,"other":{"a":1,"a":2}}')];
        yield 'oversize payload' => [self::link('{"exp":1803024000,"private":"' . str_repeat('a', 8200) . '"}')];
        yield 'non-JSON payload' => ['https://invoice.sandbox.airwallex.com/pay?s=header.bm90LWpzb24.signature'];
    }

    #[DataProvider('unsupportedLinks')]
    public function testUnknownFormatsRemainUnavailableWithoutSupportOrPaymentVerdict(string $url): void
    {
        $result = InvoiceLinkClaims::compare(
            ['hosted_url' => $url],
            ['hosted_url' => $url],
            'hosted_url',
            [1, 2],
            [3, 4],
        );
        self::assertFalse($result['first_exp_claim_available']);
        self::assertFalse($result['second_exp_claim_available']);
        self::assertFalse($result['exp_comparison_available']);
        self::assertArrayNotHasKey('invalid_payment', $result);
        self::assertStringNotContainsString('private', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{array<string,mixed>,array<string,mixed>,string,string}> */
    public static function linkStates(): iterable
    {
        yield 'missing/null' => [[], ['pdf_url' => null], 'missing', 'null'];
        yield 'empty/type' => [['pdf_url' => ''], ['pdf_url' => []], 'invalid', 'invalid'];
        yield 'blank/numeric' => [['pdf_url' => '  '], ['pdf_url' => 123], 'invalid', 'invalid'];
        yield 'opaque nonempty' => [
            ['pdf_url' => 'PRIVATE_OPAQUE'], ['pdf_url' => 'PRIVATE_OPAQUE'], 'nonempty', 'nonempty',
        ];
    }

    /**
     * @param array<string,mixed> $first
     * @param array<string,mixed> $second
     */
    #[DataProvider('linkStates')]
    public function testPdfIsOnlyFieldStateAndExactEqualityWithoutParsingAnyCapability(
        array $first,
        array $second,
        string $firstState,
        string $secondState,
    ): void {
        $result = InvoiceLinkClaims::compare($first, $second, 'pdf_url');
        self::assertSame($firstState, $result['first_state']);
        self::assertSame($secondState, $result['second_state']);
        self::assertCount(5, $result);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testStrictDatePrecisionOffsetsAndInvalidClocksNeverInventExpiry(): void
    {
        $invoice = ['hosted_url' => self::link('{"exp":1803024000}'),
            'due_at' => gmdate('Y-m-d\TH:i:s', 1800000000) . '.000000000000000001Z',
            'created_at' => '2028-02-30T00:00:00Z', 'finalized_at' => '2028-01-01T00:00:00+24:00'];
        $result = InvoiceLinkClaims::compare($invoice, $invoice, 'hosted_url', [20, 10], [30, 31]);
        self::assertTrue($result['first_due_at_clock_available']);
        self::assertFalse($result['first_due_at_plus_35_utc_days_matches_exp']);
        self::assertFalse($result['first_created_at_clock_available']);
        self::assertFalse($result['first_finalized_at_clock_available']);
        self::assertFalse($result['first_clock_window_available']);
        self::assertFalse($result['first_exp_after_window']);
    }

    public function testExpiredAndBoundaryClaimsFollowOnlyTheLocalObservationWindow(): void
    {
        $first = ['hosted_url' => self::link('{"exp":9}')];
        $second = ['hosted_url' => self::link('{"exp":12}')];
        $result = InvoiceLinkClaims::compare($first, $second, 'hosted_url', [10, 11], [12, 12]);
        self::assertTrue($result['first_exp_before_window']);
        self::assertFalse($result['first_exp_within_window']);
        self::assertTrue($result['second_exp_within_window']);
        self::assertFalse($result['second_exp_before_window']);
    }

    public function testOtherClaimsContainingPunctuationDoNotConfuseDuplicateKeyScanner(): void
    {
        $json = json_encode(['exp' => 1803024000, 'other' => ['a' => ['x', '{"a":1,"a":2}', 'quote"back\\slash']],
            'list' => [['a' => 1], ['a' => 2]]], JSON_THROW_ON_ERROR);
        $result = InvoiceLinkClaims::compare(
            ['hosted_url' => self::link($json)],
            ['hosted_url' => self::link($json)],
            'hosted_url',
            [1, 2],
            [3, 4]
        );
        self::assertTrue($result['first_exp_claim_available']);
    }

    public function testInvalidLocalWindowDoesNotEraseAnIndependentExactInvoiceTimestampCorrelation(): void
    {
        $invoice = ['hosted_url' => self::link('{"exp":1803024000}'),
            'due_at' => gmdate('Y-m-d\TH:i:s\Z', 1800000000)];
        $result = InvoiceLinkClaims::compare($invoice, $invoice, 'hosted_url', [20, 10], [30, 31]);
        self::assertFalse($result['first_clock_window_available']);
        self::assertFalse($result['first_exp_before_window']);
        self::assertFalse($result['first_exp_after_window']);
        self::assertFalse($result['first_exp_within_window']);
        self::assertTrue($result['first_due_at_clock_available']);
        self::assertTrue($result['first_due_at_plus_35_utc_days_matches_exp']);
    }
}
