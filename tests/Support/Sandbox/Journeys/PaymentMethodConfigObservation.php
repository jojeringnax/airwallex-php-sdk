<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Closure;
use GuzzleHttp\Psr7\HttpFactory;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Internal\Auth\TokenProvider;
use Jojeringnax\Airwallex\Internal\Http\HttpExchange;
use Jojeringnax\Airwallex\Internal\Http\ResponseDecoder;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration;
use SensitiveParameter;
use stdClass;
use Throwable;

/** A filtered first page is a configuration fact, never financial-use authority. */
final class PaymentMethodConfigObservation
{
    /** @return array<string,mixed> */
    public static function initial(): array
    {
        return ['scenario' => 'payment-method-config-alipayhk-recurring', 'context' => 'ACCOUNT',
            'api_version' => '2026-08-21', 'profile' => 'HK_HKD_recurring_active_page0_100',
            'result' => 'fail', 'phase' => 'configuration', 'private_snapshot_retained' => false,
            'first_page_only' => true, 'global_absence_proven' => false, 'account_owner_echo_verified' => false,
            'native_pci_proven' => false, 'verify_permission_proven' => false, 'eligibility_proven' => false,
            'financial_permission' => false, 'resource_mutations_sent' => 0];
    }

    public static function contextFingerprint(#[SensitiveParameter] Configuration $configuration): string
    {
        return hash('sha256', json_encode(['environment' => 'sandbox', 'context' => 'ACCOUNT',
            'credentials' => $configuration->credentials()->fingerprint(), 'account' => $configuration->accountId(),
            'scenario' => 'payment-method-config-alipayhk-recurring', 'api_version' => '2026-08-21',
            'target' => PaymentMethodConfigTransport::TARGET], JSON_THROW_ON_ERROR));
    }

    /**
     * @param Closure():void $gate Current source/credential/path proof, including after fsync.
     * @param (Closure():int)|null $unixClock Token-expiration seam only.
     * @return array<string,mixed>
     */
    public static function execute(
        #[SensitiveParameter] Configuration $configuration,
        RunLedger $ledger,
        PaymentMethodConfigTransport $transport,
        string $toolHash,
        Closure $gate,
        ?Closure $unixClock = null,
    ): array {
        $safe = self::initial();
        $context = self::contextFingerprint($configuration);
        try {
            $safe['phase'] = 'read_provenance';
            $ledger->assertContext($context);
            $gate();
            foreach (['login', 'page'] as $role) {
                $ledger->requestId('payment_method_config_' . $role, ['role' => $role,
                    'api_version' => '2026-08-21', 'profile' => $safe['profile'], 'tool_sha256' => $toolHash,
                    'target_sha256' => hash('sha256', $role === 'page' ? PaymentMethodConfigTransport::TARGET
                        : 'https://api.sandbox.airwallex.com/api/v1/authentication/login')], $context);
            }
            $gate();
            $transport->bind($ledger, $context, $gate);
            $factory = new HttpFactory();
            $exchange = new HttpExchange(Environment::Sandbox, $transport, $factory, $factory);
            $tokens = new TokenProvider(
                $exchange,
                new ResponseDecoder(),
                $configuration->credentials(),
                Environment::Sandbox,
                $configuration->accountId(),
                null,
                null,
                $unixClock ?? time(...)
            );
            $safe['phase'] = 'authentication';
            $token = $tokens->token();
            $safe['phase'] = 'configuration_read';
            $exchange->send(
                'GET',
                PaymentMethodConfigTransport::PATH . '?' . PaymentMethodConfigTransport::QUERY,
                ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json',
                'x-api-version' => '2026-08-21',
                'Content-Type' => 'application/json']
            );
            $raw = $transport->page();
            $safe['phase'] = 'page_proof';
            $facts = self::pageFacts(WebhookConfigObservation::nativeObject($raw));
            $gate();
            $transport->assertTime();
            $safe['phase'] = 'private_snapshot';
            $ledger->recordObservation('payment_method_config_snapshot', ['response_sha256' => hash('sha256', $raw),
                'private_json' => $raw], $context);
            $safe['private_snapshot_retained'] = true;
            $gate();
            $transport->assertTime();
            return array_replace(
                $safe,
                $facts,
                ['phase' => 'observed', 'tool_sha256' => $toolHash],
                $transport->report(),
                $ledger->evidence()
            );
        } catch (Throwable) {
            $transport->poison();
            return $safe + $transport->report();
        }
    }

