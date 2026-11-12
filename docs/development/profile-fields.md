# Define fields and page blocks

Use these examples when designing a profile’s editing form. Each example is a
manifest fragment, not a complete profile. Start with [profile authoring](profile-authoring.md)
for package setup. The same field rules apply to editing, compilation and content
transfer; changing a stored content rule may require a migration.

## Field hints

Add optional `hint` text beside a field's `label`, in profile
v1/v2/v3 fields, group children, block children and v3 group references. It is
one nonblank plain-text line of up to 160 Unicode characters, with no control
characters. The editor escapes it, places it below the name in muted text and
connects it to the native control or group with `aria-describedby`. Omit it for
fields that need no explanation. Hints never change content validation or limits.

For a decorative video loop, keep the name short and put the brief in the hint:

```json
{
  "id": "homeLoop",
  "label": "Home loop",
  "hint": "Decorative desktop loop; use its first frame as the poster.",
  "type": "video_reference",
  "localized": false,
  "requiredOnDraft": false,
  "requiredOnPublish": false,
  "settings": {
    "posterRequired": true,
    "maximumBytes": 6291456,
    "maximumDurationSeconds": 15,
    "maximumWidth": 1920,
    "maximumHeight": 1080,
    "sound": "none"
  }
}
```

Set the engine minimum to `0.5.0` when using hints. In the optional
`administration-labels.json`, a language may add a `hints` map using the same
field paths as `fields`, for example
`"ca": {"hints": {"film.homeLoop": "Bucle decoratiu per a ordinador; fes servir el primer fotograma com a pòster."}}`.
Each translation has the same 160-character bound; a missing translation uses
the profile's hint. The administration language selects the hint independently
of the record's content language. Profiles without hints keep their existing
field markup. Hint-only changes are presentation changes during upgrades.

Image fields offer one **Add images** disclosure with library and upload choices.
Upload controls show their actual formats and limits. Completed images retain
upload order and overflow remains in the library; uploading never saves the
record. See [media](conventional-cms-media.md) for resumable uploads and host
qualification. A single-language record hides language tags, and short Details
fields stay together in profile order regardless of whether they are translated.

## Single-line rich text

Use `"maximumBlocks": 1` inside a `rich_text` field's
`settings`. Use it for a short name that may carry a link, with
`"blocks": ["paragraph"]` and `"marks": ["link"]`. Keep the existing
`maximumNodes` bound. This is an opt-in field setting in profile v1/v2/v3;
only the value `1` is supported. Declare an engine minimum of `0.5.0`.

The enhanced editor puts the permitted marks beside an input-height writing
surface. Enter and Shift+Enter do not create another block; multiline paste
joins the lines with spaces while retaining their text and links. Native forms
use one-row text segments with their mark controls and cannot add blocks.
Save, live preview, public compilation, restore and import reject more than one
block, including multiple list items. Existing fields without the setting keep
their current editor and content rules. Adding the bound to an existing field
requires reviewing its stored content as part of the profile upgrade.

## Editorial web addresses

Declare a `web_address` field with `settings: {"maximumLength": 2048}` (or a
smaller positive byte limit). It may be localized and required on draft or
publication like other fields. The native editor provides an address input and
an optional plain-text link label, without JSON editing or JavaScript.

Canonical nonempty values are `{formatVersion: 1, url: "https://example.test/",
linkText: "Example"}`. Empty optional values are `null`; localized values map
each declared language to that document or `null`. Link labels accept at most
200 Unicode characters. Addresses are absolute, credential-free HTTP/HTTPS
links, at most 2048 UTF-8 bytes. Relative, protocol-relative, `mailto:`, `tel:`,
executable and data addresses are not supported by this field. It is a link,
not permission for the server to fetch the address.

