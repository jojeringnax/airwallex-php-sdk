# Package architecture

The SDK is a single framework-independent PHP package. See [development planning](planning.md) for capability scope.

## Package and runtime support

| Package | Namespace | Supported runtimes |
| --- | --- | --- |
| `jojeringnax/airwallex-php-sdk` | `Jojeringnax\Airwallex` | 64-bit PHP 8.3, 8.4 and 8.5 |

The initial release requires a 64-bit PHP build, following the [runtime decision in #101](https://github.com/jojeringnax/airwallex-php-sdk/issues/101#issuecomment-6749546000). The webhook clock returns Unix milliseconds as a PHP integer; contemporary timestamps exceed the 32-bit signed range. [PHP integer range](https://www.php.net/manual/en/language.types.integer.php) depends on the build, so Composer requires its [virtual `php-64bit` platform package](https://getcomposer.org/doc/articles/composer-platform-dependencies.md) alongside `php: >=8.3 <8.6`. This installs no new code and preserves the integer-millisecond clock/verifier interface and exact decimal behavior. Supporting 32-bit PHP would require a separately designed timestamp contract and validation; it is outside the initial release.

The SDK installs without a framework or database. Applications choose their own integration and persistence model.

PHP 8.3 provides the minimum supported runtime while retaining support for existing applications. As of 2026-11-08, PHP 8.3 receives security fixes through 2027-12-31. See the official [PHP support table](https://www.php.net/supported-versions.php).

## Dependencies and transport

The core accepts an injected PSR-18 HTTP client and PSR-17 request/stream factories, using PSR-7 messages. Consumers choose the concrete transport and message implementation. This avoids requiring a particular HTTP client or framework in every installation.

The selected interface dependency lines are `psr/http-client` 1.x, `psr/http-factory` 1.x, `psr/http-message` 2.x and `psr/simple-cache` 3.x. Their published PHP requirements admit PHP 8.3–8.5. Sources reviewed on 2026-11-08:

- [HTTP client requirements](https://github.com/php-fig/http-client/blob/1.0.3/composer.json)
- [HTTP factory requirements](https://github.com/php-fig/http-factory/blob/1.1.0/composer.json)
- [HTTP message requirements](https://github.com/php-fig/http-message/blob/2.0/composer.json)
- [Simple cache requirements](https://github.com/php-fig/simple-cache/blob/3.0.0/composer.json)

Token reuse uses in-memory storage by default, with an optional injected PSR-16 cache for reuse across processes. Both forms must isolate tokens by environment, credentials and applicable account context, and honor returned expiration. The cache backend belongs to the consuming application.

Keep runtime dependencies focused on these interfaces. Concrete transport/cache implementations are consumer choices.

## State and persistence ownership

| Concern | Owner |
| --- | --- |
| Remote customers, subscriptions, payments, invoices and their authoritative billing state | Airwallex |
| API calls, response handling and token reuse without database persistence | PHP core |
| Resource mappings, persistence and synchronized local billing state | Consuming application |
| Local user/tenant identity, credentials, infrastructure and cache backend | Consuming application |
| Access, entitlements and business policy | Consuming application |

Applications own webhook processing, reconciliation, storage and workers. They decide how observed billing state affects access and handle duplicate and out-of-order events. Airwallex remains the authority for remote financial state.

For API family authentication, consult the [API inventory](api-contracts.md). For connected-account authorization, resource ownership and outstanding eligibility evidence, consult [connected-account contracts](connected-account-contracts.md).

## Consumer walkthrough

An application installs the SDK, supplies a PSR-18 client and PSR-17 factories, and configures an explicit environment and credential/account context. It uses in-memory token reuse or supplies a PSR-16 cache. It stores remote resource identifiers in its own persistence and applies its own access rules. Requests for distinct connected accounts retain isolated authentication contexts.

For initialization, resource methods, request/response representation and consumer workflows, see [consumer interfaces](consumer-interfaces.md).

## Compatibility policy

The [compatibility and release policy](release-policy.md) defines the supported public surface, semantic versioning, maintenance, deprecations, PHP/dependency and provider-version upgrades, and observable release gates. Consult it before changing compatibility or preparing a release.
