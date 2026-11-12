# Content, languages and revision history

The profile defines what can be edited. The engine stores records, validates
changes, tracks revisions and resolves relationships. Saving a record keeps its
content in the CMS; an approved release changes the public website.

Use this guide for language settings, wording and retained history. See
[the editor workflow](editorial-foundation.md) for automatic keeping and
[publication](publication-workflow.md) for choosing the versions that go live.

## Routes and form behavior

Native forms work without JavaScript. All record routes require an authenticated
account with the relevant permission; mutations also require a session-bound CSRF
value and same-origin evidence.

| Request | Purpose |
| --- | --- |
| `GET /records/{type}` | List records, at most 100 per response |
| `GET /records/{type}/new` | Open a creation form |
| `POST /records/{type}` | Validate and create a draft |
| `GET /records/{type}/{id}` | Edit a record |
| `POST /records/{type}/{id}` | Validate and keep a revision |

Successful native mutations redirect with `303`. Invalid input returns `422`
with an accessible error summary. A stale revision returns `409`, preserves the
submitted values and shows the newer saved version. Resubmission must explicitly
use that version; a conflict never silently overwrites another edit.

## Site languages

Open **Languages** at `/settings/languages`. The owner and administrators can
change the settings; editors can read them. Choose one public primary language.

| State | Editing | Publication |
| --- | --- | --- |
| Public | Visible; required translations must be complete | Included in the next approved release |
| In preparation | Visible; missing translations are shown | Review previews only |
| Off | Hidden; saved text is preserved | Excluded |

A new installation starts with the profile's languages public and its default
language primary. Turning a language off requires confirmation. Saving checks
the settings revision; a stale choice returns `409` for review.

If the profile declares a [language envelope](profile-languages.md#language-envelope),
the owner or an administrator can add supported languages. Each starts empty and
in preparation. An added, nonprimary language can be removed only while no record
revision, media description, wording or holding-page text uses it. Otherwise turn
it off to preserve its content.

### Delete text in a language

Only the owner can delete text, and only while that language is off. The form
shows affected record and media counts and recommends an export. If the counts
change before confirmation, review them again.

Deletion creates a new revision for each affected current record. It clears media
text, wording overrides and holding text for the language, without changing record
workflow states. Earlier record revisions remain: restoring one can restore its
text, and a full-history export still contains it.

Work runs in resumable batches of 50 records or 100 media items. Only one deletion
runs at a time; turning the language on stops it. Deletion does not make an added
language removable while retained history still uses it.

Saving a record preserves hidden-language values, including nested fields and
layout captions. Validation requires completeness only in public languages.
Restore and upgrades apply the same language choice. See
[preview and publication languages](publication-workflow.md#site-languages).

## Site wording

**Site wording** at `/settings/wording` contains the profile's fixed page text:
navigation labels, page titles and address segments. Owners, administrators and
editors can change it for public languages and languages in preparation.

Leaving or clearing a value uses the profile default. **Use the profile text**
resets a key in every displayed language. Hidden-language values are retained.
Address segments use lowercase letters, digits and single hyphens; they must be
unique within a language and cannot be language prefixes.

Wording saves are revisioned, audited and checked for conflicts. Export preserves
its history, and restore validates it against the installed profile. Changes
reach visitors through the next approved release; a language needs complete
wording before it becomes public.

## Bounded record history

The default retains **50 historical revisions per record**, plus the current
and published revisions. Archived records follow the same policy. Revision
numbers never change; wording, terminology and design use separate histories.

The owner or an administrator can choose 10–500 in **Settings → Revision history**:

1. Back up the installation before reducing retention.
2. Review the proposed removal count and confirm it. Changed counts or settings
   require another review.
3. Use **Continue cleanup** to process one record and at most 100 revisions per
   request. You can leave and return to resume.

Ordinary saves also prune excess history under the record lock. Larger backlogs
persist a continuation. Raising the limit removes nothing and cancels unfinished
reductions. Interrupted transactions leave both history and audit unchanged.

Frozen previews and releases retain their own copied content even if source
revisions are removed. Exports contain only retained revisions. Restore preserves
revision numbers, gaps and both heads; it does not prune on import. The retention
setting is installation-specific, so a destination starts at the default.

Settings warns at 90% of the measured 20,000-revision export capacity. Other record,
byte and host limits still apply; see [export capacity](export-capacity.md).

## Storage and conflicts

Generic record and revision tables serve every profile type, including taxonomy
terms. Each revision carries canonical JSON, profile identity/version, authorship
and creation time. The relationship index is rebuilt in the same transaction;
validated revision bodies remain authoritative.

Automatic keeping groups consecutive edits from one page and person. A ten-minute
gap, another save session, publication or exact-preview capture seals the revision.
Published and previewed revisions are immutable. Record updates compare the
expected revision under a lock; stale edits create no revision or audit event.

## Commit-ordered site sequence

Records and release activations share a committed sequence. It advances for record
creation, revision, workflow changes, archive deletion and release activation,
including upgrade-created revisions. Media, accounts and other settings do not
advance it. Consumers order by this sequence, never by timestamps or revision IDs.

`MariaDbSiteSequence` allocates under a singleton lock inside the caller's
transaction, after validation and compare-and-set. The lock remains held through
audit and commit. Failed transactions consume no number, and a later writer
cannot expose its number first. Keep filesystem, network and compilation work
outside this transaction. Readers use READ COMMITTED or REPEATABLE READ.

Change entries contain identifiers and timestamps, not bodies. Deletion tombstones
remain available during the history window even after their record is gone.

| Bound | Behaviour |
| --- | --- |
| 30 days; at most 100,000 changes | Retain a contiguous suffix; every append enforces the count limit |
| 1,000 expired entries per worker wake | Further wakes finish a backlog; clock reversal cannot create holes |
| Cursor below `pruned_through` | Consumer must perform a full read |
| Maximum 9,007,199,254,740,991 | Exhaustion rejects the entire mutation |

Old unstamped revisions remain unstamped; migration does not invent history.
Export keeps the counter and revision stamps. Restore omits transient changes
and requires a new full read through the restored high-water mark. See
[export sequence rules](conventional-cms-export.md#site-sequence).

## No-shell migration

For hosting without SSH, use the token-gated migration procedure in
[installation](conventional-cms-identity.md#migrations-and-first-owner) or
[installed upgrades](conventional-cms-upgrades.md#control-panel-only-hosts).
Remove temporary endpoints and private gates after use. Content editing never
requires editors to run database commands.
