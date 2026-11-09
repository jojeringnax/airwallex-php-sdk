<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Unit\Sandbox\Journeys;

use Jojeringnax\Airwallex\Internal\Json\JsonCodec;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliation;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReconciliationCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReuseCommand;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\AlipayConsentReuseTransport;
use Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\KlarnaIntentReconciliation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use stdClass;

final class AlipayConsentReconciliationTest extends TestCase
{
    /** @var list<string> */
    private array $bases = [];

    /**
     * Runs the unchanged producer against an in-memory client, then attributes only this synthetic ledger to e089.
     * @return array{base:string,state:string,credentials:string,prior:string,provider:AlipayConsentReconciliationProvider,
     * marker:string,customer:string,consent:string}
     */
    public static function fixture(bool $numericStrings = false): array
    {
        $base = sys_get_temp_dir() . '/consent-observe-' . bin2hex(random_bytes(8));
        mkdir($base, 0700);
        $base = realpath($base);
        self::assertIsString($base);
        mkdir($base . '/state', 0700);
        $file = $base . '/credentials';
        file_put_contents($file, "AIRWALLEX_CLIENT_ID=PRIVATE_CLIENT\nAIRWALLEX_SANDBOX_API_KEY_MASTER=PRIVATE_KEY\n"
            . "AIRWALLEX_ACCOUNT_ID=PRIVATE_ACCOUNT\n");
        chmod($file, 0600);
        $provider = self::provider($numericStrings);
        $result = AlipayConsentReuseCommand::run(['environment' => 'sandbox', 'credentials-file' => $file,
            'state-dir' => $base . '/state', 'verify-and-charge-one-alipay-hk-consent' => true], $provider);
        self::assertSame('fail', $result['result']);
        self::assertSame('consent_verify', $result['stage']);
        self::assertSame(6, $result['http_invocations']);
        $paths = glob($base . '/state/sdk-validation-*/ledger.json') ?: [];
        self::assertCount(1, $paths);
        $prior = $paths[0];
        $raw = file_get_contents($prior);
        self::assertIsString($raw);
        $state = (new JsonCodec())->decodeObject($raw);
        $resources = $state['resources'] ?? null;
        self::assertIsArray($resources);
        self::assertIsArray($resources['payment_customers']);
        self::assertIsArray($resources['payment_consents']);
        $customer = AlipayConsentReuseTransport::identity($resources['payment_customers'][0]);
        $consent = AlipayConsentReuseTransport::identity($resources['payment_consents'][0]);
        $marker = $state['run_id'] ?? null;
        self::assertIsString($marker);
        $state['source'] = ['base_commit' => AlipayConsentReconciliation::ORIGINAL,
            'sdk_digest' => AlipayConsentReconciliation::SDK, 'harness_digest' => AlipayConsentReconciliation::HARNESS];
        file_put_contents($prior, json_encode($state, JSON_THROW_ON_ERROR));
        $provider->allowReads();
        $provider->intercept = null;
        $provider->response = null;
        return ['base' => $base, 'state' => $base . '/state', 'credentials' => $file, 'prior' => $prior,
            'provider' => $provider, 'marker' => $marker, 'customer' => $customer, 'consent' => $consent];
    }

    public static function provider(bool $numeric = false): AlipayConsentReconciliationProvider
    {
        return new AlipayConsentReconciliationProvider($numeric);
    }

    /** @param array{credentials:string,state:string,prior:string} $fixture
     * @return array<string,mixed>
     */
    public static function options(array $fixture): array
    {
        return ['environment' => 'sandbox', 'credentials-file' => $fixture['credentials'],
            'prior-ledger' => $fixture['prior'], 'state-dir' => $fixture['state'],
            'read-own-alipay-hk-consent' => true];
    }

