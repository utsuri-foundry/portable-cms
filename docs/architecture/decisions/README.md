# Architecture decisions

Use sequential four-digit identifiers. A decision record contains:

- status and date;
- context and forces;
- decision;
- consequences and risks;
- rejected alternatives;
- evidence and follow-up gates.

Accepted records are historical. Supersede them with a new record and a dated link rather than rewriting the original decision.

An accepted decision records a direction or constraint at its stated date; it
does not establish that every feature it discusses is available. Use the
[current product and operating guides](../../README.md) for supported behaviour.

Accepted decisions:

- [0001: Product-first reference profile](0001-product-first-reference-profile.md)
- [0002: PHP JSON Schema validator](0002-php-json-schema-validator.md)
- [0003: Repository architecture gate](0003-repository-architecture-gate.md)
- [0004: Development quality toolchain](0004-development-quality-toolchain.md)

- [0005: Optional verified webfont processing](0005-optional-verified-webfont-processing.md)
- [0006: Public site quality audit](0006-public-site-quality-audit.md)
- [0007: Portable publication capabilities](0007-portable-publication-capabilities.md)
- [0008: Integration surface](0008-integration-surface.md)
- [0009: Site languages chosen in the CMS](0009-site-languages.md)

- [0010: Apache 2.0 publication](0010-apache-2-publication.md)

- [0011: CI cadence](0011-ci-cadence.md)

- [0012: Isolated Lighthouse measurements](0012-isolated-lighthouse-measurements.md)
