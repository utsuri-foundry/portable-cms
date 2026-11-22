# Share a private website preview

Give someone outside the CMS a link to a preview without giving them an account:
the designer, the client, a distributor. A link shows either one frozen preview
or always the newest ready preview. Every active account may make, extend and
stop links: owners, administrators and editors alike.

Treat each link as a secret capability. Anyone holding it may read the permitted
preview until it expires or is stopped. Previously downloaded bytes cannot be
recalled. See [exact previews](exact-website-preview.md) for building and
inspecting the preview before sharing it.

## Share from the Previews page

On **Previews**, every ready preview row and every preview page has a **Share**
panel. It asks three things:

- **What the link shows**: this preview, or always the newest preview. A newest
  link follows every new ready website preview, drafts and languages in
  preparation included; coming soon builds are never part of it.
- **For how long**: 1, 7 or 30 days, 7 by default.
- **Who it is for**: a free note such as a name or a company. The note names the
  link in the list; without one the link is counted ("Link 3").

Create link shows the complete link in a field with **Copy** and a sentence
saying what it shows and until when. The **Shared links** list under the
previews shows every link by its note, what it shows, until when in the site's
time zone and who made it, with **Copy**, **Extend** and **Stop**. An expired
link stays listed for thirty days with **Extend** and **Remove**; Extend counts
from today with the same three lifetimes. At most 50 links of each kind can be
active at once. Queued, failed, deleted or coming soon previews cannot be
shared. Identities, hashes and UTC never appear.

### Secrets in storage

Links use a public random link ID and a 256-bit secret in the URL fragment. The
database stores the secret's SHA-256 hash for authorization and, so that Copy
works again later, a copy sealed with AES-256-GCM under a key derived from the
installation's throttle secret in the runtime configuration (migrations 0013,
0028 and 0033). A database copy alone reveals no link. A host without the
cipher keeps hashed links that cannot be copied again; the list then shows no
Copy for them. Changing the throttle secret has the same effect on existing
links; they keep working until they expire or are stopped.

### Secret exchange and host logging

The fragment never enters an HTTP request URL. A bounded browser island removes
it from the visible URL and submits the secret through a same-origin native POST.
Without JavaScript, the landing form accepts the part after #.
Successful exchange issues a Secure, HttpOnly, SameSite=Strict __Host- cookie
specific to the link ID. This cookie grants no administration session or CSRF
authority. Keep request bodies, Cookie and Set-Cookie headers excluded from host
and proxy logging, as for CMS login credentials. Also exclude the complete
`/shared-preview-files/`, `/newest-preview-files/` and `/live-previews/` request targets from access logs,
error request lines, request traces and analytics at every host/proxy layer.
These addresses are temporary bearer credentials. PHP diagnostics retain only
exception classes and correlation IDs; neither file tokens nor their hashes
enter audits. Apache virtual hosts can exclude these requests from CustomLog:

```apache
SetEnvIf Request_URI "^/(shared-preview-files|newest-preview-files|live-previews)/" private_preview
CustomLog logs/access.log combined env=!private_preview
```

Apply the same exclusion to every access logger, including a provider's global
logger, and keep request URLs out of error traces. Log policy belongs to the host
configuration; a PHP response or a per-directory `.htaccess` cannot remove a
request already logged upstream. Qualify this before enabling review links.
Share audit metadata has IDs and expiry, never the secret, its hash or the note.

Every viewer request checks the secret hash in constant time, ready status,
expiry and revocation. Browser file requests instead check their separate
short-lived capability and the same parent state. Preview deletion cascades to
its links. No token can select a different preview, a CMS page, an undeclared
file or a write operation. Failed authorization is limited to ten attempts per
remote client address in five minutes across all link IDs, with a five-minute
block and a 429 response. The durable throttle uses a separate keyed namespace
from login; forwarded headers do not replace the observed remote address.

### The viewer

The preview fills the window with nothing above it; the browser tab carries the
private preview title, and visitors change language through the site's own
links, as on the published site. The iframe receives unchanged, checksum-verified artifact
bytes, including deep relative navigation and assets, through
`/shared-preview-files/<link-id>/<file-token>/`. It runs the website's own scripts
under the published site's CSP, with only the frozen Vimeo frame permission when
present. The page matches the published design and interactions, but has a private
address. The opaque-origin sandbox prevents
CMS cookie and storage access, CMS response reads, forms, top-level navigation and
popups; scripts relying on these browser powers cannot use them in a review page.
The engine's enhanced Vimeo fallback has one narrow exception: the shell opens
a validated `https://vimeo.com/ID[/HASH]` link after a current click from its exact
opaque frame. It never accepts a supplied URL, sends a referrer or grants an
opener. Arbitrary profile popups remain blocked.
The shell cannot be framed, and file responses admit framing only by the CMS.
Direct signed-in artifact viewing is unchanged.

### Vimeo playback

