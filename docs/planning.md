# Development planning

## Accepted direction

- Develop a framework-independent PHP SDK for the accepted Billing and Payments scope.
- Include connected-account support in the initial scope. Account context, credential isolation, webhook routing and sandbox validation must therefore be considered from the foundation onward.
- Distribute and validate this SDK through Composer, including metadata, autoloading, dependency resolution and clean consumer installations.

These choices were accepted by the maintainer during planning. They establish the package direction and initial account-mode requirement, rather than a commitment to all Airwallex product families or all connected-account onboarding operations.

## Initial Billing and Payments scope

For [issue #1](https://github.com/jojeringnax/airwallex-php-sdk/issues/1), the maintainer confirmed that the initial release must cover both subscriptions and Payments. The intended consumer needs the complete subscription and payment journeys below; standalone payments must not be deferred merely because subscription billing is supported.

- Subscription billing: customer creation, subscription checkout, recurring collection, plan and quantity changes, cancellation, invoice retrieval, and state synchronization through webhooks.
- Standalone Payments: one-time payments, saving payment methods, subsequent charges using saved methods, authorization and capture, payment cancellation, payment status retrieval, and refunds.
- Connected-account support remains an accepted initial requirement. Verify applicability and account context independently for each Billing and Payments operation.

This is accepted product scope, not a claim that every operation or combination is available through Airwallex. Exact endpoints, lifecycle semantics, payment methods, countries, currencies, account eligibility, API versions and consumer interfaces still require contract review. Discounts/coupons, tax and usage-based billing are deferred beyond the initial release so the initial work can focus on the accepted subscription and payment journeys. Other capabilities not listed above are not implicitly included by the phrase “all payments.”

### Capability decisions

| Capability | Initial release decision |
| --- | --- |
| Customers and subscription checkout | Required |
| Subscriptions, recurring collection, plan/quantity changes and cancellation | Required |
| Saved payment methods and subsequent charges | Required |
| One-time payments, authorization, capture and payment cancellation | Required |
| Payment status and refunds | Required |
| Invoice retrieval | Required; document/PDF access needs capability review |
| Webhook state synchronization | Required |
| Connected-account support | Required, subject to independent contract verification per family |
| Discounts/coupons, tax and usage-based billing | Deferred beyond the initial release |
| Countries and currencies | No SDK-specific restrictions; expose provider-supported combinations for the applicable contract and account |
| Payment methods | All provider-supported methods applicable to the accepted flows and account context |

The maintainer selected broad geographic, currency and payment-method coverage to avoid an arbitrary SDK subset. This is a coverage requirement, not verified provider availability or a promise that every method supports recurring collection, saved-method charging, authorization/capture or connected-account use. Contract review must map supported combinations and identify unavailable or uncertain combinations before implementation; any proposed reduction in the accepted scope requires a maintainer decision.

## Implementation gates

Issue creation does not approve every proposed public method or implementation detail. The initial decision and contract milestone must settle product and endpoint coverage, supported PHP versions, SDK package naming and dependencies, framework-independent consumer interfaces, and API/webhook versions before dependent implementation begins.

Verify connected-account applicability separately for each selected API family. Organization-level Billing resources and account-level Payments resources must not be assumed to share authorization or ownership semantics. Account-specific enablement and sandbox access remain validation prerequisites.
