# Product contracts

Portable CMS has one bounded contract family between trusted profiles, the PHP engine, native browser modules, the compiler, and external storage or deployment adapters. Each document declares its own `formatVersion`. A profile also declares its semantic package version and the engine and contract versions it supports. Changing one contract does not silently change another.

## Contract family

| Contract | Authority | Purpose |
| --- | --- | --- |
| profile v1 | `portable-cms-profile-v1.schema.json` plus PHP semantic validation | Installed content model, navigation, renderers, assets, capabilities, privacy, and compatibility |
| record v1 | `portable-cms-record-v1.schema.json` | Current record projection, session-grouped and sealed revisions, localized slugs, workflow, and typed relationships |
| form protocol v1 | this document and server integration tests | Native HTML create, edit, validation, and conflict behavior |
| JSON protocol v1 | `portable-cms-protocol-v1.schema.json` | Errors, validation results, conflicts, pagination, mutation receipts, and jobs for bounded islands and operators |
| snapshot v1 | `portable-cms-snapshot-v1.schema.json` plus compiler graph validation | Frozen records, exact profile package, and immutable media inputs |
| artifact v1 | `portable-cms-artifact-v1.schema.json` plus publication verification | Exact static files, routes, redirects, media, hashes, and compiler provenance |
| search v1 | `portable-cms-search-v1.schema.json` plus the browser decoder | Static per-locale documents and weighted term postings |
| capabilities v1 | `portable-cms-capabilities-v1.schema.json` | Installation class, available features, limits, and stable remediation codes |
| export v1 | `portable-cms-export-v1.schema.json` plus export graph validation | Portable content history and media provenance |
| administration labels v1 | `portable-cms-administration-labels-v1.schema.json` plus profile reference validation | Optional administration-only names per interface language |

All schemas use JSON Schema Draft 2020-12, prohibit unknown properties, set collection and text bounds, and resolve references only within their own document. Structural acceptance is necessary but not sufficient. PHP domain constructors and services enforce cross-document references, uniqueness, state transitions, authorization, and transactional invariants.

## Profiles

Profile format v2 adds an explicit bounded `targetTypes` alternative for one
or many record references. Its values carry both `typeId` and `recordId` in
revision bodies and preserve one order across target types. Profile v1 and v2
`targetType` declarations keep their existing single-type values. Taxonomy
references continue to target one taxonomy namespace. The reusable PHP contract
validates typed identity and bounds; content services check target existence,
and the frozen graph verifies publication eligibility and projection agreement.

A profile manifest is data, not executable code. It may select a reviewed renderer identity and packaged asset path, but it cannot embed callbacks, templates, SQL, PHP, JavaScript, CSS, shell fragments, or provider credentials.

The v1 profile model has three record cardinalities:

- collections for repeatable editorial records;
- singletons for one installed record such as Home, About, or site settings;
- taxonomy namespaces for reusable terms that may themselves carry bounded fields.

Each type chooses one finite archetype and an ordered set of engine-owned fields. The supported field set is short text, multiline text, integer, decimal, boolean, date, enum, rich text, web addresses, media references, one video reference, one or many typed record references, taxonomy references, one bounded group, one bounded repeatable group, typed blocks, Vimeo references and composition data. Nested groups accept leaf fields only, so recursive schemas cannot be expressed. Rich text and composition are bounded structured data with allowlisted marks, nodes, row modes, and limits. They never contain arbitrary markup.

The additive `web_address` field stores a web-address-v1 document or `null`.
It supports localized values and group leaves. Its exact nonempty document is
`{formatVersion: 1, url: string, linkText: string}`; the label may be empty.
Profile settings require `maximumLength` between 1 and 2048 UTF-8 bytes.
PHP additionally enforces absolute credential-free lowercase HTTP/HTTPS schemes,
valid UTF-8, no whitespace/control characters or markup in the address, and a
single-line plain-text label of at most 200 Unicode characters. It does not fetch
the address. Native forms preserve invalid input, missing optional controls retain
saved values, and clearing all controls deliberately stores an empty value.
Canonical export/restore validation rejects lossy or unknown locale mappings.
Frozen snapshots recheck the same semantic policy before rendering. Profiles use
`WebAddressLink` for engine-validated, context-escaped anchors; unrelated URL
contexts keep their existing policy. Relative, `mailto:` and `tel:` links are
not supported by this field in v1.

Relationship targets must resolve to an installed type of the correct cardinality. Navigation targets and renderer identities must resolve within the same profile. Locale IDs are unique and one installed locale is the default. Required and optional capabilities cannot overlap. Asset paths are relative, traversal-free package paths.

