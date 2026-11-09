# Release preparation and package evidence

The package targets the first **0.1.0** release under the [release policy](release-policy.md). The commands below prepare and inspect local artifacts. They create no tag, GitHub release or registry publication, and a package check does not establish provider eligibility or release readiness.

## Package and consumer checks

Run the repository's deterministic checks and both clean consumer modes using the PHP and dependency combinations in CI:

```sh
composer validate --strict
composer check
composer check:consumer
composer check:consumer -- --lowest
composer check:artifact
composer check:artifact -- --lowest
```

`check:consumer` copies SDK source, runtime contracts, Composer metadata and license into an isolated path repository. It installs the package without its development dependencies. The consuming fixture explicitly chooses Guzzle as its own PSR-18/PSR-17 implementation and uses a mock handler, so no provider requests occur. Real public Billing and Payments operations exercise contract loading, exact decimal wire numbers, native paging, shared token reuse and the fixed API pin. Webhook verification and lossless decoding execute through the installed public classes. The check also rejects installation of PHPUnit, PHPStan or PHPCS as SDK runtime dependencies.

`check:artifact` builds a Composer tar archive, checks its complete content list and every file hash against an immutable byte inventory, and installs a second clean consumer from that exact archive through a local package repository. It does not substitute a working-tree path repository for archive installation. The local `dev-main` repository version identifies the installation fixture; it is not a release version or tag. Both artifact modes use the same meaningful consumer behavior check as the source installation.

CI runs metadata, deterministic tests, style/static analysis, clean consumer and archive installation checks for PHP 8.3, 8.4 and 8.5, each with lowest and latest allowed dependency resolution. The chosen Composer resolver's security policy remains enabled. Record the actual resolved versions and results, rather than assuming all declared historical patch combinations were installed. Missing or failed jobs remain missing or failed evidence.

## Distribution contents

The archive allowlist retains `composer.json`, MIT `LICENSE`, `README.md`, `SECURITY.md`, `src/`, `resources/`, `docs/` and `examples/`. All shipped source, runtime schemas, consumer references and examples must be present with their original bytes. The runtime requirements remain PHP and the approved PSR interfaces; concrete transports are consumer choices.

