# laravel-agent-guard

**Step-aware loop braking, atomic multi-tenant budgets, and graceful model downshift for Laravel AI agents and MCP tool servers.**

`laravel-agent-guard` is a fully open-source (MIT) runtime-safety layer that runs **inside** `laravel/ai` 1.x's per-step `PendingStep` pipeline and wraps `laravel/mcp` tool execution. It is the step-level runtime execution layer: instead of failing hard at the request boundary with 500s/503s or estimating tokens at the HTTP wire, it brakes the agent's generation loop per step and degrades gracefully when spend or loops run away.

## The failure mode it stops

A runaway agent loop is the costliest failure mode in production AI systems: a multi-step retry spiral can burn **$47,000 in 11 days** while health checks report "working as designed". Existing packages fail hard (exception / 402 / 503) or estimate tokens at the wire — none operate per step, and none downshift the model mid-run. `laravel-agent-guard` does three things:

1. **Step-aware loop & context-growth braking** — exact-repeat, normalized-argument, frequency, and token-growth detection; circuit breaker (`CLOSED → OPEN → HALF_OPEN`); instant kill switch.
2. **Atomic multi-tenant token/USD budgets** — per-run / per-agent / per-tenant / per-tool caps enforced with atomic Redis Lua reservations, so concurrent Horizon workers can never collectively race past a hard cap (TOCTOU solved).
3. **Graceful model downshift** — on soft-cap breach, swaps the next step to a cheaper model via `PendingStep::withModel()` and tightens tools/steps, instead of throwing at paying users.

It ships in **observe mode** by default: every detector runs and every event fires, but nothing is enforced until you flip `AGENT_GUARD_MODE=enforce`. Adoption is zero-breakage.

## Requirements

- PHP **8.3+**
- Laravel **12 / 13**
- `laravel/ai` **^1.0**
- `laravel/mcp` **^1.0**
- Redis recommended (documented DB fallback driver available)

## Installation

```bash
composer require imgul/laravel-agent-guard
php artisan vendor:publish --tag=agent-guard-config
```

## Quick start

Attach the step middleware to any `laravel/ai` agent. Budgets are configured as a map of scope → `[softCap, hardCap]` (each scope may add a third element `'usd' | 'tokens'`):

```php
use Imgul\AgentGuard\Middleware\GuardAgentSteps;

public function middleware(): array
{
    return [
        GuardAgentSteps::make()
            ->forTenant(fn () => tenant()->id)
            ->budget([
                'run'    => [0.25, 0.50],           // soft, hard (USD)
                'tenant' => [15.00, 20.00, 'usd'], // soft, hard, unit
            ])
            ->downshiftAt(0.80, toModel: 'claude-haiku-4-5', withoutTools: ['DeepWebSearch'])
            ->breakLoops(maxRepeats: 3, maxStepGrowthRatio: 2.5),
            ->enforce(), // omit for observe-only
    ];
}
```

Guard an MCP tool with the same tenant budget, loop detector, and kill switch:

```php
use Imgul\AgentGuard\Mcp\GuardedMcpTool;
use Laravel\Mcp\Server\Tool;

class QueryWarehouseTool extends Tool
{
    use GuardedMcpTool;

    protected function guardBudget(): array
    {
        return ['tool_daily_calls' => 100, 'max_repeats' => 3];
    }
}
```

### Operational controls

Inspect and intervene at runtime without touching code:

```bash
php artisan agent-guard:status              # global overview or --tenant detail
php artisan agent-guard:status acme         # budgets, breaker & kill-switch for a tenant
php artisan agent-guard:kill --tenant=acme # open the kill switch (incident response)
php artisan agent-guard:budgets acme       # per-scope spend vs caps
```

The same surface is available from code via the facade:

```php
AgentGuard::kill($tenantId);          // hard-halt a tenant
AgentGuard::unkill($tenantId);        // release it
AgentGuard::status($tenantId);        // budgets, breaker, kill-switch state
AgentGuard::budgets($tenantId);       // per-scope spend summary
```

### Testing

Swap in the fake and assert on every recorded decision — no Redis, no side effects:

```php
AgentGuard::fake();

// ... exercise a guarded agent or MCP tool ...

AgentGuard::assertLoopDetected(tool: 'search');
AgentGuard::assertDownshifted(from: 'claude-opus-4-1', to: 'claude-haiku-4-5');
AgentGuard::assertBudgetSoftCapReached(scope: 'tenant');
AgentGuard::assertKillSwitchActivated(tenant: $tenantId);
AgentGuard::assertNothingDenied();
```

## Honest comparison: which package for which job

`laravel-agent-guard` is one layer in a stack. It is deliberately **not** everything, and pretending otherwise would be misleading. Here is the honest map:

| Job | Pick | Why not laravel-agent-guard |
|---|---|---|
| Brake runaway loops, cap spend per step, downshift the model mid-run | **laravel-agent-guard** | — |
| Atomic multi-tenant budgets across concurrent workers | **laravel-agent-guard** | — |
| Per-request authorization, tool allowlists, human-in-the-loop approval | `laravel/ai` `Approvable`, `laravel-umpire`, `laravel-mcp-guard` | runtime execution, not policy |
| Behavioral trust scoring + hash-chained receipts | deferred to v2 | not in v1 scope |
| PII/egress redaction, credential vaulting, sandboxing, tool-poisoning scanning | dedicated tooling | out of scope |

**The short version:** if the failure you are afraid of is *the agent looping and burning money*, this package is the one. If the failure you are afraid of is *the agent doing something it should not have been allowed to do*, reach for an authorization/approval package first — and compose them. See [`docs/comparisons.md`](docs/comparisons.md) for the full matrix and [`docs/composition.md`](docs/composition.md) for recipes.

## What it is not (v1 scope)

`laravel-agent-guard` owns **runtime execution and spend safety**. It is a complement, not a replacement:

- **Authorization / allowlists / human approval** → `laravel/ai` `Approvable`, `laravel-umpire`, `laravel-mcp-guard`
- **Behavioral trust scoring + hash-chained receipts** → deferred to v2
- **PII/egress redaction, credential vaulting, sandboxing, tool-poisoning scanning, agent identity/DID** → out of scope

## Benchmarks

Micro-benchmarks for the two hot paths (loop detection, budget reservation/reconciliation) live in [`benchmarks/loop-budget-bench.php`](benchmarks/loop-budget-bench.php). Run them locally and compare across versions:

```bash
php benchmarks/loop-budget-bench.php --runs=200000
```

See [`docs/benchmarks.md`](docs/benchmarks.md) for methodology and representative numbers.

## Status

**v1.0.0** — all five phases complete: loop guard core, atomic budgets + Lua ledger, `PendingStep` middleware + downshift ladder, the MCP surface, and the operational/testing DX (Artisan commands, `AgentGuard::fake()`). See the phased roadmap in `PLAN-laravel-agent-guard.md`.

## License

MIT — fully open-source. Safety enforcement is never paywalled.
