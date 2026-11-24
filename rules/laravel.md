# Laravel Rules — laravel-agent-guard

> Adapted from the `laravel-best-practices` skill. This is a **package**,
> not an app — adapt conventions accordingly.

## Stack

- Laravel 12 / 13 (`illuminate/contracts`, `illuminate/redis`,
  `illuminate/support` only).
- `laravel/ai ^1.0`, `laravel/mcp ^1.0`.
- PHPStan level max; Pint (Laravel preset); Pest + Testbench.

## Service provider

- Register all bindings in `AgentGuardServiceProvider`.
- Bind abstractions (`Ledger`), not concretes, where the caller chooses.
  The `Ledger` singleton is keyed off `config('agent-guard.driver')`.
- `mergeConfigFrom` the reference config; never read hardcoded values — go
  through the config repository.

## Facade

- `Facades/AgentGuard` is the stable public API. Changing its public methods
  is a breaking change.
- The facade only wraps `AgentGuard`; core logic stays in the class.

## Migrations

- Anonymous class: `return new class extends Migration`.
- Only the fallback driver (`DatabaseLedger`) needs a table; Redis needs none.
- Index columns used in queries; use `dropIfExists` in `down()`.

## Events

- Plain immutable value objects (not Laravel event objects unless broadcasting
  is needed).
- Carry scope, key, spend, cap — **never** raw usage payloads or secrets.

## No Eloquent in core

- The Redis Lua ledger is the source of truth. `DatabaseLedger` is the
  documented weaker-concurrency fallback. Core logic must not depend on
  Eloquent.

## Middleware / pipes (Phase 3+)

- laravel/ai middleware pipes have the contract
  `(PendingStep $step, Closure $next)` returning
  `StepResult|StepResponse|Generator`. `GuardAgentSteps::handle()` returns
  `StepResult` (use `StepResult::stop()` to halt before the model call).
- `PendingStep` is readonly: `withModel(string)`, `withoutTools(string ...)`,
  `withTools()`, `withMaxTokens(?int)`. There is **no** `withMaxSteps()` —
  step ceiling is run-level via `TextGenerationOptions->maxSteps`.
- Soft-cap downshift tightens via `withModel + withoutTools + withMaxTokens`.
- `GuardAgentSteps` is the step-level wedge: kill-switch → breaker → reserve →
  downshift → run → reconcile/detect. Build it via the fluent
  `GuardAgentSteps::make()` factory. The `GuardRuntime` (singleton) owns the
  shared `KillSwitch`, `CircuitBreaker`, `Ledger`, `CostEstimator`, and
  `PricingCatalog`; the per-run `LoopDetector` lives on the middleware.
- Reserve pessimistically from the step's message content, reconcile with the
  exact `StepResponse->usage` (convert via `Support\TextUsage::fromArray($u->toArray())`).
- USD and token ledgers are separate keys: the `BudgetGuard` prepends the
  unit (`usd`/`tokens`) to the ledger key parts.

## MCP tool guarding (Phase 4)

- `GuardedMcpTool` is a trait for `Laravel\Mcp\Server\Tool`. The SDK's
  `ToolInvoker` calls `[$tool, 'handle']` via the container (which injects
  `Laravel\Mcp\Request`). The trait **owns `handle()`** (the guarded entry
  point) and delegates the actual work to `run()`, which the consuming tool
  overrides.
- The trait resolves the same `GuardRuntime` singleton as `GuardAgentSteps`
  — one kill switch, one breaker, one ledger. One policy governs both the
  AI-SDK agent surface and the MCP tool surface.
- Correlation is **principal-based** (user_id / OAuth client_id / tenant id)
  — never the `MCP-Session-Id` header. The scope key is
  `tool:{toolName}:{principal}`. The loop detector is memoized per
  tool+principal on `GuardRuntime::loopDetectorFor()`, so stateless MCP
  requests share one sliding window.
- `guardBudget(): array` returns
  `['tool_daily_calls' => int, 'max_repeats' => int, 'tool_usd' => [soft, hard], 'tool_tokens' => [soft, hard]]`.
  `tool_daily_calls` reserves 1.0 per call against the `Tool` scope
  (`calls` unit) via the `Ledger` directly. `max_repeats` overrides the loop
  detector threshold. Optional `tool_usd`/`tool_tokens` caps use `BudgetGuard`.
- Hard-halts (kill switch, breaker, budget denial) return
  `Laravel\Mcp\Response::error()` — not exceptions — matching the SDK's
  `Errable` contract.
- Default is observe; call `guardEnforce()` to activate the action ladder.

## Testing & Artisan commands (Phase 5)

- `AgentGuard::fake()` rebinds `GuardRuntime::class` in the container to an
  `AgentGuardFake` (extends `GuardRuntime`, so it is **not final**). The fake
  swaps the `Ledger` for `InMemoryLedger` and the `Dispatcher` for
  `RecordingDispatcher` — zero side effects on real Redis or the production
  event bus, but app-level listeners still fire (the recorder delegates).
- The fake records every decision; assert via `AgentGuard::assertLoopDetected`,
  `assertDownshifted`, `assertBudgetSoftCapReached`,
  `assertBudgetHardCapReached`, `assertKillSwitchActivated`,
  `assertCircuitTripped`, `assertNothingDenied`. Each throws a
  `RuntimeException` when the event was not recorded.
- Artisan commands are registered in the service provider's `boot()` via
  `$this->commands([...])`: `agent-guard:status {tenant?}`,
  `agent-guard:kill {--tenant=}`, `agent-guard:budgets {tenant}`.
- Operational facade methods (`kill`/`unkill`/`status`/`budgets`/`reconcile`)
  delegate to the shared `GuardRuntime`. Budget keys built by `status`/`budgets`
  must mirror `GuardAgentSteps::keyPartsFor` so reported spend matches writes.

## Conventions

- No business logic in controllers (N/A — no controllers). Keep
  orchestration in `AgentGuard` / `BudgetGuard` / `LoopDetector`.
- `AgentResponse->usage` is `Laravel\Ai\Responses\Data\TextUsage`; our
  `Support/TextUsage` mirrors it for framework-agnostic core.
