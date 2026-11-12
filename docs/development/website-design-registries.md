# Configure typography, fonts and spacing

When a profile enables design settings, **Settings → Design** lets administrators
choose font families, text roles, responsive sizes and named spacing values.
These are bounded settings, not an editor for arbitrary CSS.

Use **Calculate specimens** to inspect proposed values without saving, then save
and check an exact preview. A release freezes the design choices with its content.
The sections below describe the registry contract and optional font processing.

## Domain and editor

`Design\RegistryDocument` validates a complete serializable document. Font,
role, range and spacing IDs are immutable native-form identities. Labels remain
editable. Roles use a selected verified font or a system fallback, bounded
weight/line height, and fixed or clamped affine size curves. Equal minimum and
maximum sizes are fixed. Optional overrides reference stable range IDs; empty
override controls inherit the previous size. Every range starts at its minimum
width and remains active upward until replaced by a later override.

The document bounds are 32 font families, 8 ranges, 16 roles and 32 spacing tokens.
V2 families contain 1 to 8 faces each. Face facts are displayed, not repeated as
editable native inputs, so even the full family inventory fits the form budget.
The complete native form fits below PHP’s default 1,000 input variables.
Thresholds are strictly increasing. Spacing tokens are strictly ordered over
240–3840 CSS pixels. Checking every curve endpoint proves ordering between
knots, in addition to the representative viewport checks.

`/settings/design` is an authenticated, CSRF-protected native multipart form.
It requires no JavaScript. Calculate specimens projects and validates submitted
values without persisting them; sizes explain the 16 px root assumption. Save
uses an expected revision. A 422 preserves submitted values and their revision;
a 409 exposes the current revision and requires a deliberate resubmission.
Browser security prevents repopulating file inputs, so rejected uploads must
be selected again. IDs and font verification/license facts cannot be rewritten
through hidden controls. Font uploads add an immutable face or family ID; existing
family labels remain editable.

## Font families (design registry v2)

V1 single-face documents remain valid, with identical canonical checksums and
compiled CSS. Ordinary saves do not upgrade them. V2 changes only `fonts`:

```json
{
  "id": "reading",
  "label": "Reading family",
  "faces": [{
    "id": "regular", "label": "Regular",
    "style": "normal", "weightMin": 400, "weightMax": 400,
    "origin": "upload", "sha256": "<sanitized SHA-256>", "byteSize": 45972,
    "sourceSha256": "<source SHA-256>",
    "provenance": "Reviewed source and version", "license": "Reviewed webfont permission"
  }]
}
```

The checksum placeholders above are explanatory, not valid input. Each face
retains all existing verified file and permission facts. IDs are unique within
the family. Styles are `normal` (roman) or `italic`; integer weights are
100-900, with equal endpoints for a static font. Faces of the same style must
have disjoint ranges; a normal 400 and normal 700 are valid, while overlapping
variable ranges are rejected. Empty families, duplicate face IDs and missing
file facts fail closed. Roles continue to reference the family through `fontId`.

Design settings list the styles, ranges and provenance under each family.
To upload, choose an existing family, or New family and its ID/label; then
name the new face, select its real style/range and supply its WOFF2 and licence
facts. This is a native multipart save, including on script-free installations.
The declarations are reviewed editorial facts, not automatic inference from
font naming. OTS verifies/sanitizes the font bytes through the existing adapter.
There is no path or arbitrary CSS upload.

Adding a face through the new form explicitly projects a v1 registry to v2 in
the new revision. Existing families and role references keep their IDs; the old
implicit normal/100-900 CSS descriptor is retained for each legacy face. This
does not claim that every legacy file contains that full weight range. Existing
face facts cannot be silently narrowed or replaced: a separately declared
family is needed for a different legacy weight arrangement. Earlier previews
and history keep their original v1 document. Older native v1 upload requests
remain supported without projection.

Generated CSS emits one `@font-face` per face with a shared `cms-<family-id>`
name and explicit style/range. V2 roles set `font-synthesis:none`: missing
styles are not fabricated, and unavailable exact weights use native nearest-face
matching. Supply genuine italics and bold weights when the presentation needs
them. V1 keeps its historical behavior. Internal face path keys use
`<family-id>:<face-id>`; they are not URLs or new public IDs.

