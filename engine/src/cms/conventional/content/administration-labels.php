<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\I18n\InterfaceLanguage;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;

/**
 * Optional profile-supplied administration names per interface language, kept in
 * `administration-labels.json` beside `profile.json`. They are presentation only:
 * outside the package checksum, compiler input, exports and every stable identity.
 */
final readonly class AdministrationLabels
{
    public const FILE = 'administration-labels.json';
    private const MAXIMUM_BYTES = 262_144;

    /**
     * @param array<string, array{
     *   types: array<string, array{label: string, singularLabel: string}>,
     *   fields: array<string, string>,
     *   hints: array<string, string>,
     *   options: array<string, string>,
     *   items: array<string, string>,
     *   blocks: array<string, string>,
     *   wording: array<string, array{label: string, purpose: string}>,
     *   navigation: array<string, string>
     * }> $languages
     */
    private function __construct(private array $languages) {}

    public static function none(): self
    {
        return new self([]);
    }

    /** Loads and verifies the optional labels file of a profile package root. */
    public static function load(string $profileRoot, ProfileManifest $profile): self
    {
        $path = rtrim($profileRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::FILE;
        if (!file_exists($path) && !is_link($path)) {
            return self::none();
        }
        $size = is_file($path) && !is_link($path) ? filesize($path) : false;
        if (!is_int($size) || $size < 2 || $size > self::MAXIMUM_BYTES) {
            throw new \InvalidArgumentException('Profile administration labels are unavailable');
        }
        JsonSchemaValidator::validateFile($path, dirname(__DIR__, 5) . '/schemas/portable-cms-administration-labels-v1.schema.json');
        try {
            $document = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('Profile administration labels are not valid JSON', previous: $error);
        }
        return self::fromDocument($document, $profile);
    }

    /** Builds labels after checking every reference against the installed profile. */
    public static function fromDocument(mixed $document, ProfileManifest $profile): self
    {
        if (!is_array($document) || ($document['formatVersion'] ?? null) !== 1 || ($document['profileId'] ?? null) !== $profile->id
            || !is_array($document['languages'] ?? null)) {
            throw new \InvalidArgumentException('Profile administration labels belong to another profile');
        }
        $fields = [];
        $options = [];
        $items = [];
        $blocks = [];
        foreach ($profile->types as $type) {
            foreach ($type->fields as $field) {
                $fields[$type->id . '.' . $field->id] = true;
                if ($field->itemLabel() !== null) {
                    $items[$type->id . '.' . $field->id] = true;
                }
                foreach ($field->fields as $nested) {
                    $fields[$type->id . '.' . $field->id . '.' . $nested->id] = true;
                }
                foreach ($field->blockTypes as $block) {
                    $path = $type->id . '.' . $field->id . '.' . $block->id;
                    $blocks[$path] = true;
                    if ($block->itemLabel !== null) {
                        $items[$path] = true;
                    }
                    foreach ($block->fields as $child) {
                        $fields[$path . '.' . $child->id] = true;
                        foreach ($child->type === 'enum' ? $child->settings['options'] : [] as $option) {
                            $options[$path . '.' . $child->id . '.' . $option['value']] = true;
                        }
                    }
                }
                if ($field->type === 'enum') {
                    foreach (is_array($field->settings['options'] ?? null) ? $field->settings['options'] : [] as $option) {
                        if (is_array($option) && is_string($option['value'] ?? null)) {
                            $options[$type->id . '.' . $field->id . '.' . $option['value']] = true;
                        }
                    }
                }
            }
        }
        $navigation = [];
        foreach ($profile->navigation as $item) {
            $navigation[$item->id] = true;
        }
        $languages = [];
        foreach ($document['languages'] as $language => $labels) {
            if (!is_string($language) || InterfaceLanguage::preference($language) === null || !is_array($labels)) {
                throw new \InvalidArgumentException('Profile administration label language is not supported');
            }
            $entry = ['types' => [], 'fields' => [], 'hints' => [], 'options' => [], 'items' => [], 'blocks' => [], 'wording' => [], 'navigation' => []];
            foreach (is_array($labels['types'] ?? null) ? $labels['types'] : [] as $typeId => $names) {
                if (!is_string($typeId) || $profile->type($typeId) === null || !is_array($names)
                    || !self::label($names['label'] ?? null) || !self::label($names['singularLabel'] ?? null)) {
                    throw new \InvalidArgumentException('Profile administration type label is invalid');
                }
                $entry['types'][$typeId] = ['label' => $names['label'], 'singularLabel' => $names['singularLabel']];
            }
            foreach (['fields' => $fields, 'hints' => $fields, 'options' => $options, 'items' => $items, 'blocks' => $blocks, 'navigation' => $navigation] as $section => $known) {
                foreach (is_array($labels[$section] ?? null) ? $labels[$section] : [] as $path => $label) {
                    if (!is_string($path) || !isset($known[$path]) || !($section === 'hints' ? FieldDefinition::validHint($label) : self::label($label))) {
                        throw new \InvalidArgumentException('Profile administration ' . $section . ' label is invalid');
                    }
                    $entry[$section][$path] = $label;
                }
            }
            if (array_key_exists('wording', $labels) && (!is_array($labels['wording']) || count($labels['wording']) > 200)) {
                throw new \InvalidArgumentException('Profile administration wording labels are invalid');
            }
            foreach ($labels['wording'] ?? [] as $key => $names) {
                if (!is_string($key) || $profile->wording($key) === null || !is_array($names) || count($names) !== 2
                    || !self::label($names['label'] ?? null) || !is_string($names['purpose'] ?? null)
                    || !mb_check_encoding($names['purpose'], 'UTF-8') || trim($names['purpose']) === ''
                    || mb_strlen($names['purpose'], 'UTF-8') > 300 || preg_match('/[\x00-\x1f\x7f]/u', $names['purpose']) === 1) {
                    throw new \InvalidArgumentException('Profile administration wording label is invalid');
                }
                $entry['wording'][$key] = ['label' => $names['label'], 'purpose' => $names['purpose']];
            }
            $languages[$language] = $entry;
        }
        return new self($languages);
    }

    /** Stable digest of the verified labels, for comparing installed copies. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->languages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<string> Interface languages with profile-supplied labels. */
    public function languages(): array
    {
        return array_keys($this->languages);
    }

    /** @return array{label: string, singularLabel: string}|null */
    public function type(string $language, string $typeId): ?array
    {
        return $this->languages[$language]['types'][$typeId] ?? null;
    }

    public function navigation(string $language, string $itemId): ?string
    {
        return $this->languages[$language]['navigation'][$itemId] ?? null;
    }

    /** Only administration names change; public defaults and validation keep their exact values. */
    public function wording(WordingDefinition $definition, string $language): WordingDefinition
    {
        $names = $this->languages[$language]['wording'][$definition->key] ?? null;
        return $names === null ? $definition : new WordingDefinition(
            $definition->key,
            $names['label'],
            $names['purpose'],
            $definition->kind,
            $definition->maximumLength,
            $definition->defaults,
            $definition->uniqueIn,
        );
    }

    /** Presents field and option names in one interface language; other names stay the profile default. */
    public function field(FieldDefinition $field, string $language, string $path): FieldDefinition
    {
        $labels = $this->languages[$language] ?? null;
        if ($labels === null) {
            return $field;
        }
        $settings = $field->settings;
        if ($field->type === 'enum' && is_array($settings['options'] ?? null)) {
            foreach ($settings['options'] as $index => $option) {
                if (is_array($option) && is_string($option['value'] ?? null) && isset($labels['options'][$path . '.' . $option['value']])) {
                    $settings['options'][$index]['label'] = $labels['options'][$path . '.' . $option['value']];
                }
            }
        }
        if (isset($labels['items'][$path])) {
            $settings['itemLabel'] = $labels['items'][$path];
        }
        if ($field->type === 'blocks') {
            foreach ($settings['blockTypes'] as $index => $block) {
                $blockPath = $path . '.' . $block['id'];
                $settings['blockTypes'][$index]['label'] = $labels['blocks'][$blockPath] ?? $block['label'];
                if (isset($labels['items'][$blockPath])) {
                    $settings['blockTypes'][$index]['itemLabel'] = $labels['items'][$blockPath];
                }
                foreach ($field->blockTypes[$block['id']]->fields as $childIndex => $child) {
                    $presented = $this->field($child, $language, $blockPath . '.' . $child->id);
                    $settings['blockTypes'][$index]['fields'][$childIndex]['label'] = $presented->label;
                    $settings['blockTypes'][$index]['fields'][$childIndex]['settings'] = $presented->settings;
                    if ($presented->hint !== null) {
                        $settings['blockTypes'][$index]['fields'][$childIndex]['hint'] = $presented->hint;
                    }
                }
            }
        }
        $nested = [];
        foreach ($field->fields as $child) {
            $nested[] = $this->field($child, $language, $path . '.' . $child->id);
        }
        if (is_array($settings['fields'] ?? null)) {
            foreach ($settings['fields'] as $index => $child) {
                if (is_array($child) && is_string($child['id'] ?? null) && isset($labels['fields'][$path . '.' . $child['id']])) {
                    $settings['fields'][$index]['label'] = $labels['fields'][$path . '.' . $child['id']];
                }
            }
        }
        return new FieldDefinition(
            $field->id,
            $labels['fields'][$path] ?? $field->label,
            $field->type,
            $field->localized,
            $field->requiredOnDraft,
            $field->requiredOnPublish,
            $settings,
            $nested,
            $field->maximumLength,
            $field->targetType,
            $field->maximumItems,
            $field->editorialSection,
            $labels['hints'][$path] ?? $field->hint,
        );
    }

    private static function label(mixed $value): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && trim($value) !== '' && ProfileManifest::label($value);
    }
}
