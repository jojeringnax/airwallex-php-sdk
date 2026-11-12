# Airwallex PHP SDK

A framework-independent PHP SDK for Airwallex Billing and Payments, targeting API and webhook version **2026-08-21** on **64-bit PHP 8.3–8.5**.

Version **0.1.0** follows the [documentation-based completion policy](docs/release-policy.md#documentation-based-completion): official contracts, meaningful deterministic tests and package checks define SDK acceptance; account-specific Sandbox/provider confirmations are supplemental and non-blocking. The [readiness record](docs/release-readiness.md) distinguishes implemented contracts, historical observations and exact candidate evidence. Release publication establishes no account eligibility or production-readiness guarantee.

```sh
composer require "jojeringnax/airwallex-php-sdk:^0.1"
```

Start with the [consumer guide](docs/usage.md#install) for installation, explicit credentials/account contexts, and runnable recipes. The SDK accepts PSR-18/PSR-17 implementations and provider-shaped arrays; applications own their persistence, browser integration and access policy. The [pre-1.0 compatibility policy](docs/release-policy.md#versioning-and-public-surface) explains the chosen minor-line constraint.

- [Billing operations and constraints](docs/billing-resources.md): customers, catalogue, sources, checkout, subscriptions and invoice access.
- [Payments operations and constraints](docs/payments-contracts.md): customers, reusable instruments, payments, authorization/capture/cancellation and refunds.
- [Webhook verification and event contracts](docs/webhook-contracts.md#php-verification-and-decoding).
- [Development commands](https://github.com/jojeringnax/airwallex-php-sdk/blob/main/CONTRIBUTING.md), [accepted scope](docs/planning.md) and [compatibility policy](docs/release-policy.md).

The [consumer guide's evidence boundary](docs/usage.md#capability-and-validation-boundary) distinguishes SDK acceptance and account-specific deployment prerequisites. OAuth, hosted Billing portal sessions and pause/reactivation remain outside the initial scope in [planning](docs/planning.md); nonmetered quantity tiers and explicit Subscription proration controls are included. The follow-on [advanced Billing extension](docs/planning.md#advanced-billing-extension) includes unreleased [coupons and supporting Invoice writes](docs/billing-resources.md#coupons-and-one-off-invoice-application) and [Credit Notes](docs/billing-resources.md#credit-note-adjustments) and [tax controls](docs/billing-resources.md#tax-controls) and [usage meters/events](docs/billing-resources.md#usage-meters-and-events) and [versioned Rate Cards](docs/billing-resources.md#versioned-rate-cards).

Licensed under [MIT](LICENSE). Report sensitive vulnerabilities through [SECURITY.md](SECURITY.md).
