<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Exception;

final class TransportException extends \RuntimeException implements SdkException
{
    use RedactedException;
}
