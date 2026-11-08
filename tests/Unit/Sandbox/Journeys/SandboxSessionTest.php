<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession;
use PHPUnit\Framework\TestCase;

final class SandboxSessionTest extends TestCase
{
    public function testFingerprintSeparatesCredentialsContextAndApplicableAccountWithoutExposingValues(): void
    {
        $paths = [];
        try {
            $fingerprints = [];
            $contexts = [
                ['client-A', 'key-A', 'account-A', 'organization'],
                ['client-A', 'key-A', 'account-A', 'account'],
                ['client-A', 'key-A', 'account-B', 'account'],
                ['client-A', 'key-B', 'account-A', 'account'],
                ['client-B', 'key-A', 'account-A', 'account'],
            ];
            foreach ($contexts as [$client, $key, $account, $context]) {
                $path = tempnam(sys_get_temp_dir(), 'airwallex-session-fixture-');
                self::assertNotFalse($path);
                $paths[] = $path;
                file_put_contents(
                    $path,
                    "AIRWALLEX_CLIENT_ID=" . $client . "\nAIRWALLEX_SANDBOX_API_KEY_MASTER=" . $key
                    . "\nAIRWALLEX_ACCOUNT_ID=" . $account . "\n",
                );
                $session = new SandboxSession(
                    Configuration::load('sandbox', $path),
                    $context,
                    new HttpClient(['handler' => new MockHandler([])]),
                );
                $fingerprint = $session->contextFingerprint();
                self::assertSame($fingerprint, $session->contextFingerprint());
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $fingerprint);
                $fingerprints[] = $fingerprint;
                foreach ([$client, $key, $account] as $privateValue) {
                    self::assertStringNotContainsString($privateValue, $fingerprint);
                }
            }
            self::assertCount(5, array_unique($fingerprints));
        } finally {
            foreach ($paths as $path) {
                unlink($path);
            }
        }
    }
}
