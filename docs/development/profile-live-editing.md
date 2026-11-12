# Connect rendered pages to the editor

Field marks connect parts of a rendered page to their editing controls. Add them
when automatic matching is ambiguous, or to support in-place text editing. The
engine emits marks only in the live editor; exact previews and published pages
remain ordinary static output.

## Live preview field marks

The live preview links page elements with the form fields that fill them. It
recognises short text and images without help, but a renderer can mark the
element that shows a field exactly:

```php
$marks = FieldMarks::for($snapshot, $record);
'<h1' . $marks->attribute('title') . '>' . Html::text($title) . '</h1>'
```

`Utsuri\PortableCms\Conventional\Compiler\FieldMarks::attribute()` returns
` data-cms-field="title"` only while the live preview draws that record, and an
empty string everywhere else: other records on the same page, exact previews and
publication. The argument must be a field ID of the record's type. A mark on a container
claims the elements inside it, so automatic matches never override it.

For a child of one item in a top-level repeatable group, use its stable identity:

```php
'<span' . $marks->itemAttribute('credits', $item['_itemId'], 'name') . '>'
    . Html::text($item['name']) . '</span>'
```

The helper takes a group field ID, a 32-character lowercase hexadecimal item
identity and a child field ID. It emits `data-cms-field="credits.<identity>.name"`
only on the edited record's live pages. Never use a list position as identity;
the form and redraw keep the identity through insertion, removal and reordering.
The hover label names the group, current item position and child in the
administration language. Click-to-focus opens that item's card or row; a short
text shown exactly as authored can be edited in place with the ordinary limits
and Escape cancellation. Children are never guessed from their text, since two
items can show the same name. Nested groups are not supported by these marks.

The current page language selects the whole localized group's items, or the
translated child control of an unlocalized group. Do not mark fallback text as
though it belonged to another language. Legacy items without `_itemId` can use
`EditorialControls::legacyIdentity($prefix, $position, $item)` with the native
form's prefix (`groupId` or `groupId_locale`); the first form round trip carries
that identity explicitly. New content must retain `_itemId`, not regenerate it
from changed text. Reference Studio demonstrates localized project credits.

Reference Studio passes the record's marks through its page view model and
names the field behind each part of the page, for example the title, body copy,
year and status, layout and related cards. Leave fields unmarked when their
text or images are recognisable; mark when a value is transformed (an enum
shown as a label, rich text, a layout) or appears in several places.

Editors can type a short text straight into the page when the element showing
it holds only that text, exactly as saved: put the mark on the element around
the text itself (`<h1>` above), not on a container with other content, and
leave transformed or combined text to click-to-focus.

For a top-level rich-text field, opt into block editing through the existing
escaped renderer:

```php
$rich->html($artifactRoute, static fn(int $position): string =>
    $marks->blockAttribute('body', $position));
```

Positions are zero-based rows in stored-document order: each paragraph and
heading is one row, and each list item is one row (not its list container).
This is the same flattening used by `RichTextForm::rows()`. The helper validates
the field ID and position and emits `data-cms-field="body:0"` only in the edited
record's live preview. Pass marks for the exact document being rendered, not an
excerpt, reordered copy, translated fallback or combined field. Positions are
not persistent identities: the workspace compares the current complete document
with the render snapshot before accepting each change. Reference Studio's
article body demonstrates this contract. Leave compositions and group rich text
unmarked; these are not supported for in-place editing.

A marked paragraph, heading or list item whose text matches the stored row can
be edited without navigating a nested link. Inline emphasis, links and small
capitals remain in the form document. A transformed row or one containing other
controls/markup keeps click-to-focus. Existing soft-break markup also keeps
click-to-focus. Public and exact-preview HTML remain byte-identical when no
marks are active; do not add editor attributes yourself or serialize them into
authored content.
