<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\InvoiceAccessPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class InvoiceAccessPolicyTest extends TestCase
{
    public function testOnlyOriginalNamedSandboxOpaqueCapabilitiesAreAccepted(): void
    {
        $pdf = InvoiceAccessPolicy::ORIGIN . '/billing/invoices/pdf?s=PRIVATE_OPAQUE%3D';
        self::assertSame($pdf, InvoiceAccessPolicy::pdf($pdf));
        self::assertNull(InvoiceAccessPolicy::hostedToken($pdf));
        self::assertSame('PRIVATE.OPAQUE.SIGNATURE', InvoiceAccessPolicy::hostedToken(
            InvoiceAccessPolicy::ORIGIN . '/pay?s=PRIVATE.OPAQUE.SIGNATURE'
        ));
        self::assertSame('PRIVATE_OPAQUE+', InvoiceAccessPolicy::hostedToken(
            InvoiceAccessPolicy::ORIGIN . '/pay?s=PRIVATE_OPAQUE%2B'
        ));
    }

    /** @return iterable<string,array{mixed,string}> */
    public static function pdfPolicies(): iterable
    {
        $prefix = InvoiceAccessPolicy::ORIGIN . '/billing/invoices/pdf';
        yield 'admitted exact' => [$prefix . '?s=PRIVATE%3D', 'admitted'];
        yield 'missing or null' => [null, 'absent_or_null'];
        yield 'wrong type' => [new stdClass(), 'wrong_type'];
        yield 'oversize' => [$prefix . '?s=' . str_repeat('a', 32769), 'oversize'];
        yield 'control' => [$prefix . "?s=PRIVATE\nVALUE", 'lexical'];
        yield 'fragment' => [$prefix . '?s=PRIVATE#PRIVATE', 'lexical'];
        yield 'backslash' => [$prefix . '?s=PRIVATE\\VALUE', 'lexical'];
        yield 'shared origin' => ['https://invoice.airwallex.com/billing/invoices/pdf?s=PRIVATE', 'origin'];
        yield 'origin lookalike' => [InvoiceAccessPolicy::ORIGIN . '.PRIVATE.test/billing/invoices/pdf?s=x', 'origin'];
        yield 'userinfo' => ['https://PRIVATE@invoice.sandbox.airwallex.com/billing/invoices/pdf?s=x', 'origin'];
        yield 'port' => [InvoiceAccessPolicy::ORIGIN . ':443/billing/invoices/pdf?s=x', 'origin'];
        yield 'relative' => ['/billing/invoices/pdf?s=PRIVATE', 'origin'];
        yield 'unknown path' => [InvoiceAccessPolicy::ORIGIN . '/PRIVATE_PATH?s=PRIVATE', 'path'];
        yield 'encoded path' => [InvoiceAccessPolicy::ORIGIN . '/billing/invoices/%70df?s=PRIVATE', 'path'];
        yield 'unknown query' => [$prefix . '?PRIVATE=PRIVATE', 'query'];
        yield 'encoded key' => [$prefix . '?%73=PRIVATE', 'query'];
        yield 'duplicate key' => [$prefix . '?s=PRIVATE&s=PRIVATE', 'query'];
        yield 'literal plus' => [$prefix . '?s=PRIVATE+PRIVATE', 'query'];
        yield 'missing token' => [$prefix . '?s=', 'capability'];
        yield 'malformed escape' => [$prefix . '?s=PRIVATE%ZZ', 'capability'];
        yield 'encoded control' => [$prefix . '?s=PRIVATE%00', 'capability'];
        yield 'capability size' => [$prefix . '?s=' . str_repeat('a', 8193), 'capability'];
    }

    #[DataProvider('pdfPolicies')]
    public function testClosedPdfPolicyCategoriesNeverChangeAdmissionOrExposeComponents(
        mixed $value,
        string $category,
    ): void {
        self::assertSame($category, InvoiceAccessPolicy::pdfPolicy($value));
        self::assertSame($category === 'admitted', InvoiceAccessPolicy::pdf($value) !== null);
        self::assertStringNotContainsString('PRIVATE', $category);
    }

    /** @return iterable<string,array{mixed}> */
    public static function withheld(): iterable
    {
        foreach (
            ['https://invoice.airwallex.com/pay?s=PRIVATE',
            'https://invoice.sandbox.airwallex.com.example.test/pay?s=PRIVATE',
            'https://PRIVATE@invoice.sandbox.airwallex.com/pay?s=PRIVATE',
            'https://invoice.sandbox.airwallex.com:443/pay?s=PRIVATE',
            'http://invoice.sandbox.airwallex.com/pay?s=PRIVATE',
            'https://invoice.sandbox.airwallex.com/%70ay?s=PRIVATE',
            'https://invoice.sandbox.airwallex.com/pay?s=PRIVATE#',
            'https://invoice.sandbox.airwallex.com/pay?s=PRIVATE&unknown=PRIVATE',
            'https://invoice.sandbox.airwallex.com/pay?s=PRIVATE&s=PRIVATE',
            'https://invoice.sandbox.airwallex.com/pay?%73=PRIVATE',
            'https://invoice.sandbox.airwallex.com/pay?s=PRIVATE+VALUE',
            'https://invoice.sandbox.airwallex.com/pay?s=PRIVATE%0aVALUE',
            'https://invoice.sandbox.airwallex.com/pay?s=PRIVATE%ZZ',
            'https://invoice.sandbox.airwallex.com/pay?s=PRIVATE;other=VALUE',
            'https://invoice.sandbox.airwallex.com/pay?s=',
            'https://third-party.test/PRIVATE.pdf', '//invoice.sandbox.airwallex.com/pay?s=PRIVATE',
            "https://invoice.sandbox.airwallex.com/pay?s=PRIVATE\nVALUE",
            'https://invoice.sandbox.airwallex.com/pay?s=PRIVATE\\VALUE',
            InvoiceAccessPolicy::ORIGIN . '/pay?s=' . str_repeat('a', 8193),
            null, 1, new stdClass()] as $index => $value
        ) {
            yield 'shape ' . $index => [$value];
        }
    }

    #[DataProvider('withheld')]
    public function testUnknownShapesStayWithheldWithoutRewritingOrExpiryInference(mixed $value): void
    {
        self::assertNull(InvoiceAccessPolicy::hostedToken($value));
        if (is_string($value)) {
            $value = str_replace('/pay?', '/billing/invoices/pdf?', $value);
        }
        self::assertNull(InvoiceAccessPolicy::pdf($value));
        self::assertNotSame('admitted', InvoiceAccessPolicy::pdfPolicy($value));
    }
}
