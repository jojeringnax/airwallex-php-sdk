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

`composer check` runs the SDK and example tests with PHPUnit 12.5, PHP_CodeSniffer 4 with PSR-12, and PHPStan 2 at its maximum level using PHP 8.3 syntax/type assumptions. Style and static analysis include development tools. `composer style:fix` applies automatic style fixes.

| Command | Deterministic test coverage |
| --- | --- |
| `composer test` | Shipped SDK behavior and consumer examples (`sdk` suite) |
| `composer test:tools` | Sandbox harness, diagnostics and package inspection (`tools` suite) |
| `composer test:all` | Both PHP suites; equivalent to `vendor/bin/phpunit` |

Keep SDK regressions in `tests/Examples`, `tests/Resource`, or `tests/Unit` alongside the existing runtime tests. Development-tool regressions belong in `tests/Unit/Sandbox` or `tests/Unit/Tools`. Both suites require no credentials and make no provider requests; some tool tests use local subprocesses and loopback HTTP. The tooling and full-suite commands disable Composer's process timeout because historical source fixtures and journal durability checks can take several minutes. For browser or webhook tool changes, also run `node --test --test-concurrency=1 tools/browser/*.test.cjs tools/webhooks/*.test.cjs`.

Test shared validation rules directly, with representative workflow checks proving that orchestration applies them. Avoid repeating a complete sandbox journey for every invalid value or authentication profile when they use the same guard. Test-only pipe and fixture helpers are exercised through their callers. This keeps normal SDK feedback fast while retaining separate checks for the development harness.

This library leaves `composer.lock` untracked so each CI job resolves the declared constraints independently. Local `composer install` creates a development lock on its first run; use `composer update` to refresh it. To reproduce the lowest allowed set:

```sh
composer update --prefer-lowest --prefer-dist
composer check
composer check:consumer -- --lowest
```

CI runs SDK checks and clean consumer installation for PHP 8.3, 8.4 and 8.5 with both lowest and latest allowed dependencies. The separate development-tools job runs the PHP tooling suite once on PHP 8.4 and the Node policy tests on Node 22. Development tools are excluded from the distributed library, so their full orchestration tests do not repeat across the consumer compatibility matrix. See [release preparation](docs/release-preparation.md#package-and-consumer-checks) for source and archive consumer validation.

Keep sandbox validation opt-in, configured explicitly and separate from the deterministic suite. Never add credentials or captured customer data to fixtures. Supported provider versions, contract evidence and account eligibility are recorded in [API contracts](docs/api-contracts.md); release evidence must distinguish deterministic checks from sandbox observations.

For explicit sandbox configuration, safe external credential loading and journey evidence prerequisites, see [sandbox validation](docs/sandbox-validation.md).
