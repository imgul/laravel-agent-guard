# PHP Rules — laravel-agent-guard

> Adapted from the `php-best-practices` skill. Applies to all PHP in this
> package. PHP floor is **8.3** — use 8.3 features, never 8.4/8.5.

## Type system (CRITICAL)

- `declare(strict_types=1);` — always the first statement after `<?php`.
- Declare return types on every method, function, and closure.
- Type every parameter and every property.
- Prefer union and intersection types; **never use `mixed`**.
- Use `void` / `never` where appropriate.
- Use `readonly` for immutable data (properties, value objects, event payloads).

## Modern PHP (8.3)

- Constructor property promotion for dependencies and immutable data.
- `match` expressions over `switch`.
- Backed `string` enums for fixed sets (see `BudgetScope`, `LoopState`).
- `readonly` properties and `readonly` classes for value objects.
- Typed class constants (`public const string NAME = '...'`).
- `#[\Override]` on methods overriding a parent/interface.
- First-class callable syntax (`strlen(...)`) over wrapping closures.
- Nullsafe operator (`?->`) for nullable chains.
- **Never** the `@` error-suppression operator.

## PSR standards (HIGH)

- PSR-4 autoloading — one class per file, name matches file, namespace matches path.
- PSR-12 — enforced by Pint; do not fight the formatter.
- `StudlyCaps` classes, `camelCase` methods/properties, `snake_case` DB columns.
- One class per file.

## SOLID (HIGH)

- Single Responsibility — one reason to change per class.
- Open/Closed — extend via new detectors/drivers, not by editing existing ones.
- Dependency Inversion — depend on `Ledger`, `DetectorInterface`,
  `EmbeddingAdapterInterface`; inject via constructor.
- Interface Segregation — small, focused contracts.

## Error handling (HIGH)

- Specific domain exceptions (`LoopDetectedException`, `KillSwitchException`,
  `BudgetExceededException`). Never throw generic `\Exception` for domain errors.
- Catch specific exceptions, never bare `catch (\Exception)`.
- Use `finally` for guaranteed cleanup.
- Fail fast on invalid input.

## Performance (MEDIUM)

- Avoid globals; use dependency injection.
- Lazy-load expensive operations (embedding similarity).
- Native array/string functions over manual loops/regex.
- Generators for large datasets.

## Security (CRITICAL)

- Validate and sanitize all external input.
- Escape output based on context.
- Prepared statements / query builder for all SQL.
- See `rules/security.md` for the full package security policy.
