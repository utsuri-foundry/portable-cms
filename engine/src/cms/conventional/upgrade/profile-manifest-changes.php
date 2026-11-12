<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Upgrade;

/** Conservative compatibility policy over verified manifest documents, independent of I/O. */
final readonly class ProfileManifestChanges
{
    /**
     * @param list<string> $manifestKeys
     * @param list<string> $neutral
     * @param list<array{path: string, recordTypes: list<string>}> $affecting
     */
    private function __construct(public array $manifestKeys, public array $neutral, public array $affecting) {}

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    public static function classify(?array $old, ?array $new): self
    {
        // JSON object property order is not a field change; ordered field/item arrays still are.
        $old = $old === null ? null : self::orderedObjects($old);
        $new = $new === null ? null : self::orderedObjects($new);
        $types = self::typeIds($old ?? [], $new ?? []);
        if ($old === null || $new === null) {
            return new self($old === $new ? [] : ['profile-package'], [], $old === $new ? [] : [['path' => 'profile-package', 'recordTypes' => $types]]);
        }
        $manifestKeys = [];
        $neutral = [];
        $affecting = [];
        foreach (self::keys($old, $new) as $key) {
            if (self::same($old, $new, $key)) {
                continue;
            }
            $manifestKeys[] = $key;
            if (in_array($key, ['recordTypes', 'singletons', 'taxonomyNamespaces'], true)) {
                self::definitions($old[$key] ?? [], $new[$key] ?? [], $key, $types, false, $neutral, $affecting);
            } elseif (in_array($key, ['version', 'assets', 'renderers', 'navigation', 'designRegistries', 'wording'], true)
                && self::knownNeutral($key, $old[$key] ?? null) && self::knownNeutral($key, $new[$key] ?? null)
                && ($key !== 'designRegistries' || self::compatibleDesign($old[$key] ?? null, $new[$key] ?? null))) {
                $neutral[] = $key;
            } elseif ($key === 'contentFeed' && self::feed($old[$key] ?? null) && self::feed($new[$key] ?? null)) {
                // Reviewed public projection changes require a new artifact, not a content migration.
                $neutral[] = $key;
            } elseif ($key === 'compatibility' && self::engineRangeOnly($old[$key] ?? null, $new[$key] ?? null)) {
                // Both packages already passed their engine checks; a range declares code compatibility, not stored content.
                $neutral[] = $key;
            } elseif ($key === 'languageEnvelope' && self::widens($old[$key] ?? null, $new[$key] ?? null)) {
                // A wider envelope only offers more languages; a narrower one rechecks the text of added languages.
                $neutral[] = $key;
            } else {
                // Locales, contracts, capabilities and every unclassified/new key fail closed.
                $affecting[] = ['path' => $key, 'recordTypes' => $types];
            }
        }
        sort($neutral, SORT_STRING);
        usort($affecting, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        return new self($manifestKeys, $neutral, $affecting);
    }

    private static function engineRangeOnly(mixed $old, mixed $new): bool
    {
        foreach ([$old, $new] as $value) {
            if (!is_array($value) || count($value) !== 2 || array_diff(array_keys($value), ['engine', 'contracts']) !== []
                || !is_array($value['engine'] ?? null) || count($value['engine']) !== 2
                || array_diff(array_keys($value['engine']), ['minimum', 'maximumExclusive']) !== []
                || !is_string($value['engine']['minimum'] ?? null) || !is_string($value['engine']['maximumExclusive'] ?? null)
                || !is_array($value['contracts'] ?? null)) {
                return false;
            }
            try {
                new \Utsuri\PortableCms\Conventional\Content\ProfileCompatibility($value['engine']['minimum'], $value['engine']['maximumExclusive'], $value['contracts']);
            } catch (\InvalidArgumentException) {
                return false;
            }
        }
        return $old['contracts'] === $new['contracts'];
    }

    /** Registry curves/styles are presentation; removing identities can invalidate saved token references. */
    private static function widens(mixed $old, mixed $new): bool
    {
        if (!is_array($new) || !is_array($new['scripts'] ?? null) || !is_array($new['directions'] ?? null)) {
            return false;
        }
        return $old === null || (is_array($old) && is_array($old['scripts'] ?? null) && is_array($old['directions'] ?? null)
            && array_diff($old['scripts'], $new['scripts']) === [] && array_diff($old['directions'], $new['directions']) === []
            // No character list draws every character; otherwise the ranges must all remain.
            && (!isset($new['characters']) || (is_array($new['characters']) && is_array($old['characters'] ?? null) && array_diff($old['characters'], $new['characters']) === [])));
    }

    private static function feed(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (!is_array($value) || array_diff(array_keys($value), ['formatVersion', 'recordTypes', 'pageSize']) !== []
            || ($value['formatVersion'] ?? null) !== 1 || !is_array($value['recordTypes'] ?? null) || !array_is_list($value['recordTypes'])
            || count($value['recordTypes']) < 1 || count($value['recordTypes']) > 64
            || !is_int($value['pageSize'] ?? 100) || ($value['pageSize'] ?? 100) < 1 || ($value['pageSize'] ?? 100) > 100) {
            return false;
        }
        foreach ($value['recordTypes'] as $type) {
            if (!is_array($type) || count($type) !== 2 || !is_string($type['typeId'] ?? null) || !is_array($type['fields'] ?? null) || !array_is_list($type['fields'])
                || count($type['fields']) < 1 || count($type['fields']) > 32) {
                return false;
            }
            foreach ($type['fields'] as $field) {
                if (!is_string($field) || !\Utsuri\PortableCms\Conventional\Content\ProfileManifest::fieldId($field)) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function compatibleDesign(mixed $old, mixed $new): bool
    {
        if ($old === null || $new === null) {
            return $old === $new;
        }
        if (!is_array($old) || !is_array($new) || (!self::same($old, $new, 'formatVersion') && !(($old['formatVersion'] ?? null) === 1 && ($new['formatVersion'] ?? null) === 2)) || !self::same($old, $new, 'profileId')) {
            return false;
        }
        foreach (['fonts', 'responsive', 'typography', 'spacing'] as $key) {
            if (array_diff(array_column($old[$key], 'id'), array_column($new[$key], 'id')) !== []) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    public function affectedTypes(): array
    {
        $ids = [];
        foreach ($this->affecting as $change) {
            $ids = [...$ids, ...$change['recordTypes']];
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        return $ids;
    }

    /**
     * Match stable IDs, not list positions. Reordering definitions alone remains conservative.
     * @param list<string> $types
     * @param list<string> $neutral
     * @param list<array{path: string, recordTypes: list<string>}> $affecting
     */
    private static function definitions(mixed $old, mixed $new, string $path, array $types, bool $fields, array &$neutral, array &$affecting): void
    {
        if (!is_array($old) || !is_array($new)) {
            $affecting[] = ['path' => $path, 'recordTypes' => $types];
            return;
        }
        $left = array_column($old, null, 'id');
        $right = array_column($new, null, 'id');
        if (array_keys($left) !== array_keys($right) && array_diff(array_keys($left), array_keys($right)) === [] && array_diff(array_keys($right), array_keys($left)) === []) {
            $affecting[] = ['path' => $path, 'recordTypes' => $types];
        }
        foreach (self::keys($left, $right) as $id) {
            $affected = $fields ? $types : [$id];
            $entry = $path . '/' . $id;
            if (!isset($left[$id], $right[$id])) {
                $affecting[] = ['path' => $entry, 'recordTypes' => $affected];
                continue;
            }
            foreach (self::keys($left[$id], $right[$id]) as $key) {
                if (self::same($left[$id], $right[$id], $key)) {
                    continue;
                }
                $location = $entry . '/' . $key;
                $presentation = $fields ? ['label', 'hint', 'editorialSection'] : ['label', 'singularLabel', 'renderer', 'routePrefix', 'routePath'];
                if (in_array($key, $presentation, true)) {
                    $neutral[] = $location;
                } elseif (!$fields && $key === 'fields') {
                    self::definitions($left[$id][$key], $right[$id][$key], $location, $affected, true, $neutral, $affecting);
                } elseif ($fields && $key === 'settings' && is_array($left[$id][$key]) && is_array($right[$id][$key])) {
                    foreach (self::keys($left[$id][$key], $right[$id][$key]) as $setting) {
                        if (self::same($left[$id][$key], $right[$id][$key], $setting)) {
                            continue;
                        }
                        if ($setting === 'blockTypes' && ($left[$id]['type'] ?? null) === 'blocks' && ($right[$id]['type'] ?? null) === 'blocks') {
                            self::blocks($left[$id][$key][$setting] ?? [], $right[$id][$key][$setting] ?? [], $location . '/blockTypes', $affected, $neutral, $affecting);
                        } elseif ($setting === 'fields') {
                            self::definitions($left[$id][$key][$setting] ?? [], $right[$id][$key][$setting] ?? [], $location . '/fields', $affected, true, $neutral, $affecting);
                        } elseif (in_array($setting, ['presentation', 'itemLabel'], true)) {
                            // How the editor lays out and names group items; saved items are unchanged.
                            $neutral[] = $location . '/' . $setting;
                        } else {
                            $affecting[] = ['path' => $location . '/' . $setting, 'recordTypes' => $affected];
                        }
                    }
                } else {
                    $affecting[] = ['path' => $location, 'recordTypes' => $affected];
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $old
     * @param list<array<string, mixed>> $new
     * @param list<string> $types
     * @param list<string> $neutral
     * @param list<array{path: string, recordTypes: list<string>}> $affecting
     */
    private static function blocks(array $old, array $new, string $path, array $types, array &$neutral, array &$affecting): void
    {
        $left = array_column($old, null, 'id');
        $right = array_column($new, null, 'id');
        foreach (self::keys($left, $right) as $id) {
            $entry = $path . '/' . $id;
            if (!isset($right[$id])) {
                $affecting[] = ['path' => $entry, 'recordTypes' => $types];
            } elseif (!isset($left[$id])) {
                $neutral[] = $entry;
            } else {
                foreach (self::keys($left[$id], $right[$id]) as $key) {
                    if (self::same($left[$id], $right[$id], $key)) {
                        continue;
                    }
                    if (in_array($key, ['label', 'itemLabel'], true)) {
                        $neutral[] = $entry . '/' . $key;
                    } elseif ($key === 'fields') {
                        self::definitions($left[$id][$key], $right[$id][$key], $entry . '/fields', $types, true, $neutral, $affecting);
                    } else {
                        $affecting[] = ['path' => $entry . '/' . $key, 'recordTypes' => $types];
                    }
                }
            }
        }
    }

    /** Neutral subtrees have an explicit key inventory too; future additions are not silently allowed. */
    private static function knownNeutral(string $context, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if ($context === 'defaults') {
            // Site wording defaults are text per language; saved wording that differs stays the installation's.
            return is_array($value) && array_filter($value, static fn(mixed $text, int|string $locale): bool => !is_string($locale) || !is_string($text), ARRAY_FILTER_USE_BOTH) === [];
        }
        $lists = ['assets' => 'asset', 'renderers' => 'renderer', 'navigation' => 'navigationItem', 'fonts' => 'font', 'faces' => 'face', 'responsive' => 'threshold', 'typography' => 'role', 'spacing' => 'space', 'overrides' => 'override', 'wording' => 'wordingEntry'];
        if (isset($lists[$context])) {
            if (!is_array($value) || !array_is_list($value)) {
                return false;
            }
            foreach ($value as $item) {
                if (!self::knownNeutral($lists[$context], $item)) {
                    return false;
                }
            }
            return true;
        }
        $objects = [
            'asset' => ['path', 'role', 'mime', 'byteSize', 'sha256'], 'renderer' => ['id', 'kind', 'entrypoint'],
            'navigationItem' => ['id', 'label', 'targetKind', 'targetId', 'position'],
            'wordingEntry' => ['key', 'label', 'purpose', 'kind', 'maximumLength', 'uniqueIn', 'defaults'],
            'designRegistries' => ['formatVersion', 'profileId', 'revision', 'fonts', 'responsive', 'typography', 'spacing'],
            'font' => ['id', 'label', 'origin', 'sha256', 'byteSize', 'sourceSha256', 'provenance', 'license', 'faces'],
            'face' => ['id', 'label', 'origin', 'sha256', 'byteSize', 'sourceSha256', 'provenance', 'license', 'style', 'weightMin', 'weightMax'],
            'threshold' => ['id', 'label', 'minWidth'], 'role' => ['id', 'label', 'fontId', 'fallback', 'weight', 'lineHeight', 'size', 'overrides'],
            'space' => ['id', 'label', 'size'], 'override' => ['thresholdId', 'size'], 'size' => ['min', 'max', 'from', 'to'],
        ];
        if (!isset($objects[$context])) {
            return !is_array($value);
        }
        if (!is_array($value) || array_diff(array_keys($value), $objects[$context]) !== []) {
            return false;
        }
        foreach ($value as $key => $child) {
            $nested = in_array($key, ['fonts', 'faces', 'responsive', 'typography', 'spacing', 'size', 'overrides', 'defaults'], true);
            if ($nested ? !self::knownNeutral($key, $child) : is_array($child)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return list<string>
     */
    private static function keys(array $old, array $new): array
    {
        $keys = array_values(array_unique([...array_keys($old), ...array_keys($new)]));
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private static function same(array $old, array $new, string $key): bool
    {
        return array_key_exists($key, $old) === array_key_exists($key, $new) && ($old[$key] ?? null) === ($new[$key] ?? null);
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function orderedObjects(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::orderedObjects($item);
            }
        }
        unset($item);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return list<string>
     */
    private static function typeIds(array $old, array $new): array
    {
        $ids = [];
        foreach ([$old, $new] as $document) {
            foreach (['recordTypes', 'singletons', 'taxonomyNamespaces'] as $key) {
                foreach ($document[$key] ?? [] as $type) {
                    $ids[] = $type['id'];
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        return $ids;
    }
}
