# Security Rules — laravel-agent-guard

> Adapted from the `security-audit` skill (Cloudflare) and `php-best-practices`
> security section. Security is a property of the whole package, especially
> the atomic budget ledger and tenant isolation.

## Methodology

### Source-first, boundary-aware

- A finding must cross a **real trust boundary** to count. A missing best
  practice without an affected principal/resource is not a finding.
- **Never assume deployment behavior** (proxy, headers, identity policy) not
  in the source. If a control is required and absent, mark it
  `needs_validation` with the exact missing fact.
- **Separate priority from certainty.** Only `confirmed` findings get
  severity. `needs_validation` means a hypothesis is blocked, not a
  low-confidence vulnerability.

### Severity anchors

| Severity | Meaning |
|---|---|
| **critical** | Unauthenticated code execution, full data-store access, arbitrary account takeover. |
| **high** | Defeats an explicit control with real consequences (cross-tenant read/write, auth bypass, authenticated code execution). |
| **medium** | Real boundary violation, limited blast radius, uncommon preconditions. |
| **low** | Disclosure of non-secret internals. |
| **informational** | Confirmed but minimal-impact observation. |

### Operating modes

- **Guidance mode** (default): for focused security questions, use the
  relevant parts. Do not auto-run a full audit or create artifacts unless
  explicitly requested.
- **Full audit mode**: only when explicitly asked to audit/pen-test or
  produce a report. Run the full six-phase workflow with **sandboxed,
  read-only source inspection**. Never execute target code outside an
  OS-enforced sandbox with no network, empty env, and bounded resources.

## Package-specific security rules

### Tenant isolation (paramount)

- Budget keys derive from **tenant/principal context**, never from
  agent-supplied or request-supplied values.
- A budget key an agent can influence is a **blocking** cross-tenant
  finding.
- Never use `MCP-Session-Id` as a budget key (laravel/mcp 1.0 is stateless).

### Atomicity is a security property

- The Redis Lua reservation is the **TOCTOU defense**. Check + spend +
  soft-flag happen in a single `EVAL` round-trip.
- Any path that checks a budget then spends **without** the atomic `EVAL` is
  a **blocking** finding.
- The concurrency test (`RedisLedgerConcurrencyTest`: 10 workers vs $5 cap,
  zero overspend) is the regression gate — it must stay green.

### Input validation

- Validate all external entry points. `reserve()` / `reconcile()` must
  reject NaN, infinity, and negative costs. Caps must be finite.
- Type-cast and bound all numeric inputs before they reach the ledger or
  the Lua script.

### SQL safety (DatabaseLedger)

- Always use the query builder / parameterized expressions. `DB::raw('spent -
  '.(float)$excess)` is safe because the float is cast and bound.
- **Never** concatenate raw user input into SQL.

### Lua safety (reserve.lua)

- Reads only `KEYS[1]`; writes only that key.
- Must never `loadstring`, `call` external commands, or touch other keys.
- The script is loaded from the canonical `src/Budget/Lua/reserve.lua` file;
  never inline a divergent copy.

### Secrets & logging

- Never log or emit secrets. Events carry scope/key/spend/cap — never API
  keys, tokens, or raw agent payloads.
- No credentials in code, config, or test fixtures.

### Dependency security

- Run `composer audit` in CI. Critical/High CVEs are **blocking**.
- Flag restrictive licenses (GPL/AGPL/SSPL) as blocking — this is MIT.
- See `rules/composer.md`.

## Anti-patterns (reject in review)

1. Checklist deviations presented as vulnerabilities.
2. Defense-in-depth advice with no reachable boundary violation.
3. Live/shared-environment testing where bounded local evidence is sufficient.
4. Guessing provider/proxy/identity/deployment behavior not in source.
5. Treating same-principal authority or self-impact as a cross-boundary result.
6. Reporting a parser/runtime effect stronger than the observed effect.
7. Assigning severity to `needs_validation` records.
