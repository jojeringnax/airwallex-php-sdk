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

## Price-model coverage

Initial Price creation includes nonmetered `FLAT`, `PER_UNIT`, `VOLUME` and `GRADUATED` models, for one-time or recurring purchases. Quantity tiers are accepted initial-release scope under the [decision in #64](https://github.com/jojeringnax/airwallex-php-sdk/issues/64#issuecomment-6749373954): they support quantity-based catalogue pricing independently of metered usage. Metered billing, usage-event reporting and usage-priced Rate Cards remain deferred under [#40](https://github.com/jojeringnax/airwallex-php-sdk/issues/40) / [#41](https://github.com/jojeringnax/airwallex-php-sdk/issues/41). [Billing resources](billing-resources.md#price-models-and-tiers) owns the request rules and verified sources.

## Subscription proration controls

The initial release includes per-item `CUSTOM` / `proration_value` and the independent optional `proration_date` override, following the two accepted decisions in [#127](https://github.com/jojeringnax/airwallex-php-sdk/issues/127#issuecomment-6749888924) ([timestamp decision](https://github.com/jojeringnax/airwallex-php-sdk/issues/127#issuecomment-6749930115)). These controls let applications supply their own charge/credit amounts or choose a calculation time within the current billing period. Existing `NONE`, `PRORATED` and `ALL` modes and billing actions remain supported. The SDK preserves exact monetary strings and timestamp text; provider state restrictions and calculations remain provider-owned. [Billing resources](billing-resources.md#choosing-subscription-financial-controls) owns conditional rules and examples; [API provenance](api-contracts.md#version-baseline) records the selected contract. Invoice Preview remains outside initial coverage.
