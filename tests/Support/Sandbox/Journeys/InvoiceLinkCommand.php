<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;

/** Fixed-root read-only command; no URL, resource identity, owner or mutation options. */
final class InvoiceLinkCommand
{
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function run(
        #[SensitiveParameter] array $options,
        ?ClientInterface $httpClient = null,
        ?Closure $clock = null,
        ?Closure $wait = null
    ): array {
        $result = ['scenario' => 'invoice-link-observation', 'api_version' => '2026-08-21', 'result' => 'fail',
            'mutation_sent' => false, 'link_followed' => false];
        foreach ($options as $key => $value) {
            if (
                !in_array($key, ['environment', 'credentials-file', 'prior-ledger'], true)
                || !is_string($value) || $value === ''
            ) {
                return $result;
            }
        }
        $credentials = $options['credentials-file'] ?? null;
        $path = $options['prior-ledger'] ?? null;
        if (($options['environment'] ?? null) !== 'sandbox' || !is_string($credentials) || !is_string($path)) {
            return $result;
        }
        try {
            $result['source'] = SourceEvidence::capture();
            $session = new SandboxSession(Configuration::load('sandbox', $credentials), 'organization', $httpClient);
            $result['context'] = 'organization';
            $result['context_fingerprint'] = $session->contextFingerprint();
            $observer = InvoiceLinkObservation::load($path, $session);
            $result = array_replace($result, $observer->inspect($session, $clock, $wait));
        } catch (\Throwable $error) {
            if ($error instanceof ApiException) {
                $result['http_status'] = $error->httpStatus();
            }
        }
        return $result;
    }
}
