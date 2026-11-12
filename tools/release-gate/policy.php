<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\ReleaseGate;

final class ReleasePolicy
{
    public const VERSION_PATH = 'engine/src/cms/conventional/content/profile.php';

    public static function version(string $source): string
    {
        if (preg_match("/public const ENGINE_VERSION = '((?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*))';/", $source, $match) !== 1) {
            throw new \RuntimeException('The single ProfileManifest::ENGINE_VERSION declaration is missing or invalid');
        }
        return $match[1];
    }

    /** @param array<string, array{before: ?string, after: ?string}> $changes
     * @param list<string> $apiPaths
     * @return list<string>
     */
    public static function check(string $before, string $after, array $changes, array $apiPaths, string $changelog): array
    {
        $minor = $patch = [];
        foreach ($changes as $path => $bytes) {
            if (str_starts_with($path, 'engine/migrations/') && str_ends_with($path, '.sql')) {
                $minor[] = 'database migration ' . $path;
            } elseif (str_starts_with($path, 'schemas/') && str_ends_with($path, '.schema.json')) {
                $minor[] = 'versioned contract ' . $path;
            } elseif (in_array($path, $apiPaths, true) && ApiSurface::fingerprint($bytes['before'] ?? '') !== ApiSurface::fingerprint($bytes['after'] ?? '')) {
                $minor[] = 'profile-facing API ' . $path;
            }
            if ((str_starts_with($path, 'engine/') && !str_ends_with($path, '.md'))
                || str_starts_with($path, 'tools/conventional-') || $path === 'tools/build-conventional-cms-bundle.php') {
                $patch[] = $path;
            }
        }
        $errors = [];
        $old = array_map(intval(...), explode('.', $before));
        $new = array_map(intval(...), explode('.', $after));
        $raised = version_compare($after, $before, '>');
        $minorRaised = $new[0] > $old[0] || ($new[0] === $old[0] && $new[1] > $old[1]);
        if ($minor !== [] && !$minorRaised) {
            $errors[] = "Raise the engine minor version above {$before} for: " . implode(', ', $minor);
        } elseif ($patch !== [] && !$raised) {
            $errors[] = "Raise the engine patch version above {$before} for runtime/bundle changes: " . implode(', ', $patch);
        }
        if (version_compare($after, $before, '<')) {
            $errors[] = "Engine version {$after} is below base version {$before}";
        }
        if ($raised && preg_match('/^## ' . preg_quote($after, '/') . '(?:\s|$)/m', $changelog) !== 1) {
            $errors[] = "Add the {$after} release to CHANGELOG.md with its API, migration and format changes";
        }
        return $errors;
    }
}
