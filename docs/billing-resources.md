# Billing resource implementation

Use the resource methods below with an organization-authorized client from [initialization](consumer-examples.md#initialization-and-account-contexts). [Interface conventions](consumer-interfaces.md) own payload representation; [Billing boundaries](billing-capabilities.md) own capability and evidence limits. The implementation follows API `2026-08-21` and preserves provider response fields, additional keys and unknown status strings.

## Operations and contract sources

Every route below is under `/api/v1/billing`. All 30 operations independently declare organization ownership, no on-behalf-of support and the corresponding Billing resource read/write permission in the selected provider metadata. A delegated Payments client cannot call Billing. `linked_payment_account_id` selects an eligible collector; it grants no Billing permission or account eligibility.

| Resource accessor | Methods | Official contract / bundled schema |
| --- | --- | --- |
| `customers()` | `create`, `retrieve`, `list`, `update` | [Billing Customers](https://www.airwallex.com/docs/api/billing/billing_customers), [schema](../resources/contracts/billing/billing_customers.json) |
| `products()` | `create`, `retrieve`, `list`, `update` | [Products](https://www.airwallex.com/docs/api/billing/products), [schema](../resources/contracts/billing/products.json) |
| `prices()` | `create`, `retrieve`, `list`, `update` | [Prices](https://www.airwallex.com/docs/api/billing/prices), [schema](../resources/contracts/billing/prices.json) |
| `paymentSources()` | `create`, `retrieve`, `list` | [Payment Sources](https://www.airwallex.com/docs/api/billing/payment_sources), [schema](../resources/contracts/billing/payment_sources.json) |
| `checkouts()` | `create`, `retrieve`, `update`, `cancel` | [Billing Checkouts](https://www.airwallex.com/docs/api/billing/billing_checkouts), [schema](../resources/contracts/billing/billing_checkouts.json) |
| `subscriptions()` | `create`, `retrieve`, `list`, `update`, `cancel`, `retrieveItem`, `listItems` | [Subscriptions](https://www.airwallex.com/docs/api/billing/subscriptions), [schema](../resources/contracts/billing/subscriptions.json) |
| `invoices()` | `retrieve`, `list`, `retrieveLineItem`, `listLineItems` | [Invoices](https://www.airwallex.com/docs/api/billing/invoices), [schema](../resources/contracts/billing/invoices.json) |

`create(array $body): array`, `retrieve(string $id): array`, `list(array $query = []): array` and `update(string $id, array $body): array` follow the shared signatures. Checkout `cancel(string $id): array` sends no request body. Subscription `cancel(string $id, array $body): array` requires the original action's `request_id` and explicit `proration_behavior`.

Subordinate read signatures are provisional under [decision review #61](https://github.com/jojeringnax/airwallex-php-sdk/issues/61): `retrieveItem(string $id, string $itemId): array`, `listItems(string $id, array $query = []): array`, `retrieveLineItem(string $id, string $itemId): array` and `listLineItems(string $id, array $query = []): array`. The parent ID remains explicit, and each ID is encoded as one route segment. Capability coverage is accepted; these newly specified parameter names and method shapes await human review.

The bundled files record source URL, review date **2026-11-08**, selected version, provider specification `airwallex-openapi-2026-08-21.yaml` and its published MD5 identifier `f3dcbacb0453ce7a4808b6e97305dac6`. They contain the accepted operations and response schemas reachable from those operations. They omit provider examples and unrelated operations. Schema shape and permissions were extracted from each official page's `__NEXT_DATA__.props.pageProps.activeCategory`; selected `currentVersion` was checked independently on every page. The MD5 identifies the provider's published specification; it is not a hash of the SDK's transformed JSON files.

`nullable` annotations for one-time Price `recurring`, Subscription create `duration`/`ends_at`, and operation `sdkClearable` flags encode explicit provider prose that is absent from raw schema nullable flags. `sdkPagination` identifies actual list operations. These annotations do not establish account eligibility or undocumented replay behavior.

## Validation and precision

Requests are checked against their operation's required keys, known fields, native types, enum/pattern/length constraints, decimal bounds and documented conditional requirements before authentication or network requests. Unknown request fields are rejected. Metadata maps retain arbitrary string keys and string values; `new stdClass()` represents an empty object. Structured populated request objects use associative arrays; string maps such as metadata also accept `stdClass` so numeric-looking keys such as `"0"` remain JSON object keys. Field-clearing rules do not make list elements nullable. Updates preserve omission, explicit null, false and empty strings as permitted by the documented operation. Customer update does not accept an invented `request_id`; Product/Price update accept its optional documented field; Subscription update requires it.

Flat and per-unit Price amounts use major-unit decimal strings and exact JSON numbers. Recurring Prices use nested `recurring.period_unit` and optional integer `period`; one-time Prices may set `recurring` to null. Price update is limited to `active`, `description`, `metadata` and optional `request_id`. Amount, currency, product and recurrence changes require a separately created Price. Archiving with `active=false` does not change an existing Subscription.

Response numeric types follow resolved schemas. Monetary values and schema `number` fields remain strings with their original numeric text. For example, a Subscription Item's response `quantity` is a schema `number`, although request `quantity` is an integer. Known integer counts and periods become PHP integers when representable; additional numeric fields remain strings. The SDK computes no proration, tax, currency conversion or entitlement state.

Lists return native `items`, `page_after` and `page_before` fields. `page_size` is an integer from 1 to 1000, defaulting at the provider to 20 when omitted. Pass the next bookmark through `page`, retaining original filters and the same organization context. There is no automatic network iteration.

## Lifecycle behavior

Use the [accepted examples](consumer-examples.md) for customer/source linkage, checkout, provider trials, changes, cancellation, recovery and invoice access. Deterministic tests execute these Billing-shaped calls with sanitized synthetic HTTP responses; they are not provider delivery or sandbox evidence. Runnable placeholder recipes return a callable for an explicitly configured client: [monthly and per-seat catalogue](../examples/billing-catalogue.php), [customer profile and hosted signup](../examples/billing-customer-checkout.php), and [hosted setup completion reconciliation](../examples/billing-reconcile-setup.php). The deterministic example tests load and execute these exact files. Replace every placeholder and durably associate request IDs before provider use.

- Payment Sources use the authorized Payment Method ID as `external_id`, plus the Billing Customer and eligible collection account. The application verifies buyer, Consent/Method and account mappings. The SDK cannot infer ownership or authorization from opaque IDs. No source update, deletion or default-management method is exposed.
- Checkout supports applicable `PAYMENT`, `SUBSCRIPTION` and `SETUP` requests with `HOSTED`, `EMBEDDED` or `ELEMENTS` UI modes. Setup requires currency and an existing Billing Customer and omits line items. Subscription/Payment modes require their applicable items; inline Prices are limited to Payment checkout. Update changes metadata only; cancel is bodyless. Return conditional URL/client-secret fields unchanged, keep browser secrets out of logs, and reconcile completion with retrieval/events. Expiry is one hour; an expired checkout requires a new consumer action and request identity.
- Subscription create requires the Billing Customer, collection method, items and request ID; `AUTO_CHARGE` requires a source. Examples supply legal entity and linked account explicitly. Price billing frequencies must be compatible according to the provider; the SDK does not issue hidden reads to infer them. `duration` is the total billing lifetime, while recurrence comes from Prices.
- Timestamp requests retain timezone and fractional text. Start/trial range checks compare supplied timestamps without converting their decimal fractions to floats. `NOW` is accepted for documented update start/trial fields. Current-state restrictions are enforced by the provider: start updates require `PENDING`; trial-end changes require `PENDING` or `IN_TRIAL`. The SDK neither retrieves hidden state nor guarantees that a state observed earlier remains current.
- Subscription item changes retain omitted existing items. Existing IDs change quantity/metadata or delete an item; a replacement Price removes the old item and adds another. `default_proration_mode` and per-item `proration_mode` accept `NONE`, `PRORATED` and `ALL`. Billing actions explicitly defer adjustment, charge immediately while keeping the cycle, or charge and reset the cycle. Cancellation's separate `proration_behavior` is always explicit.
- Scheduled ending exposes documented update fields, including `cancel_at_period_end`, `ends_at`, `duration` and `remaining_duration`. Setting a flag to false is representable; it is not a guarantee that every schedule can be reversed in every state. Permanent cancellation, outstanding invoices and application access policy remain separate concerns. There are no pause, resume or cancelled-reactivation helpers.
- Changing `payment_source_id` is an explicit financial action: the selected contract says it immediately retries the last failed invoice. Retrieve resulting Subscription/Invoice state and handle provider rejection. HTTP retries never replay these mutations. Provider dunning and application entitlement decisions remain outside SDK scheduling.
- Invoice and line-item reads retain exact amounts and nullable PDF/hosted links. The SDK returns a supplied expired link unchanged and performs no download or renewal. `PAID` reports provider invoice reconciliation and does not independently prove cash settlement. Buyer authorization, delivery, storage and stale-link recovery belong to the application. Invoice mutations and preview remain outside accepted coverage.

The provisional [Price-model boundary](planning.md#provisional-price-model-boundary) limits creation to `FLAT` and `PER_UNIT`; [decision review #64](https://github.com/jojeringnax/airwallex-php-sdk/issues/64) remains open for non-metered tiered models. Coupon, automatic/custom tax, metered pricing and Rate Card request controls remain deferred and are rejected locally. Provider response fields concerning those features remain available for inspection. Basic customer tax-identification data and native Price tax-inclusion metadata do not add tax calculation APIs.

## Choosing subscription financial controls

Omitting `default_proration_mode` leaves the provider default `PRORATED`. Omitting `billing_action` leaves `DEFER_CHARGE_AND_KEEP_CYCLE`. The SDK preserves omission and does not insert either default. A per-item `proration_mode` overrides the default item proration; cancellation uses its separate required `proration_behavior`.

For an item/quantity update, choose one explicit `billing_action` addition to the usual `request_id`/`items` body:

| Payload addition | Financial effect |
| --- | --- |
| `['billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE']` | Apply prorated in-advance adjustments on the upcoming invoice; retain the current cycle. This is the provider default. |
| `['billing_action' => 'IMMEDIATE_CHARGE_AND_KEEP_CYCLE']` | Generate and attempt payment of an adjustment invoice immediately; retain the current cycle. |
| `['billing_action' => 'IMMEDIATE_CHARGE_AND_RESET_CYCLE']` | Reset the billing anchor to the update time and immediately invoice prorations; subsequent cycles use the new anchor. |

For example, an explicit immediate adjustment retaining the cycle is:

```php
$changed = $client->billing()->subscriptions()->update('<subscription-id>', [
    'request_id' => '<persisted-quantity-change-request-id>',
    'items' => [['id' => '<existing-item-id>', 'quantity' => 3]],
    'default_proration_mode' => 'PRORATED',
    'billing_action' => 'IMMEDIATE_CHARGE_AND_KEEP_CYCLE',
]);
```

The deterministic financial-control tests exercise all nine mode/action combinations. They establish exact serialization, not provider-calculated amounts or successful collection; the selected schema in the [Subscriptions contract](../resources/contracts/billing/subscriptions.json) owns those enum descriptions and defaults.

## Conflicts and remaining evidence

The selected API Checkout schema uses `ACTIVE`, while the current hosted product guide uses `OPEN`; responses preserve either string without normalization. The Subscription product guide identifies billing entity as immutable even though the selected update schema includes `legal_entity_id`; the SDK rejects that update pending provider clarification. The product guide also describes `duration` as frequency in places, whereas the selected schema defines lifetime. The implementation follows the schema's lifetime definition and Prices for recurrence.

[Billing capability gates](billing-capabilities.md#delivery-and-evidence-gates) retain schedule reversal/clearing and invoice-link validity/refresh uncertainty. The current [dunning guide](https://www.airwallex.com/docs/billing/subscriptions/dunning) describes cycle-dependent retry timing; no universal retry schedule is encoded. Actual source-change collection, state restrictions, method-specific source linkage, permissions and account configurations require opt-in sandbox/provider evidence in [#17](https://github.com/jojeringnax/airwallex-php-sdk/issues/17) and [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42).

The [connected-account evidence](connected-account-contracts.md#evidence-still-needed) remains a prerequisite for claiming the connected-merchant Billing bridge and both complete two-account journeys. No design assumption or deterministic fixture establishes organization authorization, product activation or eligible collection-account linkage. These implementation tests do not claim those release gates have passed.
