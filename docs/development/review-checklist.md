# Implementation review checklist

Use this checklist for product PHP, browser islands, adapters, and operator tooling. A checked box means the reviewer located evidence, not merely that the author stated an intention.

## Cohesion and boundaries

- [ ] The change has one named owner and follows `architecture.json` dependency direction.
- [ ] Domain decisions remain independent of HTTP, PDO, filesystem, image, provider, DOM, clock, randomness, and process APIs.
- [ ] New arrays have validated inputs and documented value types or shapes.
- [ ] Effects, resource ownership, cleanup, cancellation, and disposal are visible.
- [ ] No generic module, service locator, global mutable state, or hidden cross-feature call was introduced.

## Correctness and failure

- [ ] Expected failures are typed and projected to stable safe errors.
- [ ] Request identity or generation rejects stale asynchronous results.
- [ ] Database writes use prepared statements, explicit transaction ownership, and checked compare-and-set results.
- [ ] Retryable effects are idempotent and carry a deduplication identity.
- [ ] Partial failure, interruption, timeout, and cleanup paths have focused tests.

## Security, accessibility, and performance

- [ ] Every output uses the correct text, attribute, URL, JSON, or trusted-fragment context.
- [ ] Secrets, private paths, provider bodies, personal data, and raw exception messages are absent from responses and diagnostics.
- [ ] Browser behavior works without JavaScript for its authoritative action and preserves keyboard, focus, reduced-motion, and assistive-technology behavior.
- [ ] Collection, byte, geometry, nesting, concurrency, duration, and retention limits are explicit at hostile boundaries.
- [ ] Hot paths avoid avoidable allocation and have measurement evidence before specialized optimization.

## Tooling and proof

- [ ] Fast checks and every affected integration lane pass; the PR selection covers the semantic impact, with additional manual lanes when needed.
- [ ] Release/publication has complete qualification for the exact commit, beyond the short PR aggregate.
- [ ] No baseline, ignore directive, unsafe rendering API, floating promise, or new PHP error-control operator was added.
- [ ] A dependency change has an accepted ADR with lock graph, licence, advisory path, cost, update policy, and removal strategy.
- [ ] Architecture and quality exception registries remain empty; policy failures are resolved in code.
- [ ] Documentation and fixed examples still describe the behavior that the executable gate enforces.
