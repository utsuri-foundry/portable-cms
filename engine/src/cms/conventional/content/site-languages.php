<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;

/**
 * The installation's choice for each language the profile supports: public, in preparation or off,
 * and one public primary language. Turning a language off hides it; its text is never deleted.
 */
final readonly class SiteLanguages
{
    public const STATES = ['public', 'preparation', 'off'];

    /** At most as many languages as a profile may list. */
    public const MAXIMUM = 16;

    /** @param array<string, string> $states Language to state, in profile order. */
    private function __construct(public string $profileId, public int $revision, public string $primary, public array $states) {}

    /**
     * A choice read strictly: the profile's own languages first, in order, then the catalog languages
     * the installation added within the profile's envelope.
     */
    public static function from(mixed $value, ProfileManifest $profile): self
    {
        $listed = $profile->listed();
        $locales = array_map(static fn(mixed $entry): mixed => is_array($entry) ? ($entry['locale'] ?? null) : null, is_array($value) && is_array($value['languages'] ?? null) ? array_values($value['languages']) : []);
        foreach ($locales as $index => $locale) {
            if (!is_string($locale) || ($index < count($listed) ? $locale !== $listed[$index] : !self::addable($profile, $locale))) {
                throw new InvalidInput('languages.invalid');
            }
        }
        return count($locales) < count($listed) ? throw new InvalidInput('languages.invalid') : self::exact($value, $profile->id, $locales);
    }

    /**
     * A choice that names exactly $locales, in order, as a normalized export carries it beside its languages.
     * @param list<string> $locales
     */
    public static function exact(mixed $value, string $profileId, array $locales): self
    {
        if (!is_array($value) || count($value) !== 5 || ($value['formatVersion'] ?? null) !== 1
            || ($value['profileId'] ?? null) !== $profileId
            || !is_int($value['revision'] ?? null) || $value['revision'] < 0 || $value['revision'] > 4_294_967_294
            || !is_string($value['primary'] ?? null)
            || !is_array($value['languages'] ?? null) || !array_is_list($value['languages'])
            || count($value['languages']) !== count($locales) || count($locales) > self::MAXIMUM || count(array_unique($locales)) !== count($locales)) {
            throw new InvalidInput('languages.invalid');
        }
        $states = [];
        foreach ($value['languages'] as $index => $entry) {
            if (!is_array($entry) || count($entry) !== 2 || ($entry['locale'] ?? null) !== $locales[$index]
                || !in_array($entry['state'] ?? null, self::STATES, true)) {
                throw new InvalidInput('languages.invalid');
            }
            $states[$entry['locale']] = $entry['state'];
        }
        if (($states[$value['primary']] ?? null) !== 'public') {
            throw new InvalidInput('languages.primary_public');
        }
        return new self($profileId, $value['revision'], $value['primary'], $states);
    }

    /** Every listed language public and the profile's default language primary, as before this setting existed. */
    public static function defaults(ProfileManifest $profile): self
    {
        return new self($profile->id, 0, $profile->defaultLocale, array_fill_keys($profile->listed(), 'public'));
    }

    /**
     * A stored choice read under the installed profile. A profile release that changed its languages
     * keeps the choice for the languages it still supports and makes new ones public; an added language
     * stays while the envelope admits it, and a primary language no longer supported gives way to the
     * first public one.
     */
    public static function stored(mixed $value, ProfileManifest $profile): self
    {
        try {
            return self::from($value, $profile);
        } catch (InvalidInput) {
            $locales = $profile->listed();
            foreach (array_keys(self::previous($value)) as $locale) {
                if (count($locales) < self::MAXIMUM && !in_array((string) $locale, $locales, true) && self::addable($profile, (string) $locale)) {
                    $locales[] = (string) $locale;
                }
            }
            return self::scoped($value, $profile->id, $locales);
        }
    }

    /**
     * A stored choice read for an installation's known languages, in order: a language the choice
     * does not name is public, and a primary language outside them gives way to the first public one.
     * @param list<string> $locales
     */
    public static function scoped(mixed $value, string $profileId, array $locales): self
    {
        $previous = self::previous($value);
        $states = [];
        foreach ($locales as $locale) {
            $states[$locale] = $previous[$locale] ?? 'public';
        }
        $primary = is_array($value) && is_string($value['primary'] ?? null) && ($states[$value['primary']] ?? null) === 'public'
            ? $value['primary'] : (array_search('public', $states, true) ?: $locales[0]);
        $states[$primary] = 'public';
        $revision = is_array($value) && is_int($value['revision'] ?? null) && $value['revision'] >= 0 && $value['revision'] <= 4_294_967_294 ? $value['revision'] : 0;
        return new self($profileId, $revision, (string) $primary, $states);
    }

    /** @return array<string, string> */
    private static function previous(mixed $value): array
    {
        $previous = [];
        foreach (is_array($value) && is_array($value['languages'] ?? null) ? $value['languages'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['locale'] ?? null) && in_array($entry['state'] ?? null, self::STATES, true)) {
                $previous[$entry['locale']] = $entry['state'];
            }
        }
        return $previous;
    }

    /** Whether the installation may hold $locale beside the profile's own languages. */
    public static function addable(ProfileManifest $profile, string $locale): bool
    {
        $language = LanguageCatalog::language($locale);
        return $language !== null && !in_array($locale, $profile->listed(), true) && $profile->envelope?->admits($language) === true;
    }

    /** @return list<string> The catalog languages the installation added, in the order they were added. */
    public function added(ProfileManifest $profile): array
    {
        return array_values(array_diff(array_map('strval', array_keys($this->states)), $profile->listed()));
    }

    /** The profile with the installation's added languages, as records, editing and publication use it. */
    public function installed(ProfileManifest $profile): ProfileManifest
    {
        return $profile->withLanguages($this->added($profile));
    }

    /** A copy with $locale added in preparation, or removed. */
    public function changed(string $locale, bool $add): self
    {
        $states = $this->states;
        if ($add) {
            $states[$locale] = 'preparation';
        } else {
            unset($states[$locale]);
        }
        return new self($this->profileId, $this->revision + 1, $this->primary, $states);
    }

    /** @return array{formatVersion: int, profileId: string, revision: int, primary: string, languages: list<array{locale: string, state: string}>} */
    public function document(): array
    {
        $languages = [];
        foreach ($this->states as $locale => $state) {
            $languages[] = ['locale' => (string) $locale, 'state' => $state];
        }
        return ['formatVersion' => 1, 'profileId' => $this->profileId, 'revision' => $this->revision, 'primary' => $this->primary, 'languages' => $languages];
    }

    /** @return list<string> Languages editors work in: public and in preparation. */
    public function editing(): array
    {
        return $this->with(['public', 'preparation']);
    }

    /** @return list<string> */
    public function public(): array
    {
        return $this->with(['public']);
    }

    /** @return list<string> */
    public function preparation(): array
    {
        return $this->with(['preparation']);
    }

    /** @return list<string> */
    public function off(): array
    {
        return $this->with(['off']);
    }

    /** @return list<string> Languages this choice turns off that were not off before. */
    public function turnedOff(self $before): array
    {
        return array_values(array_filter($this->off(), static fn(string $locale): bool => ($before->states[$locale] ?? 'public') !== 'off'));
    }

    /**
     * The profile as record editing sees it: off languages hidden, languages in preparation marked,
     * and the primary language first in place of the profile's default.
     */
    public function editingProfile(ProfileManifest $profile): ProfileManifest
    {
        $editing = $this->editing();
        if ($editing === $profile->locales && $this->primary === $profile->defaultLocale && $this->preparation() === []) {
            return $profile;
        }
        $definitions = [];
        foreach ($profile->localeDefinitions as $definition) {
            if (in_array($definition->id, $editing, true)) {
                $definitions[] = new LocaleDefinition($definition->id, $definition->label, $this->states[$definition->id] === 'preparation');
            }
        }
        return new ProfileManifest(
            $profile->id,
            $profile->version,
            $profile->compatibility,
            $editing,
            $definitions,
            $this->primary,
            $profile->types,
            $profile->navigation,
            $profile->renderers,
            $profile->assets,
            $profile->capabilities,
            $profile->privacy,
            $profile->designRegistries,
            $profile->supported(),
            $profile->wording,
            $profile->envelope,
            $profile->addedLocales,
            $profile->contentFeed,
        );
    }

    /**
     * Restores the text of off languages from the saved record into values read from a form that
     * showed only the editing languages, so saving never changes a language that is off.
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $saved
     * @param list<string> $off
     * @return array<string, mixed>
     */
    public static function keep(RecordTypeDefinition $type, array $values, ?array $saved, array $off): array
    {
        if ($off === [] || $saved === null) {
            return $values;
        }
        foreach ($type->fields as $field) {
            if (!array_key_exists($field->id, $values) || !array_key_exists($field->id, $saved)) {
                continue;
            }
            $encoded = is_string($values[$field->id]) && in_array($field->type, ['group', 'repeatable_group', 'blocks', 'web_address', 'video_reference', 'vimeo_video'], true);
            $value = $encoded ? EditorialControls::structured($values[$field->id]) : $values[$field->id];
            $kept = self::field($field, $value, $saved[$field->id], $off);
            // A field without text to restore keeps its submitted form, so an empty value stays empty.
            if ($kept !== $value) {
                $values[$field->id] = $encoded ? ($kept === null ? '' : json_encode($kept, JSON_THROW_ON_ERROR)) : $kept;
            }
        }
        return $values;
    }

    /** @param list<string> $off */
    private static function field(FieldDefinition $field, mixed $value, mixed $saved, array $off): mixed
    {
        if ($field->type === 'composition') {
            return self::captions($value, $saved, $off);
        }
        if ($field->localized && in_array($field->type, ['localized_short_text', 'short_text', 'multiline_text', 'rich_text', 'web_address', 'video_reference', 'vimeo_video', 'group', 'repeatable_group', 'blocks'], true)) {
            if (!is_array($saved)) {
                return $value;
            }
            $value = is_array($value) ? $value : [];
            foreach ($off as $locale) {
                if (array_key_exists($locale, $saved)) {
                    $value[$locale] = $saved[$locale];
                }
            }
            return $value;
        }
        if ($field->type === 'blocks' && is_array($value) && is_array($saved)) {
            foreach ($value as $position => $item) {
                if (!is_array($item) || !isset($field->blockTypes[$item['typeId'] ?? '']) || !is_array($item['values'] ?? null)) {
                    continue;
                }
                foreach ($saved as $candidate) {
                    if (!is_array($candidate) || ($candidate['_itemId'] ?? null) !== ($item['_itemId'] ?? null) || ($candidate['typeId'] ?? null) !== $item['typeId']) {
                        continue;
                    }
                    foreach ($field->blockTypes[$item['typeId']]->fields as $child) {
                        if (array_key_exists($child->id, $item['values']) && array_key_exists($child->id, $candidate['values'] ?? [])) {
                            $value[$position]['values'][$child->id] = self::field($child, $item['values'][$child->id], $candidate['values'][$child->id], $off);
                        }
                    }
                }
            }
            return $value;
        }
        if (!in_array($field->type, ['group', 'repeatable_group'], true) || !is_array($value) || !is_array($saved)) {
            return $value;
        }
        // An untranslated group keeps the translated text of its children item by item.
        $items = $field->type === 'group' ? [$value] : $value;
        $savedItems = $field->type === 'group' ? [$saved] : $saved;
        foreach ($items as $position => $item) {
            if (!is_array($item)) {
                continue;
            }
            $match = $field->type === 'group' ? $savedItems[0] : null;
            foreach ($field->type === 'group' ? [] : $savedItems as $candidate) {
                if (is_array($candidate) && isset($item['_itemId']) && ($candidate['_itemId'] ?? null) === $item['_itemId']) {
                    $match = $candidate;
                }
            }
            if (!is_array($match)) {
                continue;
            }
            foreach ($field->fields as $child) {
                if (array_key_exists($child->id, $item) && array_key_exists($child->id, $match)) {
                    $item[$child->id] = self::field($child, $item[$child->id], $match[$child->id], $off);
                }
            }
            $items[$position] = $item;
        }
        return $field->type === 'group' ? $items[0] : $items;
    }

    /**
     * Media captions of off languages stay with the same image in the same place of the layout.
     * @param list<string> $off
     */
    private static function captions(mixed $value, mixed $saved, array $off): mixed
    {
        $value = is_string($value) ? EditorialControls::structured($value) : $value;
        if (!is_array($value) || !is_array($value['rows'] ?? null) || !is_array($saved) || !is_array($saved['rows'] ?? null)) {
            return $value;
        }
        $savedColumns = [];
        foreach ($saved['rows'] as $row) {
            foreach (is_array($row) && is_array($row['columns'] ?? null) ? $row['columns'] : [] as $index => $column) {
                $content = is_array($column) ? ($column['content'] ?? null) : null;
                if (is_array($content) && ($content['kind'] ?? null) === 'media' && is_array($content['captions'] ?? null)) {
                    $savedColumns[($row['id'] ?? '') . ':' . $index . ':' . ($content['id'] ?? '')] = $content['captions'];
                }
            }
        }
        foreach ($value['rows'] as $r => $row) {
            foreach (is_array($row) && is_array($row['columns'] ?? null) ? $row['columns'] : [] as $index => $column) {
                $content = is_array($column) ? ($column['content'] ?? null) : null;
                $captions = is_array($content) && ($content['kind'] ?? null) === 'media' ? ($savedColumns[($row['id'] ?? '') . ':' . $index . ':' . ($content['id'] ?? '')] ?? null) : null;
                if ($captions === null) {
                    continue;
                }
                foreach ($off as $locale) {
                    if (is_string($captions[$locale] ?? null)) {
                        $value['rows'][$r]['columns'][$index]['content']['captions'][$locale] = $captions[$locale];
                        $value['formatVersion'] = max(2, (int) ($value['formatVersion'] ?? 1));
                    }
                }
            }
        }
        return $value;
    }

    /**
     * @param list<string> $states
     * @return list<string>
     */
    private function with(array $states): array
    {
        return array_values(array_map('strval', array_keys(array_filter($this->states, static fn(string $state): bool => in_array($state, $states, true)))));
    }
}

