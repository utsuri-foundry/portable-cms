# Configure search and sharing metadata

Profiles supply content-derived defaults; editors can override titles,
descriptions, indexing and sharing images. The compiler freezes the resolved
metadata with the pages, so changes reach visitors only through an approved
release. This guide explains the editing behaviour and the rendering contract.

## Editorial controls

Open a record's **SEO and sharing** section. The profile decides which record
kinds expose it. Controls follow the selected content language and work through
the ordinary revisioned native form.

| Control | Empty value or default |
| --- | --- |
| SEO title | Page title and site name, using the selected title format |
| Meta description | Page summary, then the localized website description |
| Sharing title and description | Resolved search text |
| Sharing image | Record cover, then the website default, then none |
| Exclude from search | Page stays public; discovery metadata omits it |

The preview shows effective values and their sources. A custom SEO title replaces
the complete generated title; it does not receive another site-name suffix.
Title formats are page then site, site then page, or page only. Missing optional
text remains empty and never borrows another language.

Excluding a page from search is not access control. It stays publicly reachable.
The storage bounds of 240/500 characters do not predict search-engine truncation,
and search engines may display different text. Treat the on-screen specimen as
an illustration.

## Website defaults, recordless pages and icons

Website settings store an optional localized `defaultSearchDescription`,
bounded to 500 plain-text characters. Effective descriptions resolve the page
override, then its content summary, then that locale's website default. A missing
locale stays empty; another language is never borrowed. The editor reports the
last fallback as `website_default`, just as it reports the default sharing image.
These fields are ordinary revisioned content and survive normalized export and
restore without provider identifiers or generated metadata entering content.

A profile can put multiple localized search/sharing groups on its settings
singleton. Each group's bounded `searchSharing.pagePath` maps a generated page
without a record to that group; paths are unique and belong to the settings
singleton. `titleFallback` supplies its localized visible page title, and
`defaultDescriptionField` identifies a bounded localized settings field. Every
mapped group uses the ordinary native SEO editor, validation and media picker.
Reference Studio maps its work, journal, people, discipline and sector indexes,
search and settings pages. Index visibility feeds discovery. A mapped utility's
`indexing: excluded` policy fixes noindex in both discovery and the editor, which
explains the exclusion and omits the indexing toggle. The settings utility uses
this policy. Taxonomy terms without summaries also inherit the website
description. No fixed per-language description remains in renderer source.

A `media_references` field with `purpose: website-icon`, `maximumItems: 1` and
image kind stores the optional website icon ID. Save and restore share the PHP
eligibility rule: ready PNG, WebP or JPEG, square, at least 512 pixels. The native
picker disables unsuitable media. SVG editor uploads remain unsupported;
reviewed packaged vector assets may supply the fallback icon.

`WebsiteIconProcessor` is a separate injected compiler boundary. Its Imagick
adapter verifies prepared display bytes, SHA-256, raster magic, decoded MIME,
single-frame geometry and resource bounds before producing stripped sRGB PNGs
at 32, 180 and 512 pixels. It never reads editor originals or interprets SVG.
Generated files have checksum-addressed paths and a source/recipe identity in
`artifact.websiteIcon`; the compiler verifies them with the rest of the artifact.
Every route links the small icon, touch icon and large icon, with relative URLs
that work inside a private preview. Without a selection, Reference Studio links
its verified packaged `assets/icon.svg`. A selected icon without a processor
fails compilation; it cannot silently become a missing asset or a fallback.

Proof includes native HTTP validation, signature and geometry hostility,
deterministic icon recipes, every-route output, no-script browser saves and
frozen index metadata. The isolated MariaDB restore journey uploads a real square
PNG, selects it through ContentService, exports/restores its content and media,
and compares regenerated icon manifests and public files byte for byte.

## Sharing images

Profiles select one frozen media ID per public page: localized override, page
cover, then the revisioned website default. Empty choices mean no image, not a
fabricated placeholder. The reference cover is the first composition image or
gallery image, or a person's portrait. Website settings provide a native picker;
localized overrides reuse that same no-script picker and revision boundary.
Profile source mappings also report the effective image name and provenance in
the editor. Unavailable selections fail at save or build preflight.

The build injects a `SharingImageProcessor` separately from profile rendering.
Imagick consumes verified prepared display bytes, never originals. It checks
signatures, frozen dimensions and hashes before bounded pixel decoding, then
contains the full image without enlargement on a white 1200 by 630 JPEG canvas.
It converts to sRGB, strips metadata, and encodes at quality 82. Images process
sequentially in the queued build, not in an editor save request. The current
bounded artifact permits at most 1,000 distinct sharing images and 10 MiB per
input/output image; geometry is limited to 25 million pixels and 20,000 per side.
Resource caps never raise a stricter host limit and are restored after each job.

Actual output SHA-256 identifies the public JPEG path. The artifact pins source
hash, output hash, dimensions, size, recipe version and ImageMagick version.
Identical input and pinned encoder produce stable bytes; different host encoder
versions may differ. Approval and delivery use the exact built bytes, not a
later re-encode. This recipe is for sharing, not a new private display variant.

