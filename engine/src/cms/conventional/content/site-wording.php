<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;

/**
 * One fixed text a profile's pages show, such as a navigation link, a caption or an address
 * segment, declared in the manifest with defaults for the profile's initial languages.
 */
final readonly class WordingDefinition
{
    public const KINDS = ['text', 'address_segment'];
    public const MAXIMUM_TEXT = 500;
    public const MAXIMUM_SEGMENT = 80;

    /** @param array<string, string> $defaults Language to text. */
    public function __construct(
        public string $key,
        public string $label,
        public string $purpose,
        public string $kind,
        public int $maximumLength,
        public array $defaults,
        public ?string $uniqueIn = null,
    ) {
        if (!self::validKey($key) || !ProfileManifest::label($label) || $purpose === '' || mb_strlen($purpose, 'UTF-8') > 300
            || preg_match('/[\x00-\x1f\x7f]/u', $purpose) === 1 || !in_array($kind, self::KINDS, true)
            || $maximumLength < 1 || $maximumLength > ($kind === 'text' ? self::MAXIMUM_TEXT : self::MAXIMUM_SEGMENT)
            || ($uniqueIn !== null && ($kind !== 'address_segment' || !ProfileManifest::canonicalId($uniqueIn)))
            || count($defaults) > 16) {
            throw new \InvalidArgumentException('Profile wording declaration is invalid');
        }
        foreach ($defaults as $locale => $value) {
            if (!is_string($locale) || !ProfileManifest::localeId($locale) || !is_string($value) || $this->problem($value) !== null) {
                throw new \InvalidArgumentException('Profile wording default is invalid');
            }
        }
    }

    public static function validKey(string $key): bool
    {
        return strlen($key) <= 100 && preg_match('/\A[a-z][A-Za-z0-9]*(?:\.[a-z][A-Za-z0-9]*)*\z/', $key) === 1;
    }

    /**
     * Why a text cannot be this wording, or null when it can. An address segment is one safe path
     * segment that is never a language prefix of the profile.
     * @param list<string> $languages
     */
    public function problem(string $value, array $languages = []): ?Message
    {
        if ($this->kind === 'address_segment') {
            if (strlen($value) > $this->maximumLength || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value) !== 1) {
                return new Message('wording.segment_invalid', ['maximum' => $this->maximumLength]);
            }
            return in_array($value, $languages, true) ? new Message('wording.segment_language') : null;
        }
        if ($value === '' || trim($value) !== $value || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/u', $value) === 1) {
            return new Message('wording.text_invalid');
        }
        return mb_strlen($value, 'UTF-8') > $this->maximumLength ? new Message('wording.too_long', ['maximum' => $this->maximumLength]) : null;
    }
}

/**
 * The installation's site wording: only the texts that differ from the profile's defaults, per
 * language. Texts of languages that are off are kept, like the text of records.
 */
