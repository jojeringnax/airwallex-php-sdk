<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\HttpFactory;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Internal\Auth\TokenProvider;
use Jojeringnax\Airwallex\Internal\Http\HttpExchange;
use Jojeringnax\Airwallex\Internal\Http\ResponseDecoder;
use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/** One account-owned configuration page, never a delivery or registration mutation. */
final class WebhookConfigObservation
{
    private const array EVENTS = [
        'subscription' => ['created', 'in_trial', 'active', 'unpaid', 'modified', 'cancelled'],
        'billing_checkout' => ['created', 'completed', 'cancelled'],
        'invoice' => ['created', 'finalized', 'updated', 'payment.paid', 'voided'],
        'payment_consent' => ['created', 'updated', 'pending', 'verified', 'disabled', 'paused',
            'requires_payment_method', 'requires_customer_action', 'verification_failed'],
        'payment_method' => ['created', 'updated', 'attached', 'detached', 'disabled'],
        'payment_intent' => ['cancelled', 'created', 'payment_failed', 'pending', 'pending_review',
            'requires_capture', 'requires_customer_action', 'requires_payment_method', 'succeeded', 'updated'],
        'payment_attempt' => ['authentication_failed', 'authentication_redirected', 'authorization_failed',
            'authorized', 'cancelled', 'capture_failed', 'capture_requested', 'expired', 'failed_to_process',
            'paid', 'pending_authorization', 'received', 'risk_declined', 'settled'],
        'refund' => ['accepted', 'failed', 'received', 'settled'],
    ];

