# Composition

`laravel-agent-guard` is a runtime execution layer. It is most useful when
composed with the packages that own the layers it does not. This document
gives three recipes.

## The layering model

```
┌─────────────────────────────────────────────────────────┐
│  Policy layer    Approvable / umpire / mcp-guard        │
│  ─────────────   who may do this, human approval,       │
│                    allowlists, behavioral receipts        │
├─────────────────────────────────────────────────────────┤
│  Execution layer  laravel-agent-guard  ← you are here   │
│  ─────────────    loop brake, budgets, kill switch,      │
│                    downshift, MCP tool guarding          │
├─────────────────────────────────────────────────────────┤
│  Transport layer  laravel/ai, laravel/mcp               │
│  ─────────────    pending steps, tool invocation         │
└─────────────────────────────────────────────────────────┘
```

`laravel-agent-guard` never replaces the policy layer — it enforces
runtime safety *after* a step is permitted to run, and *regardless* of
policy, because a loop can burn money even inside an authorized action.

## Recipe 1: agent-guard + Approvable (approval + brake)

`Approvable` gates whether a step may run and requires human approval;
`agent-guard` brakes the loop once the step is running.

```php
use Imgul\AgentGuard\Middleware\GuardAgentSteps;

public function middleware(): array
{
    return [
        \Laravel\Ai\Approvable::class, // policy first: human approval

        GuardAgentSteps::make()
            ->forTenant(fn () => Auth::id())
            ->budget(['run' => [0.25, 0.50], 'tenant' => [15.00, 20.00]])
            ->downshiftAt(0.80, toModel: 'claude-haiku-4-5')
            ->breakLoops(maxRepeats: 3)
            ->enforce(),
    ];
}
```

**Why this order:** `Approvable` short-circuits before execution when
approval is missing, so `agent-guard` only sees steps that were actually
authorized. The budget still caps the *cost* of an authorized runaway.

## Recipe 2: agent-guard + mcp-guard (MCP authorization + spend guard)

`mcp-guard` authorizes who may call an MCP tool; `agent-guard`
spend-guards the tool's execution via `GuardedMcpTool`.

```php
use Imgul\AgentGuard\Mcp\GuardedMcpTool;
use Laravel\Mcp\Server\Tool;

class QueryWarehouseTool extends Tool
{
    use GuardedMcpTool, \Laravel\McpGuard\GuardsTool; // authorization

    // mcp-guard's allowlist / policy hook
    protected function authorize(): bool
    {
        return $this->principal()->can('query-warehouse');
    }

    // agent-guard's runtime guard: budget + loop + kill switch
    protected function guardBudget(): array
    {
        return ['tool_daily_calls' => 100, 'max_repeats' => 3];
    }

    // ... your tool implementation ...
}
```

**Why compose:** `mcp-guard` decides *whether* the call is allowed;
`agent-guard` decides *how much it may cost and whether it is looping*.
A tool can be authorized for every principal yet still loop-burn; only
the execution guard stops that.

## Recipe 3: agent-guard + umpire (brake + audit trail)

`umpire` scores behavior and emits hash-chained receipts; `agent-guard`
brakes the loop. The two are independent and complementary.

```php
use Imgul\AgentGuard\Middleware\GuardAgentSteps;

public function middleware(): array
{
    return [
        \Laravel\Umpire\Middleware::class, // records behavioral receipts

        GuardAgentSteps::make()
            ->forTenant(fn () => Auth::id())
            ->budget(['run' => [0.25, 0.50]])
            ->breakLoops(maxRepeats: 3)
            ->enforce(),
    ];
}
```

**Why compose:** `umpire` gives you an auditable trail *after* the fact;
`agent-guard` prevents the costly outcome *during* the run. Receipts do
not stop a spiral — only the brake does.

## Common patterns

### Kill switch from an incident pipeline

Trigger `agent-guard:kill` (or `AgentGuard::kill($tenant)`) from any
incident pipeline; every guarded step for that tenant hard-halts on the
next invocation. No code change, no redeploy.

### Observe → enforce rollout

Ship in observe mode, watch the events (`LoopDetected`,
`BudgetSoftCapReached`, `CircuitTripped`), tune thresholds, then flip
`AGENT_GUARD_MODE=enforce`. The event surface is identical in both modes,
so dashboards keep working through the flip.

### Per-tenant budgets without code

Publish the config and set `tenant_daily_usd` in the environment; the
`agent-guard:budgets` command reports spend against the cap per tenant.
