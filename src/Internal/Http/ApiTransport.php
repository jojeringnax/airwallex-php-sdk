<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Internal\Http;

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Exception\TransportException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\Internal\Auth\TokenProvider;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Jojeringnax\Airwallex\RetryPolicy;
use SensitiveParameter;

/** @internal */
final readonly class ApiTransport
{
    public function __construct(
        private HttpExchange $exchange,
        private TokenProvider $tokens,
        private ResponseDecoder $decoder,
        private RetryPolicy $retryPolicy,
        #[SensitiveParameter] private ?string $delegatedAccountId,
        private JsonCodec $codec = new JsonCodec(),
    ) {
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['transport' => '[redacted]', 'authentication' => '[redacted]', 'accountContext' => '[redacted]'];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new ConfigurationException('Configured API transports cannot be serialized.');
    }

    /**
     * @param array<array-key, mixed>|null $body
     * @param array<array-key, mixed> $query
     * @return array<array-key, mixed>
     */
    public function request(
        Operation $operation,
        #[SensitiveParameter] ?array $body = null,
        #[SensitiveParameter] array $query = [],
    ): array {
        if (!in_array($operation->family, ['billing', 'payments'], true)) {
            throw new ConfigurationException('Unsupported API family.');
        }
        if ($operation->family === 'billing' && $this->delegatedAccountId !== null) {
            throw new ConfigurationException('Billing does not support connected-account delegation.');
        }
        if (
            !in_array($operation->method, ['GET', 'POST'], true)
            || !str_starts_with($operation->path, '/api/v1/')
            || preg_match('/[\x00-\x20\x7f?#\\\\]/', $operation->path) === 1
            || str_contains($operation->path, '//')
            || preg_match('~/(?:\.|\.\.)(?:/|$)~', $operation->path) === 1
        ) {
            throw new ConfigurationException('Invalid internal API operation.');
        }
        $path = $operation->path . $this->queryString($query, $operation->pagination);
        $encoded = $body === null ? null : $this->codec->encode($body, $operation->requestDecimalPaths);
        $sensitive = $body === null ? [] : $this->scalarValues($body);
        $sensitive = [...$sensitive, ...$this->scalarValues($query)];
        $authRefreshed = false;
        for ($attempt = 1;; $attempt++) {
            $token = $this->tokens->token();
            $headers = [
                'Authorization' => 'Bearer ' . $token,
                'x-api-version' => '2026-08-21',
                'Accept' => 'application/json',
            ];
            if ($encoded !== null) {
                $headers['Content-Type'] = 'application/json';
            }
            if ($this->delegatedAccountId !== null) {
                $headers['x-on-behalf-of'] = $this->delegatedAccountId;
            }
            try {
                $response = $this->exchange->send($operation->method, $path, $headers, $encoded);
                return $this->decoder->decode(
                    $response,
                    $operation->responseDecimalPaths,
                    $operation->responseIntegerPaths,
                    [...$sensitive, ...$this->tokens->sensitiveValues()],
                );
            } catch (ApiException $error) {
                if ($error->httpStatus() === 401) {
                    $this->tokens->invalidate();
                    if ($operation->method === 'GET' && !$authRefreshed && $attempt < $this->retryPolicy->maxAttempts) {
                        $authRefreshed = true;
                        continue;
                    }
                }
                if (
                    $operation->method !== 'GET' || $attempt >= $this->retryPolicy->maxAttempts
                    || !in_array($error->httpStatus(), [429, 500, 502, 503, 504], true)
                ) {
                    throw $error;
                }
            } catch (TransportException $error) {
                if ($operation->method !== 'GET' || $attempt >= $this->retryPolicy->maxAttempts) {
                    throw $error;
                }
            }
            $this->retryPolicy->wait($attempt);
        }
    }

    /** @param array<array-key, mixed> $query */
    private function queryString(#[SensitiveParameter] array $query, ?string $pagination): string
    {
        if ($pagination !== null && !in_array($pagination, ['billing', 'payments'], true)) {
            throw new ConfigurationException('Unsupported pagination contract.');
        }
        if (isset($query['page_size'])) {
            if (!is_int($query['page_size']) || $query['page_size'] < 1 || $query['page_size'] > 1000) {
                throw new ValidationException('page_size must be an integer from 1 to 1000.');
            }
        }
        if (
            array_key_exists('page_num', $query)
            && ($pagination !== 'payments' || !is_int($query['page_num']) || $query['page_num'] < 0)
        ) {
            throw new ValidationException('page_num must be a non-negative Payments page number.');
        }
        if (array_key_exists('page', $query) && ($pagination !== 'billing' || !is_string($query['page']))) {
            throw new ValidationException('page must be a Billing bookmark string.');
        }
        $parts = [];
        foreach ($query as $key => $value) {
            if (!is_string($key) || (!is_string($value) && !is_int($value) && !is_bool($value))) {
                throw new ValidationException('Query parameters must be named strings, integers or booleans.');
            }
            $encodedValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $parts[] = rawurlencode($key) . '=' . rawurlencode($encodedValue);
        }
        return $parts === [] ? '' : '?' . implode('&', $parts);
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private function scalarValues(#[SensitiveParameter] array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                $result = [...$result, ...$this->scalarValues($value)];
            } elseif (is_object($value)) {
                $result = [...$result, ...$this->scalarValues(get_object_vars($value))];
            } elseif (is_string($value)) {
                $result[] = $value;
            }
        }
        return $result;
    }
}