Compilation, composition/live preview, private font delivery and normalized
export/restore all consume the same flattened face inventory. Every required
file is verified; uploaded blobs shared by several declarations are copied once
by checksum. Families, style/weight facts, licences and source/sanitized hashes
survive both normalized export formats and restore. No database migration is
needed because the complete validated document is already versioned JSON.

## Persistence and portable input

Migration `0008` adds current documents and immutable attributed revision
history. Save locks the profile row, compares the expected revision and writes
current data, history and audit in one transaction. Failed attribution or a
conflict rolls back all three. Revision zero belongs to the exact profile
package; positive revisions can be resolved from history.

Canonical registry checksums normalize object key order and numeric curve
values. Normalized exports contain the complete current registry, including
font provenance, permission facts and original/sanitized checksums. Font bytes
are separate private objects at `fonts/<sanitized-sha256>.woff2`; copy those
checksum-addressed objects with the normalized export. Audit/history remains
in the database backup. Restore must validate the document and its font bytes,
not infer labels or recreate metadata from browser filenames.

Preview creation freezes the current registry in the same consistent database
transaction as records. Frozen static snapshots carry the complete document;
older snapshots use revision-zero defaults from their exact pinned package.
The release manifest pins registry revision and canonical checksum. Compilation
resolves packaged fonts through the verified package, uploaded fonts through
`FontStore`, and fails if any required font is missing or changed.

`DesignCss` emits only the reviewed `--type-<id>`, `--space-<id>` and
`.cms-type-<id>` vocabulary, self-hosted font aliases and ordered media queries.
Labels, metadata, source filenames and editor style text never enter CSS.
Reference Studio consumes its body/title roles. Composition can consume the
same tokens without introducing another resolver.

## Optional font capability

See [ADR 0005](../architecture/decisions/0005-optional-verified-webfont-processing.md).
An operator may add this object to the existing private installation config:

```json
{
  "fontProcessing": {
    "sanitizer": "/qualified/bin/ots-sanitize",
    "compressor": "/qualified/bin/woff2_compress"
  }
}
```

Qualify executable versions, process availability, account resource isolation
and private scratch storage first. No native tools enter the customer bundle.
Without qualified executables the UI visibly disables uploads. The development
sandbox discovers the two pinned executables from the Nix shell.

Only WOFF2 is accepted. Bounds are checked before OTS; sanitized SFNT is
re-encoded and checked again before immutable private storage. Source paths,
original bytes, extended metadata and private blocks never become public
files. Sanitizer diagnostics never reach the browser. An unsuccessful settings
save may leave an unreferenced sanitized object; it remains private and cannot
enter a release without an explicit frozen font reference. Existing private
objects are retained for historical snapshots.

## Verification and fixture regeneration

The full gate includes domain/schema, no-script HTTP, hostile font processing,
compiler, bundle and real MariaDB history/conflict/export/preview-pinning tests.
Run the additional real Chromium journey against the isolated fictional sandbox:

```text
PORT=42873 nix develop -c just serve-reference-cms
CMS_BROWSER_ORIGIN=http://localhost:42873 nix develop -c node tests/browser/design-registry-journey.mjs
```

It owns a temporary headless Chromium profile and restores design values. New
attributed revisions and a sanitized test font remain in that disposable local
sandbox. It exercises narrow/zoom reflow, no-script saves and calculations,
server validation, optimistic conflicts, native WOFF2 upload and export.

Regenerate the v2 schema and reviewed local v1/v2 union embeddings with
`php tools/contracts/check-design-schema.php --write`, then refresh the derived
profile v3 and export v2 schemas with their `--write` contract tools. Regenerate reference
pins/output only after reviewing profile/compiler changes:

```text
nix develop -c php profiles/reference-studio/build.php --refresh-fixtures --output=build/reference-fixture-refresh
```

The output directory must be new. This refreshes package/design snapshot pins
and expected compiler output; it never edits asset bytes or migration history.

The full public-site lane additionally compiles the independent Reading Room
family fixture, audits it against the unchanged quality bar, and checks actual
roman/italic glyph PostScript identities in Chromium for both it and Reference
Studio. The font upload browser journey adds two sanitized faces to one family.
Run it alone with
`CMS_TEST_DESIGN_ONLY=1 nix develop -c sh tests/browser/run-editorial-journey.sh`.
The ordinary full gate always includes it and explicitly qualifies the pinned
font processors in its disposable host configuration.
