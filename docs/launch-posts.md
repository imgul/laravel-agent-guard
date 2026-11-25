# Launch Posts

Ready-to-post launch announcements for each platform, each with its tailored image.

---

## 1. Twitter / X

**Image:** `docs/assets/launch-twitter.png`

**Post:**

> 🚨 Just shipped **laravel-agent-guard v1.0.0** — a runtime-safety layer for Laravel AI agents and MCP tool servers.
>
> 🔧 Step-aware loop braking — 4 detectors, circuit breaker, kill switch
> 💰 Atomic multi-tenant budgets — Redis Lua reservations, race-proof
> 📉 Graceful model downshift — cheaper model on soft-cap breach, no exceptions
>
> Ships in observe mode. Zero-breakage adoption. One config flip to enforce.
>
> 🔗 imgul/laravel-agent-guard
> 📦 packagist.org/packages/imgul/laravel-agent-guard
>
> #Laravel #PHP #AI #MCP

---

## 2. LinkedIn

**Image:** `docs/assets/launch-linkedin.png`

**Post:**

> Today I'm excited to release **laravel-agent-guard v1.0.0** — a runtime-safety layer for Laravel AI agents and MCP tool servers.
>
> The problem it solves: runaway agent loops are the costliest failure mode in production AI systems. A multi-step retry spiral can burn tens of thousands of dollars while health checks report healthy. Existing guardrails fail hard at the request boundary — none operate per step, and none downshift the model mid-run.
>
> **laravel-agent-guard** does three things:
>
> **Step-aware loop braking** — four detectors (exact-repeat, normalized-argument, frequency, context-growth) run in a sliding window, backed by a circuit breaker and an instant kill switch.
>
> **Atomic multi-tenant budgets** — per-run, per-agent, per-tenant, and per-tool caps enforced via Redis Lua reservations. Concurrent workers can never collectively race past a hard cap.
>
> **Graceful model downshift** — on a soft-cap breach, the next step swaps to a cheaper model instead of throwing an exception at a paying customer.
>
> It ships in observe mode by default — all detectors run, all events fire, nothing is enforced until you flip one config value. Adoption is zero-breakage.
>
> Fully open-source under MIT. Safety enforcement is never paywalled.
>
> #Laravel #AI #WebDevelopment #OpenSource

---

## 3. Reddit (r/laravel)

**Image:** `docs/assets/launch-reddit.png`

**Post:**

> **Title:** Released laravel-agent-guard v1.0.0 — runtime safety for Laravel AI agents (loop braking, atomic budgets, model downshift)
>
> **Body:**
>
> Hey Laravel community,
>
> I've been working on **laravel-agent-guard**, a runtime-safety layer that runs inside `laravel/ai`'s per-step `PendingStep` pipeline and wraps `laravel/mcp` tool execution. v1.0.0 is out today on Packagist.
>
> **The failure mode it stops:** runaway agent loops. A multi-step retry spiral can burn serious money while health checks look fine. Existing packages either fail hard at the request boundary (500s/503s) or estimate tokens at the wire — none operate per step, and none downshift the model mid-run.
>
> **What it does:**
>
> - **Loop braking** — 4 detectors (exact-repeat, normalized-argument, frequency, context-growth) in a sliding window, circuit breaker, kill switch
> - **Atomic budgets** — per-run/agent/tenant/tool caps in USD and tokens, enforced via Redis Lua reservations (TOCTOU solved, race-proof under concurrent workers)
> - **Model downshift** — on soft-cap breach, swaps the next step to a cheaper model instead of throwing
>
> **Key design choice:** ships in **observe mode** by default. Every detector runs, every event fires, nothing is enforced. Flip `AGENT_GUARD_MODE=enforce` to activate. Zero-breakage adoption.
>
> **QA:** PHPStan at level max — 0 errors. Pest — 87 tests passing. Pint — clean.
>
> MIT licensed. Fully open-source.
>
> Would love feedback from anyone running AI agents in production. What's your current approach to loop/budget safety?

