# Source release verification

Portable CMS is distributed under Apache License 2.0. See
[provenance](../../PROVENANCE.md), [third-party material](../../THIRD_PARTY.md)
and [the licensing decision](../architecture/decisions/0010-apache-2-publication.md).

## Review source and rights

Review every new dependency, media asset and font for its source, licence,
modifications and required notices. The locked dependency graph and
`tools/publication/assets.json` are checked by the repository gate. Keep client
content, originals, credentials, installation configuration and local state out
of source commits. Secret scanners support review; they cannot prove absence.

## Qualify the exact commit

Use the public pinned toolchain and locked dependencies:

```sh
nix develop -c composer install --no-interaction --prefer-dist
nix develop -c tools/check-fast.sh
nix develop -c tools/check-repository.sh
nix develop -c gitleaks git --log-opts='--all --full-history' --redact=100 --ignore-gitleaks-allow --no-banner .
```

PRs require fast and affected checks. Release and source-publication review
requires complete qualification for the exact commit, including database,
public-site and browser lanes. Record the commit and successful hosted run;
neither a local run nor a short PR gate supplies that external full proof.
See [testing policy](testing.md) and [contribution guidance](../../CONTRIBUTING.md).

## Provenance and activation

`PUBLICATION.json` describes bytes and modes in the initial source import,
excluding itself. It is historical provenance; later contributions do not
regenerate it. Current qualification belongs to the commit under review.

Source verification does not deploy a CMS or change repository visibility.
Repository owners approve release publication and verify branch protection,
security reporting and anonymous access. Installed-site upgrades and content
publication use their separate documented operator workflows. Report security
issues through [SECURITY.md](../../SECURITY.md).
