# AGENTS.md — laravel-agent-guard

> Rules for AI coding agents (Claude Code, Cursor, VS Code Copilot, Windsurf,
> Cline, Gemini CLI, Codex, OpenCode, and any Agent Skills-compatible editor)
> working on the **`imgul/laravel-agent-guard`** package.
>
> These rules are the source of truth for how code is written, reviewed, and
> merged in this repository. They consolidate guidance from the installed
> agent skills (PHP best practices, Laravel best practices, security audit,
> code review, performance engineering, web design guidelines, and
> dependency audit) and adapt it to this project's specifics.

**Focused rule files** live in [`rules/`](rules/) for loading per-topic on
demand:

| File | Topic |
|---|---|
| [`rules/php.md`](rules/php.md) | PHP 8.3 type system, PSR, SOLID, error handling |
| [`rules/laravel.md`](rules/laravel.md) | Laravel 12/13 package conventions |
| [`rules/code-quality.md`](rules/code-quality.md) | Review mindset, anti-patterns, severity labels |
| [`rules/security.md`](rules/security.md) | Security audit methodology, tenant isolation, atomicity |
| [`rules/performance.md`](rules/performance.md) | Profiling method, package-specific optimizations |
| [`rules/ui-ux.md`](rules/ui-ux.md) | UI/UX and accessibility (for any UI built on this) |
| [`rules/composer.md`](rules/composer.md) | Composer constraints, dependency audit |
| [`rules/testing.md`](rules/testing.md) | Pest structure, hard gates, coverage |

---

## 1. Project overview

**laravel-agent-guard** is a step-aware runtime-safety layer for AI agents
and MCP tool servers running on Laravel. It provides:

- **LoopGuard** — fingerprinting, similarity strategies, loop detectors,
  circuit breaker, and kill switch to stop runaway agent loops.
- **BudgetGuard** — atomic multi-tenant token/USD budgets via a Redis Lua
  ledger, with a database fallback, versioned pricing, and a
  soft-cap → hard-cap action ladder.

It is an **MIT-licensed open-source library**, not an application. It ships
framework-agnostic core logic with thin Laravel adapters.

### Hard constraints (never violate)

- **PHP 8.3+**, **Laravel 12 / 13**, `laravel/ai ^1.0`, `laravel/mcp ^1.0`.
- **MIT license from day one.** No copyleft dependencies.
- **Observe-by-default.** Safety enforcement must never be paywalled or
  gated behind a paid tier. The observe mode records and emits events but
  does not halt; enforce mode is opt-in.
- **Core logic framework-agnostic** with thin adapters. Business logic in
  `src/` must not depend on Laravel facades directly except inside the
  adapter layer (drivers, service provider).
- **Pricing lives in versioned `prices.json`** — never hardcode prices in
  release tags.
- **Budget keys are tenant-derived** — never agent-supplied, never
  `MCP-Session-Id` (laravel/mcp 1.0 is stateless).

### Out of scope (do not build)

Behavioral trust scoring + hash-chained receipts (v2); per-tool
authorization/allowlists/human approval (use laravel/ai Approvable,
laravel-umpire, laravel-mcp-guard); PII/egress redaction; credential
vaulting; sandboxing; tool-poisoning scanning; agent identity/DID.

---

## 2. Tech stack & conventions

| Concern | Choice |
|---|---|
| Language | PHP 8.3+ |
| Framework | Laravel 12/13 (illuminate contracts only) |
| AI SDK | `laravel/ai ^1.0` |
| MCP SDK | `laravel/mcp ^1.0` |
| Cache/queue backend | Redis (atomic Lua ledger) |
| Static analysis | PHPStan **level max** (`phpstan.neon`) |
| Code style | Pint (Laravel preset, `pint.json`) |
| Tests | Pest 3/4 + Orchestra Testbench |
| CI | GitHub Actions: Pest (Laravel 12+13), PHPStan max, Pint, nightly on `laravel/ai 1.x-dev` + `laravel/mcp 1.x-dev` |

