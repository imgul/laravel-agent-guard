# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-11-25

### Added

- **`breakLoops(frequency:)` fluent API.** The frequency detector threshold
  can now be set per-call via the middleware chain, overriding the config
  default. Defaults to `agent-guard.loop.frequency_threshold` (8) when
  omitted.
- **`AgentGuard::killGlobal()`.** Open the global kill switch — halts every
  guarded run everywhere in a single call.
- **`AgentGuard::killRun(string $runId)`.** Open the kill switch for a
  specific agent run, targeting a single run without affecting other runs.
- **`agent-guard:kill --run=` option.** Target a single agent run by its
  run id. Also added `--global` flag; without any option, the global
  switch opens (previously opened a run-scope switch).
- **Circuit breaker TTL.** Breaker state entries now carry a configurable
  TTL (`agent-guard.loop.breaker.ttl_seconds`, default 600 = cooldown × 10),
  so finished runs' breaker records self-expire instead of accumulating.

### Fixed

- **Kill switch not wired into `GuardAgentSteps::handle()`.** The middleware
  previously checked `isOpenFor('run', 'run:{tenant}')` which never matched
  the keys `AgentGuard::kill()` wrote. Now checks global (`isGloballyOpen()`),
  tenant (`isOpenForTenant()`), and run scope (`isOpen('run', $runId)`).
- **Circuit breaker keyed by tenant, not run.** The breaker was keyed by
  `run:{tenant}`, so a loop in one run blocked all later runs for that
  tenant. Now keyed by a unique run ID generated on the first step
  (`PendingStep::isFirstStep()`), stored on the middleware, and cleared
  in `reset()`. `AgentGuard::status()` and `reconcile()` updated to match.
- **Breaker never probed, never expired.** `handle()` now implements the
  HALF_OPEN probe lifecycle: when `isOpen(runId) && allowProbe(runId)`, one
  probe step is let through with pruned context; `afterStep()` calls
  `probeResult()` based on whether loop findings occurred (success → close,
  failure → reopen). Saved with TTL.
- **Frequency threshold hardcoded to 3.** `detectorInstance()` hardcoded
  `frequencyThreshold: 3`. Now reads `config('agent-guard.loop.frequency_threshold')`
  (default 8), overridable via `breakLoops(frequency:)`.
- **`forTenant()` dropped integer and Stringable tenants.** Non-string
  returns became null, skipping tenant budgets. Now casts `int` and
  `Stringable` to string; other types log a warning (once per run) and
  return null.
- **Benchmark `SOFT_CAP_USD` too high.** Set to 15.00, the downshift never
  fired. Rescaled to 0.05 so the downshift fires at step 2-3 with real
  pricing. Benchmark output in `docs/benchmarks.md` replaced with real
  run results.
- **Anthropic pricing wrong.** `claude-opus-4-1` was $5/$25 (correct:
  $15/$75); `claude-haiku-4-5` was $0.80/$4.00 (correct: $1/$5).
  `gpt-5`, `gpt-5-mini`, and `gemini-3-pro` verified against provider
  pages. Catalog version bumped to `2026-11-25.2`.
- **Launch post claims.** Removed the unsourced "$200+ drained" line and
  the "None operate at the step level" claim. Linked the $47k case study
  source in `docs/launch-post.md`.

## [1.1.0] - 2026-11-25

### Fixed

- **Kill switch scope mismatch.** `AgentGuard::kill($tenant)` opened scope
  `tenant`, but the middleware checked scope `run` — they never matched, so
  kill/unkill and `agent-guard:kill` had no effect on guarded agents. The
  middleware now checks global, tenant, and run scopes. Added
  `AgentGuard::killGlobal()` for the global switch.
- **Circuit breaker never closed.** The middleware only called `isOpen()`;
  nothing ever called `probeResult()` or used the cooldown. The breaker was
  keyed by `run:{tenant}`, so a loop in one run blocked all later runs for
  that tenant. Now keyed per-run (unique run ID), with HALF_OPEN probe
  lifecycle: after cooldown, one probe step is allowed through;
  `probeResult()` closes on success or re-trips on failure.
- **Frequency detector too strict.** Tripped on the 3rd call to the same
  tool with any arguments. Now has its own configurable threshold
  (default **8**), separate from `maxRepeats`, so normal agents that
  legitimately call `search` several times with different queries are not
  flagged.
- **Pricing unit bug.** `prices.json` declared `usd_per_1k_tokens` but the
  values were per-1M prices, making USD budgets ~1000× too high. Changed
  the unit to `usd_per_1m_tokens` and divide by 1,000,000 in
  `PricingCatalog`. Bumped catalog version to `2026-11-25.1`.
- **`forTenant()` silently dropped integer tenants.** Non-string returns
  became null, skipping tenant budgets. Now casts `int` and `Stringable`
  to string.
- **Benchmark docs didn't match the code.** Sample output was illustrative
  only. Rescaled `SOFT_CAP_USD` to 0.05 so the downshift fires at correct
  prices.
- **CI red.** The testbench install step failed on `composer require`.
  Fixed the workflow to use `composer require --dev` + `composer install`.
- **Launch post claims.** Removed the unsourced "$200+ drained" line and
  the "None operate at the step level" claim (`promptphp/intercept-budget-guard`
  is also per-step). Linked the $47k case study source.

### Added

