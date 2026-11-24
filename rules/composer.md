# Composer & Dependency Rules — laravel-agent-guard

> Adapted from the `dependency-audit` skill. This is a PHP/Composer
> library; dependency hygiene is part of the security posture.

## Version constraints

- **Use caret constraints (`^`) for libraries.** Never `dev-*`, `>=`, or `*`
  in `require`/`require-dev` for normal releases.
- The **nightly CI** is the only place `dev` versions appear — it pins
  `laravel/ai 1.x-dev` + `laravel/mcp 1.x-dev` to test against the latest.
- **PHP floor is `^8.3`.** Never suggest 8.4/8.5-only syntax (property
  hooks, asymmetric visibility, pipe operator) in library code.
- **Stable, sorted packages.** `config.sort-packages: true` is set. Keep
  `composer.json` dependencies sorted.

## Current dependency posture

| Dependency | Constraint | Notes |
|---|---|---|
| `php` | `^8.3` | Floor |
| `illuminate/*` | `^12.0\|^13.0` | contracts, redis, support |
| `laravel/ai` | `^1.0` | AI SDK |
| `laravel/mcp` | `^1.0` | MCP SDK |
| `pestphp/*` | `^3.0\|^4.0` | dev |
| `orchestra/testbench` | `^10.6\|^11.0` | dev |
| `phpstan/phpstan` | `^2.3` | dev, level max |
| `larastan/larastan` | `^3.0` | dev, PHPStan Laravel extension |
| `laravel/pint` | `^1.20` | dev |

## Audit & supply chain

- **Run `composer audit` in CI.** Critical/High CVEs are **blocking** for
  merge.
- **No restrictive licenses.** This is MIT. Flag GPL/AGPL/SSPL dependencies
  as blocking. All deps must be permissive.
- **Lock-file integrity.** Prefer not to commit a `composer.lock` for a
  library. If one is ever committed, verify its integrity.
- **Abandoned/deprecated deps** — flag packages with no release in 12+
  months or marked deprecated; propose alternatives.
- **Transitive vulnerabilities** — resolve via the direct parent update, not
  by ignoring. Document unfixable transitive risks with a mitigation plan.
- **Typosquatting** — only depend on well-known, verified vendors
  (`laravel/*`, `illuminate/*`, `pestphp/*`, `orchestra/*`, `phpstan/*`).

## Audit process (run on demand)

1. **Auto-detect** — `composer.json` → PHP → `composer audit` / `composer outdated`.
2. **Discovery** — map direct vs transitive, check lock consistency, dev vs
   prod separation.
3. **Version analysis** — `composer outdated --direct`; flag packages 2+
   major versions behind.
4. **Security scan** — `composer audit`; classify by severity; note CVEs.
5. **License compliance** — review all licenses; flag restrictive ones.
6. **Health assessment** — last release, issue activity, bus factor.
7. **Size/performance** — flag large deps used for small features.
8. **Conflicts** — version conflicts, peer deps, breaking-change risk.
9. **Supply chain** — typosquatting, non-standard registries, ownership changes.
10. **Update strategy** — security fixes first, then deprecated, then majors,
    then minor/patch.
11. **Monitoring** — recommend Dependabot/Renovate; monthly cadence.

## Report format

```markdown
## Dependency Audit Report
**Project:** laravel-agent-guard
**Date:** <date>
**Ecosystem:** PHP (Composer)

### Security Findings
| Severity | Package | Current | Issue | Remediation |

### Outdated Packages
| Package | Current | Latest | Type | Risk |

### License Summary
| License | Count | Compatible | Notes |

### Recommended Actions
1. [Immediate] ...
2. [This sprint] ...
```

## Cadence

- Security scan: **per commit** (CI `composer audit`).
- Full audit: **monthly** for active projects.
- Automated PRs: Dependabot/Renovate.
