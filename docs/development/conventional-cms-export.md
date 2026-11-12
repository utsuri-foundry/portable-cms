# Export content and restore an empty installation

Use a normalized export to move editable content, retained revisions and media
between compatible installations. It is not a complete installation backup.
Choose the procedure that matches the destination:

| Task | Guide |
| --- | --- |
| Move a complete content package to an empty installation | [Portable handover](portable-handover.md) |
| Export larger retained histories | [Chunked export and capacity](export-capacity.md) |
| Add current content to an existing installation | [Content import](content-import.md) |
| Inspect the export contract or package a v1 download | Continue below |

## Download an export

The conventional CMS exposes one administrator-only normalized export at `/exports`. A `GET` renders an explanatory native form. Its same-origin, CSRF-protected `POST` reads one repeatable MariaDB snapshot, validates it, appends an attributed audit event in the same transaction, and returns `portable-cms-export-YYYYMMDD-<opaque-id>.json` as a `no-store` attachment.

The structural contract is [`portable-cms-export-v1.schema.json`](../../schemas/portable-cms-export-v1.schema.json). PHP also enforces graph rules that JSON Schema cannot express conveniently:

- every record names a revision in the same export as its current revision;
- revision IDs and each record's revision numbers are unique;
- relationships resolve source and target records and identify the retained current or published source revision;
- every nonnull media owner resolves a record; an explicit null owner means a website-owned raster image;
- derivative variants are unique per media record; and
- collections, nested JSON, and the final encoded document are bounded.

The required `ownerRecordId` field accepts null for website-owned JPEG, PNG and
WebP images in v1 and v2 exports and frozen snapshots. Omitting the field remains
invalid. Existing string owners retain exactly their previous meaning; restore
does not promote old images automatically. Media references, checksum inventories
and Website settings keep the same IDs. Imports into existing installations
transfer website originals through the ordinary upload/processing pipeline
without a record dependency. Full restore and PHP-only database backup preserve
the explicit null owner, including completed upload receipts.

## What an export contains

Both formats preserve:

- optional installation-owned CMS terminology (current revision and labels,
  independent of public navigation and route identity);
- optional installation-owned site languages (the state of each supported
  language and the primary language), present once the choice has been saved;
  it lists the languages added within the profile's envelope after the
  profile's own, as `profile.locales` does. Restore validates it against the
  installed profile, installs the added languages again and checks records
  against its public languages;
