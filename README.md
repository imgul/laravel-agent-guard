# laravel-agent-guard

**Step-aware loop braking, atomic multi-tenant budgets, and graceful model downshift for Laravel AI agents and MCP tool servers.**

`laravel-agent-guard` is a fully open-source (MIT) runtime-safety layer that runs **inside** `laravel/ai` 1.x's per-step `PendingStep` pipeline and wraps `laravel/mcp` tool execution. It is the step-level runtime execution layer: instead of failing hard at the request boundary with 500s/503s or estimating tokens at the HTTP wire, it brakes the agent's generation loop per step and degrades gracefully when spend or loops run away.

## The failure mode it stops

A runaway agent loop is the costliest failure mode in production AI systems: a multi-step retry spiral can burn **$47,000 in 11 days** while health checks report "working as designed". Existing packages fail hard (exception / 402 / 503) or estimate tokens at the wire — none operate per step, and none downshift the model mid-run. `laravel-agent-guard` does three things:

1. **Step-aware loop & context-growth braking** — exact-repeat, normalized-argument, frequency, and token-growth detection; circuit breaker (`CLOSED → OPEN → HALF_OPEN`); instant kill switch.
2. **Atomic multi-tenant token/USD budgets** — per-run / per-agent / per-tenant / per-tool caps enforced with atomic Redis Lua reservations, so concurrent Horizon workers can never collectively race past a hard cap (TOCTOU solved).
3. **Graceful model downshift** — on soft-cap breach, swaps the next step to a cheaper model via `PendingStep::withModel()` and tightens tools/steps, instead of throwing at paying users.

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

The package ships in **observe mode**: every detector runs and every event fires, but nothing is enforced until you flip `AGENT_GUARD_MODE=enforce`. Adoption is zero-breakage by default.

## Quick start

Attach the step middleware to any `laravel/ai` agent:

```php
use Imgul\AgentGuard\Middleware\GuardAgentSteps;

public function middleware(): array
{
    return [
        GuardAgentSteps::make()
            ->forTenant(fn () => tenant()->id)
            ->budget(runUsd: 0.25, tenantDailyUsd: 15.00)
            ->downshiftAt(0.80, toModel: 'claude-haiku-4-5', withoutTools: ['DeepWebSearch'])
            ->breakLoops(maxRepeats: 3, maxStepGrowthRatio: 2.5),
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

## What it is not (v1 scope)

`laravel-agent-guard` owns **runtime execution and spend safety**. It is a complement, not a replacement:

- **Authorization / allowlists / human approval** → `laravel/ai` `Approvable`, `laravel-umpire`, `laravel-mcp-guard`
- **Behavioral trust scoring + hash-chained receipts** → deferred to v2
- **PII/egress redaction, credential vaulting, sandboxing, tool-poisoning scanning, agent identity/DID** → out of scope

## Status

Phase 0 (skeleton & tooling) — package skeleton, service provider, config stub, `AgentGuard` facade, Pest + Testbench suite, and CI (Pest matrix on Laravel 12/13, PHPStan max, Pint, nightly pinned to `laravel/ai 1.x-dev` + `laravel/mcp 1.x-dev`) are in place. See `PLAN-laravel-agent-guard.md` for the phased roadmap toward v1.0.0.

## License

MIT — fully open-source. Safety enforcement is never paywalled.
