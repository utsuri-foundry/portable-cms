# Contributing

Portable CMS welcomes focused fixes, documentation improvements and reviewed
feature proposals. Discuss substantial changes in an issue before implementing
them. Use a branch and pull request against `main`; do not include real client
content, private media, credentials, host/account identities or local paths.

Read `AGENTS.md`, the affected area READMEs and the
[review checklist](docs/development/review-checklist.md). Keep one observable
outcome per change. Update contracts, fixtures, provenance, documentation and the
engine version when required by the [release policy](docs/development/engine-releases.md).

Keep area READMEs brief: purpose, ownership boundaries, starting points and
useful checks. Put detailed contracts and procedures in the relevant guide and
link to them. Do not append a feature history or a paragraph for every change.

Write every guide for a named reader and task. Explain the outcome first, put
steps in working order, and introduce examples before detailed rules. Label code
fragments, keep limits and recovery instructions beside the actions they govern,
and separate API reference from the main walkthrough. Remove obsolete statements
and duplication when behaviour changes. Use changelogs and dated decisions for
history; current guides must not become implementation journals.

Install Git and Nix with flakes enabled on Linux. All required PHP extensions,
MariaDB, Apache, Node.js, Chromium and analysis tools come from the public
`flake.lock`; a Linux host is required for the complete Chromium/Lighthouse gate.
No private package registry, account, token or office tool is required:

```sh
git fetch origin
nix develop -c composer install --no-interaction --prefer-dist
nix develop -c tools/check-fast.sh
```

The fast command runs static, unit, bundle, extraction and secret checks. PRs
also run affected database, browser and public-site lanes selected by changed
paths. All selected checks are required. See [testing policy](docs/development/testing.md)
for the mapping and review obligations. Complete qualification remains
`nix develop -c tools/check-repository.sh`: it runs nightly, on version tags and
manually before release or publication. Customer hosts need none of this tooling.
Maintainers may use private task coordination internally; it is not a
prerequisite for an external contribution or a repository check.

By intentionally submitting a contribution for inclusion, you offer it under
the Apache License 2.0, section 5. No CLA or DCO sign-off is required. Submit only
material you have the right to contribute. Identify third-party material and
its exact source, licence, modifications and required notices; preserve existing
notices. See `THIRD_PARTY.md`. The project does not add mechanical SPDX headers
to existing files; root licensing and explicit third-party inventories define
scope. Describe modifications prominently when redistributing changed files, as
required by the licence. The licence does not grant the project's trademarks.

Report vulnerabilities through [the private security channel](SECURITY.md).
