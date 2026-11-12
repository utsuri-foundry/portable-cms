<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RichText;
use Utsuri\PortableCms\Conventional\Content\WebAddress;

/** Deterministic public projection; no clock, release identity, originals or storage paths. */
final readonly class ContentFeedSnapshot
{
    public const ROOT = 'feed/v1/';
    public const MAX_JSON_BYTES = 8 * 1024 * 1024;

    /** @param array<string, mixed> $manifest
     * @param array<string, string> $documents Snapshot-relative JSON paths and bytes.
     * @param array<string, FrozenMedia> $media Public content-addressed derivative paths and sources.
     */
    private function __construct(public array $manifest, public array $documents, public array $media) {}

    public static function build(ProfileManifest $profile, FrozenSnapshot $snapshot): ?self
    {
        $declaration = $profile->contentFeed;
        if ($declaration === null) {
            return null;
        }
        $profile = $profile->carrying($snapshot->locales);
        $states = array_map(static fn(string $locale): array => ['locale' => $locale, 'state' => in_array($locale, $snapshot->locales, true) ? (in_array($locale, $snapshot->preparationLocales, true) ? 'preparation' : 'public') : 'off'], $profile->locales);
        $profile = \Utsuri\PortableCms\Conventional\Content\SiteLanguages::from(['formatVersion' => 1, 'profileId' => $profile->id, 'revision' => 0, 'primary' => $snapshot->defaultLocale, 'languages' => $states], $profile)->editingProfile($profile);
        $lookup = new FrozenBlockValidation(array_column($snapshot->records, null, 'id'));
        $content = new \Utsuri\PortableCms\Conventional\Content\ContentService($lookup, $profile, $lookup, $lookup, publicLocales: static fn(): array => array_values(array_diff($snapshot->locales, $snapshot->preparationLocales)));
        $records = $snapshot->records;
        usort($records, static fn(FrozenRecord $a, FrozenRecord $b): int => strcmp($a->id, $b->id));
        $documents = [];
        $collections = [];
        $index = [];
        $media = [];
        $bodies = [];
        foreach ($records as $record) {
            if ($record->workflowState === 'published' && isset($declaration->types[$record->typeId])) {
                $bodies[$record->id] = $content->validateFeedRecord($record->typeId, $record->body)->body;
            }
        }
        foreach ($declaration->types as $typeId => $selected) {
            $fields = array_column($profile->type($typeId)->fields, null, 'id');
            foreach ($snapshot->locales as $locale) {
                $entries = [];
                foreach ($records as $record) {
                    if ($record->typeId !== $typeId || $record->workflowState !== 'published') {
                        continue;
                    }
                    $values = [];
                    foreach ($selected as $name) {
                        $field = $fields[$name];
                        $raw = $bodies[$record->id][$name] ?? null;
                        $value = $field->localized ? (is_array($raw) ? ($raw[$locale] ?? null) : null) : $raw;
                        if ($field->type === 'media_references') {
                            $value = self::images($value, $snapshot, $media);
                        } elseif ($field->type === 'rich_text' && $value !== null) {
                            $value = RichText::from($value, $field)->document();
                        } elseif ($field->type === 'web_address' && $value !== null) {
                            $value = WebAddress::from($value, $field->maximumLength ?? WebAddress::MAXIMUM_LENGTH)->document();
                        }
                        $values[$name] = $value;
                    }
                    $entry = ['id' => $record->id, 'revisionNumber' => $record->revisionNumber, 'fields' => $values];
                    $entries[] = $entry;
                    $index[] = ['typeId' => $typeId, 'locale' => $locale, 'id' => $record->id, 'revisionNumber' => $record->revisionNumber, 'sha256' => hash('sha256', self::json($entry))];
                }
                // Empty pages are explicit so consumers discover declared types and languages without guessing.
                $chunks = $entries === [] ? [[]] : array_chunk($entries, $declaration->pageSize);
                $pages = [];
                foreach ($chunks as $offset => $chunk) {
                    $page = $offset + 1;
                    $path = $typeId . '/' . $locale . '/' . $page . '.json';
                    $json = self::json(['formatVersion' => 1, 'typeId' => $typeId, 'locale' => $locale, 'page' => $page, 'nextPage' => $page < count($chunks) ? $page + 1 : null, 'records' => $chunk]);
                    $documents[$path] = $json;
                    $pages[] = self::file($path, $json);
                }
                $collections[] = ['typeId' => $typeId, 'locale' => $locale, 'recordCount' => count($entries), 'pages' => $pages];
            }
        }
        ksort($media, SORT_STRING);
        $images = [];
        foreach ($media as $path => $image) {
            $images[] = ['path' => $path, 'mime' => $image->mime, 'byteSize' => $image->byteSize, 'sha256' => $image->checksum];
        }
        $core = ['formatVersion' => 1, 'profileId' => $profile->id, 'locales' => $snapshot->locales, 'collections' => $collections, 'records' => $index, 'media' => $images];
        $manifest = ['snapshotId' => hash('sha256', self::json($core)), ...$core];
        self::validate($manifest);
        return new self($manifest, $documents, $media);
    }

    /** @param array<string, FrozenMedia> $media
     * @return list<array<string, mixed>>
     */
    private static function images(mixed $value, FrozenSnapshot $snapshot, array &$media): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > 64) {
            throw new \InvalidArgumentException('Feed image selection is invalid');
        }
        $images = [];
        foreach ($value as $id) {
            $image = is_string($id) ? $snapshot->media($id) : null;
            if ($image === null || $image->mime !== 'image/webp' || preg_match('/\Adisplay_(480|960|1600|2400)_webp\z/', $image->variant) !== 1) {
                throw new \InvalidArgumentException('Feed media requires a published resized image');
            }
            $path = self::ROOT . 'media/' . $image->checksum . '.webp';
            $media[$path] = $image;
            $images[] = ['url' => ($snapshot->publicUrlContext->baseUrl ?? '') . '/' . $path, 'mime' => $image->mime, 'width' => $image->width, 'height' => $image->height];
        }
        return $images;
    }

    /** @param array<string, mixed> $manifest */
    public static function validate(array $manifest): void
    {
        $core = $manifest;
        unset($core['snapshotId']);
        if (!hash_equals($manifest['snapshotId'], hash('sha256', self::json($core)))) {
            throw new \InvalidArgumentException('Feed snapshot content identity differs');
        }
        $paths = [];
        $records = [];
        foreach ($manifest['collections'] as $collection) {
            foreach ($collection['pages'] as $page => $file) {
                if ($file['path'] !== $collection['typeId'] . '/' . $collection['locale'] . '/' . ($page + 1) . '.json' || isset($paths[$file['path']])) {
                    throw new \InvalidArgumentException('Feed collection paths differ');
                }
                $paths[$file['path']] = true;
            }
        }
        foreach ($manifest['records'] as $record) {
            $key = $record['typeId'] . ':' . $record['locale'] . ':' . $record['id'];
            if (isset($records[$key])) {
                throw new \InvalidArgumentException('Feed record identities repeat');
            }
            $records[$key] = true;
        }
        foreach ($manifest['media'] as $image) {
            if ($image['path'] !== self::ROOT . 'media/' . $image['sha256'] . '.webp' || $image['mime'] !== 'image/webp') {
                throw new \InvalidArgumentException('Feed media is not content addressed');
            }
        }
    }

    public function directory(): string
    {
        return self::directoryFor($this->manifest['snapshotId']);
    }

    public static function directoryFor(string $id): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('Feed snapshot identity is invalid');
        }
        return self::ROOT . 'snapshots/' . $id . '/';
    }

    /** @return array{path: string, byteSize: int, sha256: string} */
    public static function file(string $path, string $json): array
    {
        return ['path' => $path, 'byteSize' => strlen($json), 'sha256' => hash('sha256', $json)];
    }

    public static function json(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new \InvalidArgumentException('Feed JSON exceeds its byte bound');
        }
        return $json;
    }
}
