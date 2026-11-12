# Installation acceptance checks

Use this procedure to verify a specific installation before handing it over or
relying on it for publication. Passing on one account does not qualify every
plan from that provider. Keep host identities, credentials, configuration,
backups and detailed evidence in private operator storage.

## Prepare the installation

1. Follow the [host qualification guide](conventional-host-probe.md) using the
   actual web PHP and scheduled CLI environments. Check private storage, required
   extensions, transactional database behaviour, HTTPS and the worker schedule.
   Qualify optional capabilities separately.
2. Install the independently verified engine and profile package using the
   [installation and identity guide](conventional-cms-identity.md). Verify the
   deployed files against the bundle manifest, apply forward migrations and
   create the owner account. Remove temporary installer and probe entrypoints.
3. Preserve and test the installation's recovery path before changing an
   existing site. A complete backup includes the database, private storage,
   configuration and installed engine/profile packages. A content export alone
   does not replace that backup.

## Check editing and publication

- Use fictional acceptance content on an isolated test installation. Exercise
  sign-in, content validation, translations, media processing and the actual
  scheduled worker. Verify disabled capabilities do not leave work stuck.
- Build an [exact preview](exact-website-preview.md). Check representative pages,
  languages, media and deep navigation; verify anonymous requests cannot read
  private previews, originals or configuration.
- On an authorised test target, approve a release through the ordinary
  [publication workflow](publication-workflow.md). Verify served files against
  its artifact manifest and check discovery metadata, redirects and delivery
  headers over HTTPS. Database activation alone is not proof of correct delivery.
- Exercise retained-release rollback and interrupted-publication recovery on
  the isolated target. Standard delivery may expose mixed approved bytes during
  recovery; only claim Atomic delivery when that capability has been qualified.

## Check export and restore

Follow the [handover procedure](portable-handover.md) and its
[capacity limits](export-capacity.md#supported-envelope). Use a distinct empty
destination with independently configured accounts, secrets, URLs and a
compatible engine/profile package. Do not reuse or reset an occupied target.

Run the read-only restore plan, test bounded interruption and resume, and check
that repeating completion is idempotent. Compare restored content, retained
revisions, relationships and media against the captured export, then build a
fresh destination preview. Compare against that frozen export, not later edits
made to the source. Source accounts and publication authority are not imported.

## Record and maintain the result

Record the date, engine/profile versions, tested capabilities and limits, plus
private manifests and recovery evidence. Remove temporary test entrypoints and
restore the normal worker configuration. Resolve failed checks without rewriting
content history, bypassing checksums or substituting assets to manufacture a pass.

Recheck relevant capabilities after hosting, runtime, profile or deployment
changes. A successful acceptance run is evidence for that configuration, not a
promise of unlimited capacity, recovery time or provider-wide compatibility.
