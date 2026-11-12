<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Transfer;

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Restore\FilesystemRestoreStore;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;
use Utsuri\PortableCms\Conventional\Upgrade\RuntimeLease;

/** CLI host adapter. Authenticate before creating package files or destination locks. */
final readonly class OperatorContext
{
    private function __construct(public RuntimeConfiguration $config, public \PDO $pdo, public ProfilePackage $profile, public string $actorId, public string $publicRoot, public ?RuntimeLease $runtimeLease) {}

    /** @param array<string, mixed> $options */
    public static function load(array $options, string $runtimeRoot): self
    {
        $root = is_string($options['public-root'] ?? null) ? $options['public-root'] : '';
        if ($root === '' || realpath($root) !== $root || !is_dir($root)) {
            throw new RestoreRejected('explicit_document_root_required');
        }
        $path = is_string($options['config'] ?? null) ? $options['config'] : '';
        $lease = RuntimeLease::guard($path, $runtimeRoot);
        $config = RuntimeConfiguration::load($path, $root);
        if ($config->profileRoot === null) {
            throw new RestoreRejected('installed_profile_required');
        }
        $pdo = $config->connect();
        $lookup = $pdo->prepare("SELECT id FROM cms_users WHERE login_name = :login AND role IN ('owner', 'administrator') AND status = 'active' AND must_change_password = 0");
        $lookup->execute(['login' => is_string($options['administrator'] ?? null) ? $options['administrator'] : '']);
        $actor = $lookup->fetchColumn();
        if (!is_string($actor)) {
            throw new RestoreRejected('administrator_required');
        }
        return new self($config, $pdo, ProfilePackage::load($config->profileRoot), $actor, $root, $lease);
    }

    public static function separate(FilesystemRestoreStore $left, FilesystemRestoreStore $right): void
    {
        if ($left->root === $right->root || str_starts_with($left->root, $right->root . '/') || str_starts_with($right->root, $left->root . '/')) {
            throw new RestoreRejected('package_and_media_roots_must_be_separate');
        }
    }
}
