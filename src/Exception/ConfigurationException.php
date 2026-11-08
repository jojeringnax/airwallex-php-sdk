<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Exception;

final class ConfigurationException extends \InvalidArgumentException implements SdkException
{
    use RedactedException;
}