    /** @return array<string,mixed> Closed facts only; input is the private native view. */
    public static function pageFacts(#[SensitiveParameter] stdClass $page): array
    {
        $facts = ['result' => 'pending', 'items_state' => 'absent', 'pagination_state' => 'absent',
            'advertisement_state' => 'unavailable'];
        foreach (['items', 'has_more'] as $name) {
            $state = $name === 'items' ? 'items_state' : 'pagination_state';
            if (!property_exists($page, $name)) {
                continue;
            }
            if ($page->{$name} === null) {
                $facts[$state] = 'null';
            } elseif ($name === 'items' && is_array($page->items) && array_is_list($page->items)) {
                $facts[$state] = 'array';
            } elseif ($name === 'has_more' && is_bool($page->has_more)) {
                $facts[$state] = 'boolean';
                $facts['has_more'] = $page->has_more;
            } else {
                throw new ProtocolException('A present page field has an unavailable native type.');
            }
        }
        if ($facts['items_state'] !== 'array') {
            return $facts;
        }
        $items = $page->items;
        if (!is_array($items) || count($items) > 100) {
            throw new ProtocolException('The fixed first page exceeded its item bound.');
        }
        $facts['returned_count'] = count($items);
        $counts = ['target_name_rows' => 0, 'qualifying_rows' => 0, 'observed_literal_hkd_rows' => 0,
            'observed_wildcard_rows' => 0, 'unknown_name_rows' => 0, 'incomplete_target_rows' => 0,
            'unknown_currency_qualifying_rows' => 0];
        foreach ($items as $item) {
            if (!$item instanceof stdClass) {
                throw new ProtocolException('A configuration row must be a native object.');
            }
            foreach (
                ['name' => 128, 'transaction_mode' => 32, 'active' => 0, 'flows' => 16,
                'transaction_currencies' => 256] as $key => $bound
            ) {
                self::field($item, $key, $bound);
            }
            if (!is_string($item->name ?? null)) {
                ++$counts['unknown_name_rows'];
                continue;
            }
            if ($item->name !== 'alipayhk') {
                continue;
            }
            ++$counts['target_name_rows'];
            if (
                !is_bool($item->active ?? null) || !is_string($item->transaction_mode ?? null)
                || !is_array($item->flows ?? null)
            ) {
                ++$counts['incomplete_target_rows'];
                continue;
            }
            if (
                $item->active !== true || $item->transaction_mode !== 'recurring'
                || !in_array('mobile_web', $item->flows, true)
            ) {
                continue;
            }
            ++$counts['qualifying_rows'];
            if (!is_array($item->transaction_currencies ?? null)) {
                ++$counts['unknown_currency_qualifying_rows'];
                continue;
            }
            $counts['observed_literal_hkd_rows'] += (int) in_array('HKD', $item->transaction_currencies, true);
            $counts['observed_wildcard_rows'] += (int) in_array('*', $item->transaction_currencies, true);
        }
        $facts['advertisement_state'] = $counts['qualifying_rows'] > 0 ? 'observed'
            : ($counts['unknown_name_rows'] + $counts['incomplete_target_rows'] > 0
                ? 'unavailable' : 'not_observed_on_page');
        $unknownCurrency = $counts['unknown_name_rows'] + $counts['incomplete_target_rows']
            + $counts['unknown_currency_qualifying_rows'];
        $facts['literal_hkd_state'] = $counts['observed_literal_hkd_rows'] > 0 ? 'observed'
            : ($counts['qualifying_rows'] === 0 || $unknownCurrency > 0
                ? 'unavailable' : 'not_observed_on_page');
        $facts['wildcard_state'] = $counts['observed_wildcard_rows'] > 0 ? 'observed'
            : ($counts['qualifying_rows'] === 0 || $unknownCurrency > 0
                ? 'unavailable' : 'not_observed_on_page');
        if ($facts['pagination_state'] === 'boolean') {
            $facts['result'] = 'pass';
            $facts['filtered_page_terminal'] = $facts['has_more'] === false;
        }
        return $facts + $counts;
    }

    private static function field(#[SensitiveParameter] stdClass $item, string $key, int $bound): void
    {
        $value = $item->{$key} ?? null;
        if ($value === null) {
            return;
        }
        if ($key === 'active' && is_bool($value)) {
            return;
        }
        if (in_array($key, ['name', 'transaction_mode'], true) && is_string($value) && strlen($value) <= $bound) {
            return;
        }
        if (
            in_array($key, ['flows', 'transaction_currencies'], true) && is_array($value)
            && array_is_list($value) && count($value) <= $bound
        ) {
            foreach ($value as $entry) {
                if (!is_string($entry) || strlen($entry) > 64) {
                    throw new ProtocolException('A configuration list entry has an unavailable native type or size.');
                }
            }
            return;
        }
        throw new ProtocolException('A configuration field has an unavailable native type or size.');
    }
}
