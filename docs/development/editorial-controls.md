# Implement media and relationship controls

Use the existing native record form for media, record references, taxonomy terms,
groups and repeatable items. Enhancements must preserve unsaved input, language
values and the form's conflict behaviour. Rich text and composition have their
own bounded workspaces within that form.

This is an implementation reference. For editor-facing workflows, see
[media](conventional-cms-media.md), [composition](composition-editor.md) and
[keeping edits](editorial-foundation.md).

## Ownership

- `Content\EditorialControls` projects flat native group fields and performs
  transient selection, replacement and ordering. It has no persistence effects.
- `Content\EditorialMediaFields` admits completed sessions owned by the current
  editor and record, or checked prepared library images, to a bounded pending
  field selection. Native and enhanced batches share its ordering, deduplication,
  overflow notice and revision-preserving form response.
- `Content\EditorialRecordHttp` orchestrates record forms after the parent
  application checks administrator, origin and CSRF authority.
- `Content\ContentService` alone validates field bounds, typed targets, ready
  media and nested values before an attributed, revisioned record write.
- `View\ReferenceControls`, `GroupControls` and `EditorialValueSummary` render
  escaped fields and human-readable conflict comparisons.
- `Content\RecordBrowser` and `Media\MediaLibraryRepository` bound lookup,
  pagination, metadata updates and current-reference queries at adapter seams.
- `Media\NativeUploadHttp` streams native uploads into the existing resumable
  service and durable derivative pipeline. It never converts pixels in a form
  request. The existing upload island retains sequential resumable batches.
- `Media\MediaLibraryHttp` serves authenticated thumbnails and metadata forms;
  it is not a public asset endpoint.

## Rich text in the live preview

