<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\Content\RichText;
use Utsuri\PortableCms\Conventional\Content\SearchSharing;

/** Resolves the automatic search and sharing values an editor sees beside the override fields. */
final class SearchSharingFeedback
{
    /** The profile group that carries search and sharing overrides, if this type has one. */
    public static function field(RecordTypeDefinition $type): ?FieldDefinition
    {
        foreach ($type->fields as $field) {
            if ($field->type === 'group' && is_array($field->settings['searchSharing'] ?? null)) {
                return $field;
            }
        }
        return null;
    }

    /**
     * Effective, illustrative values per locale from explicit trusted profile source mappings.
     * A locale maps to null when unsaved input is incomplete; that input stays in the form.
     *
     * @param array<string, mixed> $values
     * @return array<string, array{metadata: SearchSharing, siteName: string, searchTitle: string, description: string, sharingTitle: string, sharingDescription: string, sources: array<string, string>, image: ?\Utsuri\PortableCms\Conventional\Media\LibraryMedia, imageSource: string}|null>
     */
    public static function resolve(RecordTypeDefinition $type, FieldDefinition $field, array $values, ProfileManifest $profile, ContentService $content, ?\Utsuri\PortableCms\Conventional\Media\MediaLibrary $media = null): array
    {
        $mapping = $field->settings['searchSharing'];
        $settingsType = $profile->type($mapping['settingsType']);
        if ($settingsType === null || $settingsType->kind !== 'singleton') {
            throw new \InvalidArgumentException('Metadata settings source is not a singleton');
        }
        $settings = $content->list($settingsType->id);
        $settingsBody = count($settings) === 1 ? $settings[0]->body : [];
        if ($type->id === $settingsType->id) {
            $settingsBody = $values;
        }
        $siteName = ($mapping['siteNameSource'] ?? null) === 'website-settings' ? $content->websiteIdentity()->name : ($settingsBody[$mapping['siteNameField']] ?? '');
        $siteName = is_string($siteName) ? $siteName : '';
        $format = $settingsBody[$mapping['titleFormatField']] ?? 'title-site';
        $overrides = EditorialControls::structured($values[$field->id] ?? null);
        $resolved = [];
        foreach ($profile->localeDefinitions as $locale) {
            try {
                $title = isset($mapping['titleField']) ? self::text($type, $values, $mapping['titleField'], $locale->id) : ($mapping['titleFallback'][$locale->id] ?? $siteName);
                $description = isset($mapping['descriptionField']) ? self::text($type, $values, $mapping['descriptionField'], $locale->id) : '';
                $raw = $field->localized && is_array($overrides) ? ($overrides[$locale->id] ?? null) : $overrides;
                $metadata = SearchSharing::from($raw, ($mapping['indexing'] ?? null) === 'excluded');
                $defaultDescription = isset($mapping['defaultDescriptionField']) ? self::text($settingsType, $settingsBody, $mapping['defaultDescriptionField'], $locale->id) : '';
                $effective = $metadata->resolve($title, $description, $siteName, is_string($format) && $format !== '' ? $format : 'title-site', $defaultDescription);
                $default = isset($mapping['defaultImageField']) ? (EditorialControls::selections($settingsBody[$mapping['defaultImageField']] ?? null)[0] ?? null) : null;
                $cover = isset($mapping['coverImageField']) ? self::cover($type, $values, $mapping['coverImageField'], $profile) : null;
                if ($cover === null && isset($mapping['coverFallbackField'])) {
                    $cover = self::cover($type, $values, $mapping['coverFallbackField'], $profile);
                }
                $selection = $metadata->resolveImage($cover, $default);
                $resolved[$locale->id] = [
                    'metadata' => $metadata,
                    'siteName' => $siteName,
                    'searchTitle' => $effective->searchTitle,
                    'description' => $effective->description,
                    'sharingTitle' => $effective->sharingTitle,
                    'sharingDescription' => $effective->sharingDescription,
                    'sources' => $effective->sources,
                    'image' => $selection->mediaId === null ? null : $media?->find($selection->mediaId),
                    'imageSource' => $selection->source,
                ];
            } catch (\InvalidArgumentException) {
                $resolved[$locale->id] = null;
            }
        }
        return $resolved;
    }
    /** @param array<string, mixed> $values */
    private static function cover(RecordTypeDefinition $type, array $values, string $id, ProfileManifest $profile): ?string
    {
        foreach ($type->fields as $field) {
            if ($field->id !== $id) {
                continue;
            }
            if ($field->type === 'media_references' && !$field->localized) {
                return EditorialControls::selections($values[$id] ?? null)[0] ?? null;
            }
            if ($field->type === 'composition' && !$field->localized) {
                $registry = $profile->designRegistries;
                return \Utsuri\PortableCms\Conventional\Content\Composition::from(EditorialControls::structured($values[$id] ?? null), $field, $type, $registry)->mediaIds()[0] ?? null;
            }
        }
        throw new \InvalidArgumentException('Metadata image source field is unavailable');
    }

    /** @param array<string, mixed> $values */
    private static function text(RecordTypeDefinition $type, array $values, string $id, string $locale): string
    {
        $field = null;
        foreach ($type->fields as $candidate) {
            if ($candidate->id === $id) {
                $field = $candidate;
            }
        }
        if (!$field instanceof FieldDefinition) {
            throw new \InvalidArgumentException('Metadata source field is unavailable');
        }
        $value = $values[$id] ?? null;
        if ($field->localized) {
            $value = is_array($value) ? ($value[$locale] ?? null) : null;
        }
        if ($field->type === 'rich_text') {
            return $value === null ? '' : RichText::from($value, $field)->excerpt();
        }
        if (!in_array($field->type, ['short_text', 'localized_short_text', 'multiline_text'], true)) {
            throw new \InvalidArgumentException('Metadata text source type is unavailable');
        }
        return is_string($value) ? $value : '';
    }
}
