<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\ValidationException;
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
    private ?TokenProvider $simulationTokens;
    private GuardedTransport $simulationTransport;

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
        $this->simulationTransport = new GuardedTransport(new SimulationTransport($httpClient));
        $this->simulationTokens = $context === 'account' ? new TokenProvider(
            new HttpExchange(Environment::Sandbox, $this->simulationTransport, $this->factory, $this->factory),
            new ResponseDecoder(),
            $configuration->credentials(),
            Environment::Sandbox,
            $configuration->accountId(),
            null,
            null,
            $this->clock,
        ) : null;
    }

    /**
     * Test-only API 2026-08-21 support: simulation:write, account level, selected metadata OBO=false.
     * Product prose describes OBO support; this harness uses explicit account login while that conflict remains.
     * @see https://www.airwallex.com/docs/api/simulation/payments
     */
    public function simulateShopperAction(string $action, #[SensitiveParameter] string $url): void
    {
        $parts = parse_url($url);
        if (
            $this->simulationTokens === null || !in_array($action, ['pay', 'reject', 'verify'], true)
            || !is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !in_array($parts['host'] ?? null, [
                'api.sandbox.airwallex.com', 'checkout.sandbox.airwallex.com',
            ], true)
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
        ) {
            throw new ValidationException('Shopper simulation requires an account-owned sandbox redirect.');
        }
        // Keep bounded response-protocol failures distinguishable from transport failures for existing callers.
        $request = $this->factory->createRequest('POST', Environment::Sandbox->baseUrl()
            . '/api/v1/simulation/pa/shopper_actions/' . $action);
        foreach (
            [
            'Authorization' => 'Bearer ' . $this->simulationTokens->token(),
            'x-api-version' => '2026-08-21', 'Content-Type' => 'application/json',
            ] as $name => $value
        ) {
            $request = $request->withHeader($name, $value);
        }
        $request = $request->withBody($this->factory->createStream(json_encode(['url' => $url], JSON_THROW_ON_ERROR)));
        $response = $this->simulationTransport->sendRequest($request);
        if ($response->getStatusCode() === 200) {
            try {
                $body = (string) $response->getBody();
            } catch (\Throwable) {
                throw new ProtocolException('Shopper simulation response could not be read.');
            }
            if ($body === '') {
                return;
            }
        }
        if ($response->getStatusCode() >= 400) {
            $sensitive = $this->simulationTokens->sensitiveValues();
            if ($response->getStatusCode() === 401) {
                $this->simulationTokens->invalidate();
            }
            (new ResponseDecoder())->decode(
                $response,
                sensitiveValues: [...$sensitive, $url],
            );
        }
        throw new ProtocolException('Shopper simulation did not return the documented empty success.');
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
        return $this->transport->loginRequests + $this->simulationTransport->loginRequests;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['context' => $this->context, 'configuration' => '[redacted]'];
    }
}
