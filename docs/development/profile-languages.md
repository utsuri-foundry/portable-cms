# Languages and site wording

Declare the languages your design can display, then supply defaults for its fixed
text. Editors choose which languages to publish and can change the wording in the
CMS. Render from the frozen snapshot so a preview and its release use the same
choices. For the editing workflow, see [site languages](conventional-cms-content.md#site-languages).

## Language envelope

A profile may declare the scripts (ISO 15924) and text directions its fonts and
layout support, and optionally the characters its fonts draw, as hexadecimal
code point ranges:

```json
"languageEnvelope": {
  "scripts": ["Latn"],
  "directions": ["ltr"],
  "characters": ["0000-00FF", "013F-0140", "2010-2027", "20AC"]
}
```

With `characters`, only catalog languages whose letters and capitals all fall in
those ranges are offered: fonts subset to Latin-1 offer Italian or Portuguese
but not French (œ, Ÿ) or Polish (ł). Declare the ranges your font subsets
actually contain, and widen them when the fonts grow. Without `characters`, every
catalog language of the scripts is offered.

An administrator can then add any language of the engine's catalog inside the
envelope from **Languages**, without a profile release
([0009: Site languages chosen in the CMS](../architecture/decisions/0009-site-languages.md)).
The engine supports Latin script, left to right, and the schema accepts nothing
else; right-to-left layouts and scripts without word spacing need engine work
first. A profile without an envelope offers only the languages it lists.

An added language arrives in snapshots like the profile's own: after them in
`locales`, with records, media text and wording in it, and nothing in the
manifest. It has no wording defaults, so editors write its wording before it
can become public. Code that follows the snapshot's languages needs no change.
Where code needs a language's name, direction or formats, read them from
`LanguageCatalog::language($locale)`: `name` in the language itself,
`direction`, `date(\DateTimeInterface)` for a numeric date and
`number($value, $decimals)` for a decimal number. It returns null for a listed
language outside the catalog. The codes of the catalog languages of the
envelope's scripts and directions, whatever the fonts draw, are reserved like
the profile's own: no address segment of the
wording, and with the root layout no page path, may take one, so an added
language always finds its prefix free.

## Site wording

Declare every fixed text your pages show, such as navigation links, page
titles, labels, captions and the segments of page addresses, in the manifest's
`wording` list. Editors write and correct it per language in **Site wording**
at `/settings/wording`, and the snapshot carries it to the build, so a new
language or a typo needs no profile release
([0009: Site languages chosen in the CMS](../architecture/decisions/0009-site-languages.md)).

```json
{
  "key": "page.projects",
  "label": "Projects title",
  "purpose": "The title of the projects page and its navigation link.",
  "kind": "text",
  "maximumLength": 40,
  "defaults": { "ca": "Projectes", "es": "Proyectos", "en": "Work" }
}
```

- `key` is the stable name code reads: dot-separated segments that start with
  a lowercase letter, at most 100 bytes, unique in the profile. A release that
  renames a key drops the texts editors wrote for it.
- `label` and `purpose` tell editors what the text is and where it appears.
  These are the fallback administration names; per-interface-language names
  belong in the optional labels file described below.
- `kind` is `text`, one line of plain text of at most `maximumLength`
  characters (up to 500), or `address_segment`, one path segment of lowercase
  letters, digits and single hyphens of at most `maximumLength` bytes (up to
  80) that is never the code of a language the profile lists or its envelope
  admits.
- `uniqueIn` names a group of address segments that must differ within each
  language, typically the top-level pages.
- `defaults` holds the profile's text per language. The default language needs
  one for every key. Another language may leave texts to the editors; it
  becomes public only once its wording is complete, because preflight names the
  missing keys.

In `administration-labels.json`, each language may provide a `wording` map:

```json
{
  "formatVersion": 1,
  "profileId": "reference-studio",
  "languages": {
    "ca": {
      "wording": {
        "page.projects": {
          "label": "Títol de projectes",
          "purpose": "El títol de la pàgina de projectes i el seu enllaç de navegació."
        }
      }
    }
  }
}
```

Each key must be declared by the installed manifest. A translation provides both
`label` (up to 100 characters) and `purpose` (up to 300), as nonblank single-line
plain text. Unknown keys, extra properties and invalid text fail validation.
Languages are the administration's `ca`, `es`, `en`, `fr` and `de`, independently
of the website's languages. Omitted keys or languages fall back to the manifest's
label and purpose. The native Site wording page and its validation summary use
these names; missing-wording preview reports resolve stable keys when read, so
another editor sees the saved report in their own interface language. Older
reports with display names remain readable. Administration labels remain outside
the package checksum, frozen wording and compiler input: changing them does not
change public texts, addresses, output bytes or provenance.

Read a text with `$snapshot->wording->text('page.projects', $locale)` and
escape it like any other text. A key the manifest does not declare is a
profile error. A key without text in a language the build draws raises
`ProfileBuildFailure` with `ProfileBuildReason::MissingWording`, which names
the key and the language. A snapshot carries the wording of its languages, and
a language in preparation shows the primary language's text where its own is
missing. A snapshot frozen without wording, such as a package fixture, draws
the profile's defaults.

To let editors name a page's address, map its first path segment, as the
manifest writes it, to an address segment key:
`addresses: ['projects' => 'address.projects']` on
[`ProfileRoutes`](profile-rendering.md#routes-and-discovery). Changing the segment moves the
page and its records; the published route history redirects their former
addresses in the next release.

Declarations and defaults are presentation: a release that changes them needs
no content migration. An installation's text for a key that a release removes,
or that the key no longer accepts, gives way to the default.
