# 0004: Development quality toolchain

Status: accepted, 2026-10-02

## Context

Portable CMS must retain consistent PHP and native JavaScript quality as humans and agents extend the product. Review alone cannot reliably enforce formatting, collection types, unsafe rendering, floating promises, analyzer suppressions, or the PHP floor. These checks must not add Node.js, Composer, or development packages to an installed customer host or public artifact.

## Decision

Adopt three development-only tools through the repository's locked Nix shell:

| Tool | Locked version | Licence | Responsibility |
| --- | --- | --- | --- |
| Biome | 2.5.14 | MIT or Apache-2.0 | JavaScript syntax, formatting, recommended lint, security lint, and floating promises |
| PHPStan | 2.2.14 | MIT | PHP static analysis at level 6 against the PHP 8.2 language floor |
| PHP CS Fixer | 3.95.1 | MIT | Deterministic PER-CS 2.0 formatting and import hygiene |

The complete graph is the Nix closure selected by `flake.nix` and locked by `flake.lock` at nixpkgs revision `c59305bab2065cfecc4944690d9eedbb56f3a9fa`. No npm package graph is introduced. No Composer development package is introduced. `composer install --no-dev` and the conventional bundle therefore remain unchanged.

Repository-owned configuration is authoritative:

- `biome.json` selects included native modules, formatting, recommended rules, `noFloatingPromises`, and `noGlobalEval`;
- `phpstan.neon.dist` selects PHP 8.2, level 6, analyzed paths, and the locked runtime autoloader;
- `tools/quality/php-cs-fixer.php` selects non-risky PER-CS 2.0 rules;
- `tools/check-quality-policy.php` rejects missing strict types, broad PHPStan baselines, analyzer ignore directives, unsafe browser rendering or evaluation, and new production error-control operators.

Public contributions keep quality-exceptions.json empty. Resolve implementation-quality failures in code; no suppression or private removal-record dependency is admitted by the public gate.

## Locked graph and measured cost

Nix records every transitive build and runtime input by content-addressed store path. On x86_64 Linux on 2026-10-02, the selected closures measured:

| Tool | Store requisites | Closure bytes |
| --- | ---: | ---: |
| Biome | 9 | 138,199,937 |
| PHPStan | 121 | 289,820,633 |
| PHP CS Fixer | 126 | 271,066,664 |

The closures overlap substantially with PHP and shell inputs already present, so these figures are characterizations, not additive download size. They affect developer and CI environments only. The installed CMS bundle and public artifact contain none of the three tools or their graphs.

## Update and advisory policy

Updates are deliberate lockfile changes. A maintainer reviews upstream release notes, supported PHP and JavaScript versions, licence metadata, security notices, rule changes, formatter diffs, closure size, and the full repository gate before accepting a new Nix lock. Renovation never runs an unconstrained formatter or analyzer upgrade directly on `main`.

Composer audit continues to cover the installed PHP graph. Tool advisories are monitored through upstream projects and nixpkgs security maintenance. A relevant unpatched tool vulnerability blocks release work that invokes the affected tool on untrusted repository content until upgraded, isolated, or removed.

## Removal strategy

- Biome can be removed by replacing its formatter, parser, lint, and floating-promise checks behind `check-javascript`; browser code remains native JavaScript.
- PHPStan can be replaced behind `check-php`; its PHPDoc shapes use broadly understood syntax and production code has no runtime dependency on it.
- PHP CS Fixer can be replaced behind `format` and `check-php`; committed source remains ordinary PER-CS PHP.
- The repository quality checker is local PHP and can evolve without changing customer artifacts.

Removal or replacement requires an ADR, equivalent executable coverage, and a clean full-repository format transition. It may not silently weaken a lane.

## Consequences

- New PHP and JavaScript receive deterministic, pinned, executable feedback.
- The imported PHP baseline now passes level 6 without a generated analyzer baseline or ignore directive.
- Existing production error-control operators are visible as exact expiring debt rather than silently accepted policy.
- Development closures grow, while customer runtime and public-site dependency promises remain unchanged.
- PHP CS Fixer runs under the shell's PHP 8.3 executable; PHPStan separately evaluates syntax and APIs against PHP 8.2. A future CI matrix should add direct PHP 8.2 execution before general availability.
