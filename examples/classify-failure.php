<?php

declare(strict_types=1);

use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ConfigurationException;
use Jojeringnax\Airwallex\Exception\SdkException;
use Jojeringnax\Airwallex\Exception\TransportException;
use Jojeringnax\Airwallex\Exception\ValidationException;

// Load the consumer's vendor/autoload.php before requiring this example.
// The result describes an application decision; it never retries a mutation.
return static function (SdkException $error): string {
    if ($error instanceof ValidationException) {
        return 'fix_local_arguments';
    }
    if ($error instanceof ConfigurationException) {
        return 'fix_configuration';
    }
    if ($error instanceof TransportException) {
        return 'reconcile_ambiguous_outcome';
    }
    if ($error instanceof ApiException) {
        return match ($error->httpStatus()) {
            400, 422 => 'fix_provider_request',
            401 => 'check_credentials',
            403 => 'check_permissions',
            429 => 'back_off_before_next_safe_operation',
            default => 'inspect_safe_http_status_and_code',
        };
    }
    return 'inspect_protocol_failure';
};
