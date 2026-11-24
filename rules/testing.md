# Testing Rules — laravel-agent-guard

> Adapted from Pest testing principles and the `laravel-best-practices`
> testing guidance.

## Core principles

- **Test behavior, not implementation.** Assert outcomes and side effects,
  not private method calls or formatting.
- **One test verifies one meaningful behavior.**
- **Quality over quantity** — 5 meaningful tests beat 20 trivial ones.
- **Keep setup minimal and relevant.**

## Structure

- **`tests/Unit/`** — pure logic, **no framework boot**. Fingerprint,
  similarity, detectors, budget guard with a fake ledger, pricing catalog,
  TextUsage.
- **`tests/Feature/`** — framework integration. Service provider wiring,
  facade, Redis concurrency.

## Hard gates

- The **concurrency test** (`RedisLedgerConcurrencyTest`: 10 parallel
  workers vs $5 daily cap, zero overspend) is a hard gate. It must never be
  removed or skipped in CI. It proves the atomic Lua reservation prevents
  TOCTOU overspend.

## Mocking & isolation

- **Mock external services.** Redis is real in the concurrency test (it's
  the point); otherwise use fakes/stubs. Never hit real external APIs.
- **No real network calls** in tests.
- **No state leakage** between tests — reset Redis keys / DB state.

## Coverage checklist

For every change, cover:

- **Happy path** — the normal success flow.
- **Failure path** — validation failures, denied reservations, loop
  detection, kill switch.
- **Edge cases** — null, empty, zero, negative, boundary values, off-by-one,
  float precision on USD.
- **Authorization/tenant isolation** — budgets scoped per tenant
  independently.

## Pest style

- Descriptive `it()` / `test()` names in plain language.
- Group related tests logically.
- **No `final` or `readonly`** on test classes that need Mockery mocking.
- Pest test files without a namespace treat PHP built-in classes as global —
  **do not** add `use` statements for global classes (`Exception`,
  `DateTimeImmutable`, `stdClass`).

## What NOT to test

- Trivial getters/setters without logic.
- Parameter counts, method existence, class names.
- Framework internals (Eloquent, routing).
- Private methods directly — test through the public API.

## Running

```bash
composer test          # pest
composer lint          # pint --test
composer stan          # phpstan analyse (level max)
```

All three must be green before a phase is considered complete.
