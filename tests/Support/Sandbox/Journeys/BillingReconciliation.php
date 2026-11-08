<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys;

use Jojeringnax\Airwallex\Exception\ProtocolException;
use RuntimeException;
use SensitiveParameter;

/** Read-only correlation of an uncertain create; never resumes or mutates an earlier run. */
final readonly class BillingReconciliation
{
    private function __construct(
        #[SensitiveParameter] private string $customer,
        #[SensitiveParameter] private string $request,
        #[SensitiveParameter] private string $marker,
        private string $contextFingerprint,
    ) {
    }

    /** Loads private state only when the operator executes the probe. */
    public static function load(#[SensitiveParameter] string $path, SandboxSession $session): self
    {
        if (!$session->isOrganization() || !str_starts_with($path, '/')) {
            throw new RuntimeException('Reconciliation requires organization context and a local absolute ledger.');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('Private reconciliation state is unavailable.');
        }
        $fileMode = fileperms($resolved);
        $directoryMode = fileperms(dirname($resolved));
        if (
            PHP_OS_FAMILY !== 'Windows'
            && ($fileMode === false || $directoryMode === false || ($fileMode & 0077) !== 0
                || ($directoryMode & 0077) !== 0)
        ) {
            throw new RuntimeException('Reconciliation state must retain private file and directory permissions.');
        }
        $raw = @file_get_contents($resolved, length: 1048577);
        if ($raw === false || strlen($raw) > 1048576) {
            throw new RuntimeException('Private reconciliation state is unavailable.');
        }
        $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (
            !is_array($state) || ($state['api_version'] ?? null) !== '2026-08-21'
            || !is_string($state['context_fingerprint'] ?? null)
            || !hash_equals($session->contextFingerprint(), $state['context_fingerprint'])
        ) {
            throw new RuntimeException('Reconciliation state does not match the selected version and owner.');
        }
        $resources = $state['resources'] ?? null;
        $customers = is_array($resources) ? ($resources['customers'] ?? null) : null;
        $actions = $state['actions'] ?? null;
        $action = is_array($actions) ? ($actions['create_future_trial'] ?? null) : null;
        $request = is_array($action) ? ($action['request_id'] ?? null) : null;
        $customer = is_array($customers) && count($customers) === 1 ? ($customers[0] ?? null) : null;
        $marker = $state['run_id'] ?? null;
        if (
            !is_string($customer) || $customer === '' || !is_string($request) || $request === ''
            || !is_string($marker) || preg_match('/^sdk-validation-[a-f0-9-]{36}$/D', $marker) !== 1
        ) {
            throw new RuntimeException('Reconciliation requires one recorded Customer and the original action.');
        }
        return new self($customer, $request, $marker, $session->contextFingerprint());
    }

    /** @return array<string,int|bool|string> */
    public function inspect(SandboxSession $session): array
    {
        if (!$session->isOrganization() || !hash_equals($this->contextFingerprint, $session->contextFingerprint())) {
            throw new RuntimeException('Reconciliation references cannot cross account contexts.');
        }
        $result = [
            'phase' => 'reconcile_prior_subscription_create', 'result' => 'pending', 'api_version' => '2026-08-21',
            'context_matches' => true, 'subscription_count' => 0, 'request_id_present_count' => 0,
            'request_id_matches_count' => 0, 'run_marker_present_count' => 0, 'run_marker_matches_count' => 0,
            'both_match_count' => 0, 'all_customer_references_match' => true, 'pagination_complete' => false,
            'mutation_sent' => false,
        ];
        $page = null;
        $seen = [];
        $subscriptions = [];
        for ($number = 0; $number < 10; ++$number) {
            $query = ['billing_customer_id' => $this->customer, 'page_size' => 100];
            if ($page !== null) {
                $query['page'] = $page;
            }
            $response = $session->client->billing()->subscriptions()->list($query);
            $items = $response['items'] ?? null;
            if (!is_array($items) || !array_is_list($items) || count($items) > 100) {
                throw new ProtocolException('Reconciliation list envelope exceeds its observation bound.');
            }
            foreach ($items as $item) {
                if (
                    !is_array($item) || !is_string($item['id'] ?? null) || $item['id'] === ''
                    || ($item['billing_customer_id'] ?? null) !== $this->customer
                ) {
                    $result['all_customer_references_match'] = false;
                    $result['result'] = 'fail';
                    return $result;
                }
                $identity = hash('sha256', $item['id']);
                if (isset($subscriptions[$identity])) {
                    continue;
                }
                $subscriptions[$identity] = true;
                ++$result['subscription_count'];
                $request = $item['request_id'] ?? null;
                $metadata = $item['metadata'] ?? null;
                $marker = is_array($metadata) ? ($metadata['sdk_validation_run'] ?? null) : null;
                $hasRequest = is_string($request);
                $hasMarker = is_string($marker);
                $requestMatches = $hasRequest && hash_equals($this->request, $request);
                $markerMatches = $hasMarker && hash_equals($this->marker, $marker);
                $result['request_id_present_count'] += (int) $hasRequest;
                $result['request_id_matches_count'] += (int) $requestMatches;
                $result['run_marker_present_count'] += (int) $hasMarker;
                $result['run_marker_matches_count'] += (int) $markerMatches;
                $result['both_match_count'] += (int) ($requestMatches && $markerMatches);
            }
            if (!array_key_exists('page_after', $response)) {
                return $result; // Missing terminal evidence does not prove there is no next page.
            }
            $page = $response['page_after'];
            if ($page === null) {
                $result['pagination_complete'] = true;
                $result['result'] = 'pass';
                return $result;
            }
            if (!is_string($page) || $page === '' || isset($seen[$page])) {
                throw new ProtocolException('Reconciliation received an invalid or repeated page bookmark.');
            }
            $seen[$page] = true;
        }
        return $result;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['state' => '[private reconciliation references]'];
    }

    /** @return array<string,never> */
    public function __serialize(): array
    {
        throw new RuntimeException('Private reconciliation references cannot be serialized.');
    }
}
