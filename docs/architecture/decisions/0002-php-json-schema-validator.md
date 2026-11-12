# Decision: PHP JSON Schema validator

Date: 2026-10-01

Status: accepted for the portable CMS runtime

## Context

Portable CMS profiles and exchange documents use JSON Schema Draft 2020-12 as their structural contract. PHP must execute that contract directly. Reimplementing every structural rule in PHP would create a second hand-written schema and make drift likely. The existing JavaScript runtime remains unchanged: its hand-written validators are checked against schemas and shared fixtures, so this decision adds no JavaScript runtime dependency.

The validator runs on inexpensive shared hosting and processes trusted, package-owned schemas against bounded content. It must not resolve network or filesystem references, enable implementation-specific schema behavior, expose submitted values in errors, or require Composer on the customer host.

## Decision

Adopt `opis/json-schema` 2.6.0 through a Composer lockfile. Use `Opis\JsonSchema\CompliantValidator`, which disables Opis-specific filters, mappers, templates, globals, defaults, slots, pragmas, data keywords, and custom keyword validators. The first executed contract is `portable-cms-profile-v1.schema.json`.

Composer is a development and release-packaging tool only. The conventional-host bundle contains the optimized autoloader, three locked production packages, and required schemas. Installation and updates copy that complete bundle; the customer host never runs Composer and does not need outbound package-registry access.

The runtime boundary enforces:

- Draft 2020-12 declared exactly by every executed schema;
- local document fragments only for `$ref` and `$dynamicRef`;
- no registered protocol or prefix resolver;
- explicit rejection of known Opis extension keywords before execution;
- 1 MiB document and 256 KiB schema byte ceilings;
- JSON depth 64 and 50,000 traversed nodes;
- at most eight projected validation issues;
- issue output limited to bounded JSON paths, standard keywords, and generic messages that never interpolate submitted values.

The schema describes structure. PHP services continue to enforce graph and transition rules such as unique IDs, installed relationship targets, taxonomy target kinds, immutable revisions, and authorization.

## Locked dependency graph

| Package | Version | Source commit | License |
| --- | --- | --- | --- |
| `opis/json-schema` | 2.6.0 | `8458763e0dd0b6baa310e04f1829fc73da4e8c8a` | Apache-2.0 |
| `opis/string` | 2.1.0 | `3e4d2aaff518ac518530b89bb26ed40f4503635e` | Apache-2.0 |
| `opis/uri` | 1.1.0 | `0f3ca49ab1a5e4a6681c286e0b2cc081b93a7d5a` | Apache-2.0 |

The application requires PHP 8.2 or newer plus `iconv`, `intl`, `json`, and `mbstring`. `composer audit --locked` reported no advisories and no abandoned packages on 2026-10-01. The repository lockfile and Composer integrity metadata are authoritative; release builds use distribution archives and allow no Composer plugins.

Primary evidence:

- [Opis JSON Schema documentation](https://opis.io/json-schema/2.x/) declares Draft 2020-12 support and Apache-2.0 licensing.
- [Compliant validator documentation](https://opis.io/json-schema/2.x/php-validator.html) distinguishes standards-only validation from optional Opis behavior.
- [Schema loader documentation](https://opis.io/json-schema/2.x/php-loader.html) shows that protocol resolution is registered explicitly; this runtime registers none.
- [JSON Schema Draft 2020-12](https://json-schema.org/draft/2020-12) is the selected specification.

## Alternatives considered

### Keep hand-written PHP structural validation

Rejected as the authority. It duplicates schemas, makes exact Draft behavior untestable, and turns every structural contract change into a manual parity exercise. Small semantic constructors remain useful after schema validation.

### Write a local Draft 2020-12 engine

Rejected. Correct reference, applicator, annotation, and unevaluated-property semantics are disproportionate to the product and security value.

### Use a validator without verified Draft 2020-12 support

Rejected. Supporting an earlier draft would either weaken the contract or require schema dialect forks. A candidate becomes eligible only with current upstream support for Draft 2020-12, a reviewable dependency graph, maintained PHP support, and a safe resolver boundary.

## Measurements

Measured in the pinned development shell on 2026-10-01:

- installed production graph: 843,962 bytes and 235 files;
- compressed `vendor/` plus schema payload: 129,710 bytes;
- complete Gate 0 conventional bundle: 1,350,065 bytes and 300 files;
- 1,000 warm validations of the Gate 0 profile: 1,429.711 ms total, about 1.43 ms each;
- benchmark PHP peak allocation: 4,194,304 bytes.

These figures characterize the development machine, not a universal host guarantee. The validator is invoked once when the profile is loaded for a request. Real-host smoke and bundle measurements remain part of deployment acceptance.

## Fixture and security coverage

Tests execute the accepted Gate 0 profile, reject unknown and malformed properties, reject HTTP, filesystem, and relative external references, reject Opis extension keywords and other schema drafts, bound bytes and nesting, cap issue count, and prove that a hostile submitted value is absent from projected issues. Existing profile constructors independently retain semantic and relationship checks.

## Lifecycle

- Renovation is deliberate, never an unconstrained automatic major upgrade.
- Every update must review the locked graph, upstream changelog, licenses, supported PHP range, advisories, resolver behavior, bundle size, runtime measurements, and all malicious fixtures.
- CI and release validation run `composer validate --strict` and `composer audit --locked`.
- A relevant vulnerability blocks releases until patched, mitigated, or the dependency is removed. Existing installations receive a bundled runtime update through the normal supported-version process.
- Removal remains possible because one engine-owned adapter contains all Opis calls and public domain code depends only on safe pass/fail issues.

## Consequences

- The PHP runtime gains one audited, locked package family while the public site remains dependency-free at runtime.
- Release artifacts grow by about 844 KiB uncompressed.
- PHP profiles now fail at a standards-based structural boundary before semantic construction.
- Bundle construction requires an installed lockfile graph, but deployed hosts gain no package manager, plugin execution, or remote schema resolution.
