<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Internal\Auth\TokenProvider;
use Jojeringnax\Airwallex\Internal\Http\HttpExchange;
use Jojeringnax\Airwallex\Internal\Http\ResponseDecoder;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\GuardedTransport;
use RuntimeException;
use SensitiveParameter;

/** Every live integration call goes through the fixed sandbox host guard. */
final readonly class SandboxSession
{
    public Client $client;
    private GuardedTransport $transport;
    private HttpFactory $factory;
    private Closure $clock;

    public function __construct(
        #[SensitiveParameter] private Configuration $configuration,
        private string $context,
        ?ClientInterface $httpClient = null,
        ?Closure $clock = null,
    ) {
        if (!in_array($context, ['organization', 'account'], true)) {
            throw new RuntimeException('Unsupported sandbox account context.');
        }
        $this->factory = new HttpFactory();
        $this->clock = $clock ?? static fn (): int => time();
        $this->transport = new GuardedTransport($httpClient ?? new \GuzzleHttp\Client([
            'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
            'timeout' => 30, 'connect_timeout' => 10,
        ]));
        $this->client = new Client(
            environment: Environment::Sandbox,
            credentials: $configuration->credentials(),
            httpClient: $this->transport,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            loginAccountId: $context === 'account' ? $configuration->accountId() : null,
            retryPolicy: new RetryPolicy(maxAttempts: 1),
            clock: $this->clock,
        );
    }

    /**
     * Supporting configuration lookup, not an SDK Account resource or onboarding operation.
     * API 2026-08-21 GET /api/v1/account, account-level, platform_account:read, OBO false.
     * @see https://www.airwallex.com/docs/api/scale/accounts/retrieve
     */
    public function legalEntity(): ?string
    {
        $exchange = new HttpExchange(Environment::Sandbox, $this->transport, $this->factory, $this->factory);
        $decoder = new ResponseDecoder();
        $tokens = new TokenProvider(
            $exchange,
            $decoder,
            $this->configuration->credentials(),
            Environment::Sandbox,
            $this->configuration->accountId(),
            null,
            null,
            static fn (): int => time(),
        );
        $data = $decoder->decode($exchange->send('GET', '/api/v1/account', [
            'Authorization' => 'Bearer ' . $tokens->token(), 'x-api-version' => '2026-08-21',
            'Accept' => 'application/json',
        ]), sensitiveValues: $tokens->sensitiveValues());
        if (($data['id'] ?? null) !== $this->configuration->accountId()) {
            throw new RuntimeException('Account configuration lookup did not match the selected account.');
        }
        $details = $data['account_details'] ?? null;
        $id = is_array($details) ? ($details['legal_entity_id'] ?? null) : null;
        return is_string($id) && $id !== '' ? $id : null;
    }

    public function isOrganization(): bool
    {
        return $this->context === 'organization';
    }

    public function contextFingerprint(): string
    {
        return hash('sha256', json_encode([
            'environment' => Environment::Sandbox->value,
            'credentials' => $this->configuration->credentials()->fingerprint(),
            'context' => $this->context,
            'login_account' => $this->context === 'account' ? $this->configuration->accountId() : null,
        ], JSON_THROW_ON_ERROR));
    }

    public function loginRequests(): int
    {
        return $this->transport->loginRequests;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['context' => $this->context, 'configuration' => '[redacted]'];
    }
}
