# Portable content handover

The installed PHP operators capture a complete private content package without a
browser download and drive an empty-destination restore through bounded batches.
They reuse the ordinary export, packaging, validation and restore services.
They require no Node.js, Composer, shell execution, provider API or daemon on the
host. A hosting control panel may invoke PHP CLI with these arguments.

This is content portability, not automatic host provisioning, profile installation,
identity migration, a full-installation recovery tool or permission to publish.
The [supported capacity](export-capacity.md) and all independent byte, memory,
profile, graph, file and database limits still apply.

## Prepare both installations

- Keep a separately verified full backup of the source installation.
- Independently install and verify the compatible destination engine and exact
  profile package. The handover records the source engine version, restore contract
  version, profile ID/version and exact profile package SHA-256. This first
  operator conservatively requires the same engine version. A mismatch needs a
  separately reviewed migration, not a forced checksum override.
- Apply the destination bundle's forward migrations. Configure an empty content
  store, an existing active owner/administrator, private media storage, new secrets
  and destination-owned CMS/public URL settings. Existing identities are allowed;
  existing content, jobs, previews, libraries and unrelated files are not adopted.
- Qualify PHP CLI and account limits. The measured v2 tier uses at least 256 MiB
  PHP memory. Reserve disk and inodes for source storage, package, transfer,
  destination copies, previews and backups. Paths below are examples, not hosting
  defaults. Create the package directory empty, owner-only and outside the
  document root, separate from the source and destination media roots.

Imported bytes never select, copy, install or execute a profile PHP file. The
destination uses only its independently verified installed package. Completion
metadata accepts no paths, URLs, credentials, source configuration or executable
entrypoints. A checksum proves integrity, not who sent the package; use an
authenticated private transfer and communicate its checksum through a trusted
channel too.

## Source: pause mutations, then run one command

Before capture, finish image/video preparation. Coordinate a mutation pause with
every editor and operator: block administration writes at the hosting control
panel, disable media/AI/publication/cleanup Cron invocations and let in-flight
requests and jobs finish. Do not run upgrades, prune files, replace fonts, remove
media or write directly to MariaDB during the handover. Merely closing one browser
tab does not pause another editor or a worker.

Keep that pause and every referenced immutable original, derivative and uploaded
font until the package reports `packaged`. If an invocation is interrupted, keep
the pause across retries. `--source-paused` is the operator's explicit
acknowledgement of this protocol; it **does not stop services automatically**.
If this pause cannot be coordinated, do not acknowledge it: arrange a documented
retention mechanism before exporting instead.

```sh
php -d memory_limit=256M tools/conventional-portable-export.php \
  --config=/account/private/cms.json \
  --public-root=/account/public_html \
  --output=/account/private/handover \
  --administrator=admin@example.test \
  --source-paused
```

The named owner/administrator is checked before workspace allocation. Capture
reads one repeatable-read content snapshot and appends an attributed audit event.
The source transaction commits before copying media; no write transaction remains
open across large filesystem copies. A source original's private upload key is
resolved locally and its bytes must still match the frozen checksum and size.
The package never exports those private keys.

Default execution drives all required file batches in one invocation, subject to
the finite budgets below. Exit 0 and `status: packaged` mean `package.json` was
committed last, after the normalized envelope, chunks and required private bytes
verified. Preserve the reported `packageSha256` and `profilePackageSha256`.
Only then may the coordinated pause end. Later source edits do not alter the
captured package; replay checks and returns the same capture rather than taking
a newer snapshot in the same workspace.

## Transfer privately

```text
handover/
  package.json                 final completion and compatibility proof
  content/
    export.json                normalized v2 envelope
    chunks/<sha256>.jsonl      retained full-history content
    blobs/<sha256>             private original, derivative and uploaded-font bytes
```

The envelope names chunk checksums and the rows name byte checksums. The outer
manifest names the envelope's exact hash/size/format, the complete logical file
count/size/inventory hash and the exact profile package checksum. The inventory
digest covers each file's canonical destination key, checksum, byte count, MIME
and optional geometry in plan order; it contains no source storage path.

Copy `package.json` and the entire `content/` tree through authenticated private
transfer, never through a public download URL. Source `intent.json`, locks,
capture markers and progress cursors are local working state, not completion
proofs. They need not be transferred; copying the whole private workspace is
also safe. The destination never treats those cursors as integrity authority.
An internal `content/export.json` without the outer `package.json` is not a
completed handover for these operators.

## Destination: inspect, confirm, complete

The default invocation is a read-only compatibility and full-inventory plan:

```sh
php -d memory_limit=256M tools/conventional-portable-restore.php \
  --config=/destination/private/cms.json \
  --public-root=/destination/public_html \
  --package=/destination/private/handover \
  --administrator=admin@example.test
```

It creates no destination locks, restore journal or content. Review `status`,
counts, provenance notes and `packageSha256`. Check that checksum against the
source's separately communicated completion report. Explicitly apply the same
package with one invocation:

