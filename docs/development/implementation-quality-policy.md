# Implementation quality policy

The authoritative root command is `nix develop -c tools/check-repository.sh`. Its quality lanes are:

1. `tools/check-quality-policy.php` for repository-specific non-negotiable rules;
2. Biome for native JavaScript parsing, formatting, lint, unsafe evaluation, and floating promises;
3. PHP CS Fixer in check mode for deterministic PHP formatting;
4. PHPStan level 6 with the PHP 8.2 language floor;
5. focused quality-policy tests proving hostile and exception paths.

## Exceptions

Public contributions keep `quality-exceptions.json` empty. Resolve quality failures in code. No analyzer suppression, unsafe browser escape hatch or production PHP error-control operator is admitted. There is no private removal-record prerequisite for contributing. Expected host warnings cross the typed `HostIo` boundary documented in [the PHP standard](php-standard.md#resources-and-cleanup).

## Commands

```text
just check-javascript
just check-php
just format
```

`format` changes source. Review its diff and rerun the focused and root gates. The check commands never rewrite source.

## Raising strictness

PHPStan level 6 is an initial floor, not a target ceiling. Raise one level only after current findings are corrected in code. Do not generate a baseline, add `ignoreErrors`, exclude a production directory, or add inline ignores to claim a clean run.

Biome recommended rules plus explicit security and promise rules are the floor. New islands may adopt stricter domain-aware rules when the code supplies enough type information, but local rule overrides cannot disable repository safety rules.
