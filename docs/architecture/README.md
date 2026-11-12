# Architecture index

## Direction

Portable CMS has one authoritative PHP product engine and replaceable outer adapters:

```text
schemas and contracts
        ^
engine domain and application services
        ^
repositories and effect interfaces
        ^
HTTP, MariaDB, filesystem, media, AI and deployment adapters
        ^
administration, trusted profiles and operator tools
```

Dependencies point inward. Effects point outward through explicit interfaces. Profile, host, provider, database, image-library, and browser types stop at their boundary.

Publishing crosses a separate artifact boundary:

```text
validated records + immutable media + exact profile release
        -> frozen snapshot
        -> deterministic compiler
        -> verified manifest and static files
        -> journaled promotion
        -> public web server or replaceable static host
```

Ordinary public requests never enter the CMS runtime.

## Stable decisions

- One authoritative PHP/MariaDB CMS runtime, not permanent JavaScript/PHP parity.
- Static public output and a private authoring plane.
- Qualified conventional hosting requires no Node.js, Docker, VPS, root access, or persistent service.
- Server-rendered administration with bounded native JavaScript islands.
- Bounded declarative profiles plus reviewed trusted renderers, not an arbitrary plugin system.
- A fictional reference profile reaches full product acceptance before real client migrations.
- Contributor work uses issues and pull requests; checks do not authorize merging, publication or deployment.
- Cloudflare and every external provider remain optional adapters.
- Verified journaled publication is the portable baseline; atomic origin activation is an optional qualified delivery capability, not a hosting prerequisite.

## Current contracts and behaviour

- [Product contracts](product-contracts.md) define profiles, records, forms,
  browser data, compiler snapshots, static artifacts and exports.
- [Administration experience](administration-experience.md) describes the
  editing interface, branding and supported visual editing.
- [Profile authoring](../development/profile-authoring.md) explains the site
  package and rendering contracts.
- [Publication and recovery](../development/publication-workflow.md) describes
  activation, scheduling, rollback and the public content feed.

## Decision records

[Accepted architecture decisions](decisions/README.md) preserve the context,
tradeoffs and boundaries of earlier choices. Their dates and status matter:
an accepted design does not mean every capability it describes is implemented.
Current operating guides document supported behaviour.

## Area contracts

Every top-level implementation area has a README describing ownership,
exclusions, allowed dependencies and focused checks. Update an area contract
when responsibility changes, not for routine implementation detail.

Use the [documentation index](../README.md) to find guides for building,
operating or contributing to Portable CMS.
