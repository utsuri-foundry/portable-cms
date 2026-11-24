# Engine changelog

Engine versions come from `ProfileManifest::ENGINE_VERSION`. See the
[release policy](docs/development/engine-releases.md) for bump rules and checks.
This changelog starts at the first version that covers the changes accumulated
after 0.1.2; older commits are not assigned invented release versions.

## 0.15.0

- Every website release publishes `llms.txt`, a plain-text summary for AI
  assistants: the website name, an optional introduction per language written
  in Website settings, and each public page as a link with its own title and
  description, the sitemap's pages in the sitemap's order. Website settings
  get a "For AI assistants" section to turn it off or write the introductions.
- Website settings save while a language is off or in preparation. The form
  sent only the editing languages and the settings require all of them, so
  every save failed with a generic error.
- API and formats: `RouteDiscovery::listed()` returns the routes the sitemap lists.
  Website settings, their export embedding and the frozen snapshot's
  `websiteIdentity` accept an optional `llmsTxt` object; documents without it
  keep their meaning, llms.txt on without introductions.

## 0.14.7

- A website whose pages embed Vimeo's player can be published. Its build freezes
  the player's frame permission in the artifact and in its Apache rules, but the
  release check and the public `.htaccess` regenerated the rules without it, so
  every release of such a site was refused with `artifact_inventory_mismatch`.
  They now regenerate the rules with the permission the build froze.

## 0.14.6

- A review link left open in a background tab past its two-hour lease no longer
  locks its visitor out: failed requests on a viewer's file address do not
  count toward the throttle any more, and a viewer whose address is gone
  loads itself again for a fresh one. The throttle's own answer now says
  there were too many attempts instead of calling the link unavailable.
- In a review link the Vimeo player keeps playing in a hidden tab, as on the
  published site, and it leaves with its poster when the site swaps its page
  in place, as a profile's own navigation may, instead of staying over the
  next page.

## 0.14.5

- In an exact or shared preview, the Vimeo player keeps playing while the page
  scrolls or resizes: the page reports where its poster goes and the player
  follows, clipped to the viewer. A hidden viewer pauses it and a returning one
  resumes it. When the viewer leaves or the page moves on, the Play button
  comes back in place of the link to Vimeo, which now appears only when the
  provider does not answer.

## 0.14.4

- A shared preview, from a fixed link or a newest link, fills the window with
  nothing above it. The bar that named the site and the sharer and listed the
  preview's languages is gone; the browser tab keeps the private preview
  title and visitors change language through the site's own links.

## 0.14.3

- Record references are ordered rows, one record per row with the picture its
  type shares, its name and the remove and order controls, like image fields;
  taxonomy terms stay chips.
- On the Previews page, Open, Share and Delete sit on one line at the same
  size, Share folded reads as the small button it is, and on a phone a
  preview's words take their own height instead of a fixed one, with the
  actions under them. The links under the list sit at its right.
- The folded Search and sharing section no longer says "Nothing added yet"
  while its values are automatic.

## 0.14.2

- Explanations behind an "i" open over the page instead of in it. In browsers
  with popovers the text appears just under its control, within the page's
  side gutters, and nothing in the form moves; a click toggles it, a pointer
  resting on the control shows it until it leaves, and Escape, a click
  elsewhere or another control folds it. Other browsers keep the inline text,
  and without scripts the text still shows in the page.
- The record form's `* required fields` line is a footnote under the form,
  outside the white body, rather than its first line.

## 0.14.1

- The record page follows the agreed design more closely. Image fields show
  each image as one row, thumbnail, name, "Image details" and a quiet remove
  control, instead of a numbered card; a field that holds one image says
  "Choose image" and offers nothing more once it is full, except right after
  an upload it received. Video fields draw the same rows for the video and its
  poster image, with the rule folded into "Choose video", the two choices side
  by side, the poster choice appearing once there is a video, empty rows
  waiting hidden and no inline player. A Vimeo field with a profile hint carries
  one help control, folds its search inside "Choose poster image" per
  language, and links "Open on Vimeo" quietly. The language tag reads as part
  of the label ("Title · Català") instead of a badge. The rail is two cards:
  the saved time, the state with one sentence on what it means and Mark as
  ready as the primary action with Change status folded quietly, then "Preview
  while editing" and "Preview the website". Revision numbers, the facts list
  and the ready-revision notices are gone, the header says the state and the
  saved time, and the rail's saved time keeps the 24-hour clock. The library
  upload panel at the foot of Media appears only when no field carries its own
  upload, and the Description disclosure after the form is gone. Every changed
  string is in each administration language. Field values, actions, limits and
  upload rules are unchanged.

