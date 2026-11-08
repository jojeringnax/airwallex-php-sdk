<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex;

use Closure;
use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Internal\Auth\TokenProvider;
use Jojeringnax\Airwallex\Internal\Http\ApiTransport;
use Jojeringnax\Airwallex\Internal\Http\HttpExchange;
use Jojeringnax\Airwallex\Internal\Http\ResponseDecoder;
use Jojeringnax\Airwallex\Resource\Billing;
use Jojeringnax\Airwallex\Resource\Payments;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use SensitiveParameter;

final class Client
{
    private ApiTransport $transport;
    private readonly Closure $clock;
    private readonly RetryPolicy $retryPolicy;
    private ?string $delegatedAccountId;

    public function __construct(
        private readonly Environment $environment,
        #[SensitiveParameter] private readonly Credentials $credentials,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        #[SensitiveParameter] private readonly ?string $loginAccountId = null,
        private readonly ?CacheInterface $tokenCache = null,
        ?Closure $clock = null,
        ?RetryPolicy $retryPolicy = null,
    ) {
        if ($loginAccountId !== null) {
            self::validateAccount($loginAccountId);
        }
        $this->delegatedAccountId = null;
        $this->clock = $clock ?? static fn (): int => time();
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();
        $this->transport = $this->createTransport();
    }

    public function forConnectedAccount(#[SensitiveParameter] string $id): self
    {
        self::validateAccount($id);
        if ($this->loginAccountId !== null) {
            throw new ConfigurationException(
                'Login account selection cannot be combined with connected-account delegation.',
            );
        }
        $scoped = clone $this;
        $scoped->delegatedAccountId = $id;
        $scoped->transport = $scoped->createTransport();
        return $scoped;
    }

    public function billing(): Billing
    {
        if ($this->delegatedAccountId !== null) {
            throw new ConfigurationException('Billing does not support connected-account delegation.');
        }
        return new Billing($this->transport);
    }

    public function payments(): Payments
    {
        return new Payments($this->transport);
    }

    private function createTransport(): ApiTransport
    {
        $exchange = new HttpExchange(
            $this->environment,
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory
        );
        $decoder = new ResponseDecoder();
        $tokens = new TokenProvider(
            $exchange,
            $decoder,
            $this->credentials,
            $this->environment,
            $this->loginAccountId,
            $this->delegatedAccountId,
            $this->tokenCache,
            $this->clock
        );
        return new ApiTransport($exchange, $tokens, $decoder, $this->retryPolicy, $this->delegatedAccountId);
    }

    private static function validateAccount(#[SensitiveParameter] string $id): void
    {
        if ($id === '' || preg_match('/[\x00-\x20\x7f]/', $id) === 1) {
            throw new ConfigurationException('Account identifiers must be nonempty header values without whitespace.');
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['environment' => $this->environment->value,
             'credentials' => '[redacted]',
             'accountContext' => '[redacted]'];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new ConfigurationException('Configured clients cannot be serialized.');
    }
}
