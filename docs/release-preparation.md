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

These commands validate package installation and behavior. They deliberately record `release_ready=false`. Complete the [observable release gates](release-policy.md#observable-release-gates), collect the approved [sandbox journey evidence](https://github.com/jojeringnax/airwallex-php-sdk/issues/42), and review the full candidate evidence before any human publication decision. The manifest preserves links to open provisional interface and Price-model decision reviews, [#60](https://github.com/jojeringnax/airwallex-php-sdk/issues/60), item-read signatures [#61](https://github.com/jojeringnax/airwallex-php-sdk/issues/61), and [#64](https://github.com/jojeringnax/airwallex-php-sdk/issues/64), together with the development-validation service choice [#91](https://github.com/jojeringnax/airwallex-php-sdk/issues/91) and first-party Invoice document boundary [#95](https://github.com/jojeringnax/airwallex-php-sdk/issues/95). The delegated runtime architecture review [#101](https://github.com/jojeringnax/airwallex-php-sdk/issues/101), Sandbox security-service profile review [#104](https://github.com/jojeringnax/airwallex-php-sdk/issues/104) and pinned same-Document exception review [#120](https://github.com/jojeringnax/airwallex-php-sdk/issues/120) are also recorded. These links identify review context, not human approval or additional SDK runtime capabilities.

## Candidate notes and remaining evidence

The [readiness record](release-readiness.md) maps each release claim to implemented behavior, deterministic checks, actual Sandbox evidence and remaining prerequisites. Its identified checkpoints do not designate the final candidate; #44 and retained manifests bind the eventual clean merged revision.

The implementation covers the accepted resource and webhook interfaces described by [Billing resources](billing-resources.md), [Payments contracts](payments-contracts.md), [consumer usage](../README.md), and the [webhook catalogue](webhook-contracts.md). Before an initial installation, configure an explicit environment, provide the owning credentials/account context and concrete PSR implementations, use decimal strings, and configure webhook subscriptions to the selected `2026-08-21` version. Existing integrations must reconcile their own opaque IDs and data ownership; no framework persistence or automatic entitlement migration is supplied.

Known boundaries remain authoritative in [planning](planning.md), [Billing capabilities](billing-capabilities.md) and [connected-account evidence](connected-account-contracts.md#evidence-still-needed). OAuth, deferred Billing products and SDK-owned synchronization stay outside this candidate. Unknown provider fields/states remain available for consumer inspection; mutation replay stays disabled. Invoice expiry/renewal and broader schedule reversal follow the specific evidence boundaries in [Billing capabilities](billing-capabilities.md); the documented positive finite-restoration case does not establish other configurations. Product activation, method eligibility, provider deliveries and both connected-account journeys retain their documented evidence requirements. A clean archive and passing deterministic fixtures do not resolve those facts.

[#44](https://github.com/jojeringnax/airwallex-php-sdk/issues/44) owns the final claim-to-evidence record and candidate review. Keep it open while required release evidence remains unresolved. Record merged commit, exact matrix/check results, sanitized sandbox observations, known limitations and migration requirements there; do not convert blocked or unavailable evidence into a passed gate.
