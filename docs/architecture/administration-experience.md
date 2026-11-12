# Administration experience

This is the product specification for administration behaviour. For practical
editing and configuration guides, start with the [documentation index](../README.md).

## Purpose

Portable CMS administration is a quiet working environment for small editorial
teams. It must make routine publishing understandable without hiding the exact
state of records, media, previews, or exports. The interface is a reusable
product surface, not a visual extension of any public website.

The baseline is server-rendered semantic HTML and native forms. A page remains
complete and operable when JavaScript is unavailable. Small browser islands may
later improve a bounded interaction such as resumable upload, rich text,
composition, relationship selection, or locale switching. They do not own
routing, persistence, authorization, validation, or form submission.

## Ownership boundary

The engine owns:

- the shell, visual tokens, navigation hierarchy, form and table primitives;
- login, overview, records, validation, conflicts, media status, previews,
  export, settings, and safe error presentation;
- visible focus, status announcements, responsive behavior, and reduced-motion
  behavior; and
- the local stylesheet and any future engine-owned progressive enhancement.

The installed profile owns only bounded editorial facts:

- content-type and field labels;
- locale labels and their stable order;
- navigation destinations and order; and
- which capabilities and record types are available.

Profiles do not inject administration HTML, CSS, JavaScript, icons, colors, or
layout rules. The administration must remain coherent when a profile has two
record types or sixty-four, one locale or sixteen, and no optional capability.

Website settings may select an administration logo and an optional dark-theme
variant from ready PNG, JPEG or WebP library images. Their prepared `960-webp`
files must each be at most 960 × 960 pixels and 1 MiB. SVG and external URLs are
not accepted. The optional dark variant requires a standard logo. Native
independent search/page controls retain all unsaved settings; ordinary revision,
authority, CSRF and audit rules apply. The public website icon is the fallback,
then the monogram. A selected administration logo replaces the visible website
name and carries that name as its image alternative. Without a logo, the name
stays visible beside the decorative icon or monogram.

The chosen prepared images, including the fallback icon when used, are
intentionally visible before sign-in. A narrow CMS-origin
`/administration-logo/<sha256>.webp` route re-resolves the current selections,
admits only those hashes and verifies the exact bounded derivative bytes. It
opens no session, sends no cookies, retains the normal CSP and `no-store`, and
never serves originals, SVG, arbitrary media, storage paths or old selections.
Revocation cannot erase images already downloaded by a visitor.

On sign-in the logo stands alone, centred above the form in a frame up to 16rem
wide and 6rem tall. Sign in remains the page's single level-one heading, visually
hidden for a quiet form while preserving screen-reader navigation. The button
keeps its Sign in label. Small print under the form contains only
`Portable CMS · Site 1.1.1`, with the installed profile version and a translated
Site label; without a profile it contains only `Portable CMS`. The account help
and the logo-led Private editing area footer line are absent. The sidebar
shows only the logo, left-aligned with navigation in a frame up to 11rem by 3rem;
the phone top bar fits it up to 8rem by 2.25rem beside Menu. These brand links
use the website name as their accessible name and retain visible keyboard focus.
With a logo, the site version appears in sign-in small print, the signed-in
account menu and Installation details. Without one, sign-in keeps the name and
Private editing area beside the icon or monogram, and the shell keeps the name
and site version.

Square, tall and wide logos scale down proportionally, without cropping,
stretching or enlargement. The icon fallback keeps its square frame. Reserved
frames and surrounding content stay fixed while images load. Image width and
height attributes describe the selected prepared derivative's actual dimensions,
independently for each theme variant.
A single logo keeps a light backing in either theme so dark artwork stays
readable; a reviewed pair has no backing tile and uses the corresponding image,
respecting both System and explicit preferences without tinting or inversion.
Text and focus use engine tokens.
These selections are administration-only optional Website settings metadata,
audited and exported/restored with their checksum-addressed media. Empty/legacy
settings retain their previous canonical shape. Public identity is unchanged;
holding snapshots/renderers receive the public projection without these
selections. Portable CMS remains named in Settings and generic error pages.

