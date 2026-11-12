# Upgrade an installed engine or profile

Run the incoming reviewed bundle's PHP upgrade command against a verified backup.
It prepares and checks the candidate before selecting the new serving release.
Use the configured database mode; the command never silently switches modes.

1. Upload a complete new bundle into private storage, beside the serving runtime.
2. Run the read-only plan and review compatibility, capacity and content changes.
3. Create and verify the required backup.
4. Apply the reviewed plan, then check the CMS and public site.
5. If interrupted, follow the recovery procedure for that same intent.

The installed command needs PHP, not Node, Composer, database dump binaries, root
access or a provider API. Hosts without shell access use the temporary HTTPS
operator described below.

## Host envelope and layout

Keep the dedicated CMS document root, static website document root and private
installation separate. Upload the entire new bundle, including its
`manifest.json`, into private storage such as `/private/cms/incoming`. Verify the
manifest's SHA-256 against the reviewed release through your normal release
channel; a checksum supplied by an untrusted uploader does not establish trust.
Never upload a new bundle over the serving runtime or upload its `public/` over
the visitor website. Retain all old bundles, databases and backups until their
retention policy permits an explicitly authorized cleanup.

## Database upgrade modes

Engine-only changes with the same manifest and completed migration graph reuse
the serving tables. Schema or profile-manifest changes follow the installation's
`database.upgradeMode`, chosen once per installation when the host is
qualified and recorded with that qualification. The dry run reports it, and an
upgrade never falls back to another mode on its own.

Set the policy when generating the private configuration with
`tools/conventional-create-install-config.php`: pass
`--upgrade-mode=table_set|in_place|separate_database` and, when the host's quota
is known, `--database-maximum-bytes=N`. The quota must be a positive whole byte
count, for example `1073741824` for an illustrative 1 GiB limit. The tool validates
both before writing any files and reports the mode. Its optional settings become:

```json
{
  "database": {
    "upgradeMode": "table_set",
    "maximumBytes": 1073741824
  }
}
```

