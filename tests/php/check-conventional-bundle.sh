#!/bin/sh

set -eu

repository=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/portable-cms-bundle.XXXXXX")
cleanup() {
  rm -rf -- "$temporary_root"
}
trap cleanup EXIT HUP INT TERM

php "$repository/tools/build-conventional-cms-bundle.php" \
  --output="$temporary_root/bundle" \
  --private-root=/srv/portable-cms/private

runtime="$temporary_root/bundle/private/runtime"
bundle_source_revision=$(git -C "$repository" rev-parse HEAD)
php -r '
  require $argv[1] . "/private/runtime/engine/src/cms/conventional/bootstrap.php";
  $bundle = Utsuri\PortableCms\Conventional\Upgrade\UpgradeBundle::load($argv[1]);
  $build = $bundle->engineBuild;
  if ($build->version !== Utsuri\PortableCms\Conventional\Content\ProfileManifest::ENGINE_VERSION
      || $build->sourceRevision !== $argv[2] || !is_bool($build->sourceDirty)) {
      throw new RuntimeException("Bundle version/revision/dirty provenance differs from the source checkout");
  }
' "$temporary_root/bundle" "$bundle_source_revision"
test ! -d "$runtime/.git"
test -f "$runtime/vendor/autoload.php"
cmp "$repository/vendor/composer/LICENSE" "$runtime/vendor/composer/LICENSE"
for notice in LICENSE NOTICE THIRD_PARTY.md; do
  cmp "$repository/$notice" "$runtime/$notice"
done
for package in json-schema string uri; do
  test -s "$runtime/vendor/opis/$package/LICENSE"
done
for package in json-schema string; do
  cmp "$repository/vendor/opis/$package/NOTICE" "$runtime/vendor/opis/$package/NOTICE"
done
test -f "$temporary_root/bundle/public/assets/admin.css"
test -s "$temporary_root/bundle/public/assets/admin.css"
test -s "$temporary_root/bundle/public/assets/preview-share.js"
test -s "$temporary_root/bundle/public/assets/preview-cleanup.js"
test -s "$temporary_root/bundle/public/assets/preview-cleanup.css"
test -s "$temporary_root/bundle/public/assets/preview-share.css"
test -s "$temporary_root/bundle/public/assets/editorial-form.js"
test -s "$temporary_root/bundle/public/assets/record-autosave.js"
test -s "$temporary_root/bundle/public/assets/record-keeper.js"
test -s "$temporary_root/bundle/public/assets/media-descriptions.js"
test -s "$temporary_root/bundle/public/assets/media-descriptions-model.js"
test -s "$temporary_root/bundle/public/assets/rich-text-editor.js"
test -s "$temporary_root/bundle/public/assets/rich-text-model.js"
test -s "$temporary_root/bundle/public/assets/rich-text-preview.js"
test -s "$temporary_root/bundle/public/assets/rich-text-surface.js"
test -s "$temporary_root/bundle/public/assets/rich-text-clipboard.js"
test -s "$temporary_root/bundle/public/assets/rich-text-history.js"
test -s "$temporary_root/bundle/public/assets/rich-text-selection.js"
test -s "$temporary_root/bundle/public/assets/admin-shell.js"
test -s "$temporary_root/bundle/public/assets/messages.js"
test -s "$temporary_root/bundle/public/assets/live-preview.js"
test -s "$temporary_root/bundle/public/assets/live-preview.css"
test -s "$temporary_root/bundle/private/runtime/engine/src/cms/conventional/client/live-preview-bridge.js"
test -s "$runtime/schemas/portable-cms-rich-text-v1.schema.json"
test -s "$runtime/schemas/portable-cms-rich-text-v2.schema.json"
test -s "$runtime/schemas/portable-cms-ai-candidate-v1.schema.json"
test -s "$runtime/tools/conventional-ai-worker.php"
test -s "$runtime/tools/conventional-restore.php"
test -s "$runtime/tools/conventional-import-content.php"
test -s "$runtime/engine/migrations/0024-content-import.sql"
php "$runtime/tools/conventional-import-content.php" --help >/dev/null
test -s "$runtime/tools/conventional-portable-export.php"
test -s "$runtime/tools/conventional-portable-restore.php"
test -s "$runtime/schemas/portable-cms-content-package-v1.schema.json"
cmp "$repository/tools/conventional-portable-export.php" "$runtime/tools/conventional-portable-export.php"
cmp "$repository/tools/conventional-portable-restore.php" "$runtime/tools/conventional-portable-restore.php"
php "$runtime/tools/conventional-portable-export.php" --help >/dev/null
php "$runtime/tools/conventional-portable-restore.php" --help >/dev/null
test -s "$runtime/tools/conventional-export-package.php"
test -s "$runtime/tools/conventional-upgrade.php"
test -s "$runtime/tools/conventional-upgrade-web.php"
test -s "$runtime/tools/conventional-release-launcher.php"
test -s "$temporary_root/bundle/operator/upgrade.php"
test -s "$runtime/engine/migrations/0011-normalized-restore.sql"
cmp "$repository/tools/conventional-restore.php" "$runtime/tools/conventional-restore.php"
php "$runtime/tools/conventional-restore.php" --help >"$temporary_root/restore-help.txt"
grep -F '0: compatible dry run, restored or already_restored.' "$temporary_root/restore-help.txt" >/dev/null
grep -F '2: preparing; incomplete, repeat the identical intent to resume.' "$temporary_root/restore-help.txt" >/dev/null
grep -F '1: blocked or rejected; investigate before retrying.' "$temporary_root/restore-help.txt" >/dev/null
php "$runtime/tools/conventional-export-package.php" --help >/dev/null
php "$runtime/tools/conventional-upgrade.php" --help >/dev/null
for schema in artifact capabilities export profile protocol record search snapshot terminology design-registries; do
  test -f "$runtime/schemas/portable-cms-$schema-v1.schema.json"
