# 0003: Repository architecture gate

Status: accepted, 2026-10-02

## Context

Portable CMS is intended to grow through work by humans and coding agents. Tests alone do not prevent implementation from accumulating hidden ownership, reverse dependencies, generic dumping-ground modules, oversized files, or undocumented temporary exceptions. A prose-only standard is also insufficient because it drifts from daily development.

Line count is not a measure of design quality. It is useful as a review signal when combined with explicit ownership, dependency direction, area contracts, tests, and exceptions that expire.

## Decision

The repository owns a versioned [`architecture.json`](../../../architecture.json) policy and [`architecture-exceptions.json`](../../../architecture-exceptions.json) registry. `php tools/check-architecture.php` enforces them and the root repository gate always runs that command.

The policy defines:

- implementation source roots and file extensions;
- architecture layers, owned paths, namespace prefixes, and permitted dependencies;
- warning and maximum line budgets for production, facade, and test sources;
- required documents and area contracts;
- ignored generated, vendored, build, and machine-local directories;
- forbidden generic module names.

The gate resolves the most-specific path owner. It checks relative JavaScript imports, PHP include/require paths, and engine namespace references where those dependencies are statically detectable. Unknown implementation files fail closed as unowned. This is a bounded architecture check, not a general PHP or JavaScript parser; the language-quality task owns deeper static analysis.

Warnings above a review threshold do not fail by themselves. Files above a maximum fail. Public contributions keep architecture-exceptions.json empty and resolve policy failures without a private exception workflow.

## Initial budgets

| Source class | Review threshold | Maximum |
| --- | ---: | ---: |
| Production | 750 | 1,200 |
| Facade | 250 | 400 |
| Test | 1,000 | 1,800 |

These budgets reflect the product's small PHP/JavaScript modules and their limited generated or platform-specific code. The accepted Gate 0 baseline has no maximum violation. Existing production files above the review threshold become visible without blocking mechanical extraction.

## Consequences

- New implementation has an explicit owner and permitted dependency direction.
- Large files prompt a cohesion review before they become emergency refactors.
- Temporary ceilings cannot become silent permanent policy.
- Required documentation and area contracts cannot disappear unnoticed.
- The customer bundle gains no dependency because the gate is repository tooling only.
- The checker itself requires focused fixtures for boundaries, failures, and exception lifecycle.

The gate does not require splitting a cohesive module merely to satisfy a number. A split must improve ownership, contracts, testability, or change isolation. Conversely, staying below a line threshold does not excuse mixed concerns or an invalid dependency.

## Alternatives rejected

### Review-only guidance

Rejected because source-size and dependency rules would be applied inconsistently and exceptions would have no expiry mechanism.

### A single hard line limit

Rejected because entry-point facades, production modules, and integration tests have different pressures. Warning and maximum bands allow review without encouraging arbitrary fragmentation.

### A new third-party architecture tool

Rejected for this bounded first version. The repository already has PHP in every development and host-qualification environment, and the required checks are small and product-specific. This decision can be superseded if a maintained analyzer provides materially stronger dependency evidence at acceptable cost.