## Information architecture

The persistent shell groups ordinary editing and publication work as follows:

1. Installation identity: the selected administration logo, or the saved Website
   settings name beside its icon or engine-derived monogram, on the sidebar,
   top bar and sign-in page. Until a name is saved, the profile identifier
   presented as words is the fallback. The subline labels
   the profile version as the site's version (for example, "Site 0.7.1") when no
   logo is selected, not the Portable CMS engine version. Profiles supply no
   administration markup or assets.
2. Overview: work to continue, items needing attention, content totals, and
   system status.
3. Navigation groups, each omitted when empty:
   - Content contains profile collections with record counts.
   - Pages contains singleton pages. A singleton destination opens its editor
     directly instead of a one-row list.
   - Library contains Media and taxonomies, the reusable things editors pick
     from inside records.
   - Publish contains previews and releases when the installation can serve
     them. Unavailable capabilities are omitted rather than shown as dead
     navigation; their direct routes still explain the missing capability.
     Editors can read Releases and scheduled times. Owners and administrators
     approve an exact preview for now or a named-zone date and time, and can
     move or cancel it before worker start. Scheduled membership is visible on
     record and exact-preview pages; later edits leave approved bytes unchanged.
     See [scheduled releases](../development/publication-workflow.md#scheduled-releases).
   - Website contains site settings, Languages, Site wording, Content names,
     and Website design. Editors see Languages, which they can read but not
     change, and Site wording, which they write.
     Each wording entry is named and explained in the administration language
     through the profile's optional administration labels, falling back to its
     manifest label and purpose. Field errors use the same name; missing-wording
     preview reports resolve saved wording identities in the reader's language.
     These names do not translate the website's authored text or alter its build.
     Installation details at `/settings/installation` is read-only for owners
     and administrators, not editors. It labels the website name, profile ID,
     site version (profile), Portable CMS engine version and installed CMS release
     separately. These are independent of the public website's publication history.
     The release is `initial` before the first installed upgrade, then the stable
     dispatcher's active release identity; a development checkout has no installed
     release. Operational details are not exposed on sign-in or error pages.
     Errors keep the generic Portable CMS mark even when configuration is unreadable.
4. A shell footer holds operator destinations such as Export, and one account
   disclosure with the appearance preference and Sign out. For every signed-in
   role it ends with a small, subdued line such as
   `Portable CMS 0.3.1 · Site 1.1.1`, using the engine and profile versions
   independently. The line stays inside the closed disclosure, including the
   phone menu, so it adds no height or scrolling to the closed sidebar.
   The engine version and source revision stay off anonymous sign-in, error
   pages and logo responses: operational version facts remain behind sign-in
   rather than advertising the engine's known flaws to anonymous visitors.

The current destination is exposed with `aria-current="page"` and a visual
marker. Page titles remain the first heading in the main region and name the
thing being worked on; editing a record shows its title, not its type. A
breadcrumb above the title gives the owning area and collection. There is one
primary action per view. Destructive or recovery actions are visually separated
from it, and recovery controls appear only when work is actually stuck.

## Layout

At wide viewports, a compact fixed-width navigation rail and a flexible main
region form a two-column application shell. Main content uses one of four
readable measures:

- narrow for authentication and simple status pages;
- standard for forms and prose;
- editor for records, which add a sticky status rail;
- wide for record tables and dashboards.

Viewport breakpoints decide only the shell. Components respond to their own
container with container queries, so a narrow main column on a wide screen
behaves like a tablet.

Below 56rem the shell becomes a slim top bar with the installation name and a
Menu button. The navigation is a native `popover` opened by `popovertarget`, so
it needs no script and keeps keyboard and Escape handling. Browsers without
popover support render the navigation inline above the main region, as before.
At 360 CSS pixels and 200 percent zoom, content remains in one reading column
and controls use the available width without horizontal page scrolling.

## Visual system

The product uses installed system fonts and an ink-and-paper palette with one
accent. It does not download font files or share typography with the public
profile. Tokens cover:

- canvas, panel, muted panel, text, muted and subtle text, border, accent,
  success, warning, danger, and overlay colors for a light and a dark theme;
- a fixed type scale from 12 to 22 CSS pixels;
- a four-pixel base spacing rhythm;
- control sizes that depend on the pointer: 32px with a fine pointer and
  44px under `pointer: coarse`, so touch keeps generous targets;
- one-pixel borders and restrained corner radii; and
- a short shadow for floating elements such as the save bar and phone menu.

Components take every color from tokens. Buttons have explicit primary,
secondary, quiet, danger, dashed, small, and icon variants; a bare `button` is
secondary, and grid parents never stretch a command.

The theme follows `prefers-color-scheme` by default. Editors can choose System,
Light, or Dark in the account disclosure. The choice is a per-browser
presentation preference stored in the strictly validated `__Host-cms-theme`
cookie through an origin-checked, CSRF-protected native form at
`/settings/theme`. The server renders the `data-theme` attribute, so the page
never flashes the wrong theme and no script is required. The shell island only
applies the choice before the same request persists it. The preference never
changes content, authority, or the public website.

Color never carries state alone. Every state has text, native semantics, or a
stable label. Motion is limited to short color and transform transitions and is
removed under `prefers-reduced-motion: reduce`.

## Page and component behavior

### Login

The login names the installation being signed into, states that the area is
private, uses correct autocomplete values, and keeps the first error adjacent
to the form. It contains no profile navigation and allocates no decorative
media.

### Overview

The overview answers three questions: what was I working on, what needs
attention, and is the installation healthy. Continue editing lists recently
updated records. Needs attention is derived from existing state such as missing
translations and media failures. Content shows totals per collection and
taxonomy, and System shows media processing, preview availability, and Export.
The media recovery control appears inside System only while a job is queued,
retrying, or needs attention.

### Record lists

Lists use a real table on wider screens with an optional thumbnail, record
title, slug or revision, language completeness, workflow state, and a readable
update time whose exact instant stays in the `time` element. The whole row is a
link to the record. A native GET form searches and filters by workflow state
with links that keep the query. Taxonomies show how many records use each term
instead of languages. Below 42rem each row becomes a compact two-line item
while keeping meaningful cell labels. An empty or filtered list explains the
state and keeps the create action available.

### Record forms

The header shows the record name, workflow state, revision and saved time.
At wide widths, a sticky rail shows save state, section links, issue/change counts
and language completeness. Scripted forms show automatic-keeping status; native
forms retain Save. Below 52rem, section links become chips and save state moves
to a sticky bottom bar.

Sections follow the editing order: Content, Media, Layout, Links, Details, then
SEO and sharing. Translated and untranslated short fields stay in profile order
and pair up when space allows. Omit empty sections. Collapse a section or group
only when every field is optional, empty and error-free; validation must never
focus a hidden required control. Profiles may select the bounded
`editorialSection` value, but cannot supply section markup.

Image fields provide one **Add images** disclosure for library selection and
uploads. Show the field's actual limits. Completed uploads append in file order
up to the field maximum; overflow remains in the library with an announced count.
Uploads preserve unsaved record input and do not save it. Removing a selection
keeps the library image. See the [media control contract](../development/editorial-controls.md#bounds-and-media-integrity)
for receipts, conflicts and separate description forms.

One header switch selects the language for every localized field. It supports
arrow, Home and End keys. Fields carry a language tag, or share one on a wholly
localized section. The switch and rail identify missing translations. Before
native validation focuses a field, the island reveals its panel.

Without JavaScript, show every active language in profile order with its own
label. Public and preparation languages are visible; off languages keep their
stored text but are hidden. Preparation fields are never required. Added
languages follow the profile's own languages and use their native names.

In Languages settings, removal uses a separate form so Enter still saves language
states. A language with text can only be turned off. For the owner, an off
language links to a deletion confirmation naming the affected records/media,
explaining retained revisions and offering Export. Require a checked confirmation
and a danger action. Show resumable progress and a final result. See
[language operations](../development/conventional-cms-content.md#site-languages).

Required state is present in text. Validation returns a linked summary and
field-level messages; the affected section is tinted and counts its issues. A
revision conflict preserves the editor's input, shows the current saved values
separately, and provides one explicit resubmission action. A failed validation
response retains the submitted revision, not a newer revision loaded during
rendering.

Rich text edits through one writing surface with a compact toolbar for text
style, bold, italic, underline, links, undo, and redo, limited to what the field
allows. The island serializes every change into the same native block fields
that PHP validates, and disables the native rows so nothing is submitted twice.
Paste keeps only the CMS's own semantic clipboard format or plain text; links
must pass the same allowlist as the server. Without JavaScript the ordered
native block rows remain the complete editing path. Localized groups follow the
record language. References render as removable chips with one add
action whose search opens on demand, and taxonomy term creation lives in the
same panel; media references render as a numbered thumbnail grid. Booleans are native checkbox
switches. Lengths attach their unit to the value.

The record shows a Layout summary card; Edit layout opens the workspace, which
draws each row as a proportional strip of its columns. Selecting a strip edits
that row, one column at a time, beside a preview fitted to its frame on wide
containers. Strips are native submit buttons, and the
island only sizes cells by their share through CSSOM, which the style policy
allows.

Fields without an editor yet, and optional capabilities the installation has
not enabled such as AI assistance, are not rendered. Saving other fields
restores unsupported values from server storage and
ignores raw structured POST values. The private configuration flag
`development.showTechnicalData` defaults to false; when deliberately enabled,
authenticated administrators see escaped, collapsed, read-only diagnostics.
This does not make a profile with missing editors production-ready.

Website > Content names changes CMS singular/plural labels only. Its native
form is administrator-only, origin-checked and CSRF-protected, and uses a
revision compare-and-set. Naming conflicts return 409 with submitted names
and current saved names. Overrides do not change stored IDs, field definitions,
relationship targets, renderer identities, profile package bytes or routes.
The original verified manifest remains the compiler and content authority;
only the admin presentation receives an overlaid copy. Localized public menu
labels remain owned by profile content/renderers and are not synchronized.

#### Repeatable groups

Use rows by default for groups of at most three short inline fields: text,
localized text, numbers, choices, booleans or web addresses. Profiles can request
rows for up to four such fields, or cards for any group.

Rows share aligned grip, field and menu tracks. A heading labels each column
once, with language tags where needed. Controls retain complete accessible names
such as “Role, row 3”. Text wraps in one-line textareas; both browser and server
fold line breaks to spaces. Below 30rem, stack fields under small labels and hide
the heading row.

Each row's native menu offers Move up, Move down, Insert below and Remove.
Disable unavailable actions, including insertion at the maximum. Keyboard users
reorder through this menu. The optional drag grip moves the stable item identity,
updates position labels and marks the record unsaved. Keep one menu open at a
time; Escape and outside clicks close it. Every action must work without scripts.

Other repeatable groups keep cards. Each card folds to a one-line summary of its
first two values (in the default language when translated) and opens to edit;
an empty item, one missing a required value, or any card of a group with an
error stays open. Both presentations end with an add button named after one
item ("Add credit", from the profile's item label, or "Add to Credits") and the
item count. Group styles are a separate engine stylesheet loaded in the head of
record pages.

#### Typed blocks

A blocks field is an ordered list of cards. Each card names its declared block
type and position and folds to a summary of its first text values. Empty cards,
cards missing required values and cards holding invalid fields open for editing.
Fields reuse the native controls, rich-text rows, media and reference pickers and
language panels. Localized composition retains its existing unsupported notice.

A labeled type choice adds a block at the end or inserts it below a selected
card. Move up, Move down, Duplicate and Remove work through native form submits;
their accessible names include the type and position. Duplicate copies all unsaved
values with a fresh identity. Item limits disable actions that would exceed the
maximum or remove below the minimum. The form rejects truncation and invalid
identities or types. Every action preserves the complete unsaved record and its
revision conflict boundary. Type changes in place and shared presets are excluded.

With scripts, grips drag the same native item order and update position labels
and the live page. Keyboard users use the move buttons. Live child marks address
stable item identities, name the block and field, and open its card when selected.
Each whole-field language keeps its own list and order; localized child fields in
an unlocalized list retain ordinary language panels.

### Live preview

Preview, beside Save, turns the record page into a workspace with the
profile's real page beside the form, drawn from the unsaved values. It is an
editing aid: the pane says nothing is saved or published, and the exact
preview remains the shareable check before publication.

- The application menu folds to the installation mark and a Menu button that
  opens the navigation as a popover. The save bar, with the record language
  switch moved into it, sits above the form.
- A separator between the form and the page moves with the pointer or the
  arrow, Home and End keys, between 20% and 60% of the window, and is
  remembered for that person in that browser.
- The pane's toolbar chooses the page (the record's own page, or the home page
  when it has none or the editor asks for it) in the record's editing language,
  the screen (desktop 1440 px, tablet 768 px, phone 390 px), Open full size and
  Close preview. Pages are drawn at the screen's real width, scaled down to the
  pane's width, and scroll inside it. A polite status says when the page shows
  the unsaved changes, is updating, or why it could not be drawn; the last good
  page stays visible.
- Below 68.75rem (1100 px) of window width, an Edit / Preview switch replaces
  the split: Preview covers the window and Edit keeps only the switch at the
  bottom of the form.
- The workspace stays open in that tab across server round trips such as
  adding a reference. Without scripts, a Preview unsaved changes button posts
  the form to the same endpoint in a new tab.

After a 0.4 s pause in typing, the island posts the form's values to
`/records/{type}/{id|new}/live-preview` with the same origin and form-token
checks as Save. The server parses them with the editorial form input and
validates them as Save would, without writing. It substitutes the record, shown
as published, into the published site, so unrelated drafts cannot block it;
runs the profile's required-value check and builder in memory; and keeps only
the requested page, its generated stylesheets and search indexes, bounded to
4 MiB. When the published site cannot draw the record's page, as on a site
whose settings or other content are still drafts before launch, the render is
repeated once with the site's drafts included, and the pane says so instead of
saying it shows the published site. A site without drafts is not drawn twice.
When neither draws the page, the published render's problem is reported, so
sites with published content keep their messages. The first problem Save or the
profile would report is returned as a plain reason. A render never creates
revisions, audit events or files.

The render is stored in `cms_live_previews` under the SHA-256 of an
unguessable workspace token and a render ID, bound to the signed-in session:
it ends ten minutes after the render, when the session signs out or expires,
and only the newest three renders per session and record are kept. The same
session reuses its token for the record, so asset addresses stay the same.
The page loads from `/live-previews/{token}/{route}?render={id}` in one of two
frames sandboxed with `allow-scripts` and `allow-popups` but without
`allow-same-origin`. The document carries the public site's content security
policy with `frame-ancestors 'self'` and the same sandbox as a policy
directive, so its own scripts run in an opaque origin even when opened full
size. Pages are `no-store`, `noindex` and `no-referrer`; verified profile
assets, media and fonts may be cached privately for the token's lifetime;
pages the render did not draw explain how to choose one. Because opaque-origin
requests are cross-site, these responses alone allow cross-origin reading;
the token is their only authority. Profiles must tolerate unavailable browser
storage in the frame.

An engine-owned bridge, served only beside live pages, reports the page's
scroll position. A new page loads in the hidden frame, takes that position and
then replaces the visible one, which stops at once; an older answer never
replaces a newer one, and a page that never reports is shown after 1.5 s.
Renders slower than three seconds switch the pane to a manual Update preview
button.

The bridge also links the page with the form:

- Hovering an element that shows one of the record's fields outlines it and
  names the field. Clicking it focuses that field in the form instead of
  following a link inside it: the field's section opens, narrow windows switch
  to Edit, and the control in the page's language receives focus.
- Focusing a field scrolls the page to its element and outlines it for 1.6 s.
  Moving between controls of one field does not scroll again, a field the page
  does not show outlines nothing, and Edit mode leaves the hidden page alone.
- The outline pairs a dark outline and light ring with a text label, so colour
  is never the only signal; it keeps its size on screen whatever the page's
  scale. Reduced motion makes both scrolls instant.

Top-level rich-text paragraphs, headings and list items can also be edited in
place when the renderer marks their stored-document row and shows its words
exactly. The form editor owns the run-preserving mapping, undo and native save;
each preview change is checked against the current full document. Changed or
transformed documents never overwrite the form. Formatting, line breaks and
block structure remain in the form, with localized guidance when attempted in
the preview. Escape restores the original structured runs. Compositions and
group rich text are excluded. See [editorial controls](../development/editorial-controls.md#rich-text-in-the-live-preview).

Short texts are edited in place. Clicking an element that shows a short text
or localized short text field exactly as the form holds it in the page's
language (the element contains only that text, and the value has no spacing the
page would change) makes the element editable instead of moving the focus: the
field's section opens and scrolls into view without taking the focus, and the
pane's status names the field. Typing, deleting and pasting plain text update
the form control as the editor types, and the form counts as changed; other
elements on the page showing the same field follow. Line breaks are refused,
Enter finishes, Escape restores the text editing started with, and the
control's maximum length applies. While an element is edited no new page
replaces the frame; the page is drawn again once editing ends, and only if
something changed. Elements whose text differs from the value (transformed,
combined, or another language's fallback), other field kinds, empty values and
read-only or disabled controls keep click-to-focus. The edited element is a
text box named after its field, and the form remains the complete accessible
path. Nothing is saved or published from the page.

For short texts, the workspace accepts edits only from the shown frame, for the
field that frame started editing, when that field still has a writable control in the
page's language, and only as a string of one line within the control's maximum
length; anything else is ignored. The page's own scripts run in the same frame,
so an edit has no more authority than typing into the form: Save still checks
and stores the value.

Elements are found two ways. Renderers mark elements exactly with
`FieldMarks`, which writes `data-cms-field` only on live pages of the record
being edited (see [Static profile authoring](../development/profile-live-editing.md#live-preview-field-marks)).
For fields the page did not mark, the bridge recognises short text fields by
elements whose whole text equals the value in the page's language, and media
fields by images showing one of their selected files; a guess never overrides
an element inside another field's mark. Record pages name each top-level field
on its outermost element with `data-field`, its label and how it can be
recognised, so the island can send the page the labels and values it needs.
Repeatable groups can also mark individual child fields through the group's
field ID and the item's stable identity, never its current position. Hovering
names the group, item position and child; clicking reveals that item's row or
opens its card and focuses the control in the page's language. Exact short texts
use the same in-place typing, length checks and Escape cancellation. Other kinds
keep click-to-focus. Item children require explicit marks rather than text guesses,
so duplicate names cannot select another item. Added, removed and reordered items
keep their identities after the next redraw; row labels follow drag reordering.
Only top-level repeatable groups are supported, not nested groups. An old editing
intent cannot change a removed, replaced, disabled or newly read-only control,
or continue across a page-language change. Nothing adds, removes, reorders, saves
or publishes an item from inside the preview.

Messages use `postMessage` in both
directions: the bridge accepts only its parent at the CMS origin, and the
workspace accepts only its own frames' opaque origin and acts on fields it
knows.

### Media, previews, and exports

Media and reference controls have a complete native form path, including
searchable bounded pickers, thumbnails, metadata, ordering, replacement,
localized labels and aliases, deprecation, inline term creation and stable
group identities. Their ownership and security bounds are defined in
[editorial controls](../development/editorial-controls.md).

Upload progress uses native `progress` and a polite live status. Processing and
preview states use stable text and status semantics. Queued pages can refresh
through HTTP without JavaScript. Export explains exactly what is and is not in
the downloaded file before its action.

### Errors

Safe error pages show the stable human heading, a short explanation, a link to
Overview, and the correlation reference. They do not echo submitted values,
exception messages, paths, SQL, or credentials.

### Website design

Each registry group is a section reached from a sticky segmented navigation.
Every token is one compact card: label and size curve inputs with attached
units, specimens drawn as inline SVG at four viewport widths, and font, weight,
line-height, and per-range overrides behind disclosures. Stable IDs move to the
card's title attribute. Update specimens is secondary to Save design.

## Accessibility contract

- Every page has one `main` landmark with the stable `main-content` target and
  a first-focus skip link.
- Navigation groups have programmatic labels and the current link uses
  `aria-current`.
- Focus uses a visible two-pixel outline with separation from the control.
- Controls have associated labels. Under `pointer: coarse` every control has a
  2.75rem hit area; with a fine pointer controls are at least 1.625rem, above the
  WCAG 2.2 AA 24px target size.
- The record language switch is a labelled group of pressed-state buttons; the
  rich-text surface is a named multiline textbox with a labelled toolbar.
- Validation and conflict summaries use alert semantics without moving focus.
- Background status uses polite live regions and does not replace newer state.
- The live preview separator is a focusable `separator` with a value moved by
  keys, its toggle and screen choices are pressed-state buttons, and closing it
  returns focus to the toggle. The page is never the only way to see a value.
  Linking from the page to a field uses the pointer; keyboard users reach the
  same link from the form, where focusing a field shows its element.
- Tables preserve headers; narrow presentation does not alter reading order.
- Group rows name every control with its column and row position; the heading
  row is hidden from assistive technology, and reordering by drag has the same
  result as the row menu's native buttons.
- The shell uses no hover-only action and no pointer-only disclosure.
- Forced colors and reduced motion retain operability.

The target is WCAG 2.2 AA. Automated markup checks are necessary but do not
replace keyboard, zoom, narrow-viewport, and screen-reader review.

## Security and delivery

The stylesheet is an engine-owned static asset copied into the installable
bundle. Pages load it from the same origin under `style-src 'self'`. There are
no inline styles, remote assets, data-derived selectors, or editor-controlled
CSS values. HTML continues to use the context-specific output boundary.

CMS HTML remains `no-store`, excluded from indexing, protected from framing,
and subject to the existing origin and CSRF rules. Styling does not change the
native form protocol or create a second API contract. Live preview pages are
the one framed exception, isolated as described under Live preview; the
workspace styles are a separate engine stylesheet loaded only on record pages.

## Islands

Engine islands are `admin-shell.js` (appearance and account panel),
`editorial-form.js` (language mode, dirty state, coverage), `group-rows.js`
(row reordering and menus), `rich-text-editor.js`
with the pure `rich-text-surface.js` mapping, `composition-editor.js`,
`live-preview.js` (the workspace, with `live-preview-bridge.js` inside live
pages), and `resumable-upload.js`. Each enhances the server form, preserves submitted
names, exposes its state to assistive technology, disposes cleanly, and leaves
a complete no-script path. Fields without an engine editor remain explicit and
their content is preserved.

The [editorial foundation](../development/editorial-foundation.md) documents
the form, keeping and terminology boundaries. The
[testing policy](../development/testing.md) defines the checks for changes.
