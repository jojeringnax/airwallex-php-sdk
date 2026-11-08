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
    }
}
