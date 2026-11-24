# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
