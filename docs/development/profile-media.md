# Render images and video

Profile renderers use verified media from the frozen snapshot. They do not fetch
originals or process images during rendering. Use these helpers for responsive
images, crops, sharing images and a Vimeo player behind an authored poster.
For uploaded video formats and field limits, see [media processing](conventional-cms-media.md).

## Responsive images

A frozen `FrozenMedia` retains its ordinary/default rendition and an optional
bounded `widths` list of prepared `RasterImageWidth` facts. Each names its
maximum recipe width, actual dimensions, byte count and checksum. Old snapshots
and in-progress backfills can have no extra widths. `renditions()` lists the
ordinary rendition plus every available prepared width. Select the media ID in
`ProfileSiteBuild::publicMediaIds`; the compiler verifies and ships its frozen
renditions, with no private originals.

Keep ordinary `src`, dimensions, authored alternative text and appropriate
loading behavior, and add these engine-escaped attributes:

`ResponsiveImage::attributes($media, $route, '(max-width: 760px) 100vw, 67vw')`.

Choose `sizes` to describe the reviewed profile's rendered slot, independently
of the image's original dimensions. The helper sorts and deduplicates actual
width descriptors and uses the same relative URL boundary as previews and
publication. It returns complete escaped attributes, not a raw editorial HTML
field. Reference Studio demonstrates full-row figures and smaller cards. Use
only widths in the frozen projection; never query live media or invent a future
2400-pixel URL while a job is pending.

## Image focal points

`FrozenMedia::$focalPoint` is an immutable, validated `FocalPoint` in oriented image coordinates, defaulting to 50/50 for older snapshots. Pass it through any profile-owned view model. Cropped images can use `MediaFocalStyle::className($media->focalPoint)` as an escaped class and link the compiler-generated `/assets/media-focal.css` through `ArtifactUrl`. The stylesheet contains deterministic, deduplicated `object-position` rules and a centre rule, including on sites with no images. `MediaFocalStyle::position($point)` returns the safe percentage pair if a reviewed renderer generates its own stylesheet.

The profile still chooses box size and `object-fit: cover`; focal metadata alone does not crop an image. Avoid inline styles: the public Content Security Policy permits external stylesheets. Shared `CompositionMedia` also carries a point, so layout previews and static image cells use the same helper. Reference Studio’s collection-card image preview demonstrates a 4:3 crop; ordinary figures remain naturally sized. All coordinates come from frozen input; renderers never query live media metadata.

## Sharing images and website icons

`SharingSelections::resolve($snapshot, $discovery, $metadata, $defaultId, $cover)`
maps each actual route to a media ID using override, cover, website default,
then none. Metadata and cover closures accept `(path, actual locale)`; metadata
returns `SearchSharing`, and cover returns a nullable frozen media ID. The locale
comes from discovery, including root aliases and routes without locale prefixes.
Selections require frozen JPEG, PNG or WebP media with authored alternatives or
explicit decoration. Unsupported/absent media and missing text fail before build.
`mediaIds()` returns sorted unique IDs for derivative preparation; `image()`
resolves a route against the compiler-supplied `SharingImages` and rejects an
unprepared selection.

For ordinary asset plumbing, extend the optional `AssetSiteBuilder`. Implement
only these protected methods:

```php
protected function sharingSelections(FrozenSnapshot $snapshot): SharingSelections;
protected function selectedWebsiteIcon(FrozenSnapshot $snapshot): ?string;
protected function buildSite(FrozenSnapshot $snapshot, ?SharingImages $images, ?WebsiteIcon $icon): ProfileSiteBuild;
```

The base implements the three existing build entrypoints and media-selection
interfaces. Assets enter as arguments on each build, including after a failed
build; the base stores no current asset state. Sharing metadata is omitted in
`buildWithAssets()` when no operator-owned public URL context is frozen. Icon
eligibility and verified derivative preparation remain the compiler's existing
responsibility. Return null for a profile that offers no selected icon.

## Vimeo behind a poster

Use `vimeo_video` in profile v1/v2/v3. Declare an engine minimum
of `0.6.0` and an empty settings object:

```json
{
  "id": "film",
  "label": "Film",
  "type": "vimeo_video",
  "localized": false,
  "requiredOnDraft": false,
  "requiredOnPublish": false,
  "settings": {}
}
```

