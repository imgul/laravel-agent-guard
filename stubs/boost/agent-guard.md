---
name: agent-guard
description: Add runtime safety to Laravel AI agents and MCP tool servers — loop braking, atomic multi-tenant budgets, model downshift, kill switch. Use when an agent or MCP tool can loop, overspend, or run away and you need per-step enforcement.
---

# laravel-agent-guard

A runtime-safety layer for `laravel/ai` 1.x agents and `laravel/mcp` tool
servers. It brakes runaway loops, enforces atomic multi-tenant
token/USD budgets, and gracefully downshifts the model mid-run instead of
failing hard.

## When to use this skill

Reach for `laravel-agent-guard` when the failure mode you are guarding
against is **the agent looping and burning money** or **spend overrunning
a cap under concurrency**. It is a runtime execution layer — it does not
do authorization, allowlists, or human approval (see
`docs/comparisons.md`). Compose it with `Approvable` / `umpire` /
`mcp-guard` when you also need policy.

## Install

```bash
composer require imgul/laravel-agent-guard
php artisan vendor:publish --tag=agent-guard-config
```

Ships in **observe mode** (events only, no enforcement). Flip
`AGENT_GUARD_MODE=enforce` to activate the action ladder.

## Guard a laravel/ai agent

Add the step middleware. Budgets are a map of scope → `[softCap, hardCap]`,
optionally with a third unit element `'usd' | 'tokens'`.

```php
use Imgul\AgentGuard\Middleware\GuardAgentSteps;

public function middleware(): array
{
    return [
        GuardAgentSteps::make()
            ->forTenant(fn () => Auth::id())
            ->budget([
                'run'    => [0.25, 0.50],
                'tenant' => [15.00, 20.00, 'usd'],
            ])
            ->downshiftAt(0.80, toModel: 'claude-haiku-4-5', withoutTools: ['DeepWebSearch'])
            ->breakLoops(maxRepeats: 3, maxStepGrowthRatio: 2.5)
            ->enforce(),
    ];
}
```

## Guard an MCP tool

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

## Operational controls

```bash
php artisan agent-guard:status              # overview or --tenant detail
php artisan agent-guard:kill --tenant=acme # incident response: hard-halt a tenant
php artisan agent-guard:budgets acme       # per-scope spend vs caps
```

From code:

```php
AgentGuard::kill($tenantId);
AgentGuard::unkill($tenantId);
AgentGuard::status($tenantId);
AgentGuard::budgets($tenantId);
```

## Testing

Swap in the fake and assert on recorded decisions — no Redis, no side
effects.

```php
AgentGuard::fake();

// ... exercise a guarded agent or MCP tool ...

AgentGuard::assertLoopDetected(tool: 'search');
AgentGuard::assertDownshifted(from: 'claude-opus-4-1', to: 'claude-haiku-4-5');
AgentGuard::assertBudgetSoftCapReached(scope: 'tenant');
AgentGuard::assertKillSwitchActivated(tenant: $tenantId);
AgentGuard::assertNothingDenied();
```

## Key concepts

- **Observe vs enforce** — observe runs every detector and emits every
  event but enforces nothing. Enforce activates downshift, break, and
  kill. Ship observe first, tune, then flip to enforce.
- **Scopes** — `run`, `agent`, `tenant`, `tool`; each in `usd` or
  `tokens`. Budget keys are tenant-derived, never agent-supplied.
- **Three-stage ladder** — observe → soft-cap downshift → hard kill.
- **Kill switch** — an open switch hard-halts the next guarded step for
  the scoped key. The "stop the bleeding" control.

## Do not

- Do not use this for authorization, allowlists, or human approval —
  that is the policy layer.
- Do not hardcode prices in release tags; the pricing catalog is data.
- Do not enable enforce in production before tuning thresholds in
  observe mode.
