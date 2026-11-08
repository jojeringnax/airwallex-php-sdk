<?php

declare(strict_types=1);

namespace Jojeringnax\Airwallex\Tests\Resource\Billing;

use Closure;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\Airwallex\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

final class BillingWorkflowTest extends HttpTestCase
{
    private const LOGIN = '{"token":"fixture-token","expires_at":"2030-01-01T00:00:00Z"}';
    public function testMonthlyCataloguePreservesExactNumbersAndImmutablePriceFields(): void
    {
        $http = $this->scenario(
            [
                [201, self::LOGIN],
                [201, '{"id":"prd_test"}'],
                [
                    201,
                    '{"id":"pri_test","flat_amount":19.900000000000000001,"recurring":{"per'
                        . 'iod":1,"period_unit":"MONTH"},"extra_number":1e100}',
                ],
            ]
        );
        $billing = $this->client($http)->billing();
        $product = $billing->products()->create(['request_id' => 'product-request', 'name' => 'Service']);
        $price = $billing->prices()->create(
            [
                'request_id' => 'price-request',
                'product_id' => $product['id'],
                'currency' => 'EUR',
                'pricing_model' => 'FLAT',
                'flat_amount' => '19.900000000000000001',
                'recurring' => ['period' => 1, 'period_unit' => 'MONTH'],
                'metadata' => ['sku' => '0019.90'],
            ]
        );
        self::assertSame('19.900000000000000001', $price['flat_amount']);
        self::assertSame(['period' => 1, 'period_unit' => 'MONTH'], $price['recurring']);
        self::assertSame('1e100', $price['extra_number']);
        self::assertSame(
            '{"request_id":"price-request","product_id":"prd_test","currency":"EUR"'

                . ',"pricing_model":"FLAT","flat_amount":19.900000000000000001,"recurring'
                . '":{"period":1,"period_unit":"MONTH"},"metadata":{"sku":"0019.90"}}',
            (string) $http->requests[2]->getBody()
        );
    }
    public function testOneTimePriceNullRecurrenceAndOptionalClearingRetainTheirWireShape(): void
    {
        $http = $this->scenario(
            [
                [201, self::LOGIN],
                [201, '{"id":"pri_once","recurring":null,"unit_amount":0}'],
                [200, '{"id":"bcus_test","email":null,"metadata":{}}'],
                [200, '{}'],
            ]
        );
        $billing = $this->client($http)->billing();
        $price = $billing->prices()->create(
            [
                'request_id' => 'once',
                'currency' => 'EUR',
                'product_id' => 'prd_test',
                'unit_amount' => '0.00',
                'recurring' => null,
            ]
        );
        self::assertSame('0', $price['unit_amount']);
        self::assertNull($price['recurring']);
        $customer = $billing->customers()->update(
            'bcus_test',
            ['email' => null, 'name' => '', 'metadata' => new stdClass()]
        );
        self::assertNull($customer['email']);
        self::assertInstanceOf(stdClass::class, $customer['metadata']);
        self::assertSame(
            '{"email":null,"name":"","metadata":{}}',
            (string) $http->requests[2]->getBody()
        );
        $billing->customers()->update('bcus_test', []);
        self::assertSame('{}', (string) $http->requests[3]->getBody());
    }
    public function testMetadataObjectRetainsNumericLookingStringKeysOnTheWire(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{"id":"bcus_test"}']]);
        $this->client($http)->billing()->customers()->update('bcus_test', [
            'metadata' => (object) ['0' => '0019.90'],
        ]);
        self::assertSame('{"metadata":{"0":"0019.90"}}', (string) $http->requests[1]->getBody());
    }