## 0.14.0

- The Wording page is folded by where each text appears. A search box at the
  top narrows the page without script. Entries sit in groups the profile's
  administration labels name through a new optional `group` per wording label,
  each group with a count, the first open and the rest opening on demand;
  texts without a group share "Other texts" and page address segments always
  form "Page addresses", with the warning about moving pages stated once.
  Each entry is one row: name, explanation behind the help control, a field per
  language by language name, and a quiet way back to the profile text. The save
  bar says that changes reach the website with the next release instead of a
  revision number. The administration labels schema gains the optional `group`,
  hence the minor version. The reference profile labels its texts into three groups in
  every administration language. Wording entries, their validation and how the
  compiler reads them are unchanged.

## 0.13.3

- The Media page and the media item page are quietened. Upload is the primary
  action, a disclosure that opens the native form in place with the size rule
  in one line. Filters All, Images, Videos and "Need a description" with its
  count sit beside the search; the videos filter appears only where the
  profile accepts video and the count only while it is above zero. Cards show
  thumbnail and name; a line appears only where there is something to do or to
  wait for: a file being prepared says when it will be ready, a failed file
  says to upload it again, and an image without a description names the
  languages it needs. "Describe selected" at the foot opens the batch page. The
  item page is one form: the image with its important point and a square crop
  beside it, or the video player, the size facts once, Name, a Description per
  language by language name with caption and credit folded, the decorative
  choice, Save and "Used in". Every explanation sits behind the help control
  and a ready file carries no state word anywhere.
- The Overview opens on two facts, when the content last changed and what the
  website shows now with a link to open it. The account line and the language
  count are gone. Editors no longer see a System card; owners see a "For the
  agency" card only while an upload is queued, retrying or stuck, or previews
  are not available, and Export is reached from the navigation alone.
- The library repository filters by kind and lists image descriptions so the
  "Need a description" count comes from stored text, not from a page. Every
  changed string is in each administration language. Upload rules, image
  processing and the batch description flow are unchanged.

## 0.13.2

- Video fields are tidied. The eligibility rule is one sentence stated once.
  The chosen video and poster image are two plainly named rows with a link to
  each media page and a quiet remove control; no numbered badges are drawn over
  their names any more. Each choice has one disclosure, "Choose video" or
  "Choose poster image" ("Change" once chosen), that holds the library search
  and paging, and the upload sits under the video one as in image fields. The
  poster appears once, on the player. Every changed string is in each
  administration language. Video limits, upload rules, the player, image fields
  and Vimeo fields are unchanged.

## 0.13.1

- Website settings is redesigned in the order of an editor's tasks. One line
  under the heading says what the public website shows now. "Website" holds
  the name, with where it appears, and the icon: the rule first, the chosen
  image previewed at the 32, 180 and 512 pixel sizes it becomes, and a chooser
  that lists only images that qualify. "Before launch" asks what the next
  release publishes as two explained choices, shows the message and contact
  per language by language name for the public languages only, and asks for a
  confirmation only when going back from the website to the coming soon page.
  "Administration appearance" comes last with the logo and its dark mode
  variant, each shown on its backing, and choosers that list only images that
  fit. Saving says it saved and names the next step on Releases. Every failure
  is a sentence; operator notes, locale codes and the atomic delivery sentence
  leave the page. Editors read the page; owners and administrators change it.
  The settings and their meaning are unchanged.

## 0.13.0

