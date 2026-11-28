<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Compiler\CompiledRoute;
use Utsuri\PortableCms\Conventional\I18n\InterfaceLanguage;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\TextNormalizer;

/** Finds the records of a type whose current slug, in one language, is exactly a value. */
interface RecordSlugLookup
{
    /** A record other than `$exceptRecordId` whose slug text (in `$locale`, or the untranslated slug when null) is `$slug`. */
    public function slugOwner(string $typeId, ?string $locale, string $slug, ?string $exceptRecordId): ?RecordSnapshot;
}

/** Writes a copy of a record as a new draft, with the source named in the audit event. */
interface RecordDuplicationRepository
{
    /**
     * @param array<string, mixed> $body
     * @param list<RelationshipProjection> $relationships
     */
    public function duplicate(RecordSnapshot $source, string $recordId, string $revisionId, string $profileId, string $profileVersion, array $body, array $relationships, string $actorUserId, string $auditId, \DateTimeImmutable $now): RecordSnapshot;
}

/**
 * A record's address segment comes from its `slug` field, as `ProfileRoutes` builds it. A slug an
 * editor writes is one safe segment (lowercase letters, digits and single hyphens) that no other
 * record of the same type uses in that language.
 */
final readonly class RecordSlugs
{
    public const FIELD = 'slug';
    /** Copies try `-2` up to this suffix before giving up. */
    private const COPY_ATTEMPTS = 200;

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

    /**
     * A copy's body: every value kept, the title marked as a copy in each language and each slug
     * the original's plus the first free `-2`, `-3` and so on.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function copy(RecordTypeDefinition $type, array $body): array
    {
        $title = self::titleField($type);
        if ($title !== null && $title->localized && is_array($body[$title->id] ?? null)) {
            foreach ($body[$title->id] as $locale => $text) {
                if (is_string($text)) {
                    $body[$title->id][$locale] = $this->copyTitle($text, (string) $locale, (int) $title->maximumLength);
                }
            }
        } elseif ($title !== null && is_string($body[$title->id] ?? null)) {
            $body[$title->id] = $this->copyTitle($body[$title->id], $this->profile->defaultLocale, (int) $title->maximumLength);
        }
        $field = self::field($type);
        if ($field !== null && isset($body[$field->id])) {
            if ($field->localized && is_array($body[$field->id])) {
                foreach ($body[$field->id] as $locale => $slug) {
                    if (is_string($slug) && trim($slug) !== '') {
                        $body[$field->id][$locale] = $this->free($type, (string) $locale, $slug, (int) $field->maximumLength);
                    }
                }
            } elseif (!$field->localized && is_string($body[$field->id]) && trim($body[$field->id]) !== '') {
                $body[$field->id] = $this->free($type, null, $body[$field->id], (int) $field->maximumLength);
            }
        }
        return $body;
    }

    private function free(RecordTypeDefinition $type, ?string $locale, string $slug, int $maximum): string
    {
        $base = TextNormalizer::slug($slug);
        $base = $base === '' ? 'copy' : $base;
        for ($number = 2; $number <= self::COPY_ATTEMPTS; $number++) {
            $suffix = '-' . $number;
            $candidate = rtrim(substr($base, 0, max(1, ($maximum > 0 ? $maximum : strlen($base) + strlen($suffix)) - strlen($suffix))), '-') . $suffix;
            if (!$this->repository instanceof RecordSlugLookup || $this->repository->slugOwner($type->id, $locale, $candidate, null) === null) {
                return $candidate;
            }
        }
        throw new \RuntimeException('No free address remains for the copy');
    }

    /** The title in its own language's words when the administration speaks it, else in English. */
    private function copyTitle(string $title, string $locale, int $maximum): string
    {
        if (trim($title) === '') {
            return $title;
        }
        $language = strtolower(explode('-', $locale)[0]);
        $translator = Translator::load(in_array($language, InterfaceLanguage::SUPPORTED, true) ? $language : Translator::DEFAULT_LOCALE);
        $marked = $translator->text('records.copy_title', ['title' => $title]);
        $over = mb_strlen($marked) - ($maximum > 0 ? $maximum : PHP_INT_MAX);
        return $over > 0 ? $translator->text('records.copy_title', ['title' => rtrim(mb_substr($title, 0, max(1, mb_strlen($title) - $over)))]) : $marked;
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
