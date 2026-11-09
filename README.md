# Airwallex PHP SDK

A framework-independent PHP SDK for Airwallex Billing and Payments, targeting API and webhook version **2026-08-21** on **64-bit PHP 8.3–8.5**.

The package is **unreleased development software**. The [documentation-based completion policy](docs/release-policy.md#documentation-based-completion) defines first-release acceptance through official contracts, meaningful deterministic tests and package checks; account-specific Sandbox/provider confirmations are supplemental and non-blocking. The [readiness record](docs/release-readiness.md) distinguishes implemented contracts, historical observations and the exact candidate checks still to be recorded; no stable Composer version or production-readiness claim is made.

Start with the [consumer guide](docs/usage.md) for development-source installation, explicit credentials/account contexts, and runnable recipes. The SDK accepts PSR-18/PSR-17 implementations and provider-shaped arrays; applications own their persistence, browser integration and access policy.

- [Billing operations and constraints](docs/billing-resources.md): customers, catalogue, sources, checkout, subscriptions and invoice access.
- [Payments operations and constraints](docs/payments-contracts.md): customers, reusable instruments, payments, authorization/capture/cancellation and refunds.
- [Webhook verification and event contracts](docs/webhook-contracts.md#php-verification-and-decoding).
- [Development commands](https://github.com/jojeringnax/airwallex-php-sdk/blob/main/CONTRIBUTING.md), [accepted scope](docs/planning.md) and [compatibility policy](docs/release-policy.md).

The [consumer guide's evidence boundary](docs/usage.md#capability-and-validation-boundary) distinguishes SDK acceptance, deferred work and account-specific deployment prerequisites. OAuth, hosted Billing portal sessions, pause/reactivation, discounts/coupons, tax and usage-based Billing remain outside the initial scope in [planning](docs/planning.md); nonmetered quantity tiers and explicit Subscription proration controls are included.

Licensed under [MIT](LICENSE). Report sensitive vulnerabilities through [SECURITY.md](SECURITY.md).