Pass the selected document to `WebAddressLink::render($value)` and consume its
engine-owned `TrustedHtml::value` in the reviewed renderer. Never concatenate
the raw URL into markup. The helper validates again, escapes the attribute and
label, and falls back to displaying the address when the label is blank. Empty
values emit no link. Links open in the same window; this field does not permit
editor-written attributes or a target. It does not broaden `Html::url()`'s
existing policy for other URL contexts. Export and restore preserve these
documents without host-specific identifiers or migrations. Existing profiles
without the new field retain their shape and behavior.

## Reusable field groups (profile v3)

Use `formatVersion: 3` to declare recurring group definitions in the manifest's
`fieldGroups` object. Formats 1 and 2 remain valid. A definition is an ordinary
`group` or `repeatable_group` field declaration without an `id`; the use supplies
that stored field identity. For example:

```json
{
  "fieldGroups": {
    "credits": {
      "label": "Credits",
      "type": "group",
      "localized": false,
      "requiredOnDraft": false,
      "requiredOnPublish": false,
      "settings": {
        "fields": [{
          "id": "name", "label": "Name", "type": "short_text",
          "localized": false, "requiredOnDraft": false,
          "requiredOnPublish": false, "settings": {"maximumLength": 160}
        }]
      }
    }
  }
}
```

Put `{"id":"credits","fieldGroup":"credits"}` in a record type, singleton,
or taxonomy's `fields` list. Each use may set `label`, `labels` (a map of direct
child field IDs to labels), and `itemLabel` for a repeatable group. It may also
set `mapping`, a partial override of the group's `settings.searchSharing` keys.
An object mapping value replaces that whole value; `null` removes an optional
mapping key. Required mappings must still be present after expansion.

For example, `{"id":"projectSearch","fieldGroup":"search-sharing",
"label":"Project discovery","labels":{"description":"Project summary"},
"mapping":{"descriptionField":"body"}}` uses the search/sharing definition
with its own identity and presentation. Overrides cannot change types, child
identities/order, required states, localization, validation limits or arbitrary
settings. Declare another group when those rules differ.

Another group can compose the child fields with
`{"includeFieldsFrom":"credits","labels":{"name":"Contributor"}}` in its
`settings.fields` list. Inline group and block-type child lists accept the same
inclusion. This inserts the children in place; it does not nest a stored group
or copy the source group's mappings, required state or localization. Duplicate
child IDs are errors, even when their declarations are identical. Structural
nesting of groups remains unsupported.

PHP validates the authored v3 document, expands declarations once during loading,
and validates the result against the existing v2 field schema before constructing
runtime definitions. Administration, content validation, previews, export and
compilation receive only expanded fields. The authored manifest is still the
package-integrity input; changing its spelling changes the package checksum.
Upgrade planning compares the validated expanded definition, so replacing inline
v2 declarations with references alone requires no content migration. Changed
constraints still do.

There are at most 32 named groups. Unknown names, direct or indirect recursion,
unknown label targets and conflicting field IDs fail closed, including in unused
declarations. Expanded lists retain the existing 64-field top-level and 32-child
limits, and the expanded manifest must fit 256 KiB. Expansion preserves stored
field IDs, values and content formats; no database migration is involved.

Reference Studio's `search-sharing` declaration supplies its twelve metadata
groups. The profile file shows per-page labels and mappings without repeating
the six leaf declarations. Its public files remain byte-identical after this
authoring change; fixture package-provenance pins change normally.

The v3 schema derives from v2's authoritative field contracts. Regenerate it with
`nix develop -c php tools/contracts/check-profile-groups-schema.php --write`
after changing those contracts; the repository gate rejects drift.

## Repeatable group presentation

The editor shows a repeatable group of at most three short inline fields (short
text, localized short text, integer, decimal, enum, boolean, web address) as
aligned rows and other groups as collapsible cards. Set
`settings.presentation` to `"cards"` to keep cards, or to `"rows"` for up to
four short inline fields; the manifest rejects rows for any other field.
`settings.itemLabel` optionally names one item as it reads after "Add" in
running text, such as `"credit"`, and `administration-labels.json` translates
it under `items` with the group path (`"project.credits"`). Both settings are
administration presentation: content, compiler input and routes are unchanged,
and a release that only adds or changes them upgrades without `upgrade.json`.

