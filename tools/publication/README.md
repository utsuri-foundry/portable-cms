# Publication preparation

Owns local source-snapshot preparation and private audit orchestration. Uses only
Node.js built-ins, Git and the Nix-pinned Gitleaks CLI. It never pushes, modifies
remote refs, changes repository visibility or deletes archive history. No runtime
or public-site code depends on these tools. Public source is selected from a
clean committed tree, not the worktree's ignored files.

See [the runbook](../../docs/development/publication.md) and ADR 0010. Audit inputs
and reports contain private identifiers and must live outside Git. No generic
allowlist waives findings. Review every category; scanners cannot prove absence.
The audit command records every ref with the chosen private-retention disposition;
maintainers must compare the complete inventory with open claims and PRs before
sign-off. It inspects reachable blobs, tree names, commit metadata and messages,
refs and current tracked files with the same private rules.

Focused proof:

```sh
nix develop -c node --test tests/unit/publication.test.js
nix develop -c node tools/publication/check.mjs
```

The snapshot manifest describes the initial imported bytes and modes, excluding
itself. It is provenance for the root import, not an evergreen generated status
file; later public contributions do not regenerate it. The source-to-public
commit mapping and private audit remain outside the exported tree. Markdown
links to withheld task records retain their human descriptions as plain text.
Public AGENTS, task README and checkout instructions are explicitly transformed;
the exporter never renames or deletes permanent private task records.

`public-docs.mjs` projects contributor-facing policies and operator guidance,
replaces internal cutover journals with source-verification guidance, and rejects
coordination references or personal workspace paths in exported documentation.
It validates every prepared document before creating the output directory.
The same check runs in public checkouts, identified by their root provenance,
and requires empty architecture/quality exception registries. Deleting the
current provenance file cannot disable it. Private coordination instructions
remain authoritative in their source checkout.

`documentation.json` is the reviewed documentation inventory. Every `docs/`
file has an audience (`index`, `build`, `operate`, `contribute`) or is marked
`private`. Snapshot creation refuses unreviewed documents and excludes working
notes. The public gate also refuses reintroducing excluded or unreviewed files.
The host-acceptance journal becomes a reusable procedure; original operational
evidence stays in the private source. Public transforms are idempotent and keep
later edits to the public guides.

Removing a document from a review branch does not remove earlier Git objects.
When material is withheld before initial publication, prepare and audit a fresh
root after review. Never make a review repository containing withheld history
public or describe a current-tree check as a history scrub.
