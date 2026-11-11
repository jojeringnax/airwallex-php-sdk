<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

final class ResponseObjectsTest extends HttpTestCase
{
    private const string LOGIN = '{"token":"fixture-token","expires_at":"2030-01-01T00:00:00Z"}';

    /** @return iterable<string, array{string, bool, array<array-key, mixed>}> */
    public static function containers(): iterable
    {
        yield 'numeric object' => ['{"0":"zero","1":"one"}', true, ['zero', 'one']];
        yield 'list' => ['["zero","one"]', false, ['zero', 'one']];
        yield 'reordered object' => ['{"1":"one","0":"zero"}', true, [1 => 'one', 0 => 'zero']];
        yield 'named object' => ['{"name":"value"}', true, ['name' => 'value']];
        yield 'noncanonical numeric keys' => [
            '{"00":"zero","-1":"negative","9223372036854775808":"large"}',
            true,
            ['00' => 'zero', -1 => 'negative', '9223372036854775808' => 'large'],
        ];
        yield 'empty object' => ['{}', true, []];
        yield 'empty list' => ['[]', false, []];
        yield 'escaped and unusual keys' => [
            '{"\u0030":"zero","a/b~":"escaped","\u0000key":"nul","":"empty"}',
            true,
            [0 => 'zero', 'a/b~' => 'escaped', "\0key" => 'nul', '' => 'empty'],
        ];
    }

    /** @param array<array-key, mixed> $fields */
    #[DataProvider('containers')]
    public function testPublicResourceAndWebhookObjectModeKeepsContainerShape(
        string $json,
        bool $object,
        array $fields,
    ): void {
        $body = '{"metadata":' . $json . '}';
        $http = $this->scenario([[201, self::LOGIN], [200, $body], [200, $body]]);
        $client = $this->client($http, preserveResponseObjects: true);
        $event = WebhookEvent::fromJson('{"data":' . $body . '}')->toArray(preserveObjects: true);
        self::assertInstanceOf(stdClass::class, $event['data']);
        foreach (
            [$client->billing()->customers()->retrieve('customer')['metadata'],
                $client->payments()->customers()->retrieve('customer')['metadata'],
                $event['data']->metadata] as $value
        ) {
            if ($object) {
                self::assertInstanceOf(stdClass::class, $value);
                self::assertSame($fields, get_object_vars($value));
            } else {
                self::assertSame($fields, $value);
            }
        }
        self::assertCount(3, $http->requests); // One shared login and two reads.
    }

    public function testDefaultArrayApiRetainsItsExistingNumericKeyNormalization(): void
    {
        $object = '{"metadata":{"0":"zero"}}';
        $list = '{"metadata":["zero"]}';
        $http = $this->scenario([[201, self::LOGIN], [200, $object], [200, $list]]);
        $customers = $this->client($http)->billing()->customers();
        self::assertSame(['metadata' => ['zero']], $customers->retrieve('object'));
        self::assertSame(['metadata' => ['zero']], $customers->retrieve('list'));
        self::assertSame(['metadata' => ['zero']], WebhookEvent::fromJson($object)->toArray());
        self::assertSame(['metadata' => ['zero']], WebhookEvent::fromJson($list)->toArray());
    }

    public function testNestedResourceListsKeepPrecisionAndProviderPaging(): void
    {
        $body = '{"items":[{"id":"00123","days_until_due":3,"duration":{"period":2},'
            . '"metadata":{"0":{"nested":[{"1":"one"},{},[]]}},'
            . '"amount":12345678901234567890.9000,"future_count":9223372036854775808,'
            . '"quoted":"19.9000","exponent":1e+99,"nullable":null,"flag":false}],'
            . '"page_after":"opaque+bookmark","has_more":true}';
        $http = $this->scenario([[201, self::LOGIN], [200, $body]]);
        $result = $this->client($http, preserveResponseObjects: true)->billing()->subscriptions()->list();
        self::assertIsArray($result['items']);
        $item = $result['items'][0];
        self::assertInstanceOf(stdClass::class, $item);
        self::assertSame('00123', $item->id);
        self::assertSame(3, $item->days_until_due);
        self::assertEquals((object) ['period' => 2], $item->duration);
        self::assertEquals(
            (object) [0 => (object) ['nested' => [(object) [1 => 'one'], new stdClass(), []]]],
            $item->metadata
        );
        self::assertSame('12345678901234567890.9000', $item->amount);
        self::assertSame('9223372036854775808', $item->future_count);
        self::assertSame('19.9000', $item->quoted);
        self::assertSame('1e+99', $item->exponent);
        self::assertTrue(property_exists($item, 'nullable'));
        self::assertNull($item->nullable);
        self::assertFalse(property_exists($item, 'absent'));
        self::assertFalse($item->flag);
        self::assertSame('opaque+bookmark', $result['page_after']);
        self::assertTrue($result['has_more']);
    }

