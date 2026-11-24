# Code Quality Rules — laravel-agent-guard

> Adapted from the `code-review-skill` (awesome-skills). Review behavior,
> not formatting — let Pint handle style.

## Review mindset

- **Catch bugs and edge cases first**: off-by-one, null checks, race
  conditions, integer overflow on token counts, float precision on USD.
- **Reuse before new code** — search for existing utilities/helpers before
  writing a new one (e.g. `TextUsage::add()` vs manual summation).
- **Single responsibility** — a class with multiple responsibilities is a
  finding.
- **Not the goals**: showing off knowledge, nitpicking formatting (use
  linters), blocking progress unnecessarily, rewriting to preference.

## Anti-patterns to reject

| Anti-pattern | Why |
|---|---|
| **TOCTOU** | Any read-then-write of a budget without an atomic op is a blocking finding. The whole BudgetGuard prevents this. |
| **Parameter sprawl** | Too many parameters → extract a value object (`BudgetReservation`, `DetectorContext`). |
| **Leaky abstractions** | `Ledger` impls must not leak Redis/DB types through the contract. |
| **Nested conditionals** | Prefer early returns / guard clauses. |
| **Stringly-typed code** | Use enums (`BudgetScope`) and typed constants, not magic strings. |
| **No-op updates** | Avoid writes that change nothing. |
| **Redundant state** | Derive values instead of storing duplicates. |

## Effective feedback

- Specific and actionable, educational not judgmental, focused on the code.
- Balanced — praise good work too.
- Prioritized — critical vs nice-to-have.

```
❌ "This is wrong."
✅ "This could cause a race condition when multiple workers access
     simultaneously. The Redis Lua EVAL must stay atomic."
```

## Severity labels (use in review output)

- 🔴 `[blocking]` — must fix before merge (correctness, security, TOCTOU).
- 🟡 `[important]` — should fix, discuss if disagree.
- 🟢 `[nit]` — nice to have, not blocking.
- 💡 `[suggestion]` — alternative approach to consider.
- 📚 `[learning]` — educational, no action needed.
- 🎉 `[praise]` — good work, keep it up.

## Review process

1. **Context** — read the change, check CI status, understand the requirement.
2. **High-level** — architecture fit, SOLID, performance implications, file
   organization, testing strategy.
3. **Line-by-line** — logic/correctness, security, performance,
   maintainability, reuse (check adjacent files for similar patterns).
4. **Summary & decision** — ✅ Approve / 💬 Comment / 🔄 Request Changes.

## Output format

```
file:line - [category] Description of issue
```

Example:
```
src/Budget/BudgetGuard.php:142 - [solid] Hard-cap check reads cap after reservation, risks TOCTOU
```

## What NOT to review manually

- Code formatting (Pint)
- Import organization
- Linting violations
- Simple typos
