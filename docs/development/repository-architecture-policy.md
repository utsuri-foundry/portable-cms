# Repository architecture policy

The executable policy lives in [`architecture.json`](../../architecture.json). The exception registry lives in [`architecture-exceptions.json`](../../architecture-exceptions.json). Change these files only when the architecture itself changes, not to silence an inconvenient finding.

## Command

```text
php tools/check-architecture.php
```

Machine-readable output is available with `--json`. The complete repository command remains:

```text
nix develop -c tools/check-repository.sh
```

## Ownership and dependencies

| Layer | Owns | May depend on |
| --- | --- | --- |
| `runtime` | Private PHP CMS runtime and product services | itself |
| `browser` | Engine-owned native JavaScript and upload island | itself |
| `profiles` | Trusted profile packages and renderers | runtime, browser |
| `operator-tools` | Checks, qualification, install, migration and worker entry points | runtime |
| `tests` | Fixtures and verification | all product layers |

The longest matching path wins, allowing `engine/src/cms/conventional/client` to belong to `browser` even though it is nested beneath the runtime root. Equal-specificity ownership is invalid.

The configured source roots must exist, and the checker scans the entire repository for recognized source extensions outside explicitly ignored directories. A new top-level script therefore fails as unowned instead of escaping the policy. The checker enforces dependencies it can establish without interpreting arbitrary code:

- relative JavaScript `import` and `export ... from` specifiers;
- PHP `require`, `require_once`, `include`, and `include_once` repository paths;
- declared PHP namespace prefixes.

Bare package imports and platform namespaces are external. Dynamic dependencies require explicit review alongside static analysis.

## Source budgets

| Class | Warning | Maximum | Classification |
| --- | ---: | ---: | --- |
| Production | 750 | 1,200 | ordinary PHP, JavaScript, and shell source |
| Facade | 250 | 400 | declared entry points and direct files beneath `tools/` |
| Test | 1,000 | 1,800 | files beneath declared test roots |

Line counts include comments and blank lines because the rule measures the amount a reviewer or agent must navigate, not executable statements. A warning prompts a cohesion review. It does not require a split if the module still has one clear responsibility. The maximum is a backstop against unbounded growth.

## Exceptions

Public contributions keep `architecture-exceptions.json` empty. Resolve policy violations within the existing budgets and dependency boundaries. The gate rejects active exceptions; no private planning record is needed to contribute.

## Adding an area

When adding a new implementation area:

1. Give it a domain-specific directory and README stating ownership, exclusions, dependencies, and focused checks.
2. Add its source path to exactly one architecture layer or introduce a reviewed layer.
3. Declare only the dependencies it needs.
4. Add representative allowed and forbidden dependency fixtures.
5. Add required durable documentation to the manifest.

Do not create `common`, `helpers`, `misc`, `utilities`, `utility`, or `utils` modules. Name a module after the capability or boundary it owns. Shared code is not an ownership category.
