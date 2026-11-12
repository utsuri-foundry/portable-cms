# Import content into an existing installation

`tools/conventional-import-content.php` is the installed PHP operator command
for migrations and bulk authoring. It consumes the existing
[normalized export](conventional-cms-export.md) format and checksum package,
without requiring an empty destination. Run a dry run first, review its entire
JSON report, then explicitly confirm that exact plan. Import does not publish.

## Prepare a bundle

Use `conventional-export-package.php` to capture a normal CMS export and its
private checksum-addressed bytes. A migration or fictional fidelity study can
also prepare the same bounded envelope directly:

```text
content-package/
  export.json
  blobs/<sha256>
```

Both legacy v1 JSON and v2 chunked packages are accepted through the shared
validated export reader. V2 adds `chunks/<sha256>.jsonl` beside the same original
blobs; use the normal packaging operator to produce it. Full source history is
validated by the transport reader, but only current revisions enter the import
plan. The package must pass its versioned schema and normalized graph contract:
every record has a stable 32-character lowercase hexadecimal ID, a current
revision, profile ID/version, UTC timestamps and source authorship identities.
Each current relationship names that revision and a record inside the bundle.
Taxonomy terms are ordinary records of the installed taxonomy type. Current
bodies retain canonical field values, rich-text ASTs, ordered references,
composition, typed blocks, web addresses and every installed content language.
Numeric strings, unknown
fields/locales, inconsistent relationships and lossy canonicalization are
reported and skipped, never guessed into compatible values.

Media entries name an owner record and an original's MIME, exact byte size and
SHA-256. Store its exact bytes under `blobs/<sha256>`; filenames and imported
paths never choose a destination storage address. For an originals-only bulk
bundle, derivatives can be an empty array and media can use the export's
`uploaded` status with nullable detection/geometry facts. Originals still pass
the ordinary raster upload contract and verified image probing. Keep names
within that contract, rather than relying on filename sanitization to change
them silently. Never put PHP, SQL or raw HTML/CSS in place of declared content.

Assign stable source identities before the first import and retain them for
later bundles. Generate new revision identities and advance each record's
source revision number when its content changes. Keep `--source` constant for
one source lineage; it accepts 1–80 lowercase ASCII letters, digits, dots,
underscores and hyphens. An export ID is an envelope identity, not that lineage.
Never switch source names to bypass a reported conflict. Source identities
remain private import provenance; source users never become destination users.

The installed profile version and ordered supported languages must match exactly,
including languages added to the destination within its profile envelope. Turning
a language off does not remove it from that envelope. Import validates its saved
text and preserves existing destination text in off languages, as native saves do.
Prepare a reviewed migration into that contract before invoking this importer.
Install required design tokens through the ordinary destination design settings
first. Import retains destination design, terminology, site languages, site wording
and website settings. Source website identity/publication, source site sequence
and activation history are excluded. Imported revisions receive new destination
site sequence entries through the ordinary record repository. Use isolated
restore when the requirement is to preserve complete source history and library
state in an empty installation.

## Review and apply

Apply the bundle's forward migrations with the existing migration operator,
including `0024-content-import.sql`. Identify an existing active destination owner
or administrator by login; editors cannot import. Configuration,
package and private media must remain outside the explicit document root.
Illustrative paths:

```sh
php tools/conventional-import-content.php \
  --config=/account/private/cms.json \
  --public-root=/account/public_html \
  --package=/account/private/content-package \
  --source=fictional-study \
  --administrator=admin@example.test
```

Dry run creates no lock, storage directory, record, revision, audit event,
upload or checkpoint. It lists every record and original as `create`, `update`,
`unchanged`, `conflict`, `blocked`, or media `resume`, with stable reasons,
destination identities, current destination revision numbers and processing
status. The `excluded` summary names historical revisions, source workflow
states, derivatives, design, terminology, site languages, site wording, website
settings and publication provenance that this
content-only import deliberately does not adopt. All source actor/time facts
remain source data; new destination revisions use the named administrator and
the destination clock.

An existing singleton maps to its destination identity instead of creating a
second singleton. That identity change is visible and a differing singleton is
a conflict on first association. Record UUID/type collisions, incompatible
identity associations and unavailable media or graph targets are blocked.
Missing or changed originals are reported per item. Invalid records can block
dependent new graph nodes; valid independent records can still be imported.

Append `--apply --confirm=<planSha256>` to the exact command. Source bytes,
profile package, private destination, administrator, design and reviewed
destination revisions bind the plan. A changed plan returns
`reviewed_plan_changed` before content writes. Supported records are validated
again through `ContentService::saveCanonical`, then record creation/revision,
cyclic relationship projections, source checkpoints and import audit commit in
one serializable transaction. New records are drafts. Existing workflow states
remain destination-owned; no release or source activation is imported.