    public static function contextFingerprint(#[SensitiveParameter] Configuration $configuration): string
    {
        return hash('sha256', json_encode(['environment' => 'sandbox',
            'credentials' => $configuration->credentials()->fingerprint(), 'context' => 'account',
            'login_account' => $configuration->accountId()], JSON_THROW_ON_ERROR));
    }

    /**
     * @param (Closure(resource):bool)|null $synchronize Private retention durability seam.
     * @return array<string,mixed>
     */
    public static function execute(
        #[SensitiveParameter] Configuration $configuration,
        RunLedger $ledger,
        string $directory,
        ?ClientInterface $http = null,
        ?Closure $synchronize = null,
    ): array {
        $safe = ['scenario' => 'webhook-config-observation', 'api_version' => '2026-08-21',
            'context' => 'account', 'result' => 'fail', 'phase' => 'read_provenance',
            'private_snapshot_retained' => false, 'configuration_read_succeeded' => false,
            'first_page_only' => true, 'global_absence_proven' => false,
            'organization_manager_verified' => false, 'raw_delivery_observed' => false,
            'signatures_verified' => false, 'receiver_followed' => false, 'mutation_sent' => false];
        $fingerprint = self::contextFingerprint($configuration);
        try {
            WebhookConfigTransport::assertAvailable();
            $ledger->assertContext($fingerprint);
            $folder = self::folder($directory, $ledger);
            $identity = @stat($folder);
            if ($identity === false) {
                throw new RuntimeException('Private observation directory identity is unavailable.');
            }
            $toolHash = hash_file('sha256', dirname(__DIR__, 4) . '/tools/webhook-config-observation.php');
            if ($toolHash === false) {
                throw new RuntimeException('Observation tool provenance is unavailable.');
            }
            $safe['tool_sha256'] = $toolHash;
            $ledger->requestId('webhook_configuration_read', ['method' => 'GET', 'path' => '/api/v1/webhooks',
                'api_version' => '2026-08-21', 'tool_sha256' => $toolHash], $fingerprint);
            $safe['phase'] = 'authentication';
            $transport = new WebhookConfigTransport($http);
            $factory = new HttpFactory();
            $exchange = new HttpExchange(Environment::Sandbox, $transport, $factory, $factory);
            $decoder = new ResponseDecoder();
            $tokens = new TokenProvider(
                $exchange,
                $decoder,
                $configuration->credentials(),
                Environment::Sandbox,
                $configuration->accountId(),
                null,
                null,
                static fn (): int => time()
            );
            $authorization = 'Bearer ' . $tokens->token();
            $safe['phase'] = 'configuration_read';
            $response = $exchange->send('GET', '/api/v1/webhooks', [
                'Authorization' => $authorization, 'x-api-version' => '2026-08-21', 'Accept' => 'application/json',
            ]);
            $safe['http_status'] = $response->getStatusCode();
            if ($response->getStatusCode() !== 200) {
                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    $decoder->decode($response, sensitiveValues: $tokens->sensitiveValues());
                }
                throw new ProtocolException('Webhook configuration returned an unexpected success status.');
            }
            // The transport has already bounded/closed the original stream; this is its bounded in-memory copy.
            $raw = (string) $response->getBody();
            (new JsonCodec())->decodeObject($raw); // Validate the bounded object before inspecting known string fields.
            $page = json_decode($raw, depth: 64, flags: JSON_THROW_ON_ERROR);
            self::uniqueObjectKeys($raw);
            if (
                !$page instanceof stdClass || !is_array($page->items ?? null)
                || !array_is_list($page->items) || count($page->items) > 20
            ) {
                throw new ProtocolException('The first configuration page does not match the bounded contract.');
            }
            $items = [];
            $identities = [];
            $counts = ['returned_count' => count($page->items), 'secret_present_count' => 0,
                'version_match_count' => 0, 'https_url_count' => 0, 'other_url_count' => 0,
                'known_billing_family_count' => 0, 'known_payments_family_count' => 0,
                'unrecognized_event_count' => 0];
            foreach ($page->items as $item) {
                $subset = self::item($item);
                if ($subset['id'] === '' || isset($identities[$subset['id']])) {
                    throw new ProtocolException('Configuration identities are missing or ambiguous.');
                }
                $identities[$subset['id']] = true;
                $items[] = $subset;
                $counts['secret_present_count'] += $subset['secret'] !== '' ? 1 : 0;
                $counts['version_match_count'] += $subset['version'] === '2026-08-21' ? 1 : 0;
                $https = self::https($subset['url']);
                ++$counts[$https ? 'https_url_count' : 'other_url_count'];
                $billing = false;
                $payments = false;
                foreach ($subset['events'] as $event) {
                    $family = self::family($event);
                    $billing = $billing || $family === 'billing';
                    $payments = $payments || $family === 'payments';
                    $counts['unrecognized_event_count'] += $family === 'unrecognized' ? 1 : 0;
                }
                $counts['known_billing_family_count'] += $billing ? 1 : 0;
                $counts['known_payments_family_count'] += $payments ? 1 : 0;
            }
            $pagination = ['page_after_state' => self::cursor($page, 'page_after'),
                'page_before_state' => self::cursor($page, 'page_before')];
            $safe += $counts + $pagination + ['no_records_on_page' => $items === [],
                'terminal_cursor_observed' => $pagination['page_after_state'] === 'null',
                'pagination_complete' => $pagination['page_after_state'] === 'null'
                    && $pagination['page_before_state'] === 'null'];
            $safe['configuration_read_succeeded'] = true;
            $safe['phase'] = 'private_retention';
            $snapshot = ['format' => 1, 'api_version' => '2026-08-21', 'method' => 'GET',
                'path' => '/api/v1/webhooks', 'tool_sha256' => $toolHash, 'items' => $items,
                'pagination' => $pagination] + $ledger->evidence();
            $hash = self::retain(
                $folder,
                ['dev' => $identity['dev'], 'ino' => $identity['ino']],
                $snapshot,
                $synchronize ?? self::synchronize(...)
            );
            $ledger->recordObservation('webhook_configuration_snapshot', ['sha256' => $hash], $fingerprint);
            $safe['private_snapshot_retained'] = true;
            $safe['phase'] = 'completed';
            $safe['result'] = 'pass';
        } catch (Throwable $error) {
            if ($error instanceof ApiException) {
                $safe['http_status'] = $error->httpStatus();
                $code = $error->providerCode();
                $safe['code'] = in_array($code, ['invalid_argument', 'unauthorized', 'credentials_invalid',
                    'credentials_expired'], true) ? $code : 'unlisted';
            }
            $safe['reason'] = 'observation_not_completed';
        }
        return $safe + $ledger->evidence();
    }

