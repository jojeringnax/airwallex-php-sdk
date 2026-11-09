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

The [API inventory](api-contracts.md#version-baseline) owns the explicit API pin and matching webhook version. Provider/account-default changes do not automatically change the SDK baseline. To upgrade it, review official changelog/schema differences for every affected family and event, reverify authorization and account contexts, refresh source/version references and sanitized fixtures, and validate affected consumer examples, deterministic failure paths and sandbox journeys. Classify observable public changes under this policy; a provider date-version change alone does not determine the SDK version bump. Document consumer webhook-subscription migration and known limitations alongside release notes. Provider deprecations require the same impact review and an explicit migration path rather than silent fallback.

## First-release scope and evidence

Deliver the accepted scope in [planning](planning.md#initial-billing-and-payments-scope), including both connected-account Billing models subject to their documented eligibility gates. Capability restrictions and application responsibilities remain in their authoritative references; this policy does not expand them.

| Release claim | Required acceptance evidence / owning work |
| --- | --- |
| Installable framework-independent package | [#10](https://github.com/jojeringnax/airwallex-php-sdk/issues/10): Composer metadata, autoloading and CI matching the approved namespace, MIT license, PHP matrix and dependency constraints |
| Safe shared client behavior | [#11](https://github.com/jojeringnax/airwallex-php-sdk/issues/11), [#12](https://github.com/jojeringnax/airwallex-php-sdk/issues/12), [#13](https://github.com/jojeringnax/airwallex-php-sdk/issues/13), [#14](https://github.com/jojeringnax/airwallex-php-sdk/issues/14), [#15](https://github.com/jojeringnax/airwallex-php-sdk/issues/15), [#16](https://github.com/jojeringnax/airwallex-php-sdk/issues/16): deterministic coverage for environment/account isolation, exact decimal serialization, token expiration/reuse, redacted errors, endpoint-safe retries retaining request identifiers and context-preserving pagination |
| Billing catalogue, customer/source linkage and checkout | [#18](https://github.com/jojeringnax/airwallex-php-sdk/issues/18), [#19](https://github.com/jojeringnax/airwallex-php-sdk/issues/19), [#20](https://github.com/jojeringnax/airwallex-php-sdk/issues/20), [#21](https://github.com/jojeringnax/airwallex-php-sdk/issues/21): accepted operations, checkout UI/business modes, ownership and negative-path fixtures |
| Subscription lifecycle, invoice access and recovery | [#22](https://github.com/jojeringnax/airwallex-php-sdk/issues/22), [#23](https://github.com/jojeringnax/airwallex-php-sdk/issues/23), [#24](https://github.com/jojeringnax/airwallex-php-sdk/issues/24), [#25](https://github.com/jojeringnax/airwallex-php-sdk/issues/25), [#26](https://github.com/jojeringnax/airwallex-php-sdk/issues/26), [#27](https://github.com/jojeringnax/airwallex-php-sdk/issues/27), [#28](https://github.com/jojeringnax/airwallex-php-sdk/issues/28): accepted lifecycle scenarios and the specific document, schedule and recovery evidence gates in [Billing boundaries](billing-capabilities.md#delivery-and-evidence-gates) |
| Standalone Payments, saved instruments, authorization/capture/cancel, status and refunds | [#55](https://github.com/jojeringnax/airwallex-php-sdk/issues/55), [#56](https://github.com/jojeringnax/airwallex-php-sdk/issues/56): implement and test the accepted Payments workflows in the [API inventory](api-contracts.md#resource-inventory), including method-specific contracts, account isolation, state-dependent actions and event fixtures; validate corresponding sandbox journeys under [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42) |
| Webhook verification and event contracts | [#29](https://github.com/jojeringnax/airwallex-php-sdk/issues/29), [#57](https://github.com/jojeringnax/airwallex-php-sdk/issues/57): implement the [versioned catalogue](webhook-contracts.md) and accepted decoding/unknown-event behavior, raw-body verification and malformed/stale/tampered delivery tests; consumer duplicate/out-of-order responsibilities documented |
| Both connected-account models | [Connected-account evidence](connected-account-contracts.md#evidence-still-needed), [#17](https://github.com/jojeringnax/airwallex-php-sdk/issues/17), [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42): provider confirmation of merchant Billing authorization/eligible collection accounts, two-account journeys and negative isolation results |
| Reproducible usage and distributable artifacts | [#43](https://github.com/jojeringnax/airwallex-php-sdk/issues/43), [#44](https://github.com/jojeringnax/airwallex-php-sdk/issues/44): runnable accepted examples, clean consumer installation, archive inspection, release notes and claim-to-evidence record |

Advanced work tracked in #37–41 does not override the initial scope or its explicit deferrals. Closed planning issues are not substitutes for unresolved provider evidence or implementation validation.

## Observable release gates

For each candidate, record the exact commit, SDK/API/webhook versions, PHP/dependency matrix, commands and results, sanitized sandbox evidence, known limitations and migration guidance in the release-readiness record under [#44](https://github.com/jojeringnax/airwallex-php-sdk/issues/44). A gate passes only with the observations below:

1. **Contract and scope:** every promised capability maps to implemented behavior, approved examples and acceptance evidence. Complete the linked Payments work and resolve connected-account eligibility evidence above. Any reduced release cut needs an explicit scope decision before advertising support.
2. **Metadata and CI:** Composer validation succeeds; autoloading and clean dependency resolution/install succeed across the supported runtime and dependency matrix. Deterministic network-independent tests, style and static analysis pass on the candidate. Metadata, CI, license, security policy and documented support agree.
3. **Behavior:** meaningful tests cover the accepted workflows and relevant failures, including precision, authentication/account isolation, retries, errors and webhook integrity. Accepted consumer examples execute against deterministic fakes; syntax checking alone does not prove behavior.
4. **Sandbox:** the opt-in harness rejects production configuration and requires explicit sandbox configuration with credentials outside the repository. Run the accepted journey matrix, including account isolation, and record pass/fail/blocked outcomes with exact versions. Seek provider confirmation where sandbox cannot establish a required contract. Blocked or unavailable evidence cannot be reported as passed; unresolved required support blocks the full-scope release.
5. **Artifact and documentation:** build and inspect the distributable archive; clean-install it in consumers on the supported matrix and reproduce supported examples. Verify license/registry metadata, release notes, migration guidance and known limitations. Exclude secrets, customer data and local agent/tool state from artifacts.

Live financial operations are outside development and release validation. Keep TLS verification enabled. Release/tag/registry publication requires separate authorization; preparing a candidate or this policy does not grant it.

## Current evidence boundary

This policy was introduced during the repository's documentation-only phase. Current package commands and artifact checks are documented in [release preparation](release-preparation.md); [#44](https://github.com/jojeringnax/airwallex-php-sdk/issues/44) owns the evidence and remaining gaps for each candidate commit. Apply the gates above to each exact candidate revision, including its applicable Sandbox/provider evidence.

Report ordinary defects through repository issues with sanitized diagnostics. Sensitive vulnerability reporting and the supported maintenance line are described in [SECURITY.md](../SECURITY.md).
