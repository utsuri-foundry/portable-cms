# Compose a page layout

A composition arranges content into rows and weighted columns. Open **Edit layout**
on a record, choose a row, select its content and adjust spacing. The layout
preview shows geometry; an exact preview shows the complete website.

Use this guide for the editing behaviour and profile integration contract. The
PHP composition services own validation and geometry, while profiles supply the
reviewed content renderers.

## Editorial behavior

The record shows a Layout summary: the row count and a proportional sketch of
the first rows. Edit layout opens the workspace in place as a native disclosure;
layout actions and validation errors reopen it. The workspace edits one selected
row at a time. A row list provides
native edit, reorder and remove buttons, with Add row and a one-step Undo last
layout action. These actions preserve the complete unsaved record and do not
create revisions. Save uses ordinary POST/redirect/GET. A stale revision returns
409 with the editor's input retained and a summary of the current saved layout.

Each column selects None, a named ready image, a rich-text field, or another
profile-enabled source. Share is a positive relative weight: 50/25/25 and 2/1/1
produce the same tracks after subtracting gaps. Asset width is a separate
percentage of the column's padded inner width. Images retain their aspect ratio.

Composition v2 adds optional `link: {typeId, recordId}` and `captions: {locale:
plainText}` to image sources. Other sources cannot carry them. V1 documents
remain valid and keep their original version until an editor adds image details.
Profiles using manifest v2 opt in with `settings.formatVersion: 2` and declare
allowed record types through the composition
field's optional `settings.targetTypes` (at most eight installed, unique types;
taxonomy namespaces are excluded). The native image inspector selects a named
record from a bounded search page and edits a caption for every site language.
Find link targets searches and pages without saving; selected targets remain
visible outside that page. The row strip shows the link and default-language
caption. Non-image selections discard image-only controls.
V1 field declarations retain their original controls and visibly disable image
details until the reviewed profile producer opts in. V2 fields accept existing
v1 documents without rewriting them merely for loading or rendering.

Captions are UTF-8 plain text, at most 500 characters, without markup or control
characters. A blank localized caption falls back to the authored default site
language, then the shared media caption. Credits remain media metadata. Links
carry identities, never stored URLs: reviewed public adapters resolve actual
frozen localized record routes. The immediate private layout iframe uses a
published target's administration route; its media URLs are also private.
Both adapters use the same semantic asset renderer and identical geometry.
Each linked image renders exactly one anchor and, when present, one figure
caption outside it. Authored image alternative text names the anchor; a
decorative image instead uses its resolved caption as the link's accessible name.
A linked image with neither fails validation.

Missing or unpublished targets are visible in the inspector and rejected by
layout preview and publication. Drafts may prepare links to other saved drafts.
Frozen published selections reject targets absent from that selection; review
previews may include explicitly selected saved drafts. Normalized export and
restore retain the complete authored link identities and caption maps and
validate them against the installed profile and restored record graph.

With script, the inspector shows one column of the selected row, chosen by tab
or by its cell in the row strip; without script every column stays visible.
Fine tune discloses width, nine anchors, text role, and four padding lengths.
Page spacing discloses inherited column gap, row gap, page inset and stacking
threshold. Rows can override their gap and minimum height in Row settings. Lengths support
bounded px, rem, vw and vh values, or named spacing tokens. Zero inset permits
edge-to-edge composition. Selecting Never stack explicitly overrides a profile
default. Numeric values and unavailable selections remain visible on rejection.

Stable row and column identities survive reorder. Transport omits constant
defaults but resolves to the same v1 layout. An end marker detects PHP input
truncation; hidden layout and undo documents are bounded to one megabyte each.
Only the selected row exposes inputs, including at the 256-row/12-column limit.
Native form submission remains the complete mutation path with scripts disabled.

## Preview and publication

Update preview posts unsaved native controls to the authenticated record route,
targeting a script-free sandboxed iframe. It does not save, queue a build, publish,
or return a public URL. Identity, same-origin and CSRF checks apply before parsing.
Its no-store response permits only same-origin framing and an exact SHA-256
stylesheet digest, never arbitrary styles, scripts or editor markup.

The native island coalesces input briefly and holds one active preview and one
replaceable latest intent. Sequence-scoped completion rejects stale navigation.
Dispose removes listeners, observers, timers and request snapshots. Failures are
recoverable through Update preview. No general DOM-swap or client router exists.

Phone (390 px), Tablet (768 px) and Desktop (1440 px) set a real iframe
viewport. The island measures the rendered page, sizes the frame to its full
height and scales it by the smaller of the width and height ratios, so the whole
page is visible without inner scrollbars, and states the width and scale. It
measures again once web fonts are ready. Scaling for display does not change
the logical viewport, font size or text wrapping. Open at full size posts the
same unsaved preview to a new tab. The preview loads only while the workspace
is open. Typography, fonts, breakpoints, spacing and composition CSS
come from the canonical PHP resolvers. The iframe is a layout preview, not the
whole profile page: its pale column guides are editor-only. Full website preview
remains a separate workflow.

Reference Studio consumes the same renderer and emits `assets/composition.css`
from published records and frozen registries only. Browser acceptance compares
text and column geometry with the compiled route, with fonts and images loaded.
Profiles must avoid extra layout-affecting rules inside the composed subtree or
explicitly prove parity with their own acceptance fixtures.

## Enabled scope

The workspace edits top-level shared composition fields. Rich-text and allowed
text-block sources may themselves be localized, and preview language selects
their locale. Localized layouts and composition nested inside groups remain
preserved and visibly unavailable rather than offering a lossy editor.

Profiles may enable existing prepared video-reference sources and simple text
blocks through `videoEnabled` and `blockFields`. The shared asset producer verifies
video MIME and requires a separate image poster. Prepared video ingestion uses
the profile-declared `preencoded_video` capability
and the host-qualified PHP container inspector, without transcoding. Reference
Studio enables video sources in its project layouts. Arbitrary HTML/CSS blocks are
never permitted. Public asset adapters must supply verified immutable URLs;
private preview adapters supply authenticated ready media.

Localized and nested composition editing remains unsupported. Preserve those
saved values and show the unavailable state instead of offering controls that
cannot keep them.

## Verification

`tests/php/composition-test.php` covers normalization, hostile input, legacy
defaults, geometry, row/column operations, native form budgets, previews, save,
CSRF and conflicts. `tests/unit/composition-model.test.js` covers latest-wins
preview sequencing. `tests/browser/composition-journey.mjs` runs in the hermetic
MariaDB/browser gate and compares immediate and compiled layout geometry, named
widths, keyboard/native structural actions, validation and no-script editing.

Before review, run the blocks/composition and other [affected checks](testing.md).
Full qualification is required before release.
