# Build and inspect an exact preview

An exact preview is a complete static website built from saved content. It lets
you inspect the artifact before publication. A live preview instead shows current
form edits while you work on a page.

## Open a preview

1. Keep your changes. With scripts disabled, save the record first.
2. Choose **Open exact preview** from the record editor for a review including
   saved drafts, or use the Previews page to choose the build mode.
3. Wait for the worker. On cron-only hosting, it starts at the next scheduled run.
4. Follow any failed-check links, correct the content and try again.
5. Inspect the ready website. Share it through a [private review link](preview-sharing.md),
   or select an eligible published-only build in [Releases](publication-workflow.md).

A ready preview is immutable. Later content edits require another build; they do
not alter a link already showing a frozen version.

## Current delivery boundary

Private exact previews compile the saved website. Editors select versions for
the next release with Publish or Publish changes. Published-only previews read
each selected published revision; review previews may include current edits and
saved drafts. Release activation, rollback and retention
use the [publication workflow](publication-workflow.md). Changing a record's
status does not write to the public website or make it immediately live.

The composition iframe is an immediate layout preview. The workflow
preview is a separate, complete immutable static artifact built from saved
revisions. Scripted editors keep pending edits before requesting a preview;
the preview endpoints themselves never change content or publish a draft.
The default mode includes only records with the frozen `published`
workflow state. The native **Include saved drafts (review only)** option adds
saved `draft` records, not unsaved forms. `archived` records remain excluded in
both modes. Incomplete draft content can still fail the exact-site preflight;
the report preserves the selected mode for retry.

Each queued preview freezes `includeDrafts` alongside the content. The list and
status page display its mode. Draft-inclusive snapshot and artifact contracts
carry `includeDrafts: true`; absent or false means published-only, preserving
the meaning of older artifacts. Profile builders must use
`FrozenSnapshot::includes()` (or `recordsOfType()` with its default selection)
instead of inventing their own publication-state filters. Original record
states are retained; previewing a draft never masquerades as publishing it.

