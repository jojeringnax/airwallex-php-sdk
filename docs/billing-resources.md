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

Subordinate reads follow the [accepted signatures](consumer-interfaces.md#subordinate-reads). The parent ID remains explicit, and each ID is encoded as one route segment. The [item-read recipe](../examples/billing-item-reads.php) executes individual reads and first pages; fetch another page explicitly with the same parent and original query settings.

The four subordinate GET routes, required parent/item path parameters, `page`/`page_size` queries and independent organization read permissions were rechecked on **2026-11-09** against the official Subscription and Invoice sources above, each reporting API `2026-08-21`. These lists expose no additional filters in the selected contract. This contract check does not establish account eligibility or Sandbox journey completion.

The bundled files record source URL, review date **2026-11-08**, selected version, provider specification `airwallex-openapi-2026-08-21.yaml` and its published MD5 identifier `f3dcbacb0453ce7a4808b6e97305dac6`. They contain the accepted operations and response schemas reachable from those operations. They omit provider examples and unrelated operations. Schema shape and permissions were extracted from each official page's `__NEXT_DATA__.props.pageProps.activeCategory`; selected `currentVersion` was checked independently on every page. The MD5 identifies the provider's published specification; it is not a hash of the SDK's transformed JSON files. Subscription Update's three added request fragments have separate **2026-11-09** `request_updates` provenance; [API provenance](api-contracts.md#selected-version-evidence) distinguishes the later page advertisement from the original snapshot.

`nullable` annotations for one-time Price `recurring`, Subscription create `duration`/`ends_at`, and operation `sdkClearable` flags encode explicit provider prose that is absent from raw schema nullable flags. `sdkPagination` identifies actual list operations. These annotations do not establish account eligibility or undocumented replay behavior.

## Validation and precision

Requests are checked against their operation's required keys, known fields, native types, enum/pattern/length constraints, decimal bounds and documented conditional requirements before authentication or network requests. Unknown request fields are rejected. Metadata maps retain arbitrary string keys and string values; `new stdClass()` represents an empty object. Structured populated request objects use associative arrays; string maps such as metadata also accept `stdClass` so numeric-looking keys such as `"0"` remain JSON object keys. Field-clearing rules do not make list elements nullable. Updates preserve omission, explicit null, false and empty strings as permitted by the documented operation. Customer update does not accept an invented `request_id`; Product/Price update accept its optional documented field; Subscription update requires it.

Price amounts and tier upper bounds use decimal strings and exact JSON numbers; monetary amounts are in major units. Recurring Prices use nested `recurring.period_unit` and optional integer `period`; one-time Prices may set `recurring` to null. Price update is limited to `active`, `description`, `metadata` and optional `request_id`. Amount, currency, product and recurrence changes require a separately created Price. Archiving with `active=false` does not change an existing Subscription.

Response numeric types follow resolved schemas. Monetary values and schema `number` fields remain strings with their original numeric text. For example, a Subscription Item's response `quantity` is a schema `number`, although request `quantity` is an integer. Known integer counts and periods become PHP integers when representable; additional numeric fields remain strings. The SDK computes no proration, tax, currency conversion or entitlement state.

Lists return native `items`, `page_after` and `page_before` fields. `page_size` is an integer from 1 to 1000, defaulting at the provider to 20 when omitted. Pass the next bookmark through `page`, retaining original filters and the same organization context. There is no automatic network iteration.

## Price models and tiers

Creation accepts nonmetered `FLAT`, `PER_UNIT`, `VOLUME` and `GRADUATED` Prices under the [accepted coverage](planning.md#price-model-coverage). `PER_UNIT` remains the omitted-model default. `FLAT` requires `flat_amount`; `PER_UNIT` requires `unit_amount`. `VOLUME` applies the matching tier's rate to the entire quantity; `GRADUATED` applies each tier's rate to its portion. The SDK submits the schedule without calculating charges or selecting tiers.

For either tiered model, supply a nonempty `tiers` list in ascending quantity order. Tier `flat_amount` and `unit_amount` are optional nonnegative decimals; both may be supplied, including zero. The selected schema states no at-least-one condition, so the SDK preserves omission without inventing amount defaults. Every tier except the last requires a positive `upper_bound` greater than the preceding bound. A bound includes that quantity; the next tier starts above it. Omit `upper_bound` on the final tier to leave it unbounded; null, empty strings and infinity markers are rejected. Comparisons preserve arbitrary decimal precision. Supply all schema `number` values as decimal strings, including upper bounds; tier response `level` is an integer assigned by Airwallex and is not a create input.

Tier package pricing uses `tiers[].lot`, with integer `size` from 2 through 2147483647 and `rounding_mode=UP`; the unit amount applies per lot. The top-level `lot` is for per-unit pricing and is rejected for tiered creation. `tiers` is rejected for `FLAT` / `PER_UNIT`. Omit `metered` or pass `false`; `true` and `meter_id` remain outside accepted usage-billing coverage. Recurring and one-time representation follows the shared rules above. The [catalogue recipe](../examples/billing-catalogue.php) demonstrates monthly Volume and one-time Graduated schedules alongside flat/per-unit Prices. Inline Checkout/Subscription Price schemas retain their flat/per-unit boundary; create tiered Prices with `prices()->create()` and reference their IDs through `price_id`.

Price Update exposes no tier, model, lot, amount or recurrence changes: create a new Price for a new schedule, then use an explicit consumer action to reference it. Existing tiered Prices can still be retrieved, listed and archived; the SDK preserves provider fields and unknown model values. The [product UI guide](https://www.airwallex.com/docs/billing/billing-components/prices) describes broader editing before use, but that does not add fields to the selected Update API.

Verified on **2026-11-09** against [Price Create](https://www.airwallex.com/docs/api/billing/prices/create), [Price Update](https://www.airwallex.com/docs/api/billing/prices/update), the [pricing-model/package guide](https://www.airwallex.com/docs/billing/billing-components/prices) and [Prices via API](https://www.airwallex.com/docs/billing/billing-components/prices/prices-via-api). The official Price page selected `2026-08-21` and specification `airwallex-openapi-2026-08-21.yaml`, advertising MD5 `2ba41cf5fc310712213aad98078c85be`. All four operation request/response schemas, routes and permission metadata and all five reachable resource schemas match the retained snapshot after accounting for SDK annotations. Organization-level `billing.price:read` / `billing.price:write` and no OBO support remain unchanged. The snapshot retains its original provenance; this comparison establishes neither whole-specification equality nor sandbox acceptance. Deterministic tests cover tier validation, precise wire/response values, immutable updates and provider failure without mutation replay. Provider-calculated totals and account eligibility remain separate integration evidence.

## Lifecycle behavior

Use the [accepted examples](consumer-examples.md) for customer/source linkage, checkout, provider trials, changes, cancellation, recovery and invoice access. Deterministic tests execute these Billing-shaped calls with sanitized synthetic HTTP responses; they are not provider delivery or sandbox evidence. Runnable placeholder recipes return a callable for an explicitly configured client: [flat, per-unit and tiered catalogue](../examples/billing-catalogue.php), [customer profile and hosted signup](../examples/billing-customer-checkout.php), and [hosted setup completion reconciliation](../examples/billing-reconcile-setup.php). The deterministic example tests load and execute these exact files. Replace every placeholder and durably associate request IDs before provider use.

- Payment Sources use the authorized Payment Method ID as `external_id`, plus the Billing Customer and eligible collection account. The application verifies buyer, Consent/Method and account mappings. The SDK cannot infer ownership or authorization from opaque IDs. No source update, deletion or default-management method is exposed.
- Checkout supports applicable `PAYMENT`, `SUBSCRIPTION` and `SETUP` requests with `HOSTED`, `EMBEDDED` or `ELEMENTS` UI modes. Setup requires currency and an existing Billing Customer and omits line items. Subscription/Payment modes require their applicable items; inline Prices are limited to Payment checkout. Update changes metadata only; cancel is bodyless. Return conditional URL/client-secret fields unchanged, keep browser secrets out of logs, and reconcile completion with retrieval/events. The [selected expiry description and conflicting Sandbox timestamps](billing-capabilities.md#checkout-ui-contracts) do not establish a guaranteed lifetime. Inspect returned dates and current status; an observed expired checkout requires a new consumer action and request identity.
- Subscription create requires the Billing Customer, collection method, items and request ID; `AUTO_CHARGE` requires a source. Examples supply legal entity and linked account explicitly. Price billing frequencies must be compatible according to the provider; the SDK does not issue hidden reads to infer them. `duration` is the total billing lifetime, while recurrence comes from Prices.
- Timestamp requests retain timezone and fractional text. Start/trial range checks compare supplied timestamps without converting their decimal fractions to floats. `NOW` is accepted for documented update start/trial fields. Current-state restrictions are enforced by the provider: start updates require `PENDING`; trial-end changes require `PENDING` or `IN_TRIAL`. The SDK neither retrieves hidden state nor guarantees that a state observed earlier remains current.
- Subscription item changes retain omitted existing items. Existing IDs request quantity/metadata changes or deletion; a Price replacement submits deletion plus a new item. Refresh and reconcile returned item references before a later mutation; ID retention is not guaranteed. `default_proration_mode` accepts `NONE`, `PRORATED` and `ALL`; per-item `proration_mode` also accepts `CUSTOM` under the [conditional rules below](#choosing-subscription-financial-controls). Billing actions explicitly defer adjustment, charge immediately while keeping the cycle, or charge and reset the cycle. Cancellation's separate `proration_behavior` is always explicit.
- Scheduled ending exposes documented update fields, including `cancel_at_period_end`, `ends_at`, `duration` and `remaining_duration`. Setting a flag to false is representable; it is not a guarantee that every schedule can be reversed in every state. Permanent cancellation, outstanding invoices and application access policy remain separate concerns. There are no pause, resume or cancelled-reactivation helpers.
- Changing `payment_source_id` is an explicit financial action: the selected contract says it immediately retries the last failed invoice. Retrieve resulting Subscription/Invoice state and handle provider rejection. HTTP retries never replay these mutations. Provider dunning and application entitlement decisions remain outside SDK scheduling.
- Invoice and line-item reads retain exact amounts and nullable PDF/hosted links. The SDK returns a supplied expired link unchanged and performs no download or renewal. `PAID` reports provider invoice reconciliation and does not independently prove cash settlement. Buyer authorization, delivery, storage and stale-link recovery belong to the application. Invoice mutations and preview remain outside accepted coverage.

Coupon, automatic/custom tax, metered pricing and Rate Card request controls remain deferred and are rejected locally. Provider response fields concerning those features remain available for inspection. Basic customer tax-identification data and native Price tax-inclusion metadata do not add tax calculation APIs.

## Choosing subscription financial controls

Omitting `default_proration_mode` leaves the provider default `PRORATED`. Omitting `billing_action` leaves `DEFER_CHARGE_AND_KEEP_CYCLE`. The SDK preserves omission and does not insert either default. A per-item `proration_mode` overrides the default item proration; cancellation uses its separate required `proration_behavior`.

Per-item `proration_mode='CUSTOM'` requires `proration_value` with at least one of `charge_amount` or `credit_amount`; both are allowed. Every supplied amount must be a plain base-10 decimal string, such as `'100.00'`, and remains an exact **JSON string** in the Subscription's billing currency. Empty objects, null/empty amounts, floats, integers, exponent notation, unknown keys and a value object on any non-CUSTOM or omitted mode are rejected before authentication. The selected schema defines no sign, magnitude or currency-scale bounds for these strings; additional financial restrictions remain provider-owned. The application chooses the amounts; the SDK calculates no charge or credit. `CUSTOM` is available only per item, leaving global default and cancellation modes unchanged.

Optional `proration_date` selects the timestamp for proration calculations and must fall within the current billing period. Pass a timezone-bearing date-time string; the SDK validates syntax/calendar dates and preserves offset and fractional text exactly. Omit the key for normal provider calculation rules. Explicit null retains the operation's general field-clearing representation; blank strings and `NOW` fail date-time validation. The SDK performs no hidden Subscription or Invoice Preview read to check the period or state, and this timestamp does not schedule the Subscription change. It can accompany an update independently of CUSTOM, including a mix of automatic and custom item modes.

These controls follow the [accepted scope](planning.md#subscription-proration-controls) and the selected API `2026-08-21` [contract review](api-contracts.md#selected-version-evidence). The [runnable proration recipes](../examples/billing-proration.php) show an immediate custom adjustment with both amounts and an independently dated automatic adjustment. Authorize the buyer, reconcile the owned Subscription/Item references and durably store each action's request ID before use. For example, an application-chosen adjustment is:

```php
$changed = $client->billing()->subscriptions()->update('<subscription-id>', [
    'request_id' => '<persisted-custom-adjustment-request-id>',
    'items' => [[
        'id' => '<existing-item-id>',
        'quantity' => 3,
        'proration_mode' => 'CUSTOM',
        'proration_value' => ['charge_amount' => '100.00', 'credit_amount' => '20.00'],
    ]],
    'billing_action' => 'IMMEDIATE_CHARGE_AND_KEEP_CYCLE',
]);
```

For an automatic adjustment at an application-selected time, use ordinary item modes and add `'proration_date' => '<date-time-within-current-billing-period>'` to that action's body, omitting `proration_value`. The SDK surfaces provider input/state failures through `ApiException`; a successful update does not prove collection or settlement. Reconcile an ambiguous result through explicit reads before another deliberate action, retaining the original action identity.

For `NONE`, the selected enum description defers the new fee to the next cycle; the [product guide](https://www.airwallex.com/docs/billing/subscriptions/subscriptions-via-api) explicitly describes next-cycle effectiveness. A successful request or newly listed target item does not prove immediate old-item removal. `SubscriptionItem` has no documented active/ended/deleted/effective-date field, and `current_period_starts_at`/`current_period_ends_at` describe invoiced periods. Item lists therefore provide no universal current-item selector. Preserve uncertainty about prior/target membership, future effectiveness and financial outcomes rather than deriving them from array order or timestamps.

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

The deterministic financial-control tests retain exact bytes for the original nine mode/action combinations and cover CUSTOM amount shapes across all three billing actions, mixed modes, timestamp precision, pre-authentication validation and provider failures without replay. Source and archive consumers also exercise the added controls. These checks establish serialization, not provider-calculated amounts or successful collection; the selected schema in the [Subscriptions contract](../resources/contracts/billing/subscriptions.json) owns those enum descriptions and defaults.

A [selected-mode Sandbox run](https://github.com/jojeringnax/airwallex-php-sdk/issues/42#issuecomment-6722981620) observed a `PRORATED`/deferred quantity change with its cycle anchor retained, and a `NONE`/deferred replacement target listed with the prior entry and retained anchor. Next-cycle effectiveness, old-item removal, provider-calculated prorations, CUSTOM financial effects, calculation-time override effects, immediate-charge/reset outcomes and funds movement remain unverified. Integration observations are recorded under [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42).

## Conflicts and remaining evidence

The selected API Checkout schema uses `ACTIVE`, while the current hosted product guide uses `OPEN`; responses preserve either string without normalization. The Subscription product guide identifies billing entity as immutable even though the selected update schema includes `legal_entity_id`; the SDK rejects that update pending provider clarification. The product guide also describes `duration` as frequency in places, whereas the selected schema defines lifetime. The implementation follows the schema's lifetime definition and Prices for recurrence.

[Billing capability gates](billing-capabilities.md#delivery-and-evidence-gates) retain schedule reversal/clearing and invoice-link validity/refresh uncertainty. The current [dunning guide](https://www.airwallex.com/docs/billing/subscriptions/dunning) describes cycle-dependent retry timing; no universal retry schedule is encoded. Actual source-change collection, state restrictions, method-specific source linkage, permissions and account configurations require opt-in sandbox/provider evidence in [#17](https://github.com/jojeringnax/airwallex-php-sdk/issues/17) and [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42).

The [connected-account evidence](connected-account-contracts.md#evidence-still-needed) remains a prerequisite for claiming the connected-merchant Billing bridge and both complete two-account journeys. No design assumption or deterministic fixture establishes organization authorization, product activation or eligible collection-account linkage. These implementation tests do not claim those release gates have passed.
