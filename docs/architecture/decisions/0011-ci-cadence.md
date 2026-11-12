# 0011: Required fast PR checks and separate full qualification

Status: accepted, 2026-11-11. Supersedes the per-review complete-gate cadence in
the development guide; the complete qualification command remains authoritative.

The owner approved a faster routine review cadence after hosted qualification
spent more than 17 minutes before reaching the browser matrix. Running that
matrix again after every main merge duplicates expensive qualification.

Every PR runs the entire shared static, unit, contract, bundle, extraction and
secret-scan foundation. Changed paths select additional bounded database,
browser and public-site jobs. `Required PR checks` is the protected aggregate:
failure, cancellation, planning failure or a skipped selected job blocks it.
Only an empty plan allows the focused matrix to be skipped. Unknown executable
changes receive native database and browser proof. Renames include both paths.
Reviewers must check that the selected lanes cover the change and request
additional focused or full qualification when cross-area impact warrants it.

The unchanged complete gate runs nightly at 02:23 UTC, on `v*` tags, and manually
before releases or source publication. Ordinary main pushes do not duplicate it.
Release/publication operators must verify a successful full run for the exact
commit they intend to ship. A green fast PR is not full qualification. Nightly
failure must be investigated before shipping; it must not become accepted debt.
Tag runs provide evidence, not an automatic release or deployment.

The short PR target is approximately 3–5 minutes including runner setup, with
additional time for changes spanning multiple boundaries. It is a target, not a
timeout that drops coverage. There is no CI performance change to the installed
CMS, no new runtime dependency, and no use of credentials for untrusted PR code.

On 2026-11-12 the owner reaffirmed that merge feedback must be much shorter.
The later automatic selection of the entire editorial chain for browser helper
changes had violated this boundary: it made those PRs wait 18–22 minutes.
PRs now select a bounded editing/controls/upload check for shared browser code,
and the actual isolated case for a changed journey. Up to eight selected jobs
run concurrently on separate runners. Full qualification retains the original
shared-state chain and every other command. It can expose cross-journey problems
that the isolated PR checks do not; those failures still block shipping.

Retaining full serial checks on every PR and every main push was rejected for
routine latency. Only parallelizing that whole suite retains unnecessary work
for prose and small changes. Running unit tests alone was rejected because the
product's database, native forms and static artifact are integration boundaries.
The classifier is conservative but cannot infer every semantic dependency;
review, focused commands and independent full qualification address that risk.

Evidence: routing tests cover mixed/high-risk changes, unsafe inputs, unknown
code and renamed/deleted paths. Hosted runs qualify the aggregate, selected
lanes and sandbox setup. See [testing policy](../../development/testing.md) and
[CI tooling](../../../tools/ci/README.md).