final readonly class SiteWording
{
    /** @param array<string, array<string, string>> $values Key to language to text, in profile order. */
    private function __construct(public string $profileId, public int $revision, public array $values) {}

    public static function defaults(ProfileManifest $profile): self
    {
        return new self($profile->id, 0, []);
    }

    /**
     * Texts that differ from the defaults, keeping only those the profile accepts.
     * @param array<string, array<string, string>> $values Key to language to text.
     */
    public static function of(ProfileManifest $profile, int $revision, array $values): self
    {
        return self::stored(['formatVersion' => 1, 'profileId' => $profile->id, 'revision' => $revision, 'values' => self::listed($values)], $profile);
    }

    /** Reads a portable document strictly: every text belongs to a declared key and a supported language. */
    public static function from(mixed $document, ProfileManifest $profile): self
    {
        $keys = is_array($document) ? array_keys($document) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['formatVersion', 'profileId', 'revision', 'values'] || $document['formatVersion'] !== 1 || $document['profileId'] !== $profile->id
            || !is_int($document['revision']) || $document['revision'] < 0 || $document['revision'] > 4_294_967_294
            || !is_array($document['values']) || !array_is_list($document['values']) || count($document['values']) > 3_200) {
            throw new \InvalidArgumentException('Site wording document is invalid');
        }
        $values = [];
        foreach ($document['values'] as $entry) {
            $definition = is_array($entry) && is_string($entry['key'] ?? null) ? $profile->wording($entry['key']) : null;
            if ($definition === null || count($entry) !== 3 || !is_string($entry['locale'] ?? null) || !in_array($entry['locale'], $profile->supported(), true)
                || !is_string($entry['text'] ?? null) || isset($values[$entry['key']][$entry['locale']])
                || $definition->problem($entry['text'], $profile->reserved()) !== null) {
                throw new \InvalidArgumentException('Site wording text is invalid');
            }
            $values[$entry['key']][$entry['locale']] = $entry['text'];
        }
        return new self($profile->id, $document['revision'], self::ordered($profile, $values));
    }

    /**
     * The document's structure, checkable without the profile, as an export is before restore
     * reads it under the installed profile.
     */
    public static function assertShape(mixed $document, string $profileId): void
    {
        $keys = is_array($document) ? array_keys($document) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['formatVersion', 'profileId', 'revision', 'values'] || $document['formatVersion'] !== 1 || $document['profileId'] !== $profileId
            || !is_int($document['revision']) || $document['revision'] < 0 || $document['revision'] > 4_294_967_294
            || !is_array($document['values']) || !array_is_list($document['values']) || count($document['values']) > 3_200) {
            throw new \InvalidArgumentException('Site wording document is invalid');
        }
        $seen = [];
        foreach ($document['values'] as $entry) {
            if (!is_array($entry) || count($entry) !== 3 || !is_string($entry['key'] ?? null) || !WordingDefinition::validKey($entry['key'])
                || !is_string($entry['locale'] ?? null) || !ProfileManifest::localeId($entry['locale'])
                || !is_string($entry['text'] ?? null) || $entry['text'] === '' || mb_strlen($entry['text'], 'UTF-8') > WordingDefinition::MAXIMUM_TEXT
                || isset($seen[$entry['key']][$entry['locale']])) {
                throw new \InvalidArgumentException('Site wording text is invalid');
            }
            $seen[$entry['key']][$entry['locale']] = true;
        }
    }

    /**
     * A saved document read under the installed profile. A profile release that removed a key or a
     * language, or narrowed what a key accepts, drops those texts so its defaults apply.
     */
    public static function stored(mixed $document, ProfileManifest $profile): self
    {
        try {
            return self::from($document, $profile);
        } catch (\InvalidArgumentException) {
            $values = [];
            foreach (is_array($document) && is_array($document['values'] ?? null) ? $document['values'] : [] as $entry) {
                $definition = is_array($entry) && is_string($entry['key'] ?? null) ? $profile->wording($entry['key']) : null;
                if ($definition !== null && is_string($entry['locale'] ?? null) && in_array($entry['locale'], $profile->supported(), true)
                    && is_string($entry['text'] ?? null) && $definition->problem($entry['text'], $profile->reserved()) === null) {
                    $values[$entry['key']][$entry['locale']] = $entry['text'];
                }
            }
            $revision = is_array($document) && is_int($document['revision'] ?? null) && $document['revision'] >= 0 && $document['revision'] <= 4_294_967_294 ? $document['revision'] : 0;
            return new self($profile->id, $revision, self::ordered($profile, $values));
        }
    }

    /** @return array{formatVersion: int, profileId: string, revision: int, values: list<array{key: string, locale: string, text: string}>} */
    public function document(): array
    {
        return ['formatVersion' => 1, 'profileId' => $this->profileId, 'revision' => $this->revision, 'values' => self::listed($this->values)];
    }

    /** The text of a key in a language: the installation's own, else the profile's default, else null. */
    public function text(ProfileManifest $profile, string $key, string $locale): ?string
    {
        return $this->values[$key][$locale] ?? $profile->wording($key)?->defaults[$locale] ?? null;
    }

    /** @return array<string, string> Key to text in one language; keys without text are absent. */
    public function texts(ProfileManifest $profile, string $locale): array
    {
        $texts = [];
        foreach ($profile->wording as $definition) {
            $text = $this->text($profile, $definition->key, $locale);
            if ($text !== null) {
                $texts[$definition->key] = $text;
            }
        }
        return $texts;
    }

    /**
     * The texts of these languages as a snapshot carries them.
     * @param list<string> $locales
     * @return list<array{key: string, locale: string, text: string}>
     */
    public function entries(ProfileManifest $profile, array $locales): array
    {
        $entries = [];
        foreach ($profile->wording as $definition) {
            foreach ($locales as $locale) {
                $text = $this->text($profile, $definition->key, $locale);
                if ($text !== null) {
                    $entries[] = ['key' => $definition->key, 'locale' => $locale, 'text' => $text];
                }
            }
        }
        return $entries;
    }

    /** @return list<string> Keys without text in a language. */
    public function missing(ProfileManifest $profile, string $locale): array
    {
        return array_values(array_diff(array_map(static fn(WordingDefinition $definition): string => $definition->key, $profile->wording), array_keys($this->texts($profile, $locale))));
    }

    /** @return list<string> Address segments in a language that another key of the same group also uses. */
    public function taken(ProfileManifest $profile, string $locale): array
    {
        $groups = [];
        foreach ($profile->wording as $definition) {
            $text = $definition->uniqueIn === null ? null : $this->text($profile, $definition->key, $locale);
            if ($text !== null) {
                $groups[$definition->uniqueIn][$text][] = $definition->key;
            }
        }
        $taken = [];
        foreach ($groups as $texts) {
            foreach ($texts as $keys) {
                if (count($keys) > 1) {
                    $taken = [...$taken, ...$keys];
                }
            }
        }
        return $taken;
    }

    /** @return list<string> Keys whose text differs from another wording in any language. */
    public function changed(self $before): array
    {
        $keys = [];
        foreach (array_unique([...array_keys($this->values), ...array_keys($before->values)]) as $key) {
            if (($this->values[$key] ?? []) !== ($before->values[$key] ?? [])) {
                $keys[] = (string) $key;
            }
        }
        return $keys;
    }

    /** The native form field of a key in a language. Keys use dots, which PHP form parsing would rewrite. */
    public static function field(string $key, string $locale): string
    {
        return 'wording__' . str_replace('.', '-', $key) . '__' . $locale;
    }

    /**
     * @param array<string, array<string, string>> $values
     * @return array<string, array<string, string>>
     */
    private static function ordered(ProfileManifest $profile, array $values): array
    {
        $ordered = [];
        foreach ($profile->wording as $definition) {
            foreach ($profile->supported() as $locale) {
                if (isset($values[$definition->key][$locale])) {
                    $ordered[$definition->key][$locale] = $values[$definition->key][$locale];
                }
            }
        }
        return $ordered;
    }


    /**
     * @param array<string, array<string, string>> $values
     * @return list<array{key: string, locale: string, text: string}>
     */
    private static function listed(array $values): array
    {
        $entries = [];
        foreach ($values as $key => $texts) {
            foreach ($texts as $locale => $text) {
                $entries[] = ['key' => (string) $key, 'locale' => (string) $locale, 'text' => $text];
            }
        }
        return $entries;
    }
}