interface SiteLanguagesRepository
{
    public function load(ProfileManifest $profile): SiteLanguages;

    /**
     * @param array<string, mixed> $detail Audit details beside the saved choice.
     * @throws SiteLanguagesConflict when the stored revision is no longer $expected
     */
    public function save(SiteLanguages $next, int $expected, string $actorId, string $auditId, \DateTimeImmutable $now, string $event = 'site_languages_saved', array $detail = []): void;

    /** Whether any record revision, media description, site wording or holding message has text in $locale. */
    public function hasText(ProfileManifest $profile, string $locale): bool;
}

final class SiteLanguagesConflict extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Site languages changed while editing');
    }
}

final readonly class SiteLanguagesService
{
    public function __construct(private SiteLanguagesRepository $repository, private ProfileManifest $profile, private Clock $clock, private RandomSource $random) {}

    public function load(): SiteLanguages
    {
        return $this->repository->load($this->profile);
    }

    /** @return list<Language> Catalog languages the installation can add now. */
    public function addable(): array
    {
        $current = $this->load();
        return count($current->states) >= SiteLanguages::MAXIMUM ? []
            : array_values(array_filter($this->profile->addable(), static fn(Language $language): bool => !isset($current->states[$language->id])));
    }

    /**
     * @param array<string, string> $states
     * @throws InvalidInput|SiteLanguagesConflict
     */
    public function save(array $states, string $primary, int $expected, SessionContext $actor, bool $confirmed): SiteLanguages
    {
        $current = $this->current($expected, $actor);
        $next = SiteLanguages::from(['formatVersion' => 1, 'profileId' => $this->profile->id, 'revision' => $expected + 1, 'primary' => $primary,
            'languages' => array_map(static fn(string $locale): array => ['locale' => $locale, 'state' => $states[$locale] ?? ''], array_map('strval', array_keys($current->states)))], $this->profile);
        if ($next->turnedOff($current) !== [] && !$confirmed) {
            throw new InvalidInput('languages.confirm_off');
        }
        $this->repository->save($next, $expected, (string) $actor->userId, bin2hex($this->random->bytes(16)), $this->clock->now());
        return $next;
    }

    /**
     * Adds a catalog language inside the profile's envelope, in preparation and without text.
     * @throws InvalidInput|SiteLanguagesConflict
     */
    public function add(string $locale, int $expected, SessionContext $actor): SiteLanguages
    {
        $current = $this->current($expected, $actor);
        if (!in_array($locale, array_map(static fn(Language $language): string => $language->id, $this->addable()), true)) {
            throw new InvalidInput('languages.add_invalid');
        }
        $next = $current->changed($locale, true);
        $this->repository->save($next, $expected, (string) $actor->userId, bin2hex($this->random->bytes(16)), $this->clock->now(), 'site_language_added', ['locale' => $locale]);
        return $next;
    }

    /**
     * Removes a language the installation added, while nothing has been written in it.
     * @throws InvalidInput|SiteLanguagesConflict
     */
    public function remove(string $locale, int $expected, SessionContext $actor): SiteLanguages
    {
        $current = $this->current($expected, $actor);
        if (!in_array($locale, $current->added($this->profile), true)) {
            throw new InvalidInput('languages.remove_invalid');
        }
        $language = ['language' => LanguageCatalog::language($locale)->name ?? $locale];
        if ($locale === $current->primary) {
            throw new InvalidInput('languages.remove_primary', $language);
        }
        if ($this->repository->hasText($current->installed($this->profile), $locale)) {
            throw new InvalidInput('languages.remove_text', $language);
        }
        $next = $current->changed($locale, false);
        $this->repository->save($next, $expected, (string) $actor->userId, bin2hex($this->random->bytes(16)), $this->clock->now(), 'site_language_removed', ['locale' => $locale]);
        return $next;
    }

    /** @throws InvalidInput|SiteLanguagesConflict */
    private function current(int $expected, SessionContext $actor): SiteLanguages
    {
        if (!$actor->administrator() || $actor->userId === null) {
            throw new InvalidInput('languages.not_allowed');
        }
        $current = $this->load();
        if ($expected !== $current->revision) {
            throw new SiteLanguagesConflict();
        }
        return $current;
    }
}
