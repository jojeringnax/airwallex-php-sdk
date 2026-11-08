# Client configuration and shared behavior

The client uses the [accepted initialization interface](consumer-examples.md#initialization-and-account-contexts) and API **2026-08-21**. Supply an explicit `Environment`, `Credentials`, a PSR-18 HTTP client and PSR-17 request and stream factories. Optional `tokenCache` accepts a PSR-16 cache. No configuration is loaded from environment variables by the SDK.

The optional `clock` and `retryPolicy` constructor arguments and `RetryPolicy` defaults below are **provisional implementation decisions**, pending [human review #60](https://github.com/jojeringnax/airwallex-php-sdk/issues/60). They add testable configuration within the accepted scope; they are not human-approved interface additions.

```php
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\RetryPolicy;

$client = new Client(
    environment: Environment::Sandbox,
    credentials: new Credentials('<client-id>', '<api-key>'),
    httpClient: $httpClient,
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
    retryPolicy: new RetryPolicy(maxAttempts: 3),
);
```

## Environment and account context

`Environment::Sandbox` uses `https://api.sandbox.airwallex.com`; `Environment::Production` uses `https://api.airwallex.com`. Every login and resource request carries `x-api-version: 2026-08-21`. Production requires explicit selection. The SDK offers no custom-host override. Configure the injected HTTP client with TLS certificate verification enabled, suitable connection/read/overall timeouts, and a redirect policy that protects authorization headers. PSR-18 exposes no standard per-request timeout or TLS configuration; the SDK cannot configure or verify those settings in an arbitrary injected client.

`loginAccountId` selects an authorized account through login `x-login-as`. `forConnectedAccount($id)` returns a separate client using resource `x-on-behalf-of`, without altering its parent or another derived client. Combining login-account selection with delegation fails locally. Billing on a delegated client also fails locally; Payments delegation does not establish organization Billing authorization. See the [account contract](connected-account-contracts.md) for permissions, resource ownership and unverified connected-merchant Billing eligibility.

## Token reuse

The client acquires a token lazily and reuses it in memory until the returned `expires_at`. Optional PSR-16 cache entries use a SHA-256 key incorporating environment, the full credential fingerprint, login selection and delegated account. Neither credentials nor account identifiers appear in the cache key as plaintext. The cache contains bearer tokens and belongs in access-controlled consumer infrastructure.

Invalid, malformed or expired cache entries cannot authorize a request; the client obtains a new token. An invalid or already-expired login response raises `ProtocolException`. Cache exceptions and false results from storing or invalidating an entry become a safe `ConfigurationException`; the current operation aborts before using a rejected entry again. Repair the cache before another deliberate operation. PSR-16 supplies no atomic locking: independent processes may both obtain a token for the same context. Isolation and returned expiration remain required; distributed single-flight acquisition is not promised.

The optional `clock` is a `Closure` returning non-negative Unix seconds as an integer; the default calls `time()`. This differs from the webhook verifier's explicitly millisecond clock. A resource `401` invalidates the rejected token. A read may acquire a fresh token and replay once within its overall attempt budget. A mutation is surfaced immediately; the next deliberate operation can acquire a fresh token. Login failures never trigger a refresh loop.

## Retries and request identity

`RetryPolicy` permits bounded retries of GET operations for HTTP `429`, `500`, `502`, `503`, `504` and transport failures without a usable HTTP response. Other HTTP failures, malformed successful responses and local validation failures are surfaced immediately. A timeout may happen after transmission; automatic mutation replay stays disabled, including after token rejection. Consumers preserve their persisted `request_id` and reconcile ambiguous mutation outcomes. Request uniqueness and duplicate-request errors do not establish safe mutation replay semantics.

The provisional defaults are `maxAttempts: 3` (including the first attempt), `baseDelayMilliseconds: 1000` and `maxDelayMilliseconds: 16000`. The allowed bounds are 1–10 attempts and delays from 0–60000 milliseconds, with maximum delay at least the base. Exponential ceilings double after each failed attempt up to the maximum; full random jitter chooses a delay from zero through that ceiling. `maxAttempts: 1` disables automatic retries and read replay after token rejection.

For deterministic validation, optional `sleeper` receives the chosen integer milliseconds and optional `randomInt` receives inclusive minimum/maximum integers and returns an integer in that range. Defaults use `usleep()` and `random_int()`. These callbacks and the clock are consumer code and should not log secrets or payloads. Retry exhaustion throws the final safe `ApiException` or `TransportException`; it does not replace the original action identity or run a recovery mutation.

## Responses, paging and failures

Successful resource calls decode JSON objects according to the [lossless payload contract](consumer-interfaces.md#interface-conventions). Only known integer paths become PHP integers within range; unknown numeric tokens retain their exact text. Request encoding preserves body omission separately from a JSON object body. Resource schemas own decimal, null, field and mutability checks.

List calls issue exactly one explicit page request, plus any eligible retries. Billing bookmarks and Payments page numbers retain their native envelopes. Caller-selected page sizes are integers from 1 through 1000; Payments page numbers start at zero. Retain every original filter and the same scoped client when following another page. No automatic iterator or normalized pagination envelope is added.

All failure types implement `Exception\SdkException`. `ApiException` exposes HTTP status and safe nullable `providerCode()`/`providerSource()`, retaining neither raw provider message/details nor response/request bodies. Provider codes with invalid identifier syntax, oversized text or an echoed credential/payload value are omitted. Error sources are limited to a conservative list of known root request fields; nested paths, metadata keys and unknown parameter names are omitted so dynamic customer data cannot become a diagnostic identifier. Default string, debug and serialized diagnostics omit stack arguments; explicitly inspecting a stack trace is a consumer responsibility. Malformed error JSON still produces an `ApiException` with its HTTP status. Malformed successful JSON produces `ProtocolException`; a missing usable response produces `TransportException` without retaining the original transport exception. `ConfigurationException` covers setup/context failures and `ValidationException` covers local argument failures.

Credentials, configured clients and internal transports redact normal debug output and reject serialization. Resource group debugging therefore also hides injected HTTP client history. Keep consumer HTTP middleware, custom cache implementations and application logs free of credentials, bearer tokens and customer payloads. The SDK supplies no raw-body diagnostic logger.


The executable [failure classification example](../examples/classify-failure.php) maps actual SDK exceptions to application actions without logging payloads or starting retries. Load your Composer autoloader, then use its returned closure in a catch block:

```php
$classifyFailure = require '/path/to/examples/classify-failure.php';
try {
    $observed = $client->payments()->paymentIntents()->retrieve($storedIntentId);
} catch (\Jojeringnax\Airwallex\Exception\SdkException $error) {
    $action = $classifyFailure($error);
    // Application handles fix_local_arguments, check_permissions, back_off_before_next_safe_operation,
    // reconcile_ambiguous_outcome, or another returned action using its persisted operation identity.
}
```

Local validation requires fixing arguments; `403` requires checking account permissions; `429` selects backoff before another safe operation. A network failure requires reconciling a possible mutation outcome. These are consumer decisions, and the example never replays a mutation. Deterministic tests execute every classification against the actual exception types.

## Verified sources and validation boundary

Reviewed against selected-version metadata `2026-08-21` and official product guidance:

- [Introduction and environment hosts](https://www.airwallex.com/docs/api/introduction).
- [Authentication headers, token reuse and returned expiry](https://www.airwallex.com/docs/api/authentication/api_access_token).
- [API key account and organization selection](https://www.airwallex.com/docs/developer-tools/api/manage-api-keys).
- [Version override](https://www.airwallex.com/docs/api/versioning).
- [Error fields and HTTP statuses](https://www.airwallex.com/docs/api/errors).
- [Rate limiting and retry guidance](https://www.airwallex.com/docs/developer-tools/api/rate-limits).
- [Billing bookmarks](https://www.airwallex.com/docs/api/billing/billing_customers/list) and [Payments page numbers](https://www.airwallex.com/docs/api/payments/payment_intents/list).

The implementation is validated with deterministic fake transports/clocks/caches. Account eligibility, exact provider enforcement and end-to-end sandbox journeys remain in [#17](https://github.com/jojeringnax/airwallex-php-sdk/issues/17) and [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42). No safe mutation replay window or provider `Retry-After` contract is inferred from the general rate-limit guidance.
