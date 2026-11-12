# Read and operate the public content feed

The feed is static JSON beside the published website. Consumers can read selected
content without a CMS account or database connection. Profile authors choose the
[exposed types and fields](profile-feed.md); this guide covers reading changes,
retention and withdrawal.

## Public content feed

Profiles [explicitly select public fields](profile-feed.md#public-content-feed).
The exact preview contains an immutable feed snapshot beside the website; it
never fabricates an activation. Release IDs are assigned later. The public
entrypoint, relative to the configured website base, is `feed/v1/current.json`.
Ordinary visitors and feed consumers need only the static host.

After target verification, the activation transaction commits its site sequence,
release identity, activated/replaced snapshot identities, rollback flag and
added/changed/removed record projections. A projection is identified by type,
language and record ID. A new revision number counts as a change even if its
declared field values stayed the same. A rollback from B to A reports B → A,
with a new activation and sequence, while reusing A's exact snapshot bytes.
Snapshot JSON never names a release. Activation JSON does not contain bodies.

The transaction also commits a feed outbox. Under the publication target's
existing filesystem lock, bounded batches write immutable activation JSON,
`feed/v1/activations.json`, then `feed/v1/current.json`. A private journal and
ownership ledger resume after process death. Public activation records never
precede verification or a committed database activation. Feed-delivery failure
after commit leaves that activation intact and its outbox runnable; it does not
roll the website back. Cron and the normal worker resume delivery. The worker
reports pending feed work and returns failure on delivery errors. Standard
publication can temporarily expose different approved versions during copying
and this final outbox phase; a completed operation settles both.

### Reading and resuming

1. Read the current pointer. With `status: available`, its `fullRead` names the
   current snapshot manifest; `snapshotId` is its content digest. Paths in the
   pointer and activation listing are relative to the website base. Collection
   page paths are relative to their snapshot directory. Media URLs identify
   published checksum-addressed WebP derivatives.
2. On first use, read every collection page in that manifest and check the
   declared lengths and SHA-256 values before replacing a local collection.
   Follow the numbered pages through `nextPage: null`. Empty collections have
   one empty page. The manifest's record fingerprints permit comparison without
   guessing from timestamps. Reread the pointer after completing a full read;
   if it advanced, process the later retained activations or repeat the read.
3. Save the activation's site sequence as the cursor. On later reads, take
   entries above that cursor from `activations.json` in ascending sequence,
   inspect their immutable activation records, and read changed content from
   each activated snapshot. Sequence gaps are normal: the same counter also
   orders authoring changes. A familiar snapshot ID does not mean an activation
   can be skipped; rollbacks activate old snapshots again.
4. If the cursor is below `cursorFloor`, or any referenced activation, page,
   snapshot or derivative is missing, reread `current.json` and restart a full
   read from its `fullRead`. The pointer explicitly declares this procedure.
   If the current full read is itself missing, stop and retry/report the
   incomplete static target rather than asking the administration for data.
5. `status: disabled` means the new artifact supplies no feed (including a
   holding artifact). `status: purged` means the current snapshot was explicitly
   withdrawn. Both have a null full-read pointer; consumers must clear that
   snapshot's cached content and wait for a later available activation.

All five formats have v1 JSON Schemas in `schemas/`: discovery, snapshot,
collection page, activation and activation listing. Discovery uses the normal
public revalidation policy. No PHP read endpoint, token, visitor session,
timestamp cursor or provider service is involved.

### Feed retention and urgent withdrawal

The publisher keeps snapshot files, activation files and derivative copies for
at least the last ten activations **and** fourteen days: an item is removable
only when it is outside both bounds. Shared media bytes remain while any kept
snapshot references them. The normal worker prunes expired ownership even when
no release is queued. Removing a private release does not remove a retained
public feed snapshot. Turning a language off, removing a record, disabling the
feed or switching to Coming soon likewise does not withdraw old snapshots.

Retention is bounded to 1,000 activations, 20,000 distinct snapshot/derivative
files and 2 GiB of those files, with 8 MiB per JSON document and 16 MiB across
retained activation JSON. If another
activation cannot respect both retention minima within that envelope, activation
fails and ordinary recovery restores the preceding artifact. It never silently
shortens retention. The existing independent private release limit still
applies. Measure this envelope for a host before promising a high-volume feed.

An operator can withdraw a known snapshot before retention expires:

```text
php tools/conventional-purge-feed.php --config=/private/cms.json \
  --snapshot=SNAPSHOT_SHA256 --confirm=SNAPSHOT_SHA256 --owner=owner@example.test
```

The command requires the installation's active owner identity and explicit
checksum confirmation, refuses during a pending release, and records one
attributed audit transaction. It performs eight filesystem actions by default;
exit **2** (`purging`) means repeat the identical command to resume, or let the
worker finish. Exit 0 (`purged`) means its managed files have been removed.
`--maximum-files=1` through `100` sets the bounded batch size. A private purge
tombstone prevents retained artifacts from publishing that snapshot again.
There is a bound of 10,000 purge tombstones; reaching it refuses another purge
without silently dropping earlier bans.

Purging the current snapshot changes discovery to `purged` with no full-read
target. Publish a corrected artifact to restore an available feed. A purge does
not edit HTML, originals, backups or downloaded consumer copies. Shared media
still referenced by another retained snapshot remain public; withdraw every
affected snapshot when those bytes must disappear. Independently configured
CDN caches and consumer copies need their own withdrawal procedure.

Apply forward migration `0023-content-feed.sql` through the normal complete
migration ledger before running this bundle. Feed activation history is public
delivery state, excluded from normalized content export/restore; restored content
produces a new destination feed through ordinary approved publication.

Every successful activation, including a rollback to an older artifact, gets a
new number from the [commit-ordered site sequence](conventional-cms-content.md#commit-ordered-site-sequence).
The activation transaction stamps `cms_releases.site_sequence` and records the
release ID and frozen preview ID in the shared bounded change history. Approval,
build progress, recovery of a failed attempt and forgetting a retained release
do not advance the sequence. The existing pending-release compare-and-set makes
activation retries consume no extra number. The sequence describes committed
activation state after target verification; filesystem copying and verification
remain outside its database lock.