These keys accompany the database credentials in the generated owner-only
configuration. Omitting the options leaves the configuration unchanged, with
the effective `table_set` default and no recorded quota. The dry run reports
the chosen mode and quota. Use the [installation command](conventional-cms-identity.md#hosts-without-command-execution)
with the settings selected during qualification; this tool creates new files
and does not change an existing installation's policy.

| Mode | Databases | During the upgrade | Rollback |
| --- | --- | --- | --- |
| `table_set` (default) | one | the CMS keeps its serving tables; a new table set is built beside them | selects the retained previous table set |
| `in_place` | one | the CMS is closed; the serving tables are changed | restores the verified backup into the serving tables |
| `separate_database` | two | the CMS keeps its serving database; an empty standby database is filled | selects the retained previous database |

**Table set.** The upgrade restores the verified backup into a new table set in
the same database (prefix `u` plus twelve characters of the release identity),
migrates, maps and checks it there, then switches the release pointer, whose
configuration names the new prefix. The serving tables are never changed. The
database temporarily holds two copies: record the host's database quota as
`database.maximumBytes` and the upgrade refuses with
`database_quota_insufficient_for_table_set` before copying when the serving
database plus a second table set would exceed it. A failed candidate table set
is removed at once because it never served.

**In place.** For hosts whose quota cannot hold a second copy. Apply writes the
private `upgrade/maintenance.json` marker before touching the serving tables.
While it exists, every CMS request and worker gets the retryable busy response,
even after the operator process dies, so nobody can save between the backup
and the end of the upgrade. Migrations, declared content mappings and checks
run on the serving tables. On success the pointer switches and the marker is
removed. On any failure the verified backup is restored into the same tables,
its exact fingerprint is checked, and only then does the CMS reopen. An
interrupted upgrade or restore keeps the marker; `recover` restores the backup
from the beginning and reopens the CMS.

**Separate database.** Provide an owner-only ordinary runtime configuration for
an empty database provisioned through the hosting control panel as
`standby.json` and pass it to apply; only its `database` section is used.
Passing a standby configuration in any other mode is refused.

In every mode the command never asks PHP to create a database. The installed
origin, storage, publication target, secrets and other policy remain
authoritative. The public website keeps serving its active static release
throughout; visitors never reach PHP.

Each installation owns one table set: InnoDB tables with primary keys named with
its `database.tablePrefix` (default `cms_`), and no views, triggers, routines or
events in the database. Backups record canonical `cms_` names and ignore other
table sets, so a backup restores into any prefix. The PHP backup
streams at most 100,000 rows, 512 MiB and 8 MiB per encoded row; a tree inventory
allows at most 20,000 files and 2 GiB. Exceeding a bound refuses the operation.
The account needs its ordinary table creation/insertion permissions and named
database locks; request-length and storage limits must be measured on the host.
Unqualified hosts fail closed rather than use an in-place upgrade fallback.

Stop old cron entries and wait for in-flight legacy PHP requests before the
**first** backup. Earlier engines cannot participate retroactively in the new
lease. The verified backup installs a stable private dispatcher, public CMS
front controller and CMS asset routing. Afterwards all CMS requests and the
stable worker share a runtime lease; an operator command takes it exclusively.
A busy operation returns a retryable CMS response while the public website
continues serving static files. Apache needs `mod_rewrite`, `FallbackResource`
and the qualified `.htaccess` override permissions for the CMS root. Upgrade
qualification must exercise these rules before using the installation live.

## Review, backup and apply

Engine compatibility and build provenance follow the [release policy](engine-releases.md).
Dry-run `engine.from` and `engine.to` report versions; `fromSourceRevision` and
`toSourceRevision` identify the serving and incoming source commits, with matching
`fromSourceDirty`/`toSourceDirty` flags. The installed marker supplies the serving
facts, independently of the incoming operator. Legacy builds report unknown
source facts as null. A profile requiring a newer engine is refused with a safe
`profile_engine_incompatible` message naming required and running versions.


Examples use fictional paths; substitute the installation's private paths.

```sh
php /private/cms/incoming/private/runtime/tools/conventional-upgrade.php \
  --action=dry-run --bundle=/private/cms/incoming \
  --config=/private/cms/cms.json --cms-root=/cms-document-root

php /private/cms/incoming/private/runtime/tools/conventional-upgrade.php \
  --action=backup --backup=/private/backups/before-upgrade \
  --config=/private/cms/cms.json --cms-root=/cms-document-root

php /private/cms/incoming/private/runtime/tools/conventional-upgrade.php \
  --action=apply --bundle=/private/cms/incoming \
  --confirm-bundle=REVIEWED_MANIFEST_SHA256 \
  --backup=/private/backups/before-upgrade \
  --config=/private/cms/cms.json --cms-root=/cms-document-root
```

In `separate_database` mode, also pass `--standby-config=/private/cms/standby.json`.

Dry run reads completed migration checksums, engine/profile versions, manifest
changes and affected record IDs. It creates no files, database rows, locks or
sessions. `manifestChanges` retains the changed top-level keys;
`contentNeutralChanges` lists presentation-only paths, while
`contentAffectingChanges` lists model paths and their directly affected record
types. `affectedRecordTypes` is their sorted union, not a promise that related
types can bypass validation. Content-affecting changes with saved records need
a reviewed declared profile migration. A prior declaration is not reapplied
to an unchanged or content-neutral profile. Even a no-op apply requires a
verified backup.

Backup copies database schemas and rows (including binary hashes), the whole
private media tree, current engine, installed profile, configuration and CMS
document root. Every copied file is rehashed before the completion manifest is
written. A missing manifest, changed byte, older-than-one-hour backup, changed
database/media/release or different installation refuses apply. If an editor,
session or worker changes the database after backup, create another backup in
a new directory. Partial backups never qualify; do not overwrite them to hide
an interruption.

Apply copies the verified bundle beside the current release. For a database
change it restores the backup into the new table set or empty standby and
verifies an exact row/schema fingerprint (or, in place, closes the CMS), applies
forward migrations through the existing locked ledger, and maps declared
content changes there. It validates saved revisions and
relationships with the authoritative content service, checks the actual CMS
login handler, and compiles a private validation artifact when the installation
has an active public release. It checks all active public bytes, the database
artifact identity, delivery rules and static-only delivery. It never publishes
the validation artifact. Only then does one atomic `upgrade/active.json`
replacement select the new runtime, configuration, database, profile and CMS
assets. The visitor document root remains untouched.

Change cron to the stable entry point after the first backup:

```sh
php /private/cms/upgrade/worker.php --tool=media --max-jobs=3
php /private/cms/upgrade/worker.php --tool=ai --max-jobs=1
```

The dispatcher selects the current release and configuration on every run.
New direct worker invocations against a retained release refuse before opening
the database. Legacy workers must remain disabled.

## Profile content declarations

Version, known assets, renderer declarations, navigation, known design registry
controls, type/field labels, editorial sections and public route presentation
can change without `upgrade.json`. Nested group field labels and the
`presentation` and `itemLabel` settings of repeatable groups are presentation
too, and so are site wording declarations and their defaults: an installation's
own texts stay, and a text for a removed key or one a key no longer accepts
gives way to the default. Declaring a language envelope, or widening its scripts, directions or characters, only offers more languages and is presentation as well. Narrowing or removing it is content-affecting: an added language the new envelope no longer admits leaves the installation's choice, and the upgrade check refuses the candidate while revisions still hold values in it. Registry curve/style changes and additional tokens retain the old contract;
removing/renaming tokens or adding/removing the registry itself can invalidate
saved references and counts as content-affecting. The pure `ProfileManifestChanges` policy is the single classification
authority. New keys, including new keys inside neutral asset/design/navigation
subtrees, are content-affecting until explicitly reviewed and classified.

Record type/singleton/taxonomy additions, removals or identity changes, field
additions/removals, types, required/localized flags and field settings other
than group presentation require a declaration when records exist. So do locale/default-locale, format,
record-contract compatibility, capability and privacy changes. A change only to
the validated engine compatibility range is neutral when record contracts are
identical; both packages must still pass their respective engine checks before
classification. Definition reordering remains
conservative. Relaxing a field limit still counts as a model change; the engine
does not infer that a mapping would be unnecessary from today's saved values.

A purely content-neutral profile release with no SQL migrations runs no mapping
and adds no record revisions. Bodies, revision identities/numbers/authorship,
timestamps, slugs and relationships remain exact. Only the revision contract
version tags advance to the incoming compatible profile version, in the same
transaction as the audit; normalized exports therefore remain restorable under
that profile without relaxing restore's historical-contract checks.
An operator audit records the
classification and zero rewritten records. Candidate validation, private
compilation and unchanged public-byte verification still run and may refuse an
invalid new presentation or removed design token. Manifest changes still follow
the configured database staging mode and existing conservative rollback rules;
this exemption is not a bypass for backup, quota, lease or rollback checks.
SQL migrations still invalidate pre-upgrade forms through new revisions, even
when their accompanying profile changes are neutral.

A reviewed profile may ship `upgrade.json` beside `profile.json`:

```json
{
  "formatVersion": 1,
  "fromVersion": "1.0.0",
  "toVersion": "1.0.1",
  "entrypoint": "src/upgrade.php"
}
```

The entry point returns a pure closure accepting a record type ID and canonical
body, and returning the mapped canonical body. The bundle checksum covers the
declaration and PHP; the profile package covers its reviewed `src` file. It is
trusted profile code, never editorial input. Mapping runs against every saved
revision in the isolated database so future history/export reads use the new
contract. Existing record/revision identities, authorship, ordering and revision
timestamps stay intact. Each current record gains a new revision; the upgrade
records an operator migration audit event (also for SQL-only upgrades), so a
form opened before the upgrade conflicts rather than clearing newly mapped
fields. The exact original revision bytes remain in the verified backup and
retained original database. Current relationship indexes are rebuilt through
the content validator. A lossy mapping must be explicitly reviewed as a data
migration with that backup and compatibility plan, not disguised as a renderer
change. This upgrade command does not invent conversions for changed fields.

## Recovery and rollback

```sh
php /private/cms/incoming/private/runtime/tools/conventional-upgrade.php \
  --action=recover --config=/private/cms/cms.json --cms-root=/cms-document-root

php /private/cms/incoming/private/runtime/tools/conventional-upgrade.php \
  --action=rollback --config=/private/cms/cms.json --cms-root=/cms-document-root
```

The private journal records preparation, mode, checked candidate, commit point
and terminal result. A process stop before the pointer switch leaves the old
bundle serving. In the table set and separate database modes its tables were
never changed; recovery aborts the preparation and reports the interrupted
table set (`interruptedTableSet`) or standby. In place, recovery restores the
verified backup into the serving tables and reopens the CMS. Retry with a fresh
backup and a new incoming release directory after inspecting the journal. A
stop after the atomic switch leaves the already checked candidate selected;
recovery settles its journal and reopens the CMS. Repeated
apply cannot start a competing pending upgrade. A subsequent independently
backed-up release archives the prior journal and retains its files/database;
the latest upgrade then owns the one-command rollback point.

Rollback selects the verified retained previous bundle and its unchanged
table set or database in one command; in place it restores the verified backup
into the serving tables behind the maintenance marker first, and an
interrupted in-place rollback is finished by `recover`. It rechecks retained
runtime bytes, database identity and public release. Engine-only rollback preserves all later
content because the database/profile contract is unchanged. For schema/profile
changes, rollback proceeds only while durable state still matches activation.
It refuses with `rollback_cannot_preserve_later_content_without_declared_reverse_migration`
after later content, account, media, job or publication changes. Sessions,
throttles and diagnostic audit rows do not count as authored changes. This
version supplies no general reverse migration or forced data-loss option. Keep
the new installation running and resolve the content through a separately
reviewed compatible migration. Never manually point an old engine at an
incompatible new database.

## Retired table sets

Table set upgrades keep the previous table set for rollback. Once it is no
longer needed, remove it, or a candidate left by an interrupted upgrade, with an
explicit confirmation:

```sh
php /private/cms/incoming/private/runtime/tools/conventional-upgrade.php \
  --action=cleanup-table-set --table-prefix=u0123456789ab_ \
  --confirm-table-prefix=u0123456789ab_ \
  --config=/private/cms/cms.json --cms-root=/cms-document-root
```

The serving table set and the latest upgrade's rollback table set are always
refused; after a later upgrade or a rollback, the earlier sets become
removable. Cleanup never runs automatically and never touches other tables.

## Control-panel-only hosts

The bundle's `operator/upgrade.php` is a temporary CMS-origin endpoint. Build
with `--upgrade-bundle-root=/private/cms/incoming` if the incoming path differs
from the default `<private-root>/incoming`. It loads the **incoming** runtime,
not the currently serving engine. Upload it as `/upgrade.php` only for the
operator window, alongside a private owner-only `upgrade-request.json`:

```json
{
  "action": "dry-run",
  "bundle": "/private/cms/incoming",
  "config": "/private/cms/cms.json",
  "cmsRoot": "/cms-document-root"
}
```

For backup add `backup`; for apply add `backup`, `confirmBundle` and, in
`separate_database` mode, `standbyConfig`. Recovery/rollback need only
action/config/cmsRoot; `cleanup-table-set` adds `tablePrefix` and
`confirmTablePrefix`. In place, a request limit that stops the operation keeps
the CMS closed until `recover` finishes, so measure the restore time first.
Create a separate one-use gate for each operation with the existing gate
creator (its `runtime-config-path` points at the private request document):

```sh
php tools/conventional-create-migration-gate.php \
  --gate-output=/local/private/upgrade-gate.json \
  --token-output=/local/private/upgrade-token \
  --runtime-config-path=/private/cms/upgrade-request.json

php tools/conventional-upgrade-client.php \
  --endpoint=https://cms.example.test/upgrade.php \
  --token-file=/local/private/upgrade-token
```

Upload the gate as `<private-root>/upgrade-gate.json` and the request privately
with mode 0600. The endpoint requires HTTPS POST, a 256-bit token header and a
maximum 512-byte request; filesystem paths and commands come exclusively from
private intent. The one-hour gate closes after a successful operation and
removes its token hash. TLS verification stays enabled and redirects are refused.
Remove the temporary endpoint, gate and token when finished. A browser reload
or client timeout does not establish failure: inspect/recover the journal first.
If the host's PHP request limit cannot accommodate the measured bounded work,
use its PHP cron control panel or refuse qualification; no persistent daemon is
required. Request-limit interruptions retain the previous installation.

## Qualification proof

The required MariaDB lane runs an actual prepared/public Reference Studio
installation through packaged dry run, no-op, backup corruption, partial DDL
failure in all three modes, process death while copying, migrating, before and
after the switch and while restoring, an interrupted in-place rollback, the
closed CMS behind the maintenance marker, recovery, rollback, a refused table
set over the declared quota, changed-field profile mapping in a new table set,
retained and removable table sets, and refusal to lose later edits. Pinned
Apache serves exact public bytes across every interruption and rollback. The unit lane covers
hostile inventories and runtime leases; loopback TLS proves the operator client.
Run the focused lane with:

```sh
nix develop -c env CMS_TEST_UPGRADE_ONLY=1 sh tests/php/run-mariadb-integration.sh
```

Host qualification records the installation's `database.upgradeMode` and, for
table sets, the database quota as `database.maximumBytes`. On each qualified
host, use an isolated fictional installation and separately authorized host
operations, in the mode that host will use, to run backup, dry run, staged
upgrade and rollback
with this same command or HTTPS endpoint. Fetch the CMS login and every required
CMS asset before/after; verify the release pointer, database ledger and unchanged
active static artifact, including actual public HTTP bytes. Exercise the stable
cron entry point, lease contention, an interrupted operation and recovery. Save
redacted aggregate results outside the public root. Local integration proves
the product path; it does not claim that a remote host was upgraded or qualified.

For a PHP cron or SSH qualification run, the new bundle also ships
`tools/conventional-upgrade-acceptance.php`. Its required bundle checksum,
configuration, CMS root, new backup and optional standby options explicitly
authorize an **upgrade and rollback round trip**. It checks real HTTPS CMS
login/assets and all active public artifact bytes after each operation, with
TLS verification and no redirects, and returns one redacted aggregate report.
Use only on the fictional qualified-host acceptance installation.
