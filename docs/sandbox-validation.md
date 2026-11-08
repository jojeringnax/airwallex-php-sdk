# Sandbox validation

The integration harness is opt-in and separate from deterministic unit tests. It loads an external credential file as literal data, selects `Environment::Sandbox`, enables TLS verification, disables redirects and rejects every request outside `https://api.sandbox.airwallex.com/api/v1/`. The SDK itself never reads environment files. Guzzle is a development-only concrete PSR-18/PSR-17 implementation for this harness; consumers choose their own implementation.

Install development dependencies, then run the authentication/token-reuse scenario with an explicit context:

```sh
composer test:sandbox -- --environment=sandbox --credentials-file=/absolute/private/path/.env --scenario=authentication --context=organization
composer test:sandbox -- --environment=sandbox --credentials-file=/absolute/private/path/.env --scenario=authentication --context=account
```

The credential source must be an absolute regular local file, limited to 64 KiB; URL and stream-wrapper sources are rejected. The external file uses `AIRWALLEX_CLIENT_ID` and `AIRWALLEX_SANDBOX_API_KEY_MASTER`. Account context additionally uses `AIRWALLEX_ACCOUNT_ID` as login `x-login-as`; organization context omits that header. A successful organization login does not establish Billing permissions, and account login does not establish connected-account delegation or collection eligibility. Supply credentials independently suitable for the selected context. `AIRWALLEX_ORGANIZATION_ID` is not substituted into an undocumented login header.

The parser accepts uppercase `NAME=value` entries, optional `export`, blank lines, comments and single/double quoted literal values. It never executes shell code, expands variables or interprets escapes; malformed or duplicate names fail. Running without options reports a skip. Partial configuration, a missing file or a non-sandbox environment fails before requests. No credentials, raw responses, tokens, customer data or stack traces are printed. The result is a sanitized scenario/version/context/status envelope; authentication validates the returned expiration through the SDK and confirms a second token request reuses memory.

Authentication creates no financial resources and requires no cleanup. Later journey scenarios must identify their own resources with a unique test-run marker, retain created identifiers only in private run state, and restrict teardown to those identifiers. Where a provider offers no deletion or archival operation, report retained test resources and their lifecycle explicitly. Never sweep existing account resources or use live financial operations for validation.

## Journey prerequisites and evidence

[API contracts](api-contracts.md) and [connected-account contracts](connected-account-contracts.md#evidence-still-needed) own product permissions and eligibility. Initial Billing journeys require organization Billing activation, legal entities, catalogue configuration and independently eligible linked collection accounts. Connected merchant Billing additionally requires provider confirmation of merchant organization/credential mapping, platform access and eligible collection linkage. An account ID or successful token exchange does not establish those facts.

[Webhook contracts](webhook-contracts.md#remaining-integration-evidence) identify matching-version provider-delivery and Retrieve evidence still required for Billing envelope ownership, event availability and subscription setup. Schema-derived fixtures remain synthetic. Payment simulations require the method-specific sandbox facilities and independently verified direct/delegated account authorization.

[#17](https://github.com/jojeringnax/airwallex-php-sdk/issues/17) owns this harness and initial context checks. [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42) owns the full accepted journey matrix: checkout/setup, subscription changes/trials/cancellation/recovery, invoice access, instruments, one-time/subsequent Payments, authorization/capture/cancel, refunds, webhook deliveries and two-account isolation. Each run must record exact commit, API/webhook version, scenario and pass/fail/blocked result without credentials or customer data. Authentication evidence alone does not satisfy those journeys or the [release gates](release-policy.md#observable-release-gates).

Official references: [sandbox environment](https://www.airwallex.com/docs/developer-tools/sandbox-environment/overview), [authentication](https://www.airwallex.com/docs/api/authentication/api_access_token), [Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html). Contract baseline: API `2026-08-21`.
