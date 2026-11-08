# Sandbox validation

The integration harness is opt-in and separate from deterministic unit tests. It loads an external credential file as literal data, selects `Environment::Sandbox`, enables TLS verification, disables redirects and rejects every request outside `https://api.sandbox.airwallex.com/api/v1/`. The SDK itself never reads environment files. Guzzle is a development-only concrete PSR-18/PSR-17 implementation for this harness; consumers choose their own implementation.

Install development dependencies, then run the authentication/token-reuse scenario with an explicit context:

```sh
composer test:sandbox -- --environment=sandbox --credentials-file=/absolute/private/path/.env --scenario=authentication --context=organization
composer test:sandbox -- --environment=sandbox --credentials-file=/absolute/private/path/.env --scenario=authentication --context=account
```

The credential source must be an absolute regular local file, limited to 64 KiB; URL and stream-wrapper sources are rejected. The external file uses `AIRWALLEX_CLIENT_ID` and `AIRWALLEX_SANDBOX_API_KEY_MASTER`. Account context additionally uses `AIRWALLEX_ACCOUNT_ID` as login `x-login-as`; organization context omits that header. A successful organization login does not establish Billing permissions, and account login does not establish connected-account delegation or collection eligibility. Supply credentials independently suitable for the selected context. `AIRWALLEX_ORGANIZATION_ID` is not substituted into an undocumented login header.

The parser accepts uppercase `NAME=value` entries, optional `export`, blank lines, comments and single/double quoted literal values. It never executes shell code, expands variables or interprets escapes; malformed or duplicate names fail. Running without options reports a skip. Partial configuration, a missing file or a non-sandbox environment fails before requests. No credentials, raw responses, tokens, customer data or stack traces are printed. The result is a sanitized scenario/version/context/status envelope; authentication validates the returned expiration through the SDK and confirms a second token request reuses memory.

Authentication creates no financial resources and requires no cleanup. Later journey scenarios must identify their own resources with a unique test-run marker, retain created identifiers only in private run state, and restrict teardown to those identifiers. Where a provider offers no deletion or archival operation, report retained test resources and their lifecycle explicitly. Never sweep existing account resources or use live financial operations for validation.

## Journey prerequisites and evidence

