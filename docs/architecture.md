# Package architecture

The SDK consists of a framework-independent PHP core and a separate Laravel integration package. See [development planning](planning.md) for capability scope.

## Packages and runtime support

| Package | Namespace | Supported runtimes |
| --- | --- | --- |
| `jojeringnax/airwallex-php-sdk` | `Jojeringnax\Airwallex` | PHP 8.3, 8.4 and 8.5 |
| `jojeringnax/airwallex-laravel` | `Jojeringnax\Airwallex\Laravel` | Laravel 12 and 13 on PHP 8.3, 8.4 and 8.5 |

The core is framework-independent and installable without Illuminate or a database. The separate Laravel package depends on the core and supplies framework integration and persistence. This lets plain PHP applications use the same API implementation as Laravel applications.

PHP 8.3 provides a shared minimum for both packages while retaining support for existing applications. As of 2026-11-08, PHP 8.3 receives security fixes through 2027-12-31. Laravel 12 and 13 both support the selected PHP versions; their security support ends on 2027-02-24 and 2028-03-17 respectively. See the official [PHP support table](https://www.php.net/supported-versions.php) and [Laravel support table](https://laravel.com/framework/docs/13.x/releases#support-policy).

## Dependencies and transport

The core accepts an injected PSR-18 HTTP client and PSR-17 request/stream factories, using PSR-7 messages. Consumers choose the concrete transport and message implementation. This avoids requiring a particular HTTP client or framework in every installation.

The selected interface dependency lines are `psr/http-client` 1.x, `psr/http-factory` 1.x, `psr/http-message` 2.x and `psr/simple-cache` 3.x. Their published PHP requirements admit PHP 8.3–8.5. Sources reviewed on 2026-11-08:

- [HTTP client requirements](https://github.com/php-fig/http-client/blob/1.0.3/composer.json)
- [HTTP factory requirements](https://github.com/php-fig/http-factory/blob/1.1.0/composer.json)
- [HTTP message requirements](https://github.com/php-fig/http-message/blob/2.0/composer.json)
- [Simple cache requirements](https://github.com/php-fig/simple-cache/blob/3.0.0/composer.json)

Token reuse uses in-memory storage by default, with an optional injected PSR-16 cache for reuse across processes. Both forms must isolate tokens by environment, credentials and applicable account context, and honor returned expiration. The cache backend belongs to the consuming application.

Keep runtime dependencies focused on these interfaces. Concrete transport/cache implementations are consumer choices; Illuminate dependencies belong to the Laravel package.

## State and persistence ownership

| Concern | Owner |
| --- | --- |
| Remote customers, subscriptions, payments, invoices and their authoritative billing state | Airwallex |
| API calls, response handling and token reuse without database persistence | PHP core |
| Resource mappings and synchronized local billing state in Laravel | Laravel integration package |
| Local user/tenant identity, credentials, infrastructure and cache backend | Consuming application |
| Access, entitlements and business policy | Consuming application |

The Laravel integration maintains local mappings and state through webhook synchronization and reconciliation. The application operates the required storage and workers and decides how observed billing state affects access. A local snapshot does not become the authority for remote financial state. Duplicate and out-of-order event handling remains part of synchronization work.

Detailed Laravel persistence interfaces are tracked in [issue #7](https://github.com/jojeringnax/airwallex-php-sdk/issues/7); API family authentication and connected-account ownership are tracked in [issue #3](https://github.com/jojeringnax/airwallex-php-sdk/issues/3) and [issue #4](https://github.com/jojeringnax/airwallex-php-sdk/issues/4).

## Consumer walkthroughs

These scenarios illustrate the package boundaries. Public interface examples are tracked in [issue #6](https://github.com/jojeringnax/airwallex-php-sdk/issues/6).

- **Plain PHP:** An application installs the core, supplies a PSR-18 client and PSR-17 factories, and configures an explicit environment and credential/account context. It uses in-memory token reuse or supplies a PSR-16 cache. It stores remote resource identifiers in its own persistence and applies its own access rules. No Laravel installation or SDK database is required.
- **Laravel:** An application installs the integration package, which depends on the core. Framework bindings supply the transport and configured cache. The integration stores resource mappings and synchronized billing state, while the application runs webhook/reconciliation processing and decides access from that state. Requests for distinct connected accounts retain isolated authentication contexts.

## Compatibility policy

Both packages use semantic versioning. During `0.x`, breaking changes must be documented with migration guidance; consumers should pin an appropriate minor release line. From `1.0`, breaking public interface changes require a major release. Supported runtime changes and breaking changes must be documented in release notes.

The Laravel package declares the core versions it supports. Detailed release gates and the definition of the supported public surface are tracked in [issue #9](https://github.com/jojeringnax/airwallex-php-sdk/issues/9).
