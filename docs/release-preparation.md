# Release preparation and package evidence

The released baseline is **0.1.2**. The commands below prepare local artifacts under the [release policy](release-policy.md); candidate readiness requires exact-revision CI, artifact and independent review evidence. [#44](https://github.com/jojeringnax/airwallex-php-sdk/issues/44) retains first-release evidence; later issues own their additional acceptance criteria. Release, tagging and registry publication require separate authorization.

## Unreleased changes and upgrade guidance

SDK v0.1.2 includes read-only `billing()->transactions()->retrieve($id)` and `list($query)` from [#159](https://github.com/jojeringnax/airwallex-php-sdk/issues/159). [Billing transactions](billing-resources.md#billing-transactions) defines parent filters, permissions and exact response fields. These observations do not prove invoice cash allocation or settlement. The next candidate adds [coupons and supporting Invoice writes](billing-resources.md#coupons-and-one-off-invoice-application); their development artifacts remain unreleased.

[Object-shape preservation](payload-precision.md#object-shape-and-proof-limits) is additive and opt-in: the final `Client` constructor parameter `preserveResponseObjects: true` preserves nested objects as `stdClass`, and `WebhookEvent::toArray(preserveObjects: true)` offers the same view. Existing constructors and default array responses retain their released behavior. Consumers enabling it must adapt every nested object access in both resource families, including page items; root envelopes remain arrays. It does not establish numeric-token provenance or duplicate-key rejection. The Laravel adapter's default branch implements the optional `airwallex.preserve_response_objects` projection (default false) in [PR #98](https://github.com/jojeringnax/airwallex-laravel/pull/98), across both resource families. Adapter release, coherent worker rollout and consumer qualification remain separate obligations.

API and webhook pins remain `2026-08-21`, and PHP/PSR requirements are unchanged. [Subscription timing research](billing-capabilities.md) documents the verified finite restoration and provisional deferred-change strategy without adding a scheduler or promising broader reversal. [#158](https://github.com/jojeringnax/airwallex-php-sdk/issues/158) and [#161](https://github.com/jojeringnax/airwallex-php-sdk/issues/161) retain the adoption evidence gaps. The unreleased [Credit Note extension](billing-resources.md#credit-note-adjustments) adds explicit Invoice adjustment/refund commands; tax controls, usage meters and rate cards have separate implementation and validation requirements under the [advanced Billing extension](planning.md#advanced-billing-extension).

## Package and consumer checks

Run the repository's deterministic checks and both clean consumer modes using the PHP and dependency combinations in CI:

```sh
composer validate --strict
composer check
composer test:tools
composer check:consumer
composer check:consumer -- --lowest
composer check:artifact
composer check:artifact -- --lowest
```

`check:consumer` copies SDK source, runtime contracts, Composer metadata and license into an isolated path repository. It installs the package without its development dependencies. The consuming fixture explicitly chooses Guzzle as its own PSR-18/PSR-17 implementation and uses a mock handler, so no provider requests occur. Real public Billing and Payments operations exercise contract loading, exact decimal wire numbers, native paging, shared token reuse and the fixed API pin. Webhook verification and lossless decoding execute through the installed public classes. The check also rejects installation of PHPUnit, PHPStan or PHPCS as SDK runtime dependencies.

`check:artifact` builds a Composer tar archive, checks its complete content list and every file hash against an immutable byte inventory, and installs a second clean consumer from that exact archive through a local package repository. It does not substitute a working-tree path repository for archive installation. The local `dev-main` repository version identifies the installation fixture; it is not a release version or tag. Both artifact modes use the same meaningful consumer behavior check as the source installation.

CI runs metadata, deterministic SDK/example tests, style/static analysis, clean consumer and archive installation checks for PHP 8.3, 8.4 and 8.5, each with lowest and latest allowed dependency resolution. The separate development-tools job runs `composer test:tools` once on PHP 8.4 and the deterministic browser/webhook policies on Node 22. Both PHP suites remain required in CI; `composer test:all` runs both locally. [Contributing](../CONTRIBUTING.md#development-setup) defines their boundaries and test commands. The chosen Composer resolver's security policy remains enabled. Record the actual resolved versions and results, rather than assuming all declared historical patch combinations were installed. Missing or failed jobs remain missing or failed evidence.

## Distribution contents

The archive allowlist retains `composer.json`, MIT `LICENSE`, `README.md`, `SECURITY.md`, `src/`, `resources/`, `docs/` and `examples/`. All shipped source, runtime schemas, consumer references and examples must be present with their original bytes. The runtime requirements remain PHP and the approved PSR interfaces; concrete transports are consumer choices.

The [Composer archive command](https://getcomposer.org/doc/03-cli.md#archive) honors export exclusions in `.gitattributes`; the check uses those normal filters. Hidden configuration, environment files, local agent instructions/state, Git/IDE metadata, dependencies, lock files, tests, tools and build output are excluded. Synthetic documentation fixtures remain with their provenance because the consumer references link to them. Unit checks create deliberately invalid archives to verify secret-path, omitted-contract and changed-byte failures; a separate Composer archive fixture verifies the actual export filters with synthetic configuration and agent state.

The archive check fails if its source commit changes during validation. Retaining an artifact additionally requires a clean committed checkout whose entire package membership and bytes match Git blobs from that commit. Ignored untracked files and tracked edits hidden by `assume-unchanged` or `skip-worktree` cannot enter a retained candidate. Candidate archives are compared directly to immutable commit-derived sizes and SHA-256 hashes, including if working files change while Composer runs. Default development checks can inspect dirty source, but record `source_clean=false` and cannot retain a candidate from it. Credentials stay in the external sandbox configuration described in [sandbox validation](sandbox-validation.md); packaging never loads that file.

## Retain a reviewable candidate

After the intended changes are reviewed and merged, use a new absolute output directory outside the checkout:

```sh
composer check:artifact -- --output=/absolute/new/candidate-directory
```

The directory contains the tar archive and `manifest.json`. The manifest records the exact source commit, clean-source state, PHP and Composer versions, dependency mode and actual consumer dependency versions, API/webhook pins, archive SHA-256, and each included path's size/hash. `intended_release` is null because package validation does not select a release number; older manifests retain their historical field values. `package_validation=pass` and `readiness_scope=package_validation_only` describe the archive inspection and installed-consumer checks for that invocation. `release_policy` points to the candidate acceptance rules and `supplemental_evidence` points to #42. Default CI/development checks remove their temporary archives and consumers; an explicit output directory retains the prepared archive. Use separate new directories when retaining evidence for another matrix combination.

An individual manifest supplies package evidence, rather than a global candidate verdict. Complete the [observable release gates](release-policy.md#observable-release-gates) and independent candidate review before declaring readiness. Supplemental observations retain their pass/fail/blocked or unobserved limits; package checks establish no provider eligibility, financial outcome or settlement.

Accepted decisions are documented with their implementations: [client clock and opt-in retry policy](client-configuration.md), [temporary receiver limits](sandbox-validation.md#temporary-signed-webhook-delivery-probe), [first-party Invoice access](sandbox-validation.md#optional-own-invoice-access-now-observations), [64-bit runtime](architecture.md#package-and-runtime-support), [Subscription proration controls](planning.md#subscription-proration-controls), and [manual hosted completion](sandbox-validation.md#manual-checkout-and-sdk-verification). The optional [bounded instrument workflow](hosted-instrument-workflow.md) merged in [PR #149](https://github.com/jojeringnax/airwallex-php-sdk/pull/149); its failed provider observation retains its original limits. The guarded [Consent cleanup](sandbox-validation.md#original-owned-consent-disable-cleanup) merged in [PR #148](https://github.com/jojeringnax/airwallex-php-sdk/pull/148); its scoped Disable/readback observation does not resolve the earlier failed Verify or historical financial uncertainty. These closed decisions add no pending release gate.

New manifests omit the former global `release_ready`, mandatory-provider `required_evidence` and pending `decision_reviews` fields. Historical manifests retain their exact revision-specific fields, inventories and hashes; interpret their old acceptance labels using the policy applicable when they were produced. Optional browser diagnostics and their private adapter add no release prerequisite.

## Preparation checkpoint and candidate handoff

At historical merged `5ee2c7640f763e062d6ad0144973fc7f9763fe64`, [all six supported PHP/dependency CI jobs](https://github.com/jojeringnax/airwallex-php-sdk/actions/runs/42597743848) passed, including both consumer modes. This checkpoint does not validate a later candidate. The [readiness record](release-readiness.md#delivery-priorities) separates SDK/package acceptance from supplemental provider observations.

1. After the intended changes are reviewed and merged, name the clean candidate commit and map the full accepted scope to the official contract references, implementation, examples and meaningful deterministic tests. Exclude optional drafts; a later merge requires its own exact-source evidence.
2. Record that revision's six supported PHP/dependency CI results, development-tools job (PHP tooling and Node policies) and actual dependency versions. Historical three-profile or browser runs keep their original commits; do not relabel them as new candidate runs.
3. Use the existing source and archive consumer commands above. Retain lowest/latest archives in separate new directories, with manifest/archive hashes, the exact commit and the known-limit record in the issue owning that candidate. The v0.1.2 transaction-extension record remains in closed [#159](https://github.com/jojeringnax/airwallex-php-sdk/issues/159). Advanced Billing candidate evidence belongs to [#37](https://github.com/jojeringnax/airwallex-php-sdk/issues/37)–[#41](https://github.com/jojeringnax/airwallex-php-sdk/issues/41); closed #44 retains the original release record. No provider call is needed for these package checks.
4. Independently review archive membership/bytes, installed examples, contract coverage, CI results, known limitations and claim-to-evidence completeness. Declare candidate readiness only after these checks actually pass. Retain the separate limits of any supplemental Sandbox/provider evidence. A retained development artifact grants no release/tag/registry publication authorization.

At historical `5ee`, the merged artifact tool recorded nine review links, through [#127](https://github.com/jojeringnax/airwallex-php-sdk/issues/127). That inventory remains provenance for those original artifacts, rather than a list of current unresolved decisions. Record the manifest schema and known limits applicable to the newly selected revision when preparing its artifacts.

## Candidate notes and remaining evidence

The [initial readiness record](release-readiness.md) maps the original release claims to implementation, deterministic checks and separately scoped Sandbox observations. Its historical checkpoints do not designate a later candidate; each candidate's owning issue and retained manifests bind its exact revision.

The implementation covers the accepted resource and webhook interfaces described by [Billing resources](billing-resources.md), [Payments contracts](payments-contracts.md), [consumer usage](../README.md), and the [webhook catalogue](webhook-contracts.md). Before an initial installation, configure an explicit environment, provide the owning credentials/account context and concrete PSR implementations, use decimal strings, and configure webhook subscriptions to the selected `2026-08-21` version. Existing integrations must reconcile their own opaque IDs and data ownership; no framework persistence or automatic entitlement migration is supplied.

Known boundaries remain authoritative in [planning](planning.md), [Billing capabilities](billing-capabilities.md) and [connected-account evidence](connected-account-contracts.md#evidence-still-needed). The full accepted Billing/Payments scope and both connected-account models remain included; the follow-on advanced Billing coverage follows [its own implementation criteria](planning.md#advanced-billing-extension), and synchronization remains application-owned. Unknown provider fields/states remain available for consumer inspection; mutation replay stays disabled. Invoice expiry/renewal and broader schedule reversal retain their recorded observational limits; the positive finite-restoration case does not establish other configurations. Product activation, method eligibility, provider deliveries and both connected-account journeys remain account-specific deployment facts. Their supplemental confirmation does not block SDK acceptance, and a clean archive or deterministic fixture does not establish those facts.

Record the merged commit, exact matrix/check results, known limitations, migration requirements and independent candidate review in the issue owning that candidate's change. For unreleased additions, tagged provenance remains pending until the separately authorized release and downstream installation checks actually complete; completed release evidence retains its original revision. Supplemental Sandbox observations remain separately scoped. No final candidate or new passing checks are designated by this document.

## Publish to Packagist

The **Publish to Packagist** workflow (`.github/workflows/publish.yml`) requests a refresh of the registered package when a GitHub release is published, including pre-releases. Using release publication as the trigger keeps the refresh tied to the maintainer's release decision. Complete the [release gates](release-policy.md#observable-release-gates) before publishing the release.

Configure these repository secrets in **Settings → Secrets and variables → Actions**:

| Secret | Value |
| --- | --- |
| `PACKAGIST_USERNAME` | Packagist username with maintainer access to `jojeringnax/airwallex-php-sdk` |
| `PACKAGIST_API_TOKEN` | That account's SAFE API token from its [Packagist profile](https://packagist.org/profile/) |

The workflow uses the [Packagist update API](https://packagist.org/apidoc#update-a-package), which accepts the SAFE token and Bearer authentication. Credentials are sent in the authorization header. It needs no GitHub token permissions, source checkout or dependency installation. Missing secrets, HTTP failures and responses without `status: success` fail the job.

To retry a refresh or sync an existing release after this workflow is merged, open **Actions → Publish to Packagist → Run workflow**, select `main`, and run it. This calls the same repository-wide refresh; it does not select or create a version. Packagist discovers tags and branches from the registered Git repository, so this action uploads no archive and creates no tag or GitHub release. Keep version numbers in Git tags rather than adding a `version` field to `composer.json`, as described in [Packagist version management](https://packagist.org/about#managing-package-versions).

A successful job means Packagist accepted the asynchronous refresh. Check the [package page](https://packagist.org/packages/jojeringnax/airwallex-php-sdk) for the expected version and verify installation from a clean consumer before reporting publication complete. Packagist's GitHub hook, if configured separately, can also refresh on pushes; this workflow does not restrict which existing repository versions Packagist indexes.
