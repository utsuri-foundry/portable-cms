# 0013: Website settings in the order of an editor's tasks

Status: accepted, 2026-11-17

Decision owner: Utsuri Foundry, SL.

## Context

Website settings (`/settings/publication`) gathers everything the next release
needs that is not content: the website's name and icon, whether the release
publishes the coming soon page or the website, the coming soon texts per
language, and the administration's own logos. A design review of the page as
installed on 2026-11-16 found that it was
ordered by the stored document rather than by what an editor comes to do:
the publication mode came first with a sentence about the host's delivery
mode, the icon and the logos sat behind closed disclosures with the whole
library listed three times and most rows disabled, the per-language cards were
headed by locale codes and shown for languages that are off, the confirmation
for returning to the coming soon page appeared even when it did not apply,
saving gave no sign of having saved, and some failures reached the editor as
engine codes.

An editor's share of the page is small and happens twice: name, icon and
message before launch, and the switch to the website on launch day. The agency
chooses the administration logos and, rarely, returns a site to the coming soon
page. The experience document's bar applies: one heading hierarchy, bound
labels, announced status and errors, usable without script, no engine terms.

## Decision

1. **One page, read top to bottom in the order of the editor's tasks, with the
   agency's part last.** The page keeps its name, Website settings, and its
   place in the Website group. Its lede says what the page holds and that
   saving changes nothing on the public website until a release is published.
   One quiet line under the heading says what the public website shows now
   (the coming soon page, the website, or nothing yet). Building and publishing
   stay on Releases; this page offers no build action and names Releases as the
   next step after saving.
2. **"Website" holds the name and the icon.** The name says where it appears.
   The icon control states its rule once, before any list: a square PNG, WebP
   or JPEG of at least 512 pixels. The chosen image is previewed at the three
   sizes the engine generates, 32, 180 and 512 pixels, drawn to fit. The
   chooser lists only images that qualify, searchable and paged, with the
   upload beside it. A library that cannot be rendered falls back to the icon
   image field.
3. **"Before launch" holds the publication choice and the coming soon texts.**
   The choice is two explained radios in the editor's words, the coming soon
   page or the website, with the consequence under each. The message and
   contact per language follow, headed by the language's name in the
   administration language, for the public languages only; text of languages
   that are off or in preparation is kept and carried through the form
   unchanged. The texts fold away while the website is chosen in browsers that
   support it. The confirmation appears only when the saved choice is the
   website and says in one sentence what the next release will do.
4. **"Administration appearance" comes last.** The logo and its dark mode
   variant each show the chosen image on the backing it gets, and their
   choosers list only prepared images within 960 by 960 pixels. The help is
   one sentence each; the fitting rule stays with the logo.
5. **One Save, then a saved notice that names the next step.** After saving,
   the page returns with a status line that says it saved and, for the coming
   soon page, that it must be built and published on Releases, with the link.
   Every failure the page can show is a sentence in every administration
   language; a conflict keeps the editor's input and says someone else saved.
   Editors read the page without its controls; owners and administrators
   change it.

## Consequences and alternatives

The settings and their meaning are unchanged: the same document, revision,
confirmation rule and authority. Only the page changed, so no migration or
content change follows. Browser journeys cover saving each choice, the
confirmation path, a conflict, an invalid value, and choosing and previewing an
icon from an uploaded image.

A separate Administration page for the logos was considered and left for later:
with one sentence of help and eligible-only choosers the section is short, and a
second page would add a destination for a rarely used choice. Moving the
publication choice to Releases was considered; it stays here because it is a
setting a release takes, not an action, and Releases already reads it. The
record form's language switch was considered for the texts; stacked cards named
by language keep every message visible at once, which is what an editor checks
before launch. The icon preview shows the generated sizes rather than a mock
browser tab, because the sizes are what the engine actually produces and a mock
would age with browsers.

## Proof

`tests/php/website-publication-test.php` and `tests/php/administration-logo-test.php`
cover the markup contract; `tests/browser/holding-page-journey.mjs` covers the
saved notice, an invalid value, a conflict, uploading, choosing and previewing
an icon, and the confirmation path; `tests/browser/administration-logo-journey.mjs`
covers the logo choosers. The experience document's Website settings section
describes the page.
