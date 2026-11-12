# 0001: Product-first reference profile

Status: accepted, 2026-10-02

Publication note, 2026-11-11: private identities are anonymized; technical decisions
are preserved. Publication policy is superseded by [ADR 0010](0010-apache-2-publication.md).

## Context

The first prototype and Gate 0 implementation lived inside a client website repository. Continuing there would let one client's information architecture, design, routes, and delivery pressure shape the reusable CMS kernel. Beginning with the second client would create the same problem with a broader but still client-specific model.

An abstract engine without a real website would avoid client coupling but fail to expose editor workflow, responsive design, compilation, accessibility, search, release, and operations problems.

## Decision

Build Portable CMS as an independent product. Its first complete consumer is a fictional `reference-studio` profile and static website in this repository. The reference uses synthetic deterministic content and exercises the full bounded product without copying client identity, content, assets, or source code.

The first client migration follows product acceptance. A second client supplies the broader profile proof. Both client profiles, data, media, credentials, and deployment configuration remain in their client repositories and consume pinned product releases.

An existing well-designed website may be selected as a visual and interaction benchmark. The implementation may reproduce measurable layout and behavior patterns for internal acceptance, but it must use original code and synthetic or licensed content. It must not publish another site's identity, copy protected assets, or present a literal clone as product work.

## Consequences

- The product repository and release lifecycle are established before client breadth.
- Reference Studio must be concrete and visually rigorous without becoming a third bespoke client project.
- Client requests become explicit product changes or profile behavior, never silent engine forks.
- Product delivery takes longer before the first client migration, but lifecycle and portability work cannot be deferred behind that migration.
- Selection of the benchmark site requires a recorded research task covering copyright, assets, behavior, accessibility, and representative CMS capabilities.

## Rejected alternatives

- Build the first client first and generalize later: too likely to fossilize its bespoke model.
- Build the second client first: broader, but still a client-specific product definition.
- Build only abstract contracts: insufficient to prove a high-quality editor and generated website.
- Publish a literal clone of an existing site: unnecessary legal, ethical, and positioning risk.

## Evidence and follow-up

Gate 0 proved the PHP/MariaDB/shared-host foundation. Subsequent work establishes product contracts, a reference design benchmark and the fictional profile foundation.

