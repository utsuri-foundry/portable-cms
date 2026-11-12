# Public site quality

Every published static site must meet a measured bar for accessibility, best
practices, search, performance, HTML validity and robustness. Reference Studio meets
it in the root gate, and private fidelity studies are held to the same bar. The tools
and their lifecycle are recorded in
[ADR 0006](../architecture/decisions/0006-public-site-quality-audit.md).

## The bar

Thresholds live in `tools/quality/public-site/bar.json`, the only place they are
defined.

| Check | Requirement | Scope |
| --- | --- | --- |
| Lighthouse accessibility, best practices, search | 100 | one page per template |
| Lighthouse performance, mobile emulation | 95 or more, median of three runs | one page per template |
| First and largest contentful paint | 1.8 s and 2.5 s or less | one page per template |
| Cumulative layout shift | 0.05 or less | one page per template |
| Total blocking time | 200 ms or less | one page per template |
| Transfer weight | 1.6 MB or less | one page per template |
| Nu HTML validator | no errors | every document |
| Scripts and console | no script errors, console errors or failed requests | every document |
| Reduced motion | no running animation or smooth scrolling when reduced motion is requested | every document |
| Without JavaScript | a main heading and at least 90% of the main text | every document |
| Links | every internal link, resource, fragment, canonical and alternate resolves | every document |

Templates come from the artifact's own route identities: each `page:` translation
group is one template, and record pages share a template with the other records under
the same index page. The first locale's page stands for the template.

A page that deliberately asks not to be indexed, such as a page an editor excluded
from search, is exempt from Lighthouse's crawlability audit only. Every other search
audit still applies.

Thresholds are never lowered to make a change pass. A shortfall is fixed in the
engine or the profile, or recorded as a task with impact and acceptance.

## Running it

```text
nix develop -c just check-public-site
nix develop -c just audit-public-site <artifact>
nix develop -c node tools/audit-public-site.mjs <artifact> --report=report.json
```

`check-public-site` is the gate lane. It builds Reference Studio's fixture snapshot
with `tools/development/build-profile-site.php`, audits it, and proves the audit
rejects a deliberately degraded page. The audit takes any compiled artifact or exact
preview directory, serves a copy through the pinned Apache with the artifact's own
delivery rules, and never modifies the input. Lighthouse samples and templates
run sequentially on one browser so they do not compete for host resources.
`--concurrency=1` remains accepted; parallel overrides are rejected before any
artifact or browser work. Scale measurements across independent hosts if needed. Use `--template=<id>` to audit selected
templates and `--runs=1` for a quick local check; complete qualification retains the configured repeated
samples and every threshold in the bar. See [ADR 0012](../architecture/decisions/0012-isolated-lighthouse-measurements.md).

`build-profile-site.php` compiles any verified profile package's frozen snapshot with
the ordinary compiler:

```text
php tools/development/build-profile-site.php --output=<new directory> \
  [--profile-root=<profile>] [--snapshot=<snapshot.json>] [--media=<media root>]
```

## Reading failures

Each failure names the template or page and the failing Lighthouse audits, metric,
validator message or behaviour. Open the page from a local artifact with
`php -S 127.0.0.1:4173 -t <artifact>` or run Lighthouse directly on it for the full
report. Lab performance is simulated; if a metric is unstable, investigate the page
rather than loosening the bar.