Exact and shared viewers announce the engine's Vimeo player support to their
opaque frame. On Play, the shell validates the source window, current user
activation, video identifier/hash, title and finite visible poster rectangle.
It creates only a `player.vimeo.com` sibling iframe, scaled to the page and
clipped within the preview viewport. The poster stays until Vimeo acknowledges
readiness, then focus moves to the player. A five-second wait or error restores
the poster and link. While it plays, the page reports where its poster goes as
it scrolls or resizes and the player follows, clipped to the viewer; a hidden
viewer pauses it and a returning one resumes it. A page navigation or a left
viewer closes it, and the page shows its Play button again rather than the
link, so playing again stays in place.
The provider receives only the CMS origin as referrer. Preview file tokens,
cookies and private paths never reach it. File responses retain their own
no-referrer policy and sandbox; the viewer alone admits the fixed provider host.
Published pages and direct signed-in artifacts retain their ordinary page-side
player when no shell answers. The live workspace keeps its existing player.
The handshake is included in newly built artifacts. Existing frozen previews keep
their captured player bytes; create a fresh preview to use the new shell player.

### File access and renewal

File access stores only SHA-256 file-token hashes, linked to the parent link
or the exact newest-link/preview resolution.
Each viewer gets an independent 256-bit token lasting at most two hours and never
past the link expiry. The shell renews that same address every 15 visible minutes
and when returning to a visible tab, using a same-origin POST with the link's
HttpOnly cookie. Renewal never reloads the frame or loses its current page. An
expired token cannot be revived; reopen the link to obtain a fresh address.
Every file request rechecks the parent link and ready preview, so stopping or
deletion closes issued addresses immediately. No per-file audit is written;
viewing and renewal use the existing secret-free audit. At most 50 unexpired
viewer tokens exist per link; minting removes its expired rows. The cascade
removes every token when its preview is deleted.

All file responses keep no-store, noindex, strict framing and no-referrer, including
Vimeo pages: a private file token must never become a provider referrer. The
shell's sibling Vimeo player uses only the CMS origin; domain restrictions must
allow that origin.
The cookie-protected signed-in artifact endpoint keeps its existing policy. The link
landing uses the visitor's interface language preference.
Creation, successful use, extension and stopping are audited; creation,
extension and stopping roll back if their audit cannot be stored.

Expiry and stopping end subsequent requests, including requests from an
already-open viewer. As with any delivered private document, bytes already
received by a reviewer cannot be recalled.

## The newest preview link and design comparisons

A link that always shows the newest preview resolves, each time it is opened, to
the newest ready **website** preview. Queued or building previews, builds needing
attention and coming soon builds are excluded. A new preview is still created
explicitly. Browser links exchange their fragment secret for a separate Secure,
HttpOnly, SameSite=Strict cookie. Reload the viewer to move to a newer preview;
the iframe stays on its resolved build while browsing its relative pages and
assets. Its files load from `/newest-preview-files/<link-id>/<file-token>/`,
without CMS cookies. Each token is bound to the preview resolved when that viewer
opened. The common two-hour lease renews through the parent cookie without
resolving a newer preview, adding use audits or reloading the frame.

A design comparison tool can use the same link without a browser. Its manifest
address is the link without its fragment plus `artifact/artifact.json`:
`https://cms.example.test/newest-previews/<link-id>/artifact/artifact.json`.
Send `Authorization: Bearer <secret>`, using the 64-character secret after `#` in
the link. Keep the secret in the tool's private local configuration or secret
store, never in a repository, issue, screenshot or committed script. Exclude
Authorization, Cookie, Set-Cookie and exchange form bodies from host, proxy and
application logs. Use HTTPS and forward Authorization to PHP.

The manifest responds with a same-origin 307 redirect to
`/newest-previews/<link-id>/pinned/<preview-id>/artifact/artifact.json`.
Keep the bearer header on same-origin redirects. Resolve every file path against
the **final redirected manifest address**, not the configured newest address;
download those files with the same header and verify their recorded byte sizes
and SHA-256 digests. New previews can arrive during this download without mixing
builds. Fetch the original manifest address again to discover the newest build.
Redirects, manifests and files all retain no-store, noindex and private framing
headers. The secret grants neither administration access nor write authority.

Each request rechecks expiry, revocation and ready website status; constant-time
hash comparison and a ten-failure, five-minute client budget protect bearer
requests as well as cookie requests across all newest-link IDs. Its keyed
namespace is separate from fixed links and CMS login. The first resolution of each
link/preview pair records one use audit, with no per-file use events. These
access capabilities are installation state, not normalized content to hand over
to another site.

Stop a comparison link on the Previews page when the review ends or the secret
is disclosed. Stopping and expiry immediately close resolving and pinned
addresses, including existing browser cookies. Deleting a preview closes its
pins; the newest address falls back to the next ready website preview. If none
exists, it returns the same safe unavailable response as an invalid link.
Previously delivered bytes cannot be recalled.
