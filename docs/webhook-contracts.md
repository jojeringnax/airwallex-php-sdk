# Versioned Billing webhook catalogue

Contract evidence for [issue #8](https://github.com/jojeringnax/airwallex-php-sdk/issues/8), reviewed on **2026-11-08**. Use this reference when implementing or consuming subscription, Billing Checkout, invoice and payment-source notifications. API requests and webhook subscriptions target **`2026-08-21`**, following the [API version policy](api-contracts.md#version-baseline). This catalogue defines event interpretation and retrieval; PHP signatures are defined by the [webhook interface example](consumer-examples.md#webhook-boundary). Application persistence, dispatch and entitlement policy follow the [architecture](architecture.md#state-and-persistence-ownership).

## Evidence and payload boundary

The selected-version API pages below expose `__NEXT_DATA__.props.pageProps.currentVersion=2026-08-21`. Their `activeCategory.webhookEnvents` lists were checked against the event names below, and their Retrieve endpoints against `activeCategory.components.schemas`. The source metadata identifies `airwallex-openapi-2026-08-21.yaml`, published MD5 `f3dcbacb0453ce7a4808b6e97305dac6`. Product guides supplement these schemas and are not independently date-versioned.

The [Billing event guide](https://www.airwallex.com/docs/developer-tools/webhooks/listen-for-webhook-events/billing) separates events for `2025-06-16` and later from legacy events for `2025-04-25` and earlier. This catalogue selects the later family. [Payments webhook references](https://www.airwallex.com/docs/payments/reference/payments-webhooks) identify the Consent and Method payloads as their corresponding Retrieve responses.

[Sanitized fixtures and provenance](fixtures/webhooks/2026-08-21/README.md) cover all 28 selected event names, plus a Payment Source retrieval. They are synthetic examples derived from the published schemas, with required resource fields and representative state/linkage fields. They are not captured deliveries, exhaustive resource examples or proof of sandbox compatibility. Their envelopes follow the generic webhook guide; actual Billing envelope shape remains an explicit evidence gap below.

## Subscription setup and verification

1. Configure a Sandbox HTTPS receiver using the [subscription guide](https://www.airwallex.com/docs/developer-tools/webhooks/listen-for-webhook-events). The web app requires Developer, Admin or Owner access: **Developer > Webhooks > New webhook**. Select the intended organization/account context and only the selected events available to it. Choose API version `2026-08-21` explicitly; an API request's `x-api-version` header does not configure webhook delivery.
2. Configure Billing resource read permissions at organization level, and Payment Acceptance read permissions for each relevant account. The [API-key permission catalogue](https://www.airwallex.com/docs/developer-tools/api/api-key-scopes) maps permissions to events. Confirm event availability in the selected context; an event name or subscription alone grants no resource access. Use the [ownership contract](connected-account-contracts.md#credential-and-request-contexts) for independent Billing and Payments authentication.
3. Retrieve the receiver's subscription secret securely outside the repository. Verify the hex HMAC-SHA256 of the exact `x-timestamp` string followed by the original body, compare in constant time, and enforce consumer-configured timestamp tolerance. The timestamp is Unix milliseconds. Verify before parsing or transforming the body. Use the receiver's configured secret; the guide's special `client-secret-key` mechanism concerns test events and must not select a secret for ordinary incoming deliveries.
4. Test delivery and inspect it in **Settings > Developer > Events** using the [view/re-trigger guide](https://www.airwallex.com/docs/developer-tools/webhooks/view-and-re-trigger-webhook-events). Confirm the delivered version, envelope, owner and Retrieve result before enabling typed handling. Capture only sanitized evidence as described in the fixture README.
5. Return `200 OK` promptly after verification and consumer acceptance. The [delivery overview](https://www.airwallex.com/docs/developer-tools/webhooks/webhooks-overview) treats other statuses or timeouts as failures, retries with exponential backoff over about three days, and permits duplicates and unordered delivery. Consumers own durable acceptance, duplicate suppression and recovery; the SDK does not implement an HTTP receiver or worker.

The selected-version [Create Webhook API](https://www.airwallex.com/docs/api/webhooks/webhooks/create) requires `events`, `request_id`, `url` and `version`; `events` cannot be changed after creation. Its metadata is account-level with `x-oboSupported=false`. This does not establish an API route for organization-wide Billing subscription provisioning or delegated connected-account subscription creation. Use the web app's available context controls and confirm availability with Airwallex where needed; do not infer provisioning support from Billing resource permissions. Receiver provisioning is consumer infrastructure, outside SDK event decoding.

## Envelope and ownership

The [generic payload reference](https://www.airwallex.com/docs/developer-tools/webhooks/listen-for-webhook-events#webhook-payload) describes `id`, `name`, `created_at`, `version`, `data.object`, and applicable `org_id` / `account_id`. `version` determines the resource schema. Preserve all supplied fields, including unfamiliar fields; neither ownership identifier is universally required across all families.

| Family | Authoritative owner and retrieval context | Reconciliation identifiers |
| --- | --- | --- |
| Subscription, Checkout, Invoice, Payment Source | Billing organization; organization-authorized bearer token, no `x-on-behalf-of` | Resource `id`, `billing_customer_id`, optional related resource IDs; `linked_payment_account_id` identifies collection, not Billing authorization |
| Payment Consent and Method | Effective Payments account; direct authorized login or verified platform delegation with `x-on-behalf-of` | Envelope `account_id`, resource `id`, applicable Payments `customer_id`; Consent may also expose `connected_account_id` |

Match the receiver's trusted environment and subscription context to the delivered owner before selecting credentials. A supplied identifier is a claim to check, not permission to retrieve another tenant's resources. A missing or conflicting applicable owner requires inspection and a known authorized retrieval context. For Billing events, never treat the collection account as the owning organization. For delegated Payments events, use the connected account that owns the resource, not the platform account by default; cross-check any `connected_account_id`. Retain environment and owner alongside resource IDs. See the [two-account scenarios](connected-account-contracts.md#two-account-walkthroughs) for both accepted Billing models.

### Documentation conflicts and interpretation

| Evidence conflict | Contract treatment and remaining evidence |
| --- | --- |
| Generic guide says `data.object` and organization-level `org_id`; newer Billing examples say `data` is the Retrieve body, show `account_id`, and omit `version` | Use the generic envelope as the target model for synthetic fixtures, while retaining raw envelopes. Neither wrapping `data` automatically nor converting `account_id` to `org_id` is justified. Treat these conflicting deliveries as unrecognized until a pinned-version sandbox delivery and matching authorized Retrieve establish shape and owner. This evidence is required before claiming typed Billing integration support. |
| Payments product samples use `accountId`, omit version/time and sometimes contain invalid JSON | Use the generic guide's `account_id` and versioned envelope for constructed examples. Preserve the original spelling on unrecognized deliveries; alias conversion requires explicit contract evidence. |
| Legacy Billing events include `subscription.updated`, `invoice.sent`, `invoice.paid`, `invoice.payment_failed`, `invoice.payment_attempt_failed`; permission catalogues may mix versions | At the selected pin, use `subscription.modified`, `invoice.finalized`, `invoice.payment.paid` and the other selected-version names below. There is no automatic aliasing or inferred modern payment-failure event. Legacy schemas include different customer and period field names. Version migration requires separate schema review. |
| Consent status description limits `PAUSED` to PayTo, and the method schema has a `payto` property, but its descriptive type list omits `payto` | Treat the descriptive list as incomplete: it is not a schema enum. The paused fixture follows the explicit status description and method property; it establishes no non-card Billing Source eligibility. Confirm the actual method payload in sandbox. |
| Subscription API introduction describes fixed retry dates and inevitable cancellation; the [dunning guide](https://www.airwallex.com/docs/billing/subscriptions/dunning) describes cycle/configuration-dependent recovery | Expose observed `UNPAID`, `ACTIVE` and `CANCELLED` states. Do not calculate a universal retry deadline, grace period or cancellation outcome. Inspect invoice attempt fields and account settings. Dunning applies to automatic collection and is distinct from SDK HTTP retries. The [inventory](api-contracts.md#payment-recovery-conflict) owns this discrepancy. |

## Resource and transition catalogue

Every row is an event observation, not a guaranteed delivery sequence or a complete provider transition graph. Creation/update events do not by themselves prove a status transition. Retrieve the current resource in the same owner context at `x-api-version: 2026-08-21`; an event snapshot may be older than the retrieved state. Only the provider fields establish the observed state.

### Subscriptions

Official [event list and Retrieve schema](https://www.airwallex.com/docs/api/billing/subscriptions/retrieve): `GET /api/v1/billing/subscriptions/{id}`, schema `Subscription`. [Fixtures](fixtures/webhooks/2026-08-21/subscription.json).

| Event | Observed effect / fields to inspect |
| --- | --- |
| `subscription.created` | Resource created; inspect `status`. A future start can be `PENDING`; creation does not imply payment or activation. |
| `subscription.in_trial` | Enters `IN_TRIAL`; inspect `trial_starts_at`, `trial_ends_at`. |
| `subscription.active` | Enters `ACTIVE`; can follow trial completion, start or recovery. It does not identify which path occurred. |
| `subscription.unpaid` | Enters `UNPAID` after collection failure; retrieve `latest_invoice_id` when present for attempt/recovery detail. |
| `subscription.modified` | Resource updated; inspect current `status`, period fields and scheduling fields. `cancel_at_period_end=true` schedules cancellation; it does not mean `CANCELLED`. Item details require the separately documented item reads. |
| `subscription.cancelled` | Enters `CANCELLED`; inspect `ends_at` and cancellation fields. Do not infer whether cancellation was manual, scheduled or caused by exhausted recovery from the name alone. |

The Retrieve status set is `PENDING`, `IN_TRIAL`, `ACTIVE`, `UNPAID`, `CANCELLED`. Trial completion can yield `ACTIVE`; failed automatic collection yields `UNPAID`, recovery can restore `ACTIVE`, and exhausted recovery may leave `UNPAID` or cancel according to settings. `starts_at`, `current_period_starts_at`, `current_period_ends_at`, `next_billing_at`, `ends_at` and cancellation fields describe provider timing. There is no separately listed `subscription.pending` event at this pin. Retrieve rather than invent an event for every enum value. Consumer access and grace policy remain outside these financial statuses.

### Billing Checkouts

Official [event list and Retrieve schema](https://www.airwallex.com/docs/api/billing/billing_checkouts/retrieve): `GET /api/v1/billing/billing_checkouts/{id}`, schema `Checkout`. [Fixtures](fixtures/webhooks/2026-08-21/billing_checkout.json).

| Event | Observed effect / fields to inspect |
| --- | --- |
| `billing_checkout.created` | Checkout created; retrieve `status`, `mode`, `expires_at` and collection account. |
| `billing_checkout.completed` | `COMPLETED`; inspect `mode`, `billing_customer_id`, `payment_source_id`, `subscription_id` for SUBSCRIPTION or `invoice_id` for PAYMENT, as applicable. Retrieve resulting resources independently. |
| `billing_checkout.cancelled` | `CANCELLED`; inspect `cancelled_at`. This concerns the checkout, not cancellation of a subscription. |

The status set is `ACTIVE`, `COMPLETED`, `CANCELLED`, `EXPIRED`. The Retrieve schema describes expiration after one hour without purchase; use returned `expires_at` and current status. No `billing_checkout.expired` event is listed. Poll/retrieve to discover expiry; a return/success redirect is not authoritative payment evidence. `SETUP` completion is instrument setup, not a paid invoice or active subscription. Completion does not authorize inferring a related resource's current financial state.

### Invoices

Official [event list and Retrieve schema](https://www.airwallex.com/docs/api/billing/invoices/retrieve): `GET /api/v1/billing/invoices/{id}`, schema `Invoice`. [Fixtures](fixtures/webhooks/2026-08-21/invoice.json).

| Event | Observed effect / fields to inspect |
| --- | --- |
| `invoice.created` | Invoice created; initial lifecycle status is `DRAFT`. Inspect `payment_status` separately. |
| `invoice.finalized` | Lifecycle becomes `FINALIZED`; inspect `finalized_at` and document links if needed. Finalization alone does not establish payment. |
| `invoice.updated` | Invoice changed; retrieve lifecycle, payment state and applicable attempt fields. The event name alone does not mean a failed attempt. |
| `invoice.payment.paid` | `payment_status=PAID`; inspect `amount_due`, `paid_at`, `paid_out_of_band`. Lifecycle `status` is separate and is not a `PAID` enum. |
| `invoice.voided` | Lifecycle becomes `VOIDED`; inspect `voided_at`. Do not equate this with a Payments refund. |

Lifecycle status is `DRAFT`, `FINALIZED`, `VOIDED`; payment status is independently `UNPAID` or `PAID`. The schema states that `amount_due=0` makes payment status `PAID`; this does not prove a card charge, since an invoice can be marked paid out of band. For subscription invoices inspect `subscription_id`, `last_payment_attempt_at`, `next_payment_attempt_at`, `past_payment_attempt_count`, `remaining_payment_attempt_count`. Exhausted attempts do not imply automatic voiding. Invoice document scope is owned by [Billing capability boundaries](billing-capabilities.md#invoice-access-and-delivery). Preserve decimal precision when reading amounts; the fixture's numeric JSON fields do not authorize PHP float arithmetic.

### Payment sources and supporting Payments events

Official [Payment Source Retrieve schema](https://www.airwallex.com/docs/api/billing/payment_sources/retrieve): `GET /api/v1/billing/payment_sources/{id}`, schema `Billing_PaymentSource`. The selected-version event list is empty and the schema has no `status` field. **No `payment_source.*` event is verified.** [Source retrieval fixture](fixtures/webhooks/2026-08-21/payment-source-retrieve.json).

Use explicit source creation/retrieval or a Checkout/Invoice/Subscription reference to discover the source. Inspect `billing_customer_id`, `external_id` (Method ID) and `linked_payment_account_id`. Retrieve that Method in its Payments account; for the source-backed card path, retrieve the associated Consent and inspect `payment_method.id` only where documented. Consumers retain the source/consent mapping; a Method ID does not itself supply a Consent ID. The [linkage caveat](api-contracts.md#representative-contract-checks) distinguishes Method and Consent IDs and limits the verified linkage example to cards. A supporting Payments event never proves that a Billing source was created, changed, removed or made default.

Official [Consent events and Retrieve schema](https://www.airwallex.com/docs/api/payments/payment_consents/retrieve): `GET /api/v1/pa/payment_consents/{id}`, schema `PaymentConsent`. [Fixtures](fixtures/webhooks/2026-08-21/payment_consent.json).

| Event | Observed effect / fields to inspect |
| --- | --- |
| `payment_consent.created` | Consent created; inspect `status`, `next_triggered_by`, `merchant_trigger_reason` and method. Creation does not prove verification. |
| `payment_consent.updated` | Consent updated; inspect fields and status without inventing a transition. |
| `payment_consent.pending` | `PENDING`: Bacs Direct Debit mandate submitted; final result may be `VERIFIED` or `DISABLED`. |
| `payment_consent.verified` | `VERIFIED`: consent verified for subsequent payments; does not itself prove a Billing Source exists. |
| `payment_consent.disabled` | `DISABLED`: cannot be verified again; inspect `disable_reason` when present. |
| `payment_consent.paused` | `PAUSED`: PayTo mandate paused after `VERIFIED`; may resume to `VERIFIED` or become `DISABLED`. This is not subscription pause support. |
| `payment_consent.requires_payment_method` | `REQUIRES_PAYMENT_METHOD`: supply a method through the documented verification flow; inspect failure detail if present. |
| `payment_consent.requires_customer_action` | `REQUIRES_CUSTOMER_ACTION`: inspect `next_action` and failure detail. |
| `payment_consent.verification_failed` | Verification failed; inspect/retrieve `status` and `failure_reason`. There is no `VERIFICATION_FAILED` status in the Retrieve contract. The fixture uses one illustrative `REQUIRES_PAYMENT_METHOD` outcome, not a universal mapping. |

Official [Method events and Retrieve schema](https://www.airwallex.com/docs/api/payments/payment_methods/retrieve): `GET /api/v1/pa/payment_methods/{id}`, schema `PaymentMethod`. [Fixtures](fixtures/webhooks/2026-08-21/payment_method.json).

| Event | Observed effect / fields to inspect |
| --- | --- |
| `payment_method.created` | Instrument created; inspect `status` and method type. |
| `payment_method.updated` | Instrument updated; retrieve current method. |
| `payment_method.attached` | Method attached to a Payments customer; inspect `customer_id`, not a Billing Customer ID. |
| `payment_method.detached` | Method detached from a customer; inspect the retrieved association. No `DETACHED` status is documented. |
| `payment_method.disabled` | `DISABLED`; inspect current method and any associated Consent in the same account. |

Method statuses are `CREATED` and `DISABLED`. Attach/detach are association changes, not additional statuses. These events support instrument reconciliation only. Standalone PaymentIntent, Attempt and Refund event catalogues belong to their resource implementation work; this contribution does not expand the Billing workflow catalogue to all Payments events.

## Unknown events, versions and reconciliation

After signature verification, the caller retains the original `$rawBody` in memory alongside the decoded unrecognized event and its supplied name, version and envelope fields; decoding does not reconstruct or replace those bytes. This applies to unknown names, unsupported or missing versions, conflicting envelopes and unknown resource status values. The SDK assigns no typed state transition to such events and does not persist, log or dispatch them. Consumers choose storage and retention; raw payment payloads require their own redaction and access controls. The [webhook interface example](consumer-examples.md#webhook-boundary) defines verification and decoding signatures.

For the supported version and recognized envelope, tolerate additional fields and preserve unfamiliar provider values. An unknown value is not a known terminal or success state. Never silently relabel a payload with the SDK version, map legacy names, normalize conflicting ownership fields, or decode an unsupported version using the pinned schema. Malformed JSON, failed signatures and invalid timestamps are verification/decoding failures, not verified unknown-event examples.

Consumers can reconcile without building an SDK-owned state store:

1. Verify authenticity and match a known authorized environment/owner context. Deduplicate by event ID within that context in consumer infrastructure when side effects are involved.
2. For a recognized name/version/envelope, obtain the resource ID from `data.object.id` and retrieve using the matching row above. For an unrecognized payload, use a previously trusted resource mapping or inspect it first; do not guess a Retrieve endpoint or owner from arbitrary fields.
3. Read current provider fields and related resources before applying consumer policy. For an unsupported incoming version, retrieval at the known `2026-08-21` contract can recover current state only when resource identity and owner are independently established; it does not reinterpret the historical event.
4. Handle duplicates and older snapshots without regressing current state. `created_at` is event creation time, not a transactional resource sequence number. Retrieval failures, missing context and unavailable permissions require consumer recovery; they do not justify financial-state or entitlement changes.

## Remaining integration evidence

[#17](https://github.com/jojeringnax/airwallex-php-sdk/issues/17) owns the opt-in sandbox harness and [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42) owns accepted journey validation. Before typed Billing integration support, they must capture matching-version deliveries and Retrieve results to resolve envelope/owner conflicts, verify event availability and organization subscription setup, and exercise account isolation plus duplicate/out-of-order recovery. Account-specific dunning outcomes require configured sandbox scenarios. Independent review and local fixture/schema checks do not substitute for those results.
