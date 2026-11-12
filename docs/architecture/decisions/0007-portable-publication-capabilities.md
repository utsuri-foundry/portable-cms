# 0007: Portable publication capabilities

Status: accepted, 2026-10-06

Decision owner: Utsuri Foundry, SL.

## Context

Portable CMS must run on qualified inexpensive conventional hosting without
Node.js, a VPS, root access, an interactive shell or a persistent service.
Content exports and verified static artifacts must remain independent of their
source provider. Publishing several files in place cannot give visitors a
whole-site atomic switch, even when each file replacement is atomic and the
database active pointer changes only after verification.

Coming soon makes that distinction visible: replacing the holding page and its
route set with a real website is a publication, not a request-time CMS flag.
Requiring atomic delivery for that feature would unnecessarily narrow the
product's hosting baseline.

## Decision

Keep three distinct portability boundaries: normalized content, verified static
artifacts and replaceable publication adapters. An artifact does not require
the same activation mechanism on every destination. Provider roots, routing
configuration and activation authority are installation facts, not canonical
content or imported authority.

| Mode | Required guarantee | Availability |
| --- | --- | --- |
| Standard | Verified source, bounded journaled application, complete destination verification, audited activation and recovery from interruption | Portable publication baseline on qualified targets |
| Atomic | Standard integrity guarantees plus a complete staged release and one qualified origin delivery switch, leaving the old release selected until then | Optional adapter capability |

The existing Apache adapter implements Standard only. Atomic is an accepted
direction, not an implemented capability. Standard does not mean FTP alone
qualifies a host: the runtime, storage, security and target requirements still
apply. An otherwise baseline-compatible host must not be rejected because it
cannot provide atomic activation. No provider-specific routing mechanism becomes
an engine or profile requirement.

Future installer qualification must record the supported delivery mode and
remediation from measured behavior, not a provider name or advertised feature.
Editors must see the selected guarantee before publication. An explicitly
configured Atomic target must fail closed if its capability is lost; it must
not silently retry as Standard. Changing mode is an explicit operator action
with compatibility and recovery checks, not a mutation of an existing journal.

Coming soon uses the selected verified adapter. Its noindex, single-page and
no-sitemap guarantees describe the completed holding release. Standard apply
and recovery can temporarily expose a mixture of approved releases, including
old live routes during a return to holding or new live routes during launch.
The CMS must disclose that window. Private previews, original media, working
files and unapproved releases remain private regardless of mode. Noindex or an
unguessable directory name is not an access-control boundary.

## Atomic adapter boundary

Stage a complete verified release without changing selected public delivery;
protect staged and retained HTML against direct public access; then switch one
delivery selector through a tested atomic operation. Keep recovery evidence
durable across filesystem and database boundaries: a selector switch and a
database transaction are not one transaction. Interrupted activation must be
reconciled from the checked selector and receipt before another approval.

Use content-addressed asset URLs and bounded retention so a page loaded before
activation can still fetch its exact assets afterward. Account for caches and
in-flight requests. Atomic means an origin activation boundary, not simultaneous
visibility in all browsers or CDN caches, nor pinning an entire visitor session
to one release.

Generated Apache per-directory routing is a candidate, not a universal
requirement or proven implementation. It needs effective rewrite permissions,
safe path mapping, storage headroom and a tested same-filesystem selector
replacement. Other servers or static hosts may implement a different selector.
If these capabilities are absent, Standard remains available when qualified;
no public PHP dispatcher or mandatory external service is introduced.

## Consequences and alternatives

Both modes share explicit approval, immutable sources, manifests, audit,
history, retention and verified rollback, but their delivery guarantees differ.
Standard rollback reapplies bytes in bounded batches; Atomic rollback selects a
retained verified release. Neither may bypass verification. Atomic increases
storage and inode requirements and adds target-specific qualification work.

Mandatory atomic delivery was rejected because it would shrink compatibility.
Calling in-place writes atomic because the database pointer changes at the end
was rejected because it misrepresents what visitors receive. Request-time PHP
routing was rejected because public availability must remain independent of
the CMS runtime and database.

## Evidence and follow-up gates

The [current publication workflow](../../development/publication-workflow.md)
documents and tests Standard, including real-host interruption and recovery.
The [Apache documentation](https://httpd.apache.org/docs/2.4/mod/mod_rewrite.html)
defines rewrite and override prerequisites; it is design evidence, not proof
that a particular installation supports the candidate Atomic adapter.

Optional atomic static delivery
owns implementation, capability projection and measured qualification. It must
test concurrent requests, interrupted staging and activation, direct-path
denial, retention, rollback and existing-target migration. Atomic is not a
prerequisite of the holding-page task. Broad hosting compatibility claims need
dated evidence from multiple conventional stacks; absence of Atomic must remain
a supported Standard outcome without relaxing security requirements.