### Directory layout

```
src/
  AgentGuard.php            # Core container / facade target
  AgentGuardServiceProvider.php
  Facades/AgentGuard.php
  Support/                  # Framework-agnostic core: Fingerprint, TextUsage, Pricing, CostEstimator
  Loop/                     # LoopGuard: detectors, breaker, kill switch
  Budget/                   # BudgetGuard: Ledger, drivers, Lua/
  Downshift/                # DownshiftEngine + DownshiftTier (capability-cliff ladder)
  Middleware/               # GuardAgentSteps (PendingStep wedge) + GuardRuntime
  Mcp/                      # GuardedMcpTool trait (MCP tool guard, Phase 4)
  Events/                   # Domain events (immutable value objects)
  Exceptions/               # Domain exceptions
config/agent-guard.php      # Reference config
database/migrations/        # Fallback driver migration only
tests/
  Unit/                     # Pure logic, no framework boot
  Feature/                  # Package wiring + integration (Redis, service provider)
```

---

## 3. PHP rules (from `php-best-practices`)

### Type system (CRITICAL)

- **`declare(strict_types=1);` in every file** — always first statement after
  `<?php`.
- **Always declare return types.** Every method, function, and closure.
- **Type all parameters.** No untyped arguments.
- **Type all properties.** Use `readonly` for immutable data (8.1+).
- **Prefer union and intersection types** over `mixed`. Avoid `mixed` entirely.
- **Use `void` / `never`** where appropriate; never return `mixed` implicitly.

### Modern PHP (8.3+ — use what the version gives you)

- **Constructor property promotion** for dependencies and immutable data.
- **`match` expressions** over `switch`.
- **Enums** (backed `string` enums) instead of class constants for fixed sets
  — see `BudgetScope`, `LoopState`.
- **`readonly` properties and `readonly` classes** for value objects and
  immutable event payloads (`BudgetResult`, `BudgetSoftCapReached`, etc.).
- **Typed class constants** (`public const string NAME = '...'`) where a
  constant has a known type.
- **`#[\Override]`** on methods that override a parent/interface method to
  catch typos at static-analysis time.
- **First-class callable syntax** (`strlen(...)`) over closures wrapping
  built-ins.
- **Nullsafe operator** (`?->`) for nullable chains.
- **Never use the `@` error-suppression operator.**

### PSR standards (HIGH)

- **PSR-4 autoloading** — one class per file, class name matches file name,
  namespace matches directory structure.
- **PSR-12 coding style** — enforced by Pint; never fight the formatter.
- **PSR naming** — `StudlyCaps` classes, `camelCase` methods/properties,
  `snake_case` for DB columns.
- **One class per file.** Exceptions and events each get their own file.

### SOLID (HIGH)

- **Single Responsibility** — one reason to change per class. `BudgetGuard`
  orchestrates; `Ledger` implementations persist; detectors detect.
- **Open/Closed** — extend behavior via new detector/driver classes, not by
  editing existing ones. Use the `DetectorInterface` and `Ledger` contracts.
- **Dependency Inversion** — depend on abstractions (`Ledger`,
  `DetectorInterface`, `EmbeddingAdapterInterface`), not concretes. Inject
  through constructors.
- **Interface Segregation** — keep contracts small and focused.

### Error handling (HIGH)

- **Specific exceptions** for each error class: `LoopDetectedException`,
  `KillSwitchException`, `BudgetExceededException`. Do not throw generic
  `\Exception` or `\RuntimeException` for domain errors.
- **Catch specific exceptions**, never bare `catch (\Exception)`.
- **Use `finally`** for guaranteed cleanup (resource release).
- **Fail fast** on invalid input rather than silently coercing.

---

## 4. Laravel rules (from `laravel-best-practices`)

This is a **package**, not an app. Adapt Laravel conventions accordingly:

- **Service provider** registers all bindings. Bind abstractions, not
  concretes, where the caller should choose. Register the `Ledger`
  singleton keyed off `config('agent-guard.driver')`.
