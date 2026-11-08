# Airwallex PHP SDK

A framework-independent PHP SDK for Airwallex Billing and Payments, targeting API and webhook version **2026-08-21** on **64-bit PHP 8.3–8.5**.

The package is **unreleased development software**. No stable Composer version or production-readiness claim is made. The initial release still requires the [release gates](docs/release-policy.md#observable-release-gates), including account-specific sandbox/provider evidence.

Start with the [consumer guide](docs/usage.md) for development-source installation, explicit credentials/account contexts, and runnable recipes. The SDK accepts PSR-18/PSR-17 implementations and provider-shaped arrays; applications own their persistence, browser integration and access policy.

- [Billing operations and constraints](docs/billing-resources.md): customers, catalogue, sources, checkout, subscriptions and invoice access.
- [Payments operations and constraints](docs/payments-contracts.md): customers, reusable instruments, payments, authorization/capture/cancellation and refunds.
- [Webhook verification and event contracts](docs/webhook-contracts.md#php-verification-and-decoding).
- [Development commands](https://github.com/jojeringnax/airwallex-php-sdk/blob/main/CONTRIBUTING.md), [accepted scope](docs/planning.md) and [compatibility policy](docs/release-policy.md).

The [consumer guide's evidence boundary](docs/usage.md#capability-and-validation-boundary) distinguishes implementation, provisional interface decisions, deferred work and provider prerequisites. OAuth, hosted Billing portal sessions, pause/reactivation and advanced Billing controls are outside the implemented initial boundary.

Licensed under [MIT](LICENSE). Report sensitive vulnerabilities through [SECURITY.md](SECURITY.md).
