<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['output:', 'private-root:', 'profile-root:', 'upgrade-bundle-root:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Usage:
  php tools/build-conventional-cms-bundle.php \
    --output=/new/local/bundle-directory \
    --private-root=/absolute/remote/private-cms-directory

The result contains public/ for first installation, private/runtime/ for the
private directory, and operator/migrate.php for a temporary authenticated
update endpoint. The runtime expects its owner-only configuration at
<private-root>/cms.json. Optional --profile-root packages a verified profile in
private/profile; pass --profile-root=<private-root>/profile to
conventional-create-install-config.php to record its remote installed path and
enable that profile and its exact-preview compiler.
HELP);
    fwrite(STDOUT, "\n");
    exit(0);
}

$output = is_string($options['output'] ?? null) ? rtrim($options['output'], DIRECTORY_SEPARATOR) : '';
$privateRoot = is_string($options['private-root'] ?? null)
    ? rtrim($options['private-root'], DIRECTORY_SEPARATOR)
    : '';
if ($output === '' || $privateRoot === '' || !str_starts_with($privateRoot, DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "A new output directory and an absolute private root are required.\n");
    exit(64);
}
if (file_exists($output)) {
    fwrite(STDERR, "The output path already exists; refusing to merge or overwrite it.\n");
    exit(73);
}

/** @return void */
function copyTree(string $source, string $destination): void
{
    if (!mkdir($destination, 0700, true) && !is_dir($destination)) {
        throw new RuntimeException('Could not create bundle directory');
    }
    $iterator = new DirectoryIterator($source);
    foreach ($iterator as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        $target = $destination . DIRECTORY_SEPARATOR . $entry->getFilename();
        if ($entry->isDir()) {
            copyTree($entry->getPathname(), $target);
        } elseif ($entry->isFile() && !copy($entry->getPathname(), $target)) {
            throw new RuntimeException('Could not copy a runtime file');
        }
    }
}

