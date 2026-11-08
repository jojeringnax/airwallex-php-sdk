---
name: create-issues
description: Use when preparing or creating GitHub issues for this SDK, including turning an accepted plan into project issues.
---

# Create issues

Create actionable issues in `jojeringnax/airwallex-php-sdk` and add each to [Airwallex PHP SDK](https://github.com/users/jojeringnax/projects/2), owned by `jojeringnax`, project number `2`. Assign every created issue to `jojeringnax`. These defaults are the user's accepted issue-management preferences; explicit task instructions can override them.

## Establish the issue set

Read the repository's `AGENTS.md`, relevant code and documentation, existing issues and discussions, and project items. Discover current project fields and options, labels, milestones, and available issue types rather than relying on cached IDs. Use connected GitHub tools where sufficient; use authenticated `gh` for Projects or other capabilities the connector lacks.

Finish this review with a proposed issue set that avoids duplicates, distinguishes accepted requirements from proposals, and identifies decisions blocking implementation. Represent unresolved product or public-interface choices as decision/discovery work, or mark dependent implementation work as blocked in the body. For API work, verify official Airwallex sources and record the applicable API version; if unverified, make verification an explicit prerequisite rather than asserting a contract.

## Prepare reviewable issues

Give each issue a concrete outcome and a concise title. Include enough context for a contributor who has not read the conversation:

- Problem and intended outcome, with relevant evidence or source links.
- Scope and boundaries, including compatibility implications where applicable.
- Observable acceptance criteria, preferably task checkboxes.
- Dependencies and execution order, using actual issue links once available.
- Validation: checks or scenarios that establish completion, including meaningful failure paths.
- Open decisions or evidence gaps that affect implementation readiness.

Use representative consumer examples for new public interfaces. Scale detail to the issue; omit empty boilerplate sections. Keep private conversational comparisons out of repository and GitHub content.

Preparing a draft does not authorize publication. When the user requests issue creation, creating the issues, assigning the user, adding them to this project, and populating applicable fields are part of that request. When only analysis or drafts are requested, present the concrete drafts and obtain authorization before GitHub writes. Creating this skill alone does not authorize creating issues.

## Create and populate

Create issues in dependency order and capture each resulting URL. Populate the following from evidence and accepted planning decisions:

| Field | Population rule |
| --- | --- |
| Title and body | Use the prepared outcome and specification. |
| Assignees | Assign `jojeringnax`, including when the authenticated operator differs. |
| Project | Add the real repository issue to owner `jojeringnax`, project `2`. |
| Status | Use `Todo` for newly planned work unless actual work state supports another available option. |
| Labels and issue type | Select applicable existing values by meaning; avoid labels implying readiness or suitability without evidence. |
| Milestone | Select an existing milestone matching the accepted release plan. If none fits, report the gap. |
| Parent, sub-issues and dependencies | Create native GitHub parent/sub-issue and `blocked by` / `blocking` relationships for the accepted hierarchy and dependencies. |
| Other custom fields | Populate every applicable writable field using its actual type and available options. Derive priority, dates, iteration and estimates only from accepted planning evidence. |

Maximize meaningful completeness rather than filling fields with guesses. Repository, timestamps, linked PRs, reviewers and sub-issue progress may be derived, read-only, or inapplicable to a new issue. Let GitHub supply them; link only real related work. Report fields that cannot yet be filled and why. Create new labels, milestones, project fields or options only when authorized by the task; otherwise propose missing configuration.

Prefer structured tool arguments for issue bodies. With `gh`, write the exact Markdown to a temporary file and use `--body-file` so newlines and shell-sensitive content are preserved.

Every parent/sub-issue and blocking relationship must exist as a native GitHub relationship between the actual issues, with the intended direction. Body mentions, links, task lists and project fields provide context only; they do not satisfy this requirement. If the current tool lacks relationship support, use another available GitHub capability. If support or permissions prevent creation, report the exact missing relationship and treat that part of the work as incomplete.

## Verify completion

Read back every created issue and its project item. Verify the body, assignee, project membership, status and applicable metadata against the prepared specification. Read back native GitHub relationship data to confirm every intended parent/sub-issue and `blocked by` / `blocking` relationship, including its direction; body text alone is insufficient evidence. Repair omissions within the authorized scope.

After a timeout or partial failure, inspect existing issues and items before retrying. Reuse successfully created issues and repair missing metadata rather than creating duplicates. If access or permissions prevent completion, preserve successful work and report the exact remaining operation.

Finish with issue links, their execution order, verification results, and any unfilled fields or unresolved decisions. Completion means every created issue is assigned to `jojeringnax`, present in project `2`, and has all evidence-supported applicable fields populated, or a clearly reported access/configuration gap.
