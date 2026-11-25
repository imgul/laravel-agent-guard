# Benchmarks

Two benchmarks ship in `benchmarks/`:

1. **`loop-budget-bench.php`** — the **5-step loop reproducer** (PRD FR-5.6,
   the launch evidence asset). Runs a runaway 5-step agent loop with and
   without the guard and reports the tokens saved, USD saved, and the
   outcome (unbounded spiral vs. graceful degraded response).
2. **`loop-budget-microbench.php`** — a per-step **micro-benchmark** of the
   two hot paths (loop detection, budget reservation/reconciliation) for
   performance regression tracking.

## 1. The 5-step loop reproducer (launch evidence)

`benchmarks/loop-budget-bench.php` simulates the costliest failure mode: a
5-step agent loop that repeats the same tool call with growing context, run
twice — with and without the guard in enforce mode.

**What it shows:**

- **WITHOUT the guard** — all 5 steps run on the frontier model; the loop
  never brakes; the run ends in an unbounded spiral (provider 503 /
  hard-cap blowout).
- **WITH the guard** — the loop detector trips on the 3rd identical call,
  the circuit breaker opens (halting steps 4–5 before they leave the
  server), and the soft-cap downshift swaps to a cheaper model — a graceful
  degraded response.

The delta between the two runs — **tokens saved** and **USD saved** — is the
launch evidence. Costs are computed from the versioned pricing catalog
(never hardcoded), using the same `CostEstimator` the middleware reserves
with.

```bash
php benchmarks/loop-budget-bench.php
```

Sample output (illustrative — run on your own hardware):

```
laravel-agent-guard — 5-step loop reproducer
========================================================================
Scenario: a 5-step agent loop repeating the same tool call with
growing context. Run with vs without the guard (enforce mode).
------------------------------------------------------------------------
                          Steps        Tokens           USD
WITHOUT guard              5         11,000         0.26
WITH guard (enforce)       3          5,700         0.05
------------------------------------------------------------------------
Tokens saved               5,300
USD saved                  0.21
------------------------------------------------------------------------
Outcome without guard: Unbounded spiral — loop ran to completion (provider 503 / hard-cap blowout)
Outcome with guard:    Graceful degraded response — loop halted by breaker, model downshifted
Downshift fired:       claude-opus-4-1 -> claude-haiku-4-5 (soft-cap, 80% threshold)
Breaker tripped at step 3 — steps 4-5 halted before leaving the server.
========================================================================
```

> The exact figures depend on the pricing catalog and the simulated step
> sizes in the script; the relative shape — a bounded, downshifted,
> halted run versus an unbounded spiral — is the evidence.

## 2. The micro-benchmark (hot-path throughput)

`benchmarks/loop-budget-microbench.php` measures the per-step hot paths:

1. **loop-detect** — a `LoopDetector::check()` call (exact-repeat path with
   a SimHash strategy). Event dispatch is disabled so the measurement is the
   detector pipeline, not the bus.
2. **budget-reserve** — a `BudgetGuard::reserve()` call (the per-step
   pre-work ledger write).
3. **budget-reconcile** — a `BudgetGuard::reconcile()` call (the post-step
   write that releases the pessimistic excess).

Each measurement warms up for 1,000 iterations, then times the configured
number of runs, reporting ops/sec and microseconds-per-op.

```bash
php benchmarks/loop-budget-microbench.php                       # 100k iterations, in-memory ledger
php benchmarks/loop-budget-microbench.php --runs=200000         # custom iteration count
php benchmarks/loop-budget-microbench.php --driver=redis        # against a live Redis ledger
```

The budget paths default to the in-memory ledger so the script runs without
infrastructure. `--driver=redis` measures the atomic Lua reservation path
against a live connection; if Redis is unavailable it falls back to
in-memory and warns on stderr.

### Methodology

- **Isolation:** each `bench()` call isolates a single operation; no events,
  no I/O beyond the ledger write itself.
- **Warm-up:** 1,000 iterations are discarded before timing, so JIT
  compilation and cache effects settle.
- **Resolution:** timing uses `hrtime(true)` (nanoseconds), so
  microsecond-per-op figures are meaningful down to the low-µs range.
- **Reproducibility:** run on the same hardware and PHP build for
  cross-version comparisons; report PHP version and `php -v` alongside any
  published numbers.

### Representative results

These are **illustrative** figures for a mid-range laptop (Apple M-class,
PHP 8.3 CLI). Run the script on your own hardware — absolute numbers vary
with CPU, PHP build, and Redis latency; the *relative* shape is what matters.

```
laravel-agent-guard benchmark  (runs=100000, driver=memory)
----------------------------------------------------------------------
loop-detect (exact-repeat)    100000 ops      ~ 480,000 ops/sec      2.08 µs/op
budget-reserve (in-memory)    100000 ops      ~ 1,600,000 ops/sec    0.62 µs/op
budget-reconcile (in-memory)  100000 ops      ~ 1,400,000 ops/sec    0.71 µs/op
----------------------------------------------------------------------
```

**How to read this:**

- **Loop detection** is the most expensive hot path — a few microseconds per
  call — because it runs the detector pipeline (exact-repeat,
  normalized-repeat, frequency, context-growth) plus a SimHash over the
  call. This is the cost the breaker/kill-switch is worth: it bounds the
  spend of a runaway loop to *steps × µs*, not an open-ended spiral.
- **Budget reservation/reconciliation** are sub-microsecond against the
  in-memory ledger. Against Redis they are bounded by the Lua script's
  round-trip; the in-memory figures represent the *computation* cost, not
  the network cost.
- The in-memory numbers are an **upper bound on throughput** and a **lower
  bound on latency** for the Redis path. For production budgeting, measure
  `--driver=redis` on a host with realistic Redis latency.

### What we do not benchmark

- **Event dispatch** — disabled in the loop-detect measurement to isolate
  the pipeline; the bus adds a constant overhead proportional to the number
  of listeners.
- **Semantic (embedding) detection** — gated behind an elevated frequency
  signal (FR-1.5); it runs only on the rare spike path, so it is excluded
  from the steady-state loop-detect figure.
- **End-to-end agent latency** — this package adds a bounded per-step
  overhead; the full latency budget depends on the model provider and is out
  of scope for these micro-benchmarks.
