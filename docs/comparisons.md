# Comparisons

An honest map of where `laravel-agent-guard` fits relative to the other
packages in the Laravel AI ecosystem. The short version is in the
README; this document is the full reference.

## The problem space

Guarding an AI agent has several distinct failure modes, and no single
package covers all of them. Treating one package as "the safety layer"
leads to either over-buying (a policy package that does nothing about a
runaway loop) or under-buying (a budget guard with no authorization).

The failure modes:

1. **Runaway loop / cost spiral** — the agent retries itself, context
   grows without progress, spend climbs until a hard limit or a bank
   statement stops it.
2. **Spend overrun** — many concurrent requests/workers collectively
   exceed a budget because each one sees only its own cost.
3. **Unauthorized action** — the agent calls a tool or performs an
   action the principal should not be allowed to perform.
4. **Untrusted output / behavior** — the agent produces output the
   operator wants to score, receipt, or redact.

## How the packages divide the work

| Failure mode | laravel-agent-guard | laravel/ai Approvable | laravel-umpire | laravel-mcp-guard |
|---|---|---|---|---|
| Runaway loop braking | ✅ per-step detector + breaker + kill switch | ❌ | ❌ | partial (MCP-level) |
| Atomic multi-tenant budgets | ✅ Lua-reserved, per-scope | ❌ | ❌ | ❌ |
| Mid-run model downshift | ✅ soft-cap ladder | ❌ | ❌ | ❌ |
| Per-request authorization | ❌ (runtime, not policy) | ✅ | ✅ | ✅ |
| Tool allowlists | ❌ | ✅ | ✅ | ✅ |
| Human-in-the-loop approval | ❌ | ✅ | ✅ | partial |
| Behavioral trust scoring | ❌ (v2) | partial | ✅ | ❌ |
| MCP tool execution guarding | ✅ wraps tool invocation | ❌ | ❌ | ✅ |
| Hash-chained receipts | ❌ (v2) | ❌ | ✅ | ❌ |

## laravel-agent-guard vs each

### vs `laravel/ai` Approvable

`Approvable` answers *"may this principal do this, and did a human
approve it?"*. It is request/policy-scoped. `laravel-agent-guard` answers
*"is this running loop about to burn money, and should we brake it?"*.
It is step/execution-scoped.

They compose cleanly: `Approvable` gates whether a step is permitted at
the policy layer; `laravel-agent-guard` sits in the execution pipeline
and brakes the step regardless of policy, because a loop can burn money
even inside an authorized action.

### vs `laravel-umpire`

`umpire` is a behavioral trust layer: it scores agent behavior and
produces hash-chained receipts. `laravel-agent-guard` does not score
behavior — it detects mechanical loop signatures (repeated calls,
context growth) and caps spend. If you need an auditable trust trail,
`umpire` is the answer; if you need to stop a spiral before it costs
$47,000, `laravel-agent-guard` is the answer. Use both when you need
both: `umpire` for the receipt, `agent-guard` for the brake.

### vs `laravel-mcp-guard`

`mcp-guard` guards MCP tool servers at the authorization boundary.
`laravel-agent-guard` guards the *execution* of an MCP tool — its budget,
its loop detector, its kill switch — via the `GuardedMcpTool` trait.
The overlap is the MCP surface; the difference is the axis: `mcp-guard`
is policy (who can call), `agent-guard` is runtime (how much it costs,
whether it loops, and whether to halt it). Compose them:
`mcp-guard` authorizes, `agent-guard` spend-guards.

## When to pick which (decision guide)

- **The agent loops / costs run away** → `laravel-agent-guard`.
- **You need per-step spend caps across concurrent workers** →
  `laravel-agent-guard`.
- **You need to downshift to a cheaper model on soft-cap** →
  `laravel-agent-guard`.
- **You need a human to approve tool calls** → `Approvable` / `umpire`.
- **You need allowlists per principal** → `Approvable` / `umpire` /
  `mcp-guard`.
- **You need an auditable behavioral receipt** → `umpire`.
- **You need to authorize MCP tool calls** → `mcp-guard`.

## The rule of thumb

> If the failure you are afraid of is **the agent looping and burning
> money**, `laravel-agent-guard` is the package. If the failure you are
> afraid of is **the agent doing something it was not allowed to do**,
> reach for a policy package first — and compose the two.

See [`composition.md`](composition.md) for concrete recipes.
