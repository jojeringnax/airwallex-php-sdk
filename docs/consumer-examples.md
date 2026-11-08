# Consumer interface examples

Use these examples when implementing or reviewing a consumer workflow. The [interface conventions](consumer-interfaces.md) define the shared public contract. Examples are executable-shaped specifications for future implementation; they have not been executed against an SDK or sandbox.

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
    'line_items' => [['price_id' => '<recurring-price-id>', 'quantity' => 1]],
    'success_url' => 'https://example.test/billing/complete',
    'back_url' => 'https://example.test/billing',
]);
$checkoutState = $billing->billing()->checkouts()->retrieve($checkout['id']);
```

Authorize the local buyer before every call; organization credentials can access multiple buyers. A hosted checkout's active `url` is the redirect target. With `ui_mode=EMBEDDED` or `ELEMENTS`, deliver `client_secret` only to the authorized browser for the corresponding Airwallex.js integration; keep it out of logs. PHP does not mount browser components. Neither redirect nor browser callback proves payment completion: retrieve provider state and reconcile verified events. See [checkout boundaries](billing-capabilities.md#checkout-ui-contracts) for one-hour expiry and nullable response fields.

The same `checkouts()->create()` shape covers the following modes, each with a fresh persisted request ID. UI mode is independent of the business mode; mode-specific constraints must be tested in [#21](https://github.com/jojeringnax/airwallex-php-sdk/issues/21).

| Mode | Payload change / use |
| --- | --- |
| `SUBSCRIPTION` | Recurring Price line items; optional `subscription_data` with `starts_at` and `trial_ends_at` |
| `PAYMENT` | One-time Price line items; reconcile the resulting payment/invoice rather than assuming a subscription |
| `SETUP` | Omit `line_items`; collect an instrument for the existing Billing Customer and eligible linked account, then retrieve resulting state before charging later |

Checkout update uses `update($id, $body)` with only verified mutable fields; cancellation uses `cancel($id)` without an invented request ID. Retrieve after ambiguous outcomes rather than assuming safe replay. A setup alone is not consent to an arbitrary later charge.

## Saved instrument and direct subscription

The application first creates a Payments Customer using `payments()->customers()->create($body)` and stores it separately from the Billing Customer. Create a Consent using `paymentConsents()->create()` with `customer_id`, persisted `request_id`, `next_triggered_by=merchant` and the applicable `merchant_trigger_reason`; the documented Billing source flow uses `unscheduled`. Use approved browser/provider verification to collect details, call `verify($id, $body)` / `continueVerification($id, $body)` where required, and retrieve state. Payment-method-specific bodies and browser actions require their own endpoint fixtures; no raw card data belongs in these examples.

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

Before linkage, verify the local buyer, Consent/Payments Customer ownership, Billing Customer and collection account together. The SDK cannot infer cross-family ownership from ID strings. Other methods need verified linkage; this card-shaped example does not restrict accepted method coverage. The API requires the Method ID as `external_id`, not the Consent ID; see the [source contract discrepancy](api-contracts.md#representative-contract-checks).

For provider trials/future starts, add timezone-bearing `starts_at` and later `trial_ends_at` strings to Subscription create, or to checkout `subscription_data`. Retrieve observed `PENDING`/`IN_TRIAL` and subsequent state. A generic local trial is application storage/access policy with no SDK call; creating a remote subscription remains a deliberate financial action. Do not hide it in a trial convenience method.

## Changes, cancellation and invoice access

```php
$changed = $billing->billing()->subscriptions()->update($subscription['id'], [
    'request_id' => '<persisted-quantity-change-request-id>',
    'items' => [['id' => '<existing-item-id>', 'quantity' => 3]],
    'default_proration_mode' => 'PRORATED',
]);
// Replacing a price removes the old item and adds a new one in the same update.
$changedPlan = $billing->billing()->subscriptions()->update($subscription['id'], [
    'request_id' => '<persisted-plan-change-request-id>',
    'items' => [
        ['id' => '<existing-item-id>', 'deleted' => true],
        ['price_id' => '<new-recurring-price-id>', 'quantity' => 3],
    ],
    'default_proration_mode' => 'NONE',
]);
```

An omitted existing item stays unchanged; its `price_id` cannot be edited in place. `NONE`, `PRORATED` and `ALL` follow provider proration semantics; per-item `proration_mode` overrides the default. The SDK does not compute credits or hide a billing-cycle reset. Billing anchors, state restrictions and item-level fixtures belong to [#24](https://github.com/jojeringnax/airwallex-php-sdk/issues/24) and [#25](https://github.com/jojeringnax/airwallex-php-sdk/issues/25).

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

Cancellation modes, scheduled-end constraints and the schedule-reversal evidence gate follow [trials, cancellation and grace](billing-capabilities.md#trials-cancellation-and-grace). The public surface excludes pause, resume and cancelled-reactivation methods; the application selects its access-grace policy.

A draft invoice may have null PDF; authorize document access before returning any link. Download, storage and email belong to consumers. Re-fetching current data does not promise expired-link renewal; preserve the [expiry evidence gap](billing-capabilities.md#invoice-access-and-delivery). No hosted Billing portal-session method is defined.

Payment recovery uses an explicit `subscriptions()->update($id, ['request_id' => ..., 'payment_source_id' => ...])` after verifying the replacement source's ownership. This can immediately retry the last failed invoice. Retrieve subscription/invoice state afterwards; no automatic source replacement, dunning schedule or entitlement cutoff is implied.

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

Refunds use `refunds()->create(['request_id' => ..., 'payment_intent_id' => $intent['id'], 'amount' => '5.00', 'reason' => 'requested_by_customer'])`, then `retrieve($refundId)` to inspect status. Do not treat acceptance as completed settlement. Retrieve/list Payment Attempts for status details and `paymentMethods()->retrieve($methodId)` for saved instrument details. Full method-specific schemas and failure paths require endpoint implementation fixtures.

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

Retain all original filters and context on subsequent pages. Billing bookmarks and Payments page numbers remain distinct; no automatic network iteration is promised. List bounds/envelopes are verified per resource in [#16](https://github.com/jojeringnax/airwallex-php-sdk/issues/16).

All SDK exceptions implement `Jojeringnax\Airwallex\Exception\SdkException` (a `Throwable` interface). `ConfigurationException` covers invalid setup/context, `ValidationException` invalid local arguments, `TransportException` no usable HTTP response, `ProtocolException` malformed successful responses, and `ApiException` HTTP failures. `ApiException` exposes `httpStatus(): int`, `providerCode(): ?string` and `providerSource(): ?string`; invalid/missing provider error JSON leaves provider fields null while retaining HTTP status. Provider message/details, secrets and raw bodies are excluded from default exception messages, serialization and logs. Diagnostic design belongs to [#14](https://github.com/jojeringnax/airwallex-php-sdk/issues/14).

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

`verify(string $rawBody, string $timestamp, string $signature): void` throws `WebhookVerificationException` (an SDK exception) on malformed, invalid or out-of-tolerance deliveries. Freshness tolerance is required explicitly, a non-negative integer in milliseconds; no undocumented provider default is selected. Reject timestamps beyond tolerance in either direction. Verification uses the exact timestamp string plus raw body, HMAC-SHA256 and constant-time comparison; header parsing and tests belong to [#29](https://github.com/jojeringnax/airwallex-php-sdk/issues/29).

After successful verification, `WebhookEvent::fromJson($rawBody)` in the same namespace losslessly decodes the envelope and exposes `toArray(): array`. Invalid envelopes throw `ProtocolException`. Preserve unknown event names/versions; consumers route unsupported versions/names to their own quarantine rather than interpreting resource state. The supported versioned event catalogue and required envelope fields are defined by [#8](https://github.com/jojeringnax/airwallex-php-sdk/issues/8), not by this example. Configure the provider webhook subscription to the SDK baseline independently of request headers.

Consumer processing and persistence follow [state ownership](architecture.md#state-and-persistence-ownership) and the [webhook contract](api-contracts.md#webhook-contract). Verification and decoding complete the SDK call; application processing completes separately.