    #[DataProvider('checkoutModes')]
    public function testAcceptedBusinessAndUiModesReturnConditionalProviderFields(string $mode, string $ui): void
    {
        $response = [
            'id' => 'checkout_test',
            'mode' => $mode,
            'ui_mode' => $ui,
            'status' => 'ACTIVE',
        ];
        if ($ui === 'HOSTED') {
            $response['url'] = 'https://example.test/provider-checkout';
        } else {
            $response['client_secret'] = 'fixture-browser-only-secret';
        }
        $http = $this->scenario(
            [
                [201, self::LOGIN],
                [201, json_encode($response, JSON_THROW_ON_ERROR)],
                [
                    200,
                    '{"id":"checkout_test","status":"EXPIRED","url":null,"client_secret":null}',
                ],
            ]
        );
        $checkouts = $this->client($http)->billing()->checkouts();
        $body = [
            'request_id' => 'checkout-request',
            'mode' => $mode,
            'ui_mode' => $ui,
            'billing_customer_id' => 'bcus_test',
            'linked_payment_account_id' => 'acct_test',
            'legal_entity_id' => 'le_test',
        ];
        if ($mode === 'SETUP') {
            $body['currency'] = 'EUR';
        } else {
            $body['line_items'] = [['price_id' => 'pri_test', 'quantity' => 1]];
            $body['success_url'] = 'https://example.test/complete';
        }
        $checkout = $checkouts->create($body);
        self::assertSame($response, $checkout);
        self::assertSame($ui !== 'HOSTED', array_key_exists('client_secret', $checkout));
        $expired = $checkouts->retrieve($checkout['id']);
        self::assertSame('EXPIRED', $expired['status']);
        self::assertNull($expired['url']);
        self::assertCount(3, $http->requests);
    }
    /** @return iterable<string,array{string,string}> */
    public static function checkoutModes(): iterable
    {
        foreach (['PAYMENT', 'SUBSCRIPTION', 'SETUP'] as $mode) {
            foreach (['HOSTED', 'EMBEDDED', 'ELEMENTS'] as $ui) {
                yield $mode . '-' . $ui => [$mode, $ui];
            }
        }
    }
    /** @param array<string,mixed> $settings */
    #[DataProvider('invoiceDueSettings')]
    public function testPaymentCheckoutAcceptsEitherSingleInvoiceDueSetting(array $settings, string $wire): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"checkout_test","status":"ACTIVE"}']]);
        $this->client($http)->billing()->checkouts()->create([
            'request_id' => 'due-setting', 'mode' => 'PAYMENT',
            'line_items' => [['price_id' => 'price_test']], 'invoice_data' => $settings,
        ]);
        self::assertStringContainsString($wire, (string) $http->requests[1]->getBody());
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function invoiceDueSettings(): iterable
    {
        yield 'relative-zero-days' => [['days_until_due' => 0], '"invoice_data":{"days_until_due":0}'];
        yield 'absolute-date' => [
            ['due_at' => '2028-01-01T00:00:00Z'], '"invoice_data":{"due_at":"2028-01-01T00:00:00Z"}',
        ];
    }

    public function testRedirectUrlSchemeIsCaseInsensitiveWithoutChangingTheWireValue(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [201, '{"id":"checkout_test","status":"ACTIVE"}']]);
        $this->client($http)->billing()->checkouts()->create([
            'request_id' => 'mixed-case-url', 'mode' => 'PAYMENT',
            'line_items' => [['price_id' => 'price_test']],
            'success_url' => 'HTTPS://example.test/ok',
            'back_url' => 'hTtPs://example.test/back',
        ]);
        self::assertStringContainsString(
            '"success_url":"HTTPS://example.test/ok"',
            (string) $http->requests[1]->getBody(),
        );
        self::assertStringContainsString(
            '"back_url":"hTtPs://example.test/back"',
            (string) $http->requests[1]->getBody(),
        );
    }

    public function testTrialsPlanChangesRecoveryAndCancellationRequireExplicitCalls(): void
    {
        $http = $this->scenario(
            [
                [201, self::LOGIN],
                [
                    201,
                    '{"id":"sub_test","status":"PENDING","starts_at":"2028-02-01T10:11:12.1'
                        . '23456789+01:00","trial_ends_at":"2028-03-01T10:11:12.000000001+01:00"}',
                ],
                [200, '{"id":"sub_test","status":"IN_TRIAL"}'],
                [200, '{"id":"sub_test","status":"ACTIVE"}'],
                [
                    200,
                    '{"id":"sub_test","status":"UNPAID","latest_invoice_id":"inv_test"}',
                ],
                [200, '{"id":"sub_test","status":"ACTIVE"}'],
                [200, '{"id":"sub_test","status":"CANCELLED"}'],
            ]
        );
        $subscriptions = $this->client($http)->billing()->subscriptions();
        $created = $subscriptions->create(
            [
                'request_id' => 'create-sub',
                'billing_customer_id' => 'bcus_test',
                'collection_method' => 'AUTO_CHARGE',
                'payment_source_id' => 'source_test',
                'linked_payment_account_id' => 'acct_test',
                'legal_entity_id' => 'le_test',
                'items' => [
                    ['price_id' => 'pri_month', 'quantity' => 1],
                    ['price_id' => 'pri_year', 'quantity' => 2],
                ],
                'starts_at' => '2028-02-01T10:11:12.123456789+01:00',
                'trial_ends_at' => '2028-03-01T10:11:12.000000001+01:00',
            ]
        );
        self::assertSame('PENDING', $created['status']);
        self::assertStringContainsString(
            '2028-02-01T10:11:12.123456789+01:00',
            (string) $http->requests[1]->getBody()
        );
        self::assertSame(
            'IN_TRIAL',
            $subscriptions->update('sub_test', ['request_id' => 'start-sub', 'starts_at' => 'NOW'])['status']
        );
        self::assertSame(
            'ACTIVE',
            $subscriptions->update(
                'sub_test',
                [
                    'request_id' => 'replace-plan',
                    'items' => [
                        ['id' => 'item_old', 'deleted' => true],
                        ['price_id' => 'pri_new', 'quantity' => 3],
                    ],
                    'default_proration_mode' => 'NONE',
                ]
            )['status']
        );
        self::assertSame('UNPAID', $subscriptions->retrieve('sub_test')['status']);
        self::assertSame(
            'ACTIVE',
            $subscriptions->update(
                'sub_test',
                [
                    'request_id' => 'replace-source',
                    'payment_source_id' => 'source_replacement',
                ]
            )['status']
        );
        self::assertSame(
            'CANCELLED',
            $subscriptions->cancel(
                'sub_test',
                ['request_id' => 'cancel-sub', 'proration_behavior' => 'NONE']
            )['status']
        );
        self::assertCount(7, $http->requests);
        self::assertStringContainsString(
            '"payment_source_id":"source_replacement"',
            (string) $http->requests[5]->getBody()
        );
    }
    #[DataProvider('prorationControls')]
    public function testItemProrationAndBillingActionsAreSerializedWithoutSdkCalculation(
        string $proration,
        string $action,
    ): void {
        $http = $this->scenario(
            [[201, self::LOGIN], [200, '{"id":"sub_test","status":"ACTIVE"}']]
        );
        $this->client($http)->billing()->subscriptions()->update(
            'sub_test',
            [
                'request_id' => 'item-change',
                'items' => [
                    ['id' => 'item_test', 'quantity' => 3, 'proration_mode' => 'NONE'],
                ],
                'default_proration_mode' => $proration,
                'billing_action' => $action,
            ]
        );
        $body = (string) $http->requests[1]->getBody();
        self::assertStringContainsString('"default_proration_mode":"' . $proration . '"', $body);
        self::assertStringContainsString('"billing_action":"' . $action . '"', $body);
        self::assertStringContainsString('"proration_mode":"NONE"', $body);
    }
    /** @return iterable<string,array{string,string}> */
    public static function prorationControls(): iterable
    {
        foreach (['NONE', 'PRORATED', 'ALL'] as $mode) {
            foreach (
                [
                'DEFER_CHARGE_AND_KEEP_CYCLE',
                'IMMEDIATE_CHARGE_AND_KEEP_CYCLE',
                'IMMEDIATE_CHARGE_AND_RESET_CYCLE',
                ] as $action
            ) {
                yield $mode . '-' . $action => [$mode, $action];
            }
        }
    }
    #[DataProvider('cancellationModes')]
    public function testCancellationProrationRemainsSeparateFromItemUpdates(string $mode): void
    {
        $http = $this->scenario([[201, self::LOGIN], [200, '{"status":"CANCELLED"}']]);
        $this->client($http)->billing()->subscriptions()->cancel(
            'sub_test',
            ['request_id' => 'cancel-request', 'proration_behavior' => $mode]
        );
        self::assertSame(
            '{"request_id":"cancel-request","proration_behavior":"' . $mode . '"}',
            (string) $http->requests[1]->getBody()
        );
    }
    /** @return iterable<string,array{string}> */
    public static function cancellationModes(): iterable
    {
        foreach (['NONE', 'PRORATED', 'ALL'] as $mode) {
            yield $mode => [$mode];
        }
    }

    /** @return iterable<string,array{string}> */
    public static function cancellationObservations(): iterable
    {
        yield 'already cancelled observation' => ['CANCELLED'];
        yield 'state may change after active observation' => ['ACTIVE'];
    }

    #[DataProvider('cancellationObservations')]
    // Chosen responses use the selected endpoint's codes; they do not establish universal state/error behavior.
    public function testExplicitCancellationPreservesAProviderStateRejectionWithoutReplaying(string $observed): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [200, json_encode(['id' => 'sub_test', 'status' => $observed], JSON_THROW_ON_ERROR)],
            [400, '{"code":"invalid_status_for_operation","message":"fixture-private-rejection"}'],
        ]);
        $subscriptions = $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3))->billing()->subscriptions();
        self::assertSame($observed, $subscriptions->retrieve('sub_test')['status']);
        try {
            $subscriptions->cancel('sub_test', [
                'request_id' => 'persisted-cancel-action', 'proration_behavior' => 'NONE',
            ]);
            self::fail('Expected the selected cancel endpoint state error.');
        } catch (ApiException $error) {
            self::assertSame(400, $error->httpStatus());
            self::assertSame('invalid_status_for_operation', $error->providerCode());
            self::assertStringNotContainsString('fixture-private-rejection', (string) $error);
        }
        self::assertCount(3, $http->requests);
        self::assertSame('POST', $http->requests[2]->getMethod());
        self::assertStringEndsWith('/subscriptions/sub_test/cancel', (string) $http->requests[2]->getUri());
        self::assertSame(
            '{"request_id":"persisted-cancel-action","proration_behavior":"NONE"}',
            (string) $http->requests[2]->getBody(),
        );
    }

    public function testAmbiguousSubscriptionCancellationKeepsItsOriginalIdentityAndIsNotReplayed(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [500, '{"code":"internal_error"}']]);
        try {
            $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3))->billing()->subscriptions()->cancel(
                'sub_test',
                ['request_id' => 'persisted-uncertain-cancel', 'proration_behavior' => 'PRORATED'],
            );
            self::fail('Expected the selected cancel endpoint server error.');
        } catch (ApiException $error) {
            self::assertSame(500, $error->httpStatus());
            self::assertSame('internal_error', $error->providerCode());
        }
        self::assertCount(2, $http->requests);
        self::assertSame(
            '{"request_id":"persisted-uncertain-cancel","proration_behavior":"PRORATED"}',
            (string) $http->requests[1]->getBody(),
        );
    }
    public function testInvoiceDocumentsAmountsAndNestedPaginationPreserveObservedState(): void
    {
        $http = $this->scenario(
            [
                [201, self::LOGIN],
                [
                    200,
                    '{"id":"inv_test","status":"DRAFT","amount_due":20.00,"pdf_url":null,"hosted_url":null}',
                ],
                [
                    200,
                    '{"id":"inv_test","status":"FINALIZED","payment_status":"PAID","total_a'

                        . 'mount":20,"pdf_url":"https://example.test/expired-pdf","hosted_url":"h'
                        . 'ttps://example.test/invoice"}',
                ],
                [
                    200,
                    '{"items":[{"id":"item_test","amount":19.900000000000000001,"price":{"u'
                        . 'nit_amount":19.90,"recurring":{"period":1}}}],"page_after":"next /+"}',
                ],
                [200, '{"items":[],"page_after":null}'],
                [200, '{"id":"item_test","quantity":3,"tax_percent":0}'],
            ]
        );
        $billing = $this->client($http)->billing();
        $draft = $billing->invoices()->retrieve('inv_test');
        self::assertNull($draft['pdf_url']);
        self::assertSame('20.00', $draft['amount_due']);
        $final = $billing->invoices()->retrieve('inv_test');
        self::assertSame('PAID', $final['payment_status']);
        self::assertSame('20', $final['total_amount']);
        self::assertSame('https://example.test/expired-pdf', $final['pdf_url']);
        $page = $billing->invoices()->listLineItems('inv_test', ['page_size' => 1]);
        self::assertIsArray($page['items']);
        self::assertIsArray($page['items'][0]);
        $first = $page['items'][0];
        self::assertSame('19.900000000000000001', $first['amount']);
        self::assertIsArray($first['price']);
        self::assertIsArray($first['price']['recurring']);
        self::assertSame(1, $first['price']['recurring']['period']);
        $billing->invoices()->listLineItems('inv_test', ['page_size' => 1, 'page' => $page['page_after']]);
        self::assertStringEndsWith(
            '/inv_test/line_items?page_size=1&page=next%20%2F%2B',
            (string) $http->requests[4]->getUri()
        );
        $item = $billing->subscriptions()->retrieveItem('sub_test', 'item_test');
        self::assertSame('3', $item['quantity']);
        self::assertSame('0', $item['tax_percent']);
    }
    public function testScheduledEndingIsExplicitAndReversalErrorsArePreserved(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [200, '{"id":"sub_test","status":"ACTIVE","cancel_at_period_end":true}'],
            [400, '{"code":"invalid_status_for_operation"}'],
        ]);
        $subscriptions = $this->client($http)->billing()->subscriptions();
        $scheduled = $subscriptions->update('sub_test', [
            'request_id' => 'scheduled-ending', 'cancel_at_period_end' => true,
        ]);
        self::assertTrue($scheduled['cancel_at_period_end']);
        self::assertSame('ACTIVE', $scheduled['status']);
        try {
            $subscriptions->update('sub_test', [
                'request_id' => 'attempt-clear-schedule',
                'cancel_at_period_end' => false,
                'duration' => null,
                'remaining_duration' => null,
                'ends_at' => null,
            ]);
            self::fail('Expected provider-specific schedule rejection.');
        } catch (ApiException $error) {
            self::assertSame('invalid_status_for_operation', $error->providerCode());
            self::assertStringContainsString('"cancel_at_period_end":false', (string) $http->requests[2]->getBody());
            self::assertStringContainsString('"ends_at":null', (string) $http->requests[2]->getBody());
        }
        self::assertCount(3, $http->requests);
    }

    public function testRepeatedAndOutOfOrderRecoveryObservationsDoNotCreateCharges(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [200, '{"id":"sub_test","status":"UNPAID"}'],
            [200, '{"id":"sub_test","status":"ACTIVE"}'],
            [200, '{"id":"sub_test","status":"UNPAID"}'],
        ]);
        $subscriptions = $this->client($http)->billing()->subscriptions();
        self::assertSame('UNPAID', $subscriptions->retrieve('sub_test')['status']);
        self::assertSame('ACTIVE', $subscriptions->retrieve('sub_test')['status']);
        self::assertSame('UNPAID', $subscriptions->retrieve('sub_test')['status']);
        self::assertCount(4, $http->requests);
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    public function testSourceCreationRetainsCollectorAndReportsUnavailableOrWrongOwner(): void
    {
        $http = $this->scenario([
            [201, self::LOGIN],
            [201, '{"id":"source_test","billing_customer_id":"bcus_test","external_id":"method_test"}'],
            [400, '{"code":"resource_not_found"}'],
        ]);
        $sources = $this->client($http)->billing()->paymentSources();
        $source = $sources->create([
            'request_id' => 'link-verified-method', 'billing_customer_id' => 'bcus_test',
            'external_id' => 'method_test', 'linked_payment_account_id' => 'acct_test',
        ]);
        self::assertSame('method_test', $source['external_id']);
        self::assertStringContainsString(
            '"linked_payment_account_id":"acct_test"',
            (string) $http->requests[1]->getBody(),
        );
        try {
            $sources->create([
                'request_id' => 'link-wrong-method', 'billing_customer_id' => 'bcus_test',
                'external_id' => 'method_wrong_account', 'linked_payment_account_id' => 'acct_test',
            ]);
            self::fail('Expected provider-owned authorization/ownership failure.');
        } catch (ApiException $error) {
            self::assertSame('resource_not_found', $error->providerCode());
        }
        self::assertCount(3, $http->requests);
    }

    /** @param Closure(Client):array<array-key,mixed> $call */
    #[DataProvider('invalidRequests')]
    public function testInvalidRequestsFailBeforeAuthentication(Closure $call): void
    {
        $http = $this->scenario([]);
        $this->expectException(ValidationException::class);
        try {
            $call($this->client($http));
        } finally {
            self::assertSame([], $http->requests);
        }
    }
    /** @return iterable<string,array{Closure(Client):array<array-key,mixed>}> */
    public static function invalidRequests(): iterable
    {
        yield 'empty-object-checkout-item' => [
            static fn (Client $c): array => $c->billing()->checkouts()->create([
                'request_id' => 'x', 'mode' => 'PAYMENT', 'line_items' => [new stdClass()],
            ]),
        ];
        yield 'empty-object-subscription-item' => [
            static fn (Client $c): array => $c->billing()->subscriptions()->create([
                'request_id' => 'x', 'billing_customer_id' => 'bcus', 'collection_method' => 'OUT_OF_BAND',
                'items' => [new stdClass()],
            ]),
        ];
        yield 'empty-object-item-update' => [
            static fn (Client $c): array => $c->billing()->subscriptions()->update(
                'sub',
                ['request_id' => 'x', 'items' => [new stdClass()]],
            ),
        ];
        yield 'conflicting-invoice-due-settings' => [
            static fn (Client $c): array => $c->billing()->checkouts()->create([
                'request_id' => 'x', 'mode' => 'PAYMENT', 'line_items' => [['price_id' => 'price']],
                'invoice_data' => ['days_until_due' => 10, 'due_at' => '2028-01-01T00:00:00Z'],
            ]),
        ];
        yield 'nonempty-object-recurrence' => [
            static fn (Client $c): array => $c->billing()->prices()->create([
                'request_id' => 'x', 'currency' => 'EUR', 'product_id' => 'prd', 'unit_amount' => '1',
                'recurring' => (object) ['period_unit' => 'HOUR'],
            ]),
        ];
        yield 'nonempty-object-item' => [
            static fn (Client $c): array => $c->billing()->subscriptions()->create([
                'request_id' => 'x', 'billing_customer_id' => 'bcus', 'collection_method' => 'OUT_OF_BAND',
                'items' => [(object) ['price_id' => 'price', 'quantity' => 1]],
            ]),
        ];
        yield 'null-list-element' => [
            static fn (Client $c): array => $c->billing()->subscriptions()->update(
                'sub',
                ['request_id' => 'x', 'items' => [null]],
            ),
        ];
        yield 'missing-request-id' => [
            static fn(Client $c): array => $c->billing()->customers()->create(['email' => 'buyer@example.test']),
        ];
        yield 'unknown-root-field' => [
            static fn(Client $c): array =>
                $c->billing()->customers()->create(['request_id' => 'x', 'customer_id' => 'payments-customer']),
        ];
        yield 'nested-required-country' => [
            static fn(Client $c): array =>
                $c->billing()->customers()->create(['request_id' => 'x', 'address' => ['city' => 'test']]),
        ];
        yield 'null-required-request' => [
            static fn(Client $c): array => $c->billing()->subscriptions()->update('sub', ['request_id' => null]),
        ];
        yield 'invented-request-field' => [
            static fn(Client $c): array => $c->billing()->customers()->update('customer', ['request_id' => 'x']),
        ];
        yield 'blank-id' => [
            static fn(Client $c): array => $c->billing()->customers()->retrieve(''),
        ];
        yield 'control-id' => [
            static fn(Client $c): array => $c->billing()->customers()->retrieve("id\n"),
        ];
        yield 'unknown-filter' => [
            static fn(Client $c): array => $c->billing()->customers()->list(['page_num' => 1]),
        ];
        yield 'oversized-page' => [
            static fn(Client $c): array => $c->billing()->customers()->list(['page_size' => 1001]),
        ];
        yield 'string-page-size' => [
            static fn(Client $c): array => $c->billing()->customers()->list(['page_size' => '10']),
        ];
        yield 'null-filter' => [
            static fn(Client $c): array => $c->billing()->products()->list(['active' => null]),
        ];
        foreach ([19.9, 19, '-0.01', '10000000000', '1e2'] as $index => $amount) {
            yield 'invalid-amount-' . $index => [
                static fn(Client $c): array => $c->billing()->prices()->create(
                    [
                        'request_id' => 'x',
                        'currency' => 'EUR',
                        'product_id' => 'prd',
                        'unit_amount' => $amount,
                    ]
                ),
            ];
        }
        yield 'immutable-price' => [
            static fn(Client $c): array => $c->billing()->prices()->update('price', ['unit_amount' => '2.00']),
        ];
        yield 'missing-model-amount' => [
            static fn(Client $c): array => $c->billing()->prices()->create(
                [
                    'request_id' => 'x',
                    'currency' => 'EUR',
                    'product_id' => 'prd',
                    'pricing_model' => 'FLAT',
                ]
            ),
        ];
        yield 'unknown-recurrence' => [
            static fn(Client $c): array => $c->billing()->prices()->create(
                [
                    'request_id' => 'x',
                    'currency' => 'EUR',
                    'product_id' => 'prd',
                    'unit_amount' => '1',
                    'recurring' => ['period_unit' => 'HOUR'],
                ]
            ),
        ];
        yield 'setup-missing-customer-currency' => [
            static fn(Client $c): array => $c->billing()->checkouts()->create(['request_id' => 'x', 'mode' => 'SETUP']),
        ];
        yield 'setup-line-items' => [
            static fn(Client $c): array => $c->billing()->checkouts()->create(
                [
                    'request_id' => 'x',
                    'mode' => 'SETUP',
                    'currency' => 'EUR',
                    'billing_customer_id' => 'bcus',
                    'line_items' => [],
                ]
            ),
        ];
        yield 'payment-missing-items' => [
            static fn(Client $c): array =>
                $c->billing()->checkouts()->create(['request_id' => 'x', 'mode' => 'PAYMENT']),
        ];
        yield 'checkout-immutable-mode' => [
            static fn(Client $c): array => $c->billing()->checkouts()->update('checkout', ['mode' => 'PAYMENT']),
        ];
        yield 'checkout-invalid-url' => [
            static fn(Client $c): array => $c->billing()->checkouts()->create(
                [
                    'request_id' => 'x',
                    'mode' => 'PAYMENT',
                    'line_items' => [['price_id' => 'price']],
                    'success_url' => 'javascript:secret',
                ]
            ),
        ];
        yield 'checkout-invalid-options' => [
            static fn(Client $c): array => $c->billing()->checkouts()->create(
                [
                    'request_id' => 'x',
                    'mode' => 'PAYMENT',
                    'line_items' => [['price_id' => 'price']],
                    'ui_options' => ['custom_button' => true],
                ]
            ),
        ];
        yield 'auto-charge-missing-source' => [
            static fn(Client $c): array => $c->billing()->subscriptions()->create(
                [
                    'request_id' => 'x',
                    'billing_customer_id' => 'bcus',
                    'collection_method' => 'AUTO_CHARGE',
                    'items' => [['price_id' => 'price']],
                ]
            ),
        ];
        yield 'new-item-missing-price' => [
            static fn(Client $c): array =>
                $c->billing()->subscriptions()->update('sub', ['request_id' => 'x', 'items' => [['quantity' => 3]]]),
        ];
        yield 'existing-item-price-change' => [
            static fn(Client $c): array => $c->billing()->subscriptions()->update(
                'sub',
                [
                    'request_id' => 'x',
                    'items' => [['id' => 'item', 'price_id' => 'price']],
                ]
            ),
        ];
        yield 'quantity-zero' => [
            static fn(Client $c): array => $c->billing()->subscriptions()->update(
                'sub',
                [
                    'request_id' => 'x',
                    'items' => [['id' => 'item', 'quantity' => 0]],
                ]
            ),
        ];
        yield 'immutable-billing-entity' => [
            static fn(Client $c): array =>
                $c->billing()->subscriptions()->update('sub', ['request_id' => 'x', 'legal_entity_id' => 'le_other']),
        ];
        yield 'invalid-proration' => [
            static fn(Client $c): array =>
                $c->billing()->subscriptions()->update(
                    'sub',
                    ['request_id' => 'x', 'default_proration_mode' => 'FREE'],
                ),
        ];
        yield 'invalid-billing-action' => [
            static fn(Client $c): array =>
                $c->billing()->subscriptions()->update('sub', ['request_id' => 'x', 'billing_action' => 'RESET']),
        ];
        yield 'invalid-cancel-proration' => [
            static fn(Client $c): array =>
                $c->billing()->subscriptions()->cancel('sub', ['request_id' => 'x', 'proration_behavior' => 'FREE']),
        ];
        yield 'invalid-timestamp' => [
            static fn(Client $c): array => $c->billing()->subscriptions()->update(
                'sub',
                ['request_id' => 'x', 'starts_at' => '2028-02-30T00:00:00Z']
            ),
        ];
        yield 'missing-timezone' => [
            static fn(Client $c): array => $c->billing()->subscriptions()->update(
                'sub',
                ['request_id' => 'x', 'starts_at' => '2028-02-01T00:00:00']
            ),
        ];
        yield 'reversed-trial-range' => [
            static fn(Client $c): array => $c->billing()->subscriptions()->update(
                'sub',
                [
                    'request_id' => 'x',
                    'starts_at' => '2028-02-01T00:00:00.000000002Z',
                    'trial_ends_at' => '2028-02-01T00:00:00.000000001Z',
                ]
            ),
        ];
        yield 'deferred-coupon' => [
            static fn(Client $c): array =>
                $c->billing()->subscriptions()->update('sub', ['request_id' => 'x', 'discounts' => []]),
        ];
        yield 'deferred-tax' => [
            static fn(Client $c): array =>
                $c->billing()->subscriptions()->update('sub', ['request_id' => 'x', 'enable_automatic_tax' => true]),
        ];
    }
    #[DataProvider('providerFailures')]
    public function testProviderStateOwnershipAndPermissionFailuresRemainObservable(int $status, string $code): void
    {
        $http = $this->scenario(
            [
                [201, self::LOGIN],
                [
                    $status,
                    json_encode(
                        ['code' => $code, 'message' => 'fixture-sensitive-detail'],
                        JSON_THROW_ON_ERROR
                    ),
                ],
            ]
        );
        try {
            $this->client($http)->billing()->subscriptions()->update(
                'sub_test',
                [
                    'request_id' => 'recovery',
                    'payment_source_id' => 'source_wrong_owner',
                ]
            );
            self::fail('Expected API failure.');
        } catch (ApiException $error) {
            self::assertSame($status, $error->httpStatus());
            self::assertSame($code, $error->providerCode());
            self::assertStringNotContainsString('fixture-sensitive-detail', (string) $error);
            self::assertCount(2, $http->requests);
        }
    }
    /** @return iterable<string,array{int,string}> */
    public static function providerFailures(): iterable
    {
        yield 'not-found' => [404, 'resource_not_found'];
        yield 'wrong-owner' => [400, 'resource_not_found'];
        yield 'permission-denied' => [403, 'unauthorized'];
        yield 'invalid-current-state' => [400, 'invalid_status_for_operation'];
        yield 'payment-declined' => [400, 'issuer_declined'];
    }
    public function testAmbiguousFinancialMutationDoesNotReplayOrChangeItsIdentity(): void
    {
        $http = $this->scenario([[201, self::LOGIN], [503, '{"code":"internal_error"}']]);
        $this->expectException(ApiException::class);
        try {
            $this->client($http, retryPolicy: new RetryPolicy(maxAttempts: 3))->billing()->subscriptions()->update(
                'sub_test',
                [
                    'request_id' => 'persisted-recovery-id',
                    'payment_source_id' => 'source_replacement',
                ]
            );
        } finally {
            self::assertCount(2, $http->requests);
            self::assertSame(
                '{"request_id":"persisted-recovery-id","payment_source_id":"source_replacement"}',
                (string) $http->requests[1]->getBody()
            );
        }
    }
}
