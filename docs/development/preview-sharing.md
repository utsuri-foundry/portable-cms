# Share a private website preview

Give reviewers a link to a frozen build without granting CMS access. Choose a
fixed link to review one version, or a newest-preview link for an ongoing review.
Only accounts with publication authority can create or revoke these links.

Treat each link as a secret capability. Anyone holding it may read the permitted
preview until it expires or is revoked. Previously downloaded bytes cannot be
recalled. See [exact previews](exact-website-preview.md) for building and inspecting
the artifact before sharing it.

## Expiring review links

An administrator with the current publication authority can open **Share preview**
from a ready preview. Native forms create links with a 1-hour, 1-day or 7-day
lifetime, list the active links and revoke any of them. The raw secret appears
only on the creation response; copy the complete link then. At most 50 links
can be active for one preview. Queued, failed or deleted previews cannot be shared.
Links to previews including saved drafts retain the same review-only meaning.

### Secret exchange and host logging

Links use a public random share ID and a 256-bit secret in the URL fragment.
The fragment never enters an HTTP request URL. A bounded browser island removes
it from the visible URL and submits the secret through a same-origin native POST.
Without JavaScript, the landing form accepts the secret copied after #.
Successful exchange issues a Secure, HttpOnly, SameSite=Strict __Host- cookie
specific to the share ID. This cookie grants no administration session or CSRF
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
Share audit metadata has IDs and expiry, never the secret or its hash.

Migration 0013 stores only SHA-256 secret hashes and the preview foreign key.
Every subsequent viewer request checks the share secret hash in constant time,
ready status, expiry and revocation. Browser file requests instead check their
separate short-lived capability and the same parent state. Preview deletion
cascades to its links.
No token can select a different preview, a CMS page, an undeclared file or a write
operation. Failed authorization is limited to ten attempts per remote client
address in five minutes across all share IDs, with a five-minute block and a
429 response. The durable throttle uses a separate keyed namespace from login;
forwarded headers do not replace the observed remote address.

### Viewer permissions

A small dismissible notice lives in a separate review shell outside the page.
The iframe receives unchanged, checksum-verified artifact bytes, including deep
relative navigation and assets, through
`/shared-preview-files/<share-id>/<file-token>/`. It runs the website's own scripts
under the published site's CSP, with only the frozen Vimeo frame permission when
present. The page matches the published design and interactions, but has a private
address and the surrounding preview notice. The opaque-origin sandbox prevents
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
the poster and link. Child/outer scrolling, resizing, page navigation, hiding or
leaving the viewer closes the overlay rather than leaving it over another place.
The provider receives only the CMS origin as referrer. Preview file tokens,
cookies and private paths never reach it. File responses retain their own
no-referrer policy and sandbox; the viewer alone admits the fixed provider host.
Published pages and direct signed-in artifacts retain their ordinary page-side
player when no shell answers. The live workspace keeps its existing player.
The handshake is included in newly built artifacts. Existing frozen previews keep
their captured player bytes; create a fresh preview to use the new shell player.

### File access and renewal

File access stores only SHA-256 file-token hashes, linked to the parent share
or the exact newest-link/preview resolution.
Each viewer gets an independent 256-bit token lasting at most two hours and never
past the share expiry. The shell renews that same address every 15 visible minutes
and when returning to a visible tab, using a same-origin POST with the share's
HttpOnly cookie. Renewal never reloads the frame or loses its current page. An
expired token cannot be revived; reopen the share to obtain a fresh address.
Every file request rechecks the parent share and ready preview, so revocation or
deletion closes issued addresses immediately. No per-file audit is written;
viewing and renewal use the existing secret-free share audit. At most 50 unexpired
viewer tokens exist per share or newest link; minting removes its expired rows. The cascade
removes every token when its preview is deleted.

All file responses keep no-store, noindex, strict framing and no-referrer, including
Vimeo pages: a private file token must never become a provider referrer. The
shell's sibling Vimeo player uses only the CMS origin; domain restrictions must
allow that origin.
The cookie-protected legacy artifact endpoint keeps its existing policy. The link
landing and notice use the visitor's interface language preference.
Creation, successful use and revocation are audited;
creation and revocation roll back if their audit cannot be stored.

Expiry and revocation stop subsequent requests, including requests from an
already-open viewer. As with any delivered private document, bytes already
received by a reviewer cannot be recalled.

## One link for the newest preview and design comparisons

In **Previews**, an administrator with publication rights can create a **Newest
preview link** for one hour, one day, seven days or thirty days. The list shows
active links and their expiry, with a native Revoke action. Copy the browser link
and comparison manifest address from the creation response: the secret is shown
only once. This capability follows every new ready **website** preview, including
drafts and languages in preparation. Holding builds, queued/building previews and
builds needing attention are excluded. A new preview is still created explicitly.

Browser links exchange their fragment secret for a separate Secure, HttpOnly,
SameSite=Strict cookie. Reload the viewer to resolve the newest ready preview;
the iframe stays on its resolved build while browsing its relative pages/assets.
The page uses the same opaque-origin sandbox, public-site script policy and
private notice as fixed shares. Its files load from
`/newest-preview-files/<link-id>/<file-token>/`, without CMS cookies. Each token is
bound to the preview resolved when that viewer opened. The common two-hour lease
renews through the parent cookie without resolving a newer preview, adding use
audits or reloading the frame. Reload the viewer to mint a token for the newest
build. Expiry, revocation and preview deletion close issued browser addresses;
bearer manifest and pinned-download routes retain their existing behavior.

For a design comparison tool, use the manifest address shown on creation:
`https://cms.example.test/newest-previews/<link-id>/artifact/artifact.json`.
Send `Authorization: Bearer <secret>`, using the 64-character secret after `#` in
the browser link. Keep the secret in the tool's private local configuration or
secret store, never in a repository, issue, screenshot or committed script.
Exclude Authorization, Cookie, Set-Cookie and exchange form bodies from host,
proxy and application logs. Use HTTPS and forward Authorization to PHP.

The manifest responds with a same-origin 307 redirect to
`/newest-previews/<link-id>/pinned/<preview-id>/artifact/artifact.json`.
Keep the bearer header on same-origin redirects. Resolve every file path against
the **final redirected manifest address**, not the configured newest address;
download those files with the same header and verify their recorded byte sizes
and SHA-256 digests. New previews can arrive during this download without mixing
builds. Fetch the original manifest address again to discover the newest build.
Redirects, manifests and files all retain no-store, noindex and private framing
headers. The secret grants neither administration access nor write authority.

Migration 0028 stores only hashed secrets and link/preview resolution identities.
Each request rechecks expiry, revocation and ready website status; constant-time
hash comparison and a ten-failure, five-minute client budget protect bearer
requests as well as cookie requests across all newest-link IDs. Its keyed
namespace is separate from fixed links and CMS login. The first resolution of each link/preview
pair records one use audit, with no per-file use events or stored raw secret.
Creation and revocation are audited transactionally. At most fifty newest links
can be active. These access capabilities are installation state, not normalized
content to hand over to another site.

Revoke a comparison link on the Previews list when the review ends or the secret
is disclosed. Revocation and expiry immediately close resolving and pinned
addresses, including existing browser cookies. Deleting a preview closes its
pins; the newest address falls back to the next ready website preview. If none
exists, it returns the same safe unavailable response as an invalid link.
Previously delivered bytes cannot be recalled. Fixed share links still select
one frozen preview and retain their existing seven-day maximum.