Compatibility is checked before a profile object is returned. The engine version must fall within the declared minimum and exclusive maximum. Every named contract version must equal a version implemented by the engine. An incompatible profile cannot reach a repository or mutation service.

### Administration labels v1

A profile may ship `administration-labels.json` beside `profile.json` with names for types, fields (including group leaves), enum options, repeatable group item labels (`items`, keyed by the group path) and navigation in any of the five interface languages (ca, es, en, fr, de). Every path must resolve to an installed element of the same profile and every name is bounded plain text, so the file can rename but never add, reorder or restructure anything. The labels are administration presentation only: they sit outside the package checksum, the compiler input, snapshots, artifacts and exports, so adding or editing them never changes public bytes or provenance.

For each interface language a name resolves in this order: a per-language Content names entry, an installation rename (a saved base name that differs from the profile default), the profile label for that language, then the profile default in `profile.json`. Stable IDs, enum values, routes and renderers never change.

Existing profiles need no migration: without the file every name stays the profile default. To adopt it, add the file with only the languages and paths you want to translate; missing entries fall back individually. A labels file that names an unknown language, element or profile fails closed when the package loads.

Reference Studio is the executable breadth fixture for these contracts. See
[profile authoring](../development/profile-authoring.md) for practical examples
and the [schemas index](../../schemas/README.md) for all supported format versions.

### Rich text v1 and v2

`portable-cms-rich-text-v1.schema.json` describes one document. A localized
field maps installed locale IDs to documents; an absent optional translation
may remain null in an imported snapshot. Documents have exactly
`formatVersion: 1` and ordered `nodes`. Paragraphs and headings contain text
runs with exactly `text` and `marks`. Lists contain `list_item` nodes whose
children are text runs. Nested lists and arbitrary attributes are unsupported.
Marks are `bold`, `italic`, `underline`, or `{type: "link", href: "…"}`.

`portable-cms-rich-text-v2.schema.json` adds the string mark `smallCaps`, with
`formatVersion: 2` and at most five unique marks per run. The v1 schema and its
valid bytes remain unchanged; a v1 document cannot contain `smallCaps`. The
runtime accepts both versions, preserves the input version on validation,
and native row serialization chooses v2 when any run contains the new mark.
Removing its last occurrence returns native content to v1 without altering
letters or the other marks. No database migration or export-envelope change
is needed: normalized export/restore preserves each versioned document.

Profiles opt in through the field's `settings.marks`. The mark compiles as
`<span class="cms-small-caps">` with escaped original text; profiles own its
stylesheet and an appropriate licensed font. Neither inline styles nor uppercase
substitution are allowed. Search, excerpts and assistive technology read the
original letters. Reference Studio inherits the typography role's retained
Source fonts with the `smcp` and `c2sc` OpenType features. The shared composition
renderer supplies the same mark styling in its script-free editor preview.

The PHP `RichText` boundary enforces the profile's declared blocks, marks and
total node count, counting blocks, items, and runs. Each run permits 10,000
Unicode characters; one serialized document permits 1 MiB. Control characters,
unknown keys, duplicate mark kinds, nesting, and empty lists fail closed.
Links must be credential-free lowercase HTTP or HTTPS URLs, or root-relative
paths beginning with one slash, with no whitespace, backslashes, or markup
delimiters. Protocol-relative URLs and executable schemes are rejected.

Serialization fixes key and mark order, normalizes CRLF, and joins adjacent
runs with identical marks within the run bound. Native form rows preserve
block order; consecutive list rows become items in one list. The browser
submits named text, block-type, mark, and link controls through the native form.
It never supplies trusted HTML. Structure buttons return an unsaved form with
the submitted revision intact. They do not persist content. Tail markers
detect PHP input-variable truncation. The HTTP request limit is 4 MiB; hosts
with smaller POST or input-variable limits reject incomplete forms rather than
saving partial records.

The enhancement formats selected text or selected blocks, previews semantic
content, and owns bounded local undo/redo. Copy within the editor carries
bounded semantic clipboard data plus plain text. Paste accepts declared
portable text blocks; other clipboard formats normalize to plain text without
reading HTML or loading remote resources. The server revalidates all clipboard
content on save. No-script forms expose ordered blocks and text segments with
native add, edit, remove, and move controls. The frozen-snapshot boundary uses
the same validator, and trusted profile views carry validated `RichText`
objects into the escaped semantic renderer.

## Records and workflow

Record IDs, revision IDs, actor IDs, media IDs, and release IDs are opaque 128-bit lowercase hexadecimal values. Profile IDs, type IDs, renderer IDs, capability IDs, and error codes use canonical identifiers. Timestamps are RFC 3339 date-time strings.

