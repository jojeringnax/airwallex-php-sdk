# API contract and permission inventory

Contract research for [issue #3](https://github.com/jojeringnax/airwallex-php-sdk/issues/3), reviewed on **2026-11-08** against official Airwallex API and product documentation. Read this reference before implementing authentication, resource endpoints, retries or webhook contracts. Capability scope is owned by [planning](planning.md).

## Version baseline

The SDK targets API date-version **`2026-08-21`**, with explicit `x-api-version` on SDK API requests and the same version configured on webhook subscriptions. This gives consumers a reproducible contract across accounts with different defaults. The resource references below were verified against that selected documentation version; sandbox compatibility remains a separate validation step.

The [versioning reference](https://www.airwallex.com/docs/api/versioning) lists `2026-08-21` as latest. Without an override, calls use the account's configured version. The header overrides that default per call; Airwallex recommends it for exceptional cases such as migration and on-behalf-of calls. The `/v1` URL segment is separate from the date-version. The SDK uses an explicit pin to keep its contract stable across account defaults; consumers configure webhook subscriptions to match.

Account upgrades do not implicitly upgrade the SDK contract. Review changelog/schema differences before changing the pin. Proposed response compatibility behavior, to be reviewed with the public interface examples: tolerate additional fields and preserve unknown provider values. Regulatory or payment-method requirements can still change across versions. SDK release compatibility remains governed by [architecture](architecture.md#compatibility-policy).

## Selected-version evidence

On 2026-11-08, the HTML of all 13 API resource pages in the inventory, plus the [authentication](https://www.airwallex.com/docs/api/authentication/api_access_token), [versioning](https://www.airwallex.com/docs/api/versioning), [changelog](https://www.airwallex.com/docs/api/changelog) and [Webhooks API](https://www.airwallex.com/docs/api/webhooks/webhooks) pages, was checked directly. Each page had a selected `<option value="2026-08-21">` and `__NEXT_DATA__.props.pageProps.currentVersion` equal to `2026-08-21`. The metadata identifies the source specification as `airwallex-openapi-2026-08-21.yaml`, with published `md5` identifier `f3dcbacb0453ce7a4808b6e97305dac6` in `versionItems`.

The same page metadata contains `activeCategory.endpoints`; the inventory operations and representative required fields, response schema references, permissions and resource levels were cross-checked there. The URLs currently default to this version and may change later: on re-verification, check the selected option and `currentVersion` rather than assuming the URL still identifies the baseline. Product guides provide supplemental behavior and are not independently date-versioned.

## Authorization and ownership

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
| Prices | Create, retrieve, list, update; flat/per-unit recurring prices | [API](https://www.airwallex.com/docs/api/billing/prices) | [Prices](https://www.airwallex.com/docs/billing/billing-components/prices) |
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

Credit notes/Billing transactions need a separate coverage decision beyond the accepted invoice retrieval and Payments refund workflows. Coupons, tax and usage resources remain deferred under planning; their presence in provider documentation does not authorize implementation.

## Representative contract checks

Paths below are relative to `/api/v1`. These checks compare documented reads and mutations independently for Billing and Payments at `2026-08-21`; no sandbox API requests were executed. Billing Customer create/retrieve reference the same `CustomerResponse` schema; Intent create/retrieve reference `PaymentIntentSingle`. Login references `Token` and retains the required credential headers and optional `x-login-as` described above.

| Family | Mutation checked | Read checked | Important contract differences |
| --- | --- | --- | --- |
| Billing | [POST `billing/billing_customers/create`](https://www.airwallex.com/docs/api/billing/billing_customers/create): required `request_id`, 201 resource response | [GET `billing/billing_customers/{id}`](https://www.airwallex.com/docs/api/billing/billing_customers/retrieve): 200 resource response | Customer fields include `default_legal_entity_id` and billing currency; these do not select a Payments login account. Create lists `duplicate_request_id`; retrieve can return `resource_not_found`. |
| Payments | [POST `pa/payment_intents/create`](https://www.airwallex.com/docs/api/payments/payment_intents/create): required `amount`, `currency`, `merchant_order_id`, `request_id`, 201 response | [GET `pa/payment_intents/{id}`](https://www.airwallex.com/docs/api/payments/payment_intents/retrieve): 200 response | Intent `status` and `next_action` drive payment progress. `request_id` is merchant supplied, maximum 64 characters. Capture requires a capturable authorization; cancellation is limited to documented states. |

The [Payment Source contract](https://www.airwallex.com/docs/api/billing/payment_sources) further bridges the families: creation requires `billing_customer_id`, `external_id` (a Payment Method ID), and `request_id`. `linked_payment_account_id` is required with multiple payment accounts. For Airwallex instruments, merchant-initiated authorization uses `next_triggered_by=merchant` and `merchant_trigger_reason=unscheduled` through an Intent or Consent. Reads return the Billing customer, method and linked account references. Verify their ownership together; a Billing Customer ID cannot substitute for a Payments Customer ID.

There is a documentation mismatch: the [payment-source product guide](https://www.airwallex.com/docs/billing/billing-components/payment-sources) describes creating the source using a verified Consent, while the API requires the associated Method ID. For a source-backed card example, retrieve a `VERIFIED` [Payment Consent](https://www.airwallex.com/docs/api/payments/payment_consents), take its `payment_method.id`, and pass that Method ID as `external_id` with the Billing customer and linked account. The Consent response documents `payment_method.id` as card-only; other methods require their own linkage verification. Clearly mark examples as unvalidated until exercised in sandbox; sandbox evidence is required before claiming integration support, rather than before drafting interface examples. The inspected API exposes create/retrieve/list, leaving default changes and removal unverified.

## Precision, pagination and errors

The [data-type reference](https://www.airwallex.com/docs/api/data_types) uses major currency units, ISO4217 currency codes and timezone-bearing ISO8601 timestamps. Representative monetary fields include Price `unit_amount`/`flat_amount`, Invoice `amount_due`, and Intent/Refund `amount`. Preserve exact decimal values during JSON parsing and serialization; a JSON `number` declaration does not justify PHP float arithmetic. Public decimal representation belongs to [issue #6](https://github.com/jojeringnax/airwallex-php-sdk/issues/6).

| List contract | Inputs and output | Evidence |
| --- | --- | --- |
| Billing Customers | `page` bookmark, `page_size` default 20; `items`, nullable `page_after`/`page_before`; created-time start inclusive, end exclusive | [List](https://www.airwallex.com/docs/api/billing/billing_customers/list) |
| Payment Intents | Zero-based `page_num`, `page_size` default 10, capped at 1000; `items` and `has_more` | [List](https://www.airwallex.com/docs/api/payments/payment_intents/list) |

Keep filters and authorization context across pages. Other lists need their own bounds/envelopes verified; the representative formats do not establish one universal paginator.

The [error reference](https://www.airwallex.com/docs/api/errors) describes `code`, `source`, `message` and conditional `details`. Preserve HTTP status and provider codes; exception interfaces remain pending. Endpoint error tables add resource-specific behavior: a missing Billing resource can be 404 even though the general table describes 404 as a missing endpoint. Error-message text is unsuitable for stable matching. Sanitize diagnostics rather than logging full bodies.

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

The [subscription guide](https://www.airwallex.com/docs/developer-tools/webhooks/listen-for-webhook-events) states that webhook API versions are chosen at subscription creation, defaulting to the account version. Event `version` describes `data`; `data.object` follows the corresponding resource Retrieve schema. An API request header does not configure webhook subscriptions. The selected-version [Webhooks API](https://www.airwallex.com/docs/api/webhooks/webhooks) independently requires a `version` field when creating a subscription; set it to `2026-08-21`. This inventory verifies version configuration, while event-by-event payload validation remains in issue #8.

The envelope includes `id`, `name`, `created_at`, `version`, `data.object` and applicable `org_id`/`account_id`. Validate organization/account applicability per event instead of requiring both IDs universally. Verify HMAC-SHA256 over the exact `x-timestamp` string followed by the raw body using the subscription secret; compare in constant time and apply timestamp tolerance. `x-timestamp` is milliseconds.

The [delivery overview](https://www.airwallex.com/docs/developer-tools/webhooks/webhooks-overview) documents duplicates and unordered delivery. Consumer processing and reconciliation remain application-owned. Event names, fixtures, resource mapping and unknown-version behavior need the versioned catalogue in [issue #8](https://github.com/jojeringnax/airwallex-php-sdk/issues/8); no payload fixtures have been validated here.

## Payment recovery conflict

The [Subscription API introduction](https://www.airwallex.com/docs/api/billing/subscriptions) describes four retries after 1, 2, 5 and 7 days and cancellation on exhaustion. The [dunning guide](https://www.airwallex.com/docs/billing/subscriptions/dunning) instead describes cycle-dependent schedules, custom schedules configured by Airwallex, and configurable exhaustion outcomes (`UNPAID` or `CANCELLED`). It limits dunning to automatic collection.

The durable SDK constraint is to expose observed provider state and account-configured recovery behavior. Neither passage establishes a universal retry schedule or inevitable cancellation. Actual account settings and sandbox observations must resolve the outcome for a consumer journey; keep provider dunning separate from HTTP request retries. This discrepancy remains relevant to subscription and webhook implementation.

## Decisions and evidence still required

| Gap | Required resolution | Follow-up |
| --- | --- | --- |
| Endpoint fixtures | Derive complete request/response and failure-path fixtures from the verified `2026-08-21` documentation for each implemented operation | Resource implementation issues |
| Platform access | [Context and ownership evidence](connected-account-contracts.md) is recorded; both Billing models and API-key authentication are selected; confirm merchant organization authorization and linked-account eligibility | [#4](https://github.com/jojeringnax/airwallex-php-sdk/issues/4) |
| Lifecycle and documents | [Capability review](billing-capabilities.md) records document, self-service, checkout, trial and lifecycle boundaries; validate link refresh, schedule reversal and account-configured recovery in the owning follow-ups | [#21](https://github.com/jojeringnax/airwallex-php-sdk/issues/21), [#23](https://github.com/jojeringnax/airwallex-php-sdk/issues/23), [#26](https://github.com/jojeringnax/airwallex-php-sdk/issues/26), [#27](https://github.com/jojeringnax/airwallex-php-sdk/issues/27), [#28](https://github.com/jojeringnax/airwallex-php-sdk/issues/28) |
| Public representation | Approve decimals, pagination, optional/null fields, errors and unknown-value behavior in consumer examples | [#6](https://github.com/jojeringnax/airwallex-php-sdk/issues/6) |
| Retry guarantees | Verify deduplication windows, conflict/replay behavior, mutation identifiers and ambiguous-outcome recovery per endpoint | [#15](https://github.com/jojeringnax/airwallex-php-sdk/issues/15) |
| Runtime evidence | Capture sanitized versioned payloads and validate permissions/account isolation using explicit sandbox configuration | [#17](https://github.com/jojeringnax/airwallex-php-sdk/issues/17), [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42) |

This review establishes a source-backed inventory and representative contract checks. The selected version establishes the SDK target; this review does not establish sandbox compatibility, complete per-endpoint schemas or production account eligibility.