done

php -r '
  require $argv[1] . "/engine/src/cms/conventional/bootstrap.php";
  $profile = Utsuri\PortableCms\Conventional\Content\ProfileManifest::gateZero();
  if ($profile->id !== "portable-gate-zero") {
      fwrite(STDERR, "Bundled profile validation failed.\n");
      exit(1);
  }
' "$runtime"

printf '%s\n' "Conventional bundle dependency and profile smoke test passed."

php "$repository/tools/build-conventional-cms-bundle.php" \
  --output="$temporary_root/profile-bundle" \
  --private-root=/srv/portable-cms/private \
  --profile-root="$repository/profiles/reference-studio"

php -r '
  require $argv[1] . "/private/runtime/engine/src/cms/conventional/bootstrap.php";
  $profile = Utsuri\PortableCms\Conventional\Content\ProfilePackage::load($argv[1] . "/private/profile");
  $profile->siteBuilder();
  if ($profile->manifest->id !== "reference-studio") {
      throw new RuntimeException("Installed profile differs");
  }
' "$temporary_root/profile-bundle"

printf '%s\n' "Installed private Reference Studio profile/compiler bundle check passed."

# Exercise the additive hint contract through the real release builder and installed validator.
cp -R "$repository/profiles/reference-studio" "$temporary_root/hint-profile"
php -r '
  $path = $argv[1] . "/profile.json";
  $manifest = json_decode(file_get_contents($path), flags: JSON_THROW_ON_ERROR);
  $manifest->recordTypes[0]->fields[0]->hint = "A short fictional title";
  $manifest->compatibility->engine->minimum = "0.5.0";
  foreach ($manifest->recordTypes[0]->fields as $field) {
    if (($field->type ?? null) === "rich_text") { $field->settings->maximumBlocks = 1; }
  }
  file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
  $path = $argv[1] . "/administration-labels.json";
  $labels = json_decode(file_get_contents($path), flags: JSON_THROW_ON_ERROR);
  $labels->languages->ca->hints = (object) ["project.title" => "Un títol breu"];
  file_put_contents($path, json_encode($labels, JSON_THROW_ON_ERROR));
' "$temporary_root/hint-profile"
php "$repository/tools/build-conventional-cms-bundle.php" \
  --output="$temporary_root/hint-bundle" \
  --private-root=/srv/portable-cms/private \
  --profile-root="$temporary_root/hint-profile"
php -r '
  require $argv[1] . "/private/runtime/engine/src/cms/conventional/bootstrap.php";
  $package = Utsuri\PortableCms\Conventional\Content\ProfilePackage::load($argv[1] . "/private/profile");
  $title = $package->manifest->type("project")->fields[0];
  if ($title->hint !== "A short fictional title" || $package->administrationLabels->field($title, "ca", "project.title")->hint !== "Un títol breu") {
    throw new RuntimeException("Bundled field hints differ");
  }
  foreach ($package->manifest->type("project")->fields as $field) {
    if ($field->type === "rich_text" && $field->settings["maximumBlocks"] !== 1) { throw new RuntimeException("Bundled single-line setting differs"); }
  }
' "$temporary_root/hint-bundle"
php -r '
  $path = $argv[1] . "/profile.json";
  $manifest = json_decode(file_get_contents($path), flags: JSON_THROW_ON_ERROR);
  $manifest->recordTypes[0]->fields[0]->hint = str_repeat("x", 161);
  file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
' "$temporary_root/hint-profile"
if php "$repository/tools/build-conventional-cms-bundle.php" \
  --output="$temporary_root/invalid-hint-bundle" \
  --private-root=/srv/portable-cms/private \
  --profile-root="$temporary_root/hint-profile" >"$temporary_root/invalid-hint.log" 2>&1; then
  printf 'Invalid field hint was accepted by the bundle builder.\n' >&2
  exit 1
fi
grep -F 'SchemaValidationFailure' "$temporary_root/invalid-hint.log" >/dev/null
printf '%s\n' "Bundled default/translated hints survive installation; oversized hints are refused."
php -r '
  $path = $argv[1] . "/profile.json";
  $manifest = json_decode(file_get_contents($path), flags: JSON_THROW_ON_ERROR);
  unset($manifest->recordTypes[0]->fields[0]->hint);
  foreach ($manifest->recordTypes[0]->fields as $field) {
    if (($field->type ?? null) === "rich_text") { $field->settings->maximumBlocks = 2; }
  }
  file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
' "$temporary_root/hint-profile"
if php "$repository/tools/build-conventional-cms-bundle.php" \
  --output="$temporary_root/invalid-single-line-bundle" \
  --private-root=/srv/portable-cms/private \
  --profile-root="$temporary_root/hint-profile" >"$temporary_root/invalid-single-line.log" 2>&1; then
  printf 'Invalid maximumBlocks was accepted by the bundle builder.\n' >&2
  exit 1
fi
grep -F 'SchemaValidationFailure' "$temporary_root/invalid-single-line.log" >/dev/null
