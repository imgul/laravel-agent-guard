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

## Conventions

- No business logic in controllers (N/A — no controllers). Keep
  orchestration in `AgentGuard` / `BudgetGuard` / `LoopDetector`.
- `AgentResponse->usage` is `Laravel\Ai\Responses\Data\TextUsage`; our
  `Support/TextUsage` mirrors it for framework-agnostic core.
