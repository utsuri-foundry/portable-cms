# Publish and recover a website

Publishing turns an inspected exact preview into the live static website. Saving
content or selecting a record's published version does not change public files.
Only an owner or administrator can approve a release.

## Publish a change

1. Keep the edits and use **Publish** or **Publish changes** on the relevant records.
2. Create a published-only exact preview and resolve its failed checks.
3. Inspect the pages, languages, media and navigation.
4. Open **Releases**, choose the preview and confirm **Now** or a scheduled time.
5. Wait for completion. Approval queues work; it is not confirmation that the site
   is already live.

Draft-inclusive previews and preparation-language previews are review-only. For
access and sharing, see [exact previews](exact-website-preview.md). For a JSON
consumer, see [the content feed](content-feed.md).

Operators should configure the target and worker before the first release, and
read recovery behaviour before acting on an interrupted publication.

## Editor journey

Each record has a current revision and, while Published, a separate published
revision. Keeping edits advances only the current content; automatic saves may group an editing session into one revision. Publish and exact previews seal their selected revisions. Releases and
published-only exact previews take the published version, including its links
and media references. Draft-inclusive exact previews and live previews take
current edits; archived records are always excluded.

**Publish** selects a kept draft; **Publish changes** selects a published
record's newer saved edits. Both require the existing status-change permission,
the current revision, and publish validation for required public-language fields,
published references and ready media. They append a content-preserving
concurrency revision and select it as the published version. Editors without
that permission can keep editing and see the pending status.

**Discard changes** requires a separate confirmation and current revision. It
copies the published content into a new clean revision, making both heads name
that identical content. Discarded revisions remain in history. Publication and
discard commit their revision, relationship projections and audit together.
Returning to Draft or archiving clears the published revision and preserves all
content history.

The editor and lists show **Published, with changes** while the two heads differ.
The editor names the published revision and latest edit time; lists can filter
this status. Previews show the number of records with unpublished changes, which
can be compared with a release candidate. Site wording, terminology and design
registries retain their existing independent revision rules.

Keeping edits and publishing a record never writes the public website. Create an
exact preview from published versions, inspect it, then
open **Releases** and explicitly approve that preview. A draft-inclusive preview
is review-only and cannot be selected. Existing preflight checks translations,
relationships, prepared media, routes, design and profile requirements before
a preview enters the queue.

Older exports without a published-revision pointer select the current revision
for Published records on restore. Upgrades preserve both heads and validate their
relationship projections.

Releases uses authenticated, same-origin, CSRF-protected native forms. Approval
and rollback require confirmation and the expected current release. A stale
form returns a conflict rather than replacing a newer decision. Approval queues
durable intent; it does not mean the site is already live. The page refreshes
during bounded work and stops at **Needs operator attention**. The native
recovery action is scoped to the pending release and current active release.

### Scheduled releases

In **Releases**, an owner or administrator selects an inspected exact preview,
chooses **Now** or **At a date and time**, and confirms approval. Date inputs and
saved times use the named installation time zone, independently of the browser,
PHP default zone and interface language. Operators set the private `timeZone`
configuration to an IANA identifier, such as `Europe/Andorra`; existing
installations default to `UTC`. The configuration creator accepts
`--time-zone=Europe/Andorra`. Storage and comparisons use UTC instants. Changing
the installation zone changes display and future input interpretation, never an
existing schedule's instant. Times in the past, equal to now, or more than one
calendar year ahead are refused. Nonexistent spring clock-change minutes and
ambiguous repeated autumn minutes are refused; choose an unambiguous minute.

Approval freezes the preview identity, artifact checksum, publication target,
approving account and expected current release. Later record edits never enter
that artifact. A schedule does not reserve the pending-publication slot, so an
immediate release remains possible. It pins its exact preview against deletion.
The same controls schedule a preview that removes published content or replaces
the website with a holding page. This is an approved artifact transition, not a
per-record timer or an automatic build of current content.

Configure a regular invocation of the ordinary worker through the host's cron
facility. A signed-in editor, browser tab or public request is unnecessary.
Post-response waking alone cannot wake an idle installation at a future time.
On the first worker run at or after the instant, due intent is admitted under
the publication target and database head locks before media jobs consume the
invocation budget. File copying, verification, activation and feed delivery use
the existing bounded, resumable pipeline. Another publication already in
progress finishes first. The page explicitly states that the actual start
depends on the worker interval; completion also depends on artifact size, host
capacity and retries. This is not an exact-minute availability guarantee.

