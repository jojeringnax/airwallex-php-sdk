# Skill description triggers

A skill description tells the agent when to load the skill. It does not summarize the skill, its documentation, workflow, or output. Descriptions are critical because they determine whether the skill is loaded before the agent acts.

Write one concise sentence that names the upstream task requiring the skill. Put procedures, rules, explanations, prerequisites, and outputs in the skill body.

Choose the prefix that matches the invocation boundary:

- `Always use when ...` for narrowly scoped rules required for every matching task.
- `Use when ...` for task conditions the agent should detect automatically.
- `Use to ...` for a concrete operation or tool capability.
- `Use when the user asks ...` for workflows that require explicit user invocation.

Good:

```yaml
description: Always use when creating or editing skills.
description: Use when working on React forms.
description: Use to resolve Git conflicts.
description: Use when the user asks to open a pull request (PR).
description: Use to consult product documentation or validate an implementation or plan against it.
```

Bad:

```yaml
description: Skill creation best practices.
description: Docs for the @crustack/form package.
description: Git conflict resolution guide.
description: Open a GitHub PR in this repo.
description: Produces AC reports/pass-fail matrices.
```

The bad examples describe what a skill contains or produces without reliably stating when to invoke it.

Avoid:

- Broad triggers that match unrelated work.
- Downstream triggers that depend on a tool or library choice the skill is meant to guide.
- Workflow instructions or multiple sentences in the description.
- Subjective qualifiers when a concrete task boundary is available.
- `Always` unless every matching task genuinely requires the skill.
