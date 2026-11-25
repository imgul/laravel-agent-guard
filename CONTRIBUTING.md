# Contributing to laravel-agent-guard

Thanks for your interest in contributing. This document covers everything you need to get started.

## Development setup

`laravel-agent-guard` is a Laravel package tested with [Orchestra Testbench](https://packages.tools/testbench/). The full toolchain runs on PHP 8.3+ and requires Composer.

### Prerequisites

- **PHP 8.3+** with the `redis` extension recommended
- **Composer 2.x**
- A running **Redis** server for concurrency tests (optional — tests fall back gracefully when Redis is absent)

### Install

```bash
git clone https://github.com/imgul/laravel-agent-guard.git
cd laravel-agent-guard
composer install
```

### Available commands

| Command | Purpose |
|---|---|
| `composer test` | Run the full Pest test suite |
| `composer lint` | Check code style with Laravel Pint (dry run) |
| `composer format` | Fix code style with Laravel Pint |
| `composer stan` | Run PHPStan at level `max` across `src/` and `tests/` |

Run all four before opening a pull request:

```bash
composer format
composer lint
composer test
composer stan
```

All four must pass. CI runs the same commands.

## Coding standards

### Style

The project uses **Laravel Pint** with the `laravel` preset. Do not worry about formatting manually — run `composer format` before committing. Pint is deterministic; if `composer lint` fails, `composer format` will fix it.

### Static analysis

PHPStan runs at **level `max`** over both `src/` and `tests/`, with Larastan for Laravel-specific inference and `mrpunyapal/peststan` for Pest test support. The configuration enforces `reportUnmatchedIgnoredErrors`, so any `@phpstan-ignore` comment must target a real, currently-reported error — stale ignores will fail the build.

If you add a `@phpstan-ignore-next-line`, verify it suppresses an actual error by removing it and confirming PHPStan reports the line.

### Testing

Tests are written with **Pest** and live under `tests/`:

- `tests/Unit/` — pure logic: detectors, breakers, fingerprints, similarity strategies, pricing.
- `tests/Feature/` — integration: middleware, MCP tools, Artisan commands, concurrency (Redis).

**Fake-first testing.** When testing code that interacts with the guard, use `AgentGuard::fake()` to swap in the in-memory runtime. This records every decision with zero Redis or event side effects, and exposes a full assertion surface:

```php
AgentGuard::fake();

// ... exercise the code under test ...

AgentGuard::assertLoopDetected(tool: 'search');
AgentGuard::assertBudgetSoftCapReached(scope: 'tenant');
AgentGuard::assertNothingDenied();
```

**Concurrency tests.** The Redis concurrency test spawns real worker processes to prove the atomic Lua reservation holds under load. It requires a running Redis server. If Redis is unavailable, the test skips automatically — it does not fail.

## Pull request process

1. **Branch from `main`.** Use a descriptive branch name: `fix/short-description`, `feat/short-description`, `docs/short-description`.

2. **Keep changes focused.** One logical change per PR. If you are fixing a bug and adding a feature, open two PRs.

3. **Write tests.** Every behavioural change must include tests. Bug fixes should include a test that reproduces the bug first, then the fix.

4. **Update documentation.** If your change affects the public API, configuration, or usage patterns, update the README, the relevant `docs/` file, and the CHANGELOG.

5. **Run the gates.** Before pushing, ensure all four commands pass locally:

   ```bash
   composer format
   composer lint
   composer test
   composer stan
   ```

6. **Open the PR.** Provide a clear title (conventional commit style: `fix:`, `feat:`, `docs:`, `refactor:`, `test:`) and a description of what changed and why. Link any related issues.

7. **Address review feedback.** Maintainers may request changes. Push follow-up commits to the same branch; do not open a new PR.

## Commit message format

The project follows [conventional commits](https://www.conventionalcommits.org/):

```
<type>(<optional scope>): <description>

<optional body>

<optional footer>
```

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`.

Examples:

```
fix: guard array_filter callback against non-Response elements
feat: add per-tool budget scope
test: cover downshift ladder in GuardAgentStepsTest
```

## Adding features

When proposing a new feature, open an issue first to discuss scope. This package deliberately maintains a narrow scope around runtime execution and spend safety — features that overlap with authorization, redaction, or sandboxing belong in separate packages. See the README's "What it is not" section for the boundary.

## Reporting bugs

Open an issue with:

- The version of `laravel-agent-guard` and `laravel/ai` you are using
- A minimal reproduction — ideally a failing test or a code snippet
- Expected vs. actual behaviour
- Relevant logs or event output

## Security issues

Do **not** open a public issue for security vulnerabilities. Email the maintainer directly or use the GitHub security advisory feature to report privately. See [SECURITY.md](SECURITY.md) for the full disclosure process.

## Code of Conduct

All contributors are expected to follow the [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you agree to uphold its standards.
