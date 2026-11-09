# Hosted no-input security observation

This development-only workflow observes a finite part of the first-party security bootstrap for one fresh, owned Sandbox HOSTED/SETUP/EUR Checkout. It uses a separate fixed entry point and leaves `instrument_ready`, `financial_permission` and `security_graph_complete` false. No actual provider result has been recorded for this workflow. Broader delivery evidence remains under [#42](https://github.com/jojeringnax/airwallex-php-sdk/issues/42).

The controller reuses the reviewed fresh Customer/Checkout lifecycle, SDK read-back and complete ownership checks. It renders only the original correlated payer document and its one qualified Drop-in frame. Existing normal reads retain their original eight-second window and fixed field snapshot; this workflow performs no Card selection, input, submission, instrument creation, payment or financial browser request. Independently verified own ACTIVE Checkout cleanup uses one cancellation and terminal read-back. Failed or ambiguous creation supplies no guessed cleanup identity.

Run it only with explicit Sandbox credentials and a private state directory outside Git, plus an already installed Node/Playwright/Chrome toolchain:

```sh
php tools/hosted-instrument-security.php \
  --environment=sandbox \
  --credentials-file=/private/external/sandbox-credentials \
  --state-dir=/private/external/validation-state \
  --node-bin=/absolute/path/to/node \
  --playwright-module=/absolute/path/to/playwright \
  --observe-first-party-security
```

The bare flag selects one fixed profile; it accepts no URL, resource ID, old ledger, account/context override, timeout, arbitrary family or header option. The existing `hosted-setup-preflight.php` entry point keeps its existing profile and options. Neither command reports a completed instrument journey.

## Finite roles and original-source proof

The security set contains the pinned fingerprint loader GET, one BWS visitor preflight OPTIONS, one visitor POST and at most eight source-bound event POSTs. Visitor/preflight target `/bws/v3/visit`; events target `/bws/v1/{same source session}` on `bws.sandbox.airwallex.com`. Existing role-specific cookie, CORS, header, body, identity and cumulative limits remain in force. New vendor families, risk iframe documents, challenges, DoH, WebSockets and financial requests remain denied. A denied request is evidence of the local boundary; it does not establish a provider failure or missing account permission.

The source checkpoint is the anonymously inspected Sandbox Elements v2.56.0 and fingerprint asset on 2026-11-08. The fingerprint asset is 137,456 bytes, SHA-256 `8198d811842296bb2ab7e339c0f5f4309c39df77d20837e459c09e350c990f8b`; its existing dated pin expires on 2026-11-15. Changed path/build/bytes or expired evidence withholds delivery without automatic repinning. [Device fingerprinting](https://www.airwallex.com/docs/payments/payment-operations/fraud-prevention/device-fingerprinting) supplies product context; the finite routes and exact browser order come from the recorded public build, rather than a promise that the entire service graph is stable.

That build can append its asynchronous loader before constructing Drop-in. One original loader candidate may wait privately for up to two seconds for natural later frame/session proof. It receives no upstream send or journal acknowledgement while unbound. Promotion rechecks the original main document, frame, source-defined script attributes, exact query and session. Duplicate candidates, navigation/reload, detachment, mismatched proof or expiry permanently withhold the original; no replacement, session invention, forced frame construction or script rewrite occurs. This ordering test does not diagnose earlier zero-admission observations.

## Durability, transport and limits

The new profile has a 40-second absolute browser ceiling, closes new admissions at 30 seconds and response release at 38 seconds, and reserves the final two seconds for shutdown. Its security stage lasts at most 15 seconds from the first candidate, including any early wait. Every operation is clipped to the original bounds; frame replacement and awaiting work do not reset them. Existing preflight eight-second normal reads, 5.5-second snapshot/new-send latch and 40-second case remain unchanged. In the new profile, method-config construction and response work use one immutable deadline captured from the original normal window; headers, body and fulfillment cannot use the longer security stage. A late context gets no send, and an already-sent late response gets no delivery. Shutdown grace reaps processes; it grants no traffic permission.

Private FD frames are bounded to 4 KiB, decoded-key unique and checked using literal native JSON types. A new adapter delegates BWS durability to the existing journal and handles only the additional closed loader role. The original default helper book is captured before authentication; the InstrumentSecurity BWS subset is derived solely from its captured extended book. It translates the monotonically increasing private control sequence around loader frames so the unchanged BWS journal keeps its own contiguous sequence. HTTP target, body, headers and local UUID bytes are never rewritten. Loader/BWS prepare records retain exact wire-body, target, prepared-header, session and scope hashes with source provenance; durable possible-send state precedes the ACK that permits invocation. A durable response result precedes delivery. Missing/wrong ACK, source changes, replay, persistence failure or unfinished outcomes withhold dependent work. BWS has no invented provider request UUID; its journal ID is local.

The new loader GET uses the native relay with TLS hostname verification and identity encoding only. It bounds the stream to 256 KiB before buffering, then requires the exact pin before release. BWS keeps its existing 64 KiB per-body and 256 KiB cumulative limits. Redirects, retries, unsupported encoding, framing/header overflow, stalled or slow-positive reads, deadline and close failures fail closed. Legacy preflight loader handling remains its existing Playwright buffered response followed by the size/hash check; the new streaming role is unavailable there.

For this profile the local proxy resolves all answers, rejects mixed or non-public address sets, connects once to a selected numeric address and verifies the connected peer before acknowledging CONNECT. TLS still verifies the original hostname, with no alternate-IP retry. Private, loopback, link-local, mapped and reserved ranges are withheld. Loopback resolver/CA fixtures exist only in deterministic tests and are unreachable through the fixed real entry. This is a conservative local routability policy, not an ownership assertion about DNS answers. See Node's [DNS lookup](https://nodejs.org/api/dns.html#dnspromiseslookuphostname-options) and [connected peer](https://nodejs.org/api/net.html#socketremoteaddress) semantics.

## Privacy and incomplete evidence

Credentials and SDK login bearer tokens are not passed to Node, Chrome or service code. The URL, browser capability, session, permitted new current-case cookies and telemetry remain private in memory; retained state contains bounded hashes and fixed facts, not raw request bodies or URLs. Operator output contains closed labels, booleans, counts and nonsecret run/source/context fingerprints. Chrome uses a dedicated temporary profile, TLS verification, blocked workers and minimal environment; API transport stays in PHP.

Trusted service code can access its current page/session capability, browser client secret and any synthetic fields later placed in that document unless a concrete policy proves a narrower boundary. A disposable profile isolates old browser state, not the current document or same-UID processes. [Provisional decision #104](https://github.com/jojeringnax/airwallex-php-sdk/issues/104) records that trust choice and remains open for human review. This first slice adds no dynamic vendor family and no input or financial permission. A successfully observed selected role does not establish complete risk processing, readiness, eligibility, collection or release readiness.