[API contracts](api-contracts.md) and [connected-account contracts](connected-account-contracts.md#evidence-still-needed) own product permissions and eligibility. Initial Billing journeys require organization Billing activation, legal entities, catalogue configuration and independently eligible linked collection accounts. Connected merchant Billing additionally requires provider confirmation of merchant organization/credential mapping, platform access and eligible collection linkage. An account ID or successful token exchange does not establish those facts.

[Webhook contracts](webhook-contracts.md#remaining-integration-evidence) identify matching-version provider-delivery and Retrieve evidence still required for Billing envelope ownership, event availability and subscription setup. Schema-derived fixtures remain synthetic. Payment simulations require the method-specific sandbox facilities and independently verified direct/delegated account authorization.

[#17](https://github.com/jojeringnax/airwallex-php-sdk/issues/17) owns this harness and initial context checks. [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42) owns the full accepted journey matrix: checkout/setup, subscription changes/trials/cancellation/recovery, invoice access, instruments, one-time/subsequent Payments, authorization/capture/cancel, refunds, webhook deliveries and two-account isolation. Each run must record exact commit, API/webhook version, scenario and pass/fail/blocked result without credentials or customer data. Authentication evidence alone does not satisfy those journeys or the [release gates](release-policy.md#observable-release-gates).

Official references: [sandbox environment](https://www.airwallex.com/docs/developer-tools/sandbox-environment/overview), [authentication](https://www.airwallex.com/docs/api/authentication/api_access_token), [Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html). Contract baseline: API `2026-08-21`.

## Billing catalogue writes

Run the explicit organization-only catalogue scenario after read/authentication checks:

```sh
composer test:sandbox -- --environment=sandbox --credentials-file=/absolute/private/path/.env --scenario=billing-catalogue --context=organization --state-dir=/absolute/private/run-state-directory
```

The state directory must already exist outside every Git checkout; a `.git` file or directory in any resolved ancestor rejects the location. If omitted, the harness creates a private `0700` run folder under the system temporary directory. The run marker is printed; provider resource identifiers remain in a private `0600` `ledger.json` inside that folder. Request identities and body hashes are persisted before mutations: the harness fsyncs the new folder's parent, the ledger contents, and the run directory after each atomic ledger rename. Unsupported or failed synchronization stops writes and poisons the ledger, including already inserted in-memory identities. Reusing an action identity for a changed body or another action fails. A no-identity endpoint receives no invented provider `request_id`; its local test intent is still recorded. A new invocation creates a new test run, and no mutation is automatically replayed after an ambiguous outcome.

The private ledger and corresponding safe result envelope include the Git base commit and SHA-256 digests of the effective source, including uncommitted changes. The SDK digest covers sorted relative names and file hashes under `src` and `resources/contracts`, plus `composer.json`; the harness digest covers `tests/Support/Sandbox` and `tools/sandbox.php`. Freeze those files during a run. A SHA-256 context fingerprint binds the ledger to the sandbox environment, credential identity, organization/account mode and applicable login-account selection. Every mutation identity and cleanup checks that fingerprint; raw credentials and account identifiers are excluded from the evidence envelope.

This scenario creates a synthetic Billing Customer, updates its description, creates one Product and two small EUR monthly per-unit Prices, and retrieves the resulting resources. Passing requires every submitted Customer field plus the updated description, the Product name/run marker/active state and each Price's amount, currency, Product, model, recurrence, run marker and active state. Decimal comparisons preserve exact value while accepting trailing zeros and scientific notation; they use no floats. Missing or different fields fail the scenario. It archives only the Product/Price identifiers returned by that run's successful create calls. Customer profiles and archived catalogue history remain because accepted APIs provide no deletion operation. Cleanup failures make the scenario fail and preserve the private inventory for reconciliation; existing account resources are never swept.

The command prepares its safe version/source evidence before prerequisite reads and adds the hashed owner context as soon as configuration is valid. Blocked legal-entity lookup and caught failures retain that available evidence. Invalid configuration or source discovery never invents a missing context or source identity. Deterministic command tests exercise the same dispatch path with fixture transports; they make no provider calls.

Before writes, the harness resolves configuration through the documented account-level `GET /api/v1/account` read, using explicit `x-login-as` and no delegation. It verifies that the returned account ID matches the configured account, then reads `account_details.legal_entity_id` without printing or storing the full response. Billing Customer creation explicitly supplies that legal-entity reference. Missing configuration blocks this scenario. This is a test prerequisite lookup, not a public SDK Account/onboarding capability. The [Billing Customer create contract](https://www.airwallex.com/docs/api/billing/billing_customers/create) identifies this lookup, and [Retrieve Account Details](https://www.airwallex.com/docs/api/scale/accounts/retrieve) specifies account-level authorization, `platform_account:read`, no on-behalf-of support and API `2026-08-21`.

Organization read/write success remains distinct from merchant Billing activation, eligible cross-family collection linkage, financial lifecycle outcomes and actual webhook deliveries. Those scenarios remain in #42's journey matrix.

## Billing checkout server matrix

Run `--scenario=billing-checkouts --context=organization` with the same explicit sandbox credentials/state options. The configured account is supplied as the proposed collection account, after the independent account/legal-entity read. The provider must accept that linkage; knowing an account ID alone does not establish eligibility.

This scenario creates a fresh synthetic Customer, Product, one recurring Price and one non-recurring Price. It creates, retrieves, updates metadata, cancels and retrieves Checkouts for all nine `SETUP`/`PAYMENT`/`SUBSCRIPTION` and `HOSTED`/`EMBEDDED`/`ELEMENTS` combinations, supplying the intended EUR currency explicitly in every mode. It checks owner/entity/collector, currency, mode, UI, metadata and applicable item identity/quantity; active hosted URLs and embedded client secrets remain private. A creation-to-expiry timestamp interval differing from the documented hour fails the overall contract observation, while independent metadata/cancellation checks continue. That timestamp check is not an observed transition to `EXPIRED`.

The test supplies public reserved `https://example.com` success/back destinations for `HOSTED`, and a return destination for `EMBEDDED`/`ELEMENTS`. No browser follows them. A controlled sandbox HOSTED probe accepted success/back URLs without `return_url`; another HOSTED request with `return_url` returned validation error terms identifying `return_url`/`ui_mode`. This is observed test configuration, not an added global SDK requiredness rule for fields absent from the selected schema's required list.

Each mode records its own outcome so a rejected setup path does not hide independent payment/subscription results. There is no browser purchase or instrument collection, no completed checkout, and no inference of paid invoices or active subscriptions. These completion/reconciliation and actual expiration journeys remain required under #42. The run cancels only its returned-created active Checkouts and archives its own catalogue; Customer/history remain. If a cancellation outcome is ambiguous, cleanup reads its current state and does not repeat the mutation. Update and cancel have no documented provider request identifier; the ledger records local intent without adding an undocumented field.

Source: [Billing Checkouts API](https://www.airwallex.com/docs/api/billing/billing_checkouts), selected version `2026-08-21`. The SDK supplies server operations; browser initialization and completion belong to the application.

## Manual Billing lifecycle

Run the separate organization scenario with the same external configuration and private state-directory rules:

```sh
composer test:sandbox -- --environment=sandbox --credentials-file=/absolute/private/path/.env --scenario=billing-lifecycle --context=organization --state-dir=/absolute/private/run-state-directory
```

This journey creates a fresh synthetic Customer, Product and two active EUR monthly `PER_UNIT`, `IN_ADVANCE` Prices, then a Subscription with `collection_method=OUT_OF_BAND`. The [collection-method guide](https://www.airwallex.com/docs/billing/subscriptions/get-started-with-subscription-management#subscription-payment-collection-methods) identifies this as manual external collection. It supplies no instrument or linked collection account and performs no invoice creation, finalization, payment, mark-paid or void operation. Required scopes cover the Customer/Product/Price reads and writes, `billing.subscription:read/write` and `billing.invoice:read`, plus the supporting account-configuration lookup described above.

The [selected Subscription contract](https://www.airwallex.com/docs/api/billing/subscriptions/create), API `2026-08-21`, supplies the future start and later trial end. The scenario observes `PENDING`, then uses separate recorded updates with `starts_at=NOW` and `trial_ends_at=NOW` to observe `IN_TRIAL` and `ACTIVE`. It uses the schema's one-month total `duration` as a finite lifetime; the [documented guide discrepancy](billing-resources.md#conflicts-and-remaining-evidence) remains explicit.

Item reads retain the returned item identity and decimal-string quantity. Quantity change explicitly selects `PRORATED` with `DEFER_CHARGE_AND_KEEP_CYCLE`; replacement deletes the existing item and adds the second Price using `NONE` with the same billing action. Read-back verifies the new quantity/Price and unchanged cycle anchor. These checks observe accepted configuration and cycle preservation; they do not prove next-cycle deferred accounting, immediate-charge/reset effects or calculate provider prorations.

On the still-active Subscription, the scenario sets `cancel_at_period_end=true`, observes that flag, then sends a separate update with `false` and observes it cleared. This records the flag round trip in that account/state. The finite duration remains; no cancellation reactivation, universal schedule-clearing behavior or actual renewal after period end is claimed.

Each asynchronous state/item/cycle checkpoint and missing-invoice discovery permits seven explicit GETs by default, with 1/2/4/8/8/7-second delays totaling 30 seconds; individual HTTP timeouts still apply. The test-only attempt bound is configurable from one to seven, and unit fixtures use fake waits. An unfinished transition remains `pending` with its exact observation step; it establishes neither product ineligibility nor successful completion. Invoice reads use the verified Subscription reference or both Customer/Subscription list filters, validate ownership, currency, collection method and exact decimal text, and read one bounded line-item page. The safe result records known invoice/payment statuses and separate document-field presence/nonempty flags; URLs remain private, and their usability is not tested. Missing, null and expired-link observations do not trigger a download or renewal request.

Every mutation has a distinct durably recorded request identity and is sent once. Any failed sent mutation is conservatively treated as ambiguous: subsequent writes, including cleanup, stop. The retained private ledger supports reconciliation. After acknowledged outcomes, cleanup first retrieves only recorded own Subscriptions, cancels a known non-cancelled state once with `proration_behavior=NONE`, and confirms `CANCELLED` within the read bound. Unconfirmed cancellation stops archive writes. Only afterward are this run's returned Price/Product IDs archived. Customer, Subscription and invoice history remain. Deterministic fixtures cover the scenario and failure gates; an actual run is separate evidence, and this manual journey does not validate instrument collection, recovery, webhook delivery or either cross-account Billing model.

### Separate manual lifecycle without a trial

Run `--scenario=billing-lifecycle-no-trial --context=organization` through `tools/sandbox.php`, or retain the narrow diagnostic output with:

```sh
php tools/billing-diagnostic.php --environment=sandbox --credentials-file=/absolute/private/path/.env --state-dir=/absolute/private/run-state-directory --scenario=billing-lifecycle-no-trial
```

This is a new run with its own Customer, catalogue, action identities and ledger. It omits the optional `trial_ends_at` field entirely, observes the requested future `PENDING` start, updates `starts_at=NOW`, and requires an observed `ACTIVE` state with valid cycle timestamps. It then performs the same quantity/proration, Price replacement, period-end flag reversal, invoice reads and owned cancellation/archive checks. Its result is labeled `billing-lifecycle-no-trial` with `trial_requested=false`; it never sends a trial-ending update or records trial-started/trial-ended checkpoints. Pending or rejected activation remains unfinished evidence under the same bounded GET/no-replay rules.

The original `billing-lifecycle` and default diagnostic path still submit the future start plus later trial, require `IN_TRIAL`, and explicitly end that trial before requiring `ACTIVE`. The trial scenario remains unvalidated against the provider: the observed fresh-create rejection identified only the lexical field term `trial_ends_at`, which establishes neither universal lack of support nor its precise conditional requirement. Passing the separate no-trial path cannot satisfy trial acceptance or alter the prior uncertain action.

For retained catalogue cleanup after either rejected create, first load each original private ledger under its exact organization/credential fingerprint and preserve its original action records. Retrieve only its recorded Customer, Product and two Prices, validate each returned ID and run marker, validate each Price's recorded Product relationship and EUR configuration, and stop if any reference is missing or mismatched. Archive only those recorded Prices, then that Product, through `active=false` updates; the [selected Price contract](https://www.airwallex.com/docs/api/billing/prices) defines this field as availability for new purchases. Already inactive resources need no write. Record distinct cleanup intents durably in a separate private ledger before each update, send each once, verify its own readback, and stop all further writes on an ambiguous outcome. Keep Customer/history and all uncertain Subscription state; this plan includes no Subscription cancellation, discovery-based sweep or replay of the failed create. An operator executes only an independently reviewed cleanup implementation; the lifecycle command does not resume or clean an earlier ledger.

### Reconcile an uncertain Subscription create

An operator can run the separate read-only probe against a private ledger; the agent preparing this helper does not read actual credentials or run state:

```sh
php tools/billing-reconcile.php --environment=sandbox --credentials-file=/absolute/private/path/.env --ledger-file=/absolute/private/run/ledger.json
```

The probe verifies API version, private file/directory permissions and the credential/environment/organization fingerprint before authentication. It lists Subscriptions filtered to that ledger's sole recorded Customer, retaining the filter through at most ten explicit pages of 100. Output contains only the fixed phase/version/result, counts and booleans for Customer-reference consistency, terminal-page evidence and correlations to the stored `create_future_trial` request ID/run marker. It emits no resource IDs, marker, bookmark, body or credentials and sends no resource mutation. The selected Subscription response schema does not promise `request_id`; separate presence counts keep absent correlation fields distinguishable from mismatches. Even an empty complete listing is a point-in-time observation, not permission to replay the original action.

For a separately authorized fresh run, use the fixed-root development entry point:

```sh
php tools/billing-diagnostic.php --environment=sandbox --credentials-file=/absolute/private/path/.env --state-dir=/absolute/private/run-state-directory
```

The command fixes organization context, accepts no prior ledger or alternate SDK root, and starts the existing Billing lifecycle with a new durable run ledger. It appends only the passive inspector's evidence and the diagnostic tool's SHA-256 to normal safe source/context/outcome evidence. It removes the lifecycle's generic provider-code/source fields so error details come only from the explicit allowlist. `SubscriptionErrorTransport` wraps the same guarded sandbox transport before constructing `SandboxSession`. It passively inspects only errors from the fixed Subscription-create route, snapshots at most 256 KiB from a seekable stream and restores its position. `SubscriptionErrorEvidence` returns only exact error-code literals from the [selected create contract](https://www.airwallex.com/docs/api/billing/subscriptions/create), fixed known field terms and a fixed phase. Field mentions are lexical diagnostic evidence, not declarations that a field caused the rejection. Raw messages, details, IDs and body values are neither returned nor retained. Unreadable/nonseekable bodies produce no body evidence.

A controlled reproduction uses a new private run ledger, new Customer/Product/Prices, a new action UUID and marker, and the same documented `OUT_OF_BAND`, currency, legal entity, `days_until_due`, lifetime `duration`, future start and later trial fields. Their presence in the selected schema does not establish which field or provider rule caused an earlier rejection. Preserve the uncertain original run and inspect the fresh allowlisted evidence before changing fields or declaring an external eligibility blocker.