- **Config is published and merged** — `mergeConfigFrom` in the provider,
  reference config in `config/agent-guard.php`. Never read hardcoded
  values; always go through the config repository.
- **Facade** exposes the public surface (`AgentGuard::kill/unkill/status/
  budgets/reconcile/fake()`). Facade only wraps `AgentGuard`; core logic
  stays in the class.
- **Migrations** use anonymous class `return new class extends Migration`.
  Only the fallback driver needs a table; Redis needs none.
- **Eloquent is not used** for the core ledger (Redis Lua is the source of
  truth). The `DatabaseLedger` is the documented weaker-concurrency
  fallback.
- **Events** are plain immutable value objects (not Laravel event objects
  unless they need broadcasting) — they carry scope, key, spend, cap. Never
  put raw usage payloads in event payloads.
- **No business logic in controllers** (N/A here — no controllers). Keep
  orchestration in `AgentGuard` / `BudgetGuard` / `LoopDetector`.

---

## 5. Code quality rules (from `code-review-skill`)

### Review mindset

- **Review behavior, not formatting.** Let Pint handle style; do not nitpick
  formatting in review.
- **Catch bugs and edge cases** first: off-by-one, null checks, race
  conditions, integer overflow on token counts, float precision on USD.
- **Reuse before new code** — search for existing utilities/helpers before
  writing a new one (e.g. `TextUsage::add()` vs manual summation).
- **Single responsibility** — a class with multiple responsibilities is a
  finding.

### Anti-patterns to reject

- **TOCTOU** — the entire BudgetGuard exists to prevent check-then-act races.
  Any code that reads-then-writes a budget without an atomic operation is a
  blocking finding.
- **Parameter sprawl** — too many parameters signals a missing value object.
  Wrap in `BudgetReservation` / `BudgetResult` / `DetectorContext`.
- **Leaky abstractions** — `Ledger` implementations must not leak Redis- or
  DB-specific types through the contract.
- **Nested conditionals** — prefer early returns / guard clauses.
- **Stringly-typed code** — use enums (`BudgetScope`) and typed constants,
  not magic strings.
- **No-op updates** — avoid writes that change nothing.
- **Redundant state** — derive values instead of storing duplicates.

### Severity labels (use in review output)

- 🔴 `[blocking]` — must fix before merge (correctness, security, TOCTOU).
- 🟡 `[important]` — should fix, discuss if disagree.
- 🟢 `[nit]` — nice to have, not blocking.
- 💡 `[suggestion]` — alternative approach.
- 📚 `[learning]` — educational, no action.

### Review output format

```
file:line - [category] Description of issue
```

Example:
```
src/Budget/BudgetGuard.php:142 - [solid] Hard-cap check reads cap after reservation, risks TOCTOU
```

---

## 6. Security rules (from `security-audit` + `php-best-practices`)

### Source-first, boundary-aware

- **A finding must cross a real trust boundary** to count. A missing best
  practice without an affected principal/resource is not a finding.
- **Never assume deployment behavior** (proxy, headers, identity policy) that
  is not in the source. If a control is required and absent, mark it
  `needs_validation` with the exact missing fact.
- **Separate priority from certainty.** Only `confirmed` findings get
  severity. `needs_validation` means a hypothesis is blocked, not a
  low-confidence vulnerability.

### Severity anchors

- **critical** — unauthenticated code execution, full data-store access,
  arbitrary account takeover.
- **high** — defeats an explicit control with real consequences (e.g.
  cross-tenant budget read/write, auth bypass).
- **medium** — real boundary violation, limited blast radius.
- **low** — disclosure of non-secret internals.
- **informational** — confirmed but minimal-impact observation.

### Package-specific security rules

- **Tenant isolation is paramount.** Budget keys derive from
  tenant/principal context, never from agent-supplied or request-supplied
  values. A budget key that an agent can influence is a **blocking**
  cross-tenant finding.