HTML emits absolute public URLs, JPEG MIME type, dimensions, localized title
alternative text and `summary_large_image`. Without a publication context,
legacy previews omit sharing URLs rather than infer an origin from the request.
Private previews serve the same checksum-verified JPEG under authentication
without changing the production metadata URL or weakening their privacy headers.

## Native editorial feedback

Trusted profile group settings can declare bounded `searchSharing` source
mappings: a website-settings singleton, its name and format fields, an optional
title field or localized fixed title, and an optional summary field. The engine
does not infer field names or embed client-specific IDs. Rich-text summaries
share the validated first-block excerpt helper with the compiler.

Discovery shows effective search and sharing copy for each language, with
provenance such as your override, page summary or effective search title.
It is explicitly illustrative, not a promise about search-engine presentation.
It updates on ordinary native server renders from the form and saved website
settings; an exact preview remains the authority for the complete frozen page.
Incomplete or invalid input stays editable and does not break the form.
Optional missing or repetitive copy gets non-blocking guidance, never a score,
mandatory artificial length or compulsory AI generation. Noindex remains a
per-language choice; excluded counterparts and an excluded default home cannot
appear as hreflang or x-default destinations.

## Publication URLs and route discovery

The private operator configuration optionally declares `publicUrlContext` as
`{"formatVersion":1,"baseUrl":"https://studio.example/optional-base"}`. It is
separate from the CMS origin, HTTPS-only, bounded, and rejects user information,
query strings, fragments, encoded separators and traversal. It never reads a
request Host header. DNS is not resolved during compilation.

Preview enqueue freezes this context in its durable database projection. The
ordinary compiler includes it in snapshot identity and artifact provenance with
a checksum. Changing configuration after enqueue cannot rewrite that preview;
publishing at another origin requires a new exact build. Publication must compare
its target context with the manifest before activation. The
[publication service](publication-workflow.md) verifies the frozen context against its target.

Snapshot and artifact v1 gain optional bounded context properties without
reinterpreting existing documents. Older documents remain valid; older readers
that do not support the addition reject it through closed-property validation.
Normalized exports carry optional `sourcePublicUrlContext` provenance,
validated by the same URL boundary. Older exports remain accepted. Metadata,
settings and portable media IDs already travel as ordinary revision bodies;
PHP, MariaDB and native browser checks prove their normalized round trip.
Source provenance is not destination configuration and never mutates it.
Restore preserves these values without automatically adopting the source URL. A destination
operator chooses its URL separately and builds new approved bytes for it.

Trusted profiles construct a `RouteDiscovery` from actual routes and stable
translation group IDs. The engine rejects duplicate routes/languages, missing
canonical/fallback targets, alias chains, contradictory indexing and disagreement
with the compiled route set. Self-canonical URLs, Open Graph URLs and reciprocal
language annotations are escaped in original HTML. Only real fallbacks produce
`x-default`. The reference root contains the default home, canonicalizes to that
localized home and is the home group's real fallback. Detail translations remain
self-canonical with their own localized slugs. Current profile preflight still
requires its published translations; sparse-language graphs are engine-tested,
not a claim that the profile already supports partial publication.

The compiler hashes `sitemap.xml` and `robots.txt` as ordinary artifact files.
The sitemap excludes noindex pages, redirects and duplicate canonical aliases.
`lastmod` is omitted until meaningful content-change timestamps are available;
it must not be fabricated from build time. Robots allows crawling so page-level
noindex can be observed. Reference settings routes are internal and noindex.
When legacy input has no public context, previews continue without inventing
canonical URLs or a sitemap. Their private noindex/no-store headers are unchanged.

For a subdirectory installation, robots.txt is included for inspection but the
operator must publish the host-root robots policy or add the sitemap to the
existing host policy. A robots file under a subdirectory is not site-wide policy.

PHP graph tests cover sparse translations, fallbacks, collisions and invalid
URLs. MariaDB integration changes worker configuration after enqueue and proves
that the artifact still uses the stored context. Chromium verifies canonical and
translated URLs and sitemap/robots through authenticated previews while all
ordinary navigation remains beneath the preview mount.

## Published routes and public delivery

`PublishedSlugHistory` is a bounded projection of trusted successful publication
events. The publication service must authenticate the verification receipt,
compare-and-set the previous release and persist history privately only after
verified activation. These operations live in the publication service;
constructing the projection alone is not evidence of activation.

When supplied by that boundary, snapshots pin history to the exact profile and
public URL context. Durable queue storage preserves it when worker configuration
changes. Preflight rejects occupied historical paths. Previously published paths
resolve directly to the latest route for the same record and language, without
chains, cycles or guessed cross-language destinations. Draft-only slugs never
enter history. Removed translations receive no invented redirect. Normalized
exports preserve the optional history as provenance; import must not silently
activate it or adopt its source origin.

