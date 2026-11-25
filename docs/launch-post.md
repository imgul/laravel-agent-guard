# Launch Post (draft)

> Draft for Laravel News and r/laravel. Adjust the tone and links for each
> venue. References the live repo, Packagist install, and the benchmark
> reproducer.

---

## Stop your Laravel AI agent from burning $47,000 while it "works"

A runaway agent loop is the most expensive failure mode in production AI
systems. A multi-step retry spiral can burn **$47,000 in 11 days** while your
health checks happily report "working as designed." This isn't hypothetical —
it happened to a production LangChain agent ([case study: the $47k infinite
loop](https://github.com/vectara/awesome-agent-failures/blob/main/docs/case-studies/langchain-a2a-47k-infinite-loop.md)).

The existing guard packages fail hard — an exception, a 402, a 503 — or
estimate tokens at the HTTP wire. That is the gap `laravel-agent-guard`
fills: it operates at the step level, and it downshifts the model mid-run.

## What it does

`laravel-agent-guard` is a runtime-safety layer that runs **inside**
`laravel/ai` 1.x's per-step `PendingStep` pipeline and wraps `laravel/mcp`
tool execution. Three controls, one policy:

1. **Step-aware loop braking.** Exact-repeat, normalized-argument,
   frequency, and context-growth detection over a sliding window, with a
   circuit breaker (`CLOSED → OPEN → HALF_OPEN`) and an instant kill switch.
   The loop is halted *before the next billable step leaves the server*.
2. **Atomic multi-tenant budgets.** Per-run / per-agent / per-tenant /
   per-tool caps in tokens and USD, enforced with atomic Redis Lua
   reservations. Ten concurrent Horizon workers can never collectively race
   past a hard cap — TOCTOU, solved.
3. **Graceful model downshift.** On a soft-cap breach, the next step is
   swapped to a cheaper model via `PendingStep::withModel()` and tools/steps
   are tightened, instead of throwing a 503 at a paying customer.

It ships in **observe mode** by default: every detector runs and every event
fires, but nothing is enforced until you flip `AGENT_GUARD_MODE=enforce`.
Zero-breakage adoption.

## The wedge: step-level + downshift

Four MIT packages already claim loop-breaking and cost-capping. What makes
this one different is the **step**: it brakes inside the generation loop,
per step, and it **downshifts the model** rather than failing. That is the
window that was open, and it is the difference between a graceful degraded
run and a 503 at your most expensive moment.

## Install

```bash
composer require imgul/laravel-agent-guard
php artisan vendor:publish --tag=agent-guard-config
```

Attach the middleware to any `laravel/ai` agent:

```php
use Imgul\AgentGuard\Middleware\GuardAgentSteps;

public function middleware(): array
{
    return [
        GuardAgentSteps::make()
            ->forTenant(fn () => Auth::id())
            ->budget(['run' => [0.25, 0.50], 'tenant' => [15.00, 20.00]])
            ->downshiftAt(0.80, toModel: 'claude-haiku-4-5')
            ->breakLoops(maxRepeats: 3)
            ->enforce(),
    ];
}
```

Guard an MCP tool with the same policy:

```php
use Imgul\AgentGuard\Mcp\GuardedMcpTool;

class QueryWarehouseTool extends \Laravel\Mcp\Server\Tool
{
    use GuardedMcpTool;

    protected function guardBudget(): array
    {
        return ['tool_daily_calls' => 100, 'max_repeats' => 3];
    }
}
```

Intervene at runtime without a redeploy:

```bash
php artisan agent-guard:status acme
php artisan agent-guard:kill --tenant=acme   # stop the bleeding
php artisan agent-guard:budgets acme
```

## The evidence

Run the 5-step loop reproducer and watch the spiral turn into a bounded,
downshifted run:

```bash
php benchmarks/loop-budget-bench.php
```

It runs the same runaway 5-step loop twice — with and without the guard —
and reports the tokens saved, the USD saved, and the outcome: an unbounded
spiral (503 / hard-cap blowout) versus a **graceful degraded response**
where the breaker halts the loop and the model downshifts.

## Honest positioning

`laravel-agent-guard` owns **runtime execution and spend safety**. It is a
complement, not a replacement:

- **Authorization, allowlists, human approval** → `laravel/ai` `Approvable`,
  `laravel-umpire`, `laravel-mcp-guard`
- **Behavioral trust scoring + receipts** → deferred to v2
- **PII/egress redaction, sandboxing, tool-poisoning scanning** → out of scope

If the failure you are afraid of is **the agent looping and burning money**,
this is the package. If it is **the agent doing something it shouldn't**,
reach for a policy package first — and compose the two. (Full matrix in
`docs/comparisons.md`, recipes in `docs/composition.md`.)

## Testing

`AgentGuard::fake()` swaps in an in-memory runtime that records every
decision with zero Redis or side-effect exposure, so you assert on the guard
in your own app tests:

```php
AgentGuard::fake();

// ... exercise a guarded agent ...

AgentGuard::assertLoopDetected(tool: 'search');
AgentGuard::assertDownshifted(from: 'claude-opus-4-1', to: 'claude-haiku-4-5');
AgentGuard::assertBudgetSoftCapReached(scope: 'tenant');
AgentGuard::assertNothingDenied();
```

## License

MIT — fully open-source. Safety enforcement is never paywalled.

---

**Links:** [GitHub](https://github.com/imgul/laravel-agent-guard) ·
[Packagist](https://packagist.org/packages/imgul/laravel-agent-guard) ·
[Docs](https://github.com/imgul/laravel-agent-guard/blob/main/README.md)