## Typed page blocks

A `blocks` field is an ordered list of bounded shapes defined by reviewed profile
code. Declare `settings.minimumItems` (0–64), `maximumItems` (1–64, at least the
minimum), and between one and sixteen `blockTypes`. Each type has a permanent
`id`, a `label`, an optional `itemLabel`, and one to thirty-two `fields` using the
same leaf field declarations as groups. Groups and blocks cannot nest inside a
block. For example:

```json
{
  "id": "sections", "label": "Page sections", "type": "blocks",
  "localized": true, "requiredOnDraft": false, "requiredOnPublish": true,
  "settings": {
    "minimumItems": 0, "maximumItems": 32,
    "blockTypes": [{
      "id": "quote", "label": "Quote", "itemLabel": "Quotation",
      "fields": [{
        "id": "text", "label": "Text", "type": "short_text",
        "localized": false, "requiredOnDraft": true, "requiredOnPublish": true,
        "settings": { "maximumLength": 500 }
      }]
    }]
  }
}
```

### Stored block values

Each item stores exactly `_itemId` (a stable 32-character lowercase hexadecimal
identity), `typeId` (one declared block type), and `values` (that type's field
values). Order is the list order; identity survives reordering and text changes.
Duplicate item identities, unknown types and undeclared values are refused.
An example item is:

```json
{
  "_itemId": "10000000000000000000000000000001",
  "typeId": "quote", "values": { "text": "Spaces find meaning when we share them." }
}
```

Whole-field localization stores a list per language; each language may have its
own order and block choices. Its child values are already inside that language,
just as with a localized group. In an unlocalized blocks field, localized children
keep their ordinary language maps. Languages turned off retain their saved
values, and frozen snapshots omit them. Draft requirements apply on save;
publication additionally enforces each selected type's publication requirements.

### Editing blocks

The native record form exposes ordered cards for the declared types, using their
administration labels. Editors add or insert a chosen type, move, duplicate and
remove cards without scripts; a mounted island adds grip dragging. Every operation
preserves unsaved leaf values and other languages. A duplicate receives a new
item identity. Existing items cannot change type. Saves still enforce the full
contract and item minimum, even while an incomplete list is being assembled.
Localized composition children retain the existing visible unsupported state and
saved values. Developers and migration adapters use the authoritative content
service, never write unvalidated record JSON directly.

### Render blocks

`Content\Blocks::items($field, $value, $snapshot->locales, true)` supplies each
item, its language and a group projection of its child definitions. Render
`$entry['item']['typeId']` through an explicit match in reviewed profile code,
escape its `values`, and use the ordinary frozen media and route helpers. No
renderer path or executable markup comes from an item. The graph validators use
that same projection for record references, media, layouts and web addresses.
For live page links, mark each rendered child with
`FieldMarks::itemAttribute($field->id, $item['_itemId'], $childId)`.
The editor labels it with block type, position and child name and opens the owning
card on selection. Marks are absent from published and exact-preview artifacts.
`Content\Blocks::text()` extracts plain text for profile-selected search weights;
it excludes item/type identities and relationship identifiers.

### Labels and upgrades

Administration labels accept a `blocks` section keyed by `type.field.block`,
with child labels in `fields` at `type.field.block.child`, optional item names in
`items` at `type.field.block`, and child enum choices in `options` at
`type.field.block.child.option`. These labels never change stored values.

Normalized exports and restores retain the exact ordered items, identities,
types, values and revision history. Adding a block type and changing display
labels are presentation changes. Removing a type or changing its child content
contract requires an explicit content migration. Renaming a stable type identity
is removal plus addition, not a label change. Reference Studio's `landing` page
proves hero, quotation and project-link blocks in all three fixture languages.
