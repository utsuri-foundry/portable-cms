# Testing

Use the cheapest layer that proves the contract:

- pure tests for schemas, domain transitions, normalization, path policy, escaping, and reducers;
- repository and adapter integration tests for MariaDB, filesystem, ImageMagick, HTTP, and provider boundaries;
- golden fixtures for portable records, exports, snapshots, artifacts, search postings, manifests, and generated CSS;
- black-box journeys for login, editing, upload/resume, background work, preview, publish, rollback, export, restore, update, and recovery;
- real-host acceptance for behavior a local environment cannot qualify.

Tests are hermetic, bounded, and reproducible. Inject time, IDs, randomness, providers, failures, and process completion. Replace timing sleeps with observable conditions and deadlines. Host qualification is evidence, not a substitute for local regression coverage.

Every PR requires the shared foundation (licences, contracts, architecture,
quality, formatting, static analysis, all unit/service tests, templates and
installable bundle), extraction checks and a full-history secret scan:

```text
nix develop -c tools/check-fast.sh
```

The protected `Required PR checks` aggregate also requires all affected jobs.
`tools/ci/plan.mjs BASE_SHA HEAD_SHA` selects them from every changed path,
including deleted files and both sides of renames. Prose needs only fast checks.
Unknown code gets native database/form/browser proof; matching paths add:

| Change boundary | Additional proof |
| --- | --- |
| CI, Nix, root check scripts | Browser startup and degraded audit rejection |
| Records, views, forms, editor | Automatic keeping and single-line rich fields |
| Preview, snapshots, jobs | Exact preview database, FastCGI and browser |
| Media, uploads, images, videos | Responsive images, video limits, Vimeo |
| Export, restore, import, backup | Restore, import and portable transfer |
| Database, migrations, schemas | Upgrades and growth |
| Publication, releases, schedules, feed | Release database, schedules and published heads |
| Blocks and composition | Block and composed-page browsers |
| Search, sharing, metadata, SEO | Native search/sharing browser journey |
| Compiler, profiles, public site, schemas, design | All HTML plus three Lighthouse templates |

Rules accumulate. Reviewers verify semantic impact and request additional lanes
with `nix develop -c sh tools/ci/run-check.sh LANE`, or full qualification,
when the path mapping is insufficient. Focused browser harnesses keep their own
fresh database. A skipped selected job, failed planner or cancelled job fails
the aggregate. A docs-only empty plan is the sole accepted matrix skip.

Changed browser journeys select their registered `browser-NAME` case on a fresh
fixture. Shared browser helper changes require a bounded editorial check covering
streamed forms, editing, controls and field uploads, plus native startup/database/
workflow proof. PR jobs run on up to eight independent runners. The complete
shared editorial chain is a nightly/release/manual check, not a normal PR job.
This keeps ordinary review feedback near the 3–5 minute target while retaining
all cross-journey state checks in full qualification. Broad changes may take
longer; no selected test is skipped to meet that target.

Full qualification runs nightly at 02:23 UTC, on `v*` tags and manually. There
is no duplicate run on ordinary main pushes. Before release or source publication,
verify a successful full run for the exact commit to ship; fast checks are not
that evidence. Nightly failure must be resolved before shipping. The workflow
does not publish a release or deploy. See [ADR 0011](../architecture/decisions/0011-ci-cadence.md).

The hosted full gate runs independent lanes on separate runners (at most four
at once). `tools/ci/qualification.json` is the command inventory shared with the
local gate; no check is dropped. Public-site measurements retain a dedicated
runner and sequential samples. The complete aggregate requires all lanes and
the history secret scan to succeed. Lane timing appears in each job log; elapsed
time still depends on the slowest work, runner availability and setup.

The authoritative complete command is:

```text
nix develop -c tools/check-repository.sh
```

It checks licences, architecture and quality policies, canonical formatting, JavaScript diagnostics, PHP static analysis, native browser code, PHP templates and services, the installable bundle, extraction boundaries, and the full ephemeral-MariaDB editorial journey. Required lanes must not be skipped or converted into advisory output. Add classified lanes as the product grows, but preserve this single root command.

The Ubuntu GitHub-hosted workflow installs the locked Chromium sandbox helper
from `nix build .#ci-chromium-sandbox` under `/usr/local/sbin`, with a root-owned
link at the wrapper path the Nix browser already checks. It verifies root
ownership and mode 4755, then runs
a bounded headless startup check before the complete gate. The job removes its
helper afterward. This qualifies the disposable CI host without modifying the
Nix store or disabling browser sandboxing. Installed CMS hosts need none of this.
See [Chromium's Linux sandbox setup](https://chromium.googlesource.com/chromium/src/+/main/docs/linux/suid_sandbox_development.md)
and [its Ubuntu sandbox guidance](https://chromium.googlesource.com/chromium/src/+/main/docs/security/apparmor-userns-restrictions.md).

For hosted-runner diagnosis, the Repository workflow's manual check selector
can run `fast`, `public-site-full`, `degraded-audit` or `editor-start` separately.
`public-site-full` executes the complete public-site lane with every template,
configured repeated samples, language variant and negative fixture. Lighthouse
measurements run sequentially on each host; separate jobs have separate runners. These report as
focused checks and do not satisfy the required PR aggregate or full qualification. The degraded fixture can also be run with
`nix develop -c sh tests/public-site/run-degraded-site-check.sh`; its layout shift
waits for observed contentful paint, separately from its script-only text.