A preview may also include the site's languages in preparation when the editor
asks for them. The preview row records `include_preparation`, the snapshot and
artifact carry `preparationLocales`, and the list and status page show it as
review only. See [Site languages](publication-workflow.md#site-languages).

The publication service calls `PreviewService::releaseCandidate()` before
promotion. It requires a ready published-only preview and a checksum-verified
`artifact.json` without the draft-inclusive marker. Both the stored preview
mode and artifact mode are checked; successful rendering alone is not release
authority. This eligibility guard does not implement public activation or
replace full release-target verification.

## Opening from the record editor

The save bar offers **Live preview** beside **Open exact preview**. Live preview
draws the page while it is being edited. Exact preview waits for automatic
keeping, then opens the whole saved website including drafts in a new tab.
Failed keeping leaves the tab closed and focuses the save error. Without
scripts, save first: the separate native form opens saved content and never
saves fields from the editor.

Opening reuses the newest ready draft-inclusive website only when its frozen
input still matches the saved site. The comparison includes changes elsewhere,
wording, languages, design, identity and media, and does not depend on clocks
being different. A matching queued build is reused; otherwise ordinary preview
capture creates a job and seals editing sessions. The tab waits for that build
or a newer ready build, and displays the same safe failure diagnostics as the
previews page. Incomplete drafts can therefore prevent this preview from building.

On qualified FastCGI hosts using `post_response`, a pending opening finishes its
redirect before waking one job from the preview queue, just like creation from
the previews list. Reusing a ready capture wakes nothing. The waiting tab names
queued and building states separately; `cron_only` explains the scheduled-worker
wait instead of suggesting that construction has started. It keeps the two-second
Refresh header and a native **Refresh status** link. See
[host wake requests](media-processing.md#requests-that-wake-work-after-the-response).

The signed-in address `/previews/newest/records/<type>/<id>?locale=<locale>`
resolves the record through the verified artifact's route identities. A record
without a route opens home; when a previous draft-inclusive capture of the same profile package establishes
this, the editor button says **(home)**. Reloading the stable address resolves
the newest ready draft-inclusive website at the same record and language.
The individual frame and its assets remain on one immutable verified build.

This creates no sharing link. Opaque preview files use hashed leases tied to
the active editorial session and its current account permissions. Leases last
at most two hours, are bounded to 20 per session, and stop on session expiry,
revocation or account security changes. Profile scripts run inside the existing
opaque preview sandbox, without CMS cookie or storage access.

## Cleaning up previews

The website preview list uses timestamp/ID keyset pagination with 20 rows per
page. Older snapshots remain reachable even beyond the former 50-row limit.
Newer snapshots do not shift older page boundaries. Selection is page-local
and cleared on navigation, including browser Back restoration.

Native checkboxes select exact IDs. **Delete selected** opens a read-only review
showing identities, dates, eligible/protected counts and active review links.
**Review all eligible previews on this page** reviews only the finite IDs shown
on that page, never a filter or the whole installation. Without JavaScript,
the selected count is supplied on the review screen. Cancel makes no changes.
Confirmation is HMAC-bound to the actor, the approved IDs and a 15-minute expiry.
Malformed, empty or oversized selections are rejected before any deletion.
All mutations retain the existing editorial role, origin and CSRF checks.

Only terminal ready or needs-attention website previews are eligible. Building
or queued snapshots, holding builds and every snapshot referenced by any
retained release row are protected. This includes failed releases and rollback
history, not only the active release. Protection is rechecked under database
locks for each item. A protected item cannot silently become approved later.
Release references are never forgotten by preview cleanup.

Each item has an independent result. A confirmation handles at most 20 IDs,
with a shared 4,096-filesystem-operation budget and a three-second scheduling
deadline. The deadline stops new work; an individual host/database operation
can take longer. Deletion temporarily caps the database row-lock wait at one
second and restores the connection setting afterwards. Failures or pending
items have an explicit signed retry of the remaining exact IDs. Successful
items do not gain another deletion audit,
and newly created previews are never added to retries. Busy cleanup locks do
not block a request. Lock stripes have a bounded 256-inode footprint.

The single-delete and batch paths share the guarded transaction. After locks
and release checks, an owner-only journal is persisted and the private artifact
directory is quarantined through a bounded rename. Preview, owned terminal job,
share-link cascade and attributed deletion audit commit together. Rollback
restores quarantine. The preview-row foreign key also serializes concurrent
release insertion, so a source cannot be linked after deletion has authority.

Post-commit purge is bounded and never touches originals, shared derivatives,
authored records or public release files. Incomplete purge is not reported as
reclaimed disk space. The private journal survives request termination: if the
database row remains, recovery restores the artifact before rechecking guards;
if absent, recovery only finishes purging. Recovery of a protected snapshot is
restore-only. Its signed retry retains that restriction even if protection
later changes; it never becomes a new deletion approval. **Review interrupted cleanup** on the
list offers bounded manual recovery, including when a deleted row no longer
appears there. No new cron or hosting capability is required. There is no
in-product undo. Associated review links stop working after database deletion;
already delivered copies cannot be recalled.

## Installation boundary

Private runtime configuration may declare `profileRoot`, an absolute directory
outside the document root. `ProfilePackage` verifies its manifest, assets,
renderers, support files and licences. Enabling `profileRoot` requires a trusted
`src/compiler.php` entrypoint returning a closure accepting the package and returning a
`ProfileSiteBuilder`. It is hashed with the support sources, never uploaded or
chosen by an editor. The engine has no Reference Studio class dependency.

`build-conventional-cms-bundle.php --profile-root=<verified-profile>` packages
only the reviewed manifest, sources, licences, renderer entrypoints and public
assets into `private/profile`. It does not copy fictional fixtures, development
tools or client content. Configure `profileRoot` to that installed private path.
The installed runtime and worker need neither Node nor Composer on the host.

An installation without `profileRoot` retains the legacy fixture profile/compiler.
The local Reference Studio sandbox sets this configuration automatically.

## Snapshot and build protocol

The authenticated, same-origin, CSRF-protected native preview form freezes the
current record revisions, workflow states, typed relationship projections,
ready derivative metadata, design registries and verified package checksum in
one repeatable-read transaction. Preflight runs against that projection before
enqueueing. Missing publication-required translations and invalid profile
content return an escaped editor-facing report, not an orphan build job.

Each failed check records a `preview_check_failed` audit event after the enqueue
transaction rolls back. It retains the check time, selected draft mode, safe
message keys/parameters and engine record links, never exception text or a
snapshot. The retry response visibly shows its timestamp, including milliseconds,
and submits the same draft option. The latest report also appears on the previews
page and under **Needs attention** on the Overview, including after a new session
or process starts. A later successful website `preview_queued` audit fact clears
it; deleting a preview or building a holding page does not erase that outcome.
Failed checks queue no jobs and do not wake the worker. Unexpected exceptions
retain the existing private error boundary.

Before the first check, profiles implementing `PreviewStartupRequirements` name
the singleton types their builder already needs. Overview and previews link
missing records to their native creation forms and saved unpublished records to
their editors, with required field labels and a reminder about published-only
versus draft-inclusive previews. Reference Studio declares Website settings,
Home and About. This advisory declaration changes no publication rule and does
not replace full preflight. Builders without it retain their existing behavior.

The bounded conventional worker claims the durable job with an expiring lease.
It translates the private projection into the versioned frozen-snapshot
contract and uses the ordinary static compiler and profile builder. It never
queries live records during compilation. Package drift fails terminally and
requires a new preview. Media and uploaded fonts are checksum verified against
the frozen metadata. A canonical snapshot identity includes the complete
projection, design, package identity and microsecond UTC timestamp.
The selected draft mode is also part of that immutable identity. Migration
`0012` stores it separately on the preview projection; older previews default
to published-only.

The legacy derivative key `960-webp` is translated to the portable
compiler variant `display_960_webp` by the private media adapter. Filesystem
keys never become content identity. Public bytes receive the portable variant
name. The complete static `artifact.json`, including profile/compiler/design
provenance and file hashes, is itself included in the private preview inventory.

After filesystem verification and private commit, the repository accepts
completion only from the current unexpired lease. Ready status and its audit
event become durable together. An interrupted worker is recoverable after lease
expiry through the ordinary cron worker, including installations with a
five-minute cron floor. Expiry of the final permitted attempt moves both job
and preview to `needs_attention`, rather than leaving a crashed build running
forever. A request can wake the worker after FastCGI flush;
environments without that facility rely on cron. Status pages refresh while
queued/building, and also provide a native Refresh status action.

## Private serving and portable URLs

The administration artifact route requires an authenticated administrator
session. A separate share capability can authorize one ready preview without
a CMS account. Knowing the preview ID grants no access. Responses are `no-store` with
`X-Robots-Tag: noindex, nofollow, noarchive`; the administration security headers
remain active. Native directory routes map only to declared `index.html` files.
Unknown paths and traversal are rejected. Each served file is checked against
its recorded size and SHA-256 digest.

Reference Studio emits mount-independent relative navigation, stylesheet,
module, media, rich-text link and font URLs. Assets and links therefore stay
beneath `/previews/<id>/artifact/`, including deep localized routes. Serving
does not inject a base element or rewrite HTML, CSS, JavaScript or search JSON.
The same bytes can later be promoted to a public static root. A future profile
must prove this portability; arbitrary root-relative assets are not repaired
silently by the engine. Internal rich-text paths must use the reviewed local
URL vocabulary, while allowed external HTTPS links remain external.

## Delivery and diagnostics

Profiles with speculation rules deliver a same-mount `Speculation-Rules` header
on frozen HTML and serve verified rules JSON through the same authorization as
every other artifact. Headers come from the snapshot's inventory, not live profile
settings; `no-store` and noindex remain intact. Top-level previews can prerender
likely links in supporting browsers. The shared-review iframe runs profile scripts
in an opaque-origin sandbox. Browsers do not act on subframe speculation rules.
See [profile rules](profile-rendering.md#speculative-navigation).

Preflight names the affected record type and frozen title, field and language,
page address or required settings type for each reported cause. Profile builders
use [typed failures and context wrappers](profile-rendering.md#build-failures-and-private-diagnostics)
to distinguish missing translations, broken relationships, unsupported sharing
images and unexpected renderer failures. Both failed checks and background build
details show a correlation reference for the configured private error log.
Technical exception text and source locations never appear in the editor or
public artifact. Safe structured causes survive worker failure in job progress
and are translated using the viewing administrator's interface language.

## Deletion and retention

Only ready or terminal failed previews can be deleted. Storage is quarantined
before transactional database/job deletion; database failure restores it.
Cleanup and actor attribution follow the existing guarded preview service.
There is no automatic retention deletion . Release references
are guarded transactionally and by a foreign key so a rollback artifact cannot
be removed. Explicit release retention cleanup unpins only inactive, nonpending
references. See [publication and recovery](publication-workflow.md).

## Verification

For engine changes, run the exact-preview and affected browser lanes in the
[testing policy](testing.md). Check frozen output hashes, native navigation,
failed builds, interrupted jobs and access after revocation or deletion.
Repository fixtures are isolated local installations; qualify a real host
separately with the [installation checklist](reference-host-acceptance.md).
