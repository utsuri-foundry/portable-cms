# Engine releases

`ProfileManifest::ENGINE_VERSION` in
`engine/src/cms/conventional/content/profile.php` is the single source of the
engine version. A change that profiles or installed sites depend on raises that
version in the same implementation change and adds a `CHANGELOG.md` entry.
Do not identify different engine contracts with the same version.

During 0.x development:

- Raise the minor version for a new or changed profile-facing API, manifest field,
  database migration, or bundle/package format. Reset the patch to zero.
- Raise the patch for fixes that preserve those contracts, including rendering,
  validation and administration fixes. Never decrease a version or reuse it for
  a different released engine. Several changes released together share one bump.
- Documentation, tests and developer-only tooling changes need no engine bump
  when installed behavior and profile contracts remain unchanged.

The policy is about engine compatibility; profile versions and schema
`formatVersion` values have their own identities. A minor engine bump does not
silently reinterpret older exports or alter completed migration bytes.

## Required gate

`nix develop -c tools/check-repository.sh` runs `tools/check-engine-release.php`
against the current `origin/main` snapshot. Fetch the base before review. A
missing base fails the check; it is never silently skipped. CI or an isolated
fixture can explicitly choose `--base=<Git ref>` and `--root=<checkout>`.

The gate reads the working tree, including staged and untracked additions.
Changes to SQL migrations or versioned JSON schemas require a minor bump.
`engine-release-policy.json` registers the documented PHP profile API modules;
their class, constructor, public/protected method, property and constant
declarations are compared without executing historical PHP. Method bodies and
comments do not change that declaration fingerprint. Constructors include their
promoted properties. `ENGINE_VERSION` itself is excluded from this fingerprint,
so a patch bump is possible. Removed API registry entries remain checked against
the base registry for that change. Register new documented modules when exposing
them to profiles, and keep the [authoring guide](profile-authoring.md) aligned.

Other engine runtime and installed operator/bundle changes require at least a
version increase. Failures identify the migration/API/file that needs the bump.
Every raised version requires its heading and API/migration/format notes in
`CHANGELOG.md`. Reviewers still classify semantic API/manifest/format changes:
the declaration check cannot infer a changed meaning inside a method body.

## Bundles and installed builds

The developer bundle builder requires Git and records its full HEAD revision,
engine version and dirty-checkout flag. `manifest.json` includes `engineVersion`,
`sourceRevision` and `sourceDirty`; the private `engine-version.json` uses
`version` with the same provenance fields. The checksum inventory defines the
exact delivered bytes, including builds made during development. Use a clean,
reviewed commit for a released bundle. The marker and manifest must agree;
invalid or inconsistent provenance is refused. Git is not copied into the bundle
and no installed operation invokes it.

Upgrade dry-run `engine` retains `from` and `to`, adding `fromSourceRevision`,
`toSourceRevision`, `fromSourceDirty` and `toSourceDirty`. The serving runtime's
own marker supplies its provenance, even though the operator runs from the new
bundle. Old manifests/markers remain accepted: absent source facts are null,
never inferred from the incoming bundle or the operator's machine.

Profiles set `compatibility.engine.minimum` to the engine version that first
provided every API, manifest feature and format they consume. Incompatible
packages fail at profile check, bundle creation, installation and upgrade before
rendering; installation checks precede database connections and migrations.
Authenticated install/upgrade responses use `profile_engine_incompatible`, a
safe message, `minimumEngineVersion`, `maximumEngineVersionExclusive` and
`runningEngineVersion`. Unauthenticated gate requests keep their ordinary
non-disclosing refusal.

Once both profile packages pass their respective engine checks, a change only to
the engine range is content-neutral when the known record-contract declarations
are identical. It needs no content mapping. Changed record contracts, unknown
compatibility fields and invalid ranges retain the existing migration/refusal
rules. This permits a legacy 0.1.2 installation to adopt a profile that correctly
requires 0.2.0 alongside its incoming 0.2.0 engine, preserving authored history.
