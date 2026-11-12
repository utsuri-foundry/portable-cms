# 0005: Optional verified webfont processing

Status: accepted, 2026-10-03

## Context

Website font uploads need an actual OpenType sanitizer. A WOFF2 signature,
filename, MIME claim or browser decode is insufficient evidence. Conventional
hosts may forbid external programs; those installations still need usable
revisioned design settings and packaged fonts.

## Decision

Keep the PHP registry domain provider independent. `FontSanitizer` accepts
bounded WOFF2 bytes and returns newly serialized, verified WOFF2 bytes.
`FontStore` retains only checksum-addressed sanitized bytes in private storage.

The optional native adapter runs OpenType Sanitizer, then Google's WOFF2
encoder. OTS validates and serializes the font into SFNT; re-encoding drops
WOFF2 extended metadata and private blocks. Registry metadata is separately
bounded plain text; source filenames, paths and embedded names never become
CSS family names. Uploaders record source/version and licensing permission,
including permission to sanitize the font. This records their assertion; it
does not infer legal permission from a font's technical embedding bits.

| Component | Locked development version | License | Boundary |
| --- | --- | --- | --- |
| OpenType Sanitizer | 9.3.0 | BSD-3-Clause | validate and sanitize SFNT tables |
| Google WOFF2 | 1.0.2 | MIT | encode sanitized SFNT as WOFF2 |
| Brotli | Nix-locked transitive input | MIT | WOFF2 compression |

`flake.lock` selects the complete Nix graph. These executables are development
and optional host capabilities; the installable bundle does not vendor them or
require Node, Composer, root, a process daemon or outbound network access.
Operators supply absolute executable paths in private configuration after
qualifying their isolated account. Missing executables or forbidden process
execution visibly disable uploads. Packaged fonts and all other registries
continue to work.

Commands receive argument arrays, a clean environment, fixed generated private
filenames, discarded diagnostics and a ten-second timeout per stage. An installation lock permits one font processor at a time. Input is
limited to 2 MiB, at most 128 tables and an 8 MiB claimed SFNT size; output is
also bounded. Host qualification must enforce process memory and concurrency
limits through the account's process isolation or a reviewed wrapper. The
sanitizer remains native code processing hostile bytes, so technical header
bounds alone do not qualify that isolation.

## Alternatives and consequences

Signature-only acceptance and preserving original bytes were rejected. A new
PHP font parser would introduce a large security-sensitive dependency and is
not justified. Remote processing can implement the same interface later, but
no provider is mandatory. Hosts without qualified processing cannot upload
new fonts; the control states that limitation before submission.

## Update, advisories and removal

Review upstream [OTS](https://github.com/khaledhosny/ots) and
[WOFF2](https://github.com/google/woff2) releases, security notices, licenses,
Nix transitive inputs and malformed-font regression results before a lock
update. Hosts independently pin and maintain their qualified versions. An
unpatched relevant vulnerability disables that processor until it is upgraded
or isolated with accepted evidence. Composer audit covers the separate PHP
graph; it does not cover native host programs.

Removal replaces `FontSanitizer` behind equivalent hostile-byte tests or
explicitly removes upload capability. Historical sanitized font objects,
registry revisions, normalized exports and frozen artifacts remain readable.

## Evidence

The gate sanitizes a real licensed reference WOFF2, removes an attached private
block, rejects executable/truncated/corrupt/oversized bytes, checks private
storage and immutable compiler copies, and proves unavailable-capability
behavior. Conventional-host isolation and resource qualification remain an
operator responsibility, as with the existing raster processor.
