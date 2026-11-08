---
name: open-pr
description: Use when the user asks to open or update a pull request for this SDK.
---

# Open PR

Prepare focused PRs in `jojeringnax/airwallex-php-sdk` and add them to [Airwallex PHP SDK](https://github.com/users/jojeringnax/projects/2), owned by `jojeringnax`, project number `2`. Populate labels and milestone from the actual change and accepted planning. Explicit user instructions override these defaults.

## Establish the change

Read `AGENTS.md`, relevant issues and discussions, the working-tree changes, and the full diff against the intended base. Establish which issue criteria the final change satisfies, what remains unresolved, and which files belong to the request. Preserve unrelated work and keep it out of the PR.

Discover the remote default branch, existing PRs for the branch, current labels, milestones, and project fields/options. Prefer connected GitHub tools; use authenticated `gh` for capabilities or permissions the connector lacks. Reuse an existing matching PR rather than creating a duplicate.

Complete the requested implementation or documentation and run appropriate checks before publishing. For documentation, apply [writing-docs](../writing-docs/SKILL.md). Resolve material scope or public-contract decisions before claiming implementation is complete.

## Prepare and publish

Write the title and description around the final diff for a reviewer who has not read the conversation. Lead with the concrete problem or decision and resulting behavior. Include only validation actually performed, material limitations and follow-up links needed to assess the change. Scale structure to complexity and follow any repository template. Rewrite stale descriptions when scope changes.

Use `Closes #N` when the PR resolves the issue. If acceptance criteria remain, describe the gap and link the actual follow-up; use a non-closing reference unless the user explicitly requests closure with those gaps disclosed. Verify the closing relationship after publication, especially when the target is not the default branch.

A request to open a PR authorizes the necessary scoped branch, commit, push, PR creation and metadata population described here. Drafting or reviewing alone authorizes preparation, not publication. Creating this skill grants no permission to publish, merge or release. Use existing session authorization without asking for it again.

Commit only the intended changes and push to the intended remote. Prefer structured body arguments; with `gh`, write the exact Markdown to a temporary file and use `--body-file`.

## Populate metadata

| Metadata | Selection rule |
| --- | --- |
| Labels | Apply existing labels describing the actual change. Linked issue labels are evidence, not a set to copy automatically. |
| Project | Add the actual PR to owner `jojeringnax`, project `2`; an issue's membership does not add the PR. |
| Milestone | Use the linked issue's milestone when the PR delivers that milestone's scope; otherwise select an existing milestone matching accepted planning. Report conflicting or missing evidence. |
| Project fields | Populate applicable writable fields from evidence and actual work state, using discovered field types and options. |

A request to open or update the PR includes these metadata operations. Create new labels, milestones, project fields or options only with authorization covering those configuration changes. Leave unsupported dates, estimates and priorities unset and explain material gaps. Update only metadata relevant to the request, preserving unrelated manual choices.

## Verify completion

Read back the PR's title, body, base/head branches, files, labels, milestone, closing issue references, project membership and applicable project fields. Compare the published diff and metadata with the intended change. Confirm reported checks match their actual results.

After a timeout or partial failure, inspect the remote state before retrying. Reuse the successfully created PR and repair missing metadata within scope. If permissions or unavailable configuration prevent completion, report the precise remaining operation; distinguish a created PR from completed metadata setup.

Finish with the PR link, validation results, unresolved delivery gaps and any metadata that could not be populated. Opening the PR does not authorize merging it or closing linked issues manually.
