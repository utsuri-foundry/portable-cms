# Full-history export capacity

[Normalized export and isolated restore](conventional-cms-export.md) preserves
all retained record revisions. Export v2 changes transport, not the meaning of
records, history, relationships or private media. It never silently prunes history.

The installation's [record-history policy](conventional-cms-content.md#bounded-record-history)
keeps 50 historical revisions per record by default, additional to current and
published heads. Administrators may choose 10–500; confirmed reductions use
resumable batches. Export includes only retained revisions and restore preserves
gaps in their original numbers. Settings shows the live retained total against
the measured 20,000-revision capacity and warns at 90%; retention does not replace
the other export bounds or an installation backup.

## Supported envelope

Capacity is the intersection of these independent limits, the installed profile's
field limits, and the qualified host budget. Counts alone cannot guarantee export
of arbitrarily large bodies or media libraries.

| Resource | Legacy v1 | Chunked v2 |
| --- | --- | --- |
| Records, including drafts, archives, terms and settings | 500 | 2,000 |
| All retained record revisions | 5,000 | 20,000 |
| Current relationship projections | 16,000 | 32,000 |
| Media records | 5,000 | 5,000 |
| Serialized content | 32 MiB envelope | 512 MiB aggregate JSONL |
| One serialized row, including newline | envelope bound | 1 MiB |
| One chunk | not applicable | 2 MiB and 128 rows |
| Manifest | part of envelope | 1 MiB and 4,096 chunks in total |
| Nested revision body | depth 8, 1,000 items per container | depth 16, 5,000 items per container |

The legacy depth bound counts scalar leaves too. A localized rich-text link can
exceed it even on a small site. The source download checks every retained
revision, not only current content, and returns the explanatory private-package
422 page for rows accepted by v2 but not v1. Restoring the current words does
not remove historical revisions. Neither the v1 reader nor its meaning is widened;
malformed rows or bodies beyond both formats still fail validation. The private
v2 workflow retains the complete marked text and history without pruning.

Source admission separately caps the combined stored revision/media JSON columns
at 512 MiB and each stored revision body below 1 MiB, before decoding. Raw stored
JSON and normalized JSONL need not have identical sizes (for example, Unicode
escaping can differ); both source admission and serialized transport limits apply.

`ExportCapacity` owns v2 limits. `tools/contracts/check-export-package-schema.php`
checks the derived schema. PHP additionally enforces aggregate bytes/counts and
complete graph integrity. The v1 constants and interpretation remain unchanged.

These are installation-wide counts across all types and languages, not a budget
per visible list or published page. One ordinary save retains another full body,
not a diff. Drafts, archived records, terms and settings contribute even if they
never appear on the public website. The administrator's `/exports` page has a
native **Export and restore limits** disclosure, available without JavaScript
even before the first record exists. It uses the enforced PHP limits for both
paths; live usage notices are advisory, not permission to exceed a bound.

Existing independent byte ceilings remain 20,000 derivatives, 25,032 destination
files and 8 GiB destination bytes. Each blob is at most 128 MiB, except prepared
video, whose existing 256 MiB cap still obeys the narrower profile rule. These
media ceilings are not a benchmark claiming every host copies 8 GiB in one unit.

Preview accepts 2,000 selected records and 4 MiB serialized frozen input with
250,000 validation nodes. Artifact/search validation accepts 8 MiB/250,000 nodes.
Its pre-allocation check also caps raw current bodies plus ready-media text at
4 MiB before constructing the selected projection.
Ordinary form/profile validation remains 1 MiB/50,000 nodes; v1 export remains
32 MiB/500,000 nodes. Existing compiler caps still apply, including 40 MiB normal
artifact bytes, 10,000 routes, 20,000 artifact files and 200,000 search tokens.
The CMS preview inventory permits those 20,000 files plus `artifact.json`,
instead of imposing the older independent 2,000-file ceiling.
Profiles can hit these before the record cap. A safe capacity preflight refuses
an oversized current projection rather than queueing an impossible build.
That preflight includes aggregate ready-media text before allocating it, and
checks media, derivative and relationship counts before loading collections.
The release reader uses the same artifact-specific limit as the compiler.

## Resource budgets and measured limits

The required MariaDB fixture contains exactly 2,000 records, 20,000 full revisions,
3,989 relationships, three raster originals with derivatives, Catalan/Spanish/
English text including emoji, rich text, typed blocks and composition. It retains
433,775,706 raw historical body bytes and 1,494,630 current body bytes, including
all workflow states. No collections are sampled during export or restore.

```sh
CMS_TEST_GROWTH_ONLY=1 nix develop -c sh tests/php/run-mariadb-integration.sh
```

This hermetic test uses PHP's 256 MiB limit and asserts each measured content
unit takes less than 120 seconds and peaks below 256 MiB. One observed run:

| Phase | Elapsed | Peak PHP allocation |
| --- | --- | --- |
| Capture and first byte batch | 62.71 s | 30 MiB |
| Source snapshot transaction, included above | 54.32 s | included above |
| Resume private packaging | 7.82 s | 32 MiB |
| Complete semantic validation | 5.12 s | 26 MiB |
| Atomic destination commit | 36.90 s | 26 MiB |
| Destination transaction, included above | 36.88 s | included above |
| Fresh CMS preview and release-reader verification | 2.14 s | 94 MiB |
| Fresh draft-inclusive CMS preview, above 3,000 files | 8.79 s | 104 MiB |

This tier leaves room below the 512 MiB transport and 120-second processing
ceilings for the measured full bodies, and below 256 MiB for both preview modes.
Higher count tiers are not qualified by simply changing constants. The scale
fixture completes its draft's required English slug before capture; separate
legacy restore fixtures continue to preserve intentionally incomplete translations.

These are pinned local observations, not hosting latency promises. An initial
OFFSET scan exhausted the same 120-second budget. Keyset pages of eight rows
fixed it without increasing the budget. Only one body is decoded at a time;
identity indexes and current-revision offsets do not retain body histories.

Qualify this tier with PHP CLI memory of at least 256 MiB, a 120-second content
unit and sufficient transaction duration. File streaming retains its injected
180-second budget and 128 KiB buffers. Final full byte verification must fit
that budget on the intended library/disk. Validation, full byte verification and
the final content commit must also fit together inside the reader's 120-second
invocation deadline; the filesystem budget is not an extension of that deadline.
Smaller packages may pass the reader's
adaptive pre-allocation check on smaller hosts; this does not qualify that host
for the measured tier. Customer hosts need no Node, Composer, shell execution,
daemon or external service; a hosting control panel can invoke the PHP CLI tool.

Check account quota and inodes, not only filesystem free space. Allow for source
history, the private package, destination copies/imports, previews and independent
backups. No universal disk promise follows from a record count. Preserve an
independent verified backup before migration.

## Capacity, host capability and incomplete work

| Report or condition | Meaning and next action |
| --- | --- |
| A count, serialized byte, row or graph limit rejects | No export has been truncated. Use v2 if only the v1 envelope is too small; review the supported envelope if v2 also rejects. Do not prune history or raise constants to disguise the limit. |
| Insufficient available PHP memory | The count tier alone does not qualify the process. V1 restore needs free memory of at least `max(64 MiB, 16 × JSON file bytes)` before decoding, in addition to memory already in use. A 32 MiB file therefore needs at least 512 MiB **free**, not merely a 512 MiB configured limit. The source download also reserves row and metadata overhead. Use the qualified v2 workflow rather than disabling checks. |
| A required host capability is absent | This is not content capacity. For example, restore needs permitted private-filesystem `link()` for atomic no-replace commits, writable private storage and working database transactions. Qualify or change the host; a larger limit does not repair missing capability. |
| `packaging` or `preparing`, exit 2 | Work is incomplete and resumable, not failed content and not a completed backup/restore. Repeat the identical bounded intent as described below. |
| `blocked` or `rejected`, exit 1 | Investigate the safe reason first. Missing or changed chunks/blobs, profile incompatibility, occupied destinations and denied storage must not be retried blindly or treated as incomplete success. |

V2 checks available memory per bounded unit rather than multiplying the whole
history size by sixteen; the measured tier still requires the qualified
256 MiB CLI budget above. File preparation defaults to eight logical files per
invocation (configurable from one to 64), and checksum streaming uses 128 KiB
buffers. Final complete-inventory verification and the database commit are not
split into arbitrary unbounded work. Resource and time limits remain enforced.

Both formats exclude private media/font bytes from JSON. A portable package
must carry the referenced originals, derivatives and uploaded fonts, while
packaged fonts and executable renderers come from the independently verified
profile. Normalized migration excludes accounts, secrets, sessions, old audit
history, job recovery and release activation. Keep an independent verified
installation backup for disaster recovery; a content package is not its replacement.

## Limit ownership and drift checks

The table is a summary, not another configurable limit registry. Verify changes
against all of these sources together:

- [V1 PHP constants and validation](../../engine/src/cms/conventional/export/normalized-export-contract.php),
  [v1 schema](../../schemas/portable-cms-export-v1.schema.json) and the
  [source repository](../../engine/src/cms/conventional/export/maria-db-export-repository.php).
- [V2 capacity constants](../../engine/src/cms/conventional/export/export-capacity.php),
  [generated v2 schema](../../schemas/portable-cms-export-v2.schema.json) and its
  [drift check](../../tools/contracts/check-export-package-schema.php).
- [Keyset source queries](../../engine/src/cms/conventional/export/maria-db-export-rows.php)
  (pages of eight, with whole-collection admission checks, not a silent SQL truncation).
- [Available-memory qualification](../../engine/src/cms/conventional/export/export-memory.php),
  [file reader and bounded staging](../../engine/src/cms/conventional/restore/filesystem-restore-store.php)
  and [semantic restore/file inventory](../../engine/src/cms/conventional/restore/restore-plan.php).

