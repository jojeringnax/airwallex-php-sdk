# Connected-account ownership and authentication

Contract research for [issue #4](https://github.com/jojeringnax/airwallex-php-sdk/issues/4), verified on **2026-11-08** against API **`2026-08-21`**. Consult this reference when designing account context, platform requests or Billing–Payments linkage. The [API inventory](api-contracts.md) owns the version policy and endpoint catalogue; [planning](planning.md) owns accepted capability scope.

## Verified boundary

Billing resources belong to an organization. Payments resources belong to an account. A connected-account Payments request does not select a Billing organization. A Billing `linked_payment_account_id` identifies the collection account; it does not authorize access to that account or move Billing resource ownership.

The selected-version API page metadata was inspected independently for every endpoint in the following groups. All 13 pages reported `currentVersion=2026-08-21`; every Billing endpoint reported `x-resourceLevel=org`, `x-oboSupported=false`, and every Payments endpoint reported `x-resourceLevel=account`, `x-oboSupported=true`. These checks include operations outside the accepted release; metadata availability does not expand SDK scope.

| Family / official API reference | Endpoints checked | Permission identifier prefix |
| --- | --- | --- |
| [Billing Customers](https://www.airwallex.com/docs/api/billing/billing_customers) | 5 | `billing.customer` |
| [Products](https://www.airwallex.com/docs/api/billing/products) | 4 | `billing.product` |
| [Prices](https://www.airwallex.com/docs/api/billing/prices) | 4 | `billing.price` |
| [Payment Sources](https://www.airwallex.com/docs/api/billing/payment_sources) | 3 | `billing.payment_source` |
| [Billing Checkouts](https://www.airwallex.com/docs/api/billing/billing_checkouts) | 5 | `billing.checkout` |
| [Subscriptions](https://www.airwallex.com/docs/api/billing/subscriptions) | 7 | `billing.subscription` |
| [Invoices](https://www.airwallex.com/docs/api/billing/invoices) | 17 | `billing.invoice` |
| [Payments Customers](https://www.airwallex.com/docs/api/payments/customers) | 5 | `payment_acceptance` |
| [Payment Consents](https://www.airwallex.com/docs/api/payments/payment_consents) | 7 | `payment_acceptance` |
| [Payment Methods](https://www.airwallex.com/docs/api/payments/payment_methods) | 5 | `payment_acceptance` |
| [Payment Intents](https://www.airwallex.com/docs/api/payments/payment_intents) | 9 | `payment_acceptance` |
| [Payment Attempts](https://www.airwallex.com/docs/api/payments/payment_attempts) | 2 | `payment_acceptance` |
| [Refunds](https://www.airwallex.com/docs/api/payments/refunds) | 3 | `payment_acceptance` |

The metadata's `x-scope` uses `:read` and `:write` suffixes (Attempts has only reads). The [API-key scope catalogue](https://www.airwallex.com/docs/developer-tools/api/api-key-scopes) supplies the corresponding resource labels. Configure organization-level Billing permissions separately from account-level Payment Acceptance permissions. A platform relationship, a resource ID or an on-behalf-of header alone grants neither permission nor product activation.

## Credential and request contexts

The [key guide](https://www.airwallex.com/docs/developer-tools/api/manage-api-keys) and [login API](https://www.airwallex.com/docs/api/authentication/api_access_token) establish scoped-key login behavior. The [connected-account API reference](https://www.airwallex.com/docs/api/connected_accounts) establishes platform delegation. They describe distinct selectors:

| Mode | Authentication | Resource request and ownership |
| --- | --- | --- |
| Organization Billing | Key with the owning organization's Billing permissions; organization-only login omits `x-login-as` | Bearer token; no `x-on-behalf-of`; organization owns Billing resources |
| Direct account Payments | Account-authorized credentials; a scoped key linked to multiple accounts selects the permitted account with `x-login-as` at login | Bearer token for the selected account; no delegation needed |
| Platform-delegated Payments | Platform Client ID and API key obtain a platform token | Bearer token plus `x-on-behalf-of: <connected-account-id>` on eligible Payments operations; target account owns the payment |

A scoped key linked to exactly one account can omit `x-login-as`. With multiple linked accounts, omission produces organization permissions only, or no permissions for an account-only key. Selecting an account retains any organization permissions granted to the key; it does not change the owning organization. This is not evidence that a platform can select any connected account using `x-login-as`: that selector is documented for accounts permitted on the key. Platform delegation is separately documented through `x-on-behalf-of`.

Admin keys are limited to their issuing organization or account; organization admin keys cannot access account resources, and account admin keys cannot span accounts. Scoped keys can carry both permission sets. The initial authentication scope uses Client ID and API key exchanged for bearer tokens, following [planning](planning.md#capability-decisions). Scoped keys are the preferred configuration for examples because Billing and Payments permissions can be configured independently. OAuth is deferred even where endpoint metadata advertises it; OAuth authorization, refresh and delegated organization permissions are outside the reviewed contract.

A platform token can authorize requests for multiple connected accounts through the delegation header. The SDK's isolation requirement is stricter than this provider capability: cache lookup and request state must preserve environment, credential identity, login account and effective delegated account. Separate context entries may contain the same platform token bytes. Never mutate shared request headers when switching accounts; retain context across pagination, retries and concurrent requests. Use returned `expires_at` for expiration; token or credential values must stay out of diagnostics and cache keys in plaintext. Public context and cache conventions are defined in [interface examples](consumer-examples.md#initialization-and-account-contexts); authentication implementation remains in [#13](https://github.com/jojeringnax/airwallex-php-sdk/issues/13).

## Resource ownership and linkage

| Identifier | Meaning and boundary |
| --- | --- |
| Organization ID | Owner of Billing customers, catalogue, sources, checkouts, subscriptions and invoices |
| Billing Customer ID | Buyer profile within that organization; distinct from a Payments Customer and a connected account |
| `default_legal_entity_id` / `legal_entity_id` | Default or selected invoicing legal entity; not a login account or delegation target |
| `linked_payment_account_id` | Account used to collect payment; not proof that any connected account is eligible for the organization's Billing |
| Payments Customer, Consent, Method, Intent, Attempt, Refund IDs | Resources in the effective Payments account context; preserve that context alongside consumer-stored IDs |
| Payment Source ID | Organization Billing resource linking `billing_customer_id`, Method ID as `external_id`, and the collection account |

The [Billing settings guide](https://www.airwallex.com/docs/billing/configure-your-billing-settings) distinguishes the issuing legal entity from the collection account. Billing Customer creation accepts `default_legal_entity_id`; Checkout and Subscription creation accept `legal_entity_id`, required when the organization has multiple entities. Source and Checkout creation require `linked_payment_account_id` when the organization has multiple payment accounts. Subscription creation documents that requirement for `CHARGE_ON_CHECKOUT` with multiple payment accounts; `AUTO_CHARGE` requires `payment_source_id`. The [custom checkout guide](https://www.airwallex.com/docs/billing/billing-components/checkout/other-checkout-options) explicitly uses the same linked account for source and subscription. Examples should supply it explicitly in either flow to make ownership reviewable.

The selected Source schema refers to payment accounts in the organization. Neither it nor `x-oboSupported=true` on Payments proves that a platform's Billing organization can collect through an arbitrary connected merchant's account. Connected merchants' organization membership, available Billing permissions, legal entities and eligible collection accounts need provider confirmation for the intended business model.

## Account eligibility and unsupported combinations

The [platform payment guide](https://www.airwallex.com/docs/payments-for-platforms/airwallex-gateway/process-payments-and-manage-funds/collect-payments-on-behalf-of-connected-accounts) explicitly demonstrates Intent create, confirm and capture under the same connected-account header. In that model, payments, fees, refunds and chargebacks belong to the connected account. Funds splitting and payouts are outside this SDK's accepted initial scope.

The [capability guide](https://www.airwallex.com/docs/connected-accounts/about/account-capabilities) requires a full connected account with payment acceptance enabled for merchant-of-record acceptance. Payment activation verification is additional to account onboarding. The [Billing setup guide](https://www.airwallex.com/docs/billing/get-started-with-billing) requires activating Payments for online collection. Deployment prerequisites are the applicable platform relationship, permitted API scopes, Billing availability and an activated collection account; SDK configuration cannot confer these entitlements.

| Combination | Contract conclusion / next action |
| --- | --- |
| Billing operation with `x-on-behalf-of` | Unsupported by inspected endpoint metadata; do not implement it as organization delegation |
| Multi-account scoped key without login account used for Payments | No account permissions from login; select an authorized account or use verified platform delegation |
| Method/Consent from account A used under account B | No verified cross-account portability; preserve source account and treat mismatch as an invalid combination |
| Platform Billing with arbitrary connected-account collection ID | Unverified; confirm eligible linkage with Airwallex and then exercise in sandbox |
| Merchant Billing accessed with platform Payments token alone | Unverified organization authorization; obtain a documented organization permission path rather than inferring it from Payments access |
| OAuth | Deferred by the accepted authentication scope |
| Combined login/delegation selectors or other PSP linkage | Needs separate contract review before support claims |

## Two-account walkthroughs

Both models below are accepted capability goals under [planning](planning.md#capability-decisions). These are contract review scenarios, not executable SDK interfaces or sandbox results. All names are placeholders. The platform's service subscription and a merchant's buyer subscription are distinct financial relationships with independent customer, instrument and subscription mappings.

### Platform bills connected accounts A and B

Here A and B are buyers of the platform's service. The platform's Billing organization **O-P** owns their Billing customers **BC-A** and **BC-B**, its catalogue, sources, subscriptions and invoices. Its legal entity **LE-P** issues invoices; its eligible payment account **P** collects the platform's revenue. The consumer stores the association between each connected account and its Billing customer. Being a connected account does not make A or B the collector or a payment instrument.

1. Authenticate using credentials with O-P's Billing permissions. Create or retrieve BC-A and BC-B without `x-on-behalf-of`. Organization authorization permits access to both; the consuming application enforces tenant authorization.
2. Create a hosted `SUBSCRIPTION` Checkout for BC-A using the platform's recurring Price, LE-P and P. Repeat for BC-B with a separate checkout and request identifier. Completion creates the corresponding sources and subscriptions; retrieve the resulting resources to reconcile each buyer's mapping. Public examples are defined in [interface examples](consumer-examples.md#customer-linkage-and-checkout); checkout implementation remains in [#21](https://github.com/jojeringnax/airwallex-php-sdk/issues/21).
3. For custom payment setup, collect each buyer's card details and merchant-initiated authorization under **P's Payments context**, using direct account login for P. Retrieve the verified Consent in P, take `payment_method.id`, and create the organization's Source with BC-A or BC-B, that Method and `linked_payment_account_id=P`. Create the `AUTO_CHARGE` subscription with the corresponding Source, legal entity, recurring Price and the same linked account.
4. Verify that both platform-service subscriptions collect into P, while customer/source/subscription mappings remain distinct. A buyer's A/B connected-account ID is neither the login account nor the delegation header for collecting the platform's own subscription revenue. Debiting connected-account wallets through Charges would require separate product scope; it is not implied by this Billing flow.

### Connected merchants A and B bill their buyers

For each merchant, confirm an authorized Billing organization **O-A/O-B**, its invoicing legal entity **LE-A/LE-B**, and its eligible collection account **A/B**. These labels describe ownership mappings, not proof that every connected account has an independent Billing organization or can be linked to platform Billing. Where organizations differ, supply credentials granting each organization's Billing permissions; platform Payments credentials alone do not establish that access. If Airwallex confirms a shared organization, apply consumer tenant authorization within it.

1. Obtain the platform token using platform credentials. Create/retrieve merchant A's Payments customer and merchant-initiated Consent under `x-on-behalf-of: A`. Complete shopper verification through the applicable checkout integration. Retrieve the card Consent in A, require `VERIFIED`, and take `payment_method.id`. The [inventory's linkage caveat](api-contracts.md#representative-contract-checks) applies to non-card methods.
2. With credentials permitted for O-A, create/retrieve the buyer's Billing customer without a delegation header. Create a Payment Source with that customer, A's Method as `external_id`, A as the confirmed eligible linked account, and a unique `request_id`.
3. Create the subscription in O-A using that customer, LE-A, a recurring Price in O-A, `AUTO_CHARGE`, Source and the same linked account. Retrieve the subscription and validate returned references against the mapping. For hosted signup, create a `SUBSCRIPTION` Checkout in O-A with LE-A and A, then reconcile its resulting resources; method/source creation is handled by checkout.
4. Repeat with B's mappings and O-B's authorization. Keep Payments delegation and Billing authorization explicit for every request. A shared organization may deliberately share catalogue resources; customer and payment mappings remain tenant-specific. Merchant A/B buyer instruments are distinct from any instruments those merchants use to pay the platform in the preceding model.

The merchant flow remains conditional on Airwallex confirming the organization authorization and Billing collection-account relationship. Payments delegation itself is verified; this does not establish the end-to-end Billing bridge. A direct API-key context for a Billing-enabled merchant organization is a documented authentication pattern, but connected merchants' ability to obtain or use that context is account-dependent.

## Isolation validation

For both models, the consuming application stores `(consumer tenant, Billing organization, Billing customer, legal entity, collection account, Payments customer, Consent, Method, Source, Subscription)` per relationship. Organization authorization does not restrict access to an individual tenant's Billing customers. For platform billing, A and B may share collection account P while retaining different customers and instruments; for merchant collection, Payments account A and B remain separate.

Define these deterministic checks in the authentication/resource implementation issues, and exercise provider rejection cases in sandbox under [#17](https://github.com/jojeringnax/airwallex-php-sdk/issues/17) and [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42):

| Negative case | Required observation |
| --- | --- |
| Same credentials, different environment/login/delegation contexts | Separate cache lookups; no reuse from the wrong context; expiration tested independently |
| Interleaved/concurrent A and B calls, pagination and retry | Every outgoing request retains its original account header, version and resource/filter mapping |
| A Payments resource retrieved or mutated under account B, or a Billing resource requested from another organization | No resource data or successful mutation across the account/organization boundary; do not depend on an unverified exact provider error code |
| A Method paired with B's linked account, or Source paired with another Billing customer | Consumer mapping rejects known mismatch; sandbox verifies provider enforcement without claiming it already occurred |
| Tenant B asks for tenant A's customer within a shared Billing organization | Consumer authorization rejects the request; the SDK cannot infer tenant ownership from an organization bearer token |
| Billing request given a delegation target | SDK interface rejects this unsupported context rather than silently dropping it or claiming Billing delegation |
| Account-only key used for Billing, or org-only key used for Payments | Missing permission produces failure; no automatic fallback to broader credentials or another account |

## Evidence still needed

The capability and authentication decisions are recorded in [planning](planning.md#capability-decisions). No business-model choice remains open. Public interface decisions are defined in [consumer interfaces](consumer-interfaces.md).

The reviewed public documentation establishes API-key organization access and account-level Payments delegation separately. It does not establish connected-merchant Billing entitlement or arbitrary connected-account linkage. The SDK implements those documented request boundaries and tests context isolation deterministically. Under the [documentation-based completion rule](release-policy.md#documentation-based-completion), account-specific confirmations and live enforcement checks are supplemental to SDK delivery. Confirm the following deployment prerequisites before using the bridge for a particular merchant:

| Confirmation needed | Required evidence |
| --- | --- |
| Which Billing organization owns each merchant's buyer subscriptions? | Organization mapping for merchant A and B, including whether either shares the platform's organization |
| Which API-key credentials can access that organization? | Supported credential provisioning/authorization path with the required Billing resource permissions; platform Payments access alone is insufficient |
| Can the merchant's connected Payments account collect for that Billing organization? | Explicit eligibility of A/B as `linked_payment_account_id`, with the allowed invoicing legal entities; separately confirm P for platform-service collection |
| Are the relevant products enabled? | Billing availability and Payments activation for each collector and its intended payment methods |

[Journey validation (#42)](https://github.com/jojeringnax/airwallex-php-sdk/issues/42) records supplemental provider confirmations, both walkthroughs and live negative cases. Record the selected API version, sanitized resource mappings, request context and observed failures. The [sandbox harness (#17)](https://github.com/jojeringnax/airwallex-php-sdk/issues/17) consumes the confirmed account and permission prerequisites before enabling those scenarios. Keep unresolved confirmations explicit as blocked evidence; SDK completion and the contract research in #4 do not establish account eligibility. Credentials remain outside the repository.

No sandbox requests or account-specific provider confirmations are recorded here. Endpoint fixtures and runnable SDK interfaces belong to their implementation issues; they must preserve the ownership boundaries documented here.
