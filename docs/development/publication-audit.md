# Source review scope

The source import uses fresh history, as recorded in
[ADR 0010](../architecture/decisions/0010-apache-2-publication.md).
Original development records and sensitive audit evidence are not distributed.
This document describes review boundaries, not a current CI result or a
certificate for later commits.

Review covers tracked text and paths, reachable Git objects and metadata,
credentials, client and account identities, private operational material,
licences and binary provenance. Public standards and upstream attribution,
reserved fixture addresses and fictional Reference Studio content are retained.
No client publication permission is assumed. Identity rules, matched private
values and source-to-import mappings stay outside the distributed source.

Gitleaks scans source history without a fixture baseline or inline suppression.
Identity and rights review remains necessary alongside scanner results.
The repository gate checks the exact locked runtime graph and every admitted
media asset against its reviewed licence and provenance inventory. Imported
fonts retain OFL terms; upstream runtime licences and notices stay in bundles.
See [THIRD_PARTY.md](../../THIRD_PARTY.md).

Public documentation must describe the public contributor workflow. The gate
rejects internal coordination references and links to undistributed records.
Public architecture and quality exception registries stay empty; contributors
resolve policy failures rather than depend on unavailable removal records.

`PUBLICATION.json` identifies the initial imported bytes and modes. Subsequent
changes need their own review and exact-commit CI evidence. See
[source verification](publication.md), [testing](testing.md) and
[security reporting](../../SECURITY.md). Detailed operational inventories and
cutover status are not maintained as public documentation.