```sh
php -d memory_limit=256M tools/conventional-portable-restore.php \
  --config=/destination/private/cms.json \
  --public-root=/destination/public_html \
  --package=/destination/private/handover \
  --administrator=admin@example.test \
  --apply --confirm=PACKAGE_SHA256_FROM_THE_VERIFIED_PLAN
```

`--confirm` here confirms **packageSha256**, not the lower-level batch command's
`exportSha256`. The whole manifest, including compatibility and inventory, is
confirmed. The executor also retains the existing durable envelope/administrator/
destination/profile restore intent and serializable empty-store check.

One validated content plan drives the internal file batches. Legacy v1 and v2
preparation use intent-bound progress cursors in this orchestration path, without
rehashing the entire prepared prefix for each batch. Each copied file verifies;
the complete destination inventory verifies again before the only authoritative
content transaction. The exact envelope and outer completion manifest are checked
again at the commit boundary. A cursor alone never authorizes data or success.

Exit 0 and `restored` mean complete content restoration. Repeating the identical
apply reports `already_restored` after verification, not duplicate insertion.
Source identities, secrets, sessions, old audit events, jobs and release activation
are not imported. Create a fresh exact preview with the destination configuration;
publication remains a separate authorized operation. Static visitors on an
existing site are never switched by this command.

## Budgets, progress and recovery

- Defaults: a 120-second processing budget, at most 25,032 file batches and 64 logical files
  per batch. `--maximum-seconds` accepts 1 to 600 only; `--maximum-batches` accepts
  1 to 25,032; `--maximum-files` accepts 1 to 64. Qualify any larger runtime
  allowance against the real host; a configured limit does not create resources.
- Time checks are cooperative during content/chunk/file work, between batches
  and before commit. A native checksum or SQL statement may finish after the
  boundary; this is not a hard real-time process killer. Counts and individual
  work units remain bounded, and a commit already completed is not reported as
  an incomplete restore merely because the clock subsequently advanced.
- Stdout carries one final bounded JSON report. Stderr carries one small progress
  JSON line per completed batch or a safe rejection report. Progress contains only
  status and prepared-file counts, not credentials, paths or editorial bodies.
- Exit **2** with `packaging` or `preparing` is incomplete, not successful handover
  or content restoration. Repeat the same command, package, administrator and
  confirmation. Completed prefixes resume; no browser re-export is needed.
- An abrupt process or host kill may prevent any final JSON or application exit
  status at all. Absence of a verified completion is never success. Retain the
  owned state and resume the identical intent after investigating the interruption;
  the command does not depend on optional signal-handling PHP extensions.
- Batch exhaustion returns the last prepared count. A deadline during early
  planning may report no count because no durable restore was reserved yet.
  Do not infer that a JSON report by itself is a completed package or backup.
- Source workspace intent binds the administrator, source storage identity,
  a credential-free database/table-prefix fingerprint (not the raw DSN),
  engine and exact profile. If capture died before its atomic `capture.json`
  seal, retry removes only bounded, validated chunk/partial files in that owned
  workspace and captures again under the continuing pause. Unknown files or
  symlinks reject before cleanup. No source originals are deleted. A failure
  before any durable workspace intent exists may require a new empty directory.
- Exit **1** (`blocked`/`rejected`) requires investigation: missing/changed bytes,
  mismatched profiles or engine, occupied stores, denied private storage, memory
  qualification and unsafe paths are not silently retried or repaired.
- A failed SQL transaction imports no authoritative content. Keep its matching
  restore journal and verified private files; resume the same confirmed intent.
  Never delete the journal or repurpose a partial destination for another package.

## Existing packages and commands

The lower-level `conventional-export-package.php` and `conventional-restore.php`
retain their established batch behavior, confirmation meaning and v1/v2 support.
No previously valid normalized content is reinterpreted by the handover envelope.

The new restore orchestrator also accepts an existing flat **v1** package. It
cannot invent the exact source profile checksum that v1 never recorded. Supply
`--legacy-profile-sha256=<independently verified source profile checksum>` on
both plan and apply. It must match the destination's installed profile. The
plan reports `legacyProfileChecksumSupplied: true` and a deterministic package
confirmation bound to the old envelope, its inventory and that profile; it does
not claim to know the legacy source engine version. Do not copy a destination
checksum blindly as evidence of a source package you have not verified.

Existing flat v2 packages can continue through the established batch restore
tool. The new orchestrator requires its completed outer manifest for v2; do not
hand-edit one to bypass verification.

## Required proof

`CMS_TEST_TRANSFER_ONLY=1 nix develop -c sh tests/php/run-mariadb-integration.sh`
uses fresh isolated MariaDB databases, synthetic content and a real installed
PHP bundle. It proves source attribution, private fonts/media, read-only planning,
exact compatibility, complete single invocations, repeated completion, bounded
resume, changed/missing bytes, unsafe paths and capture recovery. The full gate
includes this lane. Pure tests additionally inject clock exhaustion, changed
completion metadata and commit-boundary refusal. This is not real-host throughput
acceptance or permission to deploy or activate a public website.