- **Atomicity is a security property.** The Redis Lua reservation is the
  TOCTOU defense. Any path that checks a budget then spends without the
  atomic `EVAL` is a **blocking** finding. The concurrency test
  (`RedisLedgerConcurrencyTest`) is the regression gate — it must stay green.
- **Never log or emit secrets.** Events carry scope/key/spend/cap, never API
  keys, tokens, or raw agent payloads.
- **Input validation on all external entry points.** `reserve()` and
  `reconcile()` validate cost is a finite non-negative number; caps are
  finite. Reject NaN, infinity, and negative costs.
- **SQL safety in `DatabaseLedger`** — always use the query builder /
  parameterized expressions (`DB::raw('spent - '.(float)$excess)` is safe
  because the float is cast and bound; never concatenate raw user input into
  SQL).
- **Lua safety** — `reserve.lua` reads only `KEYS[1]` and writes only that
  key. It must never `loadstring`, `call` external commands, or touch other
  keys. The script is embedded from the canonical file; never inline a
  divergent copy.
- **Dependency security** — run `composer audit` in CI. Flag Critical/High
  CVEs as blocking. See §9.

### Operating the security skill

- **Guidance mode** (default): for focused security questions, use the
  relevant parts. Do not auto-run a full audit or create artifacts unless
  explicitly requested.
- **Full audit mode**: only when explicitly asked to audit/pen-test the
  codebase or produce a report. Run the full six-phase workflow with
  sandboxed, read-only source inspection. Never execute target code outside
  an OS-enforced sandbox with no network.

---

## 7. Performance & speed rules (from `performance-engineer`)

### Measure, don't guess

- **Profile before optimizing.** Identify the bottleneck with evidence, fix
  the one that matters, measure again. Never optimize blind.
- **Define baseline and target** from the service SLO, not from a generic
  table. The performance skill's targets are a starting point, not acceptance
  criteria.

### Package-specific performance rules

- **Atomicity over speed, but never waste a round-trip.** The Redis Lua
  reservation is one round-trip (check + spend + soft-flag in one `EVAL`).
  Never split it into GET-then-INCRBY — that reintroduces the TOCTOU race
  and adds a round-trip.
- **Avoid N+1-style loops over the ledger.** Budget reconciliation is
  per-step, not per-token. Aggregate `TextUsage` before reconciling, not
  token-by-token.
- **Lazy-load similarity strategies.** `EmbeddingSimilarity` (vector-based)
  is expensive; the default `SimHash`/`TrigramJaccard` strategies are
  lightweight. Only instantiate embedding strategies when configured.
- **Fingerprint computation** uses native PHP string functions, not regex
  where a native function exists. `mb_*` is used correctly (polyfilled in
  test harness).
- **Generators for large datasets.** If a detector ever processes a large
  step history, use generators to avoid materializing all fingerprints in
  memory.
- **No global state / static mutable caches** except the read-only
  `reserve.lua` script cache in `RedisLedger` (which is idempotent and safe).
- **Index the budget key.** The Redis key schema
  (`agent-guard:budget:{scope}:{tenant}:{agent}:{period}`) must stay stable
  and colon-delimited for `SCAN`-ability; never embed unescaped user input
  in the key.

### Performance review checklist

- N+1 queries? (N/A for Redis, but check DB fallback queries)
- Missing indexes? (migration index coverage)
- Over-fetching? (return only needed fields)
- Unnecessary allocations? (reuse value objects, avoid per-step re-creation)
- Serial requests that could be batched?

---

## 8. UI/UX rules (from `web-design-guidelines` + `frontend-design`)

This package is a **backend library** with no shipped UI of its own. These
rules apply to any documentation site, admin panel, status dashboard, or
example app built on top of it, and to the README/docs.

### If UI is ever built

- **Accessibility first (WCAG 2.1 AA).** Semantic HTML, keyboard navigation,
  ARIA where needed, sufficient color contrast.
- **Responsive layouts.** Mobile-first; never assume desktop width.
- **Performance-conscious UI.** Lazy-load routes/components, memoize
  expensive computations, optimize images (WebP, lazy-load, responsive).
