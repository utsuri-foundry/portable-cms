# Configure uploads and media workers

Operators configure private storage and a scheduled worker. Developers use the
upload protocol and persistence rules below. For descriptions, focal points and
video selection in the CMS, see [working with media](conventional-cms-media.md).

Configure cron even on a host that can start work after a web response: cron is
the recovery path when a request or process ends early. Qualify the actual web
and CLI PHP environments before enabling a capability.

## Protocol

Raster uploads accept JPEG, PNG and WebP with these limits:

| Input | Limit |
| --- | --- |
| Original file | 40 MiB |
| Decoded geometry | 25 million pixels; 20,000 pixels on either axis |
| Upload part | Exactly 5 MiB, except the final remainder |
| Unfinished session | Expires after 24 hours |

Record uploads name protocol version 1, the record and its current revision, a
bounded browser upload ID, filename, declared type and exact byte size. Website
uploads use the same service without a record/revision and have no separate JSON
creation endpoint.

The authenticated JSON routes are:

```text
POST   /api/records/{type}/{record}/media-uploads
GET    /api/media-uploads/{session}
PUT    /api/media-uploads/{session}/parts/{number}
POST   /api/media-uploads/{session}/complete
DELETE /api/media-uploads/{session}
GET    /api/jobs/{job}
```

Every mutation requires the existing session-bound CSRF header and exact same-origin policy. Responses contain only stable errors, public upload progress, media metadata, and job metadata. Filesystem paths, database details, active client keys, part checksums, and provider configuration remain private.

The external native ES-module island stores only the client upload ID in `sessionStorage`. Re-selecting the same file asks the server for authoritative completed parts and transmits only missing parts. Multiple selected files are processed sequentially so browser and host memory remain bounded.

## Storage and persistence

Migration `0004-media-uploads.sql` adds generic media, upload-session, upload-part, and job tables. Media belongs to a stable generic record or, since migration `0026`, the website itself. An active client key makes creation idempotent per ownership scope, editor, and browser upload ID. Completion creates the media record, queues one `media_derivatives` job, closes the session, and appends one attributed audit event in a single transaction.

Parts and originals live below the configured private media root, which defaults beside the owner-only runtime configuration and outside the document root. Part writes use new temporary files, bounded streaming, SHA-256, exact byte counts, owner-only permissions, and atomic rename. Completion streams parts in order into a private temporary original, verifies total size and declared JPEG, PNG, or WebP signature, then atomically commits it. Invalid signatures are deleted and terminally rejected. Parts are removed only after durable database completion, allowing safe retry after an interrupted assembly or transaction.

The runtime refuses unsafe roots and insufficient disk headroom. Opaque IDs determine paths; filenames never do. No uploaded bytes are served from the public document root or interpreted by PHP.

## First derivative and durable execution

The image recipe checks header geometry before decoding. It processes the first
frame, auto-orients, converts to sRGB, strips metadata and produces quality-78
WebP without upscaling. The default width is 960 pixels; other widths are described
in [responsive images](conventional-cms-media.md#responsive-image-widths).

Each derivative records its processor/version, normalized recipe, byte count,
dimensions and checksum. The geometry limit applies before full decode, including
to rejected sources. Qualification must measure native ImageMagick memory as well
as PHP allocations.

The filesystem commits the verified derivative below the private media root before one MariaDB transaction marks the media ready, inserts immutable derivative metadata, completes the matching leased job, and appends an attributed audit event. A retry may reuse an existing file only when its size and checksum match exactly. Conflicting bytes at the same logical key fail terminally.

Job claiming uses an atomic ordered MariaDB update with a random 256-bit, 15-minute lease and increments the attempt before work begins. Only the current unexpired lease may complete or fail. Retryable infrastructure failures return to the queue with bounded backoff until the maximum attempt count; invalid or unsafe sources move both job and media to `needs_attention` with a stable code and no internal diagnostic in the browser.

On qualified FastCGI hosts, upload completion sends the `202` response first and then claims one job through `fastcgi_finish_request()`. Installations select either `post_response` or `cron_only` as their bounded media wake strategy; unknown values fail closed. The editor uploads every selected original sequentially and durably queues the complete batch before polling derivative jobs, so processing latency cannot prevent later originals from reaching durable storage on a cron-only installation. It reports ready, queued/retrying, or needs-attention state without reloading. `tools/conventional-media-worker.php` is the authoritative cron recovery path; it accepts bounded job/time budgets, records an owner-only heartbeat with job counts, elapsed milliseconds, PHP-allocator peak bytes, and Linux peak resident-process bytes, and requires only the private runtime configuration path. PHP and resident memory are deliberately separate because ImageMagick uses native allocations outside PHP's memory counter.

### Requests that wake work after the response

With `mediaWakeStrategy: post_response` and `fastcgi_finish_request()` available,
the web entry point sends and finishes the response before running bounded work:

- Media upload completion (`POST /api/media-uploads/<id>/complete`), native record
  uploads (`POST /records/<type>/<id>/upload`), and `POST /jobs/wake` acknowledge
  with `202`, then run the existing single-job media budget.
- Preview creation from the previews list or preview page (`POST /previews`,
  `202`), and a pending exact opening from the record editor
  (`POST /records/<type>/<id>/exact-preview`, `303` to a waiting address), run
  one leased preview job from the shared queue. A ready exact preview is reused
  without waking work. Rejected requests and waiting/status GETs wake nothing.
- `POST /releases` with `action=build_holding` wakes one preview job. Other
  accepted release actions retain their existing bounded publication wake.

With `cron_only`, or without FastCGI response finishing, these requests only
queue work. Configure `tools/conventional-media-worker.php` on the host scheduler
for both ordinary builds and recovery. The exact-preview tab distinguishes queued
from building and, on cron-only installations, explains that it waits for the
scheduled worker. That wait follows the host's cron interval. Its two-second
Refresh header and manual **Refresh status** link continue until ready or failed.

## Cron recovery acceptance

The CLI worker must run through the host's actual cron PHP binary and account, not through an interactive shell with potentially different extensions or limits. A representative invocation is:

```text
/usr/bin/php /absolute/private/runtime/tools/conventional-media-worker.php --config=/absolute/private/cms.json --max-jobs=3 --max-seconds=50
```

1. During qualification, schedule one-minute runs using the host's real cron PHP
   binary and account. Enable no-overlap scheduling if available.
2. Confirm that an empty invocation updates private `worker-heartbeat.json`.
3. Set `mediaWakeStrategy` to `cron_only`, interrupt and resume an upload, and
   verify cron completes the durable job without a web wake.
4. Review counts, elapsed time, PHP peak and resident-process peak memory. Heartbeat
   v2 also records up to ten per-job measurements and retained memory growth.
5. Restore the selected operating strategy after testing.

Do not infer native-memory use from PHP's allocator counter. Test sustained work
and every enabled image width on the actual host.

Database leases remain authoritative even when the scheduler offers an exclusive option. The isolated MariaDB integration uses separate connections to prove that a second worker cannot steal a live lease or consume an attempt, then advances beyond expiry and proves that another worker can reclaim and complete it.
