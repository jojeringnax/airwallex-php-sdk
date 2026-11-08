<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Examples;

use Closure;
use InvalidArgumentException;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class BillingFiniteRestorationTest extends HttpTestCase
{
    private const string LOGIN = '{"token":"fixture-token","expires_at":"2130-01-01T00:00:00Z"}';
    private const string END = '2100-01-08T01:00:00.123456789+01:00';

    private function recipe(string $name): Closure
    {
        $recipes = require dirname(__DIR__, 2) . '/examples/billing-lifecycle.php';
        self::assertIsArray($recipes);
        $recipe = $recipes[$name];
        self::assertInstanceOf(Closure::class, $recipe);
        return $recipe;
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private static function subscription(array $changes = []): array
    {
        return array_replace([
            'id' => 'sub', 'billing_customer_id' => 'buyer', 'legal_entity_id' => 'entity',
            'status' => 'ACTIVE', 'currency' => 'EUR', 'collection_method' => 'OUT_OF_BAND',
            'cancel_at_period_end' => false, 'ends_at' => self::END,
            'duration' => ['period' => 7, 'period_unit' => 'DAY'],
        ], $changes);
    }

    /** @return array<string,string> */
    private static function snapshot(): array
    {
        return ['subscription_id' => 'sub', 'billing_customer_id' => 'buyer', 'legal_entity_id' => 'entity',
            'ends_at' => self::END, 'schedule_request_id' => 'persisted-schedule',
            'restore_request_id' => 'separate-persisted-restore'];
    }

    /** @param array<string,mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function testExactOriginalEndAndBothActionsAreCommittedBeforeSchedulingThenRestored(): void
    {
        $shortened = self::subscription(['cancel_at_period_end' => true,
            'ends_at' => '2100-01-02T00:00:00Z', 'duration' => ['period' => 1, 'period_unit' => 'DAY']]);
        $restored = self::subscription(['ends_at' => '2100-01-08T00:00:00.1234567890Z']);
        $http = $this->scenario([[201, self::LOGIN], [200, self::json(self::subscription())],
            [200, self::json($shortened)], [200, self::json($shortened)],
            [200, '{}'], [200, self::json($restored)]]);
        $client = $this->client($http);
        $storage = new class {
            public mixed $snapshot = null;
            public int $requestsAtCommit = 0;
        };
        ($this->recipe('schedule_finite_end'))(
            $client,
            'sub',
            'buyer',
            'entity',
            'persisted-schedule',
            'separate-persisted-restore',
            static function (array $snapshot) use ($storage, $http): void {
                $storage->snapshot = $snapshot; // Synthetic stand-in for the application's durable commit.
                $storage->requestsAtCommit = count($http->requests);
            },
        );
        self::assertSame(2, $storage->requestsAtCommit); // Login + GET, before any mutation.
        self::assertSame(self::snapshot(), $storage->snapshot);
        self::assertIsArray($storage->snapshot);
        $result = ($this->recipe('restore_finite_end'))($client, $storage->snapshot);
        self::assertIsArray($result);
        self::assertSame(false, $result['cancel_at_period_end']);
        self::assertSame($restored['ends_at'], $result['ends_at']);
        self::assertCount(6, $http->requests);
        self::assertSame(
            '{"request_id":"persisted-schedule","cancel_at_period_end":true}',
            (string) $http->requests[2]->getBody()
        );
        self::assertSame(self::json(['request_id' => 'separate-persisted-restore',
            'cancel_at_period_end' => false, 'ends_at' => self::END]), (string) $http->requests[4]->getBody());
        self::assertSame('GET', $http->requests[3]->getMethod());
        self::assertSame('GET', $http->requests[5]->getMethod());
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('', $request->getHeaderLine('x-on-behalf-of'));
        }
    }

    public function testFailedApplicationCommitStopsBeforeAnyMutation(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, self::json(self::subscription())]]);
        try {
            ($this->recipe('schedule_finite_end'))(
                $this->client($http),
                'sub',
                'buyer',
                'entity',
                'schedule',
                'restore',
                static function (): never {
                    throw new RuntimeException('Synthetic application storage failure.');
                },
            );
            self::fail('A failed commit must stop scheduling.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic application storage failure.', $error->getMessage());
        }
        self::assertCount(2, $http->requests);
    }

    public function testSchedulingRequiresDistinctActionsBeforeAuthentication(): void
    {
        $http = $this->scenario([]);
        try {
            ($this->recipe('schedule_finite_end'))(
                $this->client($http),
                'sub',
                'buyer',
                'entity',
                'same-action',
                'same-action',
                static function (): never {
                    self::fail('Duplicate actions must not reach persistence.');
                },
            );
            self::fail('Separate consumer actions require distinct identities.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('distinct persisted action identities', $error->getMessage());
        }
        self::assertCount(0, $http->requests);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidStoredAction(): iterable
    {
        yield 'missing saved end' => [['ends_at' => null]];
        yield 'past saved end' => [['ends_at' => '2000-01-01T00:00:00Z']];
        yield 'bad saved offset' => [['ends_at' => '2100-01-08T00:00:00+24:00']];
        yield 'missing identity' => [['restore_request_id' => '']];
        yield 'duplicate identity' => [['restore_request_id' => 'persisted-schedule']];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidStoredAction')]
    public function testInvalidStoredActionStopsBeforeAuthentication(array $changes): void
    {
        $http = $this->scenario([]);
        try {
            ($this->recipe('restore_finite_end'))($this->client($http), array_replace(self::snapshot(), $changes));
            self::fail('Invalid private action storage must not reach authentication.');
        } catch (InvalidArgumentException) {
            self::assertCount(0, $http->requests);
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidInitialState(): iterable
    {
        foreach (
            ['id' => 'other', 'billing_customer_id' => 'other', 'legal_entity_id' => 'other',
            'status' => 'CANCELLED', 'currency' => 'USD', 'collection_method' => 'AUTO_CHARGE',
            'payment_source_id' => 'source', 'cancel_at_period_end' => true] as $key => $value
        ) {
            yield $key => [[$key => $value]];
        }
        foreach (
            ['missing' => null, 'past' => '2000-01-01T00:00:00Z', 'relative' => '+1 hour',
            'calendar' => '2100-02-30T00:00:00Z', 'offset' => '2100-01-08T00:00:00+24:00',
            'offset-shape' => '2100-01-08T00:00:00+2400'] as $name => $value
        ) {
            yield $name => [['ends_at' => $value]];
        }
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidInitialState')]
    public function testUnverifiedInitialStateCannotBePersistedOrScheduled(array $changes): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, self::json(self::subscription($changes))]]);
        try {
            ($this->recipe('schedule_finite_end'))(
                $this->client($http),
                'sub',
                'buyer',
                'entity',
                'schedule',
                'restore',
                static function (): never {
                    self::fail('Unverified state must not reach application persistence.');
                },
            );
            self::fail('Unverified state must stop scheduling.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Verify the owned ACTIVE', $error->getMessage());
        }
        self::assertCount(2, $http->requests);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function unobservedRestoration(): iterable
    {
        yield 'still scheduled' => [['cancel_at_period_end' => true]];
        yield 'shortened end' => [['ends_at' => '2100-01-02T00:00:00Z']];
        yield 'fraction differs' => [['ends_at' => '2100-01-08T00:00:00.123456788Z']];
        yield 'missing end' => [['ends_at' => null]];
        yield 'wrong customer' => [['billing_customer_id' => 'other']];
        yield 'cancelled' => [['status' => 'CANCELLED']];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('unobservedRestoration')]
    public function testAcknowledgementWithoutExactReadbackRequiresReconciliationWithoutReplay(array $changes): void
    {
        $http = $this->scenario([[201, self::LOGIN],
            [200, self::json(self::subscription(['cancel_at_period_end' => true]))], [200, '{}'],
            [200, self::json(self::subscription($changes))]]);
        try {
            ($this->recipe('restore_finite_end'))(
                $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3)),
                self::snapshot(),
            );
            self::fail('A successful POST alone must not pass restoration.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('not yet observed', $error->getMessage());
        }
        self::assertCount(4, $http->requests);
    }

    public function testCancelledPreflightCannotSubmitRestoration(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, self::json(self::subscription(['status' => 'CANCELLED']))]]);
        try {
            ($this->recipe('restore_finite_end'))($this->client($http), self::snapshot());
            self::fail('Cancelled subscriptions cannot be restored.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Reconcile the owned ACTIVE', $error->getMessage());
        }
        self::assertCount(2, $http->requests);
    }

    public function testRejectedRestorationPreservesSavedBytesAndIdentityWithoutReplay(): void
    {
        $stored = self::snapshot();
        $http = $this->scenario([[201, self::LOGIN],
            [200, self::json(self::subscription(['cancel_at_period_end' => true]))],
            [400, '{"code":"validation_error","message":"synthetic-private-provider-message"}']]);
        try {
            ($this->recipe('restore_finite_end'))(
                $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3)),
                $stored,
            );
            self::fail('Expected synthetic provider rejection.');
        } catch (ApiException $error) {
            self::assertSame(400, $error->httpStatus());
            self::assertStringNotContainsString('synthetic-private-provider-message', (string) $error);
        }
        self::assertSame(self::snapshot(), $stored);
        self::assertCount(3, $http->requests);
        self::assertSame(self::json(['request_id' => 'separate-persisted-restore',
            'cancel_at_period_end' => false, 'ends_at' => self::END]), (string) $http->requests[2]->getBody());
    }
}