interface SiteWordingRepository
{
    public function load(ProfileManifest $profile): SiteWording;

    /**
     * @param list<string> $changed Keys the save changes, for the audit trail.
     * @throws SiteWordingConflict when the stored revision is no longer $expected
     */
    public function save(SiteWording $next, int $expected, array $changed, string $actorId, string $auditId, \DateTimeImmutable $now): void;
}

final class SiteWordingConflict extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Site wording changed while editing');
    }
}

final readonly class SiteWordingService
{
    /** @param null|\Closure(): list<string> $editing Languages editors work in; the profile's languages when absent. */
    public function __construct(private SiteWordingRepository $repository, private ProfileManifest $profile, private Clock $clock, private RandomSource $random, private ?\Closure $editing = null) {}

    public function load(): SiteWording
    {
        return $this->repository->load($this->profile);
    }

    /** @return list<string> */
    public function languages(): array
    {
        return $this->editing === null ? $this->profile->locales : ($this->editing)();
    }

    /**
     * Saves the texts of the languages editors work in. An empty text, or the default itself,
     * follows the profile's default; a reset key follows it in every language shown. Texts of
     * other languages, and of fields the form did not send, stay as they are.
     * @param array<string, array<string, string>> $texts Key to language to submitted text.
     * @param list<string> $reset
     * @throws InvalidInput|SiteWordingConflict
     */
    public function save(int $expected, array $texts, array $reset, SessionContext $actor): SiteWording|ValidationFailure
    {
        if (!$actor->editorial() || $actor->userId === null) {
            throw new InvalidInput('wording.not_allowed');
        }
        $current = $this->load();
        if ($expected !== $current->revision) {
            throw new SiteWordingConflict();
        }
        $values = $current->values;
        $errors = [];
        $submitted = [];
        foreach ($this->profile->wording as $definition) {
            foreach ($this->languages() as $locale) {
                $field = SiteWording::field($definition->key, $locale);
                if (in_array($definition->key, $reset, true)) {
                    unset($values[$definition->key][$locale]);
                    continue;
                }
                if (!isset($texts[$definition->key][$locale])) {
                    continue;
                }
                $text = trim($texts[$definition->key][$locale]);
                $submitted[$field] = $text;
                unset($values[$definition->key][$locale]);
                if ($text === '' || $text === ($definition->defaults[$locale] ?? null)) {
                    continue;
                }
                $problem = $definition->problem($text, $this->profile->reserved());
                if ($problem !== null) {
                    $errors[$field] = $problem;
                    continue;
                }
                $values[$definition->key][$locale] = $text;
            }
        }
        $next = SiteWording::of($this->profile, $expected + 1, $values);
        foreach ($this->languages() as $locale) {
            foreach ($next->taken($this->profile, $locale) as $key) {
                $errors[SiteWording::field($key, $locale)] ??= new Message('wording.segment_taken');
            }
        }
        if ($errors !== []) {
            return new ValidationFailure($submitted, $errors);
        }
        $this->repository->save($next, $expected, $next->changed($current), $actor->userId, bin2hex($this->random->bytes(16)), $this->clock->now());
        return $next;
    }
}