The [Composer archive command](https://getcomposer.org/doc/03-cli.md#archive) honors export exclusions in `.gitattributes`; the check uses those normal filters. Hidden configuration, environment files, local agent instructions/state, Git/IDE metadata, dependencies, lock files, tests, tools and build output are excluded. Synthetic documentation fixtures remain with their provenance because the consumer references link to them. Unit checks create deliberately invalid archives to verify secret-path, omitted-contract and changed-byte failures; a separate Composer archive fixture verifies the actual export filters with synthetic configuration and agent state.

The archive check fails if its source commit changes during validation. Retaining an artifact additionally requires a clean committed checkout whose entire package membership and bytes match Git blobs from that commit. Ignored untracked files and tracked edits hidden by `assume-unchanged` or `skip-worktree` cannot enter a retained candidate. Candidate archives are compared directly to immutable commit-derived sizes and SHA-256 hashes, including if working files change while Composer runs. Default development checks can inspect dirty source, but record `source_clean=false` and cannot retain a candidate from it. Credentials stay in the external sandbox configuration described in [sandbox validation](sandbox-validation.md); packaging never loads that file.

## Retain a reviewable candidate

After the intended changes are reviewed and merged, use a new absolute output directory outside the checkout:

```sh
composer check:artifact -- --output=/absolute/new/candidate-directory
```

The directory contains the tar archive and `manifest.json`. The manifest records the exact source commit, clean-source state, PHP and Composer versions, dependency mode and actual consumer dependency versions, API/webhook pins, archive SHA-256, and each included path's size/hash. Default CI/development checks remove their temporary archives and consumers; an explicit output directory retains the prepared archive. Use separate new directories when retaining evidence for another matrix combination.

These commands validate package installation and behavior. They deliberately record `release_ready=false`. Complete the [observable release gates](release-policy.md#observable-release-gates), collect the approved [sandbox journey evidence](https://github.com/jojeringnax/airwallex-php-sdk/issues/42), and review the full candidate evidence before any human publication decision. The manifest preserves decision-review links as provenance; a link alone does not establish acceptance or add an SDK runtime capability. The temporary receiver service choice [#91](https://github.com/jojeringnax/airwallex-php-sdk/issues/91#issuecomment-6749450101) is accepted within the [documented event/profile and privacy limits](sandbox-validation.md#temporary-signed-webhook-delivery-probe), while its provider-validation work remains under #42. Other recorded reviews cover provisional interfaces [#60](https://github.com/jojeringnax/airwallex-php-sdk/issues/60), Price models [#64](https://github.com/jojeringnax/airwallex-php-sdk/issues/64), first-party Invoice documents [#95](https://github.com/jojeringnax/airwallex-php-sdk/issues/95), runtime architecture [#101](https://github.com/jojeringnax/airwallex-php-sdk/issues/101), the Sandbox security-service profile [#104](https://github.com/jojeringnax/airwallex-php-sdk/issues/104), the pinned same-Document exception [#120](https://github.com/jojeringnax/airwallex-php-sdk/issues/120) and newly documented Subscription controls [#127](https://github.com/jojeringnax/airwallex-php-sdk/issues/127). Consult each decision's current record independently.

## Preparation checkpoint and candidate handoff

At merged `5ee2c7640f763e062d6ad0144973fc7f9763fe64`, [all six supported PHP/dependency CI jobs](https://github.com/jojeringnax/airwallex-php-sdk/actions/runs/42597743848) passed, including both consumer modes. The [readiness record](release-readiness.md#delivery-priorities) separates implemented SDK/example work from remaining provider validation. Known-limit documentation and local artifact preparation can proceed without another browser instrument; the full accepted 0.1.0 release still requires the existing [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42) evidence.

1. Merge the intended recovery-recipe/documentation changes, then name the clean candidate-preparation commit. Exclude parked or unreviewed branches; a later merge requires its own exact-source evidence.
2. Record that revision's six supported PHP/dependency CI results and actual dependency versions. Historical three-profile or browser runs keep their original commits; do not relabel them as new candidate runs.
3. Use the existing source and archive consumer commands above. Retain lowest/latest archives in separate new directories, with manifest/archive hashes, the exact commit and the known-limit record under #44. No provider call is needed for these package checks.
4. Review archive membership, installation/examples and claim-to-evidence completeness. Keep `release_ready=false` while required Sandbox/provider evidence is unresolved. A retained development artifact is not a release/tag/registry publication.

At `5ee`, the merged artifact tool records nine review links, through [#127](https://github.com/jojeringnax/airwallex-php-sdk/issues/127). The separate open [#130](https://github.com/jojeringnax/airwallex-php-sdk/issues/130) review concerns unmerged original-Consent cleanup. Record the review inventory and known limits applicable to the selected merged revision when preparing its artifacts.

## Candidate notes and remaining evidence

The [readiness record](release-readiness.md) maps each release claim to implemented behavior, deterministic checks, actual Sandbox evidence and remaining prerequisites. Its identified checkpoints do not designate the final candidate; #44 and retained manifests bind the eventual clean merged revision.

The implementation covers the accepted resource and webhook interfaces described by [Billing resources](billing-resources.md), [Payments contracts](payments-contracts.md), [consumer usage](../README.md), and the [webhook catalogue](webhook-contracts.md). Before an initial installation, configure an explicit environment, provide the owning credentials/account context and concrete PSR implementations, use decimal strings, and configure webhook subscriptions to the selected `2026-08-21` version. Existing integrations must reconcile their own opaque IDs and data ownership; no framework persistence or automatic entitlement migration is supplied.

Known boundaries remain authoritative in [planning](planning.md), [Billing capabilities](billing-capabilities.md) and [connected-account evidence](connected-account-contracts.md#evidence-still-needed). OAuth, deferred Billing products and SDK-owned synchronization stay outside this candidate. Unknown provider fields/states remain available for consumer inspection; mutation replay stays disabled. Invoice expiry/renewal and broader schedule reversal follow the specific evidence boundaries in [Billing capabilities](billing-capabilities.md); the documented positive finite-restoration case does not establish other configurations. Product activation, method eligibility, provider deliveries and both connected-account journeys retain their documented evidence requirements. A clean archive and passing deterministic fixtures do not resolve those facts.

[#44](https://github.com/jojeringnax/airwallex-php-sdk/issues/44) owns the final claim-to-evidence record and candidate review. Keep it open while required release evidence remains unresolved. Record merged commit, exact matrix/check results, sanitized sandbox observations, known limitations and migration requirements there; do not convert blocked or unavailable evidence into a passed gate.
