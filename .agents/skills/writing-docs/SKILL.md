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

## Review and fix in context

For substantive documentation changes, use an independent subagent review before completion. Give the reviewer the complete changed document and diff, its entry-point references, the current request or issue acceptance criteria, accepted decisions, relevant implementation and linked prerequisite/follow-up work. Have it inspect the evidence rather than supplying the author's expected verdict.

Ask the reviewer to assess:

- Can a developer or implementing agent understand and act on the part documented by this change without conversation history or inventing behavior? Identify concrete unanswered questions, contradictions and unsupported claims with locations and evidence.
- Does the change satisfy the current request or issue? Distinguish completion of this contribution from completion of the whole document, which may be developed across several issues.
- Which gaps block the current contribution, and which belong to linked prerequisites, later issues or deliberate deferrals? Check that boundaries and references make that distinction discoverable; a future issue is not a valid reason to defer a current acceptance criterion.
- Are the document's callers and related references consistent, and does the entry-point pointer lead readers to it when needed? Apply the writing-for-agents relevance, behavioral-effect and necessity checks.

Fix findings within the authorized scope and resolve material scope or public-contract decisions with the user. Keep legitimate follow-ups explicit and linked to their owning work; avoid expanding the current contribution to finish unrelated parts of the document. Separate evidence still needed from decisions still needed, so a reader knows the next action.

Have the reviewer recheck the affected passages and context after fixes. Continue until current-scope blockers are resolved, or report the specific unresolved blocker and its required decision or evidence. When a PR is involved, base its issue-closing claim on the final revision and current acceptance criteria, following [open-pr](../open-pr/SKILL.md) within existing authorization. A review alone grants no permission to publish or close issues.

## Verify completion

Check local links and formatting. Validate executable examples with checks appropriate to the change; report unexecuted examples and evidence gaps. For prose-only edits, review and whitespace checks are sufficient unless the repository supplies additional required checks.

Finish with the changed documents, meaningful review findings, checks actually run and remaining gaps. Keep commits, pushes and PR updates within the user's authorization and the requested change's scope.
