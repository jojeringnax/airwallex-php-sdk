# Airwallex PHP SDK

## Workflow

1. Inspect the files and GitHub items relevant to the request. Establish the current behavior, accepted decisions, and working-tree changes before editing. If a source is inaccessible, identify the gap and keep conclusions limited to available evidence.
2. Resolve decisions that materially affect scope or the public contract with the user. Keep proposals distinguishable from accepted requirements; record accepted decisions and their rationale in repository documentation.
3. Implement the requested outcome while preserving unrelated work. Use the repository's established toolchain and conventions; introduce new dependencies or conventions only when the task justifies them.
4. Validate the changed behavior with the available checks. Completion requires reporting the result of checks actually run, any remaining gaps, and unresolved decisions that affect delivery. Documentation changes require a review for accuracy and consistency.

Commit, push, release, and GitHub board changes require user authorization covering those actions.

## Planning

Before planning development or implementing capabilities, read [docs/planning.md](docs/planning.md) for accepted SDK scope, deferrals and implementation gates.

Use https://github.com/users/jojeringnax/projects/2 for project planning when accessible. For each proposed development item, define its outcome, scope, acceptance criteria, dependencies, and validation. Planning is complete when the proposed work has a clear order and every decision blocking implementation is identified for the user.

Choose product coverage, runtime support, authentication modes, and public interfaces from accepted requirements. Establish representative consumer examples before implementing a new public interface.

## API work

For endpoint implementation, authentication, retries, versioning, or webhooks, verify contracts against the official Airwallex documentation at https://www.airwallex.com/docs/api and the applicable product documentation. Record the source and API version alongside contract documentation. Verify the authentication and account context for each API family independently.

- Authentication state must remain isolated by environment, credentials, and applicable account context. Reuse tokens according to their returned expiration.
- Retried operations must be safe under the endpoint's documented semantics and retain the original idempotency identifier.
- Money handling must preserve decimal precision through serialization and arithmetic.
- Webhook verification must use the raw request body, the documented signing algorithm, and constant-time comparison. Document the consumer's responsibility for duplicate and out-of-order events.
- Public interface changes must account for compatibility with released versions and document breaking changes.

API work is complete when the implementation and examples match the verified contract, and meaningful tests cover the changed behavior and relevant failure paths.

## Credentials and validation environments

Use placeholders in examples, sanitized test fixtures, and redacted diagnostics. Keep credentials, tokens, webhook secrets, and customer data out of version control and diagnostic output. Keep TLS verification enabled.

Keep unit tests deterministic and independent of the network. Run integration validation in sandbox with explicit environment configuration and credentials supplied outside the repository. Live financial operations are outside development and test scope.

## Agent-document changes

Before creating or editing `AGENTS.md`, `CLAUDE.md`, or a skill, read [.agents/skills/writing-for-agents/SKILL.md](.agents/skills/writing-for-agents/SKILL.md) and apply its workflow and completion criteria.
