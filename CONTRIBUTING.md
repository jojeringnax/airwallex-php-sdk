# Contributing

Read [planning](docs/planning.md) for accepted scope and [consumer interfaces](docs/consumer-interfaces.md) before changing public behavior. The [release policy](docs/release-policy.md) defines compatibility and release gates.

## Development setup

Use PHP 8.3, 8.4 or 8.5 and Composer 2. Development tools require the DOM, mbstring, XML and XMLWriter extensions. Run:

```sh
composer install
composer validate --strict
composer check
composer check:consumer
```

`composer check` runs PHPUnit 12.5, PHP_CodeSniffer 4 with PSR-12, and PHPStan 2 at its maximum level using PHP 8.3 syntax/type assumptions. Add deterministic unit tests under `tests/`; ordinary checks require no credentials and make no provider requests. `composer style:fix` applies automatic style fixes.

This library leaves `composer.lock` untracked so each CI job resolves the declared constraints independently. Local `composer install` creates a development lock on its first run; use `composer update` to refresh it. To reproduce the lowest allowed set:

```sh
composer update --prefer-lowest --prefer-dist
composer check
composer check:consumer -- --lowest
```

CI runs those checks and clean consumer installation for PHP 8.3, 8.4 and 8.5 with both lowest and latest allowed dependencies. `composer check:consumer` copies package source and metadata into a temporary path repository, resolves runtime dependencies without development dependencies, checks platform requirements and autoloads a temporary SDK-namespace probe and required PSR interfaces. The fixture is removed after the check. It validates the package foundation; it does not establish an implemented Billing or Payments workflow.

Keep sandbox validation opt-in, configured explicitly and separate from the deterministic suite. Never add credentials or captured customer data to fixtures. Supported provider versions, contract evidence and account eligibility are recorded in [API contracts](docs/api-contracts.md); release evidence must distinguish deterministic checks from sandbox observations.

For explicit sandbox configuration, safe external credential loading and journey evidence prerequisites, see [sandbox validation](docs/sandbox-validation.md).
