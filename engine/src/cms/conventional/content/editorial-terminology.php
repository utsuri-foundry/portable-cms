<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

final readonly class EditorialTerminology
{
    /**
     * Installation-owned type names. `languages` optionally overrides them per interface language
     * and is omitted from documents when empty, so existing exports keep their exact bytes.
     *
     * @param list<array{typeId: string, singular: string, plural: string, languages?: array<string, array{singular: string, plural: string}>}> $labels
     */
    public function __construct(public string $profileId, public int $revision, public array $labels)
    {
        if (!ProfileManifest::canonicalId($profileId) || $revision < 0 || $revision > 2_147_483_647 || count($labels) > 96) {
            throw new \InvalidArgumentException('Terminology identity is invalid');
        }
        $seen = [];
        foreach ($labels as $label) {
            $languages = $label['languages'] ?? [];
            if (!in_array(array_keys($label), [['typeId', 'singular', 'plural'], ['typeId', 'singular', 'plural', 'languages']], true)
                || !is_array($languages) || (array_key_exists('languages', $label) && $languages === [])
                || !self::languagesValid($languages)
                || !ProfileManifest::canonicalId($label['typeId'])
                || !ProfileManifest::label($label['singular'])
                || !ProfileManifest::label($label['plural'])
                || trim($label['singular']) === '' || trim($label['plural']) === ''
                || !mb_check_encoding($label['singular'], 'UTF-8') || !mb_check_encoding($label['plural'], 'UTF-8')
                || isset($seen[$label['typeId']])) {
                throw new \InvalidArgumentException('Terminology label is invalid');
            }
            $seen[$label['typeId']] = true;
        }
    }

    /** @return array{formatVersion: int, profileId: string, revision: int, labels: list<array{typeId: string, singular: string, plural: string, languages?: array<string, array{singular: string, plural: string}>}>} */
    public function document(): array
    {
        return ['formatVersion' => 1, 'profileId' => $this->profileId, 'revision' => $this->revision, 'labels' => $this->labels];
    }

    public static function fromDocument(mixed $document): self
    {
        if (!is_array($document) || !is_int($document['revision'] ?? null)
            || !is_string($document['profileId'] ?? null) || ($document['formatVersion'] ?? null) !== 1
            || !is_array($document['labels'] ?? null) || !array_is_list($document['labels'])) {
            throw new \InvalidArgumentException('Terminology document is invalid');
        }
        $keys = array_keys($document);
        sort($keys, SORT_STRING);
        if ($keys !== ['formatVersion', 'labels', 'profileId', 'revision']) {
            throw new \InvalidArgumentException('Terminology document properties are invalid');
        }
        $labels = [];
        foreach ($document['labels'] as $label) {
            if (!is_array($label) || !is_string($label['typeId'] ?? null)
                || !is_string($label['singular'] ?? null) || !is_string($label['plural'] ?? null)) {
                throw new \InvalidArgumentException('Terminology document label is invalid');
            }
            $keys = array_keys($label);
            sort($keys, SORT_STRING);
            if ($keys !== ['plural', 'singular', 'typeId'] && $keys !== ['languages', 'plural', 'singular', 'typeId']) {
                throw new \InvalidArgumentException('Terminology label properties are invalid');
            }
            $entry = ['typeId' => $label['typeId'], 'singular' => $label['singular'], 'plural' => $label['plural']];
            if (isset($label['languages'])) {
                if (!is_array($label['languages'])) {
                    throw new \InvalidArgumentException('Terminology label languages are invalid');
                }
                $languages = [];
                foreach ($label['languages'] as $language => $names) {
                    if (!is_string($language) || !is_array($names) || !is_string($names['singular'] ?? null) || !is_string($names['plural'] ?? null) || count($names) !== 2) {
                        throw new \InvalidArgumentException('Terminology label languages are invalid');
                    }
                    $languages[$language] = ['singular' => $names['singular'], 'plural' => $names['plural']];
                }
                $entry['languages'] = $languages;
            }
            $labels[] = $entry;
        }
        return new self($document['profileId'], $document['revision'], $labels);
    }

    /**
     * Presents type, field, option and navigation names for one interface language: a per-language
     * installation name, then an installation rename, then the profile label for that language,
     * then the profile default. Stable IDs, routes and renderers never change.
     */
    public function present(ProfileManifest $profile, ?string $language = null, ?AdministrationLabels $administration = null): ProfileManifest
    {
        if ($profile->id !== $this->profileId) {
            throw new \InvalidArgumentException('Terminology profile differs');
        }
        $administration ??= AdministrationLabels::none();
        $labels = [];
        foreach ($this->labels as $label) {
            if ($profile->type($label['typeId']) === null) {
                throw new \InvalidArgumentException('Terminology type is not installed');
            }
            $labels[$label['typeId']] = $label;
        }
        $names = [];
        $types = [];
        foreach ($profile->types as $type) {
            $label = $labels[$type->id] ?? null;
            $translated = $language === null ? null : ($label['languages'][$language] ?? null);
            $renamed = $label !== null && ($label['plural'] !== $type->label || $label['singular'] !== $type->singularLabel);
            $profileLabel = $language === null ? null : $administration->type($language, $type->id);
            $names[$type->id] = [
                'plural' => $translated['plural'] ?? ($renamed ? $label['plural'] : ($profileLabel['label'] ?? $label['plural'] ?? $type->label)),
                'singular' => $translated['singular'] ?? ($renamed ? $label['singular'] : ($profileLabel['singularLabel'] ?? $label['singular'] ?? $type->singularLabel)),
                'installed' => $translated !== null || $renamed,
            ];
            $fields = [];
            foreach ($type->fields as $field) {
                $fields[] = $language === null ? $field : $administration->field($field, $language, $type->id . '.' . $field->id);
            }
            $types[] = new RecordTypeDefinition(
                $type->id,
                $names[$type->id]['plural'],
                $names[$type->id]['singular'],
                $fields,
                $type->kind,
                $type->archetype,
                $type->renderer,
                $type->route,
            );
        }
        $navigation = [];
        foreach ($profile->navigation as $item) {
            $name = $item->targetKind === 'system' ? null : ($names[$item->targetId] ?? null);
            $default = $name === null ? $item->label : ($labels[$item->targetId]['plural'] ?? $item->label);
            if ($language !== null && $name !== null && $name['installed']) {
                $text = $name['plural'];
            } elseif ($language !== null) {
                $text = $administration->navigation($language, $item->id)
                    ?? ($name !== null && $administration->type($language, $item->targetId) !== null ? $name['plural'] : $default);
            } else {
                $text = $default;
            }
            $navigation[] = new NavigationDefinition($item->id, $text, $item->targetKind, $item->targetId, $item->position);
        }
        return new ProfileManifest(
            $profile->id,
            $profile->version,
            $profile->compatibility,
            $profile->locales,
            $profile->localeDefinitions,
            $profile->defaultLocale,
            $types,
            $navigation,
            $profile->renderers,
            $profile->assets,
            $profile->capabilities,
            $profile->privacy,
            $profile->designRegistries,
            $profile->supportedLocales,
            array_map(static fn(WordingDefinition $definition): WordingDefinition => $language === null ? $definition : $administration->wording($definition, $language), $profile->wording),
            $profile->envelope,
            $profile->addedLocales,
            $profile->contentFeed,
        );
    }

    /** @param array<mixed> $languages */
    private static function languagesValid(array $languages): bool
    {
        foreach ($languages as $language => $names) {
            if (!is_string($language) || \Utsuri\PortableCms\Conventional\I18n\InterfaceLanguage::preference($language) === null
                || !is_array($names) || array_keys($names) !== ['singular', 'plural']) {
                return false;
            }
            foreach ($names as $value) {
                if (!is_string($value) || trim($value) === '' || !ProfileManifest::label($value) || !mb_check_encoding($value, 'UTF-8')) {
                    return false;
                }
            }
        }
        return count($languages) <= count(\Utsuri\PortableCms\Conventional\I18n\InterfaceLanguage::SUPPORTED);
    }
}

