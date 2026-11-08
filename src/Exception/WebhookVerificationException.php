<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Exception;

final class WebhookVerificationException extends \RuntimeException implements SdkException
{
    use RedactedException;
}
