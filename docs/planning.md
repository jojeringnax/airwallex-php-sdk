# Development planning

## Accepted direction

- Develop a framework-independent PHP SDK for the accepted Billing and Payments scope.
- Distribute the SDK through Composer.

The [package architecture](architecture.md) defines runtime, dependencies and application-owned persistence.

For public signatures, payload representation and representative workflows, consult [consumer interfaces](consumer-interfaces.md).

For authentication, endpoint, retry or webhook work, consult the [API contract and permission inventory](api-contracts.md) for verified sources, the selected version policy and evidence gaps.

For selected Billing webhook names, versioned resource transitions, setup and unknown-event handling, consult the [webhook catalogue](webhook-contracts.md).

The initial scope is limited to the Billing and Payments capabilities below. Other Airwallex product families and connected-account onboarding operations require separate scope decisions.

For compatibility changes and release preparation, follow the [release policy](release-policy.md), including the first-release evidence gates.

## Initial Billing and Payments scope

The initial release covers both subscription billing and standalone Payments ([issue #1](https://github.com/jojeringnax/airwallex-php-sdk/issues/1)).

### Capability decisions

| Capability | Initial release decision |
| --- | --- |
| Customer creation and subscription checkout | Required |
| Subscriptions, recurring collection, plan/quantity changes and cancellation | Required |
| Saving payment methods and subsequent charges | Required |
| One-time payments, authorization, capture and payment cancellation | Required |
| Payment status retrieval and refunds | Required |
| Invoice retrieval | Required, including provider PDF and hosted links; see [Billing capability boundaries](billing-capabilities.md) |
| Webhooks | SDK verification and event contracts required; state synchronization and reconciliation belong to consumers |
| Connected-account support | Both platform billing of connected accounts and connected merchants billing their buyers; subject to independent provider-eligibility verification per family |
| Authentication | Client ID and API key exchanged for bearer tokens; OAuth deferred |
| Discounts/coupons, tax and usage-based billing | Deferred beyond the initial release |
| Countries and currencies | No SDK-specific restrictions; expose provider-supported combinations for the applicable contract and account |
| Payment methods | All provider-supported methods applicable to the accepted flows and account context |

For platform requests and Billing–Payments ownership, consult [connected-account contracts](connected-account-contracts.md), including the two Billing models and account-eligibility prerequisites.

Both Billing models are included to cover platform service subscriptions and merchants’ customer billing. API-key authentication provides the initial credential path; OAuth authorization and refresh support require a separate scope decision.

For invoice documents, self-service, checkout UI modes, trials and cancellation/grace boundaries, consult [Billing capability boundaries](billing-capabilities.md).

## Provisional Price-model boundary

Initial Price creation currently implements `FLAT` and `PER_UNIT`, following the selected work in [#19](https://github.com/jojeringnax/airwallex-php-sdk/issues/19). The coordinator provisionally retains this subset while [decision review #64](https://github.com/jojeringnax/airwallex-php-sdk/issues/64) determines whether non-metered `VOLUME`/`GRADUATED` pricing belongs in the initial release. This is not a human-approved reduction of broader capability scope, and those models are not automatically classified as deferred usage billing. The review issue records rationale, alternatives, compatibility and reversal options. [Billing resources](billing-resources.md) documents the implemented boundary and validation.

## Provisional Subscription Update additions boundary

The implemented Subscription Update contract retains `NONE`, `PRORATED` and `ALL`, without the newly documented per-item `CUSTOM` / `proration_value` or independent `proration_date` override. A later selected-2026-08-21 page comparison found these additions; no change to an existing promised three-mode request was established. Under delegated authority, the coordinator provisionally retains that explicit boundary for the initial release while [decision review #127](https://github.com/jojeringnax/airwallex-php-sdk/issues/127) considers inclusion of either control. This is not human approval, a permanent deferral or an automatic reduction of accepted plan/quantity-change scope. New request support requires verified conditional money/timestamp rules and representative consumer examples; the existing request validator continues to reject those inputs before authentication. [Billing resources](billing-resources.md#choosing-subscription-financial-controls) owns the current behavior and [API provenance](api-contracts.md#version-baseline) distinguishes the dated comparisons.
