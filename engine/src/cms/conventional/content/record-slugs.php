<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Compiler\CompiledRoute;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\TextNormalizer;

/** Finds the records of a type whose current slug, in one language, is exactly a value. */
interface RecordSlugLookup
{
    /** A record other than `$exceptRecordId` whose slug text (in `$locale`, or the untranslated slug when null) is `$slug`. */
    public function slugOwner(string $typeId, ?string $locale, string $slug, ?string $exceptRecordId): ?RecordSnapshot;
}

/**
 * A record's address segment comes from its `slug` field, as `ProfileRoutes` builds it. A slug an
 * editor writes is one safe segment (lowercase letters, digits and single hyphens) that no other
 * record of the same type uses in that language.
 */
final readonly class RecordSlugs
{
    public const FIELD = 'slug';

    public function __construct(private RecordRepository $repository, private ProfileManifest $profile) {}

    /** The slug field of a type whose records have their own pages, or null. */
    public static function field(RecordTypeDefinition $type): ?FieldDefinition
    {
        if ($type->kind === 'singleton') {
            return null;
        }
        foreach ($type->fields as $field) {
            if ($field->id === self::FIELD && in_array($field->type, ['short_text', 'localized_short_text'], true)) {
                return $field;
            }
        }
        return null;
    }

    /** The text field a record is named by, as `RecordLabels` reads it. */
    public static function titleField(RecordTypeDefinition $type): ?FieldDefinition
    {
        foreach (['title', 'name', 'label'] as $id) {
            foreach ($type->fields as $field) {
                if ($field->id === $id && in_array($field->type, ['short_text', 'localized_short_text'], true)) {
                    return $field;
                }
            }
        }
        return null;
    }

    public static function valid(string $slug): bool
    {
        return preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) === 1;
    }

    /**
     * Why the slug in `$values` cannot be saved: a changed value must be a valid segment and free in
     * its language. With `$all`, every language is checked and a saved value only needs to be a
     * route the website accepts, as a record that is about to be published.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $saved
     * @param list<string> $locales Languages checked with `$all`.
     * @return array<string, Message>
     */
    public function problems(RecordTypeDefinition $type, array $values, array $saved, ?string $recordId, bool $all = false, array $locales = []): array
    {
        $field = self::field($type);
        if ($field === null) {
            return [];
        }
        $title = self::titleField($type);
        foreach ($field->localized ? $this->profile->locales : [null] as $locale) {
            if ($locale !== null && $all && !in_array($locale, $locales, true)) {
                continue;
            }
            $value = self::text($values[$field->id] ?? null, $locale);
            $before = self::text($saved[$field->id] ?? null, $locale);
            if (trim($value) === '' || (!$all && $value === $before)) {
                continue;
            }
            $detail = null;
            if (!self::valid($value) && !($value === $before && CompiledRoute::validRoute('/' . $value . '/'))) {
                $example = TextNormalizer::slug($value);
                if ($example === '' && $title !== null) {
                    $example = TextNormalizer::slug(self::text($values[$title->id] ?? null, $title->localized ? ($locale ?? $this->profile->defaultLocale) : null));
                }
                $detail = $example === '' ? new Message('validation.slug.characters') : new Message('validation.slug.characters_example', ['example' => $example]);
            } elseif ($this->repository instanceof RecordSlugLookup) {
                $owner = $this->repository->slugOwner($type->id, $locale, $value, $recordId);
                $detail = $owner === null ? null : new Message('validation.slug.taken', ['record' => RecordLabels::label($owner, $this->profile)]);
            }
            if ($detail !== null) {
                return [$field->id => $locale === null || count($this->profile->locales) === 1
                    ? new Message('validation.detail', ['field' => $field->label, 'detail' => $detail])
                    : new Message('validation.detail_locale', ['field' => $field->label, 'locale' => $this->language($locale), 'detail' => $detail])];
            }
        }
        return [];
    }

    private function language(string $locale): string
    {
        foreach ($this->profile->localeDefinitions as $definition) {
            if ($definition->id === $locale) {
                return $definition->label;
            }
        }
        return $locale;
    }

    private static function text(mixed $value, ?string $locale): string
    {
        $text = $locale === null ? $value : (is_array($value) ? ($value[$locale] ?? null) : null);
        return is_string($text) ? $text : '';
    }
}
