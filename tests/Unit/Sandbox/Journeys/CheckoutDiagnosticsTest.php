<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\CheckoutDiagnostics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class CheckoutDiagnosticsTest extends TestCase
{
    private const string URL = 'https://api.sandbox.airwallex.com/api/v1/billing/billing_checkouts/create';
    private const string REQUEST = '{"mode":"PAYMENT","ui_mode":"HOSTED","metadata":{"PRIVATE_KEY":"PRIVATE_VALUE"}}';

    private function diagnostic(Response $response): CheckoutDiagnostics
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);
        return new CheckoutDiagnostics($client);
    }

    public function testExactPublicFieldsAndCodesPreserveBothStreamsAndSuppressOtherData(): void
    {
        $raw = '{"code":"validation_error","source":"line_items[0].price_id",'
            . '"message":"currency and ui_mode: PRIVATE_MESSAGE https://PRIVATE_HOST/PRIVATE_URL",'
            . '"details":{"PRIVATE_FIELD":"PRIVATE_VALUE"}}';
        $response = new Response(400, ['PRIVATE_HEADER' => 'PRIVATE_VALUE'], $raw);
        $response->getBody()->seek(5);
        $request = new Request('POST', self::URL, ['Authorization' => 'Bearer PRIVATE_TOKEN'], self::REQUEST);
        $request->getBody()->seek(7);
        $diagnostic = $this->diagnostic($response);
        self::assertSame($response, $diagnostic->sendRequest($request));
        self::assertSame(7, $request->getBody()->tell());
        self::assertSame(5, $response->getBody()->tell());
        self::assertSame(self::REQUEST, (string) $request->getBody());
        self::assertSame($raw, (string) $response->getBody());
        self::assertSame([
            'entries' => [[
                'case' => 'payment_hosted', 'http_status' => 400, 'public_code' => 'validation_error',
                'validation_fields' => ['currency', 'line_items.price_id', 'ui_mode'],
                'validation_fields_truncated' => false,
            ]], 'entries_dropped' => 0,
        ], $diagnostic->report());
        self::assertStringNotContainsString('PRIVATE', json_encode($diagnostic->report(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('PRIVATE', print_r($diagnostic, true));
    }

    public function testOpaqueCodesAndDynamicMetadataPathsCannotBecomeEvidence(): void
    {
        $diagnostic = $this->diagnostic(new Response(400, [], '{"code":"private_customer_identifier",'
            . '"source":"metadata.PRIVATE.currency","message":"metadata.PRIVATE.legal_entity_id",'
            . '"details":{"currency":"PRIVATE_VALUE"},"metadata":{"currency":"PRIVATE_VALUE"}}'));
        $diagnostic->sendRequest(new Request('POST', self::URL, [], self::REQUEST));
        self::assertSame('unlisted', $diagnostic->report()['entries'][0]['public_code']);
        self::assertSame([], $diagnostic->report()['entries'][0]['validation_fields']);
        self::assertStringNotContainsString('PRIVATE', json_encode($diagnostic->report(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(
            'private_customer',
            json_encode($diagnostic->report(), JSON_THROW_ON_ERROR)
        );
    }

    public function testPublicNestedFieldNamesCanBeMentionedWithoutAFullPath(): void
    {
        $diagnostic = $this->diagnostic(new Response(400, [], '{"code":"validation_error",'
            . '"message":"price_id and unit_amount are invalid; PRIVATE_VALUE"}'));
        $diagnostic->sendRequest(new Request('POST', self::URL, [], self::REQUEST));
        self::assertSame(['price_id', 'unit_amount'], $diagnostic->report()['entries'][0]['validation_fields']);
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function cases(): iterable
    {
        foreach (['SETUP', 'PAYMENT', 'SUBSCRIPTION'] as $mode) {
            foreach (['HOSTED', 'EMBEDDED', 'ELEMENTS'] as $ui) {
                yield $mode . '-' . $ui => [$mode, $ui, strtolower($mode . '_' . $ui)];
            }
        }
    }

    #[DataProvider('cases')]
    public function testOnlyKnownModeUiLabelsAreRetainedWithoutInspectingSuccessfulResponses(
        string $mode,
        string $ui,
        string $label,
    ): void {
        $stream = new NoSeekStream(Utils::streamFor('{"client_secret":"PRIVATE_SECRET","url":"PRIVATE_URL"}'));
        $response = new Response(201, [], $stream);
        $diagnostic = $this->diagnostic($response);
        $diagnostic->sendRequest(new Request('POST', self::URL, [], json_encode([
            'mode' => $mode, 'ui_mode' => $ui, 'billing_customer_id' => 'PRIVATE_CUSTOMER',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(0, $stream->tell());
        self::assertSame([
            'entries' => [['case' => $label, 'http_status' => 201]], 'entries_dropped' => 0,
        ], $diagnostic->report());
    }

    /** @return iterable<string,array{string}> */
    public static function unknownRequests(): iterable
    {
        yield 'opaque mode' => ['{"mode":"PRIVATE_CUSTOMER","ui_mode":"HOSTED"}'];
        yield 'opaque ui' => ['{"mode":"PAYMENT","ui_mode":"PRIVATE_URL"}'];
        yield 'missing ui' => ['{"mode":"PAYMENT"}'];
        yield 'malformed' => ['PRIVATE_INVALID_JSON'];
        yield 'not object' => ['[]'];
        yield 'oversized' => [json_encode([
            'mode' => 'PAYMENT', 'ui_mode' => 'HOSTED', 'metadata' => ['PRIVATE' => str_repeat('X', 66000)],
        ], JSON_THROW_ON_ERROR)];
    }

    #[DataProvider('unknownRequests')]
    public function testUnclassifiedRequestsDoNotExposeOrChangePrivateValues(string $body): void
    {
        $request = new Request('POST', self::URL, [], $body);
        $request->getBody()->seek(1);
        $diagnostic = $this->diagnostic(new Response(400, [], '{}'));
        $diagnostic->sendRequest($request);
        self::assertSame(1, $request->getBody()->tell());
        self::assertSame($body, (string) $request->getBody());
        self::assertSame('unclassified', $diagnostic->report()['entries'][0]['case']);
        self::assertStringNotContainsString('PRIVATE', json_encode($diagnostic->report(), JSON_THROW_ON_ERROR));
    }

    public function testNonCheckoutTrafficIsNeverObserved(): void
    {
        $stream = new NoSeekStream(Utils::streamFor('PRIVATE_TOKEN_AND_DATA'));
        $diagnostic = $this->diagnostic(new Response(400, [], $stream));
        foreach (
            ['authentication/login', 'account', 'billing/billing_customers/create',
            'billing/billing_checkouts/PRIVATE_ID/update'] as $path
        ) {
            $diagnostic->sendRequest(new Request('POST', 'https://api.sandbox.airwallex.com/api/v1/' . $path));
        }
        self::assertSame(0, $stream->tell());
        self::assertSame(['entries' => [], 'entries_dropped' => 0], $diagnostic->report());
    }

    public function testNonseekableAndOversizedErrorBodiesStayUnconsumed(): void
    {
        $streams = [new NoSeekStream(Utils::streamFor('PRIVATE_BODY')), Utils::streamFor(str_repeat('X', 66000))];
        foreach ($streams as $body) {
            $diagnostic = $this->diagnostic(new Response(400, [], $body));
            $diagnostic->sendRequest(new Request('POST', self::URL, [], self::REQUEST));
            self::assertSame(0, $body->tell());
            self::assertSame('unavailable_or_not_object', $diagnostic->report()['entries'][0]['body_observation']);
        }
    }

    public function testUnknownSizeReadsAndErrorTextAreBoundedAndPositionIsRestored(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('getSize')->willReturn(null);
        $offset = 11;
        $stream->method('tell')->willReturnCallback(static fn (): int => $offset);
        $stream->method('seek')->willReturnCallback(static function (int $value) use (&$offset): void {
            $offset = $value;
        });
        $stream->method('read')->willReturnCallback(static function (int $length): string {
            self::assertSame(65537, $length);
            return str_repeat('X', $length);
        });
        $diagnostic = $this->diagnostic(new Response(400, [], $stream));
        $diagnostic->sendRequest(new Request('POST', self::URL, [], self::REQUEST));
        self::assertSame(11, $offset);
        self::assertSame('unavailable_or_not_object', $diagnostic->report()['entries'][0]['body_observation']);
        $diagnostic = $this->diagnostic(new Response(400, [], json_encode([
            'code' => 'validation_error', 'message' => str_repeat('currency ', 1100),
        ], JSON_THROW_ON_ERROR)));
        $diagnostic->sendRequest(new Request('POST', self::URL, [], self::REQUEST));
        self::assertSame([], $diagnostic->report()['entries'][0]['validation_fields']);
    }

    public function testFieldEvidenceIsTruncatedAtItsFixedLimit(): void
    {
        $message = 'back_url billing_customer_id currency customer_data customer_data.email customer_data.name '
            . 'customer_data.type customer_data_collection customer_data_collection.billing_address '
            . 'customer_data_collection.tax_id discounts discounts.coupon discounts.coupon.id discounts.type '
            . 'enable_automatic_tax hosted_completion_page hosted_completion_page.display invoice_data '
            . 'invoice_data.days_until_due invoice_data.default_tax_percent invoice_data.due_at invoice_data.memo '
            . 'invoice_data.metadata legal_entity_id line_items line_items.discounts line_items.discounts.coupon '
            . 'line_items.discounts.coupon.id line_items.discounts.type line_items.price line_items.price.currency '
            . 'line_items.price.description line_items.price.flat_amount line_items.price.metadata '
            . 'line_items.price.pricing_model line_items.price.product line_items.price.product.description '
            . 'line_items.price.product.metadata line_items.price.product.name line_items.price.product.tax_code';
        $diagnostic = $this->diagnostic(new Response(400, [], json_encode([
            'code' => 'validation_error', 'message' => $message,
        ], JSON_THROW_ON_ERROR)));
        $diagnostic->sendRequest(new Request('POST', self::URL, [], self::REQUEST));
        $entry = $diagnostic->report()['entries'][0];
        self::assertIsArray($entry['validation_fields']);
        self::assertCount(32, $entry['validation_fields']);
        self::assertTrue($entry['validation_fields_truncated']);
    }

    public function testMalformedBodiesAndRecordCountAreBounded(): void
    {
        foreach (['PRIVATE_INVALID_JSON', '["PRIVATE"]', str_repeat('[', 40)] as $body) {
            $diagnostic = $this->diagnostic(new Response(400, [], $body));
            $diagnostic->sendRequest(new Request('POST', self::URL, [], self::REQUEST));
            self::assertSame('unavailable_or_not_object', $diagnostic->report()['entries'][0]['body_observation']);
        }
        $diagnostic = $this->diagnostic(new Response(400, [], '{}'));
        for ($index = 0; $index < 40; ++$index) {
            $diagnostic->sendRequest(new Request('POST', self::URL, [], self::REQUEST));
        }
        self::assertCount(32, $diagnostic->report()['entries']);
        self::assertSame(8, $diagnostic->report()['entries_dropped']);
        $this->expectException(RuntimeException::class);
        serialize($diagnostic);
    }

    public function testFailedRequestStreamRestorationCannotSendAlteredContentOrLeakFailureText(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('getSize')->willReturn(40);
        $stream->method('tell')->willReturn(5);
        $stream->method('read')->willReturn('{"mode":"PAYMENT","ui_mode":"HOSTED"}');
        $stream->method('seek')->willReturnCallback(static function (int $offset): void {
            if ($offset === 5) {
                throw new RuntimeException('PRIVATE_STREAM_FAILURE');
            }
        });
        $inner = $this->createMock(ClientInterface::class);
        $inner->expects(self::never())->method('sendRequest');
        $diagnostic = new CheckoutDiagnostics($inner);
        $this->expectExceptionMessage('Diagnostic inspection could not preserve the stream.');
        $diagnostic->sendRequest(new Request('POST', self::URL, [], $stream));
    }

    public function testTransportExceptionIsNotRetainedOrReplaced(): void
    {
        $error = new RuntimeException('PRIVATE_PROVIDER_MESSAGE');
        $inner = $this->createStub(ClientInterface::class);
        $inner->method('sendRequest')->willThrowException($error);
        $diagnostic = new CheckoutDiagnostics($inner);
        try {
            $diagnostic->sendRequest(new Request('POST', self::URL, [], self::REQUEST));
            self::fail('Expected the transport failure.');
        } catch (RuntimeException $observed) {
            self::assertSame($error, $observed);
        }
        self::assertSame([
            'entries' => [['case' => 'payment_hosted', 'transport' => 'exception']], 'entries_dropped' => 0,
        ], $diagnostic->report());
        self::assertStringNotContainsString('PRIVATE', print_r($diagnostic, true));
    }
}
