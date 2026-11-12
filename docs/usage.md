# Consumer guide

Use this guide to install the SDK and connect the implemented SDK calls to your application's buyer, account and operation records. [Consumer interfaces](consumer-interfaces.md) define the public signatures; [Billing resources](billing-resources.md), [Payments contracts](payments-contracts.md) and the [webhook catalogue](webhook-contracts.md) own operation-specific behavior.

## Install

Use **64-bit PHP 8.3, 8.4 or 8.5** and Composer 2. The [runtime boundary](architecture.md#package-and-runtime-support) and Composer platform checks apply to the complete SDK, including webhook verification. In your application, install the `0.1` release line:

```sh
composer require "jojeringnax/airwallex-php-sdk:^0.1"
```

Retain your application's `composer.lock` and review dependency changes before updating. The `^0.1` constraint accepts compatible patch releases within `0.1`; breaking changes require a new minor under the [pre-1.0 compatibility policy](release-policy.md#versioning-and-public-surface).

The SDK requires the approved PSR HTTP/client/factory/cache interfaces and supplies no concrete HTTP client. The [architecture](architecture.md#dependencies-and-transport) owns those dependency lines. One consumer choice is [Guzzle](https://docs.guzzlephp.org/en/stable/overview.html), installed in your application with `composer require guzzlehttp/guzzle:^7.10`; its PSR-7 package provides `GuzzleHttp\Psr7\HttpFactory`.

## Install the unreleased development source

For contributions or unreleased changes, register the repository in a consuming application's `composer.json` and require its development branch:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/jojeringnax/airwallex-php-sdk"
        }
    ],
    "require": {
        "php": ">=8.3 <8.6",
        "jojeringnax/airwallex-php-sdk": "dev-main"
    }
}
```

For a new application manifest without a lock file, run `composer update`. For an existing application lock, run `composer update jojeringnax/airwallex-php-sdk --with-dependencies` and review the resolved dependency changes. Retain the resulting `composer.lock` to record the resolved development commit. The branch moves; an update is a deliberate dependency change, not a released-version guarantee. [Composer's VCS repository documentation](https://getcomposer.org/doc/05-repositories.md#vcs) explains root-project repository configuration.

For a clean local source checkout without credential files, replace the VCS entry with a [path repository](https://getcomposer.org/doc/05-repositories.md#path):

```json
{
    "type": "path",
    "url": "../airwallex-php-sdk",
    "options": {
        "symlink": false,
        "versions": {"jojeringnax/airwallex-php-sdk": "dev-main"}
    }
}
```

Point it to the complete checkout, including `resources/contracts`, rather than copying only `src`. Keep the same SDK requirement and update from your application. Repository contributors use the separate [development setup](https://github.com/jojeringnax/airwallex-php-sdk/blob/main/CONTRIBUTING.md).

## Configure an explicit environment and owner

From an entry file at your application's root, load Composer's autoloader and supply credentials from your application's secret configuration. The SDK does not read `.env` files or choose an environment implicitly. The [initialization recipe](../examples/initialize-client.php) creates a Guzzle PSR-18 client and PSR-17 factories with TLS verification enabled, redirects disabled and explicit timeout choices:

```php
require __DIR__ . '/vendor/autoload.php';

use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;

