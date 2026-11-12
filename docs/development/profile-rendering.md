# Render a profile into a static site

A profile builder reads a frozen snapshot and returns pages, selected media and
discovery data. The engine validates and writes the artifact. This reference
explains the optional helpers available to a builder; it does not require a
frontend framework. Start with [profile authoring](profile-authoring.md).

## Frozen records and settings

### Select records

`PublishedRecords($snapshot, orderField: 'sortOrder')` indexes only records
accepted by `FrozenSnapshot::includes()`. Published-only builds exclude drafts;
an explicitly draft-inclusive private snapshot includes drafts. Both exclude
archives and preserve saved workflow states. `records($typeId)` sorts ascending
by the chosen integer field, then record ID; absent/noninteger orders use
100000. Pass another field name or `null` for ID ordering.

### Use the frozen languages

The snapshot's `locales` are the languages this build publishes, in profile
order, and its `defaultLocale` is the installation's primary language. They are
the public languages chosen in Settings, not necessarily the manifest's
`locales` and `defaultLocale`; a review build adds languages in preparation and
lists them in `preparationLocales`. Records and media text carry no values in
other languages. Build every page, navigation entry, language link and search
index from the snapshot's languages and draw the root page in its
`defaultLocale`; never loop over the manifest's languages or name a language in
code. `ProfileRoutes` accepts the profile's languages in profile order,
followed by any the installation [added within its envelope](profile-languages.md#language-envelope),
and places them by the profile's [language layout](#routes-and-discovery).

Every fixed text a page shows comes from the snapshot's
[site wording](profile-languages.md#site-wording), never from the profile's code.

### Read settings and required pages

`record($id)` returns an included record or null. `singleton($typeId)` requires
exactly one included record; `singleton($typeId, required: false)` permits none
but still rejects duplicates. Decide explicitly which settings your presentation
requires. Do not fabricate descriptions when required settings are absent.
The engine website identity described below is independent of profile settings.

### Guide an empty installation

For first-preview guidance, a trusted builder may also implement
`Utsuri\PortableCms\Conventional\Preview\PreviewStartupRequirements` and return
the singleton type IDs it already requires from `requiredSingletons(): array`.
For example, Reference Studio returns `['home', 'about']`; discovery defaults are optional.
The engine validates a unique list of at most 64 installed singleton types and
links missing or unpublished records from Overview and previews, using the
manifest's required field labels. Declare only genuine existing requirements;
optional settings and collection content do not belong here. The hook neither
loads content nor performs effects. Preflight remains authoritative for fields,
translations, relationships, media and the completed site build.

### Select an optional sharing image

`SharingSelections::setting($records, $settingsType, $fieldId)` reads an optional
single-media selection, such as a default sharing image.
Absent optional settings/fields select nothing. Use `required: true` when the
settings singleton is mandatory. Malformed IDs, external URLs, multiple selected
IDs and duplicate settings are rejected.

### Website identity and distinct administration names

Engine **Website settings** (`/settings/publication`) own the one website name
and optional square raster icon. The live website, metadata and Coming soon
page share them. Saving is not publication: exact previews freeze the identity
and published files retain that frozen choice. Names accept at most 180 Unicode
characters. An untouched installation starts with a readable name derived from
its profile ID and no selected icon; editors change it in the same native form.

Use `$snapshot->websiteIdentity->name` when building pages. Snapshots from older
engines may omit this optional value; a reviewed compatibility fallback can use
`WebsiteIdentity::defaults($snapshot->profileId)`. Do not query live configuration
or declare a second editable name or favicon field. `AssetSiteBuilder` uses the
frozen icon selection automatically, including an explicit empty selection;
pass its prepared icon to `DocumentHead`. Older builders retain their original
interface, but a profile update must adopt this single source.

Search/sharing field mappings select `"siteNameSource": "website-settings"`
instead of `siteNameField`. `settingsType` and `titleFormatField` still identify
the profile's optional discovery defaults. Descriptions, organization details,
default sharing images, title order and page-specific SEO are profile content;
publication mode, name, favicon and holding text belong to the engine form.
Reference Studio calls its profile settings **Discovery defaults**, not Website
settings, and its empty optional defaults do not block the first preview.

Both plural and singular type labels, and record navigation labels, must be
distinct from other types and from engine menu destinations in every supported
administration language. The package loader checks translated labels with their
actual fallback names, not only the profile's public languages. Comparison folds
case, Unicode composition and repeated whitespace. A failure names the type,
language and colliding type or engine catalog key. Installation renames use the
same rule. Keep public page titles and address wording separate from CMS labels.
Candidate upgrade checks and restore admission also validate retained installation
renames. Correct conflicting existing names explicitly; they are never silently
rewritten to make an upgrade or import pass.

Migrating a profile that formerly owned its own name or icon requires an explicit
content migration. Before removing those fields, the engine captures their values
inside the upgrade transaction:

- Explicitly saved Website settings take precedence.
- Otherwise the old declared profile identity is adopted.
- Conflicting old sources without an explicit choice reject the upgrade.

A bounded `identityUpgrade` note preserves the old name/icon IDs in settings and
normalized exports. The editor explains which source was kept; ordinary saves
cannot erase the note. Verified backups retain the original history.

Only outgoing-package inspection may use `allowLegacyProfile: true`. Incoming
and serving packages must use the engine identity and distinct menu labels.
Update the reviewed builder and content mapping together; an engine-only upgrade
cannot reconcile a builder that still reads its own identity. Retained releases
remain available through verified rollback.

## Routes and discovery

`ProfileRoutes($manifest, $records)` uses each collection's `routePrefix`, each
singleton's `routePath` and a collection record's localized `slug`. `index()`
returns a localized index/singleton path; `record()` returns a record path;
`path('/search/', $locale)` places any other page of the profile. All three
require a frozen locale and reject unavailable records or unsafe paths. Build
every address, navigation entry included, through these methods so the
profile's language layout and its [address wording](profile-languages.md#site-wording) apply
everywhere. The `addresses` argument maps a first path segment to the address
segment wording that names it in each language.

The last argument chooses the language layout:

- `LanguageLayout::Prefixed`, the default, publishes every language under its
  prefix (`/ca/projects/`, `/en/projects/`) and the primary language's home page
  also at `/`.
- `LanguageLayout::PrimaryAtRoot` publishes the primary language without a
  prefix (`/projects/the-ivy/`) and every other language under its prefix
  (`/ca/projects/...`). The primary language's home page is `/` itself. A site
  launched in one language gets addresses without a prefix and can add
  languages later. No unprefixed page path may begin with the code of a
  language the profile lists or its envelope admits, so a language added later
  never takes an address in use.

The layout is the profile's decision, fixed in its code. The primary language
is the installation's, so with the root layout the addresses at the root follow
the language chosen in Settings. When either changes, the published route
history redirects every moved address to the same page's new address in the
next release. An address that the same page keeps in another language, such as
`/about/` after the primary language changes, goes on serving that page.
Profiles that build their own addresses can follow the same pattern with
`LanguageLayout::path()`.

Taxonomies have no manifest public routes. Opt them in explicitly, for example
`taxonomyPaths: ['discipline' => '/topics/']`. A `segment` closure accepting
`(FrozenRecord, locale)` can map a different slug field or normalize an authored
taxonomy label. It must return one nonempty directory segment, not a URL.
Traversal, separators, encoded paths, query strings and fragments fail before
rendering. Profiles with more specialized route policies may use the existing
`DiscoveryRoute` and `RouteDiscovery` contracts directly.

`discovery($metadata, pages: ['/search/' => 'search'])` builds the actual route
graph from manifest pages, included collection records and opted-in taxonomies.
The metadata closure accepts `(nullable record, page group/type ID, locale)`
and returns `SearchSharing`. A null record means an index or added utility page;
singleton and individual-record routes receive their frozen record. The `pages`
map adds unlocalized paths or overrides page group names for existing paths.
Overriding a group also makes that page's metadata mapping explicit: an alias
group that is not a singleton type receives null.

Translation groups default to `page:<group>` and `record:<id>`. With the
prefixed layout, a manifest singleton at `/` produces localized homes plus a `/`
alias to the default-language home, with reciprocal `x-default` links when
indexable. Pass `rootAlias: false` to omit it. With the root layout there is no
alias: every page's language alternates name each public language, and its
`x-default` link names the page's primary-language address, which for the home
page is the root. Noindex pages remain in the actual route graph but
are omitted from sitemap and language alternates. Duplicate paths/groups,
missing singleton pages and contradictory aliases fail closed. Return that same
graph in `ProfileSiteBuild`; its route set must exactly match the emitted HTML.

## Document head and search

Resolve page metadata with `SearchSharing::resolve()` using authored visible
title, summary, site name and optional website description. Pass the result to
`DocumentHead::render(path, locale, siteName, metadata, discovery, sharingImage,
structured, icon, packagedSvgIcon)` inside your `<head>`. It emits charset,
viewport, structured-data CSP, escaped search/social metadata, canonical and
language discovery links, bounded structured JSON and icon links in stable order.
An optional reviewed packaged SVG path is used when no generated icon exists.
It does not invent an origin, description, organization, breadcrumb or icon.
Missing capabilities emit no corresponding markup.

Construct optional `StructuredData::page()` only from visible page facts, choosing
organization/article/breadcrumb semantics in the profile. Fonts, styles, scripts,
the `<html lang>` wrapper, navigation and page layout remain presentation code.
Do not pass editor-written HTML or JSON into the head.

`SearchIndexBuilder($locale)` consumes `SearchDocument(id, typeId, route, title,
mediaId)` and explicit text/weight fields:

```php
$index->add(new SearchDocument($record->id, $record->typeId, $route, $title, $coverId), [
    ['text' => $title, 'weight' => 16],
    ['text' => $summary, 'weight' => 8],
    ['text' => $relatedLabel, 'weight' => 4],
]);
$searchIndexes[] = $index->build();
```

The profile chooses searchable types, included documents, title/summary fields,
related labels, exclusions and result images. The builder preserves document
insertion order, performs the shared Unicode normalization, takes the maximum
weight for repeated tokens, sorts terms and orders postings by descending weight
then ID. Weights are integers 1–255; duplicate IDs, hostile routes, oversized
text/tokens and contract count overruns fail without partially adding a document.
It returns search-v1 `CompiledSearchIndex`; final schema/size validation and
artifact writing remain in the static compiler.

## Build failures and private diagnostics

Raise `Compiler\ProfileBuildFailure` for known invalid content. Its
`ProfileBuildReason` is a finite engine-owned, translated reason. Supply the
frozen record ID and type, the actual page route, or the required singleton
type. Optional `fieldId` and `locale` identify a field and translation:

```php
throw new ProfileBuildFailure(
    ProfileBuildReason::MissingTranslation,
    $record->id,
    $record->typeId,
    fieldId: 'title',
    locale: $locale,
);
```

Other reasons include `RequiredValue`, `MissingSetting`, `Relationship`,
`SharingImage`, `DuplicateRoute`, `InvalidRoute` and `InvalidContent`. Use
`ProfileBuildFailures` with a nonempty list to report several independent
causes, bounded to 100. The engine resolves record titles and field labels from
the frozen content; do not put technical details in titles or other context.

Wrap page construction and rendering with
`ProfileBuildFailure::atRoute($path, fn() => ...)`, or record work with
`ProfileBuildFailure::atRecord($record, fn() => ...)`. These preserve typed
failures and wrap unexpected `Throwable` instances with their page or record
and the generic translated `ProfileError` reason. Put argument preparation
inside the closure too. Legacy unwrapped exceptions can identify only the
website; the engine cannot infer an arbitrary builder's current page.

Configured CMS preflight and worker builds write a bounded technical event to
the private `errorLog`: correlation reference, actual exception class, message
with credential redaction, and a relative profile source location when present.
The editor receives escaped, translated causes and the same reference, never
the exception message, source location or stack. Avoid secrets in exceptions.
Logging failure does not replace the build failure. A missing private log
configuration still permits editor diagnostics but cannot retain technical
evidence. Background failures persist safe message keys and parameters in the
leased job's progress, and deterministic content failures need attention rather
than automatic retries. No diagnostic information enters the public artifact.

## Optional holding presentation

A reviewed package may provide `src/holding.php`, returning a closure that
receives its verified `ProfilePackage` and returns `HoldingPageRenderer`.
Its `render(WebsitePublication, ?WebsiteIcon): HoldingPresentation` produces
trusted escaped body markup and bounded CSS. The engine owns the document
head, noindex policy, root-only routes, checksum-addressed CSS/icon files and
artifact inventory. The hook is included in the package source checksum.
No hook means the engine fallback; an invalid hook fails closed. This is
reviewed profile code, never an executable editor setting.

For packaged fonts or images, the factory may instead return
`HoldingPageAssetRenderer`. Its `assets(): array` names a list of paths from the
verified manifest, and `render(WebsitePublication, ?WebsiteIcon, HoldingAssets)`
receives the checked artifact URLs. Use `$assets->url($packagePath)` in escaped
markup and `$assets->cssUrl($packagePath)` in generated CSS: the stylesheet has
a different relative base. These URLs resolve within private holding inspection
and the published site without changing their bytes.

Only WOFF2 assets with role `font` and PNG, JPEG, WebP or SVG assets with role
`image`/`icon` are accepted. Scripts, additional stylesheets, undeclared paths,
duplicates, symlinks and changed bytes fail before output is created. Request
at most 16 assets, each at most 2 MiB, with at most 8 MiB across declarations.
Each verified file is copied under `assets/holding/<sha256>.<extension>` and
included in the artifact inventory; identical named bytes share one output file.
An unrequested asset has no URL. The complete holding release, including its
icon, page, CSS, descriptors and manifest, is bounded to 40 MiB.

The original two-argument hook and asset-aware hooks requesting nothing retain
the existing public file bytes; source provenance in the manifest changes when
the compiler or package source changes. Reference Studio's
[`src/holding.php`](../../profiles/reference-studio/src/holding.php) demonstrates its packaged
Studio Sans face without data URLs or a Content Security Policy change.

## Speculative navigation

Declare at most one root-level JSON asset with role `speculation-rules`, MIME
`application/speculationrules+json`, byte size and SHA-256 as usual. For example,
`navigation-rules.json` can contain:

```json
{
  "prerender": [{
    "where": {"selector_matches": "a[data-next-page]"},
    "eagerness": "moderate"
  }],
  "prefetch": [{"urls": ["en/about/"], "eagerness": "conservative"}]
}
```

This is a reviewed profile asset, never editor-written JSON. The engine accepts
only `prefetch` and `prerender`, at most 32 rules, with at most 64 URLs per list.
The source and compiled JSON each fit in 32 KiB. Use either `urls` or `where`.
URLs and `href_matches` are relative paths from the artifact root, never absolute
or root-relative URLs: no schemes, hosts, parent traversal, encoded segments,
queries or fragments. A path pattern can end in `*`; a bare `*` selects all paths
inside this mount. `relative_to`, when present, must be `ruleset`.

Predicates support `and`, `or`, `not`, `href_matches` and simple class or
`data-*` presence selectors: `.next`, `a.next`, `[data-next-page]`,
`a[data-next-page]`. Arbitrary CSS selectors and URLPattern objects are outside
this supported contract. Boolean lists contain at most 16 clauses; depth is at
most eight and the complete document has at most 128 predicate nodes. The
compiler adds a positive mount-local URL guard outside every authored predicate,
including negations, and writes deterministic scoped JSON. It pins these compiled
bytes independently of the package's declared source hash. Keep the asset at the
package root so the same bytes resolve under a public subdirectory or any private
preview mount. Other declared asset bytes are copied unchanged.

Apache sends a quoted `Speculation-Rules` header on HTML only, with a same-mount
rules URL, and serves that file with the required MIME through `ForceType`.
The qualified Apache modules/overrides already support these directives. Neither
the public CSP nor the per-page structured-data policy changes; `script-src`
remains limited to the existing self/hash allowances. Previews derive their
header from the verified frozen inventory, not the current package. Every page
and rules fetch still passes ordinary account or review-capability authorization
and retains `no-store`. Old snapshots and undeclared/holding pages send no header.

Choose `conservative` for pointer/touch-down intent, `moderate` for stronger hover
intent, `eager` for earlier browser heuristics, or `immediate` for loading as soon
as rules are considered. List rules default to `immediate`; document rules default
to `conservative`. Prefer a small likely-next-link group: each prerender fetches
and executes another complete page and its assets, increasing host requests and
visitor bandwidth, memory and CPU even when the visitor never clicks. Browsers
can decline speculative work based on settings or resources; unsupported browsers
keep native navigation. Rules inside subframes are not acted on, so the sandboxed
shared-review iframe remains a protected document viewer rather than an instant
navigation surface. Top-level authenticated exact previews carry the feature.
See the [HTML rules standard](https://html.spec.whatwg.org/multipage/speculative-loading.html)
and [Chrome's prerender guide](https://developer.chrome.com/docs/web-platform/prerender-pages).

Prerendering executes scripts before a page becomes visible. Delay timers,
analytics, autoplay and scroll-driven entrance effects until activation:

```js
function startVisibleEffects() { /* reviewed profile effects */ }
if (document.prerendering) {
  document.addEventListener("prerenderingchange", startVisibleEffects, { once: true });
} else {
  startVisibleEffects();
}
```

Do not speculate state-changing endpoints. Reference Studio uses `moderate`
prerendering for its existing `a[data-preview-trigger]` collection-card group.
Its filter and pointer island initializes document-local controls and has no
analytics, autoplay or timed entrance effect.
