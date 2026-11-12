# Upload and manage media

Upload images or prepared videos through the media library or a record's media
field. Originals remain private. The worker verifies and prepares files before
they can appear in a published website.

## Upload an image

1. Choose a file within the field and host limits. Raster uploads accept JPEG,
   PNG or WebP up to 40 MiB, 25 million pixels and 20,000 pixels on either axis.
2. Let the upload complete. Reselecting the same file can resume missing parts.
3. Add an alternative description, or explicitly mark a decorative image.
4. Wait for processing, then select the prepared image in the relevant field.
5. Keep the record and inspect a preview. Uploading alone does not select an
   image, save the record or publish it.

For worker setup, interruption handling and API details, see
[media processing](media-processing.md). Profile authors can use the
[rendering helpers](profile-media.md).

## Describing uploaded images

Native library and record uploads lead directly to one description form for the accepted images,
even while derivative processing is queued. Enhanced record uploads show the
same per-image controls beside the upload field as each original completes;
their separate native form saves descriptions without submitting or replacing
unsaved record content. Ready thumbnails appear after normal processing. Images
are still selected in content through the ordinary picker.
Description requests have a fifteen-second bound. A failed request retains the
entered text; a retry checks the library revision again, including after a save
completed on the server but its response was lost.

In the library, select up to eight images and choose **Describe images** to edit
their alternatives, captions, credits and decorative flags on one page. Every
active site language has its own text inputs. Empty fields use the existing
authored default-language fallback; text in languages currently off is retained.
The decorative flag applies to the image across languages, as in media details.
Names and focal points stay unchanged.

All saves use the existing `MediaText` validator and `MediaLibrary` metadata
revision/audit transaction. Each image commits independently: a stale image is
reported as conflicting and its submitted text remains visible, while other valid
images can save. Review current library details before retrying a conflict.
Invalid text remains escaped and editable. Missing alternatives can be saved for
later; the upload controls, selected media field and library flag them using the
same fallback/decorative rules as publishing. A warning does not invent a title
or change publication validation.

The batch is bounded to eight image IDs, at most sixteen site languages, and the
ordinary 500-character bound per alternative, caption and credit. The native
description endpoint alone allows a 2.5 MiB form body to accommodate the worst
case of URL-encoded Unicode within those bounds. A final form marker rejects
truncated submissions before any metadata writes. Video originals use their
existing detail controls; separate image posters can be described here.

## Image focal points

In Library → Media → image details, mark the point that should stay visible when a renderer crops the image to fill a box. The centre is the default. Horizontal percentages run from the left (0) to the right (100); vertical percentages run from the top (0) to the bottom (100). The point is measured on the processed, orientation-corrected image. Whole percentages keep the stored contract bounded and portable.

With scripts available, point on the full image or use its arrow keys (Home centres it). Percentage fields remain the native keyboard and no-script path. The marker and example crop show the current choice; without scripts they update after Save media details. “Reset to centre when saving” clears the selection to 50/50. Names, descriptions and the point save together under the same metadata revision. A conflict preserves the submitted values; review them before saving against the latest revision.

Changes affect the next exact preview and approved publication wherever the image is used. Existing frozen previews and publications keep their previous position. Focal edits do not change originals, derivatives or processor recipes and do not enqueue image work. They apply to images, not prepared video. The point does not create a separate art-directed crop, detect faces or change which image is selected.

