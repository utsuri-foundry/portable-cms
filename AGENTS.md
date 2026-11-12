# Portable CMS contributor guide

Use a branch and pull request for one focused outcome. Read CONTRIBUTING.md,
the affected area READMEs and the product rules below. Run fast checks and the
affected lanes before review; full qualification precedes release/publication.
Do not commit client content, credentials or local state.
Private maintainer coordination is not required. Push, merge, deployment and
publication still require explicit authorization from the repository owner.

## Product invariants

- PHP and MariaDB/MySQL are the only authoritative production CMS runtime.
- The public website is a verified static artifact. Visitor requests do not require PHP, MariaDB, sessions, Cron, Cloudflare, or Utsuri services.
- Server-rendered semantic HTML and native forms are the administration baseline. Native JavaScript islands own only bounded interaction that HTML cannot provide well.
- Profiles are trusted, versioned packages of bounded data, reviewed renderers, and prebuilt assets. Editors cannot upload executable PHP, SQL, JavaScript, or unrestricted HTML/CSS.
- Domain rules and validation live once in PHP services. HTTP, storage, database, media, AI, and deployment remain replaceable adapters.
- All variable time, randomness, IDs, filesystem, network, database, image, and provider behavior enters through explicit boundaries.
- Private configuration, originals, sessions, previews, backups, and working files never enter the document root or public artifact.
- Jobs are durable, idempotent, leased, bounded, observable, and recoverable after interruption.
- Normalized export plus checksum-addressed media is the portability boundary. Provider identifiers are never canonical content.
- Unsupported capabilities fail closed or are visibly disabled. Work cannot remain queued for infrastructure that is absent.
- The fictional Reference Studio profile proves the product before any client migration. It must not become a hidden client-specific kernel.

The dependency direction is:

```text
schemas and stable contracts
        <- domain and application services
        <- repositories and effect interfaces
        <- HTTP, filesystem, database, media and provider adapters
        <- administration, trusted profiles and operator tools
```

Dependencies point inward. Effects point outward through explicit requests. Host, provider, database, and profile-specific types stop at their adapters.

## Runtime and dependency rules

- Support the documented PHP floor and strict types. Expected failures are typed results or domain exceptions projected into stable safe errors.
- Production code does not suppress errors, interpolate untrusted SQL, invoke a shell with content-derived strings, or rely on global mutable state.
- Browser code is standards-based native JavaScript. Do not add a framework, client router, TypeScript build, HTMX, or general DOM-swap runtime without an accepted dependency decision.
- The installed bundle requires no Node.js, Composer, Docker, root access, or persistent process.
- Composer is permitted as a release-build tool only for individually reviewed PHP dependencies. Customer hosts receive the locked vendored graph.
- Developer and CI tooling may use pinned tools that never enter the installed runtime or public artifact.
- New dependencies require a concrete boundary, licence review, locked transitive graph, advisory path, update policy, and removal plan.

## Change rules

- One task produces one finite, observable outcome.
- Keep behavior changes separate from broad mechanical extraction or movement.
- Preserve contracts, fixtures, canonical bytes, hashes, migration provenance, and public routes during mechanical changes.
- Generated output has one authoritative input and is never edited by hand.
- Database changes are forward migrations. Destructive transforms require a verified backup and compatibility plan.
- Security-sensitive behavior needs hostile-path tests, not only happy-path coverage.
- Deferred work belongs in a reviewed issue with impact and an acceptance boundary, not only a source comment.
- Never commit credentials, access tokens, signing material, client content, private media, live hostnames, or machine-local paths.

## Repository map

- `engine`: reusable PHP product runtime and engine-owned browser islands.
- `profiles/reference-studio`: fictional acceptance profile, deterministic content, renderers, and prebuilt assets.
- `schemas`: versioned structural contracts and fixtures.
- `tools`: repository gate, bundle, qualification, migration, release, and operator tooling.
- `tests`: cross-area fixtures, integration journeys, security tests, and artifact acceptance.
- `docs/architecture`: system boundaries and durable decision records.
- `docs/development`: implementation, dependency, testing, security, and operations standards.
- `tasks`: contributor guidance; work is tracked through issues and pull requests.

Every top-level implementation area has a README that states ownership, exclusions, allowed dependencies, and focused checks. Avoid generic `helpers`, `common`, `misc`, or `utils` dumping grounds.

`architecture.json` enforces ownership, dependencies, documentation and source budgets. Public contributions keep `architecture-exceptions.json` empty. A line count prompts cohesion review; never split a coherent module solely to satisfy a number.

Public contributions keep `quality-exceptions.json` empty. Resolve quality failures in code; analyzer suppressions, unsafe browser escape hatches and production PHP error-control operators are not admitted.

## Verification

Run fast checks and the affected lanes before review:

```text
nix develop -c tools/check-fast.sh
```

The PR workflow selects focused checks from changed paths. Reviewers verify that
selection and request more coverage for cross-area changes. The protected
`Required PR checks` aggregate requires every selected check to pass.

Run complete qualification before release or publication; CI also runs it nightly
and on version tags. The authoritative complete command remains:

```text
nix develop -c tools/check-repository.sh
```

It includes architecture, quality policy, Biome, PHP formatting, PHPStan, unit tests, templates, installable bundle checks, extraction boundaries, and the MariaDB journey. A skipped required lane or missing host tool is a failure, not a success. Tests are hermetic, bounded, and deterministic; time, IDs, randomness, providers, and failures are injected.

## Safety

Do not force-reset, clean, delete, overwrite, or repurpose another worktree or branch. Preserve other contributors’ implementation work. Push, merge, release, deployment, migration, destructive cleanup, and production activation remain explicit operations outside task ownership.
