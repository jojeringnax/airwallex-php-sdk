<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Internal\Schema;

use SensitiveParameter;
use Jojeringnax\Airwallex\Internal\Http\Operation;

/** @internal Selected-version operation metadata; never accepts a caller URL or method. */
final class Endpoint
{
    /**
     * @param array<string, mixed> $endpoint
     * @param array<string, array<string, mixed>> $definitions
     */
    private function __construct(
        private readonly string $family,
        private readonly array $endpoint,
        private readonly array $definitions,
    ) {
    }

    public static function load(string $family, string $resource, string $operationId): self
    {
        if (preg_match('/^[a-z_]+$/D', $family) !== 1 || preg_match('/^[a-z_]+$/D', $resource) !== 1) {
            throw new \LogicException('Invalid internal contract name.');
        }
        $file = dirname(__DIR__, 3) . '/resources/contracts/' . $family . '/' . $resource . '.json';
        $json = file_get_contents($file);
        if ($json === false) {
            throw new \LogicException('Bundled operation contract is unavailable.');
        }
        /** @var array{version:string,endpoints:array<string,array<string,mixed>>,schemas:array<string,array<string,mixed>>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if ($data['version'] !== '2026-08-21' || !isset($data['endpoints'][$operationId])) {
            throw new \LogicException('Bundled operation contract is incompatible.');
        }
        return new self($family, $data['endpoints'][$operationId], $data['schemas']);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     */
    public function validateRequest(#[SensitiveParameter] ?array $body, #[SensitiveParameter] array $query = []): void
    {
        /** @var array{content?:array<string,array{schema?:array<string,mixed>}>,required?:bool} $request */
        $request = $this->endpoint['requestBody'] ?? [];
        $schema = $request['content']['application/json']['schema'] ?? null;
        if ($schema === null) {
            if ($body !== null) {
                Validator::fail();
            }
        } elseif ($body === null) {
            if (($request['required'] ?? false) === true) {
                Validator::fail();
            }
        } else {
            Validator::validate(
                $body,
                $schema,
                $this->definitions,
                ($this->endpoint['sdkClearable'] ?? false) === true,
                true,
            );
        }
        $properties = [];
        $required = [];
        /** @var list<array{in:string,name:string,required?:bool,schema:array<string,mixed>}> $parameters */
        $parameters = $this->endpoint['parameters'] ?? [];
        foreach ($parameters as $parameter) {
            if ($parameter['in'] === 'query') {
                $properties[$parameter['name']] = $parameter['schema'];
                if (($parameter['required'] ?? false) === true) {
                    $required[] = $parameter['name'];
                }
            }
        }
        Validator::validate(
            $query,
            ['type' => 'object', 'properties' => $properties, 'required' => $required],
            [],
            false,
            true,
        );
    }

    /**
     * @param array<string, string> $pathParameters
     */
    public function operation(#[SensitiveParameter] array $pathParameters = []): Operation
    {
        /** @var string $path */
        $path = $this->endpoint['apiPath'];
        foreach ($pathParameters as $name => $value) {
            if ($value === '' || $value === '.' || $value === '..' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                Validator::fail();
            }
            $marker = '{' . $name . '}';
            if (!str_contains($path, $marker)) {
                throw new \LogicException('Unexpected internal path parameter.');
            }
            $path = str_replace($marker, rawurlencode($value), $path);
        }
        if (str_contains($path, '{')) {
            throw new \LogicException('Missing internal path parameter.');
        }
        /** @var array{content?:array<string,array{schema?:array<string,mixed>}>} $request */
        $request = $this->endpoint['requestBody'] ?? [];
        $requestNumbers = $this->paths($request['content']['application/json']['schema'] ?? [], 'number');
        $responseNumbers = [];
        $responseIntegers = [];
        $pagination = ($this->endpoint['sdkPagination'] ?? false) === true ? $this->family : null;
        /** @var array<int|string,array{content?:array<string,array{schema?:array<string,mixed>}>}> $responses */
        $responses = $this->endpoint['responses'];
        foreach ($responses as $status => $response) {
            if (!str_starts_with((string) $status, '2')) {
                continue;
            }
            $schema = $response['content']['application/json']['schema'] ?? [];
            $responseNumbers = [...$responseNumbers, ...$this->paths($schema, 'number')];
            $responseIntegers = [...$responseIntegers, ...$this->paths($schema, 'integer')];
        }
        /** @var string $method */
        $method = $this->endpoint['method'];
        return new Operation(
            method: strtoupper($method),
            path: $path,
            family: $this->family,
            requestDecimalPaths: array_values(array_unique($requestNumbers)),
            responseDecimalPaths: array_values(array_unique($responseNumbers)),
            responseIntegerPaths: array_values(array_unique($responseIntegers)),
            pagination: $pagination,
        );
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $visited
     * @return list<string>
     */
    private function paths(array $schema, string $type, string $path = '', array $visited = []): array
    {
        $reference = $schema['$ref'] ?? null;
        if (is_string($reference)) {
            if (in_array($reference, $visited, true)) {
                return [];
            }
            $visited[] = $reference;
        }
        $schema = Validator::resolve($schema, $this->definitions);
        if (($schema['type'] ?? null) === $type) {
            return [$path];
        }
        $paths = [];
        /** @var array<string,array<string,mixed>> $properties */
        $properties = $schema['properties'] ?? [];
        foreach ($properties as $name => $child) {
            $key = str_replace(['~', '/'], ['~0', '~1'], $name);
            $paths = [...$paths, ...$this->paths($child, $type, $path . '/' . $key, $visited)];
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            /** @var array<string,mixed> $items */
            $items = $schema['items'];
            $paths = [...$paths, ...$this->paths($items, $type, $path . '/*', $visited)];
        }
        return $paths;
    }
}