- **One or two typefaces, deliberately chosen.** Follow a clear type scale;
  avoid generic AI-default font stacks.
- **Color via CSS variables** for theming (light/dark). Don't hardcode
  colors in components.
- **Motion with purpose.** Animations should guide, not distract. Respect
  `prefers-reduced-motion`.
- **Distinctive, intentional design** — reject generic AI aesthetics.
  Ground the design in the subject matter.

### Documentation as UX

- **README and doc examples must be copy-pasteable and correct.** A broken
  example is a UX bug.
- **Status output should be terse and scannable** — `file:line` style for
  findings, table format for budgets/status.
- **Error messages are UX.** Domain exceptions should carry clear,
  actionable messages with scope/key/spend/cap context.

---

## 9. Composer & dependency rules (from `dependency-audit`)

### Version constraints

- **Use caret constraints (`^`) for libraries**, never `dev-*`, `>=`, or
  `*`. The nightly CI pins `laravel/ai 1.x-dev` + `laravel/mcp 1.x-dev`
  explicitly for testing — that is the only place dev versions appear.
- **PHP version floor is `^8.3`** — never suggest syntax from 8.4/8.5
  features (property hooks, asymmetric visibility, pipe operator) in
  library code unless the floor is raised.
- **Stable, sorted packages.** `config.sort-packages: true` is set. Keep
  `composer.json` dependencies sorted.

### Audit & supply chain

- **Run `composer audit` in CI.** Critical/High CVEs are **blocking** for
  merge.
- **No restrictive licenses.** This is MIT. Flag GPL/AGPL/SSPL dependencies
  as blocking. All deps must be permissive.
- **Lock-file integrity.** If a `composer.lock` is ever committed, verify
  its integrity; prefer not to commit a lock for a library.
- **Abandoned/deprecated deps** — flag packages with no release in 12+
  months or marked deprecated; propose alternatives.
- **Transitive vulnerabilities** — resolve via the direct parent update, not
  by ignoring. Document unfixable transitive risks with a mitigation plan.
- **Typosquatting** — only depend on well-known, verified vendors
  (`laravel/*`, `illuminate/*`, `pestphp/*`, `orchestra/*`, `phpstan/*`).

### Dependency health cadence

- Security scan: per commit (CI `composer audit`).
- Full audit: monthly for active projects.
- Recommend Dependabot/Renovate for automated PRs.

---

## 10. Testing rules (from `pest-testing` principles)

- **Test behavior, not implementation.** Assert outcomes and side effects,
  not private method calls or formatting.
- **Unit tests (`tests/Unit/`)** for pure logic — no framework boot. Fingerprint,
  similarity, detectors, budget guard with a fake ledger, pricing catalog.
- **Feature tests (`tests/Feature/`)** for framework integration — service
  provider wiring, facade, Redis concurrency.
- **Hard gates must have regression tests.** The concurrency test
  (10 workers vs $5 cap, zero overspend) is a hard gate — it must never be
  removed or skipped in CI.
- **Mock external services.** Redis is real in the concurrency test (it's
  the point); otherwise use fakes/stubs. Never hit real external APIs.
- **Cover happy path, failure path, and edge cases.** Null/empty/zero/negative
  inputs, boundary values, off-by-one.
- **Pest style:** descriptive `it()`/`test()` names in plain language. No
  `final`/`readonly` on test classes that need mocking.
- **Quality over quantity** — 5 meaningful tests beat 20 trivial ones.

---

## 11. Package-specific rules (agent-guard domain)

### LoopGuard

- **Detectors are composable and stateless** — each `DetectorInterface`
  implementation reads a `DetectorContext` and returns `LoopFinding`s. No
  detector mutates shared state across steps.
- **The circuit breaker and kill switch are the hard stops.** Kill switch is
  tenant-scoped and global to that tenant. Once tripped, no step proceeds
  until un-killed.
- **Similarity strategies must be pluggable.** The default `SimHash` is
  framework-agnostic; `EmbeddingSimilarity` adapts via
  `EmbeddingAdapterInterface`. Never hardcode an embedding SDK in core.

