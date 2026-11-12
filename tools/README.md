# Tools

Repository checks, local development, bundle construction and installed PHP
operator commands live here. Top-level scripts are thin entry points; reusable
tooling belongs in named subdirectories.

Tools call the engine through its public boundaries. Product rules stay in the
engine, which never depends on this directory. Developer tools do not become
installed runtime dependencies.

## Find a tool

| Area | Purpose |
| --- | --- |
| `check-*.sh`, `check-*.php` | Repository, architecture, quality and release checks |
| [`ci`](ci/README.md) | Select and run focused checks or full qualification |
| `contracts` | Check and regenerate derived schemas |
| `development`, `serve-*.sh` | Local sandboxes and fictional fixture builds |
| `build-conventional-cms-bundle.php` | Build the installable PHP bundle |
| `conventional-*.php` | Installed web, worker and operator entry points |
| [`publication`](publication/README.md) | Prepare and audit public source snapshots |

For local setup, see [the development guide](../docs/development/local-reference-cms.md).
For installed operations, use the procedure for
[installation](../docs/development/conventional-cms-identity.md),
[publication](../docs/development/publication-workflow.md),
[handover](../docs/development/portable-handover.md),
[import](../docs/development/content-import.md) or
[upgrades](../docs/development/conventional-cms-upgrades.md).
These guides explain required inputs, confirmation and recovery.

## Check a change

From the repository root:

```sh
nix develop -c just check-architecture
nix develop -c just check-php
nix develop -c just check-javascript
```

Run `nix develop -c just test-bundle` when changing installed entry points or
packaging. Before review, run the fast gate and affected lanes in the
[testing guide](../docs/development/testing.md). Release and publication require
full qualification.
