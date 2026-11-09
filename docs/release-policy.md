# Compatibility and release policy

This policy defines compatibility, maintenance and release acceptance for `jojeringnax/airwallex-php-sdk`, namespace `Jojeringnax\Airwallex`. The package uses the [MIT license](../LICENSE). [Architecture](architecture.md) owns runtime and dependency choices; [planning](planning.md) and [Billing capability boundaries](billing-capabilities.md) own capability scope.

## Versioning and public surface

Use semantic versioning. The first distributable release targets **0.1.0**, covering the full accepted initial Billing and Payments scope once the gates below pass. This is a release target, not a claim that the SDK is implemented or ready for production. A coherent end-to-end release lets consumers evaluate both families and their linkage rather than a transport-only foundation.

The supported public surface comprises documented classes, interfaces, constructors, methods and their parameter names/types/defaults, return and exception contracts, configuration, and documented payload representations and behavior. Symbols explicitly marked internal are excluded. Consumer examples accepted through [#6](https://github.com/jojeringnax/airwallex-php-sdk/issues/6) constrain implementation; undocumented implementation details do not create compatibility promises.

| Release line | Compatibility rule |
| --- | --- |
| `0.x` | Breaking public changes require a new minor version, release notes and migration guidance. Patch releases preserve the documented public contract. Consumers should constrain installation to their chosen minor line. |
| `1.0` onward | Breaking public changes require a major version. Compatible additions use minor releases; compatible fixes use patches. |

A public deprecation identifies the replacement, migration steps and earliest permitted removal in release notes and documentation. Removal follows the breaking-change rules above. The `1.0.0` cut requires a separate readiness decision after experience with the public API; this policy does not commit to a date.

## Maintenance and upgrades

During `0.x`, maintain only the latest minor line, including bug and security fixes. Consumers on older minor lines must upgrade to receive fixes; no parallel backport or response-time commitment is made. The repository maintainer triages reported defects, dependency advisories and provider changes against the maintained line. Stable-major maintenance windows must be decided and documented before `1.0.0`.

64-bit PHP 8.3, 8.4 and 8.5 and the selected PSR dependency lines form the accepted baseline in [architecture](architecture.md#package-and-runtime-support). Each release must resolve and test its declared dependency constraints on every supported PHP version, including lowest and latest allowed dependency sets. Optional concrete HTTP/cache implementations remain consumer choices. Dependency constraints must describe combinations actually validated; a transitive conflict is not evidence of runtime support.

Adding a PHP version or widening dependency constraints requires clean installation, deterministic tests, style and static analysis on the added combinations. Review PHP support lifecycle and dependency compatibility when changing the matrix. Dropping a supported runtime, raising a minimum or excluding a previously supported dependency combination is a breaking change and follows the versioning rules. Record the matrix, constraint changes, rationale and migration requirements in release notes; update Composer metadata, CI and documentation together.

The [API inventory](api-contracts.md#version-baseline) owns the explicit API pin and matching webhook version. Provider/account-default changes do not automatically change the SDK baseline. To upgrade it, review official changelog/schema differences for every affected family and event, reverify authorization and account contexts, refresh source/version references and sanitized fixtures, and validate affected consumer examples and deterministic failure paths. Record available Sandbox results as supplemental evidence. Classify observable public changes under this policy; a provider date-version change alone does not determine the SDK version bump. Document consumer webhook-subscription migration and known limitations alongside release notes. Provider deprecations require the same impact review and an explicit migration path rather than silent fallback.

## Documentation-based completion

For **0.1.0**, a feature is complete when its public interface implements the verified official Airwallex contract, representative consumer examples and meaningful deterministic tests cover its behavior and failures, and the required package checks pass. The [accepted completion decision](https://github.com/jojeringnax/airwallex-php-sdk/issues/44#issuecomment-6753924251) applies to every initial-release issue. It keeps the accepted capability scope while making account-specific Sandbox runs and provider confirmations supplemental, non-blocking evidence.

Account activation, authorized Billing organizations and eligible collection accounts remain prerequisites for using the corresponding provider operations. Document those prerequisites and the limits of the observed evidence. Unavailable credentials or account capabilities, unobserved provider timing and unanswered provider questions do not prevent completion of a documented SDK implementation. Retain every failed, pending, blocked and unverified result accurately; this acceptance rule establishes no provider entitlement, live success or settlement.

Where provider documentation conflicts, preserve the verified request/response contract, expose provider state and document the uncertainty. The SDK must not invent collection schedules, link lifetimes, authorization paths or financial outcomes to complete a feature.

## First-release scope and evidence

Deliver the accepted scope in [planning](planning.md#initial-billing-and-payments-scope), including both connected-account Billing models subject to their documented account prerequisites. Capability restrictions and application responsibilities remain in their authoritative references; this policy does not expand them.

| Release claim | Required acceptance evidence / owning work |
| --- | --- |
| Installable framework-independent package | [#10](https://github.com/jojeringnax/airwallex-php-sdk/issues/10): Composer metadata, autoloading and CI matching the approved namespace, MIT license, PHP matrix and dependency constraints |
| Safe shared client behavior | [#11](https://github.com/jojeringnax/airwallex-php-sdk/issues/11), [#12](https://github.com/jojeringnax/airwallex-php-sdk/issues/12), [#13](https://github.com/jojeringnax/airwallex-php-sdk/issues/13), [#14](https://github.com/jojeringnax/airwallex-php-sdk/issues/14), [#15](https://github.com/jojeringnax/airwallex-php-sdk/issues/15), [#16](https://github.com/jojeringnax/airwallex-php-sdk/issues/16): deterministic coverage for environment/account isolation, exact decimal serialization, token expiration/reuse, redacted errors, endpoint-safe retries retaining request identifiers and context-preserving pagination |
| Billing catalogue, customer/source linkage and checkout | [#18](https://github.com/jojeringnax/airwallex-php-sdk/issues/18), [#19](https://github.com/jojeringnax/airwallex-php-sdk/issues/19), [#20](https://github.com/jojeringnax/airwallex-php-sdk/issues/20), [#21](https://github.com/jojeringnax/airwallex-php-sdk/issues/21): accepted operations, checkout UI/business modes, ownership and negative-path fixtures |
| Subscription lifecycle, invoice access and recovery | [#22](https://github.com/jojeringnax/airwallex-php-sdk/issues/22), [#23](https://github.com/jojeringnax/airwallex-php-sdk/issues/23), [#24](https://github.com/jojeringnax/airwallex-php-sdk/issues/24), [#25](https://github.com/jojeringnax/airwallex-php-sdk/issues/25), [#26](https://github.com/jojeringnax/airwallex-php-sdk/issues/26), [#27](https://github.com/jojeringnax/airwallex-php-sdk/issues/27), [#28](https://github.com/jojeringnax/airwallex-php-sdk/issues/28): documented lifecycle, document, schedule and recovery behavior with executable examples and deterministic failure coverage in [Billing boundaries](billing-capabilities.md#delivery-and-evidence-gates) |
| Standalone Payments, saved instruments, authorization/capture/cancel, status and refunds | [#55](https://github.com/jojeringnax/airwallex-php-sdk/issues/55), [#56](https://github.com/jojeringnax/airwallex-php-sdk/issues/56): implement and test the accepted Payments workflows in the [API inventory](api-contracts.md#resource-inventory), including method-specific contracts, account isolation, state-dependent actions and event fixtures; retain supplemental Sandbox results under [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42) |
| Webhook verification and event contracts | [#29](https://github.com/jojeringnax/airwallex-php-sdk/issues/29), [#57](https://github.com/jojeringnax/airwallex-php-sdk/issues/57): implement the [versioned catalogue](webhook-contracts.md) and accepted decoding/unknown-event behavior, raw-body verification and malformed/stale/tampered delivery tests; consumer duplicate/out-of-order responsibilities documented |
| Both connected-account models | [Connected-account contracts](connected-account-contracts.md), [#17](https://github.com/jojeringnax/airwallex-php-sdk/issues/17), [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42): documented organization/account authorization and collection mappings, deterministic two-account context and negative-path coverage, and explicit activation/eligibility prerequisites; live enforcement and account-specific confirmations remain supplemental |
| Reproducible usage and distributable artifacts | [#43](https://github.com/jojeringnax/airwallex-php-sdk/issues/43), [#44](https://github.com/jojeringnax/airwallex-php-sdk/issues/44): runnable accepted examples, clean consumer installation, archive inspection, release notes and claim-to-evidence record |

Advanced work tracked in #37–41 does not override the initial scope or its explicit deferrals. Issue completion requires inspected implementation and validation evidence; provider-specific limits remain discoverable alongside the completed SDK work.

## Observable release gates

For each candidate, record the exact commit, SDK/API/webhook versions, PHP/dependency matrix, commands and results, available sanitized Sandbox evidence, known limitations and migration guidance in the release-readiness record under [#44](https://github.com/jojeringnax/airwallex-php-sdk/issues/44). A gate passes only with the observations below:

1. **Contract and scope:** every promised SDK capability maps to the verified documented contract, implemented behavior, approved examples and deterministic acceptance evidence. Document account/product prerequisites and unverified live behavior. Any reduced capability scope needs an explicit decision before advertising support.
2. **Metadata and CI:** Composer validation succeeds; autoloading and clean dependency resolution/install succeed across the supported runtime and dependency matrix. Deterministic network-independent tests, style and static analysis pass on the candidate. Metadata, CI, license, security policy and documented support agree.
3. **Behavior:** meaningful tests cover the accepted workflows and relevant failures, including precision, authentication/account isolation, retries, errors and webhook integrity. Accepted consumer examples execute against deterministic fakes; syntax checking alone does not prove behavior.
4. **Validation boundaries:** deterministic checks prove the opt-in harness rejects production configuration and requires explicit Sandbox configuration with external credentials. Record available live results and remaining account/provider questions with exact versions and limitations. Additional Sandbox journeys, natural timing waits, account enablement and provider confirmations are supplemental under the [completion rule](#documentation-based-completion); they are not release prerequisites. Use [manual checkout and SDK/API verification](sandbox-validation.md#manual-checkout-and-sdk-verification) when performing hosted validation. Preserve failed or unavailable results without reporting them as passed.
5. **Artifact and documentation:** build and inspect the distributable archive; clean-install it in consumers on the supported matrix and reproduce supported examples. Verify license/registry metadata, release notes, migration guidance and known limitations. Exclude secrets, customer data and local agent/tool state from artifacts.

Live financial operations are outside development and release validation. Keep TLS verification enabled. Release/tag/registry publication requires separate authorization; preparing a candidate or this policy does not grant it.

## Current evidence boundary

Current package commands and artifact checks are documented in [release preparation](release-preparation.md); [#44](https://github.com/jojeringnax/airwallex-php-sdk/issues/44) owns the exact candidate evidence. Apply the gates above to that revision and keep supplemental Sandbox/provider limits explicit.

Report ordinary defects through repository issues with sanitized diagnostics. Sensitive vulnerability reporting and the supported maintenance line are described in [SECURITY.md](../SECURITY.md).
