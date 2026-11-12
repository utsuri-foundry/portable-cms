# 0006: Public site quality audit

Status: accepted, 2026-10-04

Measurement concurrency superseded on 2026-11-11 by
[ADR 0012](0012-isolated-lighthouse-measurements.md); tools and thresholds remain.

## Context

Portable CMS promises a verified static public website. Byte hashes, schemas and
delivery tests prove that the artifact is the approved one; they do not prove that
it is accessible, valid, fast, discoverable or robust without scripts. Reference
Studio and the private fidelity studies are reference implementations, so their
published output needs a measured, repeatable bar that fails the gate when a change
regresses it. The measurement must use what visitors receive and must not add Node.js
packages, a results service or anything to an installed host or public artifact.

## Decision

Adopt two development-only tools through the repository's locked Nix shell and audit
compiled artifacts with `tools/audit-public-site.mjs`:

| Tool | Locked version | Licence | Responsibility |
| --- | --- | --- | --- |
| Google Lighthouse command-line tool (`google-lighthouse`) | 13.5.0 | Apache-2.0 | Accessibility, best practices, search and lab performance per public template |
| Nu HTML Checker (`validator-nu`) | 25.12.12 | MIT | Offline HTML conformance for every published document |

The nixpkgs attribute `lighthouse` is an unrelated Ethereum client and must not be
used. Both tools come from nixpkgs revision `c59305bab2065cfecc4944690d9eedbb56f3a9fa`
locked by `flake.lock`. Lighthouse attaches to the already pinned Chromium through the
DevTools debugging port. The audit's own code is native Node modules with no npm graph.

The audit serves a copy of the artifact through the pinned Apache with the artifact's
own approved delivery rules, then checks:

- Lighthouse accessibility, best practices and search at 100, and performance at 95 or
  more as the median of three mobile-emulated runs, for one page per public template;
- hard limits on first and largest contentful paint, layout shift, blocking time and
  transfer weight, from the same median;
- Nu validator errors for every published HTML document;
- script errors, console errors and failed requests, running motion when reduced motion
  is requested, dangling fragment links and content that exists only with JavaScript,
  for every document;
- every internal link and resource, including canonical and alternate links written for
  the production base URL.

Thresholds live in `tools/quality/public-site/bar.json`. A page that deliberately asks
not to be indexed is exempt from Lighthouse's crawlability audit only.

Lighthouse CI was rejected: it is not packaged in nixpkgs, would add an npm dependency
graph, and its main additions (a results server and history) are not needed. Its
threshold assertions are a short script around the packaged tool.

## Locked graph and measured cost

On x86_64 Linux on 2026-10-04:

| Tool | Store requisites | Closure bytes |
| --- | ---: | ---: |
| google-lighthouse | 378 | 2,089,255,344 |
| validator-nu | 13 | 681,557,200 |

Lighthouse's closure includes its own Node runtime and a Chromium build, and the
validator's includes a Java runtime; they overlap with inputs already present. They
affect developer and CI environments only. The full Reference Studio lane takes about
four minutes with three parallel browsers.

## Update and advisory policy

Updates are deliberate `flake.lock` changes reviewed with release notes, scoring and
audit changes, closure size and a complete gate. A Lighthouse update that changes
scores is reviewed as a bar change: fix the product, never relax `bar.json` to absorb
it. Tool advisories are followed through upstream projects and nixpkgs security
maintenance; the tools only read local loopback pages produced by the repository.

## Removal strategy

Either tool can be replaced behind `tools/audit-public-site.mjs` with equivalent
categories and thresholds, proven by the degraded-page test. Removal requires an ADR
and may not silently weaken the lane.

## Consequences

- Published output now has an executable quality bar in the root gate.
- Private fidelity studies run the same audit on their exact previews.
- Lab performance is simulated and runs in parallel; wide margins in the bar keep it
  stable, and a flaky metric is investigated rather than loosened.
- The bar measures one representative page per template. Content-specific regressions
  on other pages are covered by validation, behaviour and link checks, not Lighthouse.
