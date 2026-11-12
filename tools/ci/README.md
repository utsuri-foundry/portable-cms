# Continuous integration

Owns PR change classification, fixed focused-check commands and disposable
GitHub runner setup. It depends on existing repository checks and test harnesses;
the installed product never depends on CI tooling.

`check-fast.sh` runs the shared foundation, extraction policy and all-ref secret
scan. `plan.mjs BASE_SHA HEAD_SHA` emits only a JSON array of fixed lane names.
It compares complete commits, includes deletions and both sides of renames,
rejects unsafe paths and applies every matching rule. Documentation gets fast
checks; unknown executable changes also get the native database/browser journey.
High-risk paths add database, portability, media, preview, publication, blocks, search/sharing or
public-site checks. CI and pinned-tool changes prove browser startup and audit
rejection. The public-site PR lane audits all HTML and three Lighthouse templates (home, project and people);
full qualification retains every template, language and negative fixture.

PRs never automatically select the long `editorial` chain. Shared browser helper
changes select `browser-editorial-smoke`: streamed-response/file-input regression,
record editing, media controls and field uploads, on one fresh fixture. Native
startup/database/workflow proof remains selected alongside it. A changed journey
selects its own `browser-NAME` case, including the special profile setup for Vimeo,
video limits and single-line rich fields. This avoids running unrelated database
and browser features merely because a test filename contains their keywords.
`browser-cases.json` is the allowlisted inventory; `browser-cases.mjs` launches
the existing fixture harness and rejects unknown names. The inventory test checks
every standalone journey in both full harnesses. PR cases run on up to eight
independent runners; selected failures still block the aggregate.

`editorial` remains the complete shared-state chain for manual, nightly and
release qualification. Its cross-journey state checks are deliberately separate
from fast PR feedback. Complete qualification retains every original command,
including journeys also represented by bounded PR cases.

`qualification.json` owns the complete command inventory, including focused
harness environments. `qualification.mjs all` runs it sequentially for the local
root gate; `qualification.mjs list` supplies the hosted matrix. Full qualification
runs those lanes on separate runners, at most four at once, so editorial failures
need not wait behind Lighthouse. Each lane reports its elapsed time. Lighthouse
samples remain sequential on their own runner. The complete aggregate requires
every lane and the history secret scan; failure, cancellation or missing work
cannot qualify a commit. Parallelism reduces elapsed time, not required coverage
or total runner work.

Run `node --test tests/unit/ci-plan.test.js tests/unit/qualification.test.js` for
routing, complete command inventory and aggregate proof. Run a selected
lane with `nix develop -c sh tools/ci/run-check.sh LANE`; see
[testing policy](../../docs/development/testing.md) for the required aggregate,
full qualification cadence and review obligations. Missing/failed/cancelled
selected jobs cannot satisfy the PR requirement.