### BudgetGuard

- **Reserve → call → reconcile is the lifecycle.** Reserve pessimistically
  (atomic), reconcile with exact `TextUsage` after the step. Never skip
  reconciliation (it releases reserved-but-unspent cost).
- **Soft cap → downshift, hard cap → halt.** Soft-cap is a signal
  (`BudgetSoftCapReached`), not a denial. Hard-cap is a denial
  (`BudgetExceededException` in enforce mode; event in observe mode).
- **Action ladder is configurable per scope.** Observe/downshift/halt stages
  are independent; the config decides which fires.
- **Pricing is data.** `prices.json` is versioned and synced by CI. Never
  inline a price in PHP. A missing price → USD null, but token budgets still
  enforce.

### Downshift & GuardAgentSteps (Phase 3)

- **`GuardAgentSteps` is the step-level wedge.** It implements the
  `laravel/ai` `HasMiddleware` pipe (`handle(PendingStep, Closure): StepResult`)
  and wraps every generation step with the kill-switch → breaker → reserve →
  downshift → run → reconcile/detect pipeline. Never bypass it for guarded
  agents.
- **The downshift ladder is capability-cliff mitigation.** `DownshiftEngine`
  picks the deepest tier whose spend-fraction threshold is crossed; each tier
  swaps the model (`withModel`), drops tools (`withoutTools`), and tightens
  the token ceiling (`withMaxTokens`) so a degraded-model retry cannot spiral.
  `PendingStep` has **no `withMaxSteps()`** — step ceiling lives in
  `TextGenerationOptions::$maxSteps`, not on the step.
- **Default is observe; enforce activates the ladder.** `observe()` runs
  detection + events only (no downshift, no breaker trip, no kill). `enforce()`
  activates downshift, loop braking, and hard-cap halts. The middleware's
  own enforce/observe flag governs the `BudgetGuard`, overriding the runtime
  default.
- **Reservation estimates come from the step, not magic numbers.** Input
  tokens are estimated from the step's message content (`CostEstimator`);
  USD is derived from the versioned `PricingCatalog`. Reconciliation replaces
  the estimate with the exact `StepResponse->usage` via
  `TextUsage::fromArray($usage->toArray())`.
- **USD and token ledgers are separate keys.** The `BudgetGuard` prepends the
  unit (`usd`/`tokens`) to the ledger key parts so the two never collide.
- **Kill switch and breaker are shared global state.** `GuardRuntime` owns
  one `KillSwitch` and one `CircuitBreaker` shared across all middleware
  instances, so killing a tenant halts every guarded agent for that tenant.
  The loop detector is per-run (sliding window is run-scoped state).

### GuardedMcpTool (Phase 4)

- **`GuardedMcpTool` is the MCP tool guard.** A trait for
  `Laravel\Mcp\Server\Tool` that wraps tool invocations with the same three
  controls (budget, loop detector, kill switch) as agent steps. The SDK's
  `ToolInvoker` calls `[$tool, 'handle']` via the container, so the trait
  **owns `handle()`** (the guarded entry point) and delegates the actual
  work to `run()`, which the consuming tool overrides.
- **One policy governs both surfaces.** `GuardedMcpTool` resolves the same
  `GuardRuntime` singleton (kill switch, breaker, ledger, estimator) as
  `GuardAgentSteps`. Opening the kill switch for a tenant halts both agent
  steps and MCP tool calls — no separate control plane.
- **Correlation is principal-based, never session-based.** The scope key is
  `tool:{toolName}:{principal}`, where the principal is the authenticated
  user id, an OAuth client id, or a tenant id. **Never** use the
  `MCP-Session-Id` header for correlation — stateless MCP is safe because
  the loop detector's sliding window is memoized per tool+principal on the
  shared `GuardRuntime`, so repeat calls across independent JSON-RPC
  requests still trip the breaker.
