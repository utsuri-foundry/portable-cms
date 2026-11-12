# Editing and keeping records

The record editor uses native forms with optional JavaScript enhancements.
Automatic keeping stores edits in the CMS; selecting a published revision and
releasing the website are separate actions. This guide covers save behaviour,
conflicts and the implementation boundaries for editor work.

## Keeping record edits

With scripts, record editors keep changes after 1.5 seconds without typing, when
a field loses focus and when the page becomes hidden. Save is replaced by a
progress/time indicator and Retry on failure. The separate polite live region
announces failures and recovery, rather than each ordinary save. Only unkept
changes trigger the leave-page warning. Without scripts, the native Save form
and its validation remain available. Wording, design, accounts and media details
retain their existing save behavior.

The queue serializes requests and retains a request identity across retries,
including lost responses. Backoff rises from one second to at most 30 seconds.
Input remains in the page through network failure or session expiry; signing in
again in another tab lets the original page retry with the renewed CSRF token.
No browser content cache is created. Keep the page open until saved: browser
termination cannot guarantee delivery, and keepalive delivery has browser byte
limits. The pending warning remains authoritative.

A first edit creates a Draft and moves to its normal record page after all input
has been kept. Subsequent changes share an editing revision for the same page
and person, including reloads in the same tab. Only its opaque session key is kept
in session storage; denial starts a fresh editing session. Ten minutes without
changes, another person's or page's save, a
workflow action, or exact-preview capture starts a new revision. Each committed
save still advances the site-wide sequence, which is the form's concurrency
token even while the revision number stays the same. A stale tab stops keeping
and opens the existing conflict view with its complete input preserved.

Incomplete required fields and translations can be kept; Publish retains its
full requirements. Unstorable values stay visible with field errors while other
fields are committed and the invalid field's previous server value is retained.
Uploaded selections waiting for media preparation remain in the page and retry
automatically; ordinary invalid values wait for correction or Retry. Further
uploads can continue after the other fields have been acknowledged.
Publish and preview actions wait for pending values and stop on saving errors.

Migration 0031 adds private editing-session metadata to records. It travels with
full database backups but is deliberately absent from normalized exports. Restore
starts a fresh editing session; authored history, both record heads and the
latest sequence remain portable. Exact-preview enqueue locks selected heads and
ends their editing sessions in the snapshot transaction, so frozen and published
revisions are never changed by a later automatic save.

## Saved-record status

Every saved record has Draft, Published or Archived status. **Releases take the
record's published version, not its latest edit.** Ordinary saving preserves
both the status and the selected published revision. Publish or Publish changes
validates and selects saved edits; it does not immediately change the live site.
Pending edits appear as Published, with changes. Confirmed Discard copies the
published content into a new clean revision and keeps the earlier history.
Record lists show and filter status before database pagination, with totals
for the entire collection rather than just the displayed page.

The editor's **Change status** disclosure submits a separate native form to
`POST /records/<type>/<id>/status`. It requires a session, administrator
permission, same-origin CSRF, the saved `_revision` and `_edit_sequence`, an allowlisted
`_workflow_state` and explicit `_confirmed=1`. It acts only on saved content;
keep an open form first; scripted actions wait for pending edits. No script is
needed. Text is localized in all five interface languages.

`Content\RecordWorkflowService` owns transitions. Publishing revalidates the
canonical body, every required-on-publish field and locale, referenced records
and ready raster sources, including nested media and composition. Unsupported
video is not silently published. Unpublishing or archiving never rewrites other
records; correct references before the next preview if their target is no
longer included. Exact-preview preflight remains the whole-site correctness
gate after later edits.

The MariaDB adapter locks the record and compares its revision and sequence, copies the
unchanged body and relationship projection into a new immutable revision,
changes status and appends `record_status_changed` with actor, previous state,
new state and revision in one transaction. This deliberately advances editor
authority so an old content form cannot save over a newer status decision.
Existing revisions are retained; a same-state request is a no-op. Audit failure
rolls back both status and revision. A stale request returns 409 with the current
revision and requested state preserved for explicit reconfirmation; invalid
publish content returns 422. Neither writes an audit success.

Normalized export and restore preserve current status and immutable content
revisions, but do not import source accounts or permissions. Review-only
[draft-inclusive previews](./exact-website-preview.md) are a separate selection,
not a workflow transition.

## Settings and portability

Migration `0007` adds profile-keyed current CMS names and immutable attributed
settings revisions. An update locks the profile row, checks the expected
revision, performs a checked compare-and-set, and writes history and audit in
one transaction. Conflicts write neither history nor an audit success.

The naming document uses the versioned terminology schema. IDs, locales,
public labels and routes are not editable properties of that document.
Normalized export v1 adds optional `editorialTerminology` with the current
settings revision and labels for the exported profile. Existing exports remain
valid and unchanged when no overrides exist. Settings audit/history remains
in MariaDB backups; the normalized export retains the current settings, not
that separate audit history. Restore retains this optional field rather than deriving names from collection IDs.
Each label may also carry optional per-interface-language `languages` names,
edited in the same Content names form and saved through the same revision
compare-and-set. A language needs both names or neither.

## Module ownership and extension seam

| Module | Responsibility |
| --- | --- |
| `Content\EditorialForm` | Bounded native input and section selection |
| `View\EditorialRecordLayout` | Headings, anchors, issues and optional diagnostics |
| `View\Pages` | Native controls and revisioned forms |
| `client/editorial-form.js` | Language tabs, field state and island lifecycle |
| `record-autosave.js`, `record-keeper.js` | Serialized saves through the PHP record service |
| Terminology service and adapter | Revisioned CMS presentation names |

Structured controls use the same form boundary. An unsupported control preserves
its saved value; it must never expose hidden JSON as an editable substitute.
Adding support requires server validation, conflict handling and a usable native
path. The browser holds no durable content store and replaces no form HTML.

## Proof and review

Run the record-keeping and affected browser lanes in the [testing policy](testing.md).
Check lost responses, session expiry, stale tabs, invalid fields and script-free
forms. Review keyboard navigation at 360 CSS pixels and actual 200% browser zoom.
Automated checks do not replace assistive-technology review.
