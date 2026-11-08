# Development planning

## Accepted direction

- Develop a framework-independent PHP SDK for the accepted Billing and Payments scope.
- Distribute the SDK through Composer.
- Keep the SDK framework-independent, following the [package architecture](architecture.md).

For authentication, endpoint, retry or webhook work, consult the [API contract and permission inventory](api-contracts.md) for verified sources, the selected version policy and evidence gaps.

The initial scope is limited to the Billing and Payments capabilities below. Other Airwallex product families and connected-account onboarding operations require separate scope decisions.

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
| Invoice retrieval | Required; document/PDF access needs capability review |
| Webhooks | SDK verification and event contracts required; state synchronization and reconciliation belong to consumers |
| Connected-account support | Both platform billing of connected accounts and connected merchants billing their buyers; subject to independent provider-eligibility verification per family |
| Authentication | Client ID and API key exchanged for bearer tokens; OAuth deferred |
| Discounts/coupons, tax and usage-based billing | Deferred beyond the initial release |
| Countries and currencies | No SDK-specific restrictions; expose provider-supported combinations for the applicable contract and account |
| Payment methods | All provider-supported methods applicable to the accepted flows and account context |

For platform requests and Billing–Payments ownership, consult [connected-account contracts](connected-account-contracts.md), including the two Billing models and account-eligibility prerequisites.

Both Billing models are included to cover platform service subscriptions and merchants’ customer billing. API-key authentication provides the initial credential path; OAuth authorization and refresh support require a separate scope decision.
