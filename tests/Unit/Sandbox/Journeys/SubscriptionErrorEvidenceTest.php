<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SubscriptionErrorEvidence;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SubscriptionErrorTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

final class SubscriptionErrorEvidenceTest extends TestCase
{
    public function testOnlyExactWhitelistedCodesAndFieldMentionsAreReturned(): void
    {
        $raw = '{"code":"validation_error","source":"duration.period","message":'
            . '"private customer fixture-private-id has trial_ends_at and days_until_due values fixture-secret",'
            . '"details":{"id":"another-private-id","url":"https://example.test/private-token"}}';
        $result = SubscriptionErrorEvidence::inspect(400, $raw);
        self::assertSame('validation_error', $result['provider_code']);
        self::assertSame(['days_until_due', 'duration', 'period', 'trial_ends_at'], $result['mentioned_fields']);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['fixture-private-id', 'fixture-secret', 'another-private-id', 'private-token'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        $unknown = SubscriptionErrorEvidence::inspect(400, '{"code":"fixture_private_identifier","message":"private"}');
        self::assertFalse($unknown['code_recognized']);
        self::assertNull($unknown['provider_code']);
        self::assertStringNotContainsString('fixture_private_identifier', json_encode($unknown, JSON_THROW_ON_ERROR));
    }

    public function testPassiveErrorInspectionRestoresTheOriginalResponseAndDoesNotExposeInnerTransport(): void
    {
        $raw = '{"code":"validation_error","message":"duration has a private fixture-token value"}';
        $response = new Response(400, [], $raw);
        $response->getBody()->seek(5);
        $inner = $this->createStub(ClientInterface::class);
        $inner->method('sendRequest')->willReturn($response);
        $transport = new SubscriptionErrorTransport($inner);
        $observed = $transport->sendRequest(new Request(
            'POST',
            'https://api.sandbox.airwallex.com/api/v1/billing/subscriptions/create',
        ));
        self::assertSame($response, $observed);
        self::assertSame(5, $observed->getBody()->tell());
        self::assertSame($raw, (string) $observed->getBody());
        self::assertSame(['duration'], $transport->evidence()['mentioned_fields']);
        self::assertStringNotContainsString('fixture-token', print_r($transport, true));
        $this->expectException(RuntimeException::class);
        serialize($transport);
    }

    public function testOversizedOrMalformedEvidenceDoesNotEchoItsContents(): void
    {
        foreach (['private malformed payload', str_repeat('private-token-', 30000)] as $raw) {
            $result = SubscriptionErrorEvidence::inspect(400, $raw);
            self::assertNull($result['provider_code']);
            self::assertSame([], $result['mentioned_fields']);
            self::assertStringNotContainsString('private-token', json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    public function testAnIndependentFreshCreateCannotReuseEarlierErrorEvidence(): void
    {
        $inner = $this->createStub(ClientInterface::class);
        $inner->method('sendRequest')->willReturn(
            new Response(400, [], '{"code":"validation_error","message":"duration"}'),
            new Response(201, [], '{"id":"fixture-created-subscription"}'),
        );
        $transport = new SubscriptionErrorTransport($inner);
        $url = 'https://api.sandbox.airwallex.com/api/v1/billing/subscriptions/create';
        $transport->sendRequest(new Request('POST', $url, [], '{"request_id":"fixture-first-action"}'));
        self::assertSame('validation_error', $transport->evidence()['provider_code']);
        $transport->sendRequest(new Request('POST', $url, [], '{"request_id":"fixture-different-action"}'));
        self::assertSame([], $transport->evidence());
    }
}