If the current release differs from the one captured at approval, the worker
marks the schedule **Needs a new approval** without publishing it. Moving its
date cannot renew that approval. Inspect an appropriate preview, cancel the old
schedule and approve again against the current release. A new preview may be
required when route history or publication configuration changed.

Owners and administrators may move or cancel a schedule until the worker claims
it. Native forms carry its expected schedule revision, and the transaction also
checks its status; concurrent moves, cancellation and worker start cannot
overwrite each other. Every successful scheduling change is attributed in the
audit, including automatic start or invalidation under the original approving
account. Editors can read Releases and schedule notices on record and preview
pages, but cannot create, move, cancel, recover or forget releases. Record
notices include content removed from the previous artifact as well as content
present in the scheduled artifact.

Scheduled, cancelled and invalidated approvals count towards the existing
100-release retention ceiling. Cancelled or invalidated approvals can be
explicitly forgotten to unpin previews. An earlier release referenced by an
outstanding schedule remains protected until that schedule is cancelled or
forgotten. Private full-installation backups retain schedules; normalized content
export/restore does not import publication authority or timers.
No browser script or external provider is required.

## Site languages

A release publishes the languages that are public in **Languages**
([0009: Site languages chosen in the CMS](../architecture/decisions/0009-site-languages.md))
and nothing else. Each exact preview reads the language choice in the same
database transaction that freezes its records. Its snapshot lists the public
languages in profile order with the primary language as default, and drops
every value in other languages, media text and layout captions included, before
the profile builds. Pages, search indexes, sharing metadata, language
alternates, the sitemap, the route graph and the holding page follow these
languages, and preflight requires values in public languages only. A changed
choice reaches the public website only through the next approved release.
A language added within the profile's envelope is published the same way, after
the profile's own languages, once it is public, its wording is complete and
preflight passes.

**Include languages in preparation** on Previews adds the languages in
preparation for review. A value missing in such a language shows the primary
language's value, as media text and layout captions already do, so untranslated
pages still draw. The snapshot and the artifact list these languages as
`preparationLocales`; the preview is labelled review only, Releases never offers
it and approval refuses it. The live preview of a record draws public languages
and languages in preparation the same way, because editors write in both.

When a release stops publishing a language, the published route history
redirects each of its addresses permanently to the same page in the primary
language, or to the primary language's home page when the page has no
counterpart there. The redirects remain while the language is off. Turning it
on again publishes its pages at their former addresses with the text they had,
and the next verified activation removes them from the history.

