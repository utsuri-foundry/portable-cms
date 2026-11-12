# Schemas

Versioned JSON Schema contracts define the shape of profiles, records, frozen
builds, exports and browser data. PHP validates these structures, then checks
semantic rules and relationships that a schema alone cannot prove.

Schemas contain no runtime orchestration or provider behaviour. Their meaning
is documented in [Product contracts](../docs/architecture/product-contracts.md).

## Find a contract

Files follow `portable-cms-NAME-vN.schema.json`:

| Family | Boundary |
| --- | --- |
| `profile`, `administration-labels` | Installed profile and editing labels |
| `record`, `rich-text`, `web-address`, `vimeo-video`, `composition` | Content values |
| `snapshot`, `artifact`, `search` | Frozen compiler input and public output |
| `export`, `content-package` | Portable content and verified handover |
| `feed*` | Published content feed and activation history |
| `website-publication`, `published-slugs` | Publication intent and route history |
| `design-registries`, `search-sharing`, `structured-data` | Presentation and discovery |
| `capabilities`, `protocol`, `terminology`, `ai-candidate` | Host and application boundaries |

[`fixtures/contract-cases-v1.json`](fixtures/contract-cases-v1.json) is the shared
fixture catalog: `accepted` passes validation, `schema_rejected` fails structure,
and `domain_rejected` passes structure but fails semantic validation.

## Change a contract

Give each schema an exact `$id`, bound strings and collections, reject unknown
properties and avoid remote references. Preserve the meaning of existing valid
bytes; incompatible representations need a new version and fixture cases.

Some schemas embed or derive other contracts. The scripts in
[`tools/contracts`](../tools/contracts/) check these copies; `--write` deliberately
regenerates them. After changing embedded definitions, regenerate profile v3
and export v2 last with `check-profile-groups-schema.php --write` and
`check-export-package-schema.php --write`. Never edit generated copies by hand.

## Check a change

From the repository root:

```sh
nix develop -c php tests/php/run.php
nix develop -c tools/check-fast.sh
```

The fast gate checks generated-schema drift and shared fixtures. Run the affected
integration lanes too when the representation changes; see the
[testing policy](../docs/development/testing.md).
