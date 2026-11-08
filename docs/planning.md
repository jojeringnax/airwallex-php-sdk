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

This is accepted product scope, not a claim that every operation or combination is available through Airwallex. Exact endpoints, lifecycle semantics, payment methods, countries, currencies, account eligibility, API versions and consumer interfaces still require contract review. Discounts, tax, usage billing and other capabilities not listed above remain undecided rather than implicitly included by the phrase “all payments.”

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
| Discounts, tax and usage billing | Undecided; no inclusion or exclusion approved yet |
| Countries, currencies and payment methods | No specific combinations selected yet |

### Workflow review and follow-up evidence

The accepted scope covers signup through customer creation and checkout, renewal through recurring collection, plan/quantity changes, cancellation, and invoice retrieval. Failed-payment and recovery behavior requires contract review; no retry schedule or automatic recovery policy has been approved.

- [Contract inventory (#3)](https://github.com/jojeringnax/airwallex-php-sdk/issues/3): verify provider availability, endpoint coverage, versions, permission requirements, geographic/currency/payment-method restrictions, and failed-payment semantics for the accepted Billing and Payments scope.
- [Connected-account contracts (#4)](https://github.com/jojeringnax/airwallex-php-sdk/issues/4): verify account eligibility, enablement, credentials and resource ownership for each selected family.
- [Capability gaps (#5)](https://github.com/jojeringnax/airwallex-php-sdk/issues/5): resolve invoice documents, trials and self-service capabilities before promising them.
- [Consumer examples (#6)](https://github.com/jojeringnax/airwallex-php-sdk/issues/6): review representative signup, renewal, change-plan, cancellation, failed-payment and invoice journeys against verified contracts before implementation.
- [Sandbox validation (#42)](https://github.com/jojeringnax/airwallex-php-sdk/issues/42): establish account access and entitlements, and validate accepted journeys with sanitized evidence.

The undecided capabilities and geographic/payment-method coverage remain open decisions. This document records the accepted direction without claiming that all original issue #1 acceptance criteria have been resolved.

The existing Billing-focused implementation plan must be reconciled with this Payments scope before implementation. Identify missing Payments work and its dependencies during contract review; the current milestone list does not yet establish a delivery sequence for those additions.

## Implementation gates

Issue creation does not approve every proposed public method or implementation detail. The initial decision and contract milestone must settle product and endpoint coverage, supported PHP versions, SDK package naming and dependencies, framework-independent consumer interfaces, and API/webhook versions before dependent implementation begins.

Verify connected-account applicability separately for each selected API family. Organization-level Billing resources and account-level Payments resources must not be assumed to share authorization or ownership semantics. Account-specific enablement and sandbox access remain validation prerequisites.

Milestone numbering expresses the intended development sequence. Dependencies on individual issues define which work can proceed concurrently. Dates, estimates and release numbers remain unset until agreed. Publishing, tagging, pushing and release operations require separate authorization.

## SDK milestones

The list below includes SDK work only. Linked GitHub milestones retain their existing numbering; their remote descriptions and issue dependencies still need reconciliation with the current scope.

1. [Decisions and contracts](https://github.com/jojeringnax/airwallex-php-sdk/milestone/1): agree scope, examples, compatibility and verified provider contracts.
2. [SDK foundation](https://github.com/jojeringnax/airwallex-php-sdk/milestone/2): Composer tooling, transport, authentication, precision, errors, retries and pagination.
3. [Billing workflows](https://github.com/jojeringnax/airwallex-php-sdk/milestone/3): customers, catalog, checkout, subscription lifecycle and invoices.
4. [Advanced billing](https://github.com/jojeringnax/airwallex-php-sdk/milestone/5): credit notes, discounts, tax and usage billing, subject to capability decisions.
5. [Release readiness](https://github.com/jojeringnax/airwallex-php-sdk/milestone/6): sandbox evidence, consumer documentation and distributable artifacts.
