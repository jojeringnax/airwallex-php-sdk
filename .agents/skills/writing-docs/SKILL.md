---
name: writing-docs
description: Use when creating, editing or reviewing documentation for this SDK.
---

# Writing docs

Repository documentation is a reference for AI-driven development. Read [writing-for-agents](../writing-for-agents/SKILL.md) and apply its workflow and completion criteria to the changed documentation.

## Establish the document's job

Read `AGENTS.md`, the target document and its callers, relevant implementation, and linked issues or PRs. Identify the reader, the decisions or actions the document must support, and the evidence available. Keep conclusions limited to inspected sources; apply the repository's API verification requirements when documenting provider behavior.

Choose the document's responsibility before drafting:

- Scope documents record capabilities, boundaries, deferrals and decision rationale.
- Contract references record verified provider behavior, sources, versions and evidence gaps.
- Consumer documentation explains supported usage against the actual public interface.
- GitHub issues and Projects hold execution order, milestones, status and implementation follow-ups.

Use the existing document that owns the information. Create another only when it has a distinct purpose and a discoverable reference from the relevant entry point.

## Write the durable result

State confirmed decisions directly. Keep proposals and unresolved questions explicit. Include rationale when it explains a tradeoff or constrains later decisions; omit conversational history, approval narration and defensive caveats that repeat existing boundaries.

Keep each requirement in one authoritative place. A capability matrix can replace a prose list of the same capabilities. Link to shared instructions and tracked work rather than copying workflow rules or mutable GitHub metadata into the document.

State provider restrictions where they affect the documented capability. Separate desired coverage from verified availability, and identify uncertainty precisely enough to guide the next decision or verification. Preserve accepted scope while improving wording.

## Verify completion

Review the complete changed document, not just the edited sentences. Check accuracy against inspected sources, consistency with accepted decisions, duplication across referenced documents, and whether the entry-point pointer triggers reading at the right time. Apply every applicable writing-for-agents rule, including relevance, behavioral effect and necessity.

Check local links and formatting. Validate executable examples with checks appropriate to the change; report unexecuted examples and evidence gaps. For prose-only edits, review and whitespace checks are sufficient unless the repository supplies additional required checks.

Finish with the changed documents, meaningful review findings, checks actually run and remaining gaps. Keep commits, pushes and PR updates within the user's authorization and the requested change's scope.