Migration `0016-media-focal-point.sql` adds bounded centre-default coordinates to media. The normalized export and snapshot v1 contracts accept optional `focalPoint: {x, y}`; legacy absence restores the centre, while metadata revisions remain preserved. Renderer use is documented in [static profile authoring](profile-media.md#image-focal-points).

## Website ownership

In **Library → Media**, choose **Website (images)** to upload JPEG, PNG or WebP
images independently of any collection entry. Website settings also offer a
native upload beside the website icon and both administration logo pickers.
These uploads preserve pending settings and do not save or select an image.
After the normal worker prepares it, search the picker, choose it and save the
settings. Stale settings submissions refuse uploads before accepting bytes.

Website images appear in the same library searches and image pickers as other
images, including content fields and singleton pages. Ownership does not publish
an image. Originals and library derivatives remain private; publication copies
only assets selected by the compiled site. Before sign-in, only the currently
resolved administration logo derivatives use the existing anonymous logo route.

Existing record-owned images and selections keep working unchanged. To retain
one independently, an administrator opens its prepared image details and chooses
**Keep for website**. This one-way action keeps its ID, checksums, files,
descriptions and selections, checks the metadata revision and records an audit
event. Pending name, description and focal-point edits remain visible for a
separate save. It removes the former record dependency from the media and its
completed upload receipts, so that record can later be deleted. It offers no
general move between records. Videos continue to require a collection record.

Migration `0026-website-owned-images.sql` makes `owner_record_id` nullable in media,
upload sessions and import checkpoints. A null owner means the website; no
placeholder record is created. Website upload sessions also have a null initial
record revision. Existing nonnull IDs retain their meaning. Database constraints
limit this ownership to raster images. Export, import, restore and PHP-only
database backup preserve this scope and checksum-addressed originals.

## Responsive image widths

Raster delivery has four maximum widths: 480, 960, 1600 and 2400 pixels,
quality-78 WebP with the existing first-frame, auto-orientation, sRGB and metadata
stripping recipe. The default `960-webp` remains compatible: a smaller original
retains its own width. Extra targets greater than the oriented original width
are skipped. No image is upscaled. Originals remain private and immutable.

Each extra width is one leased `media_image_width` job, using the unchanged
25-million-pixel/20,000-pixel source limits, single-thread ImageMagick resource
limits and 45-second image-work budget. A worker invocation still starts at most
ten jobs and honors its elapsed-time budget between jobs. Additional widths
increase the number of jobs and retained files, rather than the per-job decode
envelope. Qualification should exercise every width with the actual host's
cron binary and retain its heartbeat memory/time measurements; results for one width do not certify the latency of other widths.

### Backfill and recovery

Each Imagick-capable worker wake discovers at most eight missing widths. Stable
media/width job IDs prevent duplicate work. Upgraded and restored images can
therefore acquire widths without a migration or bulk script.

- An absent processor queues no backfill.
- Ready images stay ready; their default 960-pixel bytes are never replaced.
- Interrupted file commits may be verified and reused after lease expiry.
- Terminal failures remain visible and do not restart on every wake.
- Original preparation takes priority, so backfill cannot delay a new upload.

The separate job type prevents an older engine from claiming an unknown recipe
after rollback. It can still prepare originals; width jobs wait for a capable
engine.

New exact previews freeze all currently committed widths and their dimensions,
sizes and checksums. Pending targets are absent. Legacy/saved snapshots retain
their original projection, so backfill cannot change old preview bytes.
Profiles use `ResponsiveImage::attributes($media, $route, $sizes)` to obtain
escaped `srcset` and `sizes` attributes, keeping an ordinary `src`, dimensions
and authored alternative text. Its candidates are sorted by actual width and
use mount-independent artifact URLs. See [profile authoring](profile-authoring.md).
Publication includes every frozen width of selected media; unselected media and
private originals stay outside the static artifact. Normalized exports retain
originals as the portable source and include available derivative provenance
and bytes. Restore preserves those files; missing widths are regenerated by
the destination's qualified worker from originals.

Use the [profile media helpers](profile-media.md#responsive-images) to select
widths for the layout. Qualify processing and total storage for the real site;
fictional fixture budgets are not production capacity guarantees.

## Prepared video and separate posters

A profile declares `preencoded_video` as an optional or required capability.
Qualified hosts then accept prepared MP4 or WebM through the same native and
resumable upload boundary, with eight files per native/enhanced batch. Images
retain their 40 MiB ceiling. Videos default to 40 MiB and can reach 256 MiB
(268,435,456 bytes) on explicitly qualified hosts. A 64-bit PHP runtime and native random-access
file, checksum and copy operations are required. The environment report exposes
processor availability and the upload limit; absent support disables the file
choices and rejects video intent before a job exists.

`VideoMetadata` is a pure bounded random-access container inspector. It reads at
most 2 MiB of metadata and visits at most 4096 boxes/elements, skipping sample
payloads. MP4 accepts a self-contained, nonfragmented H.264 `avc1`/`avc3` video
track with optional `mp4a` audio; WebM accepts VP8/VP9 with optional Opus/Vorbis.
Each file requires one video track, declared finite duration up to two hours,
positive geometry up to 4096 pixels on either axis and 8,847,360 pixels total.
Encrypted tracks, external MP4 data references, unsupported codecs, truncated
structures and missing metadata fail closed. WebM segment size may be unknown;
other element sizes must be finite. Live/fragmented streams and transcoding are
outside this contract. Inspection qualifies container metadata; it does not
decode every compressed sample or promise support for every browser codec profile.
The parser follows the [WebM container guidelines](https://www.webmproject.org/docs/container/)
and [ISO BMFF structure](https://www.w3.org/TR/mse-byte-stream-format-isobmff/).

Upload completion validates the container before queuing. The leased worker
rechecks source size, checksum and metadata and copies the exact prepared bytes
into `prepared-video` recipe version 1. No shell, external decoder or image engine
receives video input. The immutable derivative records dimensions, codec and
duration. Disk failures use the existing bounded retry and lease protocol;
unsupported containers are terminal failures.

A `video_reference` retains exactly `mediaId` and `posterId`, naming a ready
prepared video and a distinct ready raster image. Native search/pagination and
separate labelled visual pickers show asset names; editors never enter identities or
JSON. Uploading adds files to the shared library, while selection remains an
ordinary revisioned record save. Localized and group video references use the
same domain contract, and validation/conflict responses retain both choices.
Profile field `maximumBytes` may tighten the global video ceiling.
Upload creation uses the greatest video field allowance on the owning record
type; selection, frozen compilation and restore enforce each field's own limit.
The committed reference profile deliberately retains its 40 MiB field allowance.
An installed trailer profile must explicitly allow the larger size.

### Qualifying larger trailers

`videoUploads` is absent or `null` on existing installations. The private runtime
configuration accepts exactly these three integer values after qualification:

```json
{
  "maximumBytes": 268435456,
  "requestTimeoutSeconds": 180,
  "verifiedCompletionSeconds": 60
}
```

These numbers illustrate the shape; they are not evidence for a customer host.
Keep larger uploads unavailable to editors while qualifying a staging installation
with the same web/CLI PHP settings, account quota, filesystem and web-server/proxy
timeouts as production. Provision the intended profile allowance and provisional
private configuration there, then upload a prepared file of `maximumBytes` through
the real web endpoint. Interrupt and resume it, complete assembly, run the actual
leased preparation worker, create an exact preview, approve its staged publication
and play opening and end ranges of the static trailer. Exercise the intended
complete site's final verification as well as its asset-copy batches.
Repeat after cold filesystem reads. Record the slowest elapsed time across
assembly, preparation, publication batches and final verification, rounded up
to whole seconds, as `verifiedCompletionSeconds`.
Each must finish within half the configured request budget; reduce the size or
decline qualification if the staging trial fails. Keep the trial record with the
operator's host qualification evidence, then install its reviewed configuration.

The finite request budget is 30–180 seconds and must fit the effective PHP
execution/input limits and the independently verified server/proxy timeout.
Zero PHP time limits mean unlimited; input `-1` inherits the execution limit.
The resumable session still lasts 24 hours from creation and uses exact 5 MiB
parts. Network transfer spans many requests, so the whole upload need not finish
in 180 seconds. Assembly checks its injected clock between 1 MiB reads, removes
partial temporary output on timeout, and retains the parts for a completion retry.
Qualification must also prove each part request fits the host's ingress timeout
at the supported connection speed. An interrupted browser resumes using the same
client upload ID in the same browser tab/session.

Qualification requires at least 64 MiB PHP memory, PHP multipart upload capacity
of 5 MiB, POST capacity of 6 MiB, and private filesystem space for four copies of
the selected ceiling plus 64 MiB reserve. At 256 MiB this is 1,088 MiB. Check the
account quota as well as filesystem free space. Upload creation rechecks the
four-copy reserve, every part checks space for its bounded write, and completion
requires space for three further copies because the parts already occupy one.
An unavailable fact or regressed limit returns the video ceiling to 40 MiB;
actual insufficient storage still refuses even a smaller upload. This is a
per-upload reserve, not a global reservation against concurrent uploads; ordinary
disk errors retain the existing retry behavior.

`media.preencodedVideo` in both environment reports exposes `maxBytes`, `reasons`,
`nativeMaxBytes`, the request budget, part size and session lifetime. Missing
qualification is explicit, and the web/CLI comparison flags differing ceilings
or reasons. Use the lower qualified ceiling and slower measured completion time
across the two SAPIs. The runtime rechecks local PHP limits and free space at
construction; an acknowledged session keeps its original size contract while
storage, expiry and effective timeout checks continue to apply. Its own stored
parts may lower the ceiling for new sessions without preventing its resumption.
The browser remembers the server acknowledgement separately from the client ID,
so preflight still permits that file's resume while rejecting fresh oversize files.

The record editor displays the effective profile/host ceiling and rejects an
oversize selection before a request. Native forms retain PHP's smaller multipart
file and total POST bounds, displayed separately; use the record editor's enhanced
resumable uploader for larger trailers. Eight selected files do not each receive
the full POST allowance. No transcoding or codec/geometry relaxation accompanies
the larger ceiling.

Private layout previews serve only the checksum-verified delivery derivative
through authenticated `/media/{id}/video`, including single forward byte ranges;
library and exact/shared preview responses verify and stream the same open file
descriptor in bounded chunks rather than buffering the complete video. Public
Apache delivery also serves large byte ranges directly from the static artifact.
Image thumbnail routes cannot return videos or originals. Exact previews freeze
both selections and ready delivery facts, and the ordinary static compiler emits
native `<video controls preload="none">` with a separate poster and immutable
relative source URL. Public playback uses static files only. Normalized exports
retain original/derivative checksums, recipe provenance and both selections;
restore validates container facts and geometry before committing any bytes.

`tests/php/video-test.php` covers real tiny MP4/WebM files, bounded and hostile
metadata, capability absence, idempotent upload, native controls, wrong-kind
references, private ranges, byte tampering and symlink refusal. The MariaDB
export/restore journey runs both formats through real leased preparation, retains
both checksum inventories and recompiles the MP4/poster composition byte for byte.
`tests/browser/video-journey.mjs` exercises uploads and video/poster choices with
page scripts disabled, native private playback, validation recovery, enhanced
resumable upload, revision conflicts and a 360-pixel layout.
`tests/php/trailer-upload-test.php` generates a sparse 250 MiB valid MP4 fixture
with an opaque `free` box, resumes after a one-hour interruption, prepares exact
bytes, restores the normalized original/derivative inventory, streams full/range
responses and checks peak memory growth under 16 MiB.
It also proves missing/regressed qualification, profile and image caps, disk
refusal, assembly timeout and retry. The Apache integration compiles that large
fixture with an explicitly wider test profile and verifies opening, tail and
unsatisfiable ranges over HTTP. Generated payloads are temporary, never committed.

## Per-field prepared-video limits

Engine 0.4.0 adds optional `video_reference.settings.maximumDurationSeconds`
(integer 1–7200), `maximumWidth` and `maximumHeight` (each integer 1–4096),
and `sound` (`allowed`, the default, or `none`). They supplement the existing
required `posterRequired: true` and `maximumBytes`. Omitted settings preserve
older profiles' meaning and the global container/host bounds still apply.
Profile schemas v1, v2 and v3 and the installed package loader check these settings.

For example, a decorative loop may declare the following reviewed field. Profiles
using the new settings must require engine 0.4.0 or later:

```json
{
  "id": "decorativeLoop",
  "label": "Decorative loop",
  "type": "video_reference",
  "localized": false,
  "requiredOnDraft": false,
  "requiredOnPublish": false,
  "settings": {
    "posterRequired": true,
    "maximumBytes": 10485760,
    "maximumDurationSeconds": 15,
    "maximumWidth": 1920,
    "maximumHeight": 1080,
    "sound": "none"
  }
}
```

`none` means no audio track, including a silent or muted audio track. MP4 AAC
(`mp4a`) and WebM Opus/Vorbis tracks are recognized by the bounded PHP inspector.
No transcoding, trimming, muting or resizing is performed. The editor shows the
field's limits in the selected interface language and disables unsuitable library
choices with their measured value and the allowed maximum, or the sound rule.
Native forms retain a previously selected unsuitable clip until the editor changes
it, so it is rejected visibly instead of silently cleared.

A saved collection's video-field upload reports a failed rule as soon as the
completed original has been inspected. The upload remains in the library for
other fields; choose it after normal preparation. The owning record's greatest
video byte allowance and the qualified host ceiling still govern upload admission.
The native per-file ceiling remains 40 MiB. A field's narrower byte allowance is
also enforced on selection/save, and does not discard a valid library upload.

`VideoLimits` owns the shared rules for save, Publish, frozen graphs, restore and
existing-installation import. Preview freezing inspects checksum-verified video
bytes and exposes `FrozenMedia::durationMilliseconds` and `FrozenMedia::hasAudio`
to reviewed renderers. Snapshot v1 accepts the two optional fields together;
legacy absence means unknown. New prepared recipes persist `hasAudio` alongside
`durationMilliseconds` and codec. Ready legacy recipes missing audio metadata
are inspected from private prepared bytes on demand; they never imply silence.
The compiler and restore also verify claimed video metadata against actual bytes.

Restore checks every historical revision, including grouped and block video
references, and refuses incompatible clips rather than discarding references.
Existing-installation import checks its current-content projection from verified
original bytes before its existing image-only media boundary: prepared-video
import is still unsupported, even for clips that meet every field rule.

Tightening any video field setting is content-affecting. The upgrade dry run
identifies the affected record type/records and requires a declared content
migration. Review affected clips before applying a profile upgrade; these settings
do not rewrite historical content or transform media.

## Administration video previews

Engine 0.5.1 uses visual cards for both video and separate poster choices. Native
selection buttons redisplay the unsaved form; the browser island updates the
same hidden identities and player immediately. Neither action saves a revision.
Record players have native controls, a filename label, `muted`, `preload="none"`
and the chosen image as `poster`. Browsing candidates never loads video bytes.
The media detail player uses `preload="metadata"` and shows duration, dimensions,
size, codec and records that use the clip. No player autoplays.

Ready library videos show a Video marker and duration, with a neutral video icon
until a frame exists. Preparing and failed assets retain their status markers.
On library pages the browser decodes visible missing frames sequentially, without
playing the clips, and captures a WebP no larger than 320 pixels on its longest
edge or 64 KiB. Existing clips are handled the same way; unsupported browser
codecs, disabled JavaScript and capture failures retain the neutral fallback.
A manually played field or loaded detail player can also supply a frame.
There is no server video decoding and no captured frame becomes an authored
poster. Redundant thumbnails have empty alternative text.

`POST /media/{id}/video-frame` requires an authenticated editorial session,
same-origin request and CSRF token. It checks the prepared source checksum,
bounded WebP bytes and expected scaled dimensions. The first valid frame wins;
repeated captures are idempotent. Atomic private files live under
`media/video-frames/{id}-{source-checksum}.webp`, outside canonical derivatives.
Authenticated thumbnail delivery serves the cached image. These disposable hints
are excluded from export, restore and frozen/public artifacts and need no backup;
a restored installation regenerates them on later library visits. Cache loss
does not change content, authored posters or media revisions.