- Previews reads as seeing the website as it would be published: one form
  offers "Content marked ready" or "Everything, drafts included" with their
  consequence and one button, "Preview the website"; previews are named by
  their time in the site's zone, say who made them, tag drafts and languages in
  preparation, and offer Open, Share and a quiet Delete; the pending notice
  shows only when something is pending; bulk selection appears on request or
  beyond one page; a preview's page is titled by its time. Identities and
  hashes leave the page.
- Sharing is one Share panel on every ready preview, for every account: what
  the link shows (this preview, or always the newest preview), for how long
  (1, 7 or 30 days, 7 by default) and who it is for (a note). The result is
  the complete link with Copy and a sentence saying what it shows and until
  when. "Shared links" lists each link by its note, what it shows, until when
  in the site's time zone and by whom, with Copy, Extend and Stop; an expired
  link keeps Extend and Remove. The viewer shows a thin bar, "Preview of
  <site>, not public, shared by <name>", with the preview's languages. The one
  hour option, link identities, UTC times, the separate newest links section
  and the manifest address leave the administration; the bearer manifest route
  is unchanged and documented.
- Migration 0033 adds a note and a sealed copy of the secret to share and
  newest links, so a link can be copied again; authorization still uses only
  the hash. Links can be extended from today. Previews carry their creator.
  Share secrets are sealed with AES-256-GCM under a key derived from the
  installation's throttle secret.

## 0.12.13

- Releases is redesigned around one publish form and plain language: an "On
  the website now" card with the time, the person and a link to the website;
  a "Ready to publish" form that lists candidates by what they are and when
  they were built, folds older coming soon builds, asks Now or a date, takes
  one confirmation and one Publish button, and offers the coming soon build
  as a secondary action; "Scheduled" with Move and Cancel; and "Earlier
  releases" with dated rows, a readable status, Restore and a quiet Remove.
  No identities, hashes or worker words remain; failures are a sentence with
  the code folded for the agency. Releases now carry their creation and
  update times. The publication model and scheduling rules are unchanged.
- On Installation, the release identity keeps its cell to itself again; its
  folded explanation is a second definition of the same term, so operator
  tooling that reads the cell finds the identity alone.

## 0.12.12

- Explanations fold behind a small "i" control beside the label, legend or
  heading they belong to: profile field hints, group purposes on Wording, the
  design groups, the media detail and the other pages that printed help under
  a control. The text stays in the page for assistive technology and shows
  inline without scripts. Required fields carry a star after the label with
  one "* required fields" line per record form, and the words Required and
  optional leave the labels. Stylesheet and shell scripts are versioned.

## 0.12.11

- A record's rail no longer says Publish. The action reads Mark as ready, a
  ready record offers Back to draft as a confirmed native action, and the
  states read Draft, Ready and Ready, with changes in every administration
  language; Publish is the word of Releases and of the Previews' explanation
  of what a release publishes. The stored states, the status route and the
  publication model are unchanged.

## 0.12.10

- The administration sidebar groups destinations by the editor's work: Content
  (every record type, then Media), Publish (Previews and Releases whenever the
  installation serves them, whatever the profile's navigation declares) and
  Website (Settings, Languages and Wording for everyone, plus Content names,
  Design and Accounts for owners and administrators). Installation, Revision
  history and Export sit in a "For the agency" foot that editors do not see,
  and the footer no longer repeats Releases. No profile API, migration or
  export format changes.

## 0.12.9

- Reserve the first scheduled worker slot for exact previews and holding builds,
  sharing the remaining job and time budget with media. Empty lanes lend their
  slots to the other lane. Heartbeats include explicit per-lane processing and
  queued counts. No profile API, migration or export format changes.

## 0.12.8

- Upgrade and migration HTTPS clients send `Content-Type: application/json`
  with their empty POST bodies so shared-host front servers do not reject them
  before PHP runs. No profile API, migration or export format changes.

## 0.12.7

- The CMS document root's `.htaccess` keeps the host's own lines, such as the
  PHP handler block a shared host's panel writes to select a version, above a
  marker line; the engine's rules follow the marker and only they are rewritten
  when a backup installs the stable front controller. A file from before the
  marker is adopted once. Dry runs report the file's state and the handler it
  names. No profile API, migration or export format changes.

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
