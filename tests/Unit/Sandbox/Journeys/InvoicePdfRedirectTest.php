<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoiceAccessPolicy;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoicePdfRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoicePdfRedirectTest extends TestCase
{
    private const string BASE = InvoiceAccessPolicy::ORIGIN . '/private/initial.pdf?old=PRIVATE%3D';

    /** @return iterable<string,array{string,string}> */
    public static function admitted(): iterable
    {
        yield 'absolute' => [InvoiceAccessPolicy::ORIGIN . '/PRIVATE%2fnext.pdf?cap=PRIVATE+VALUE%3D&x=1&x=2',
            InvoiceAccessPolicy::ORIGIN . '/PRIVATE%2fnext.pdf?cap=PRIVATE+VALUE%3D&x=1&x=2'];
        yield 'network reference' => ['//invoice.sandbox.airwallex.com/next.pdf?x=PRIVATE%2B',
            InvoiceAccessPolicy::ORIGIN . '/next.pdf?x=PRIVATE%2B'];
        yield 'absolute path' => ['/next.pdf?cap=PRIVATE%3d', InvoiceAccessPolicy::ORIGIN . '/next.pdf?cap=PRIVATE%3d'];
        yield 'relative path' => ['next.pdf?cap=PRIVATE+VALUE',
            InvoiceAccessPolicy::ORIGIN . '/private/next.pdf?cap=PRIVATE+VALUE'];
        yield 'nested relative path' => ['nested/next.pdf?cap=PRIVATE',
            InvoiceAccessPolicy::ORIGIN . '/private/nested/next.pdf?cap=PRIVATE'];
        yield 'query reference' => ['?cap=PRIVATE%3D&part=one&part=two',
            InvoiceAccessPolicy::ORIGIN . '/private/initial.pdf?cap=PRIVATE%3D&part=one&part=two'];
        yield 'query dot bytes remain opaque' => ['?cap=../PRIVATE%2e%2e%2fvalue',
            InvoiceAccessPolicy::ORIGIN . '/private/initial.pdf?cap=../PRIVATE%2e%2e%2fvalue'];
    }

    #[DataProvider('admitted')]
    public function testOnlyRfcResolutionChangesTheReferenceAndOpaqueBytesRemainExact(
        string $reference,
        string $target,
    ): void {
        $decision = InvoicePdfRedirect::inspect(self::BASE, new Response(301, ['Location' => $reference]));
        self::assertSame('admitted', $decision['policy']);
        self::assertSame($target, $decision['target']);
        self::assertSame(hash('sha256', $reference), $decision['location_sha256']);
    }

    /** @return iterable<string,array{array<string,string|list<string>>,string}> */
    public static function withheld(): iterable
    {
        yield 'missing' => [[], 'missing_location'];
        yield 'empty' => [['Location' => ''], 'missing_location'];
        yield 'duplicate same' => [['Location' => ['/next', '/next']], 'multiple_locations'];
        yield 'duplicate foreign' => [['Location' => ['/next', 'https://PRIVATE.test/next']], 'multiple_locations'];
        yield 'combined' => [['Location' => '/next,/other'], 'ambiguous_location'];
        yield 'oversize' => [['Location' => '/' . str_repeat('a', 32768)], 'oversize'];
        yield 'backslash' => [['Location' => '/PRIVATE\next'], 'lexical'];
        yield 'encoded backslash' => [['Location' => '/PRIVATE%5cnext'], 'lexical'];
        yield 'encoded control' => [['Location' => '/PRIVATE%0d%0anext'], 'lexical'];
        yield 'fragment' => [['Location' => '/next#PRIVATE'], 'lexical'];
        yield 'encoded DEL' => [['Location' => '?cap=PRIVATE%7f'], 'lexical'];
        yield 'malformed escape' => [['Location' => '/PRIVATE%GG'], 'uri'];
        yield 'normalizing byte' => [['Location' => '/PRIVATE<value'], 'uri'];
        yield 'empty query marker' => [['Location' => '?'], 'uri'];
        yield 'scheme normalization' => [['Location' => 'HTTPS://invoice.sandbox.airwallex.com/next'], 'uri'];
        yield 'foreign' => [['Location' => 'https://private.test/next'], 'origin'];
        yield 'shared' => [['Location' => 'https://invoice.airwallex.com/next'], 'origin'];
        yield 'lookalike' => [['Location' => 'https://invoice.sandbox.airwallex.com.private.test/next'], 'origin'];
        yield 'downgrade' => [['Location' => 'http://invoice.sandbox.airwallex.com/next'], 'origin'];
        yield 'foreign network' => [['Location' => '//private.test/next'], 'origin'];
        yield 'userinfo' => [['Location' => 'https://PRIVATE@invoice.sandbox.airwallex.com/next'], 'origin'];
        yield 'alternate port' => [['Location' => 'https://invoice.sandbox.airwallex.com:444/next'], 'origin'];
        yield 'default port normalized' => [['Location' => 'https://invoice.sandbox.airwallex.com:443/next'], 'uri'];
        yield 'dot' => [['Location' => './next.pdf'], 'dot_segments'];
        yield 'parent' => [['Location' => '../next.pdf'], 'dot_segments'];
        yield 'absolute dot' => [['Location' => '/a/../next.pdf'], 'dot_segments'];
        yield 'escaped dot' => [['Location' => '/a/%2e%2e/next.pdf'], 'dot_segments'];
        yield 'escaped separator with dot' => [['Location' => '/a%2f..%2fnext.pdf'], 'dot_segments'];
        yield 'self' => [['Location' => self::BASE], 'self_reference'];
        yield 'same query' => [['Location' => '?old=PRIVATE%3D'], 'self_reference'];
        yield 'retry after' => [['Location' => '/next', 'Retry-After' => '0'], 'retry_after'];
    }

    /** @param array<string,string|list<string>> $headers */
    #[DataProvider('withheld')]
    public function testUnknownOrAmbiguousReferencesNeverYieldATargetOrDiagnosticValue(
        array $headers,
        string $policy,
    ): void {
        $decision = InvoicePdfRedirect::inspect(self::BASE, new Response(301, $headers));
        self::assertSame($policy, $decision['policy']);
        self::assertNull($decision['target']);
        self::assertNull($decision['location_sha256']);
        self::assertStringNotContainsString('PRIVATE', json_encode($decision, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string,string,string,string}> */
    public static function diagnosticFamilies(): iterable
    {
        yield 'same' => ['/next.pdf?PRIVATE', 'https', 'same_named_sandbox', 'admitted'];
        yield 'shared invoice' => ['https://invoice.airwallex.com/PRIVATE?token=PRIVATE',
            'https', 'shared_invoice', 'origin'];
        yield 'other sandbox' => ['https://api.sandbox.airwallex.com/PRIVATE', 'https', 'other_airwallex', 'origin'];
        yield 'other Airwallex' => ['https://static.airwallex.com/PRIVATE', 'https', 'other_airwallex', 'origin'];
        yield 'root namespace' => ['https://airwallex.com/PRIVATE', 'https', 'other_airwallex', 'origin'];
        yield 'other' => ['https://private.example.test/PRIVATE', 'https', 'other', 'origin'];
        yield 'prefix lookalike' => ['https://evilairwallex.com/PRIVATE', 'https', 'other', 'origin'];
        yield 'suffix lookalike' => ['https://airwallex.com.private.test/PRIVATE', 'https', 'other', 'origin'];
        yield 'invoice lookalike' => ['https://invoice.sandbox.airwallex.com.private.test/PRIVATE',
            'https', 'other', 'origin'];
        yield 'malformed label' => ['https://bad..airwallex.com/PRIVATE', 'https', 'unavailable', 'origin'];
        yield 'downgrade unchanged' => ['http://invoice.sandbox.airwallex.com/PRIVATE',
            'http', 'same_named_sandbox', 'origin'];
        yield 'other scheme' => ['ftp://invoice.airwallex.com/PRIVATE', 'other', 'shared_invoice', 'origin'];
        yield 'port remains denied' => ['https://invoice.sandbox.airwallex.com:444/PRIVATE',
            'https', 'same_named_sandbox', 'origin'];
        yield 'userinfo remains denied' => ['https://private@invoice.airwallex.com/PRIVATE',
            'https', 'shared_invoice', 'origin'];
        yield 'opaque scheme' => ['mailto:private@example.test', 'other', 'unavailable', 'origin'];
    }

    #[DataProvider('diagnosticFamilies')]
    public function testClosedDiagnosticNeverChangesTheExistingAdmissionDecision(
        string $location,
        string $scheme,
        string $family,
        string $policy,
    ): void {
        $decision = InvoicePdfRedirect::inspect(self::BASE, new Response(301, ['Location' => $location]));
        self::assertSame($policy, $decision['policy']);
        self::assertSame($scheme, $decision['target_scheme']);
        self::assertSame($family, $decision['target_origin_family']);
        self::assertSame($policy === 'admitted', $decision['target'] !== null);
        $diagnostic = ['scheme' => $decision['target_scheme'], 'origin_family' => $decision['target_origin_family']];
        $raw = json_encode($diagnostic, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE', 'private', '://', 'airwallex.com', 'token', '@', '444'] as $value) {
            self::assertStringNotContainsString($value, $raw);
        }
    }

    public function testUnambiguousResolutionIsRequiredBeforeAnyTargetDiagnostic(): void
    {
        foreach (
            [['Location' => ['/one', '/two']], ['Location' => '/PRIVATE%GG'],
            ['Location' => '/PRIVATE<normalization'], ['Location' => '/PRIVATE%0a'],
            ['Location' => '../PRIVATE'], ['Location' => '/PRIVATE#fragment'], []] as $headers
        ) {
            $decision = InvoicePdfRedirect::inspect(self::BASE, new Response(301, $headers));
            self::assertSame('unavailable', $decision['target_scheme']);
            self::assertSame('unavailable', $decision['target_origin_family']);
            self::assertNull($decision['target']);
        }
    }

    public function testBaseDotSegmentsAreNotNormalizedDuringRelativeResolution(): void
    {
        $decision = InvoicePdfRedirect::inspect(
            InvoiceAccessPolicy::ORIGIN . '/a/../initial?cap=PRIVATE',
            new Response(301, ['Location' => 'next.pdf'])
        );
        self::assertSame('dot_segments', $decision['policy']);
        self::assertNull($decision['target']);
    }

    public function testResolutionCannotExceedTheFinalUriBoundOrMisclassifyItAsAForeignOrigin(): void
    {
        $base = InvoiceAccessPolicy::ORIGIN . '/'
            . str_repeat('a', 32768 - strlen(InvoiceAccessPolicy::ORIGIN) - 30) . '/initial.pdf';
        self::assertSame('admitted', InvoiceAccessPolicy::pdfPolicy($base));
        $decision = InvoicePdfRedirect::inspect(
            $base,
            new Response(301, ['Location' => str_repeat('b', 100) . '.pdf'])
        );
        self::assertSame('oversize', $decision['policy']);
        self::assertNull($decision['target']);
    }

    /** @return iterable<string,array{int,bool}> */
    public static function statuses(): iterable
    {
        foreach ([200, 201, 300, 301, 302, 303, 304, 305, 307, 308, 400, 500] as $status) {
            yield (string) $status => [$status, in_array($status, [301, 302, 303, 307, 308], true)];
        }
    }

    #[DataProvider('statuses')]
    public function testOnlySelectedGetRedirectStatusesCanSelectAnotherRead(int $status, bool $admitted): void
    {
        $decision = InvoicePdfRedirect::inspect(self::BASE, new Response($status, ['Location' => '/next.pdf']));
        self::assertSame($admitted ? 'admitted' : 'not_redirect', $decision['policy']);
        self::assertSame($admitted ? InvoiceAccessPolicy::ORIGIN . '/next.pdf' : null, $decision['target']);
    }
}
