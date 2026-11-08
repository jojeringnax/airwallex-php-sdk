<?php

declare(strict_types=1);

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;

// Guzzle is one consumer-selected PSR implementation. Install it in the consuming application's dependencies.
// Obtain Credentials from your application's secret configuration; the SDK does not read environment files.
return static function (Environment $environment, Credentials $credentials, ?string $loginAccountId = null): Client {
    $http = new HttpClient([
        'verify' => true,
        'allow_redirects' => false,
        'connect_timeout' => 5,
        'timeout' => 15,
    ]);
    $factory = new HttpFactory();
    return new Client(
        environment: $environment,
        credentials: $credentials,
        httpClient: $http,
        requestFactory: $factory,
        streamFactory: $factory,
        loginAccountId: $loginAccountId,
    );
};
