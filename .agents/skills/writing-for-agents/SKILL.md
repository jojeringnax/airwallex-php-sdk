---
name: writing-for-agents
description: Writing documents for agents. Use when creating or editing skills, or modifying AGENTS.md or CLAUDE.md.
---

Reference for writing any document an agent consumes — a skill, an `AGENTS.md` / `CLAUDE.md`, or a document reached by a pointer. The packaging differs; the writing does not: the same levers make each one predictable — the agent taking the same _process_ every run, not producing the same output.

When the document is a skill, read [`SKILL-MECHANICS.md`](SKILL-MECHANICS.md) for frontmatter, invocation choice, and router skills.

## Workflow

1. Identify the audience and the branches that need the document.
2. Place each instruction or reference on the information hierarchy.
3. Write the boundaries and completion criteria that affect the result.
4. Prune the draft.
5. Check every applicable rule in this skill against the draft.

## Context pointers

A **context pointer** is a reference held in the agent's context that names out-of-context material and encodes the condition for reaching it. A skill description is one; a line in `AGENTS.md` naming a document is the same object. The wording, not the target, decides when the agent reaches the material and how reliably.

A pointer states what the material is and names each distinct branch that should trigger it. Front-load the leading word, collapse synonyms that rename one branch, and cut identity the target already carries. Sharpen a weak pointer before inlining its target.

## The two loads

Every document and pointer spends one of two budgets:

- **Context load** — always-loaded material spends tokens and attention on every turn, whether or not it is used.
- **Cognitive load** — material with no model-facing pointer relies on the human to remember and invoke it. This is the price of human agency; spend it where human judgment matters.

## Information hierarchy

A document contains **steps**, the ordered actions the agent performs, and **reference**, the rules and facts consulted on demand. Place each piece on the highest useful rung:

1. **In-file step** — what the agent must do, in order.
2. **In-file reference** — material every relevant branch may need.
3. **Disclosed reference** — branch-specific material behind a context pointer.

**Progressive disclosure** moves material down this ladder. Inline what every branch needs; disclose what only some branches need. Too little disclosure buries the process. Too much hides material needed for correct execution.

**Co-location** keeps a concept's definition, rules, and caveats under one heading.

**Sprawl** is a document too long even when every line is unique; cure it by disclosing reference and splitting by branch or sequence.

## Steps and completion criteria

Every step ends on a completion criterion. It needs:

- **Clarity** — the agent can distinguish done from not done. Sharpen a vague bound before hiding later steps behind a real context boundary.
- **Demand** — the bound requires enough legwork. “Every modified model accounted for” is stronger than “produce a change list.”

The strongest criteria are checkable and exhaustive.

## When to split

Split only when the cut earns its added context or cognitive load:

- **By sequence** — hide later steps behind a real context boundary when they cause premature completion of the current step.
- **By invocation** — skill-specific; see [`SKILL-MECHANICS.md`](SKILL-MECHANICS.md).

## Leading words

Use an established compact term when it replaces repeated meaning and remains unambiguous in context. It can anchor execution in the body and invocation in a pointer. Verify that it changes behavior; otherwise remove it.

**Negation** is the failure mode beside this lever: steering by prohibition drags the forbidden behaviour into context and makes it _more_ available, not less. _Don't think of an elephant_, and the elephant is all there is; the negation is a weak modifier the strongly-activated concept overruns, so the ban half-reads as an instruction to do the thing. Prompt the **positive** — state the target behaviour ("write one-line comments") so the banned one is never spoken. A prohibition earns its place only as a hard guardrail you cannot phrase positively; even then, pair it with the positive target so attention lands on what to do.

## Preserve the solution space

Specify the boundaries that matter. Preserve freedom inside them.

State the required outcome, constraints, and completion criteria. Leave trivial implementation choices open when several approaches can satisfy them. An exhaustive-looking enumeration may make an omitted valid option appear unavailable, spends context, and anchors the agent to the author's current ideas instead of allowing stronger future models to find better approaches.

Prescribe a method or sequence when it affects correctness, safety, compliance, compatibility, reproducibility, or required output. Otherwise, describe the boundary and let the agent choose within it. Use examples to demonstrate a pattern, not to enumerate every valid possibility.

## Pruning

Keep each meaning in one authoritative place. Treat the environment as a source of truth: cache only information that is expensive to recover or cannot be found by inspection.

During the final pass, test each instruction in order:

1. **Relevance:** Does it bear on the document's task?
2. **Behavioral effect:** Does it change behavior from the model's default?
3. **Necessity:** Would removing it allow a materially wrong result?

A **prescriptive enumeration** defines or implies the available solutions, such as “use polling, webhooks, or queues.” A **presentational enumeration** only structures independent requirements, such as supported locales, required checks, or acceptance criteria. Either can use bullets, numbers, a table, or an inline sentence.

Remove an instruction when it fails an applicable test. If a prescriptive enumeration does not improve behavior over leaving the choice implicit, remove it. Keep a presentational enumeration when each item matters. When a constraint is necessary, state it at the highest useful level instead of enumerating every acceptable implementation.

## Completion

The document is complete when every applicable rule in this skill has been checked against the draft.