---

## 4. Dev.to / Hashnode

**Image:** `docs/assets/launch-devto.png`

**Post:**

> **Title:** laravel-agent-guard v1.0.0 — Runtime Safety for Laravel AI Agents
>
> **Body:**
>
> Today I'm releasing **laravel-agent-guard v1.0.0** — a runtime-safety layer that plugs into `laravel/ai`'s per-step pipeline and wraps `laravel/mcp` tool execution. It's the package I wanted when a runaway agent loop burned through a production budget and health checks kept reporting healthy.
>
> ## The problem
>
> A runaway agent loop is the costliest failure mode in production AI systems. A multi-step retry spiral can burn tens of thousands of dollars over a few days while health checks report healthy. Existing guardrails fail hard at the request boundary — 500s, 503s, exceptions — or estimate tokens at the HTTP wire. None operate per step, and none downshift the model mid-run.
>
> ## What laravel-agent-guard does
>
> It runs **inside** the agent's per-step pipeline and brakes at every step, degrading gracefully when spend or loops run away. Three capabilities, each independently configurable:
>
> **Step-aware loop & context-growth braking** — Four detectors in a sliding window: exact-repeat, normalized-argument, frequency, and context-growth (step-over-step input-token growth with no progress). A circuit breaker moves through `CLOSED → OPEN → HALF_OPEN` with configurable cooldown, and a kill switch halts execution instantly.
>
> **Atomic multi-tenant budgets** — Four scopes (run, agent, tenant, tool) in both USD and tokens. Budget reservations are atomic via Redis Lua scripts, so concurrent Horizon workers can never collectively race past a hard cap. The TOCTOU race is solved at the storage layer.
>
> **Graceful model downshift** — On a soft-cap breach, the next step is swapped to a cheaper model via `PendingStep::withModel()`, with optional tool and step-count tightening. The agent keeps producing a degraded-but-usable response instead of throwing an exception at a paying customer.
>
> ## Observe by default
>
> The package ships in **observe mode**: every detector runs, every event fires, nothing is enforced. Flip `AGENT_GUARD_MODE=enforce` to activate the action ladder. Adoption is zero-breakage.
>
> ## Quick start
>
> ```bash
> composer require imgul/laravel-agent-guard
> php artisan vendor:publish --tag=agent-guard-config
> ```
>
> ```php
> use Imgul\AgentGuard\Middleware\GuardAgentSteps;
>
> public function middleware(): array
> {
>     return [
>         GuardAgentSteps::make()
>             ->forTenant(fn () => tenant()->id)
>             ->budget([
>                 'run'    => [0.25, 0.50],
>                 'tenant' => [15.00, 20.00, 'usd'],
>             ])
>             ->downshiftAt(0.80, toModel: 'claude-haiku-4-5')
>             ->breakLoops(maxRepeats: 3, maxStepGrowthRatio: 2.5)
>             ->enforce(),
>     ];
> }
> ```
>
> ## QA
>
> - PHPStan at level max: **0 errors**
> - Pest: **87 tests passing**
> - Pint: **clean**
>
> MIT licensed, fully open-source. Safety enforcement is never paywalled.
>
> #Laravel #PHP #AI #OpenSource

---

## Image Reference

| Platform | Image | File |
|---|---|---|
| Twitter / X | ![Twitter](docs/assets/launch-twitter.png) | `docs/assets/launch-twitter.png` |
| LinkedIn | ![LinkedIn](docs/assets/launch-linkedin.png) | `docs/assets/launch-linkedin.png` |
| Reddit | ![Reddit](docs/assets/launch-reddit.png) | `docs/assets/launch-reddit.png` |
| Dev.to / Hashnode | ![Dev.to](docs/assets/launch-devto.png) | `docs/assets/launch-devto.png` |