The artifact includes canonical route identities and a hashed
`delivery/apache24.conf` descriptor. An Apache 2.4 publication target must verify
the complete artifact and apply this exact configuration as `.htaccess` only
inside its owned, dedicated public root. It requires `mod_alias`, `mod_headers`,
`mod_dir` and `FileInfo`/`Indexes` override permission; missing modules do not
silently disable redirects. The rules issue exact-path 308 redirects, apply
public security headers and preserve the distinct private preview policy. The
header CSP permits only same-origin executable resources, without unsafe inline
scripts or styles; trusted JSON-LD remains inert data. Stable asset filenames
revalidate rather than pretending to be immutable across releases. Configuration
contains approved public URLs, not private configuration or credentials.

Filesystem hashes are the mandatory activation gate, including applied rules
and the complete route set. Served-origin fetching is a smoke check, not a
substitute for filesystem verification. The repository's pinned local Apache
test proves live 308 responses, exact matching, static page and sharing-image
bytes, public headers and separation from a CMS mount. It is not qualification
of a real hosting provider. The publisher integration additionally exercises
actual promotion, history persistence, slug changes, rollback and failure/recovery
against this target. See [publication and recovery](publication-workflow.md).
Qualify the actual host before relying on its delivery behaviour.

Adapter references: Apache's
[RedirectMatch](https://httpd.apache.org/docs/2.4/mod/mod_alias.html#redirectmatch),
[Header](https://httpd.apache.org/docs/2.4/mod/mod_headers.html#header) and
[AllowOverride](https://httpd.apache.org/docs/2.4/mod/core.html#allowoverride).

## Structured data and CSP

The trusted reference renderer maps visible organization identity on Home/About,
visible article headings and summaries on article detail pages, and actual
navigation destinations into bounded Organization, Article and BreadcrumbList
facts. Listing pages do not claim to be articles. Missing dates, ratings, awards
or authors are never invented. The optional revisioned organization name is
plain text and also appears in the footer; blank uses the visible website name.

The engine serializes only those approved fact shapes and graph-verified public
URLs. JSON-LD output uses the standard root shape, not a CMS envelope; its v1
output schema is separate from editorial content. JSON_HEX_TAG, JSON_HEX_AMP,
JSON_HEX_APOS and JSON_HEX_QUOT prevent script-closing injection while preserving
text semantics after JSON decoding. There is no editor-authored JSON-LD or HTML.

Generated pages carry a CSP meta policy before their resources, allowing self
resources and the SHA-256 hash of the exact serialized JSON block, not general
unsafe-inline execution. The static artifact hash pins both the policy and JSON.
Private preview HTTP headers remain independently restrictive; JSON-LD is an
inert data block, not executable JavaScript. Meta CSP does not replace delivery
headers: framing policy, HSTS and other host-level rules remain deployment-target
obligations. The public browser journey proves original/no-script data, hash
matching, denial of unapproved inline execution and absence of admin noindex
headers on static output. The publication workflow also verified qualified-host
served hashes, applied rules and public/private header separation.

This follows [Google's structured-data formats](https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data)
and [MDN's CSP hash guidance](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/script-src),
The markup makes no rich-result or ranking guarantee.

## Contract and security

`portable-cms-search-sharing-v1.schema.json` is the standalone versioned
plain-text override contract. Its `overrides` object is the same per-locale
value represented by the profile's bounded group fields. Record/export JSON
does not insert an extra envelope into the group: the profile definition pins
its shape and the enclosing product format pins its version. Accepted and
rejected fixtures execute the schema; PHP domain construction rejects unknown
keys, non-string values, control characters, invalid UTF-8 and oversized text.

The v1 text-only schema remains unchanged. V2 adds a boolean indexing policy;
V3 adds a zero-or-one portable media-reference list for the sharing image.
the resolver accepts legacy objects with no policy as eligible. Profile package
identity pins the additive group fields; no database migration or automatic
revision rewrite is required.

The pure engine resolver supplies effective values and stable provenance keys.
The reviewed head renderer emits one title, an optional description, Open
Graph text and a social card (text-only when there is no prepared image). It uses separate HTML text and
attribute escapers. Quotes and literal angle brackets are text, never executable
markup. Raw HTML, arbitrary head attributes, URL overrides and scripts are not
accepted. Absolute URLs come only from the operator-owned frozen context;
JSON-LD comes only from bounded reviewed visible-page facts.

An older record with no group or a null group resolves to the defaults. The
additive profile fields do not require a database migration, change IDs or
mutate existing revision bodies. Overrides are ordinary revisioned content and
are frozen with the record body. Compilation reads that snapshot, not live CMS
state. The profile package and compiler fingerprints cover both the reviewed
mapping and the engine resolver/head-rendering bytes. Golden fixtures are
regenerated deliberately when those bytes change.

SEO titles never replace the visible heading, navigation label, slug, search
index text or gallery caption. Private exact previews retain session checks,
no-store and noindex delivery headers independently of the generated head.

## Verification

Inspect effective values in the CMS and the generated HTML, sitemap, language
alternates and sharing-image URLs in an exact preview. Verify final URLs after
publication and after a host or mount change. For engine changes, run the
search/sharing and public-site lanes in the [testing policy](testing.md).