A current record projection points to its current revision; an automatic editing session may update that unsealed revision until inactivity, an intervening writer, publication or exact-preview capture seals it. Every committed edit receives a new site sequence, including within a grouped revision. Published and previewed revisions are immutable. The projection contains materialized slugs and relationships. The revision body remains authoritative. Relationship indexes are rebuilt transactionally from that body. Every target must exist, have the declared target type, and be legal for the source field. Duplicate localized slugs, relationship positions, and record IDs are rejected by domain validation even when JSON Schema cannot express the graph rule.

The initial workflow states are `draft`, `published`, and `archived`. A service owns legal transitions, authorization, audit attribution, and optimistic revision checks. Adapters must not update workflow state directly.

Published selects one saved revision for the next static release; it does not mean
the current website is live with that record. The record's current revision may
advance independently. Publish changes validates and selects it; ordinary saving
preserves the published pointer. Relationship projections retain both heads.
Transitions preserve the body,
advance the revision used for optimistic authority and retain earlier history.
Publish validation checks publication-required fields, related records and
ready supported media. Actual website activation is a separate release service.
Confirmed discard appends a clean revision containing the published body; Draft
and Archived states have no published pointer. Normalized exports retain both
heads, and legacy exports select the current revision for Published records.

## Native HTML form protocol v1

Administration pages are server-rendered HTML. JSON is reserved for bounded islands, jobs, and operator tooling.

```text
GET  /records/{type}
GET  /records/{type}/new
POST /records/{type}
GET  /records/{type}/{id}
POST /records/{type}/{id}
```

Every mutation includes a session-bound `_csrf` value. Updates include `_revision`. Retry-safe operations may include `_idempotency`. JavaScript islands send the same CSRF value in the request header defined by the HTTP adapter.

The response contract is:

- success: `303 See Other` to a canonical GET route;
- invalid submission: `422 Unprocessable Content`, with the complete escaped form, submitted values, field issues, and an accessible error summary;
- stale revision: `409 Conflict`, with the editor's submitted values preserved, current saved values shown separately, both revision numbers, and an explicit resubmission action;
- unauthenticated, unauthorized, origin, and CSRF failures: safe stable responses with no submitted or secret values reflected.

Templates must use the engine output boundary for text, attribute, and URL contexts. Raw content echo is forbidden. Form limits and required states come from the same validated profile definitions used by the service.

## Bounded JSON protocol v1

Every JSON message has a format version, discriminating `kind`, request ID, and bounded payload. The protocol defines safe errors, validation issues, revision conflicts, cursor pages, idempotent mutation receipts, and job states.

Error codes and remediation codes are stable canonical identifiers. Human messages are safe summaries, not exception strings. Unexpected failures expose a correlation ID while server logs retain redacted diagnostics. Cursor values are opaque URL-safe tokens. Clients must not parse or construct them. Page size is at most 100 and a cursor is scoped to the authenticated query that issued it.

## Frozen snapshots

A compiler receives one immutable snapshot rather than querying live repositories during rendering. The snapshot pins:

- snapshot identity and creation time;
- profile ID, semantic version, package hash, locales, and default locale;
- exact record revision IDs and numbers;
- typed relationship positions;
- exact immutable media variants, byte sizes, dimensions, MIME types, and hashes.

The snapshot service must reject duplicate IDs, missing relationship targets, media owned by absent records, default locales outside the locale list, changed immutable media, and records whose profile or workflow state is not eligible for the requested preview or release. A snapshot is complete before compiler work begins.

## Static artifacts

Snapshot and artifact v1 accept an optional boolean `includeDrafts`, default
false. True adds saved drafts to the preview selection without changing their
original workflow states; archives remain excluded. It is frozen with the
snapshot and copied into the generated artifact manifest. A true marker is
review-only and must be rejected by publication, independently of build success.

A snapshot lists the languages it publishes, an ordered subset of the profile's
languages with the installation's primary language as default, and carries no
values in other languages. An optional `preparationLocales` list, frozen with the
snapshot and copied into the artifact manifest, marks languages drawn for review
only; publication rejects it like `includeDrafts`.

The compiler writes a new isolated artifact and a manifest. Every file has a safe relative path, MIME type, exact byte size, SHA-256 digest, and cache policy. The manifest pins the snapshot, profile package, compiler build, search format, redirects, and public media mappings.

Reusable compiler orchestration validates the frozen graph, owns isolated
filesystem output, copies verified package assets and immutable media, and
verifies the finished tree. A trusted profile builder alone interprets profile
field IDs and constructs typed renderer views, localized routes, search
weights, and media selections. The engine therefore compiles any accepted
profile adapter without learning Reference Studio content or presentation.

