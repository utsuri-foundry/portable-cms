# Engine changelog

Engine versions come from `ProfileManifest::ENGINE_VERSION`. See the
[release policy](docs/development/engine-releases.md) for bump rules and checks.
This changelog starts at the first version that covers the changes accumulated
after 0.1.2; older commits are not assigned invented release versions.

## 0.12.6

- Include Apache licence, copyright, third-party inventory and retained upstream
  notices in installed runtime bundles. No profile API, migration or export
  format changes.

## 0.12.5

- Automatic record keeping retains the last valid integer and decimal values
  when an edit is rejected. Valid sibling edits still save, and optional numbers
  can still be cleared deliberately. No profile API, migration or format changes.

## 0.12.4

- Exact and shared preview shells host Vimeo players outside their opaque pages.
  A validated Play request keeps the poster until readiness, preserves autoplay
  and do-not-track, and sends only the CMS origin as referrer. Scrolling, resizing,
  navigation and failures restore the poster/link. Page isolation, popup limits
  and ordinary public/direct-artifact playback are preserved.

## 0.12.3

- Vimeo posters remain visible until the player acknowledges readiness. Failed or
  unresponsive players restore the Vimeo link after at most five seconds, including
  the live workspace and opaque preview sandboxes. Playback still starts only on Play.
  Shared viewers open validated Vimeo fallback links through their shell while
  preserving the sandbox's denial of arbitrary popups.

## 0.12.2

- Opening a pending exact preview wakes one preview job after the response on
  qualified FastCGI hosts, matching ordinary preview creation. Reusing a ready
  preview wakes no work. Waiting pages distinguish queued and building states
  and explain scheduled-worker waits on cron-only installations in all five
  administration languages. Public artifacts and portable formats are unchanged.

## 0.12.1
- The administration shell primes scripted record controls before first paint,
  avoiding a flash of native Save and Preview changes buttons. Native controls
  return if enhancement fails; scripts-disabled forms keep their save-first help.
  Text entered before handoff is kept, and language-button names stay stable.
  Profile, database and portable formats are unchanged.

## 0.12.0

- The record save bar separates Live preview from Open exact preview. Exact
  previews wait for automatic keeping, include drafts, reuse an unchanged kept
  site and otherwise show bounded build progress with ordinary diagnostics.
  Native forms save nothing and explain saving first.
- Signed-in newest addresses retain the record's page (or its home fallback) on
  reload while immutable files stay pinned. Migration 0032 adds bounded, hash-only
  file leases tied to live editorial sessions; no guest share link is created.
  Public artifacts and normalized export/restore formats are unchanged.

## 0.11.0

- Record edits are kept automatically with scripts: after a short pause, on blur,
  and when hiding or leaving the page. The save bar reports progress, saved time,
  failures and Retry; native Save remains available without scripts.
- Automatic saves share one editing revision per page and person until ten
  minutes of inactivity, an intervening save, publication or an exact preview.
  Every commit advances the site sequence for optimistic concurrency. Published
  and previewed revisions remain unchanged. Migration 0031 adds private editing
  metadata; exports retain the authored revisions without transferring sessions.
- Invalid fields retain their previous stored value while valid fields are kept;
  all local input stays visible. Serialized retries survive connectivity and
  sign-in failures without retyping, and stale tabs open the native conflict view.
  Previews and publication wait for pending edits. New records become drafts on
  the first kept change. Interface text is available in all five languages.

## 0.10.0
- Keep 50 historical revisions per record by default, with administrator settings
  from 10 to 500. Current and published revisions are additional protected heads.
  Saves prune bounded batches atomically; confirmed reductions support resumable
  cleanup, removal audits and a measured export-capacity warning.
- Migration 0030 adds installation-local history settings and cleanup progress.
  It does not delete history. Frozen previews/releases retain exact content;
  normalized export/restore formats are unchanged and retain revision gaps.

## 0.9.0

- Fixed and newest shared review pages run the website's scripts with the published-site policy
  inside an opaque-origin sandbox. The private notice stays in the outer viewer;
  forms, popups, storage and administration access remain blocked.
- Migration 0029 adds hashed, share-bound file capabilities with a renewable
  two-hour lifetime, immediate parent revocation and deletion checks, and bounded
  inventory. Newest browser leases remain on their resolved build through renewal;
  bearer downloads are unchanged. Installed behavior changes; profile APIs and portable formats are unchanged.

## 0.8.0

- Publication administrators can create revocable newest-preview links for up to
  30 days. Browser viewers follow the newest ready website preview on reload;
  bearer-authenticated manifest redirects pin complete tool downloads to one
  verified build. Fixed preview shares retain their existing behavior.
- Migration 0028 adds hash-only newest-preview capabilities, per-preview audited
  resolutions and an index for newest ready website builds. Revocation and expiry
  close pinned addresses too; normalized content/export formats are unchanged.

## 0.7.0

- Records retain a published revision independently of current edits. Saving a
  published record leaves the next release unchanged; Publish changes validates
  and selects the saved version. Confirmed Discard changes appends a clean
  revision containing the published content, retaining history.
- Published-only exact previews and releases use the published revision and its
  relationships. Draft-inclusive and live previews use current revisions.
  Editors see pending changes, the published revision, and the latest edit time;
  record lists filter pending changes and previews count them in five languages.
- Migration 0027 pins existing Published records and retains relationship
  projections for both heads. Draft and archived records have no published head.
  Normalized export v1/v2 gain optional `publishedRevisionId`; legacy exports
  retain their original meaning. Restore, database reports and upgrade checks
  preserve and account for the published version. Profile field APIs are unchanged.

