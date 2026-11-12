# Profiles

A profile defines a site's content model and presentation: a bounded manifest,
reviewed PHP renderers, prebuilt assets and compatibility metadata. The shared
engine supplies editing, validation, storage and publication.

Profiles depend on public engine contracts. They cannot add client-specific SQL
tables, replace authorization or accept executable code from editors. Only the
fictional [Reference Studio fixture](reference-studio/README.md) belongs here;
real client profiles live in separate repositories.

## Build a profile

Start with [profile authoring](../docs/development/profile-authoring.md) for
frozen data, routes, media, field marks, packaging and compatibility. That guide
also describes [custom holding pages](../docs/development/profile-rendering.md#optional-holding-presentation).
Use the [local CMS guide](../docs/development/local-reference-cms.md) to try a
profile in an isolated installation.

## Check a change

Run `nix develop -c php tests/php/run.php` for profile and compiler contracts.
For rendered changes, also run `nix develop -c sh tools/ci/run-check.sh public-site`.
Before review, follow the [testing policy](../docs/development/testing.md).
