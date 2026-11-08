<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use GuzzleHttp\Psr7\HttpFactory;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Internal\Auth\TokenProvider;
use Jojeringnax\Airwallex\Internal\Http\HttpExchange;
use Jojeringnax\Airwallex\Internal\Http\ResponseDecoder;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use RuntimeException;
use SensitiveParameter;
use stdClass;

/** Test-only management requests, with public SDK methods for the own unconfirmed Intent. */
final class WebhookDeliveryApi
{
    public readonly Client $client;
    private readonly HttpExchange $exchange;
    private readonly ResponseDecoder $decoder;
    private readonly TokenProvider $tokens;

    public function __construct(
        #[SensitiveParameter] Configuration $configuration,
        private readonly WebhookDeliveryTransport $transport,
    ) {
        $factory = new HttpFactory();
        $this->exchange = new HttpExchange(Environment::Sandbox, $transport, $factory, $factory);
        $this->decoder = new ResponseDecoder();
        $this->tokens = new TokenProvider(
            $this->exchange,
            $this->decoder,
            $configuration->credentials(),
            Environment::Sandbox,
            $configuration->accountId(),
            null,
            null,
            static fn (): int => time()
        );
        $this->client = new Client(
            Environment::Sandbox,
            $configuration->credentials(),
            $transport,
            $factory,
            $factory,
            loginAccountId: $configuration->accountId(),
            retryPolicy: new RetryPolicy(maxAttempts: 1)
        );
    }

    public function authenticate(): void
    {
        $this->tokens->token();
    }

    /** @return array{id:string,url:string,secret:string,version:string,events:list<string>,request_id:string} */
    public function createWebhook(#[SensitiveParameter] string $url, string $requestId): array
    {
        if (!self::destination($url)) {
            throw new ProtocolException('The delivery destination is outside its generated temporary contract.');
        }
        return self::registration($this->management(
            'POST',
            '/api/v1/webhooks/create',
            201,
            ['url' => $url, 'version' => '2026-08-21',
            'events' => ['payment_intent.created',
            'payment_intent.cancelled'],
            'request_id' => $requestId]
        ));
    }

    /** @return array{id:string,url:string,secret:string,version:string,events:list<string>,request_id:string} */
    public function retrieveWebhook(#[SensitiveParameter] string $id): array
    {
        return self::registration($this->management('GET', self::path($id), 200));
    }

    public function deleteWebhook(#[SensitiveParameter] string $id): bool
    {
        $value = $this->management('POST', self::path($id) . '/delete', 200);
        return ($value->id ?? null) === $id && ($value->deleted ?? null) === true;
    }

    public function webhookGone(#[SensitiveParameter] string $id): bool
    {
        $response = $this->exchange->send('GET', self::path($id), $this->headers());
        return $response->getStatusCode() === 404;
    }

    private static function path(#[SensitiveParameter] string $id): string
    {
        if (!WebhookDeliveryTransport::validIdentity($id)) {
            throw new ProtocolException('Webhook identity is outside the local resource-path policy.');
        }
        return '/api/v1/webhooks/' . rawurlencode($id);
    }

    /** @param array<string,mixed>|null $body */
    private function management(
        string $method,
        #[SensitiveParameter] string $path,
        int $status,
        #[SensitiveParameter] ?array $body = null,
    ): stdClass {
        $response = $this->exchange->send(
            $method,
            $path,
            $this->headers(),
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)
        );
        if ($response->getStatusCode() !== $status) {
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                $this->decoder->decode($response, sensitiveValues: $this->tokens->sensitiveValues());
            }
            throw new ProtocolException('Webhook management returned an unexpected response status.');
        }
        $value = json_decode((string) $response->getBody(), depth: 64, flags: JSON_THROW_ON_ERROR);
        if (!$value instanceof stdClass) {
            throw new ProtocolException('Webhook management did not return its expected object.');
        }
        return $value;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->tokens->token(), 'Accept' => 'application/json',
            'Content-Type' => 'application/json', 'x-api-version' => '2026-08-21'];
    }

    /** @return array{id:string,url:string,secret:string,version:string,events:list<string>,request_id:string} */
    private static function registration(#[SensitiveParameter] stdClass $value): array
    {
        if (
            !is_array($value->events ?? null) || !array_is_list($value->events)
            || count($value->events) !== 2
        ) {
            throw new ProtocolException('Webhook event selection does not match its bounded contract.');
        }
        $events = [];
        foreach ($value->events as $event) {
            if (!is_string($event)) {
                throw new ProtocolException('Webhook event selection has an invalid representation.');
            }
            $events[] = $event;
        }
        sort($events, SORT_STRING);
        if ($events !== ['payment_intent.cancelled', 'payment_intent.created']) {
            throw new ProtocolException('Webhook event selection differs from the isolated experiment.');
        }
        return ['id' => self::field($value, 'id', 1024), 'url' => self::field($value, 'url', 8192),
            'secret' => self::field($value, 'secret', 8192), 'version' => self::field($value, 'version', 128),
            'events' => $events, 'request_id' => self::field($value, 'request_id', 50)];
    }

    private static function field(#[SensitiveParameter] stdClass $value, string $key, int $limit): string
    {
        $field = $value->{$key} ?? null;
        if (
            !is_string($field) || $field === '' || strlen($field) > $limit
            || ($key === 'id' && !WebhookDeliveryTransport::validIdentity($field))
        ) {
            throw new ProtocolException('Webhook registration fields are missing or exceed their private bounds.');
        }
        return $field;
    }

    /** @param array{id:string,url:string,secret:string,version:string,events:list<string>,request_id:string} $left
     * @param array{id:string,url:string,secret:string,version:string,events:list<string>,request_id:string} $right
     */
    public static function same(#[SensitiveParameter] array $left, #[SensitiveParameter] array $right): bool
    {
        return $left['id'] === $right['id'] && $left['url'] === $right['url']
            && $left['version'] === '2026-08-21' && $right['version'] === '2026-08-21'
            && $left['events'] === $right['events'] && $left['request_id'] === $right['request_id']
            && hash_equals($left['secret'], $right['secret']);
    }

    public static function destination(#[SensitiveParameter] string $url): bool
    {
        return strlen($url) <= 8192 && preg_match(
            '~^https://[a-z0-9]+(?:-[a-z0-9]+)*\.trycloudflare\.com/[a-f0-9]{64}$~D',
            $url
        ) === 1;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['management' => '[redacted]', 'client' => '[redacted]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Webhook delivery clients cannot be serialized.');
    }
}