- **Cross-process switch persistence.** Kill switch and circuit breaker
  state now persists through a `SwitchStore` (Redis, cache/database, or
  in-memory for tests). `php artisan agent-guard:kill` now affects Horizon
  workers, Octane, and web requests — not just the CLI process.
- **`frequency_threshold` config.** New config key for the frequency
  detector threshold, plus `breakLoops(frequency: …)` fluent API.
- **`killGlobal()` method.** Open the global kill switch — halts every
  guarded run everywhere.

## [1.0.0] - 2026-11-24

The first stable release: a step-aware runtime-safety layer for `laravel/ai`
1.x agents and `laravel/mcp` tool servers. Ships in **observe mode** by
default (zero-breakage adoption); flip `AGENT_GUARD_MODE=enforce` to activate
the action ladder.

### Added

- **LoopGuard core (Phase 1).** `LoopDetector` with a sliding window running
  four detectors: exact-repeat, normalized-argument, frequency, and
  context-growth (step-over-step input-token growth with no progress).
  Pluggable similarity strategies (`SimHash`, `TrigramJaccard`,
  `EmbeddingAdapterInterface`) with semantic escalation gated on elevated
  frequency (FR-1.5 cost control).
- **Circuit breaker (FR-1.6).** `CLOSED → OPEN → HALF_OPEN` state machine with
  context-prune-on-probe and configurable cooldown; per-tool breakers and
  per-run step ceiling.
- **Kill switch (FR-1.7).** Instant, runtime-toggleable per-run and
  per-tenant halt; hard-stops all guarded execution on the next step.
- **BudgetGuard (Phase 2, FR-2.1–2.8).** Four scopes (`run` / `agent` /
  `tenant` / `tool`) in both `usd` and `tokens`. Pessimistic reservation
  reconciled with exact `AgentResponse->usage`. Atomic Redis Lua reservations
  (`reserve.lua`) make concurrent workers race-proof (TOCTOU solved); a
  documented DB fallback driver is included.
- **Pricing catalog (FR-2.5).** Versioned `prices.json` with runtime/DB
  overrides; no prices hardcoded in release tags.
- **Model downshift (Phase 3, FR-3.1–3.4).** `DownshiftEngine` ladder with
  capability-cliff mitigation: on soft-cap breach, swaps the next
  `PendingStep` to a cheaper model via `withModel()` and tightens tools/steps.
- **`GuardAgentSteps` middleware (Phase 3, FR-5.1).** The `laravel/ai`
  `PendingStep` wedge: kill switch → breaker → reserve → downshift → run →
  reconcile + loop-detect. Fluent API: `forTenant()`, `budget()`,
  `downshiftAt()`, `breakLoops()`, `observe()`/`enforce()`.
- **`GuardedMcpTool` (Phase 4, FR-4.1–4.3).** A trait for
  `Laravel\Mcp\Server\Tool` sharing the same tenant budget, loop detector,
  and kill switch. Principal-based correlation (never `MCP-Session-Id`).
- **Operational controls (API §4).** `AgentGuard::kill()`, `unkill()`,
  `status()`, `budgets()`, `reconcile()`.
- **Artisan commands (API §7).** `agent-guard:status`, `agent-guard:kill`,
  `agent-guard:budgets`.
- **Testing fakes (Phase 5, FR-5.3, API §6).** `AgentGuard::fake()` swaps in
  an in-memory runtime (`AgentGuardFake` + `InMemoryLedger` +
  `RecordingDispatcher`) that records every decision with zero Redis/event
  side effects; full assertion surface.
- **Domain events (FR-5.2).** `LoopDetected`, `BudgetSoftCapReached`,
  `BudgetHardCapReached`, `ModelDownshifted`, `CircuitTripped`,
  `KillSwitchActivated`.
- **Documentation (Phase 5).** Failure-mode-first README with an honest
  comparison matrix and "which package" guide; `docs/comparisons.md`,
  `docs/composition.md` (Approvable / mcp-guard / umpire recipes),
  `docs/benchmarks.md`; Laravel Boost skill stub (`stubs/boost/agent-guard.md`).
- **Benchmarks (Phase 5, FR-5.6).** `benchmarks/loop-budget-bench.php` — the
  5-step loop reproducer (with/without guard: tokens saved, USD saved,
  graceful degraded response vs. 503); `benchmarks/loop-budget-microbench.php`
  — per-step hot-path throughput.

### Notes

- **Observe by default.** All detectors run and all events fire in `observe`
  mode; enforcement is a config flip. Adoption is zero-breakage.
- **Dependency policy.** Hard dependencies are limited to `laravel/framework`
  (via `illuminate/*`), `laravel/ai`, `laravel/mcp`, and `illuminate/redis`.
  Embeddings, the dashboard, and receipts are optional/adapter-based.
- **Out of scope (v1).** Authorization / allowlists / human approval,
  behavioral trust scoring + receipts (deferred to v2), PII/egress redaction,
  credential vaulting, sandboxing, tool-poisoning scanning, agent identity/DID.

### Acceptance

PRD §12 acceptance criteria satisfied: loop detectors + breaker + kill switch
in observe/enforce; Redis Lua budget reservation proven race-safe under a
10-worker concurrent test (zero overspend); soft-cap downshift demonstrated in
the benchmark reproducer; `GuardedMcpTool` wraps MCP tools with shared
tenant budget + loop detection; `AgentGuard::fake()` covers the test surface;
README, comparison matrix, Boost skill, and benchmarks published.
