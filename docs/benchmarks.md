# Benchmarks

Micro-benchmarks for the two hot paths of `laravel-agent-guard`: loop
detection and budget reservation/reconciliation.

## The script

`benchmarks/loop-budget-bench.php` measures three operations:

1. **loop-detect** — a `LoopDetector::check()` call with the exact-repeat
   path and a SimHash similarity strategy (the common trip). Event
   dispatch is disabled so the measurement is the detector pipeline, not
   the bus.
2. **budget-reserve** — a `BudgetGuard::reserve()` call against the
   ledger (the per-step pre-work write).
3. **budget-reconcile** — a `BudgetGuard::reconcile()` call (the
   post-step write that releases the pessimistic excess).

Each measurement warms up for 1,000 iterations, then times the
configured number of runs, reporting ops/sec and microseconds-per-op.

## Running

```bash
php benchmarks/loop-budget-bench.php                      # 100k iterations, in-memory ledger
php benchmarks/loop-budget-bench.php --runs=200000        # custom iteration count
php benchmarks/loop-budget-bench.php --driver=redis       # against a live Redis ledger
```

The budget paths default to the in-memory ledger so the script runs
without infrastructure. `--driver=redis` measures the atomic Lua
reservation path against a live connection; if Redis is unavailable it
falls back to in-memory and warns on stderr.

## Methodology

- **Isolation:** each `bench()` call isolates a single operation; no
  events, no I/O beyond the ledger write itself.
- **Warm-up:** 1,000 iterations are discarded before timing, so JIT
  compilation and cache effects settle.
- **Resolution:** timing uses `hrtime(true)` (nanoseconds), so
  microsecond-per-op figures are meaningful down to the low-µs range.
- **Reproducibility:** run on the same hardware and PHP build for
  cross-version comparisons; report PHP version and `php -v` alongside
  any published numbers.

## Representative results

These are **illustrative** figures for a mid-range laptop (Apple M-class,
PHP 8.3 CLI). Run the script on your own hardware — absolute numbers
vary with CPU, PHP build, and Redis latency; the *relative* shape is what
matters.

```
laravel-agent-guard benchmark  (runs=100000, driver=memory)
----------------------------------------------------------------------
loop-detect (exact-repeat)    100000 ops      ~ 480,000 ops/sec      2.08 µs/op
budget-reserve (in-memory)    100000 ops      ~ 1,600,000 ops/sec    0.62 µs/op
budget-reconcile (in-memory)  100000 ops      ~ 1,400,000 ops/sec    0.71 µs/op
----------------------------------------------------------------------
```

**How to read this:**

- **Loop detection** is the most expensive hot path — a few microseconds
  per call — because it runs the detector pipeline (exact-repeat,
  normalized-repeat, frequency, context-growth) plus a SimHash over the
  call. This is the cost the breaker/kill-switch is worth: it bounds the
  spend of a runaway loop to *steps × µs*, not an open-ended spiral.
- **Budget reservation/reconciliation** are sub-microsecond against the
  in-memory ledger. Against Redis they are bounded by the Lua script's
  round-trip; the in-memory figures represent the *computation* cost,
  not the network cost.
- The in-memory numbers are an **upper bound on throughput** and a
  **lower bound on latency** for the Redis path. For production
  budgeting, measure `--driver=redis` on a host with realistic Redis
  latency.

## What we do not benchmark

- **Event dispatch** — disabled in the loop-detect measurement to isolate
  the pipeline; the bus adds a constant overhead proportional to the
  number of listeners.
- **Semantic (embedding) detection** — gated behind an elevated frequency
  signal (FR-1.5); it runs only on the rare spike path, so it is excluded
  from the steady-state loop-detect figure.
- **End-to-end agent latency** — this package adds a bounded per-step
  overhead; the full latency budget depends on the model provider and is
  out of scope for these micro-benchmarks.
