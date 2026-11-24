# Performance & Speed Rules — laravel-agent-guard

> Adapted from the `performance-engineer` skill. Performance work is a
> measurement discipline: **profile → find the bottleneck → fix it → measure
> again**. Never optimize blind.

## Method

1. **Define metrics** — baseline, target, acceptable. Derive from the SLO,
   not a generic table.
2. **Measure** current performance with a profiler or timing harness.
3. **Find the one bottleneck** — fix it, then re-measure.
4. **Verify** — compare to baseline, ensure no regressions, document.

## Package-specific performance rules

### Atomicity over speed — but never waste a round-trip

- The Redis Lua reservation is **one round-trip** (check + spend + soft-flag
  in one `EVAL`). Never split into GET-then-INCRBY — that reintroduces the
  TOCTOU race **and** adds a round-trip.

### Avoid N+1-style ledger writes

- Budget reconciliation is **per-step**, not per-token. Aggregate
  `TextUsage` before reconciling; never reconcile token-by-token.

### Lazy-load similarity strategies

- `EmbeddingSimilarity` (vector-based) is expensive. The default `SimHash` /
  `TrigramJaccard` strategies are lightweight. Only instantiate embedding
  strategies when explicitly configured.

### Fingerprint computation

- Use native PHP string functions, not regex, where a native function exists.
- Use generators if a detector ever processes a large step history — avoid
  materializing all fingerprints in memory.

### No global mutable state

- No static mutable caches except the read-only `reserve.lua` script cache
  in `RedisLedger` (idempotent and safe).

### Stable, scannable key schema

- The Redis key schema
  (`agent-guard:budget:{scope}:{tenant}:{agent}:{period}`) must stay stable
  and colon-delimited for `SCAN`-ability. Never embed unescaped user input
  in the key.

## Performance review checklist

- [ ] N+1 queries? (check DB fallback queries)
- [ ] Missing indexes? (migration index coverage)
- [ ] Over-fetching? (return only needed fields)
- [ ] Unnecessary allocations? (reuse value objects; avoid per-step
      re-creation)
- [ ] Serial requests that could be batched?
- [ ] Expensive computation that could be lazy or cached?

## Targets (starting point only — derive real targets from the SLO)

| Metric | Starting target | Critical threshold |
|---|---|---|
| API response (p50) | < 100ms | < 500ms |
| API response (p95) | < 500ms | < 1s |
| Database query | < 50ms | < 200ms |
| Budget reservation (EVAL) | < 5ms | < 50ms |

These are **examples**, not acceptance criteria without evidence or owner
agreement.