$sdkDirectory = __DIR__ . '/vendor/jojeringnax/airwallex-php-sdk';
$initialize = require $sdkDirectory . '/examples/initialize-client.php';
$billing = $initialize(
    Environment::Sandbox,
    new Credentials('<organization-client-id>', '<organization-api-key>'),
);
$payments = $initialize(
    Environment::Sandbox,
    new Credentials('<account-client-id>', '<account-api-key>'),
    '<authorized-login-account-id>',
);
$platform = $initialize(
    Environment::Sandbox,
    new Credentials('<platform-client-id>', '<platform-api-key>'),
);
$merchant = $platform->forConnectedAccount('<authorized-connected-account-id>');
```

Replace every placeholder before provider use. `loginAccountId` is optional when the key already selects the intended account. Organization Billing and account Payments can require distinct credentials; a Billing Customer ID and a Payments Customer ID are separate resources.

| Work | Client and ownership |
| --- | --- |
| Billing customers, subscriptions, sources, checkout and invoices | Client authorized for the owning Billing organization; no on-behalf-of delegation |
| Direct Payments | Account-authorized client; use login-account selection only for an account permitted by that key |
| Delegated connected-account Payments | Platform client followed by `$platform->forConnectedAccount('<owning-account-id>')`; the original client remains unchanged |
| Connected merchants using organization Billing with account collection | Independently authorized Billing organization and independently eligible linked collection account; provider/account confirmation remains required |

Each operation must use the environment and owner stored with its opaque resource ID. Authenticate the local buyer before looking up that mapping. An incoming resource/account ID is not authorization. Billing fails locally on a delegated Payments client; login-account selection and delegation cannot be combined. For both accepted Billing models, use the [two-account walkthroughs](connected-account-contracts.md#two-account-walkthroughs) and their evidence gates.

The [client reference](client-configuration.md) covers token expiration/reuse, optional PSR-16 caching, immutable contexts and transport configuration. Default token storage is in memory. It also defines the accepted optional authentication clock and opt-in read retries for [#60](https://github.com/jojeringnax/airwallex-php-sdk/issues/60), including timing units and the default one-attempt policy. SDK API requests pin `x-api-version: 2026-08-21`; configure webhook subscriptions to the same version separately.

## Run a recipe for the intended workflow

The files below return a closure or a map of named application recipes. Load the required file after your autoloader, supply the explicitly scoped client and your stored IDs, and invoke only the intended action. Named recipes are application examples, not additional SDK methods. Their comments identify authorization and persistence prerequisites. Exact files execute against deterministic fake transports in the test suite.

| Workflow | Runnable example and action |
| --- | --- |
| Flat, per-seat, Volume and Graduated prices | [Catalogue](../examples/billing-catalogue.php) |
| Billing customer profile and hosted subscription checkout | [Customer/signup](../examples/billing-customer-checkout.php) |
| Reconcile hosted instrument setup after browser return | [Setup reconciliation](../examples/billing-reconcile-setup.php) |
| Verified card instrument to Billing Source and direct Subscription | [Card linkage](../examples/billing-card-subscription.php), with stored buyer/collector mapping |
| Provider trial/future start, quantity and plan changes | [Billing lifecycle](../examples/billing-lifecycle.php): `start_trial`, `change_quantity`, `replace_price` |
| Custom charge/credit amounts or a chosen proration calculation time | [Proration](../examples/billing-proration.php): choose `custom_adjustment` or `prorate_at` |
| Scheduled ending or immediate permanent cancellation | [Billing lifecycle](../examples/billing-lifecycle.php): choose `schedule_end` or `cancel_now` |
| Preserve and restore an applicable finite end | [Billing lifecycle](../examples/billing-lifecycle.php): `schedule_finite_end`, then separately authorized `restore_finite_end`, with private durable snapshot callbacks |
| Replace an expired source and observe recovery | [Billing lifecycle](../examples/billing-lifecycle.php): `replace_source_and_observe` |
| Invoice PDF/hosted links | [Billing lifecycle](../examples/billing-lifecycle.php): `read_invoice` |
| Subscription items and Invoice line items | [Item reads](../examples/billing-item-reads.php): individual reads and explicit first pages using the [accepted signatures](consumer-interfaces.md#subordinate-reads) |
| Payments Customer/Consent setup and card verification | [Payments lifecycle](../examples/payments-lifecycle.php): `prepare_customer_consent`, then `verify_card_consent` after approved instrument collection |
| Authorized charge using a verified saved Consent | [Saved Consent](../examples/payments-saved-consent.php) |
| One-time customer-initiated card payment | [Payments lifecycle](../examples/payments-lifecycle.php): `one_time_card_payment` |
| Manual authorization and later capture, or cancellation | [Payments lifecycle](../examples/payments-lifecycle.php): `authorize_card`, then an explicitly selected `capture` or `cancel` where provider state permits |
| Partial or remaining-amount refund; observed payment/refund status | [Payments lifecycle](../examples/payments-lifecycle.php): `refund`, `read_payment`, `read_refund` |
| Explicit Billing bookmark and Payments numbered pages | [One-page recipes](../examples/explicit-pages.php), preserving original filters and owner |
| Raw webhook verification and lossless decoding | [Webhook boundary](../examples/verify-webhook.php) |
| Validation, permission, rate-limit and ambiguous network failures | [Failure classification](../examples/classify-failure.php) |

For example, a quantity change is an explicit action on the existing item:

```php
$recipes = require $sdkDirectory . '/examples/billing-lifecycle.php';
$observed = $recipes['change_quantity'](
    $billing,
    '<subscription-id>',
    '<existing-item-id>',
    3,
    '<durably-persisted-quantity-change-request-id>',
);
```

Durably associate each documented `request_id` with the intended action before sending it. Preserve it when investigating an ambiguous result; changing it can create another financial operation. Bodyless endpoints retain their bodyless contract, and some Billing updates have no request ID field. The SDK never invents one or automatically replays a mutation.

### Billing workflow decisions

A checkout URL or browser callback does not establish settled payment or application access. Reconcile through retrieval and verified events under the same owner. Hosted setup completion can expose a reusable Billing source, but setup is not a charge. The setup recipe checks the stored customer/collector mapping before following the source reference. `EMBEDDED` and `ELEMENTS` use browser integration and conditional client secrets; keep those secrets scoped to the authorized browser and out of logs. Expired checkout requires a fresh consumer action and identity; the SDK does not extend its lifetime.

For direct card linkage, the [executed recipe](../examples/billing-card-subscription.php) retrieves the expected `VERIFIED` Consent, checks its Payments Customer, card Method and documented merchant/unscheduled authorization, creates a Billing source with the **Method ID**, and uses the returned Source ID in Subscription create. It stops on missing/mismatched identities. Supply the independently authorized clients and stored Billing Customer/collection-account mapping; matching opaque IDs do not establish product eligibility. Other methods retain their separately verified linkage requirements.

Provider trial timestamps are timezone-bearing strings, including fractional precision. A local application trial creates no provider subscription by itself. Quantity/plan updates and cancellation have different proration controls; choose their financial effects using [the Billing control mapping](billing-resources.md#choosing-subscription-financial-controls). Refresh and reconcile item references before a later mutation; IDs need not stay stable, and `NONE` scheduling can leave prior/target entries listed without an active-item classifier. The examples accept explicitly selected item IDs and expose no pause, resume or cancelled-reactivation helper. The application chooses paid-through access and grace policy independently.

Source replacement can immediately retry a failed invoice. Verify the new source's buyer/collector ownership before that explicit action, then retrieve Subscription/Invoice state. Provider dunning depends on account configuration; the SDK supplies no collection scheduler or entitlement cutoff. For documents, a draft may have a null PDF, and an expired link may remain expired after retrieval. Authorize the buyer before exposing links; downloading, archiving and delivery belong to the application. [Billing boundaries](billing-capabilities.md) retain schedule-reversal and link-renewal evidence gaps.

### Payments workflow decisions

Payments Customer/Consent preparation, approved browser instrument collection, verification and later charging are separate phases. Inspect `status` and `next_action`; a pending, paused, disabled or unfamiliar Consent is not verified. The saved-Consent recipe stops until its observed state is `VERIFIED`, and the application still verifies buyer ownership and merchant-initiated authorization. Other methods use their independently selected operation schemas; the card recipes do not establish every method's eligibility or replace its browser/mandate flow. [Payments contracts](payments-contracts.md#instruments-confirmation-and-continuation) describe method-specific continuation and return-URL rules.

The one-time recipe requests automatic card capture. For a separate authorization, create an Intent deliberately and pass its stored ID to `authorize_card`, using the same account and authorized Consent; this is an alternative to automatic capture. Inspect provider state before explicitly selecting capture or cancellation. Authorization, `PENDING`, a next action and a successful SDK call do not establish settlement. Retrieve Intent/Attempt/Method details as needed; provider state and account/method restrictions determine which action is allowed.

For `refund`, supply a decimal amount for a partial refund or omit it for the remaining refundable amount. Preserve the returned Refund ID and retrieve its status. Acceptance does not mean that the shopper has received funds. Full/partial refund limits and method support remain provider-enforced; the SDK performs no amount arithmetic and redirects no refund to a different destination.

## Preserve payload precision and list context

Pass money as major-unit strings, such as `['amount' => '19.90', 'currency' => 'EUR']`. Implemented schemas encode those fields as exact JSON numbers where the provider declares numbers. Subscription Update custom charge/credit amounts remain JSON strings; [financial controls](billing-resources.md#choosing-subscription-financial-controls) owns their conditional rules. PHP floats are rejected at decimal positions. Returned monetary/decimal values remain strings, including integer-valued amounts; additional numeric fields retain their original numeric text. Perform application arithmetic with an exact decimal representation. The SDK does not calculate tax, proration, rounding or currency conversion.

Omission differs from explicit null, and `false`, zero, an empty string, an empty array and an empty object have distinct meanings. Send null only where the operation permits it; use `new stdClass()` for an empty JSON object. Read [payload precision](payload-precision.md) and the [shared representation contract](consumer-interfaces.md#interface-conventions) before transforming provider arrays.

When reads must distinguish numeric-key objects from lists, use the [object-preserving examples](consumer-examples.md#object-preserving-reads-and-webhook-views) and review their [availability and proof limits](payload-precision.md#object-shape-and-proof-limits). Existing clients and `WebhookEvent::toArray()` retain their default representation.

List calls retrieve one explicit page. Billing uses `page` bookmarks with native `page_after`/`page_before`; Payments uses zero-based `page_num` and `has_more`. Carry the original filters and the same client to the next call; do not reuse a bookmark under another owner. The [executed page recipes](../examples/explicit-pages.php) call public resource methods once per invocation. For example, retain `$filters` and choose the next Billing page only after inspecting its native bookmark:

```php
$pages = require $sdkDirectory . '/examples/explicit-pages.php';
$filters = ['page_size' => 20, 'email' => '<authorized-buyer-email>'];
$first = $pages['billing_customers']($billing, $filters);
$bookmark = $first['page_after'] ?? null;
if (is_string($bookmark) && $bookmark !== '') {
    // A deliberate application choice to fetch another page.
    $second = $pages['billing_customers']($billing, $filters, $bookmark);
}
```

For Payments, pass the original supported filters with explicit `page_num` values through `payment_intents`; inspect `has_more` before choosing another call. A failed next-page call surfaces its exception without fetching a later page. The [representation examples](consumer-examples.md#pagination-and-failures) show both native forms. There is no automatic network iterator or common normalized envelope.

## Verify webhooks before processing

Preserve the original body and the exact `x-timestamp` and `x-signature` header strings. Select the receiver's trusted environment, endpoint secret and subscription context from your configuration. The [webhook recipe](../examples/verify-webhook.php) verifies authenticity and millisecond freshness before decoding. Its required tolerance is your policy, not an SDK-selected provider default. Configure a clock returning Unix milliseconds; the authentication clock uses Unix seconds.

The decoder preserves unknown names/statuses, missing or unsupported versions and conflicting owner fields for inspection. It assigns no typed state transitions or owner authorization. Retain the original body separately when inspection needs exact signed bytes; apply your own access controls and redaction. Follow the [catalogue's unknown-event policy](webhook-contracts.md#unknown-events-versions-and-reconciliation) rather than aliasing legacy event names or treating unfamiliar values as success.

The application owns HTTP acknowledgement, durable acceptance, storage, duplicate suppression and workers. Deduplicate within trusted environment/owner/event context when side effects are possible. Deliveries can arrive twice or out of order; an old snapshot must not regress current application state. Retrieve authoritative current state in a known authorized context when reconciling. Verification/decoding alone neither persists work nor sends the receiver's HTTP response.

## Recover from failures and stale observations

Catch `Jojeringnax\Airwallex\Exception\SdkException` and inspect the documented subtype. `ApiException` retains HTTP status and nullable safe provider code/source; default diagnostics exclude provider message/details and raw bodies. The [failure classifier](../examples/classify-failure.php) demonstrates fixing local input/configuration, checking permissions, backing off before another safe operation and reconciling a possible mutation outcome.

After timeout or transport failure, a mutation might already have reached Airwallex. Preserve the application operation record, original request ID and owner context; retrieve/reconcile before deciding the next deliberate action. HTTP retries are bounded reads, while provider collection/dunning is a different process. A stale local row, redirect or older webhook must not create an additional charge or override a newer known observation. Applications own resource mappings, queues, locks, business access and retention; the SDK owns no database or synchronization service.

## Capability and validation boundary

API and webhook contracts target **2026-08-21**. The [compatibility policy](release-policy.md) governs `0.1.0` and pre-1.0 changes. The [documentation-based completion policy](release-policy.md#documentation-based-completion) defines acceptance of the full SDK scope. Repository checks execute the exact recipe files and provider-shaped failure fixtures. Account-specific Sandbox/provider confirmations are supplemental and do not block SDK acceptance; actual account activation, method acceptance, collection linkage and delivery behavior remain separate deployment facts.

The accepted scope and extension boundaries live in [planning](planning.md). OAuth, hosted Billing portal sessions and subscription pause/reactivation remain outside the implemented boundary. Nonmetered Volume and Graduated Price creation is included; see [tier rules and examples](billing-resources.md#price-models-and-tiers). The unreleased [advanced Billing extension](planning.md#advanced-billing-extension) adds [coupons and supporting Invoice writes](billing-resources.md#coupons-and-one-off-invoice-application) and [tax controls](billing-resources.md#tax-controls); metering and rate cards require their own implementation evidence.

The [release readiness reference](release-readiness.md#known-limitations-and-upgrade-guidance) records accepted boundaries, historical evidence and known limitations.

The [connected-account evidence](connected-account-contracts.md#evidence-still-needed), [Billing capability boundaries](billing-capabilities.md#delivery-and-evidence-gates) and [webhook integration evidence](webhook-contracts.md#remaining-integration-evidence) describe the remaining provider/account observations. [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42) retains this supplemental evidence; [#44](https://github.com/jojeringnax/airwallex-php-sdk/issues/44) owns the exact candidate CI, artifact and review record. Preserve blocked, failed and unobserved outcomes as recorded. Synthetic examples establish no account authorization, product eligibility, settlement, provider timing or actual webhook delivery; scoped observed results retain their own source and limits.

For archive inspection, clean installation from the generated package and retained candidate evidence, see [release preparation](release-preparation.md). Publication and unresolved release gates remain explicit in that record.
