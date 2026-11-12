# 0009: Site languages chosen in the CMS

Status: accepted, 2026-10-14

Decision owner: Utsuri Foundry, SL.

## Context

A profile's manifest lists its languages (`locales`) and a default language.
Every localized field holds a value per listed language, validation requires
required fields in every language, publication builds every language, and the
profile's own code carries the fixed wording of its pages per language:
navigation, captions, address segments. Changing the list is a profile release
with a content migration that rewrites every record, which only a developer can
make and which destroys the text of a removed language.

Sites change their languages for editorial reasons: a client decides to launch
in English only and may add Catalan and Spanish later, or another language
altogether. Editors expect to make that choice in the CMS, without losing text
and without a developer. Only some languages need design work: those written in
a script the site's fonts do not cover, or from right to left.

## Decision

1. **The profile declares which languages it can carry.** It lists its initial
   languages (`locales`, with `defaultLocale` as the initial primary language)
   and may declare an envelope of scripts and directions its design supports,
   such as Latin script, left to right. Any language inside the envelope can be
   added in Settings from the engine's language catalog, which supplies its
   name, script, direction and formats. A language outside the envelope, or a
   profile without one, needs a profile release, because fonts, layout and
   search must be designed for it.
2. **The site's own wording belongs to the CMS.** A profile declares the fixed
   texts its pages show (navigation, captions, address segments) as named
   wording with defaults for its initial languages. Editors write and correct
   that wording per language in the CMS; it is revisioned, audited and exported
   like content. A language becomes public only when its wording is complete.
3. **The installation chooses a state for each of its languages: public, in
   preparation or off, and one primary language, which is public.** The choice
   is installation-owned, revisioned, audited and part of the normalized export
   and restore. The owner and administrators change it; editors see it.
4. **Turning a language off hides it and never deletes it.** Its text stays in
   revisions and exports, saving a record keeps it unchanged, and turning the
   language on again restores it. Deleting the text of a language that is off
   is a separate, explicit owner action.
5. **Editing follows the chosen languages.** Editors work in public and
   in-preparation languages; off languages are hidden. Requirements that apply
   in every language apply to public languages. Languages in preparation show
   translation coverage but never block saving or publication.
6. **Only public languages reach the public website, through the next approved
   release.** A changed choice is published like any other change, through an
   exact preview a person approves. Snapshots carry only public languages,
   with the primary language as their default, so profile code that follows the
   snapshot's languages needs no change, and text in other languages never
   enters a published artifact. Previews for review may include languages in
   preparation.
7. **Addresses stay valid when languages change.** The profile places each
   language's addresses; engine route helpers can serve the primary language at
   the root and the others under their language prefix. Addresses that a change
   of languages or of the primary language removes or moves redirect to their
   counterpart in the primary language, or to its home page, through the
   published route history.

## Consequences and risks

Changing a site's languages becomes an editorial decision with a reviewed
release instead of a developer migration, and it is reversible. Adding a
language within the design's envelope needs no developer; editors supply its
content and wording. Profiles must not assume a fixed set of published
languages: profile code reads languages and wording from the snapshot, never
from constants. Stored records keep values for languages that are off, so
exports and backups grow with every language an installation has used.
Removing a language from the public website leaves redirects behind; adding one
adds addresses and search indexes at the next release. The language catalog is
reviewed engine data and must stay bounded; search normalization is chosen per
script, and scripts without word spacing stay outside every envelope until the
engine supports them.

## Rejected alternatives

- A profile release for every change of languages: it needs a developer, a
  content migration and loses the text of removed languages.
- Fixed wording in profile code: every new language, and every typo, needs a
  release.
- Any language without an envelope: a site whose fonts cannot draw a script, or
  whose layout assumes left to right, would publish broken pages.
- Deleting text when a language is turned off: it is irreversible and the
  choice is often temporary.
- Choosing languages per record: a site's navigation, addresses and search
  need one consistent set of languages.

## Evidence and follow-up gates

Languages are profile-wide today in the
[profile manifest](../../development/profile-authoring.md), the
[content rules](../../development/conventional-cms-content.md) and the
[publication workflow](../../development/publication-workflow.md). Delivery:

1. Choose site languages in Settings
2. Publish only the public languages
3. Serve the primary language at the root
4. Let editors write the site's wording
5. Add languages within the profile's envelope
6. Delete the text of a language that is off