    public static function remove(string $base): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $base,
            \FilesystemIterator::SKIP_DOTS
        ), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($base);
    }

    protected function tearDown(): void
    {
        foreach ($this->bases as $base) {
            self::remove($base);
        }
    }

    public function testMissingPromisorSourceCannotRunEvenALocalFetchHelper(): void
    {
        $base = sys_get_temp_dir() . '/consent-source-' . bin2hex(random_bytes(8));
        mkdir($base, 0700);
        $base = realpath($base);
        self::assertIsString($base);
        $this->bases[] = $base;
        $repo = $base . '/repo';
        mkdir($repo . '/objects', 0700, true);
        mkdir($repo . '/refs', 0700);
        file_put_contents($repo . '/HEAD', "ref: refs/heads/main\n");
        file_put_contents($repo . '/config', "[core]\n\tbare = true\n[remote \"fixture\"]\n"
            . "\tpromisor = true\n\turl = ext::/usr/bin/touch " . $base . "/PRIVATE-marker\n");
        // Positive control executes only a local touch in this fixture, never a network transport.
        $process = proc_open(
            ['git', '-C', $repo, 'show', str_repeat('a', 40)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            env_vars: ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'LANG' => 'C', 'GIT_ALLOW_PROTOCOL' => 'ext']
        );
        self::assertIsResource($process);
        try {
            self::assertNotSame(0, proc_close($process));
            self::assertFileExists($base . '/PRIVATE-marker');
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        unlink($base . '/PRIVATE-marker');
        $read = new \ReflectionMethod(AlipayConsentReconciliation::class, 'git');
        try {
            $read->invoke(null, $repo, ['show', str_repeat('a', 40)]);
            self::fail('The missing original source was accepted.');
        } catch (RuntimeException $failure) {
            self::assertStringNotContainsString('PRIVATE', $failure->getMessage());
            self::assertFileDoesNotExist($base . '/PRIVATE-marker');
        }
    }

    /** @return iterable<string,array{string}> */
    public static function states(): iterable
    {
        foreach (
            ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION', 'PENDING', 'PAUSED', 'VERIFIED', 'DISABLED'] as $s
        ) {
            yield $s => [$s];
        }
    }

    #[DataProvider('states')]
    public function testObservationPassNeverResolvesOriginalFailedFinancialCaseOrSendsCleanup(string $state): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $before = hash_file('sha256', $f['prior']);
        $f['provider']->resources[$f['consent']]['status'] = $state;
        $r = AlipayConsentReconciliationCommand::run(self::options($f), $f['provider']);
        self::assertSame('pass', $r['observation_result']);
        self::assertSame('pending', $r['result']);
        self::assertSame($state, $r['current_consent_state']);
        self::assertTrue($r['owner_chain_matched']);
        self::assertTrue($r['historical_reconciliation_required']);
        self::assertTrue($r['historical_verification_or_financial_disposition_unresolved']);
        self::assertFalse($r['original_pipeline_rewritten']);
        self::assertSame('fail', $r['original_pipeline_result']);
        self::assertSame(0, $r['cleanup_writes_sent']);
        self::assertSame($state === 'DISABLED', $r['current_terminal_consent_capability_observed']);
        self::assertSame(
            $state === 'DISABLED' ? 'already_disabled_current_observation' : 'pending_not_attempted',
            $r['consent_cleanup']
        );
        self::assertTrue($r['prior_bytes_unchanged']);
        self::assertSame($before, hash_file('sha256', $f['prior']));
        self::assertCount(3, $f['provider']->requests);
        self::assertSame(['POST', 'GET', 'GET'], array_map(static fn (RequestInterface $r): string =>
            $r->getMethod(), $f['provider']->requests));
        self::assertSame('PRIVATE_ACCOUNT', $f['provider']->requests[0]->getHeaderLine('x-login-as'));
        self::assertFalse($f['provider']->requests[1]->hasHeader('x-login-as'));
        self::assertStringNotContainsString('PRIVATE', json_encode($r, JSON_THROW_ON_ERROR));
        $new = array_values(array_filter(
            glob($f['state'] . '/sdk-validation-*/ledger.json') ?: [],
            static fn (string $path): bool => $path !== $f['prior']
        ));
        self::assertCount(1, $new);
        $raw = file_get_contents($new[0]);
        self::assertIsString($raw);
        $native = KlarnaIntentReconciliation::assertUniqueJsonObject($raw);
        self::assertSame([], $native->resources);
        self::assertStringNotContainsString('synthetic-payments-token', $raw);
    }

    public function testQuotedNumericOpaqueIdentitiesAndOriginalPreparedWireAreAccepted(): void
    {
        $f = self::fixture(true);
        $this->bases[] = $f['base'];
        self::assertSame('123', $f['customer']);
        self::assertSame('456', $f['consent']);
        $r = AlipayConsentReconciliationCommand::run(self::options($f), $f['provider']);
        self::assertSame('pass', $r['observation_result']);
        $native = KlarnaIntentReconciliation::assertUniqueJsonObject((string) file_get_contents($f['prior']));
        self::assertInstanceOf(stdClass::class, $native->actions);
        self::assertInstanceOf(stdClass::class, $native->observations);
        $verify = $native->actions->consent_verify;
        $wire = $native->observations->consent_verify_wire_prepared;
        self::assertInstanceOf(stdClass::class, $verify);
        self::assertInstanceOf(stdClass::class, $wire);
        $body = AlipayConsentReconciliation::bodies($f['marker'], $f['customer'])['consent_verify'];
        $body['request_id'] = $verify->request_id;
        self::assertSame(hash('sha256', (new JsonCodec())->encode($body)), $wire->body_sha256);
        self::assertNotSame(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), $wire->body_sha256);
    }

    /** @return iterable<string,array{string}> */
    public static function badPrior(): iterable
    {
        foreach (
            ['api', 'context', 'old-source', 'old-sdk', 'old-harness', 'inventory-extra', 'numeric-id',
            'repeat-uuid', 'action-extra', 'intent-hash', 'wire-body', 'wire-target', 'missing-possible',
            'wrong-status', 'numeric-status', 'bad-response-hash', 'duplicate-context', 'escaped-duplicate',
            'oversize', 'mode', 'root-override', 'run-folder-git', 'state-root-git',
            'symlink', 'renamed-folder'] as $key
        ) {
            yield $key => [$key];
        }
    }

    #[DataProvider('badPrior')]
    public function testOriginalAmbiguityOrChangedBindingRejectsBeforeAnyAuthentication(string $key): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $raw = file_get_contents($f['prior']);
        self::assertIsString($raw);
        $n = KlarnaIntentReconciliation::assertUniqueJsonObject($raw);
        self::assertInstanceOf(stdClass::class, $n->source);
        self::assertInstanceOf(stdClass::class, $n->resources);
        self::assertInstanceOf(stdClass::class, $n->actions);
        self::assertInstanceOf(stdClass::class, $n->observations);
        self::assertInstanceOf(stdClass::class, $n->actions->consent_verify);
        self::assertInstanceOf(stdClass::class, $n->actions->customer_create);
        self::assertInstanceOf(stdClass::class, $n->observations->consent_verify_wire_prepared);
        self::assertInstanceOf(stdClass::class, $n->observations->consent_verify_wire_result);
        $options = self::options($f);
        switch ($key) {
            case 'api':
                $n->api_version = '2024-01-01';
                break;
            case 'context':
                $n->context_fingerprint = str_repeat('0', 64);
                break;
            case 'old-source':
                $n->source->base_commit = str_repeat('a', 40);
                break;
            case 'old-sdk':
                $n->source->sdk_digest = str_repeat('a', 64);
                break;
            case 'old-harness':
                $n->source->harness_digest = str_repeat('a', 64);
                break;
            case 'inventory-extra':
                $n->resources->payment_intents = [];
                break;
            case 'numeric-id':
                $n->resources->payment_customers = [123];
                break;
            case 'repeat-uuid':
                $n->actions->consent_verify->request_id = $n->actions->customer_create->request_id;
                break;
            case 'action-extra':
                $n->actions->consent_disable = $n->actions->consent_verify;
                break;
            case 'intent-hash':
                $n->actions->consent_verify->body_hash = str_repeat('a', 64);
                break;
            case 'wire-body':
                $n->observations->consent_verify_wire_prepared->body_sha256 =
                $n->actions->consent_verify->body_hash;
                break;
            case 'wire-target':
                $n->observations->consent_verify_wire_prepared->target_sha256 = str_repeat('a', 64);
                break;
            case 'missing-possible':
                unset($n->observations->consent_verify_possible_send);
                break;
            case 'wrong-status':
                $n->observations->consent_verify_wire_result->http_status = '200';
                break;
            case 'numeric-status':
                $n->observations->consent_verify_wire_result->http_status = 400;
                break;
            case 'bad-response-hash':
                $n->observations->consent_verify_wire_result->response_sha256 = 'PRIVATE';
                break;
            case 'mode':
                chmod($f['prior'], 0644);
                break;
            case 'root-override':
                $options['state-dir'] = $f['base'];
                break;
            case 'run-folder-git':
                mkdir(dirname($f['prior']) . '/.git');
                break;
            case 'state-root-git':
                mkdir($f['state'] . '/.git');
                break;
            case 'symlink':
                $alias = $f['state'] . '/PRIVATE-link.json';
                symlink($f['prior'], $alias);
                $options['prior-ledger'] = $alias;
                break;
            case 'renamed-folder':
                $renamed = $f['state'] . '/PRIVATE-renamed';
                rename(dirname($f['prior']), $renamed);
                $f['prior'] = $renamed . '/ledger.json';
                $options['prior-ledger'] = $f['prior'];
                break;
        }
        $changed = json_encode($n, JSON_THROW_ON_ERROR);
        if ($key === 'duplicate-context' || $key === 'escaped-duplicate') {
            $name = $key === 'escaped-duplicate' ? 'context_\\u0066ingerprint' : 'context_fingerprint';
            $changed = '{"' . $name . '":"PRIVATE",' . substr($changed, 1);
        } elseif ($key === 'oversize') {
            $changed = str_repeat(' ', 1048577);
        }
        file_put_contents($f['prior'], $changed);
        $r = AlipayConsentReconciliationCommand::run($options, $f['provider']);
        self::assertSame('fail', $r['result']);
        self::assertSame('prior_validation', $r['phase']);
        self::assertSame([], $f['provider']->requests);
        self::assertStringNotContainsString('PRIVATE', json_encode($r, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string,array{string}> */
    public static function badOwner(): iterable
    {
        foreach (
            ['customer-id', 'numeric-customer', 'merchant-marker', 'customer-run', 'consent-id',
            'parent', 'numeric-parent', 'terms', 'consent-run', 'connected', 'unlisted-state', 'missing-state',
            'matching-numeric-id', 'matching-numeric-parent'] as $key
        ) {
            yield $key => [$key];
        }
    }

    #[DataProvider('badOwner')]
    public function testCurrentNativeOwnershipAndKnownStateAreRequiredWithoutAnyFallback(string $key): void
    {
        $f = self::fixture(str_starts_with($key, 'matching-numeric-'));
        $this->bases[] = $f['base'];
        $f['provider']->response = static function (RequestInterface $request, array $resource) use ($key): array {
            $checked = [];
            foreach ($resource as $name => $value) {
                if (!is_string($name)) {
                    throw new RuntimeException('Synthetic resource object keys are required.');
                }
                $checked[$name] = $value;
            }
            $resource = $checked;
            $customer = str_contains($request->getUri()->getPath(), '/customers/');
            if ($customer) {
                if ($key === 'customer-id') {
                    $resource['id'] = 'PRIVATE_FOREIGN';
                }
                if ($key === 'matching-numeric-id') {
                    $resource['id'] = 123;
                }
                if ($key === 'numeric-customer') {
                    $resource['id'] = 123;
                }
                if ($key === 'merchant-marker') {
                    $resource['merchant_customer_id'] = 'PRIVATE_FOREIGN';
                }
                if ($key === 'customer-run') {
                    $resource['metadata'] = ['sdk_validation_run' => 'PRIVATE'];
                }
            } else {
                if ($key === 'consent-id') {
                    $resource['id'] = 'PRIVATE_FOREIGN';
                }
                if ($key === 'parent') {
                    $resource['customer_id'] = 'PRIVATE_FOREIGN';
                }
                if ($key === 'matching-numeric-parent') {
                    $resource['customer_id'] = 123;
                }
                if ($key === 'numeric-parent') {
                    $resource['customer_id'] = 123;
                }
                if ($key === 'terms') {
                    $resource['next_triggered_by'] = 'customer';
                }
                if ($key === 'consent-run') {
                    $resource['metadata'] = ['sdk_validation_run' => 'PRIVATE'];
                }
                if ($key === 'connected') {
                    $resource['connected_account_id'] = 'PRIVATE_FOREIGN';
                }
                if ($key === 'unlisted-state') {
                    $resource['status'] = 'PRIVATE_UNKNOWN';
                }
                if ($key === 'missing-state') {
                    unset($resource['status']);
                }
            }
            return $resource;
        };
        $r = AlipayConsentReconciliationCommand::run(self::options($f), $f['provider']);
        self::assertSame('fail', $r['observation_result']);
        self::assertTrue($r['historical_reconciliation_required']);
        self::assertFalse($r['owner_chain_matched']);
        self::assertSame(0, $r['cleanup_writes_sent']);
        self::assertCount(str_starts_with($key, 'customer') || in_array(
            $key,
            ['numeric-customer', 'merchant-marker', 'matching-numeric-id'],
            true
        ) ? 2 : 3, $f['provider']->requests);
        self::assertStringNotContainsString('PRIVATE', json_encode($r, JSON_THROW_ON_ERROR));
    }

    public function testOptionalMethodAndActionFieldsNeverAuthorizeAnotherResourceOrClearHistory(): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $f['provider']->resources[$f['consent']] += ['payment_method' => ['id' => 123, 'type' => 'PRIVATE'],
            'initial_payment_intent_id' => 'PRIVATE_INTENT', 'next_action' => ['url' => 'https://PRIVATE']];
        $r = AlipayConsentReconciliationCommand::run(self::options($f), $f['provider']);
        self::assertSame('pass', $r['observation_result']);
        self::assertSame('other', $r['method_id_state']);
        self::assertFalse($r['method_id_usable']);
        self::assertFalse($r['method_type_matches']);
        self::assertSame('object', $r['next_action_state']);
        self::assertTrue($r['historical_verification_or_financial_disposition_unresolved']);
        self::assertCount(3, $f['provider']->requests);
        self::assertStringNotContainsString('PRIVATE', json_encode($r, JSON_THROW_ON_ERROR));
    }

    public function testCleanupSeamReusesOriginalProofButSeparatesContextAndKeepsStablePrivateClaimNamespace(): void
    {
        $f = self::fixture();
        $this->bases[] = $f['base'];
        $configuration = \Jojeringnax\Airwallex\Tests\Support\Sandbox\Configuration::load('sandbox', $f['credentials']);
        $session = new \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\SandboxSession(
            $configuration,
            'account',
            $f['provider']
        );
        $prior = AlipayConsentReconciliation::load($f['prior'], $f['state'], $session);
        $context = $prior->cleanupContext(str_repeat('a', 64));
        self::assertNotSame($session->contextFingerprint(), $context);
        self::assertNotSame($prior->cleanupContext(str_repeat('b', 64)), $context);
        $path = $prior->cleanupClaimPath();
        self::assertSame($f['state'], dirname($path));
        self::assertMatchesRegularExpression('/^\.consent-disable-[a-f0-9]{64}\.claim$/D', basename($path));
        $ledger = \Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\RunLedger::createConsentCleanup(
            $context,
            $f['state']
        );
        $claim = $prior->cleanupClaim($ledger, str_repeat('a', 64));
        self::assertSame($ledger->runId(), $claim['run_id']);
        self::assertArrayNotHasKey('request_id', $claim);
        self::assertSame($path, $prior->cleanupClaimPath());
        self::assertSame([], $ledger->counts());
        self::assertStringNotContainsString($f['customer'], json_encode($claim, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($f['consent'], json_encode($claim, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($f['customer'], print_r($prior, true));
        self::assertSame([], $f['provider']->requests);
    }
}
