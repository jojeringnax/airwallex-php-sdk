# Development planning

## Accepted direction

- Develop a framework-independent PHP SDK for the accepted Billing and Payments scope.
- Distribute the SDK through Composer.

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
| Webhook state synchronization | Required |
| Connected-account support | Required, subject to independent contract verification per family |
| Discounts/coupons, tax and usage-based billing | Deferred beyond the initial release |
| Countries and currencies | No SDK-specific restrictions; expose provider-supported combinations for the applicable contract and account |
| Payment methods | All provider-supported methods applicable to the accepted flows and account context |
