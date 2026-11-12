# Repository layout

Portable CMS is a monorepo for multiple independently versioned artifacts that share contracts and acceptance tests.

| Area | Owns | Must not own |
| --- | --- | --- |
| `engine` | reusable runtime, administration, compiler foundation, adapters, engine browser islands | client content, client design, provider credentials |
| `profiles/reference-studio` | fictional profile manifest, trusted renderers, deterministic content and assets | reusable engine behavior, real client identity |
| `schemas` | versioned structural contracts and compatibility fixtures | runtime orchestration or database access |
| `tools` | checks, builds, qualification, install/update, migration, release and operator commands | hidden domain authority |
| `tests` | cross-area fixtures and journeys | production-only behavior unavailable to product code |
| `docs` | architecture, decisions, standards and runbooks | generated task status |
| `tasks` | contributor guidance; issues and pull requests track work | implementation logs or duplicate roadmaps |

Area-specific implementation may add nested packages when a contract and independent check justify them. Avoid creating packages merely to mirror conceptual nouns.

Generated artifacts go to ignored build directories. Authoritative schemas, migrations, fixtures, manifests, and expected hashes remain tracked.

## Contributor checkouts

Use an ordinary clone, a focused branch and a pull request. See
[CONTRIBUTING](../../CONTRIBUTING.md). No special checkout layout or private coordination tool is required.