    /** Validate key uniqueness after JSON syntax/depth validation; never expose names or values. */
    private static function uniqueObjectKeys(#[SensitiveParameter] string $raw): void
    {
        $scopes = [];
        for ($offset = 0, $length = strlen($raw); $offset < $length;) {
            $character = $raw[$offset];
            if ($character === '{' || $character === '[') {
                $scopes[] = $character === '{' ? [] : null;
            } elseif ($character === '}' || $character === ']') {
                array_pop($scopes);
            } elseif ($character === '"') {
                if (preg_match('/\G"(?:[^"\\\\]|\\\\.)*"/s', $raw, $matches, 0, $offset) !== 1) {
                    throw new ProtocolException('Configuration object keys could not be safely observed.');
                }
                $offset += strlen($matches[0]);
                $next = $offset + strspn($raw, " \t\n\r", $offset);
                if (($raw[$next] ?? '') === ':') {
                    $key = json_decode($matches[0], flags: JSON_THROW_ON_ERROR);
                    $index = count($scopes) - 1;
                    if (!is_string($key) || !isset($scopes[$index]) || array_key_exists($key, $scopes[$index])) {
                        throw new ProtocolException('Configuration object keys are unavailable or ambiguous.');
                    }
                    $scopes[$index][$key] = true;
                }
                continue;
            }
            ++$offset;
        }
    }

    /** @return array{id:string,secret:string,url:string,version:string,events:list<string>} */
    private static function item(#[SensitiveParameter] mixed $item): array
    {
        if (
            !$item instanceof stdClass || !is_string($item->id ?? null) || strlen($item->id) > 1024
            || !is_string($item->secret ?? null) || strlen($item->secret) > 8192
            || !is_string($item->url ?? null) || strlen($item->url) > 8192
            || !is_string($item->version ?? null) || strlen($item->version) > 128
            || !is_array($item->events ?? null) || !array_is_list($item->events) || count($item->events) > 256
        ) {
            throw new ProtocolException('A configuration item has unavailable or oversized fields.');
        }
        $events = [];
        foreach ($item->events as $event) {
            if (!is_string($event) || strlen($event) > 256) {
                throw new ProtocolException('A configuration event does not match its bounded string contract.');
            }
            $events[] = $event;
        }
        return ['id' => $item->id, 'secret' => $item->secret, 'url' => $item->url,
            'version' => $item->version, 'events' => $events];
    }

    private static function https(#[SensitiveParameter] string $url): bool
    {
        if (strlen($url) > 8192 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== '';
    }

    private static function cursor(#[SensitiveParameter] stdClass $page, string $field): string
    {
        if (!property_exists($page, $field)) {
            return 'missing';
        }
        $value = $page->{$field};
        return $value === null ? 'null' : (is_string($value) ? ($value === '' ? 'empty' : 'nonempty') : 'invalid');
    }

    private static function family(#[SensitiveParameter] string $event): string
    {
        $parts = explode('.', $event, 2);
        if (!isset(self::EVENTS[$parts[0]]) || !in_array($parts[1] ?? '', self::EVENTS[$parts[0]], true)) {
            return 'unrecognized';
        }
        return in_array($parts[0], ['subscription', 'billing_checkout', 'invoice'], true) ? 'billing' : 'payments';
    }

    /** @param resource $stream */
    private static function synchronize($stream): bool
    {
        return @fsync($stream);
    }

    private static function folder(string $directory, RunLedger $ledger): string
    {
        $base = realpath($directory);
        $folder = $base === false ? false : realpath($base . '/' . $ledger->runId());
        if ($folder === false || is_link($base . '/' . $ledger->runId())) {
            throw new RuntimeException('Private observation directory is unavailable.');
        }
        self::privateDirectory($folder);
        return $folder;
    }

    private static function privateDirectory(string $folder): void
    {
        $permissions = @fileperms($folder);
        if (
            realpath($folder) !== $folder || !is_dir($folder) || is_link($folder)
            || $permissions === false || ($permissions & 0777) !== 0700
        ) {
            throw new RuntimeException('Private observation directory is unavailable.');
        }
        for ($ancestor = $folder;; $ancestor = dirname($ancestor)) {
            if (file_exists($ancestor . '/.git') || is_link($ancestor . '/.git')) {
                throw new RuntimeException('Private observations must remain outside Git checkouts.');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
    }

    /** @param array<string,mixed> $snapshot
     * @param array<string,int> $identity
     * @param Closure(resource):bool $synchronize
     */
    private static function retain(
        string $folder,
        array $identity,
        #[SensitiveParameter] array $snapshot,
        Closure $synchronize,
    ): string {
        self::privateDirectory($folder);
        $current = @stat($folder);
        if ($current === false || $current['dev'] !== $identity['dev'] || $current['ino'] !== $identity['ino']) {
            throw new RuntimeException('Private observation directory identity changed.');
        }
        $path = $folder . '/webhook-config.json';
        $temporary = $path . '.writing';
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('A private configuration observation cannot overwrite another snapshot.');
        }
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $stream = @fopen($temporary, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Private configuration retention is unavailable.');
        }
        try {
            if (
                !@chmod($temporary, 0600) || @fwrite($stream, $json) !== strlen($json)
                || !@fflush($stream) || $synchronize($stream) !== true
            ) {
                throw new RuntimeException('Private configuration retention could not be synchronized.');
            }
        } finally {
            fclose($stream);
        }
        if (!@link($temporary, $path) || !@unlink($temporary)) {
            throw new RuntimeException('Private configuration retention could not be finalized.');
        }
        $parent = @fopen($folder, 'r');
        if ($parent === false) {
            throw new RuntimeException('Private configuration directory could not be opened.');
        }
        try {
            if ($synchronize($parent) !== true) {
                throw new RuntimeException('Private configuration directory could not be synchronized.');
            }
        } finally {
            fclose($parent);
        }
        return hash('sha256', $json);
    }
}