A profile may publish its primary language at the root and the others under
their prefix ([Routes and discovery](profile-rendering.md#routes-and-discovery)).
Choosing another primary language then moves pages between the root and a
prefix. The published route history redirects each moved address to the same
page's new address, and an address the same page keeps in the new primary
language goes on serving it.

## Site wording

Each exact preview, and the live preview of a record, reads the site wording in
the same transaction as its records. The snapshot carries the text of every key
in its languages: the installation's text where editors wrote one, else the
profile's default. A language in preparation shows the primary language's text
where its own is missing. Preflight refuses a public language whose wording is
incomplete, naming the missing keys and linking to **Site wording**, before a
preview enters the queue. Changing an address segment moves its pages, and the
published route history redirects their former addresses in the next release.

## Coming soon and launch

**Website settings** at `/settings/publication` owns revisioned publication
intent, the website name, an optional prepared square icon, and short plain-text
messages/contact details for every profile language. The holding page shows
the messages of the public languages, with the primary language as its document
language. New installations default to **Coming soon**. Upgrades with an already active website retain **Live**
unless settings were explicitly saved. Saving never writes public files.

In Coming soon mode, **Releases** can build a private holding artifact without
authored website records. Inspect the frozen page through its private
`/releases/holding/<id>/artifact/` link, then approve it explicitly. Internal
builds have their own kind and listing; exact previews and guest-share APIs
never expose them. The ordinary durable build queue and bounded worker process
both kinds. Post-response wake is optional; Cron remains recovery.

The engine controls the root-only route set, noindex head, robots file and
verified delivery descriptor. No sitemap, website routes, originals or unused
website media enter this artifact. Profile packages may supply a reviewed
`src/holding.php` closure factory returning `HoldingPageRenderer`; it replaces
only the body and generated stylesheet. The source is included in the package
checksum. Missing hooks use the accessible native engine page; malformed hooks
fail closed. Editors cannot supply HTML, styles or executable contact URLs.

The optional asset-aware profile hook names verified package fonts and images
and receives URLs for its markup and generated stylesheet. The compiler checks
the complete requested inventory and current bytes before creating any output,
copies only named assets under checksum-addressed paths, and inventories their
exact MIME, length and hash. Limits are 16 declarations, 2 MiB per asset and
8 MiB across requested assets; the complete holding release is at most 40 MiB.
Only WOFF2 fonts and PNG/JPEG/WebP/SVG images are supported, with their declared
font/image/icon roles. Package scripts and extra stylesheets are excluded.
The existing self-only font policy is unchanged. See the
[holding hook contract](profile-rendering.md#optional-holding-presentation).
Ordinary approval, private inventory verification, bounded publication and
obsolete-route removal apply to these assets too. They are not editor media,
and do not introduce website routes or a sitemap.

To launch, save **Live**, build/inspect an ordinary published-only exact
website preview, then approve it in Releases. To return to Coming soon, save
that intent with the explicit replacement confirmation, build and inspect a
new holding artifact, and approve replacement of the public routes. Settings
revision and hash fence approval of outdated holding builds. Saving after
approval cannot mutate the queued frozen artifact. Every settings change and
release approval is attributed in the audit history.

Standard publication visibly discloses its mixed-approved-version window.
Only verified completion guarantees obsolete managed routes and the old
sitemap are absent. The last live slug history survives holding publication
for a later relaunch; a first holding release fabricates none. Retained release
rollback restores exact historical bytes, independently of current editorial
intent, with an explicit public consequence confirmation. Delete unused
holding builds after forgetting any retaining releases; active sources remain
pinned.

Normalized export preserves `websitePublication`. Restore retains intent,
messages, icon selection and revision, but never activates source releases or
adopts source credentials. Legacy exports without this optional document
remain supported. Apply forward migration `0014-website-publication.sql`
with the entire outstanding migration ledger before installing this bundle.

## Operator configuration

Back up the destination and apply forward migration `0013-publication.sql`
through the normal migration procedure before running the new bundle. Set:

```json
{
  "profileRoot": "/absolute/private/path/profile",
  "publicUrlContext": {"formatVersion": 1, "baseUrl": "https://studio.example"},
  "publicationRoot": "/absolute/dedicated/static-root"
}
```

Apply every outstanding forward migration in the integrated bundle, not only
the publication migration. The ledger identifies migrations by their full
filename and checksum; never rename an already applied migration to renumber it.

The public root must exist, be writable and canonical without symlink
components, and be separate from the CMS document root and private storage.
This adapter currently requires separate document roots, for example CMS and
website subdomains. A `/cms` mount inside the publication root is unsupported.
Without a target or public URL context, Releases reports unavailability and
does not enqueue unsupported work. Existing unmanaged files at artifact-owned
paths block publication instead of being overwritten.

The target requires Apache 2.4, `mod_alias`, `mod_headers`, `mod_dir` and
`FileInfo`/`Indexes` override permission. Qualification must prove the rules
are effective, not just that `.htaccess` is writable. The target installs the
approved `delivery/apache24.conf` as `.htaccess`. Editors cannot supply rules
or executable files. The publisher does not fetch arbitrary configured origins;
served-origin checks are operator/test smoke checks after mandatory filesystem
verification.

The operator's URL context is authoritative, never a request Host header or
imported source history. Changing it requires a new exact preview. Recovery can
restore the preceding verified artifact's original context; a failed attempt
at a new context leaves preceding history unchanged. Old-context rollback needs
a matching configuration or a new build, not silent canonical URL rewriting.

## Publication modes and portability

Verified journaled publication is the portable baseline. The existing Apache
adapter implements **Standard**, not Atomic. **Atomic** is an optional future
adapter capability: prepare and verify a complete release, then switch one
qualified origin delivery selector. It does not become a prerequisite for the
CMS, a profile or a coming soon page. The decision and follow-up gates are in
[0007: Portable publication capabilities](../architecture/decisions/0007-portable-publication-capabilities.md).

Future qualification and administration must state the measured mode before
approval. Standard can temporarily serve a mixture of approved releases during
apply or recovery. A holding-to-live transition can expose new approved routes
before completion; a live-to-holding transition can retain old routes until
verified removal. A completed holding release must have only its declared page
and assets, no sitemap and no obsolete managed live routes. Private previews,
originals and unapproved working content must never be exposed in either mode.

Atomic requires additional target-specific routing, filesystem and storage
evidence. If unavailable, an otherwise qualified host remains supported with
Standard. Explicitly selecting Atomic must never silently downgrade on failure.
Existing pending work stays bound to its approved target; a future mode migration
requires an explicit checked operator procedure. No migration is implemented by
this documentation change, and current approvals retain their existing behavior.

Hosting support claims need dated runtime, storage, HTTP and recovery evidence
for each tested stack, identifying the publication mode and limits. Generic
PHP support, a provider feature list or one successful host test is not evidence
of compatibility with all hosts. Atomic support must be recorded separately
from baseline qualification and must not weaken account isolation or private
storage requirements.

## Durable publication and recovery

Approval pins the preview, artifact SHA-256, actor, preceding release and target
roots checksum. The artifact pins source revisions, package, compiler and design
registries. Changing target roots fails closed; no target migration is implicit.
One database reservation and nonblocking filesystem lock serialize work for a
single shared-filesystem target, not a distributed multi-node publisher.

### Work and artifact limits

| Boundary | Limit |
| --- | --- |
| One batch | 8 files; 256 MiB of declared asset bytes |
| One worker invocation | Up to 32 batches within its time budget |
| Ordinary assets in a release | 200 MiB aggregate |
| One prepared video | 256 MiB, with [host/profile qualification](conventional-cms-media.md#qualifying-larger-trailers) |
| Complete artifact | 8 GiB |

Qualify the intended complete site against the host's timeout; the format ceiling
is not a host capacity promise. Video bytes stream from verified private files
into temporary public files rather than loading into PHP strings.

Delivery writes assets before HTML, then rules, then removes obsolete managed
files. It prunes empty route directories without touching unrelated content or
the document root. Repeating cleanup after interruption is safe. Individual writes
use temporary files and same-directory rename.

A private journal records the plan, phase and cursor. Process exit releases the
lock; the next invocation resumes the same journal.

This is ordered in-place publication, **not an atomic document-root swap**.
Visitors may receive mixed releases during apply or recovery. The saved active
pointer is activation authority, not a claim that every response stays unchanged
during writes. The immutable source preview is never modified.

Activation verifies every declared asset and HTML route, manifest, applied
rules and absence of obsolete managed files. An HMAC-authenticated receipt pins
release, predecessor, artifact, target, context, rules and verification ID. The
service checks its signature, scope and target bytes before compare-and-set
activation. Receipt, history, active pointer and actor audit commit together.
Filesystem or activation-transaction failure cannot promote the pending release.
Recovery restores and verifies preceding exact bytes, or removes this attempt's
owned files if there was no prior release. Without an initial checked journal,
recovery never removes unmanaged files.

Three failed apply/recovery attempts require operator attention. The preceding
artifact stays pinned, new approvals are blocked, and cron stops retrying. Repair
the problem and use explicit recovery. Private artifact corruption can prevent
automatic recovery: preserve immutable previews/backups and do not bypass
verification by deleting journals or editing active pointers.

The existing bounded worker handles media, previews and publication:

```text
php tools/conventional-media-worker.php --config=/private/cms.json --max-jobs=3 --max-seconds=240
```

A five-minute cron interval suffices for recovery. Qualified FastCGI hosts can
also wake work after flushing the approval response with `post_response`;
others use cron. Latency and partial-publication windows depend on host limits
and artifact size. Local proof makes no real-host latency claim.

## History, retention and deletion

Only successful activations persist `PublishedSlugHistory`. Later snapshots
freeze it and emit direct permanent redirects for localized slug changes.
Failed attempts and draft-only slugs never create history. Exports retain actual
activated history and source context as provenance; restore never imports its
activation authority. Rollback is a new verified activation of retained exact
bytes, not a database-only pointer change.

Every release pins its preview through transactional checks and a foreign key.
There is a ceiling of 100 release records with no automatic deletion. Explicitly
forget inactive retained/failed releases to unpin their previews, then delete
unreferenced previews. Active and pending records are protected. Forgetting
does not erase audit or activated history. Private journal cleanup is best effort;
interruption may leave a small orphan journal for operator cleanup.

Collection records can be deleted only after archival, current-revision
confirmation, and checks for incoming references, owned media, upload sessions
and AI jobs. Blocked records stay archived rather than cascading into private
objects. Singleton/taxonomy deletion is not enabled. Content and editorial
revisions are removed in one audited transaction; frozen previews/releases stay
unchanged until another verified release updates the public site.

## Verify publication

After a completed release, compare served files and redirects with its artifact
manifest. Qualify rollback and interrupted-publication recovery on an isolated
target before relying on them. Keep host-specific evidence private; repository
fixtures do not qualify an arbitrary provider. Follow the
[installation acceptance checklist](reference-host-acceptance.md).

For engine changes, run the publication lane and other [affected checks](testing.md).
Complete qualification is required before release.
