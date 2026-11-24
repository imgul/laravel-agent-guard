# UI/UX Rules — laravel-agent-guard

> Adapted from the `web-design-guidelines` (Vercel) and `frontend-design`
> (Anthropic) skills.
>
> This package is a **backend library** with no shipped UI of its own.
> These rules apply to any documentation site, admin panel, status
> dashboard, or example app built on top of it — and to the README/docs.

## If UI is ever built

### Accessibility first (WCAG 2.1 AA)

- Semantic HTML, keyboard navigation, ARIA where needed, sufficient color
  contrast.
- Audit with the `web-design-guidelines` skill: read its
  `references/command.md`, apply all rules, output findings in terse
  `file:line` format.

### Responsive & performance-conscious

- Mobile-first; never assume desktop width.
- Lazy-load routes/components, memoize expensive computations, optimize
  images (WebP, lazy-load, responsive images).
- Respect `prefers-reduced-motion`.

### Design quality

- One or two typefaces, deliberately chosen. Follow a clear type scale;
  avoid generic AI-default font stacks.
- Color via CSS variables for theming (light/dark). Don't hardcode colors
  in components.
- Motion with purpose — guide, don't distract.
- **Distinctive, intentional design** — reject generic AI aesthetics.
  Ground the design in the subject matter before implementing.

## Documentation as UX

- **README and doc examples must be copy-pasteable and correct.** A broken
  example is a UX bug.
- **Status output should be terse and scannable** — `file:line` style for
  findings, table format for budgets/status.
- **Error messages are UX.** Domain exceptions carry clear, actionable
  messages with scope/key/spend/cap context.
- Keep prose tight; use tables and code blocks for structured data.

## When to load these skills

- **`web-design-guidelines`** — "review my UI", "check accessibility",
  "audit design", "review UX".
- **`frontend-design`** — building new UI, choosing aesthetic direction,
  typography, color theming, motion.
