# Security Policy

## Supported Versions

Security fixes are applied to the latest stable release. The version support window tracks the package's semantic versioning:

| Version | Supported |
|---|---|
| `1.0.x` | ✅ Yes |
| `< 1.0` | ❌ No |

## Reporting a Vulnerability

**Do not open a public GitHub issue for security vulnerabilities.**

If you discover a security vulnerability in `laravel-agent-guard`, please report it privately by one of the following methods:

1. **GitHub Security Advisory** — Use the "Report a vulnerability" tab on the repository's Security page to request a private advisory.
2. **Email** — Contact the maintainer directly with a description of the vulnerability.

### What to include

- A description of the vulnerability and its potential impact
- Steps to reproduce (a minimal test case or code snippet is ideal)
- Any known mitigations or workarounds
- Whether you would like to be credited for the discovery

### What to expect

You will receive a response acknowledging receipt within **48 hours**. The maintainer will investigate and keep you updated on the progress. Once a fix is prepared, it will be released in a patch version, and you will be notified before the public advisory is published.

## Scope

`laravel-agent-guard` is a runtime-safety layer for Laravel AI agents and MCP tool servers. The security boundary is the package's own code — vulnerabilities in upstream dependencies (`laravel/ai`, `laravel/mcp`, Laravel framework) should be reported to those projects directly.