$repository = dirname(__DIR__);
try {
    if (!is_file($repository . '/vendor/autoload.php')) {
        throw new RuntimeException('Composer runtime dependencies are missing; run composer install first');
    }
    require_once $repository . '/engine/src/cms/conventional/bootstrap.php';
    require_once __DIR__ . '/release-gate/repository.php';
    $source = new \Utsuri\PortableCms\ReleaseGate\ReleaseRepository($repository);
    $engineBuild = new \Utsuri\PortableCms\Conventional\Upgrade\EngineBuild(\Utsuri\PortableCms\Conventional\Content\ProfileManifest::ENGINE_VERSION, $source->revision(), $source->dirty());
    $runtime = $output . '/private/runtime';
    copyTree($repository . '/engine/src/cms/conventional', $runtime . '/engine/src/cms/conventional');
    copyTree($repository . '/engine/migrations', $runtime . '/engine/migrations');
    copyTree($repository . '/vendor', $runtime . '/vendor');
    foreach (['LICENSE', 'NOTICE', 'THIRD_PARTY.md'] as $notice) {
        if (!copy($repository . '/' . $notice, $runtime . '/' . $notice)) {
            throw new RuntimeException('Could not copy distribution notices');
        }
    }
    file_put_contents($runtime . '/engine-version.json', json_encode($engineBuild->document(), JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
    if (isset($options['profile-root'])) {
        if (!is_string($options['profile-root'])) {
            throw new RuntimeException('Profile package path is invalid');
        }
        require_once $repository . '/engine/src/cms/conventional/bootstrap.php';
        $package = \Utsuri\PortableCms\Conventional\Content\ProfilePackage::load($options['profile-root']);
        $package->siteBuilder();
        $destination = $output . '/private/profile';
        mkdir($destination, 0700, true);
        copy($package->root . '/profile.json', $destination . '/profile.json');
        if (is_file($package->root . '/upgrade.json')) {
            copy($package->root . '/upgrade.json', $destination . '/upgrade.json');
        }
        copyTree($package->root . '/src', $destination . '/src');
        copyTree($package->root . '/licenses', $destination . '/licenses');
        if (is_file($package->root . '/' . \Utsuri\PortableCms\Conventional\Content\AdministrationLabels::FILE)) {
            copy($package->root . '/' . \Utsuri\PortableCms\Conventional\Content\AdministrationLabels::FILE, $destination . '/' . \Utsuri\PortableCms\Conventional\Content\AdministrationLabels::FILE);
        }
        $files = [];
        foreach ($package->manifest->renderers as $renderer) {
            $files[$renderer->entrypoint] = $package->rendererPath($renderer->id);
        }
        foreach ($package->manifest->assets as $asset) {
            $files[$asset->path] = $package->assetPath($asset->path);
        }
        foreach ($files as $relative => $source) {
            $target = $destination . '/' . $relative;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) {
                throw new RuntimeException('Private profile directory could not be created');
            }
            if (!copy($source, $target)) {
                throw new RuntimeException('Verified profile file could not be copied');
            }
        }
        $installed = \Utsuri\PortableCms\Conventional\Content\ProfilePackage::load($destination);
        if (!hash_equals($package->checksum, $installed->checksum) || !hash_equals($package->administrationLabels->fingerprint(), $installed->administrationLabels->fingerprint())) {
            throw new RuntimeException('Bundled profile identity changed');
        }
    }
    mkdir($runtime . '/schemas', 0700, true);
    foreach ([
        'portable-cms-ai-candidate-v1.schema.json',
        'portable-cms-artifact-v1.schema.json',
        'portable-cms-capabilities-v1.schema.json',
        'portable-cms-composition-v1.schema.json',
        'portable-cms-composition-v2.schema.json',
        'portable-cms-export-v1.schema.json',
        'portable-cms-export-v2.schema.json',
        'portable-cms-content-package-v1.schema.json',
        'portable-cms-profile-v1.schema.json',
        'portable-cms-profile-v2.schema.json',
        'portable-cms-profile-v3.schema.json',
        'portable-cms-design-registries-v1.schema.json',
        'portable-cms-protocol-v1.schema.json',
        'portable-cms-record-v1.schema.json',
        'portable-cms-rich-text-v1.schema.json',
        'portable-cms-rich-text-v2.schema.json',
        'portable-cms-search-v1.schema.json',
        'portable-cms-snapshot-v1.schema.json',
        'portable-cms-vimeo-video-v1.schema.json',
        'portable-cms-administration-labels-v1.schema.json',
        'portable-cms-feed-v1.schema.json',
        'portable-cms-feed-snapshot-v1.schema.json',
        'portable-cms-feed-page-v1.schema.json',
        'portable-cms-feed-activation-v1.schema.json',
        'portable-cms-feed-activations-v1.schema.json',
        'portable-cms-terminology-v1.schema.json',
    ] as $schema) {
        if (!copy($repository . '/schemas/' . $schema, $runtime . '/schemas/' . $schema)) {
            throw new RuntimeException('Could not copy a runtime schema');
        }
    }
    mkdir($runtime . '/tools', 0700, true);
    foreach ([
        'conventional-cms-web.php',
        'conventional-restore.php',
        'conventional-import-content.php',
        'conventional-portable-export.php',
        'conventional-portable-restore.php',
        'conventional-purge-feed.php',
        'conventional-export-package.php',
        'conventional-migrate.php',
        'conventional-migrate-web.php',
        'conventional-upgrade.php',
        'conventional-upgrade-web.php',
        'conventional-release-launcher.php',
        'conventional-upgrade-acceptance.php',
        'conventional-bootstrap-admin.php',
        'conventional-recover-owner.php',
        'conventional-recover-owner-web.php',
        'conventional-media-worker.php',
        'conventional-ai-worker.php',
        'conventional-install-web.php',
        'check-php-templates.php',
    ] as $tool) {
        if (!copy($repository . '/tools/' . $tool, $runtime . '/tools/' . $tool)) {
            throw new RuntimeException('Could not copy a runtime tool');
        }
    }
    copy(
        $repository . '/config/conventional-cms.example.json',
        $output . '/private/cms.example.json',
    );

    $public = $output . '/public';
    mkdir($public, 0700, true);
    mkdir($public . '/assets', 0700, true);
    copy($repository . '/engine/src/cms/conventional/client/editorial-form.js', $public . '/assets/editorial-form.js');
    copy($repository . '/engine/src/cms/conventional/client/record-autosave.js', $public . '/assets/record-autosave.js');
    copy($repository . '/engine/src/cms/conventional/client/record-keeper.js', $public . '/assets/record-keeper.js');
    copy($repository . '/engine/src/cms/conventional/client/media-descriptions.js', $public . '/assets/media-descriptions.js');
    copy($repository . '/engine/src/cms/conventional/client/media-descriptions-model.js', $public . '/assets/media-descriptions-model.js');
    foreach (['video-previews.js', 'video-previews.css', 'preview-cleanup.js', 'preview-cleanup.css', 'preview-share.js', 'preview-share.css', 'admin-shell.js', 'admin-start.js', 'focal-point.js', 'focal-point.css', 'messages.js', 'rich-text-editor.js', 'rich-text-model.js', 'rich-text-surface.js', 'rich-text-clipboard.js', 'rich-text-history.js', 'rich-text-selection.js', 'rich-text-preview.js', 'composition-editor.js', 'composition-model.js', 'group-rows.js', 'groups.css', 'live-preview.js', 'live-vimeo.js', 'preview-vimeo.js', 'vimeo-player.js', 'live-preview.css'] as $asset) {
        copy($repository . '/engine/src/cms/conventional/client/' . $asset, $public . '/assets/' . $asset);
    }
    copy(
        $repository . '/engine/src/cms/conventional/client/resumable-upload.js',
        $public . '/assets/resumable-upload.js',
    );
    copy(
        $repository . '/engine/src/cms/conventional/client/admin.css',
        $public . '/assets/admin.css',
    );
    $configPath = $privateRoot . '/cms.json';
    $webPath = $privateRoot . '/runtime/tools/conventional-cms-web.php';
    $index = "<?php\n\ndeclare(strict_types=1);\n\n"
        . 'putenv(' . var_export('CMS_CONFIG=' . $configPath, true) . ");\n"
        . 'require ' . var_export($webPath, true) . ";\n";
    file_put_contents($public . '/index.php', $index, LOCK_EX);
    $installConfigPath = $privateRoot . '/install.json';
    $installWebPath = $privateRoot . '/runtime/tools/conventional-install-web.php';
    $installer = "<?php\n\ndeclare(strict_types=1);\n\n"
        . 'putenv(' . var_export('CMS_INSTALL_CONFIG=' . $installConfigPath, true) . ");\n"
        . 'require ' . var_export($installWebPath, true) . ";\n";
    file_put_contents($public . '/install.php', $installer, LOCK_EX);
    $operator = $output . '/operator';
    mkdir($operator, 0700, true);
    $migrationConfigPath = $privateRoot . '/migrate.json';
    $migrationWebPath = $privateRoot . '/runtime/tools/conventional-migrate-web.php';
    $migrationEndpoint = "<?php\n\ndeclare(strict_types=1);\n\n"
        . 'putenv(' . var_export('CMS_MIGRATION_CONFIG=' . $migrationConfigPath, true) . ");\n"
        . 'require ' . var_export($migrationWebPath, true) . ";\n";
    file_put_contents($operator . '/migrate.php', $migrationEndpoint, LOCK_EX);
    $recoveryEndpoint = "<?php\n\ndeclare(strict_types=1);\n"
        . 'putenv(' . var_export('CMS_OWNER_RECOVERY_CONFIG=' . $privateRoot . '/recover-owner.json', true) . ");\n"
        . 'require ' . var_export($privateRoot . '/runtime/tools/conventional-recover-owner-web.php', true) . ";\n";
    file_put_contents($operator . '/recover-owner.php', $recoveryEndpoint, LOCK_EX);
    $upgradeRoot = $options['upgrade-bundle-root'] ?? $privateRoot . '/incoming';
    if (!is_string($upgradeRoot) || !str_starts_with($upgradeRoot, '/')) {
        throw new RuntimeException('Remote upgrade bundle root must be absolute');
    }
    $upgradeEndpoint = "<?php\n\ndeclare(strict_types=1);\n"
        . 'putenv(' . var_export('CMS_UPGRADE_CONFIG=' . $privateRoot . '/upgrade-gate.json', true) . ");\n"
        . 'require ' . var_export($upgradeRoot . '/private/runtime/tools/conventional-upgrade-web.php', true) . ";\n";
    file_put_contents($operator . '/upgrade.php', $upgradeEndpoint, LOCK_EX);
    file_put_contents(
        $public . '/.htaccess',
        "Options -Indexes\nDirectoryIndex index.php\nFallbackResource /index.php\n",
        LOCK_EX,
    );

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output));
    $manifest = [];
    foreach ($files as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($output) + 1);
        $manifest[$relative] = [
            'sha256' => hash_file('sha256', $file->getPathname()),
            'bytes' => $file->getSize(),
        ];
    }
    ksort($manifest, SORT_STRING);
    file_put_contents(
        $output . '/manifest.json',
        json_encode(['version' => 1, 'engineVersion' => $engineBuild->version, 'sourceRevision' => $engineBuild->sourceRevision, 'sourceDirty' => $engineBuild->sourceDirty, 'files' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        LOCK_EX,
    );
    fwrite(STDOUT, sprintf("Built %d files for private root %s.\n", count($manifest), $privateRoot));
} catch (Throwable $error) {
    if ($error instanceof \Utsuri\PortableCms\Conventional\Content\ProfileEngineMismatch) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
    fwrite(STDERR, 'Bundle build failed (' . $error::class . ").\n");
    exit(1);
}