- optional installation-owned site wording (the texts that differ from the
  profile's defaults, in every language including those that are off), present
  once it has been saved; restore reads it strictly against the installed
  profile and keeps its revision. After the owner deletes the text of a
  language that is off, current revisions, media text, wording and holding
  settings carry no values in it, while earlier revisions keep theirs;
- optional validated `sourcePublicUrlContext`, publication-origin provenance
  only, never destination operator configuration;
- optional verified published-slug history, preserving release and route
  provenance without promoting a release or activating redirects on import;
- revisioned website design registries, settings and localized metadata stored
  in ordinary record revisions, including portable sharing-image references;

- export identity and UTC creation time;
- installed profile ID, version, and ordered locales;
- every generic record with workflow and current-revision identity;
- every retained revision with profile and actor provenance, including the current editing revision as captured by the consistent export; private editing-session metadata is not exported;
- the rebuildable current and published relationship projections;
- original media MIME, byte size, and SHA-256 identity; and
- derivative filename, MIME, geometry, byte identity, processor/version, normalized recipe, and creation time.

Rows and arrays are ordered explicitly before serialization. Repeating an export against unchanged authoritative content therefore produces identical normalized collections; only the export identity and creation time may differ.

## Deliberate exclusions

The document contains no database DSN, filesystem root, absolute path, object-storage key, provider media URL, session, cookie, upload session, job lease, credential, or secret. Its optional public publication URL is bounded operator-owned provenance, not a storage address or credential. Media bytes are not embedded. The UI states this clearly because a normalized export is a portability contract, not a complete backup by itself.

Export is permitted to the owner and administrators, never editors. Normalized
restore does not copy source accounts, password hashes, roles or authentication
sessions. Bootstrap the destination owner first, then use its `/accounts` page
to recreate administrators and editors with their own temporary passwords.
Retained source actor IDs are provenance only and cannot authenticate. A full
installation backup is deliberately different: it retains local accounts and
sessions for recovery of that same installation, not normalized migration.

An operator preserves private originals, derivatives and uploaded fonts in a checksum-addressed package. The [isolated restore workflow](#isolated-restore-workflow) consumes both the normalized JSON and that byte inventory. This is a content portability bundle, not a full installation backup: it excludes accounts, authentication state, old audit events, release activation and job recovery. The restore executor never installs the source publication URL into destination configuration. Adoption is an explicit operator choice and a new exact build, never rewriting a frozen artifact's canonical or sharing URLs.

## Validation boundary

PHP performs bounded domain and graph validation before the response or audit commit. The Draft 2020-12 schema remains the structural source of truth and executes in the shared repository contract tests through the already-reviewed PHP validator. Validation uses the bundled runtime dependency.

Failures roll back the audit mutation. Legacy capacity failures return an
explanatory 422 form directing the operator to chunked capture; unexpected
failures retain the correlation-safe generic error page. Export bodies and
private storage details must never enter application logs.

## Isolated restore workflow

Restore is an operator action, not an editor upload or public HTTP route. Both tools ship in the conventional bundle and run with PHP CLI through a hosting control panel or an operator session. They do not invoke a shell, require Node/Composer, fetch providers or execute imported content. The operator must have authorized access to the private installation configuration. Apply additionally identifies an existing active destination administrator by login name.

1. Download `/exports` from the source CMS. Stop editorial writes while capturing its media, or retain the immutable bytes named by that export. Do not delete referenced media during packaging.
2. Prepare a new empty private package directory with owner-only permissions. Keep the JSON, package, source media and destination media outside the explicitly supplied document root.
3. Run the source bundle's packaging tool. The following paths are illustrative, not hosting defaults:

```sh
php tools/conventional-export-package.php \
  --config=/account/private/cms.json \
  --public-root=/account/public_html \
  --export-json=/account/private/download.json \
  --output=/account/private/export-package
```

The result is `export.json` plus flat `blobs/<sha256>` files. A source file must have the expected byte size, SHA-256, MIME and, for derivatives, geometry. Font headers must satisfy the existing sanitized WOFF2 contract. Package fonts remain in the independently verified profile package; uploaded fonts travel in the inventory. `export.json` is written last. An interrupted package is incomplete, never a successful backup; make a fresh empty directory rather than overwriting it.

4. Copy the package privately to an isolated destination. Install the same independently verified profile version and ordered locales, configure its own database, administrator, secrets and public URL context, and apply the bundle's forward migrations through the existing migration operator. Restore checks the entire migration ledger and checksums; it never automatically migrates an unexpected database.
5. Run a read-only dry run:

```sh
php tools/conventional-restore.php \
  --config=/destination/private/cms.json \
  --public-root=/destination/public_html \
  --package=/destination/private/export-package \
  --administrator=admin@example.test
```

Successful output reports `compatible`, collection/file/byte counts, an `exportSha256` and provenance notes. Missing or changed blobs return `blocked` with at most 32 checksum/reason entries. Incompatible profiles, unsupported historical versions, malformed bodies, unknown locales, invalid typed relationships, mismatched projections, unfinished media jobs, unsupported video containers or recipes, occupied destinations and unsafe paths reject with stable reason codes. Nothing is merged, repaired, guessed or silently coerced. Provide an explicitly reviewed migration outside this first-version workflow if a profile or contract differs.

6. Append `--apply --confirm=<exportSha256>` to the same command. It prepares at most eight files by default (`--maximum-files=1..64`). `preparing` exits **2** and is incomplete. Repeat the identical command until it reports `restored` and exits **0**. Repeating a completed intent verifies its private files and reports `already_restored` with exit **0**, without replaying inserts. Blocked or rejected results exit **1** and require investigation before retrying.
7. Create a new exact preview through the ordinary destination CMS. Review its content and generated metadata. Publication is a separate authorized operation; restore neither publishes nor imports an activation receipt.

## Restore command exit statuses

`conventional-restore.php` uses the same stable contract in the repository and
installed bundle:

| Exit status | JSON `status` | Operator action |
| --- | --- | --- |
| `0` | `compatible` | Dry run succeeded; no restoration was applied. |
| `0` | `restored`, `already_restored` | Restoration is complete and verified. |
| `2` | `preparing` | Incomplete, resumable preparation; repeat the identical intent. |
| `1` | `blocked`, `rejected` | Failure; investigate the report before retrying. |

JSON result reports remain on stdout, including `preparing` and `blocked`;
rejections remain bounded JSON on stderr. `--help` exits 0 with plain-text help.
The checksum-confirmed apply protocol is unchanged. Resume with the same
configuration, package, destination administrator, confirmation and file limit.
Automation must handle exit 2 explicitly: ordinary shell success during apply
means the restoration is complete, and `set -e` stops on incomplete preparation
as it does on any non-zero status. A failed or blocked command is not an
instruction to retry blindly. Stopping after an incomplete result retains the
verified files and journal for the next identical invocation.

## Recovery and provenance

The destination must contain no records, revisions, relationship index, media, jobs, upload sessions, previews, saved design settings, terminology, site languages or site wording. Existing identities, sessions and installation audit events are permitted and are not replaced. Existing content files and unowned partial stages are refused even if their bytes happen to match. Empty storage directories and the existing font-store lock are harmless.

A private filesystem lock serializes execution on the selected media root. A durable singleton database journal binds the export ID, exact envelope checksum, verified profile checksum, destination-root identity and destination administrator. A different intent or root cannot take over it. Matching retries reconstruct work from that immutable intent and verified files.

Files are streamed into restore-owned private stages, flushed, checksum checked and committed without replacing existing canonical files. This adapter requires PHP's `link()` to be available and permitted within the private storage filesystem for its atomic no-replace commit; missing capability fails closed, and real-host acceptance must exercise it. Partial owned stages can be discarded and recopied on retry. Only after all private bytes verify does one serializable transaction recheck the empty destination, insert content/history/index/media/registries, append completion audit and mark the journal complete. Range locks fence concurrent content/settings writes during that commit. A database rollback leaves no imported rows and retains the private verified files and preparing journal for recovery. Do not remove the journal or manually repurpose a partially restored installation; investigate it or use a different clean installation.

Source actor IDs are stored as authorship provenance, not as authentication users. Author foreign keys point to the authorized destination administrator. A later normalized export preserves source creation and historical authorship; after a destination edit, the new revision and `updatedBy` attribute the real destination editor. No source password, session, role or secret is imported. Source public URL context and published-slug history live in private provenance only; they are never wired into destination preview or publication services. A context-free re-export retains that provenance, while an explicitly configured export uses its own operator context rather than activating the source history.

The export includes all retained content revisions but only the current design/terminology documents, not their previous library revisions. Removed record history is excluded; restore accepts gaps in original revision numbers and does not recreate pruned bodies. The installation-local record-history limit is not exported. Restore keeps design/terminology revision identity and creates that current history checkpoint; it does not fabricate missing older checkpoints. Administration-label files and package fonts remain profile-package assets. Media marked `needs_attention` keeps that status with a safe restored diagnostic; queued/running media is refused because its processing jobs were deliberately not exported.

## Bounds and proof

The [current capacity matrix](export-capacity.md#supported-envelope) distinguishes
the legacy download from the larger measured chunked workflow. V1 admits at most
500 total records, 5,000 retained revisions, 16,000 current relationships, 5,000
media records and 20,000 derivatives, together with its 32 MiB JSON ceiling.
These are installation-wide totals, not limits per type, language or collection
page. Drafts, archived records, taxonomy terms and settings count too. Each saved
revision contains its full body, not just a text diff, so history can exhaust
bytes before a count ceiling. Oversized content is rejected, never silently
truncated or pruned. Use v2 when v1 no longer fits; v2 still has finite independent
limits and needs a qualified host.

The legacy v1 envelope retains its 32 MiB cap and collection limits. Draft 2020-12 validation has an export-specific 500,000-node bound without raising ordinary form/profile validation limits. Loading requires at least 64 MiB available PHP memory and a conservative sixteen-times-envelope allowance. Each blob is at most 128 MiB, except prepared-video originals and derivatives, which can reach 256 MiB; at most 25,032 destination files and 8 GiB total destination bytes are accepted. Video selections still obey their profile field limits. Streaming uses 128 KiB chunks, checks an injected 180-second filesystem budget and leaves interrupted work recoverable. The database commit has the export's finite row bounds. A process failure is not a completed restore; disaster recovery and machine/power-loss guarantees still require independently verified backups.

The repository gate proves a real source MariaDB export, checksum media/font package, read-only dry run, interrupted stage, injected SQL rollback, resumed destination restore and byte-equivalent fresh preview. A different destination publication context intentionally changes canonical/share URLs, without activating source redirects. Legacy absent SEO overrides, partial optional translations, locale-specific indexing policy, sharing image bytes, fonts/design, historical authorship and later destination edits are covered. Qualify the actual destination using the [installation checklist](reference-host-acceptance.md); local tests do not qualify a host.

Source packaging resolves each original's private storage key from the source
installation's `cms_media` row, then verifies the export's checksum and byte size
before copying. A native upload stores its original under an upload-session ID;
that private key is deliberately absent from normalized content. Restore still
places the verified blob under the canonical media ID. The packaging operator
uses a prepared, read-only database lookup and rejects absent or unsafe mappings.

## Responsive image derivatives

Original checksum-addressed media remains the portable source. The envelope
also retains every available 480/960/1600/2400-pixel WebP derivative's dimensions,
checksum and recipe provenance; the package carries those verified bytes.
Restore preserves available files and their frozen identities. The destination's
qualified media worker regenerates missing widths from originals through bounded
leased jobs, including legacy exports with only the 960-pixel default. It never
replaces existing immutable derivatives or changes already-saved preview bytes.
Derivatives are reproducible delivery artifacts rather than canonical content;
regeneration on a different reviewed ImageMagick version may yield new bytes,
so retain the exported originals and existing derivative checksums together.

## Site sequence

New normalized exports carry `siteSequence`, the site's committed high-water
mark captured in the same repeatable-read snapshot as the content. Revisions
that were sequenced also retain their optional positive `siteSequence`; legacy
revisions omit it. PHP rejects duplicate revision positions, positions above the
high-water mark and integers outside the exact JSON range. Old exports with no
sequence metadata remain valid and do not acquire invented history.

Restore preserves those stamps and atomically sets the counter to the greater
of the imported value and the destination's existing high-water mark. An
interruption or SQL failure rolls this back with the content. The first later
edit receives a larger number. `cms_site_changes` is transient and excluded from
normalized exports; restore sets `pruned_through` to the retained counter and
imports no history. Consumers must begin with a full consistent read on a
restored installation, not reuse another installation's change cursor. Source
release identities remain provenance and are not activated at the destination.