## 0.6.0

- Profile API: `vimeo_video` fields store a normalized external reference, an
  owned poster and an authored title, with optional localization. Native controls
  accept Vimeo addresses without fetching metadata or embedding a player.
- `VimeoPlayer::render()` renders a local poster and accessible Play button,
  retaining a Vimeo link without scripts. Only Play loads the DNT player.
  Public, live, exact and shared previews conditionally permit the Vimeo frame;
  shared previews grant only the checksum-pinned engine player script.
- Profile v1/v2/v3, snapshot v1 and export v1/v2 gain the additive value contract.
  Artifact v1 optionally records the single allowed frame origin. Profiles without
  a value retain their public bytes and policy. Database migrations: none.

## 0.5.1

- Video fields use visual video and poster pickers with native, muted players.
  Record editors load no video bytes until playback; media details load metadata.
- Ready library videos show duration and browser-captured frames. Small private
  frame hints regenerate after restore and never become authored poster images.
- Profile API, export contracts and database migrations: unchanged.

## 0.5.0

- Profile API: fields accept an optional plain-text `hint` of up to 160
  characters. Administration labels may translate hints by field path. Profile
  v1/v2/v3 and administration-labels v1 schemas gain these additive properties;
  hint-only upgrades preserve authored content without a mapping.
- Image fields offer library selection and a collapsed native uploader through
  one Add images control. Upload guidance is compact and uses each video field's
  own limit. Record media-description guidance appears once.
- Single-language editing hides language tags. Details fields stay together in
  profile order, and the media library names both images and videos.
- Rich text settings accept `maximumBlocks: 1` for a compact single-line editor
  with the permitted marks. Save, preview, publication, restore and import enforce
  the same block bound; unconfigured rich text fields retain their behavior.
- Database migrations: none. Export, snapshot and installed bundle envelopes
  retain their existing contracts.

## 0.4.0

- Video fields optionally restrict duration, width, height and sound alongside file size.
  Editor choices, saves, publication and portable/frozen graphs share the limits.
- Frozen prepared media exposes verified duration and audio-track presence to renderers.
  Legacy prepared bytes are inspected on demand; no database migration is required.

## 0.3.1

- Sign-in keeps its single accessible heading visually hidden and reduces small
  print to the Portable CMS name and site version. Logo, icon and monogram
  branding retain their existing frames and fallbacks.
- Every signed-in role sees the engine and site versions at the end of the
  account disclosure. Anonymous sign-in, errors and logo delivery expose no
  engine version or source revision.
- Profile API: unchanged. Database migrations: none. Profile manifests, exports
  and installed package formats retain their existing contracts.

## 0.3.0

- The native editor composes typed blocks with translated collapsible cards,
  add/insert, move, duplicate and remove actions, grip dragging and live-preview
  field links. Actions preserve unsaved values, languages and revision conflicts;
  invalid fields open their cards, and unavailable video controls retain saved values.
- Profile API: `Blocks::items()` adds an optional trailing `partial` flag for
  transient editor envelopes below their item minimum. Its default remains
  strict; saved, frozen and exported content retains the existing requirements.
  The documented blocks module is now registered in the profile API release gate.
- Selected Website logos lead the administration and sign-in branding while
  fallback icons retain the visible Website name and secondary product details.
- Database migrations: none. Profile manifests, canonical block values, export
  formats and installed package formats retain their existing contracts.

## 0.2.0

Profile-facing additions and changes since 0.1.2:

- `FieldMarks::itemAttribute()` and `blockAttribute()` for live group and rich-text
  editing; reusable field groups in profile manifest v3; typed content blocks.
- Responsive raster widths and authored media descriptions/focal points in frozen
  media; Website-owned raster images use explicit null ownership without a record.
- Multi-face font families in design registries v2, including weight/style bounds.
- Opt-in static content feeds and their activation/retention contracts.
- Installation-owned site sequence and language-text deletion, scheduled frozen
  releases, Website administration logos, and broader native editorial controls.

Forward database migrations introduced since 0.1.2:

- `0021-site-sequence`: commit-ordered changes and release ordering.
- `0022-language-text-deletions`: bounded resumable deletion of off-language text.
- `0023-content-feed`: public activation history and retained feed work.
- `0024-content-import`: source checkpoints for import into an existing installation.
- `0025-release-schedules`: durable approval and revisioned scheduling.
- `0026-website-owned-images`: nullable image ownership and upload/import receipts.

Formats and operator behavior:

- Full-history normalized export v2 with bounded checksum-addressed JSONL chunks;
  private content-package v1 handover proof and resumable installed operators.
- Profile v3 field groups; design registries v2; feed v1 discovery, snapshot,
  page and activation documents; additive snapshot/export Website image ownership.
- Bundles record the engine version, source Git revision and whether the checkout
  was dirty, in both the checksum inventory manifest and private runtime marker.
  Upgrade dry runs report current and incoming build provenance. Legacy builds
  remain readable with explicitly unknown source revision/dirty state.
- Incompatible profiles fail before rendering or installation database work, with
  required and running versions in safe operator errors. The repository gate
  requires a minor bump for migrations, structural contracts and declared profile
  API changes, and a version increase for other runtime fixes.
- Engine-range-only profile updates with identical known record contracts need
  no content mapping, after both packages pass their respective engine checks.
- Reference Studio 1.1.1 corrects its engine minimum to 0.2.0 for the APIs it uses;
  content mappings, public routes and authored values retain their meaning.
