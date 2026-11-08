<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox;

use Jojeringnax\Airwallex\Credentials;
use RuntimeException;
use SensitiveParameter;

/** Development harness configuration; values are data and are never evaluated. */
final readonly class Configuration
{
    /** @param array<string, string> $values */
    private function __construct(#[SensitiveParameter] private array $values)
    {
    }

    public static function load(string $environment, string $path): self
    {
        if ($environment !== 'sandbox') {
            throw new RuntimeException('The integration harness requires explicit sandbox configuration.');
        }
        if (!str_starts_with($path, '/') && preg_match('/^[a-zA-Z]:[\\\\\/]/D', $path) !== 1) {
            throw new RuntimeException('Credentials require an absolute regular local file.');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('The external credential file is unavailable or too large.');
        }
        $contents = @file_get_contents($resolved, length: 65537);
        if ($contents === false || strlen($contents) > 65536) {
            throw new RuntimeException('The external credential file is unavailable or too large.');
        }
        return new self(self::parse($contents));
    }

    /** @return array<string, string> */
    public static function parse(#[SensitiveParameter] string $contents): array
    {
        $values = [];
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/D', $line, $parts) !== 1) {
                throw new RuntimeException('Malformed external credential configuration.');
            }
            $name = $parts[1];
            if (array_key_exists($name, $values)) {
                throw new RuntimeException('Duplicate external configuration name.');
            }
            $value = $parts[2];
            if (str_starts_with($value, '"') || str_starts_with($value, "'")) {
                $quote = $value[0];
                $end = strpos($value, $quote, 1);
                if ($end === false || preg_match('/^\s*(?:#.*)?$/D', substr($value, $end + 1)) !== 1) {
                    throw new RuntimeException('Malformed quoted external configuration.');
                }
                $value = substr($value, 1, $end - 1);
            } else {
                $value = rtrim(preg_split('/\s+#/', $value, 2)[0] ?? '');
            }
            $values[$name] = $value;
        }
        return $values;
    }

    public function credentials(): Credentials
    {
        return new Credentials(
            clientId: $this->required('AIRWALLEX_CLIENT_ID'),
            apiKey: $this->required('AIRWALLEX_SANDBOX_API_KEY_MASTER'),
        );
    }

    public function accountId(): string
    {
        return $this->required('AIRWALLEX_ACCOUNT_ID');
    }

    private function required(string $name): string
    {
        $value = $this->values[$name] ?? '';
        if ($value === '') {
            throw new RuntimeException('Required external sandbox configuration is missing.');
        }
        return $value;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['configuration' => '[redacted]'];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Sandbox configuration cannot be serialized.');
    }
}