Reviewed profiles can mark individual top-level rich-text rows with the
[compiler helper](profile-live-editing.md#live-preview-field-marks). Clicking an
exactly represented paragraph, heading or list item edits its words. Each change
updates the mounted form editor, its native transport, dirty feedback and undo
history. Click outside to finish, or Escape to restore the exact original runs,
including marks, not merely the original words. Saving still requires the
ordinary native form and server validation; nothing is published by typing.

Plain-text paste ignores clipboard HTML. Splitting/joining/adding/removing
blocks, new line breaks, formatting shortcuts and multiline paste are refused
and point to the form editor. Transformed text keeps click-to-focus. Rich text
inside compositions/groups remains form-only. No raw HTML crosses the frame
message boundary.

`rich-text-preview.js` owns the bounded pure run mapping and explicit mounted
editor adapters. An edit changes one contiguous word range: unchanged prefix
and suffix keep their exact marks, replacement text inherits the first removed
character's marks, and insertion between runs inherits only shared marks. At an
outer boundary insertion is unmarked, so an adjacent link is never extended
implicitly. Unicode code points determine the range without splitting surrogate
pairs; runs retain the existing 10,000-code-point bound. The server is still
authoritative for profile-specific node limits and document validation.

The workspace keeps the full render-time document locally, sends only bounded
row text descriptors to its opaque-origin frame, and compares the complete
current document before every change. A form change, stale row position,
removed editor or language switch invalidates the old intent rather than
overwriting newer input. The page stays mounted while typing and redraws after
editing ends. A rejected change focuses the current form and refreshes the page.

## Native form contract

Controls submit `_editor_action` with an operation and field identity.
Search, pagination, selection, replacement, group insertion and ordering return
the native form with submitted values and the base revision preserved. These
responses do not themselves save content. With scripts, the returned field choices
are kept by the ordinary automatic-save queue; unchanged searches create no revision.
Without scripts, **Save draft** still uses POST/redirect/GET. Validation returns 422; a stale base
returns 409 with both versions and explicit resubmission. Picker actions return
focus to their fieldset using native `autofocus`; validation and conflict
summaries take priority in the enhancement.

Conflict responses also retain `_editor_base_revision` and `_editor_base_sequence`
for transient operations, including a conflict within one grouped editing revision.
Browsing or reordering must not silently dismiss the conflict just because the
explicit save button is now targeting the newer revision.

Inline taxonomy creation explicitly saves the term to the shared namespace,
then selects it in the still-unsaved parent form. The interface explains this
boundary. Authentication, origin and CSRF precede either mutation. A full
selection prevents term creation rather than saving an unselected term. The
label-only shortcut uses the profile's localized `label` field; other required
term fields can reject creation and need the complete taxonomy form.

Rendered structured controls have an engine-owned `_present_<field>` marker.
It distinguishes clearing a selection from clients that omit an editor.
Unsupported kinds remain server-preserved. Hidden selections and markers
neither grant permission nor bypass validation.

Repeatable items store a canonical 32-hex `_itemId`. Reordering does not
regenerate it. Legacy items receive deterministic identities from saved values,
locale and original position on first editing; a native save makes them durable.
Unchanged legacy content can be preserved until then. Nested input and
relationship keys derive from field, locale, item identity and child ID. The
compiler resolves them against that profile and body. Groups cannot introduce
arbitrary children or deeper group nesting.

## Bounds and media integrity

Profile format v2 lets `record_reference` and `record_references` declare
`settings.targetTypes`: one to eight unique installed non-taxonomy type IDs.
Declare either `targetType` or `targetTypes`. The former keeps its existing
string identity representation in both profile versions. The latter stores
`{"typeId":"article","recordId":"30000000000000000000000000000001"}`,
or an ordered list of those objects. A cleared single value is `null`; a
cleared list is `[]`. Each field retains its ordinary item bound.

The native picker submits bounded `typeId@recordId` tokens that PHP decodes
before validation; these tokens never enter canonical content. Type labels,
the all-types filter, query, pagination and ordering work without scripts.
Search returns one 20-record page across the allowed types. Selections remain
visible outside a filter. Missing targets and types removed by a profile upgrade
stay visible and removable; saving unresolved selections fails validation.
Unpublished targets are reported while drafting, and a published source with
an unresolved mixed link fails frozen compilation before any artifact is built.

Normalized export retains typed values in authoritative revision bodies and
rebuilds the existing relationship index on restore. Frozen mixed projections
also carry `targetTypeId` and must exactly match body order and target type.
The snapshot schema's additive optional property leaves v1 single-target
snapshots valid. No database migration or provider identifier is required.

Search returns 20 results plus one adapter sentinel, with a maximum
100-character query and pages 1 to 1000. Selected entries remain visible outside
the page. SQL parameters escape literal `%`, `_` and `!`; record lookup is scoped
to the installed target type. The initial adapter searches current bodies with
SQL `LIKE`, not an indexed search service. It does not load complete collections
into browser or PHP memory.

Taxonomies remain profile-owned records. Reference Studio adds localized
`aliases` and a `deprecated` boolean. Search includes aliases and labels.
Deprecated entries cannot be newly saved into relationships; existing
relationships may retain them while editors migrate their content.

Media pickers admit new selections only for canonical, ready library entries.
Unavailable existing selections stay visible and removable. Replacement changes
one record selection, never original bytes, other records, historical revisions,
releases or exact previews.

Migration `0008` adds compare-and-set metadata revisioning. Renaming the label
changes `original_name` and writes audit atomically, without changing media
identity, checksums, bytes or storage keys. Normalized export retains the current
label as `originalName`; metadata audit history and the installation-local
concurrency counter remain in database backups.

Usage lists show at most 100 exact current-body references, not historical
snapshots. Authenticated thumbnails expose only verified, bounded `960-webp`
derivatives. Storage checks key, MIME, size, checksum and symlink boundaries;
missing derivatives never fall back to private originals.

Native upload accepts up to eight JPEG/PNG/WebP files, each within the existing
40 MB and 25-megapixel service limits. Host request limits still apply to the
combined multipart batch. It holds one upload part in memory, enqueues durable
processing, and reports accepted counts on partial failure. Post-response wake
runs at most the existing worker allowance; cron remains recovery. No-script
editors can reload the library to inspect status.

Every supported top-level image field on a saved collection record has one
Add images disclosure offering library selection and a collapsed native uploader.
Native multipart submission includes the complete unsaved editorial
form and its original revision; it reads only the chosen field's file input.
The enhanced uploader transfers files sequentially, then posts completed session
identities with the current unsaved form. PHP checks session completion, record
and actor ownership and image kind before appending after existing selections,
in upload order. The field maximum applies once in this shared application path.
Overflow files remain in the library and an announced count explains them.
The returned field retains description cards for every completed image, including
overflow, through the shared media description editor. Each upload panel owns a
separate description form, so metadata saves exclude the pending record and
other panels' batches. Native cards are available before derivative processing;
native description saves open a separate tab to retain the pending record.
The enhanced page saves inline and refreshes thumbnails as the original jobs complete.
Neither path saves a content revision. A stale native request accepts no bytes;
transient operations preserve the conflict and original base until explicit
resolution. Scripted uploads wait for pending edits before reading their revision;
each acknowledgement refreshes the panel's revision without replacing its controls.
Enhanced batches take turns using the shared record form. Expected PHP multipart
errors and the unchanged native size ceiling return field errors with the other
submitted values intact; non-uploaded local paths still fail closed at ingress.

A partial batch retains completed images in the pending field and names the
failure. Enhanced retries keep their session identity in tab storage, so already
completed files can be replayed without duplicate images or selections. Unfinished
sessions cannot be selected. Pending derivatives are visible as processing;
ordinary Save still requires media to satisfy the normal validation contract.
Generic library and video uploads keep their separate form and do not target an
image field.

The library picker offers native checkboxes and **Add selected images** for one
bounded page of at most 20 choices. They append in picker order, preserve every
other unsaved field and use the same maximum and overflow notice. Removing a
selection never deletes or changes its library image.

The local sandbox seeds checksum-verified fictional originals through that
same pipeline. Development seeding is excluded from installed bundles and
never overwrites existing media or edited records.

## Verification

The root gate includes domain/HTTP controls, hostile thumbnail and CSRF paths,
native upload, static artifacts, MariaDB literal searches and pagination,
metadata compare-and-set and audit, and unchanged resumable batch tests.
Its isolated Chromium lane also runs the rich-text and media-control journeys
against one seeded database, preparing fictional derivatives before review.
Rich-text structural actions share the native transient handler and preserve
the submitted base revision, just like media selection and group reordering.

For real browser review, start a dedicated Chromium debugging session and the
fictional sandbox:

```text
PORT=4183 nix develop -c just serve-reference-cms
nix develop -c node tests/browser/editorial-controls-journey.mjs
CMS_BROWSER_ORIGIN=http://localhost:4183 CMS_BROWSER_DEBUG_URL=http://127.0.0.1:9224 nix develop -c node tests/browser/editorial-journey.mjs
```

Use Chromium with a separate temporary user-data directory and
`--remote-debugging-port=9224`. Both scripts refuse non-loopback HTTP targets.
The controls journey reviews thumbnails, names, selection/order, stable group
IDs, focus, validation preservation, 360 CSS-pixel reflow and no-script upload.
It restores renamed labels and leaves one fictional upload; no parent selection
is implicitly saved. Generated screenshots go to `/tmp`. These checks are not
a WCAG certification.

`edited-field-upload-journey.mjs` additionally interrupts an enhanced batch,
replays its completed session, verifies file ordering, and exercises native field
uploads, one-action multi-select, preserved unsaved text/revision and library
retention at 360px without scripts. Run it in isolation with
`CMS_TEST_FIELD_UPLOADS_ONLY=1 nix develop -c sh tests/browser/run-editorial-journey.sh`.