Editors paste `https://vimeo.com/ID`, `https://vimeo.com/ID/HASH` or
`https://player.vimeo.com/video/ID?h=HASH`, choose a prepared raster poster from
the media library and write a title. The server never requests Vimeo metadata,
thumbnails or oEmbed. Other hosts, HTTP, credentials, ports, fragments and extra
query parameters are refused. IDs are 1–20 digits without leading zeroes; an
unlisted hash is exactly ten ASCII letters or digits, with case preserved.

The normalized value is:

```json
{
  "formatVersion": 1,
  "videoId": "123456789",
  "privacyHash": "abcdef1234",
  "poster": "d0000000000000000000000000000003",
  "title": "An evening study"
}
```

`privacyHash` is present with `null` for a public video. Every supplied value
requires a poster and a nonblank title of at most 200 Unicode characters without
control characters. An optional empty field is `null`. A localized field stores
one complete value per content language, so titles, posters and videos may vary
by language. Save, frozen compilation, publication, restore and import enforce
the same normalized contract. Field requirements follow public languages;
languages in preparation may omit their entry. Supplied entries always require
their own complete title and poster. Import remaps the owned poster reference only.

Read the value from the frozen record and render it with the engine helper:

```php
use Utsuri\PortableCms\Conventional\Compiler\VimeoPlayer;

$value = $record->body['film'];
$poster = $snapshot->media($value['poster']);
$markup = VimeoPlayer::render(
    $value,
    $poster,
    $route,
    $locale,
    play: 'Play',
    defaultLocale: $snapshot->defaultLocale,
)->value;
```

Select the locale entry first for a localized field. Include the poster ID in
the build's `publicMediaIds`, author its ordinary localized image description,
and insert the trusted markup in the page. The poster dimensions reserve the
player's shape. Profiles can style the `.cms-vimeo` classes or render their own
markup from the same frozen value. Rich text, composition blocks and typed block
children do not accept this field. Ordinary groups may contain it.

Compilation adds the engine module and stylesheet only when a frozen record has
a Vimeo value. The helper begins with a visible Vimeo link; the module replaces
that action with a real button named Play plus the title. A blocked or absent
module therefore keeps the link. `scripts: false` explicitly renders only the
link. Pressing Play creates one titled iframe, requests
`https://player.vimeo.com/video/ID?h=HASH&dnt=1&autoplay=1`, permits autoplay,
fullscreen and picture-in-picture. The poster remains visible until a message from
that exact Vimeo frame acknowledges readiness, then focus moves to the frame. A
load event alone is insufficient because blocked documents can also report load.
An error or five seconds without readiness removes the frame and restores the
Vimeo link over the poster, without offering Play again. Public videos
omit `h`. Sound is not muted; browser autoplay restrictions may leave Vimeo's
own Play control ready instead of starting playback.

### Preview and security policy

The compiler permits only Vimeo's player origin when a frozen value needs it.
The manifest records that permission; a profile without Vimeo keeps its existing
policy. Do not add arbitrary frame origins or load the provider SDK.

Exact and shared previews use an engine-controlled player outside the opaque page
frame. The shell validates the source window, user activation, video identity and
visible poster rectangle. It sends only the CMS origin to Vimeo, and closes the
player on scrolling, resizing, navigation or failure. Fallback clicks may open
only the validated Vimeo page, without an opener or referrer. Other popups remain
blocked. See [preview sharing](preview-sharing.md) for the access boundary.

Published pages and direct signed-in artifacts create their own player when no
shell answers. The helper follows [Vimeo's ready/ping protocol](https://github.com/vimeo/player.js/blob/master/src/player.js).

The player inherits `strict-origin-when-cross-origin`. Domain-restricted videos
must allow the public website **and the CMS and preview addresses** in Vimeo.
Removing the origin referrer can prevent playback; see
[Vimeo's referrer policy guidance](https://help.vimeo.com/hc/en-us/articles/35817429341457-Troubleshooting-video-playback-errors-due-to-referrer-policy-conflicts).

Before Play, the helper loads only local assets and sends no request to Vimeo.
After Play, Vimeo receives the visitor's IP address. `dnt=1` disables Vimeo's
analytics/session tracking, but essential cookies may still be used; describe
this interaction in the site's privacy policy. See
[Vimeo's player parameter documentation](https://help.vimeo.com/hc/en-us/articles/12426260232977-About-Player-Parameters).
Portable exports own the poster, title and external reference; the video bytes
remain hosted by Vimeo. Use `video_reference` for a self-contained prepared video.
