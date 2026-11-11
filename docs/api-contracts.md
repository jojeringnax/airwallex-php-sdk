# API contract and permission inventory

Contract research for [issue #3](https://github.com/jojeringnax/airwallex-php-sdk/issues/3), reviewed on **2026-11-08** against official Airwallex API and product documentation. Read this reference before implementing authentication, resource endpoints, retries or webhook contracts. Capability scope is owned by [planning](planning.md).

## Version baseline

The SDK targets API date-version **`2026-08-21`**, with explicit `x-api-version` on SDK API requests and the same version configured on webhook subscriptions. This gives consumers a reproducible contract across accounts with different defaults. The resource references below were verified against that selected documentation version; sandbox compatibility remains a separate validation step.

The [versioning reference](https://www.airwallex.com/docs/api/versioning) lists `2026-08-21` as latest. Without an override, calls use the account's configured version. The header overrides that default per call; Airwallex recommends it for exceptional cases such as migration and on-behalf-of calls. The `/v1` URL segment is separate from the date-version. The SDK uses an explicit pin to keep its contract stable across account defaults; consumers configure webhook subscriptions to match.

Account upgrades do not implicitly upgrade the SDK contract. Review changelog/schema differences before changing the pin. The accepted [consumer interfaces](consumer-interfaces.md#interface-conventions) tolerate additional response fields and preserve unknown provider values. Regulatory or payment-method requirements can still change across versions. SDK release compatibility remains governed by [architecture](architecture.md#compatibility-policy).

## Selected-version evidence

On 2026-11-08, the HTML of all 13 API resource pages in the inventory, plus the [authentication](https://www.airwallex.com/docs/api/authentication/api_access_token), [versioning](https://www.airwallex.com/docs/api/versioning), [changelog](https://www.airwallex.com/docs/api/changelog) and [Webhooks API](https://www.airwallex.com/docs/api/webhooks/webhooks) pages, was checked directly. Each page had a selected `<option value="2026-08-21">` and `__NEXT_DATA__.props.pageProps.currentVersion` equal to `2026-08-21`. The metadata identifies the source specification as `airwallex-openapi-2026-08-21.yaml`, with published `md5` identifier `f3dcbacb0453ce7a4808b6e97305dac6` in `versionItems`.

The same page metadata contains `activeCategory.endpoints`; the inventory operations and representative required fields, response schema references, permissions and resource levels were cross-checked there. The URLs currently default to this version and may change later: on re-verification, check the selected option and `currentVersion` rather than assuming the URL still identifies the baseline. Product guides provide supplemental behavior and are not independently date-versioned.

On **2026-11-09**, fresh official-page metadata still selected `2026-08-21` and the same specification filename, with advertised MD5 `c75b7f73321b6952731bfa2b7eab5748`. Parsed `activeCategory` and environment-domain objects from all 13 resource pages matched the retained original page data: 76 category operations, 371 category-local schema entries and all 55 operations selected by the runtime contracts, including their route and authorization metadata. Only `versionItems` changed in those page objects; its 48 advertised versions retained their names/paths and changed MD5 values. The normalized runtime snapshots keep their original source attribution and local annotations; no SDK repin follows from this comparison.

This comparison does not cover the complete YAML, standalone authentication or Webhooks-management page data, provider runtime behavior or the reason for the checksum change. The original complete YAML was not established by this audit. Reverify the relevant selected contract before relying on an affected operation; a shared date-version or checksum change alone does not establish either whole-specification equality or a changed endpoint contract.

A later targeted comparison on **2026-11-09** found Subscription and Invoice pages selecting the same `2026-08-21` specification filename while advertising MD5 `2ba41cf5fc310712213aad98078c85be`. Their raw categories differ from the retained original subjects: Subscription Update documents per-item `CUSTOM` / `proration_value` and a separate `proration_date`; unselected Invoice Preview inputs/descriptions/errors/examples also differ, with `total_tax_amount` added to its response required list. The compared Subscription create/retrieve/cancel/Item-read contracts and Invoice Retrieve schema are unchanged. This is a limited operation/schema comparison, not whole-category equality, a downloaded-YAML hash, a runtime compatibility verdict or an SDK repin. The earlier 13-page `c75…` comparison remains tied to its recorded subjects. [Accepted scope #127](planning.md#subscription-proration-controls) includes both Subscription additions; Preview remains outside accepted Invoice-read coverage.

The **2026-11-09** implementation review rechecked Subscription Update's `currentVersion=2026-08-21`, the `2ba41cf5fc310712213aad98078c85be` page advertisement and request fragments directly in `activeCategory`. Only `items.proration_mode`, `items.proration_value` and `proration_date` were incorporated into the bundled [Subscription contract](../resources/contracts/billing/subscriptions.json), recorded under `request_updates`. The original `reviewed`/`provider_md5` attribution continues to identify the original snapshot; it does not attribute the new fragments to that earlier hash. Update independently retains organization ownership, `billing.subscription:write` with API-key support and `x-oboSupported=false`. Custom amounts are schema strings; the SDK checks plain decimal syntax and the described CUSTOM-only/at-least-one conditions without adding currency, sign or scale limits. `proration_date` is schema `string` / `date-time`; current-period restrictions stay provider-enforced. [Financial controls](billing-resources.md#choosing-subscription-financial-controls) owns consumer rules and examples. No API-version change or Invoice Preview operation is included.

## Authorization and ownership

The **2026-11-11** [adoption timing review](billing-capabilities.md#adoption-timing-and-restoration-contracts) checked fresh selected Subscription metadata against v0.1.1 for the required timing/restoration fields. It records the exact page advertisement, compared subjects and remaining state/collection guarantees without repinning the API or changing runtime request schemas.

The [API-key guide](https://www.airwallex.com/docs/developer-tools/api/manage-api-keys) independently identifies Billing as organization-level and Payments as account-level. Scoped keys may grant both. Resource Read permissions cover GET operations; Write includes reads and mutations. These are API-key permission labels, not OAuth scope strings.

| Key configuration | Login account selection | Token access |
| --- | --- | --- |
| Organization permissions only | Omit `x-login-as` | Organization resources |
| One permitted account | Optional `x-login-as` | That account, plus any organization permissions |
| Multiple permitted accounts | Supply target account ID in `x-login-as` for account access | Selected account, plus any organization permissions |
| Multiple accounts, header omitted | No account selected | Organization permissions only, or none for an account-only key |

The [authentication contract](https://www.airwallex.com/docs/api/authentication/api_access_token) specifies `POST /api/v1/authentication/login` with `x-client-id`, `x-api-key` and optional `x-login-as`; success is 201 with `token` and ISO8601 `expires_at`. Subsequent calls use bearer authorization. Reuse the token until its returned expiration. Login documents 400, 401 (`credentials_invalid`, `credentials_expired`), 403, 429 and 500 failures. Environment, credentials and applicable account context must partition token reuse as required by the [architecture](architecture.md#dependencies-and-transport).

The [connected-account reference](https://www.airwallex.com/docs/api/connected_accounts) describes platform credentials with `x-on-behalf-of` on resource requests. This differs from selecting an account during login. Its example is a balance read; it does not establish Billing organization ownership or every Payments operation's eligibility. Selected-version endpoint metadata independently confirms Billing Customer create/retrieve as `x-resourceLevel=org`, `x-oboSupported=false`, with `billing.customer:write`/`billing.customer:read`; Intent create/retrieve are `account`, `x-oboSupported=true`, with `payment_acceptance:write`/`payment_acceptance:read`. These are provider metadata identifiers; authentication modes and public interfaces still follow their own decisions. See [connected-account contracts](connected-account-contracts.md) for endpoint-wide context verification, ownership, entitlements, two-account scenarios and unresolved Billing authorization.

## Resource inventory

All rows were reviewed on 2026-11-08 with documentation version `2026-08-21` selected. The operations listed are present in that version; full request/response and failure-path fixtures remain part of endpoint implementation. Operations below identify the accepted workflows and supporting resources; provider operations outside those workflows do not expand SDK scope.

Permission labels come from the [API-key scope catalogue](https://www.airwallex.com/docs/developer-tools/api/api-key-scopes). Billing uses each named resource permission at organization level; the Payments rows use **Payment Acceptance** at account level. Request bodies and error cases still need endpoint-level fixtures before implementation.

| Resource / permission | Operations for accepted flows | Endpoint reference | Product reference |
| --- | --- | --- | --- |
| Billing Customers | Create, retrieve, list, update | [API](https://www.airwallex.com/docs/api/billing/billing_customers) | [Customers](https://www.airwallex.com/docs/billing/billing-components/customers) |
| Products | Create, retrieve, list, update | [API](https://www.airwallex.com/docs/api/billing/products) | [Products](https://www.airwallex.com/docs/billing/billing-components/products) |
| Prices | Create, retrieve, list, update; nonmetered flat/per-unit/volume/graduated prices | [API](https://www.airwallex.com/docs/api/billing/prices) | [Prices](https://www.airwallex.com/docs/billing/billing-components/prices) |
| Payment Sources | Create, retrieve, list | [API](https://www.airwallex.com/docs/api/billing/payment_sources) | [Payment sources](https://www.airwallex.com/docs/billing/billing-components/payment-sources) |
| Billing Checkouts | Create, retrieve, update, cancel; PAYMENT, SUBSCRIPTION, SETUP | [API](https://www.airwallex.com/docs/api/billing/billing_checkouts) | [Hosted checkout](https://www.airwallex.com/docs/billing/billing-components/checkout/hosted-billing-checkout) |
| Subscriptions | Create, retrieve, list, item reads, update, cancel | [API](https://www.airwallex.com/docs/api/billing/subscriptions) | [Subscriptions via API](https://www.airwallex.com/docs/billing/subscriptions/subscriptions-via-api) |
| Invoices | Retrieve/list invoices and line items; document-link evidence and scope in [Billing capability boundaries](billing-capabilities.md#invoice-access-and-delivery) | [API](https://www.airwallex.com/docs/api/billing/invoices) | [Invoices via API](https://www.airwallex.com/docs/billing/invoicing/invoices-via-api) |
| Payments Customers | Create, retrieve, list, update to support saved instruments | [API](https://www.airwallex.com/docs/api/payments/customers) | [Payments data model](https://www.airwallex.com/docs/payments/about-airwallex-payments/payments-data-model) |
| Payment Consents | Create, retrieve/list, verify/continue verification, update, disable | [API](https://www.airwallex.com/docs/api/payments/payment_consents) | [Payments data model](https://www.airwallex.com/docs/payments/about-airwallex-payments/payments-data-model) |
| Payment Methods | Retrieve/list saved instrument details | [API](https://www.airwallex.com/docs/api/payments/payment_methods) | [Payments data model](https://www.airwallex.com/docs/payments/about-airwallex-payments/payments-data-model) |
| Payment Intents | Create, retrieve/list, confirm/continue confirmation, capture, cancel | [API](https://www.airwallex.com/docs/api/payments/payment_intents) | [PaymentIntents](https://www.airwallex.com/docs/payments/get-started/using-payments-intent-api) |
| Payment Attempts | Retrieve/list payment status details | [API](https://www.airwallex.com/docs/api/payments/payment_attempts) | [Payments data model](https://www.airwallex.com/docs/payments/about-airwallex-payments/payments-data-model) |
| Refunds | Create, retrieve/list | [API](https://www.airwallex.com/docs/api/payments/refunds) | [Refunds](https://www.airwallex.com/docs/payments/payment-operations/manage-payments/refunds) |

The [adoption extension](planning.md#adoption-extension) accepts read-only Billing Transactions; its selected-version permission, schema provenance and allocation limits are in [Billing resources](billing-resources.md#billing-transactions). Credit Note writes, coupons, tax and usage resources retain their separate scope decisions; their presence in provider documentation does not authorize implementation.

For implemented Billing routes, request validation, source snapshots and remaining runtime evidence, see [Billing resources](billing-resources.md).

For implemented Payments resource operations, method-specific request conditions, replay evidence and remaining runtime gates, see [Payments resource contracts](payments-contracts.md).

## Representative contract checks

Paths below are relative to `/api/v1`. These checks compare documented reads and mutations independently for Billing and Payments at `2026-08-21`; no sandbox API requests were executed. Billing Customer create/retrieve reference the same `CustomerResponse` schema; Intent create/retrieve reference `PaymentIntentSingle`. Login references `Token` and retains the required credential headers and optional `x-login-as` described above.

| Family | Mutation checked | Read checked | Important contract differences |
| --- | --- | --- | --- |
| Billing | [POST `billing/billing_customers/create`](https://www.airwallex.com/docs/api/billing/billing_customers/create): required `request_id`, 201 resource response | [GET `billing/billing_customers/{id}`](https://www.airwallex.com/docs/api/billing/billing_customers/retrieve): 200 resource response | Customer fields include `default_legal_entity_id` and billing currency; these do not select a Payments login account. Create lists `duplicate_request_id`; retrieve can return `resource_not_found`. |
| Payments | [POST `pa/payment_intents/create`](https://www.airwallex.com/docs/api/payments/payment_intents/create): required `amount`, `currency`, `merchant_order_id`, `request_id`, 201 response | [GET `pa/payment_intents/{id}`](https://www.airwallex.com/docs/api/payments/payment_intents/retrieve): 200 response | Intent `status` and `next_action` drive payment progress. `request_id` is merchant supplied, maximum 64 characters. Capture requires a capturable authorization; cancellation is limited to documented states. |

The [Payment Source contract](https://www.airwallex.com/docs/api/billing/payment_sources) further bridges the families: creation requires `billing_customer_id`, `external_id` (a Payment Method ID), and `request_id`. `linked_payment_account_id` is required with multiple payment accounts. For Airwallex instruments, merchant-initiated authorization uses `next_triggered_by=merchant` and `merchant_trigger_reason=unscheduled` through an Intent or Consent. Reads return the Billing customer, method and linked account references. Verify their ownership together; a Billing Customer ID cannot substitute for a Payments Customer ID.

There is a documentation mismatch: the [payment-source product guide](https://www.airwallex.com/docs/billing/billing-components/payment-sources) describes creating the source using a verified Consent, while the API requires the associated Method ID. For a source-backed card example, retrieve a `VERIFIED` [Payment Consent](https://www.airwallex.com/docs/api/payments/payment_consents), take its `payment_method.id`, and pass that Method ID as `external_id` with the Billing customer and linked account. The Consent response documents `payment_method.id` as card-only; other methods require their own linkage verification. Mark live account behavior as unverified until observed in Sandbox. Documented SDK interfaces and meaningfully tested examples satisfy the [completion rule](release-policy.md#documentation-based-completion); those checks do not establish account-specific integration success. The inspected API exposes create/retrieve/list, leaving default changes and removal unverified.

## Precision, pagination and errors

The [data-type reference](https://www.airwallex.com/docs/api/data_types) uses major currency units, ISO4217 currency codes and timezone-bearing ISO8601 timestamps. Representative monetary fields include Price `unit_amount`/`flat_amount`, Invoice `amount_due`, and Intent/Refund `amount`. Preserve exact decimal values during JSON parsing and serialization; a JSON `number` declaration does not justify PHP float arithmetic. The accepted [decimal representation](consumer-interfaces.md#interface-conventions) uses strings with lossless endpoint-aware JSON handling.

| List contract | Inputs and output | Evidence |
| --- | --- | --- |
| Billing Customers | `page` bookmark, `page_size` default 20; `items`, nullable `page_after`/`page_before`; created-time start inclusive, end exclusive | [List](https://www.airwallex.com/docs/api/billing/billing_customers/list) |
| Payment Intents | Zero-based `page_num`, `page_size` default 10, capped at 1000; `items` and `has_more` | [List](https://www.airwallex.com/docs/api/payments/payment_intents/list) |

Keep filters and authorization context across pages. Other lists need their own bounds/envelopes verified; the representative formats do not establish one universal paginator.

The [error reference](https://www.airwallex.com/docs/api/errors) describes `code`, `source`, `message` and conditional `details`. Preserve HTTP status and provider codes; exception conventions are defined in [interface examples](consumer-examples.md#pagination-and-failures). Endpoint error tables add resource-specific behavior: a missing Billing resource can be 404 even though the general table describes 404 as a missing endpoint. Error-message text is unsuitable for stable matching. Sanitize diagnostics rather than logging full bodies.

## Request identity and retries

| Operation | Verified request identity / semantics | Remaining retry evidence |
| --- | --- | --- |
| Billing Customer create | Required body `request_id`; duplicate ID can produce 400 `duplicate_request_id` | No verified replay window, payload-conflict handling or original-response replay guarantee |
| Product/Price/Checkout/Payment Source create | Each inspected schema requires body `request_id` | Verify deduplication scope/window and endpoint-specific failure behavior separately |
| Intent create/confirm/capture/cancel | Required merchant `request_id`; financial effects depend on Intent state | Identifier presence alone does not establish safe mutation replay |
| Consent create/disable; Refund create | Required merchant `request_id` (maximum 64) | Verify ambiguous-outcome recovery and replay behavior before enabling retries |
| GET resource/list reads | No mutation identifier | Bounded transient retries can be designed while retaining filters and account/version context |

The references in the resource inventory are the evidence for each row. Subscription update/cancel and other mutation identities require their own schema checks before retry implementation. Retain the original identifier and payload for any authorized replay; a new identifier risks a second financial operation. Until deduplication is verified, automatic mutation replay remains gated by [issue #15](https://github.com/jojeringnax/airwallex-php-sdk/issues/15).

The [rate-limit guide](https://www.airwallex.com/docs/developer-tools/api/rate-limits) distinguishes global, endpoint and resource limits, with different sandbox/production quotas. A 429 or 5xx response alone does not prove a financial operation is safe to repeat. Exact retry timing, Retry-After behavior and limits require endpoint-specific checks; avoid treating transient failures as payment declines.

## Webhook contract

The [subscription guide](https://www.airwallex.com/docs/developer-tools/webhooks/listen-for-webhook-events) states that webhook API versions are chosen at subscription creation, defaulting to the account version. Event `version` describes `data`; `data.object` follows the corresponding resource Retrieve schema. An API request header does not configure webhook subscriptions. The selected-version [Webhooks API](https://www.airwallex.com/docs/api/webhooks/webhooks) independently requires a `version` field when creating a subscription; set it to `2026-08-21`. This inventory owns version configuration; the [webhook catalogue](webhook-contracts.md) records selected events, schema-derived fixtures, interpretation policy and delivery evidence gaps.

The generic guide describes `id`, `name`, `created_at`, `version`, `data.object` and applicable `org_id`/`account_id`. The [webhook catalogue](webhook-contracts.md#envelope-and-ownership) owns event-specific envelope interpretation and provider documentation conflicts; its [verification setup](webhook-contracts.md#subscription-setup-and-verification) owns raw-body signature and timestamp requirements.

The [delivery overview](https://www.airwallex.com/docs/developer-tools/webhooks/webhooks-overview) documents duplicates and unordered delivery. Consumer processing and reconciliation remain application-owned. For event names, schema-derived fixtures, resource mapping, unknown-version behavior and conflicting provider envelope examples, consult the [webhook catalogue](webhook-contracts.md). The catalogue distinguishes documentation checks from outstanding sandbox delivery validation.

## Payment recovery conflict

The [Subscription API introduction](https://www.airwallex.com/docs/api/billing/subscriptions) describes four retries after 1, 2, 5 and 7 days and cancellation on exhaustion. The [dunning guide](https://www.airwallex.com/docs/billing/subscriptions/dunning) instead describes cycle-dependent schedules, custom schedules configured by Airwallex, and configurable exhaustion outcomes (`UNPAID` or `CANCELLED`). It limits dunning to automatic collection.

The durable SDK constraint is to expose observed provider state and account-configured recovery behavior. Neither passage establishes a universal retry schedule or inevitable cancellation. Actual account settings and sandbox observations must resolve the outcome for a consumer journey; keep provider dunning separate from HTTP request retries. This discrepancy remains relevant to subscription and webhook implementation.

## Decisions and evidence still required

Endpoint fixtures and public representations are SDK implementation requirements. Account-specific platform access, live timing and runtime observations below are supplemental under the [documentation-based completion rule](release-policy.md#documentation-based-completion). Their absence leaves the corresponding provider facts unverified without blocking a correctly implemented and tested documented interface.

| Gap | Required resolution | Follow-up |
| --- | --- | --- |
| Endpoint fixtures | Derive complete request/response and failure-path fixtures from the verified `2026-08-21` documentation for each implemented operation | Resource implementation issues |
| Platform access | Confirm the merchant organization authorization, linked-account eligibility and product activation specified in [connected-account evidence](connected-account-contracts.md#evidence-still-needed), then validate both Billing models | [#17](https://github.com/jojeringnax/airwallex-php-sdk/issues/17), [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42) |
| Lifecycle and documents | [Capability review](billing-capabilities.md) records document, self-service, checkout, trial and lifecycle boundaries; validate link refresh, schedule reversal and account-configured recovery in the owning follow-ups | [#21](https://github.com/jojeringnax/airwallex-php-sdk/issues/21), [#23](https://github.com/jojeringnax/airwallex-php-sdk/issues/23), [#26](https://github.com/jojeringnax/airwallex-php-sdk/issues/26), [#27](https://github.com/jojeringnax/airwallex-php-sdk/issues/27), [#28](https://github.com/jojeringnax/airwallex-php-sdk/issues/28) |
| Public representation implementation | Implement the accepted [consumer interfaces](consumer-interfaces.md), including decimal encoding, pagination and errors | [#12](https://github.com/jojeringnax/airwallex-php-sdk/issues/12), [#14](https://github.com/jojeringnax/airwallex-php-sdk/issues/14), [#16](https://github.com/jojeringnax/airwallex-php-sdk/issues/16) |
| Retry guarantees | Verify deduplication windows, conflict/replay behavior, mutation identifiers and ambiguous-outcome recovery per endpoint | [#15](https://github.com/jojeringnax/airwallex-php-sdk/issues/15) |
| Runtime evidence | Capture sanitized versioned payloads and validate permissions/account isolation using explicit sandbox configuration | [#17](https://github.com/jojeringnax/airwallex-php-sdk/issues/17), [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42) |

This review establishes a source-backed inventory and representative contract checks. The selected version establishes the SDK target; this review does not establish sandbox compatibility, complete per-endpoint schemas or production account eligibility.