Conflicts are skipped by default. To replace the specifically reviewed newer
destination revisions, also provide `--confirm-conflicts=<same-planSha256>`.
This distinct confirmation is recorded in the import audit. Re-review after
any destination edit; an old confirmation cannot override it. On subsequent
imports, a source revision can advance only when the destination still matches
its last imported revision. Source/destination revision counters are independent;
the checkpoint compares each lineage to its own baseline. An identical source
is a no-op even after a destination editor has changed its imported record.
Checkpoints retain stable destination identities as private provenance rather
than preventing ordinary archived-record deletion. A later import reports a
deleted mapped destination as blocked; it cannot silently recreate it.

## Media and interruption

The record transaction reserves private media identities, then the ordinary
`MediaUploadService` transfers at most two originals per invocation by default
(`--maximum-media=1..8`). It verifies the assembled checksum before handing off
one normal processing job. Original names are metadata only. Source derivatives,
processor facts and geometry are rebuilt by the normal destination worker,
never installed as trusted ready images. A missing qualified Imagick/WebP
capability visibly blocks media import instead of queuing unsupported work.

A changed source name, alternative text, caption, credit or focal point for an
already imported original uses the ordinary `MediaLibrary` metadata service and
its revision check, without uploading the same bytes or creating another
processing job. Later destination metadata edits are preserved for identical
sources; changed source metadata conflicts with a
local metadata edit unless separately confirmed. Where the source carries media
metadata revisions, source and destination counters retain separate baselines;
an older revision or an unversioned replacement conflicts with versioned source
metadata. Identical older record or media replays never rewind a checkpoint.
The reviewed plan binds the current metadata revision. Native metadata/checkpoint
interruption resumes without repeating the update. These metadata updates do
not require a raster processor.
The invocation limit covers uploads and metadata updates together; the result
reports `transferredMedia` and `updatedMedia` separately.

`preparing` exits **2** and means more original transfers or metadata updates
remain. Re-run dry run, review its new plan and confirm it to continue.
Checkpoints make identical content a no-op;
upload client identities resume partial parts and completed uploads, including
a failure after upload completion but before its import checkpoint. Expired
sessions use the normal upload expiry/recreation path. A pending media transfer
must resume as its original destination administrator. A private nonblocking
root lock prevents overlapping import executors; database transactions and
ordinary compare-and-set writes fence concurrent editorial changes. Imports refuse
while language-text deletion is running. Installed direct invocations also hold
the normal shared runtime lease and refuse an interrupted upgrade or a stale runtime.

`applied` means content is committed and required uploads are queued, not that
image jobs or public publication have completed. `applied_with_skips` retains
the reported rejected/conflicting items and exits 2; operational rejection exits
1. Inspect processing status in the CMS and run the existing media worker or
qualified cron recovery. Failed images stay observable through the usual job
tools. Review a fresh exact preview before any separately authorized publication.

### Import limits

| Boundary | Limit |
| --- | --- |
| v1 input | 32 MiB JSON; 500 records; 5,000 revisions; 16,000 relationships; 5,000 media entries |
| v2 input | 2,000 records; 20,000 revisions; 32,000 relationships; 5,000 media entries |
| Current-content plan | 8 MiB serialized metadata, revisions, relationships and media facts; also subject to a memory check |
| Originals | 40 MiB each; 8 GiB in total |
| Planning, record writes, inventory checking | Separate 180-second deadlines |
| Media transfer | Separate 180-second invocation budget, checked between bounded originals |

Only current revisions are imported; historical bodies and source derivative
recipes are not retained in the plan. Mapping reads select this bundle's indexed
identities. An expired record-write phase rolls back the transaction; interrupted
media work remains resumable. Parts stream through bounded temporary storage.

Prepared MP4/WebM imports are unsupported. They and records selecting them are
reported as `blocked` with `prepared_video_import_not_supported`; source paths or
derivative declarations cannot bypass this restriction. See [export capacity](export-capacity.md)
for the input formats' other bounds.

## Prepare a migration

Map the source into the destination profile's contract before importing. Report
any conversion losses, keep a stable source name and review the destination plan.
Use fictional content to rehearse the full import and preview workflow.

Migration code must use the importer rather than write CMS tables or bypass
conflict checks. Keep real content, media and credentials outside this repository.

The required MariaDB gate proves dry-run immutability, existing singleton
conflicts, cyclic graph commits, attribution, checksum upload and ordinary
processing, transaction rollback, interrupted media checkpoints, idempotent
repeats, later destination edits, stale confirmations, unsupported/lossy bodies,
missing media, hostile paths, normalized export and the real PHP CLI.
