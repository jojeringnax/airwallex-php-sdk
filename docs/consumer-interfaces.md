# Consumer interfaces

Accepted public contract for [#6](https://github.com/jojeringnax/airwallex-php-sdk/issues/6). Use this reference when designing or reviewing SDK signatures and payload handling. The conventions constrain future implementation; runnable consumer recipes follow package implementation.

[Planning](planning.md) owns capability scope, [architecture](architecture.md) owns dependencies and runtime support, and [API contracts](api-contracts.md) owns the provider baseline (`2026-08-21`).

## Interface conventions

Use resource-oriented synchronous methods with provider-shaped associative array bodies and responses. This keeps native field names visible and accommodates payment-method-specific fields without a large DTO hierarchy. Application responsibilities follow [state ownership](architecture.md#state-and-persistence-ownership).

| Concern | Contract |
| --- | --- |
| Resource access | `$client->billing()->subscriptions()` and `$client->payments()->paymentIntents()`; lower camel case PHP methods, native snake case payload keys |
| Operations | `create(array $body): array`, `retrieve(string $id): array`, `update(string $id, array $body): array`, `list(array $query = []): array`; named actions such as `cancel`, `capture`, `confirm`, `verify` only where the endpoint exists. A bodyless action takes only the ID. |
| Local identity | Application stores opaque remote ID strings with environment, owning organization/account and local identity. Local-ID resolution belongs to the application. Billing and Payments customers are distinct. |
| Optional fields | Omitted array key stays omitted. Explicit `null` is serialized as JSON null only where the endpoint permits it. `false`, `0`, `''` and `[]` are preserved, never filtered by truthiness. Use `new stdClass()` for an empty JSON object, `[]` for an empty JSON array. Response key absence differs from a present null; use `array_key_exists` when that matters. JSON objects decode to associative arrays, except empty objects return `stdClass` to preserve `{}` versus `[]`. |
| Decimal input | Money/decimal fields use base-10 strings, e.g. `'19.90'`; reject PHP floats at documented decimal paths. Endpoint-aware encoding writes these as exact JSON numbers where the schema requires numbers. It must never globally convert numeric-looking strings: IDs, metadata and other string fields remain strings. |
| Decimal output | Known decimal fields, including integer-valued monetary amounts, return strings. Known integer counts use PHP integers within range; oversized integers return strings. Unknown numeric fields retain their original JSON numeric text as strings. Unknown strings and arrays retain their values/shape. Lossless parsing occurs before any float conversion. |
| Amount arithmetic | Provider amounts use major units plus a separate currency string. Consumers use decimal arithmetic; the SDK does not calculate proration, tax or currency conversion. See [payload precision](payload-precision.md) for codec validation and arithmetic boundaries. |
| Timestamps | Timezone-bearing ISO8601 strings for API fields; preserve fractional precision and offset. Webhook header timestamps retain their exact millisecond string for verification. |
| Unknown fields/values | Preserve additional response keys and unknown status/event strings. Decode unknown strings unchanged; consumers interpret them. Requests are checked against implemented operation schemas; new request fields require contract review before decimal/null semantics can be promised. |
| Mutation identity | Consumers supply and durably associate each documented `request_id` with the intended action; the SDK never silently generates/replaces it. Bodyless endpoints without request identity do not gain an invented field. Automatic mutation replay stays disabled until endpoint safety is verified in [#15](https://github.com/jojeringnax/airwallex-php-sdk/issues/15). |
| Responses | Return resource/list envelopes as arrays, preserving nullable fields and provider pagination. Each call performs its named operation. HTTP/error metadata is exposed through exceptions on failure. |

These choices establish the future public surface before an initial release. Once shipped, changes follow the [compatibility and release policy](release-policy.md), including its release gates. Resource contracts determine operation coverage.

## Subordinate reads

Billing item and line-item reads use the direct methods accepted in [#61](https://github.com/jojeringnax/airwallex-php-sdk/issues/61#issuecomment-6749286212):

| Resource | Accepted signatures |
| --- | --- |
| `subscriptions()` | `retrieveItem(string $id, string $itemId): array`, `listItems(string $id, array $query = []): array` |
| `invoices()` | `retrieveLineItem(string $id, string $itemId): array`, `listLineItems(string $id, array $query = []): array` |

The direct resource structure keeps item reads discoverable alongside the parent operations. Every call retains the explicit parent ID; individual reads also take the item ID. Responses remain native arrays, and list calls return one bookmark-paginated page. The names and parameter shapes follow the shared compatibility policy once released. See the [executed item-read recipe](../examples/billing-item-reads.php) for all four calls and [Billing resources](billing-resources.md) for schema provenance and remaining provider evidence.

For implemented environment, token, transport, retry and diagnostic behavior, see [client configuration](client-configuration.md). Its optional clock and retry configuration remain a delegated provisional decision under [#60](https://github.com/jojeringnax/airwallex-php-sdk/issues/60).

## Workflow references

Read the relevant [consumer example](consumer-examples.md) when implementing or reviewing a workflow:

| Work | Reference |
| --- | --- |
| Client construction, token cache and direct/delegated account context | [Initialization](consumer-examples.md#initialization-and-account-contexts) |
| Customer linkage, checkout business/UI modes | [Customer and checkout](consumer-examples.md#customer-linkage-and-checkout) |
| Saved instruments, direct subscriptions, provider/local trials | [Instrument and subscription](consumer-examples.md#saved-instrument-and-direct-subscription) |
| Plan/quantity changes, cancellation, invoice links, recovery | [Subscription changes](consumer-examples.md#changes-cancellation-and-invoice-access) |
| Individual Subscription/Invoice items and explicit pages | [Item reads](consumer-examples.md#subscription-items-and-invoice-line-items) |
| Payment creation, authorization, capture, cancellation, refunds | [Standalone Payments](consumer-examples.md#standalone-payments) |
| List envelopes, pagination and typed exceptions | [Pagination and failures](consumer-examples.md#pagination-and-failures) |
| Raw-body verification and event decoding | [Webhook boundary](consumer-examples.md#webhook-boundary) |

## Provider evidence

The example fields and actions were reviewed on 2026-11-08 against official selected-version metadata for [Customers](https://www.airwallex.com/docs/api/billing/billing_customers), [Subscriptions](https://www.airwallex.com/docs/api/billing/subscriptions), [Checkouts](https://www.airwallex.com/docs/api/billing/billing_checkouts), [Consents](https://www.airwallex.com/docs/api/payments/payment_consents), [Intents](https://www.airwallex.com/docs/api/payments/payment_intents) and [Refunds](https://www.airwallex.com/docs/api/payments/refunds), each reporting `currentVersion=2026-08-21`.

Billing and Payments implementations, sanitized contract fixtures and runnable recipes follow these interfaces; see [Billing resources](billing-resources.md), [Payments contracts](payments-contracts.md) and [usage](usage.md) for coverage. Remaining provider observations belong to [Sandbox validation #42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42). The [Billing capability reference](billing-capabilities.md#delivery-and-evidence-gates) identifies the invoice-link-refresh, schedule-reversal and account-specific validation gates.
