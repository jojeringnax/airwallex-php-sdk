# Consumer interface examples

Use these accepted workflow shapes when implementing or reviewing consumer calls. The [interface conventions](consumer-interfaces.md) own the shared public contract; the [consumer guide](usage.md) supplies development-source installation and an index of runnable recipe files executed against deterministic fixtures. The snippets below describe the same supported calls without claiming sandbox or account-specific validation. Operation details and evidence boundaries remain in [Billing resources](billing-resources.md), [Payments contracts](payments-contracts.md) and the [webhook catalogue](webhook-contracts.md).

## Initialization and account contexts

```php
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;

// Inject consumer-created PSR-18 / PSR-17 implementations.
$billing = new Client(
    environment: Environment::Sandbox,
    credentials: new Credentials(clientId: '<org-client-id>', apiKey: '<org-api-key>'),
    httpClient: $httpClient,
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
);
// Optional constructor argument tokenCache: $psr16Cache; otherwise memory reuse.
$payments = new Client(
    environment: Environment::Sandbox,
    credentials: new Credentials(clientId: '<account-client-id>', apiKey: '<account-api-key>'),
    httpClient: $httpClient,
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
    loginAccountId: '<collection-account-id>',
);
// Platform credentials are distinct from the organization's Billing credentials.
$platform = new Client(
    environment: Environment::Sandbox,
    credentials: new Credentials(clientId: '<platform-client-id>', apiKey: '<platform-api-key>'),
    httpClient: $httpClient,
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
);
$merchantA = $platform->forConnectedAccount('<connected-account-A>');
$merchantB = $platform->forConnectedAccount('<connected-account-B>');
$paymentA = $merchantA->payments()->paymentIntents()->retrieve('<intent-owned-by-A>');
```

Environment is mandatory; production requires explicit `Environment::Production`. The API pin is fixed by the SDK baseline, not an arbitrary constructor version string. No credentials or endpoint URLs come implicitly from environment variables. TLS verification remains enabled by the injected transport.

Clients and derived account contexts are immutable. `forConnectedAccount(string $id): Client` returns a new Payments delegation context and leaves the original unchanged. Billing calls on a delegated client fail locally with `ConfigurationException`; they never send `x-on-behalf-of`. Combining login-account selection with delegation is rejected until independently verified. `loginAccountId` maps to login `x-login-as`; delegation maps to resource `x-on-behalf-of`. Token/cache keys isolate environment, credentials, login account and effective delegated account. No bearer token or plaintext credential enters diagnostic output.

