<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Internal\Http;

/** @internal Operation schemas and resource paths are supplied by the resource layer. */
final readonly class Operation
{
    /**
     * @param list<string> $requestDecimalPaths
     * @param list<string> $responseDecimalPaths
     * @param list<string> $responseIntegerPaths
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $family,
        public array $requestDecimalPaths = [],
        public array $responseDecimalPaths = [],
        public array $responseIntegerPaths = [],
        public ?string $pagination = null,
    ) {
    }
}
