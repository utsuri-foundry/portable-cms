# Build a website profile

A profile gives Portable CMS a site's content model, templates and assets. You
write the design in PHP, HTML, CSS and browser JavaScript; the engine supplies
the editing interface, validation, previews and publication.

Start by running the [local CMS](local-reference-cms.md). Its fictional
[Reference Studio profile](../../profiles/reference-studio/README.md) is a working
example to inspect, not a required design or a client template.

## 1. Understand the package

| File or directory | What you provide |
| --- | --- |
| `profile.json` | Content types, fields, languages, routes, assets and compatibility |
| Renderer entry points | PHP closures that return complete semantic HTML documents |
| `src/compiler.php` | A factory returning the site's `ProfileSiteBuilder` |
| Supporting PHP source | Mapping from frozen content to page views and renderers |
| Prebuilt assets | Styles, scripts, fonts and images, with checksums and licence evidence |
| `administration-labels.json` | Optional translated names and guidance for the CMS |

The engine verifies the package before loading its code. The package digest
includes renderers and supporting source; do not modify an installed package in
place. Customer hosts need no Composer or JavaScript build step.

## 2. Define the content to edit

Choose the first page or collection, then declare only the fields its design
needs. For example, this field fragment adds a required translated title:

```json
{
  "id": "title",
  "label": "Title",
  "type": "short_text",
  "localized": true,
  "requiredOnDraft": true,
  "requiredOnPublish": true,
  "settings": {"maximumLength": 180}
}
```

The manifest determines the form's labels, controls, limits and required states.
Use [field examples](profile-fields.md) for hints, rich text, reusable groups and
page blocks. The [profile schemas](../../schemas/README.md) define the complete
manifest structure; a field fragment alone is not an installable profile.

Declare default languages and [site wording](profile-languages.md) for navigation,
page labels and address segments. Keep editable content separate from presentation
code. Use the engine's Website settings for the site name and icon, rather than
adding a second editable identity in the profile.

## 3. Render frozen content

The compiler factory receives a verified `ProfilePackage` and returns your
builder. A typical factory ends with:

```php
return static fn(ProfilePackage $package): ProfileSiteBuilder => new StudioBuilder($package);
```

Import those engine types and load only the reviewed support source your builder
needs. See [Reference Studio's factory](../../profiles/reference-studio/src/compiler.php)
for a complete example.

The builder reads a `FrozenSnapshot` and returns a `ProfileSiteBuild`: pages,
selected media and any search/discovery data. It does not query MariaDB, read live
settings, prepare derivatives or write public files. Those effects belong to the
engine, so preview and publication can use the same verified bytes.

For each page:

1. Select records through the snapshot's inclusion policy.
2. Use its languages, primary language, website identity and wording.
3. Resolve routes and media from frozen data.
4. Escape content for its HTML context and return a complete document.

The [rendering reference](profile-rendering.md) covers records, routes, metadata,
search, errors and holding pages. [Media rendering](profile-media.md) covers
responsive images, crops, sharing images and Vimeo posters.

## 4. Try the profile in the CMS

Run a verified package in a separate local sandbox:

```sh
nix develop -c just serve-profile-cms /path/to/profile 4182
```

This starts without seed content. Create records through the CMS, check required
fields and languages, then build an exact preview. See
[local setup](local-reference-cms.md#other-profile-packages) for the sandbox's
storage and lifecycle. Previewing does not publish the website.

Add [live editing marks](profile-live-editing.md) when a rendered value needs an
explicit connection to its field. If external consumers need selected content,
opt into a [public feed](profile-feed.md).

## Engine compatibility

Set `compatibility.engine.minimum` to the first engine version supporting every
API, manifest field and format you use. Also set a reviewed `maximumExclusive`.
A Git revision does not express engine compatibility. Installation and upgrade
checks reject incompatible packages before rendering or database setup.

Use the [changelog](../../CHANGELOG.md) for feature introduction versions and the
[release policy](engine-releases.md) for compatibility rules.

## Releasing a profile

Classify the change before preparing an upgrade:

- **Presentation:** known assets, renderers, labels, navigation, wording, design
  controls and a widening language envelope can change without creating content
  revisions, when no SQL migration is present.
- **Content model:** changed types, fields, validation, default languages or
  other content-affecting declarations require a reviewed migration.

Unknown keys are treated as content-affecting. The operator's dry run reports
the classification; backups, candidate validation and private compilation remain
required. Follow [profile upgrades](conventional-cms-upgrades.md#profile-content-declarations).

## Reference and verification

Use Reference Studio's builder and fixtures to understand the contracts, while
keeping your site's code and content in a separate package. Verify empty content,
missing translations, broken references and unsupported media as well as valid
pages. Check navigation, accessibility and responsive layouts in an exact preview.

For engine or bundled-profile changes, run the [fast and affected checks](testing.md).
Before deploying an independent profile, qualify its package and host using the
[installation checklist](reference-host-acceptance.md).