final class TerminologyConflict extends \RuntimeException
{
    public function __construct(public readonly EditorialTerminology $current)
    {
        parent::__construct('Terminology changed while editing');
    }
}

interface TerminologyRepository
{
    public function load(string $profileId): EditorialTerminology;

    public function save(EditorialTerminology $intent, string $actor, string $auditId, \DateTimeImmutable $now): EditorialTerminology;
}

final class TerminologyService
{
    public function __construct(
        private readonly TerminologyRepository $repository,
        private readonly ProfileManifest $profile,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        private readonly ?AdministrationLabels $administration = null,
    ) {}

    public function load(): EditorialTerminology
    {
        return $this->repository->load($this->profile->id);
    }

    /** @param array<string, string> $values */
    public function save(int $revision, array $values, string $actor): EditorialTerminology|ValidationFailure
    {
        $labels = [];
        $errors = [];
        foreach ($this->profile->types as $type) {
            $singular = trim($values[$type->id . '__singular'] ?? '');
            $plural = trim($values[$type->id . '__plural'] ?? '');
            foreach (['singular' => $singular, 'plural' => $plural] as $key => $value) {
                if ($value === '' || !ProfileManifest::label($value) || !mb_check_encoding($value, 'UTF-8')) {
                    $errors[$type->id . '__' . $key] = new \Utsuri\PortableCms\Conventional\I18n\Message('validation.name_length');
                }
            }
            $label = ['typeId' => $type->id, 'singular' => $singular, 'plural' => $plural];
            foreach (\Utsuri\PortableCms\Conventional\I18n\InterfaceLanguage::SUPPORTED as $language) {
                $names = [];
                foreach (['singular', 'plural'] as $key) {
                    $names[$key] = trim($values[$type->id . '__' . $key . '__' . $language] ?? '');
                }
                if ($names['singular'] === '' && $names['plural'] === '') {
                    continue;
                }
                foreach ($names as $key => $value) {
                    $name = $type->id . '__' . $key . '__' . $language;
                    if ($value === '') {
                        $errors[$name] = new \Utsuri\PortableCms\Conventional\I18n\Message('validation.name_pair');
                    } elseif (!ProfileManifest::label($value) || !mb_check_encoding($value, 'UTF-8')) {
                        $errors[$name] = new \Utsuri\PortableCms\Conventional\I18n\Message('validation.name_length');
                    }
                }
                $label['languages'][$language] = $names;
            }
            $labels[] = $label;
        }
        if ($errors !== []) {
            return new ValidationFailure($values, $errors);
        }
        $intent = new EditorialTerminology($this->profile->id, $revision, $labels);
        try {
            AdministrationMenuLabels::assert($this->profile, $this->administration, $intent);
        } catch (\InvalidArgumentException $error) {
            return new ValidationFailure($values, ['_labels' => new \Utsuri\PortableCms\Conventional\I18n\Message('validation.diagnostic', ['text' => $error->getMessage()])]);
        }
        return $this->repository->save(
            $intent,
            $actor,
            bin2hex($this->random->bytes(16)),
            $this->clock->now(),
        );
    }
}