- **`guardBudget()` return shape.** `['tool_daily_calls' => int,
  'max_repeats' => int, 'tool_usd' => [soft, hard], 'tool_tokens' =>
  [soft, hard]]`. `tool_daily_calls` is a call-count budget on the `Tool`
  scope (`calls` unit, 1.0 per invocation) reserved directly against the
  `Ledger`. `max_repeats` overrides the loop detector's repeat threshold
  for this tool. Optional `tool_usd`/`tool_tokens` caps use `BudgetGuard`
  with an estimate derived from the argument payload.
- **Default is observe; `guardEnforce()` activates the ladder.** Mirrors
  `GuardAgentSteps`: observe runs detection + events only; enforce trips the
  breaker on loop findings and denies calls that breach hard caps.
- **Hard-halts return `Response::error()`, not exceptions.** The kill switch,
  breaker, and budget-denial paths return an MCP error response (the SDK's
  `Errable` contract), not a thrown exception.

### AgentGuard facade

- **The facade is the stable public API.** Changing `AgentGuard`'s public
  methods is a breaking change — document it.
- **`fake()` for testing** — provides a no-op guard so tests don't touch real
  Redis/budgets.

---

## 12. Git & PR workflow

- **Work on the current session branch.** Never create/switch/push to other
  branches. Commit to the current branch; open PRs from it.
- **One phase per commit** where the roadmap defines phases. Each phase ends
  green (lint + tests + hard gates) before the next begins.
- **Commit messages** are imperative, descriptive: `Phase N: <summary>`.
- **PRs** are squashed/merged via `gh pr merge`. The PR description must
  summarize what changed, the verification run, and any hard gates exercised.
- **Never bypass CI.** Local verification (php-wasm lint, lupa concurrency
  proof) supplements CI but does not replace it. CI (Pest on L12+L13,
  PHPStan max, Pint, nightly) is the real gate.

---

## 13. Installed agent skills

These skills are installed from skills.sh (Vercel) and live under
`.agents/skills/` (also mirrored to `.claude/skills/` and `agent/skills/`).
Load them on demand; their `SKILL.md` is the authoritative procedural detail.

| Skill | Repo | Domain | When to use |
|---|---|---|---|
| `find-skills` | vercel-labs/skills | Discovery | Finding/installing new skills |
| `security-audit` | cloudflare/security-audit-skill | Security | Security reviews, audits |
| `php-best-practices` | asyrafhussin/agent-skills | PHP | PHP code quality, type safety |
| `laravel-best-practices` | asyrafhussin/agent-skills | Laravel | Laravel conventions |
| `code-review-skill` | awesome-skills/code-review-skill | Review | PR/code reviews |
| `performance-engineer` | zhaono1/agent-playbook | Performance | Optimization, profiling |
| `web-design-guidelines` | vercel-labs/agent-skills | UI/UX | UI reviews, accessibility |
| `frontend-design` | anthropics/skills | UI/UX | Visual design |
| `dependency-audit` | SteveFeldman/agents-skills | Composer | Dependency vulnerability scans |

**Security note:** skills run with full agent permissions. Review any skill
before relying on it. The skills above passed skills.sh security audits
(Gen/Socket/Snyk) at install time, but re-verify against the latest audit
status.

---

## 14. Quick-reference checklist (before committing)

- [ ] `declare(strict_types=1);` present in every new PHP file
- [ ] All methods/properties/parameters typed; no `mixed`
- [ ] PSR-4: one class per file, namespace matches path
- [ ] SOLID: single responsibility, depend on abstractions
- [ ] Specific domain exceptions, no bare `\Exception`
- [ ] Security: tenant keys tenant-derived; budget checks atomic; no secrets
  in events/logs
- [ ] Performance: no TOCTOU; no per-token ledger writes; lazy similarity
- [ ] Tests: behavior-focused, hard-gate regression tests intact
- [ ] Pint clean (`composer lint`), PHPStan max clean (`composer stan`)
- [ ] Pest green (`composer test`)
- [ ] Dependencies: no new restrictive-license or vulnerable packages
- [ ] Commit message follows the phase convention