The delivered `preserve-exportability-as-content-grows` task owns the measured
expansion from v1 to v2. Any higher tier needs fresh measurements and acceptance;
this disclosure changes no runtime bounds or retention policy and makes no
unmeasured claim about how many years a client can keep editing.

## Capture and resume

The [portable handover operators](portable-handover.md) drive these same bounded
services to completion with one source command and one confirmed destination
invocation, within finite overall budgets. Their exact package/profile proof
does not expand any capacity below.

The native `/exports` page shows live record/history counts and an approaching
warning at 80% of v2 count, body-byte, row or current-body budgets. These are
advisory: capture independently checks one consistent snapshot. Raw SQL body
totals, including media text and derivative recipe JSON, exclude serialization overhead, so the writer may reject a serialized
byte cap earlier. Above legacy limits the page directs administrators to private
v2 capture instead of a doomed download; a crossing POST returns explanatory 422.
Legacy memory qualification additionally allows for collection rows, metadata
and derivative overhead before loading arrays. It may conservatively route a
smaller download to v2 when PHP has insufficient available memory; it never
omits metadata to make the legacy download fit.

Create a new owner-only empty directory outside the document root. Paths below
are illustrative, not hosting defaults:

```sh
php -d memory_limit=256M tools/conventional-export-package.php \
  --config=/account/private/cms.json \
  --public-root=/account/public_html \
  --output=/account/private/export-package \
  --administrator=admin@example.test \
  --maximum-files=64
```

Repeat the identical command until `packaged`. `packaging` exits 2 (incomplete),
`packaged` exits 0, rejection exits 1. Default eight logical files per invocation;
allowed range 1 to 64. Larger batches avoid repeating content validation for every
small file. Shared checksums are copied once and logical entries verify separately.

Capture reads metadata, all rows and the site-sequence high-water mark inside
one repeatable-read transaction. A concurrent ordinary edit can succeed without
entering that snapshot. The attributed audit commits before `capture.json` seals
the resumable intent. Failure before that seal needs a fresh empty output directory.
After sealing, retries validate the captured chunks, not newer source content.

Retain every captured original, derivative and uploaded font until completion.
CMS paths are immutable and owned media blocks record deletion. Do not prune
files out of band during packaging. Missing/changed source bytes reject; new
derivatives or substitute bytes are never silently adopted.

`capture.json` and `packaging-progress.json` are working files, not completion
receipts. A checkpoint avoids rehashing the prepared prefix on each small batch.
New files verify individually and the full inventory verifies again before
`export.json` commits atomically without replacement. Only that manifest with
its chunks and blobs is a portable complete package.

## Restore authority and recovery

The root manifest has `formatVersion: 2`, `metadata` and `collections`. Metadata
retains the v1 header, including `formatVersion: 1`, without hidden collections.
Each chunk descriptor contains only SHA-256, byte size and row count. Files live
at `chunks/<sha256>.jsonl` and `blobs/<sha256>`; no imported paths or credentials.

The installed reader automatically recognizes v1 and v2. Use the
[existing dry-run/apply commands](conventional-cms-export.md#isolated-restore-workflow)
with `php -d memory_limit=256M`. It validates every chunk, the entire identity/
reference graph, every historical body through ordinary content rules, matching
profile/locales and private inventory. No rows become authoritative before the
final empty-destination serializable transaction.

`preparing` exits 2 for either format. Repeat the confirmed intent until `restored`
(0); investigate `blocked` or rejection (1). V2 stage checkpoints skip prepared
prefixes but cannot authorize bytes: the whole destination inventory verifies
again before SQL. A missing file rewinds progress and rejects that invocation;
a retry can recopy. Corrupt immutable files reject without replacement. Owned
partial copies recover. SQL failure rolls back all imported rows and retains the
matching journal and stage. Completed replay verifies and returns `already_restored`.

Restore imports no accounts, authentication, configuration or public activation.
Source URLs remain provenance, never destination authority. Create a fresh verified
preview using destination configuration; publishing remains separate. Do not remove
the journal or reuse partial destinations for another intent. Occupied-installation
import is a different workflow. V1 packages retain their established meaning.

## Required proof

`tests/php/chunked-export-test.php` covers hostile/missing inventories, traversal,
symlinks, changed chunks, cross-chunk duplicates/references, count/row/aggregate
limits, deadlines, memory refusal and untrusted progress. The large integration
proves complete normalized equivalence, live-source consistency, interrupted
staging, SQL rollback/recovery, idempotency and a fresh destination preview. The
same large fixture also builds a draft-inclusive CMS preview above 3,000 files,
and passes the published preview through the ordinary release reader.
The full gate always runs it with isolated databases and fictional content. The
bundle ships both schemas and the same PHP code. This is not real-host throughput
acceptance or a deployment.