Choose the owning organization and eligible collection account using the [two-account walkthroughs](connected-account-contracts.md#two-account-walkthroughs).

## Customer linkage and checkout

```php
$customer = $billing->billing()->customers()->create([
    'request_id' => '<persisted-create-customer-request-id>',
    'email' => 'buyer@example.test',
    'default_legal_entity_id' => '<legal-entity-id>',
]);
$billingCustomerId = $customer['id'];
// Application persists the ID with its local buyer, environment and organization.
$checkout = $billing->billing()->checkouts()->create([
    'request_id' => '<persisted-checkout-request-id>',
    'billing_customer_id' => $billingCustomerId,
    'legal_entity_id' => '<legal-entity-id>',
    'linked_payment_account_id' => '<eligible-collection-account-id>',
    'mode' => 'SUBSCRIPTION',
    'ui_mode' => 'HOSTED',
    'currency' => 'EUR', // Match the recurring Price's currency.
    'subscription_data' => ['days_until_due' => 0],
    'line_items' => [['price_id' => '<recurring-price-id>', 'quantity' => 1]],
    'success_url' => 'https://example.test/billing/complete',
    'back_url' => 'https://example.test/billing',
]);
$checkoutState = $billing->billing()->checkouts()->retrieve($checkout['id']);
```

Authorize the local buyer before every call; organization credentials can access multiple buyers. A hosted checkout's active `url` is the redirect target. With `ui_mode=EMBEDDED` or `ELEMENTS`, deliver `client_secret` only to the authorized browser for the corresponding Airwallex.js integration; keep it out of logs. PHP does not mount browser components. Neither redirect nor browser callback proves payment completion: retrieve provider state and reconcile verified events. See [checkout boundaries](billing-capabilities.md#checkout-ui-contracts) for expiry/timestamp evidence and nullable response fields.

The same `checkouts()->create()` shape covers the following modes, each with a fresh persisted request ID. UI mode is independent of the business mode; mode-specific contracts and deterministic coverage are documented in [Billing resources](billing-resources.md).

| Mode | Payload change / use |
| --- | --- |
| `SUBSCRIPTION` | Recurring Price line items and explicit `subscription_data`, as above; add `starts_at`, `trial_ends_at` or finite `duration` only when intended |
| `PAYMENT` | One-time Price line items and `invoice_data`, for example `['days_until_due' => 0]`; reconcile the resulting payment/invoice |
| `SETUP` | Supply `currency` and the existing Billing Customer; omit `line_items`; collect an instrument under the eligible linked account, then retrieve resulting state before charging later |

Checkout update uses `update($id, $body)` with only verified mutable fields; cancellation uses `cancel($id)` without an invented request ID. Retrieve after ambiguous outcomes rather than assuming safe replay. A setup alone is not consent to an arbitrary later charge.

For SUBSCRIPTION, `days_until_due=0` states the selected default explicitly; PAYMENT uses zero as the example's chosen due-date setting. The subscription example leaves `duration` absent for an ongoing subscription. A [bounded sandbox matrix](https://github.com/jojeringnax/airwallex-php-sdk/issues/42#issuecomment-6722652823) passed all nine mode/UI control flows with these mode-specific objects and a finite one-month subscription duration; it did not complete a purchase or validate every optional-field combination. The SDK retains the selected schema's requiredness rules.

## Saved instrument and direct subscription

The application first creates a Payments Customer using `payments()->customers()->create($body)` with `merchant_customer_id` and a persisted `request_id`, and stores it separately from the Billing Customer. Create a Consent using `paymentConsents()->create()` with `customer_id`, persisted `request_id`, `next_triggered_by=merchant` and the applicable `merchant_trigger_reason`; the documented Billing source flow uses `unscheduled`. Use approved browser/provider verification to collect details, call `verify($id, $body)` / `continueVerification($id, $body)` where required, and retrieve state. Use the [selected method-specific contracts](payments-contracts.md#instruments-confirmation-and-continuation) and approved browser/provider actions; no raw card data belongs in these examples.

```php
$consent = $payments->payments()->paymentConsents()->retrieve('<verified-consent-id>');
if ($consent['status'] !== 'VERIFIED') {
    throw new RuntimeException('Complete instrument verification first.');
}
// Card example: the verified Consent exposes payment_method.id.
$source = $billing->billing()->paymentSources()->create([
    'request_id' => '<persisted-source-request-id>',
    'billing_customer_id' => $billingCustomerId,
    'external_id' => $consent['payment_method']['id'],
    'linked_payment_account_id' => '<same-collection-account-id>',
]);
$subscription = $billing->billing()->subscriptions()->create([
    'request_id' => '<persisted-subscription-request-id>',
    'billing_customer_id' => $billingCustomerId,
    'collection_method' => 'AUTO_CHARGE',
    'payment_source_id' => $source['id'],
    'legal_entity_id' => '<legal-entity-id>',
    'linked_payment_account_id' => '<same-collection-account-id>',
    'items' => [['price_id' => '<recurring-price-id>', 'quantity' => 1]],
]);
```

The [executed card-linkage recipe](../examples/billing-card-subscription.php) implements this sequence with identity/ownership guards and stops before Subscription create if the returned source is unusable. Before linkage, verify the local buyer, Consent/Payments Customer ownership, Billing Customer and collection account together. The SDK cannot infer cross-family ownership from ID strings. Other methods need verified linkage; this card-shaped example does not restrict accepted method coverage. The API requires the Method ID as `external_id`, not the Consent ID; see the [source contract discrepancy](api-contracts.md#representative-contract-checks).

For provider trials/future starts, add timezone-bearing `starts_at` and later `trial_ends_at` strings to Subscription create, or to checkout `subscription_data`. Retrieve observed `PENDING`/`IN_TRIAL` and subsequent state. A generic local trial is application storage/access policy with no SDK call; creating a remote subscription remains a deliberate financial action. Do not hide it in a trial convenience method.

## Changes, cancellation and invoice access

```php
$changed = $billing->billing()->subscriptions()->update($subscription['id'], [
    'request_id' => '<persisted-quantity-change-request-id>',
    'items' => [['id' => '<verified-initial-item-id>', 'quantity' => 3]],
    'default_proration_mode' => 'PRORATED',
]);
// Refresh listItems/retrieveItem and reconcile the intended owned item before this separate action.
// NONE requests a next-cycle fee change; listing the new entry does not establish immediate replacement.
$changedPlan = $billing->billing()->subscriptions()->update($subscription['id'], [
    'request_id' => '<persisted-plan-change-request-id>',
    'items' => [
        ['id' => '<refreshed-selected-item-id>', 'deleted' => true],
        ['price_id' => '<new-recurring-price-id>', 'quantity' => 3],
    ],
    'default_proration_mode' => 'NONE',
]);
```

An omitted existing item stays unchanged; its `price_id` cannot be edited in place. `NONE`, `PRORATED` and `ALL` follow provider proration semantics; per-item `proration_mode` overrides the default. Per-item `CUSTOM` accepts application-chosen decimal strings, and independent `proration_date` selects a calculation time within the current period. The [runnable proration recipes](../examples/billing-proration.php) demonstrate both additions. The SDK does not compute credits or hide a billing-cycle reset. The [Billing financial-control reference](billing-resources.md#choosing-subscription-financial-controls) documents provider defaults, explicit billing actions and deterministic coverage; current-state restrictions remain provider-enforced.

Item IDs are provider references, and their retention across updates is not guaranteed. Before each later item mutation, refresh the same owning Subscription's `listItems()`/`retrieveItem()` data and match its Subscription, nested `price.id`, currency, exact quantity and the application's known action. Do not reuse an earlier ID without reconciliation or choose the first/latest row. The selected item schema has no active/deleted/effective-date classifier; its `current_period_*` fields describe invoiced periods. A list can contain the known prior entry and a scheduled target. If the intended item cannot be selected unambiguously, stop and resolve its lifecycle before another mutation. The recipes accept an already verified caller-selected ID and perform no hidden selection.

Choose **one** cancellation action according to consumer intent:

```php
$scheduled = $billing->billing()->subscriptions()->update($subscription['id'], [
    'request_id' => '<persisted-schedule-end-request-id>',
    'cancel_at_period_end' => true,
]);
// Alternative: immediate permanent cancellation, not the next step after scheduling.
$cancelled = $billing->billing()->subscriptions()->cancel('<other-subscription-id>', [
    'request_id' => '<persisted-immediate-cancel-request-id>',
    'proration_behavior' => 'NONE',
]);
$invoice = $billing->billing()->invoices()->retrieve('<invoice-id-owned-by-buyer>');
$pdfUrl = $invoice['pdf_url'] ?? null;
$hostedUrl = $invoice['hosted_url'] ?? null;
```

Cancellation modes, scheduled-end constraints and schedule-reversal observations follow [trials, cancellation and grace](billing-capabilities.md#trials-cancellation-and-grace). The public surface excludes pause, resume and cancelled-reactivation methods; the application selects its access-grace policy.

For the observed **ACTIVE, finite EUR OUT_OF_BAND** case, preserve the original future `ends_at` before requesting the ending flag. The [executed finite recipes](../examples/billing-lifecycle.php) make that ordering explicit:

```php
$recipes = require 'examples/billing-lifecycle.php';
$scheduled = $recipes['schedule_finite_end'](
    $billing, $subscriptionId, $billingCustomerId, $legalEntityId,
    '<persisted-schedule-request-id>', '<separate-persisted-restore-request-id>',
    $persistPrivateSnapshot, // Application callback: commit the supplied snapshot durably or throw.
);
// A later authorized buyer action loads that same private pre-scheduling snapshot from storage.
$restored = $recipes['restore_finite_end']($billing, $storedPrivateSnapshot);
```

The snapshot contains the exact original end, owner mapping and both distinct action IDs. Commit it before the true update; that update can shorten the visible end, so do not reconstruct the old end afterward. After verifying the same owned ACTIVE state with the flag true, restoration sends only `request_id`, `cancel_at_period_end=false` and that exact saved `ends_at`; it omits `duration` and `remaining_duration`. The recipe retrieves once and requires the same owner, ACTIVE, false and the same end instant without losing fractional precision. Missing, mismatched or still-pending readback stops the recipe. Continue reconciliation with explicit GETs and application action records; never replay a sent restoration because a response was rejected, lost or not yet observed.

The [observed provider case](billing-capabilities.md#trials-cancellation-and-grace) used a daily Price and a seven-day lifetime whose original end lay beyond the first daily period. The application must verify its own intended Price/lifetime and authorize both actions; the recipe does not establish another configuration's eligibility. This is finite-state restoration, not a guarantee for false alone, other states or collection methods, all schedule-field combinations or indefinite renewal. It never reactivates a CANCELLED Subscription or changes application access policy.

A draft invoice may have null PDF; authorize document access before returning any link. Download, storage and email belong to consumers. Re-fetching current data does not promise expired-link renewal; preserve the [expiry evidence gap](billing-capabilities.md#invoice-access-and-delivery). No hosted Billing portal-session method is defined.

For an application-known expired instrument, first obtain and independently authorize its replacement through the applicable setup flow. Load the persisted buyer, Billing organization-client, legal-entity and collection-account mapping. The [executed recovery recipes](../examples/billing-lifecycle.php) use the existing Subscription and Payment Source reads to check that mapping before one explicit source change:

```php
$recipes = require 'examples/billing-lifecycle.php';
$observed = $recipes['replace_source_and_observe'](
    $billing, $subscriptionId, $authorizedReplacementSourceId,
    $billingCustomerId, $legalEntityId, $collectionAccountId, '<persisted-recovery-request-id>',
);
// Persist the action outcome privately. After a rejection, lost response or failed read, use only reads:
$later = $recipes['observe_recovery'](
    $billing, $subscriptionId, $billingCustomerId, $legalEntityId, $collectionAccountId,
);
```

The application authorizes the client and replacement instrument; the SDK cannot infer organization membership or merchant-initiated authorization from opaque IDs. The example checks the current Subscription's ID, Billing Customer, legal entity and linked collection account, then the replacement Source's ID, Customer and collector. Missing optional owner fields withhold this example's proof; they are not newly required API fields. These are SDK-decoded-field comparisons, not separate raw-JSON identity or duplicate-key validation. No Payment Source expiry field, instrument usability or current collector/product eligibility is inferred. Preconditions or Source-read failures throw before the update. Resolve those failures before choosing a deliberate action.

Changing `payment_source_id` can immediately retry the last failed invoice under the selected `2026-08-21` [Subscription contract](../resources/contracts/billing/subscriptions.json). The application supplies one already-persisted `request_id`; the callable neither generates an identity nor retries the update. Record that the action was attempted before invoking it, and do not rerun the mutation recipe to reconcile an uncertain result. Application persistence supplies that cross-call discipline; this example is not an idempotency store.

The return separates `update_response`/`update_error` from the later `subscription`, `invoice` and `read_error`. Provider API errors retain only the SDK's safe status/code fields; a transport/protocol failure remains `unconfirmed`. An unproved update response is withheld without implying that no effect occurred. Reconciliation reads return only matching owned Subscription/Invoice observations; a failed or unproved read leaves that resource null and a fixed error fact. The latest Invoice is followed only when a nonempty reference is supplied, and its ID, Customer and Subscription must match. Otherwise the unchanged Subscription preserves the missing/null/unknown reference. No list, alternate lookup or Invoice mutation is hidden in the recipe.

Read-only observations preserve unknown, nonterminal, repeated and reordered provider states, exact monetary values and extra fields. They establish neither that the source change caused an observed state nor that payment, settlement or access entitlement succeeded. A response or later error never schedules another source change, collection retry or dunning action. Actual reusable-instrument/Source linkage, eligible collection accounts and account-configured recovery/dunning evidence remain under [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42); the [dunning conflict](api-contracts.md#payment-recovery-conflict) does not establish a universal retry schedule. These deterministic recipes do not depend on another browser diagnostic or promise a live recovery outcome.

## Subscription items and Invoice line items

Use the [item-read recipe](../examples/billing-item-reads.php) after authorizing the buyer and resolving stored parent/item IDs in the owning Billing organization and environment:

```php
$readItems = require $sdkDirectory . '/examples/billing-item-reads.php';
$items = $readItems(
    $billing,
    '<subscription-id>',
    '<subscription-item-id>',
    '<invoice-id>',
    '<invoice-line-item-id>',
);
```

The recipe calls all four [accepted methods](consumer-interfaces.md#subordinate-reads), returning two individual items and one page per parent. Each list preserves the native `items`, `page_after` and `page_before` envelope. When choosing another page, pass its non-null bookmark as `page` to `listItems($subscriptionId, $query)` or `listLineItems($invoiceId, $query)`, retaining the original `page_size`, parent ID and authorized client. These subordinate lists support only `page` and `page_size` in API `2026-08-21`. Empty pages and provider failures remain visible; the recipe performs no automatic traversal.

## Standalone Payments

Use the effective owning account client throughout a payment/refund journey. The same resource methods apply to direct accounts and independently eligible delegated connected accounts.

```php
$intent = $payments->payments()->paymentIntents()->create([
    'request_id' => '<persisted-intent-request-id>',
    'merchant_order_id' => '<application-order-id>',
    'amount' => '19.90',
    'currency' => 'EUR',
]);
$observed = $payments->payments()->paymentIntents()->retrieve($intent['id']);
```

Confirmation uses `confirm($id, $body)` with persisted `request_id` and verified method/Consent details; follow `next_action` via approved browser or `continueConfirmation($id, $body)` calls. A merchant-initiated subsequent charge explicitly references the saved Consent in the same account and follows its authorization. For separate authorization/capture, select documented manual capture settings at confirmation; `capture($id, ['request_id' => ..., 'amount' => '19.90'])` applies only to a capturable authorization. `cancel($id, ['request_id' => ...])` requires a cancellable Intent state. These are alternative state-dependent actions, not an unconditional sequence.

Refunds use `refunds()->create(['request_id' => ..., 'payment_intent_id' => $intent['id'], 'amount' => '5.00', 'reason' => 'requested_by_customer'])`, then `retrieve($refundId)` to inspect status. Do not treat acceptance as completed settlement. Retrieve/list Payment Attempts for status details and `paymentMethods()->retrieve($methodId)` for saved instrument details. The [Payments contracts](payments-contracts.md) record selected method-specific schemas and deterministic failure coverage; actual method/account acceptance remains sandbox/provider evidence.

## Pagination and failures

```php
$page = $billing->billing()->customers()->list(['page_size' => 20]);
if (($page['page_after'] ?? null) !== null) {
    $next = $billing->billing()->customers()->list([
        'page_size' => 20,
        'page' => $page['page_after'],
    ]);
}
$paymentPage = $payments->payments()->paymentIntents()->list([
    'page_num' => 0, 'page_size' => 10,
]);
// If has_more is true, request page_num 1 with the same filters and client.
```

Retain all original filters and context on subsequent pages. Billing bookmarks and Payments page numbers remain distinct; no automatic network iteration is promised. The [Billing](billing-resources.md#validation-and-precision) and [Payments](payments-contracts.md#refunds-errors-and-replay) references document their selected list bounds and envelopes.

All SDK exceptions implement `Jojeringnax\Airwallex\Exception\SdkException` (a `Throwable` interface). `ConfigurationException` covers invalid setup/context, `ValidationException` invalid local arguments, `TransportException` no usable HTTP response, `ProtocolException` malformed successful responses, and `ApiException` HTTP failures. `ApiException` exposes `httpStatus(): int`, `providerCode(): ?string` and `providerSource(): ?string`; invalid/missing provider error JSON leaves provider fields null while retaining HTTP status. Provider message/details, secrets and raw bodies are excluded from default exception messages, serialization and logs. The [client diagnostic reference](client-configuration.md#responses-paging-and-failures) records safe fields and default diagnostic behavior.

```php
try {
    $state = $billing->billing()->subscriptions()->retrieve('<subscription-id>');
} catch (\Jojeringnax\Airwallex\Exception\ApiException $error) {
    $status = $error->httpStatus();
    $providerCode = $error->providerCode();
    // Match documented codes/statuses, not provider message text.
    // Reconcile an ambiguous mutation outcome; do not create a new request ID.
} catch (\Jojeringnax\Airwallex\Exception\SdkException $error) {
    // Application selects recovery without logging credentials or raw payloads.
}
```

HTTP retries and provider collection retries remain separate. Preserve original payload and identity if replay becomes independently safe. Unknown provider state is returned for consumer review, never mapped to paid/access-granted.

## Webhook boundary

```php
use Jojeringnax\Airwallex\Webhook\WebhookVerifier;

$verifier = new WebhookVerifier(
    secret: '<endpoint-secret>',
    toleranceMilliseconds: $consumerSelectedToleranceMilliseconds,
    clock: $clock,
);
// $clock is a Closure returning current Unix milliseconds as an int.
// Preserve the exact bytes/string received from the HTTP request.
$verifier->verify($rawBody, $timestampHeader, $signatureHeader); // void or exception
```

`verify(string $rawBody, string $timestamp, string $signature): void` throws `WebhookVerificationException` (an SDK exception) on malformed, invalid or out-of-tolerance deliveries. Freshness tolerance is required explicitly, a non-negative integer in milliseconds; no undocumented provider default is selected. Reject timestamps beyond tolerance in either direction. Verification uses the exact timestamp string plus raw body, HMAC-SHA256 and constant-time comparison; header parsing, numeric decoding and error behavior are documented in the [implemented webhook boundary](webhook-contracts.md#php-verification-and-decoding).

After successful verification, `WebhookEvent::fromJson($rawBody)` in the same namespace losslessly decodes a JSON object and exposes `toArray(): array`. This method converts the root envelope to an array, including `[]` for an empty root object; nested empty objects retain `stdClass` under the shared representation rules. Malformed JSON or a non-object root throws `ProtocolException`.

A valid JSON object with an unknown name, missing or unsupported version, conflicting envelope fields or unknown resource status remains unrecognized and available for consumer inspection; it receives no typed state transition. Preserve supplied fields without normalizing ownership or assigning the SDK version. Consumers retain the original `$rawBody` alongside the decoded fields when inspection or quarantine needs the exact bytes; storage and retention belong to the application. The [webhook catalogue](webhook-contracts.md#unknown-events-versions-and-reconciliation) defines interpretation and supplemental delivery observations. Configure the provider webhook subscription to the SDK baseline independently of request headers.

Selected `billing_checkout.*`, `subscription.*` and `invoice.*` observations can contain the Retrieve resource directly in `data`, while generic envelopes contain `data.object`. The decoder preserves both forms. Without a competing `object` key, their known integer fields use the same selected-schema representation as generic resources. Existing generic selection and other direct families remain unchanged. Direct Subscription and Invoice development consumers should remove string-only assumptions at their [four known integer positions](payload-precision.md); direct Checkout retains its six known positions. Money, extra numbers, decimal/exponent forms, quoted numbers and overflow retain exact strings. This expands numeric representation before the initial release, not event acceptance or state transitions; public signatures are unchanged.

After the existing raw-body verification, a consumer can inspect the original resource shape:

```php
$envelope = WebhookEvent::fromJson($rawBody)->toArray();
$data = $envelope['data'] ?? null;
$checkout = is_array($data) && array_key_exists('object', $data)
    ? $data['object'] : $data;
// Trusted endpoint/environment/owner/resource acceptance and durable deduplication are application work.
if (($envelope['version'] ?? null) === '2026-08-21'
    && ($envelope['name'] ?? null) === 'billing_checkout.created'
    && ($envelope['org_id'] ?? null) === $trustedOrganization
    && is_array($checkout) && ($checkout['id'] ?? null) === $knownCheckoutId) {
    $quantity = $checkout['line_items'][0]['quantity'] ?? null;
    if (is_int($quantity)) {
        $observedQuantity = $quantity; // No cast or financial/access decision follows from its type.
    }
}
```

A SETUP with no line items or an unrecognized representation may supply no usable quantity. A matching direct Subscription cancellation can likewise return `duration.period` as PHP integer zero:

```php
$subscription = is_array($data) && array_key_exists('object', $data) ? $data['object'] : $data;
if (($envelope['version'] ?? null) === '2026-08-21'
    && ($envelope['name'] ?? null) === 'subscription.cancelled'
    && ($envelope['org_id'] ?? null) === $trustedOrganization
    && is_array($subscription) && ($subscription['id'] ?? null) === $knownSubscriptionId) {
    $period = $subscription['duration']['period'] ?? null;
    if (is_int($period)) {
        $observedDurationPeriod = $period; // Preserve zero; its type grants no financial or access decision.
    }
}
```

For a matching Invoice, the same boundary preserves zero remaining automatic attempts as an integer while money remains text:

```php
$invoice = is_array($data) && array_key_exists('object', $data) ? $data['object'] : $data;
if (($envelope['version'] ?? null) === '2026-08-21'
    && ($envelope['name'] ?? null) === 'invoice.payment.paid'
    && ($envelope['org_id'] ?? null) === $trustedOrganization
    && is_array($invoice) && ($invoice['id'] ?? null) === $knownInvoiceId) {
    $remaining = $invoice['remaining_payment_attempt_count'] ?? null;
    if (is_int($remaining)) {
        $observedRemainingAttempts = $remaining; // A count grants no settlement or access decision.
    }
}
```

Current numeric selection operates on lossless decoded values and is not native identity validation, duplicate-key rejection or validation of numeric bounds. The [catalogue conflict record](webhook-contracts.md#documentation-conflicts-and-interpretation) distinguishes the scoped Checkout, historical Subscription and Invoice observations. The executed verification recipe covers all three direct shapes, exact amounts and integer representation; decoding alone establishes no instrument completion, collection or application entitlement.

Consumer processing and persistence follow [state ownership](architecture.md#state-and-persistence-ownership) and the [webhook contract](api-contracts.md#webhook-contract). Verification and decoding complete the SDK call; application processing completes separately.

