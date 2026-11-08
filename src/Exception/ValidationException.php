<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Exception;

final class ValidationException extends \InvalidArgumentException implements SdkException
{
    use RedactedException;
}