    public function testDelegatedPaymentsRetainObjectModeAndKnownIntegerPaths(): void
    {
        $body = '{"amount":19.9000,"order":{"products":[{"quantity":2,"metadata":{"0":"zero"}}]}}';
        $http = $this->scenario([[201, self::LOGIN], [200, $body]]);
        $client = $this->client($http, preserveResponseObjects: true)->forConnectedAccount('connected');
        $result = $client->payments()->paymentIntents()->retrieve('intent');
        self::assertSame('19.9000', $result['amount']);
        self::assertEquals((object) ['products' => [(object) [
            'quantity' => 2, 'metadata' => (object) [0 => 'zero'],
        ]]], $result['order']);
        self::assertSame('connected', $http->requests[1]->getHeaderLine('x-on-behalf-of'));
    }

    public function testObjectModeOnlyChangesMutationResponsesAndKeepsEmptyRootArray(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN], [200, '{"metadata":{"0":"zero"}}'], [200, '{}'],
        ]);
        $customers = $this->client($http, preserveResponseObjects: true)->billing()->customers();
        $result = $customers->update('customer', ['metadata' => (object) [0 => 'zero']]);
        self::assertEquals((object) [0 => 'zero'], $result['metadata']);
        self::assertSame('{"metadata":{"0":"zero"}}', (string) $http->requests[1]->getBody());
        self::assertSame([], $customers->retrieve('customer'));
        self::assertSame([], WebhookEvent::fromJson('{}')->toArray(preserveObjects: true));
    }

    public function testWebhookViewsAreIndependentAndRetainDirectAndGenericIntegerSelection(): void
    {
        $resource = '{"status":"CANCELLED","duration":{"period":0},"days_until_due":30,'
            . '"amount":19.9000,"metadata":{"0":[{},[],{"1":"one"}]},"nullable":null}';
        foreach ([$resource, '{"object":' . $resource . '}'] as $data) {
            $event = WebhookEvent::fromJson('{"id":"event","name":"subscription.cancelled",'
                . '"version":"2026-08-21","org_id":"org","created_at":"2026-11-11T00:00:00Z",'
                . '"data":' . $data . '}');
            $view = $event->toArray(preserveObjects: true);
            self::assertInstanceOf(stdClass::class, $view['data']);
            $object = property_exists($view['data'], 'object') ? $view['data']->object : $view['data'];
            self::assertInstanceOf(stdClass::class, $object);
            self::assertEquals((object) ['period' => 0], $object->duration);
            self::assertSame(30, $object->days_until_due);
            self::assertSame('19.9000', $object->amount);
            self::assertEquals((object) [0 => [new stdClass(), [], (object) [1 => 'one']]], $object->metadata);
            self::assertTrue(property_exists($object, 'nullable'));
            self::assertNull($object->nullable);
            self::assertFalse(property_exists($object, 'absent'));
            $original = $event->toArray();
            $originalObjects = $event->toArray(preserveObjects: true);
            self::assertInstanceOf(stdClass::class, $object->duration);
            self::assertInstanceOf(stdClass::class, $object->metadata);
            self::assertIsArray($object->metadata->{'0'});
            self::assertInstanceOf(stdClass::class, $object->metadata->{'0'}[0]);
            $object->duration->period = 999;
            $object->metadata->{'0'}[0]->added = 'local';
            self::assertEquals($original, $event->toArray());
            self::assertEquals($originalObjects, $event->toArray(preserveObjects: true));
        }
    }

    public function testObjectModeDoesNotAddTokenTypeOrDuplicateKeyProvenance(): void
    {
        $json = '{"numeric":123,"quoted":"123","metadata":{"0":"first","\u0030":"last"}}';
        $event = WebhookEvent::fromJson($json)->toArray(preserveObjects: true);
        $http = $this->scenario([[201, self::LOGIN], [200, $json]]);
        $resource = $this->client($http, preserveResponseObjects: true)->billing()->customers()->retrieve('customer');
        foreach ([$event, $resource] as $result) {
            self::assertSame('123', $result['numeric']);
            self::assertSame($result['numeric'], $result['quoted']);
            self::assertEquals((object) [0 => 'last'], $result['metadata']);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidJson(): iterable
    {
        foreach (
            ['[]', 'null', '{"metadata":{"0":"zero",}}', '{"x":"\uD800"}',
            '{"x":' . str_repeat('[', 130) . '0' . str_repeat(']', 130) . '}'] as $index => $json
        ) {
            yield (string) $index => [$json];
        }
    }

    #[DataProvider('invalidJson')]
    public function testObjectModeRetainsResourceProtocolFailures(string $json): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, $json]]);
        $this->expectException(ProtocolException::class);
        $this->client($http, preserveResponseObjects: true)->billing()->customers()->retrieve('customer');
    }

    public function testObjectModeRetainsSafeApiErrors(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [400, '{"code":"validation_error","message":"private"}']]);
        try {
            $this->client($http, preserveResponseObjects: true)->billing()->customers()->retrieve('customer');
            self::fail('Expected an API error.');
        } catch (ApiException $error) {
            self::assertSame(400, $error->httpStatus());
            self::assertSame('validation_error', $error->providerCode());
            self::assertStringNotContainsString('private', (string) $error);
        }
    }
}