Paths, routes, and redirect sources are unique. Redirect targets must resolve directly to an artifact route, without chains, cycles or sources occupied by current routes. Public media must resolve to a manifest file with the same hash. Verification hashes files from storage before promotion. A served-origin request is a smoke check, not the source of truth. Publication never mutates a verified artifact in place.

Optional route identities and a hashed public-delivery descriptor declare the
inputs a publication target must verify and apply. Published-slug history changes
only after a trusted verified activation, never after a draft edit or preview.
Snapshots freeze this history; normalized exports preserve its provenance but do
not activate it at a destination. The Apache publication adapter verifies the
complete target and applied rules, authenticates a scoped receipt, and
compare-and-sets activation with audit and history. The immutable source
artifact is separate from the journaled, in-place public target; interruption
restores the preceding verified artifact. See [publication and recovery](../development/publication-workflow.md)
for target limitations and qualified-host acceptance evidence.

Publication integrity and activation mode are separate contracts. Standard
journaled delivery is the portable baseline and may expose mixed approved
releases during apply or recovery. Atomic origin activation is optional and
requires a qualified staged-release selector; the current Apache adapter does
not implement it. Missing optional atomic support does not make an otherwise
qualified installation unsupported. An explicitly selected atomic adapter must
fail closed rather than silently downgrade. Content, snapshots and exports
must not acquire provider-specific activation authority. See
[portable publication capabilities](decisions/0007-portable-publication-capabilities.md)
for the accepted direction, privacy boundary and qualification gates.

## Static search

Each locale receives one search index. Tokens use `unicode_nfkd_accent_fold_lower_words_v1`, matching the shared PHP and browser normalization fixtures. Documents have unique IDs. Terms are unique and sorted by token code-point order. Each posting resolves to a document and is ordered by weight descending, then document ID ascending. A document appears at most once per term.

Image results require a media ID. Text results must not carry one. The browser treats the downloaded index as hostile input and runs `decodeSearchIndex` before constructing any runtime lookup structure. The decoder accepts the same tracked fixture validated by PHP and rejects unknown fields, duplicates, dangling IDs, invalid media relationships, and non-canonical order.

## Capabilities and compatibility

An installation capability document classifies the runtime as `standalone`, `managed_service_required`, or `unsupported`. Each known capability states whether it is baseline, required by the installed profile, or optional; whether it is available, degraded, or unavailable; bounded numeric limits; and an optional stable remediation code.

A profile cannot install or activate when a required capability is unavailable. A degraded capability must be visible to the administrator before related work begins. A managed external service may add optional heavy behavior, but it does not silently satisfy the standalone baseline.

Readers reject unknown format versions. Writers emit one canonical version at a time. A future format needs a new schema, fixtures, compatibility declaration, and migration or dual-reader plan. Version checks, structural validation, and semantic construction all complete before persistence, publication, or job creation.

## Fixture policy

[`contract-cases-v1.json`](../../schemas/fixtures/contract-cases-v1.json) is the executable catalog. It includes accepted, schema-rejected, and domain-rejected documents. PHP executes every schema case. Browser formats also have a native decoder test over the same accepted bytes. Rejected cases cover unknown executable-shaped properties, recursive groups, incompatible engine ranges, and unsafe artifact paths. Generated production data must add regression fixtures when a new invariant or prior defect is identified.

## Website design registries v1

Profiles may enable optional revision-zero font, typography, responsive and
spacing defaults through the bounded design schema. IDs are stable; labels and
values are editable native-form settings. Registries contain no executable
paths or style text. Semantic validation orders thresholds, validates sparse
overrides and proves spacing curve order across the supported viewport range.

Registry writes are attributed compare-and-set revisions. Normalized exports
retain complete current registry/font facts; immutable sanitized font objects
travel separately by checksum. Frozen snapshots carry exact documents, and
release manifests pin their revision and canonical checksum. Reviewed compiler
contracts emit all public design CSS. See the
[implementation and host capability contract](../development/website-design-registries.md).

## Optional AI image editorial candidates

`portable-cms-ai-candidate-v1.schema.json` is the versioned plain-text provider
boundary. Description, caption, contextual alt and taxonomy labels are separate
localized suggestions. PHP checks exact frozen locales, byte/character limits
and forbidden markup. Suggestions enter native forms only through an explicit
human action, remain unsaved, and never create taxonomy terms automatically.
Private job provider/model provenance, context and credentials never enter
records, normalized exports or public artifacts. See
[the assistance contract](../development/ai-editorial-assistance.md).
