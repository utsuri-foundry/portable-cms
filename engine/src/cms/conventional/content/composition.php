<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Design\RegistryDocument;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

/**
 * Bounded serializable layout, independent of rendering, storage and the installed profile.
 * @phpstan-type Length array{unit: 'px'|'rem'|'vw'|'vh'|'token', value: float|string}
 * @phpstan-type Source array{kind: string, id?: string, field?: string, link?: array{typeId: string, recordId: string}, captions?: array<string, string>}
 * @phpstan-type Column array{id: string, share: float, content: Source|null, width: float, anchor: string, roleId: string|null, padding: array{top: Length, right: Length, bottom: Length, left: Length}}
 * @phpstan-type Row array{id: string, gap: Length|null, minHeight: Length, columns: list<Column>}
 * @phpstan-type Layout array{formatVersion: 1|2, columnGap: Length, rowGap: Length, padding: Length, stackAt: string|null, rows: list<Row>}
 */
final readonly class Composition
{
    public const ANCHORS = ['top-left', 'top-center', 'top-right', 'mid-left', 'mid-center', 'mid-right', 'bottom-left', 'bottom-center', 'bottom-right'];

    /** @param Layout $value */
    private function __construct(private array $value, private ?string $defaultRole) {}

    /** @param list<string> $locales */
    public static function from(mixed $raw, FieldDefinition $field, RecordTypeDefinition $type, ?RegistryDocument $registry, array $locales = []): self
    {
        $raw ??= ['formatVersion' => 1, 'rows' => []];
        $value = self::map($raw, ['formatVersion', 'rows', 'columnGap', 'rowGap', 'padding', 'stackAt']);
        if (!in_array($value['formatVersion'] ?? null, [1, 2], true) || !is_array($value['rows'] ?? null) || !array_is_list($value['rows']) || count($value['rows']) > (int) $field->settings['maximumRows']) {
            throw new InvalidInput('validation.layout.choose_a_bounded_ordered_set_of');
        }
        if ($value['formatVersion'] === 2 && ($field->settings['formatVersion'] ?? 1) !== 2) {
            throw new InvalidInput('composition.details_not_enabled');
        }
        $design = $registry?->document();
        $defaultRole = $field->settings['defaultRole'] ?? null;
        if ($defaultRole !== null && (!is_string($defaultRole) || !in_array($defaultRole, array_column($design['typography'] ?? [], 'id'), true))) {
            throw new InvalidInput('validation.layout.the_profile_default_typography_role_is');
        }
        $stackAt = array_key_exists('stackAt', $value) ? $value['stackAt'] : ($field->settings['stackAt'] ?? null);
        if ($stackAt !== null && (!is_string($stackAt) || !in_array($stackAt, array_column($design['responsive'] ?? [], 'id'), true))) {
            throw new InvalidInput('validation.layout.choose_an_available_responsive_range');
        }
        $rows = [];
        $identities = [];
        foreach ($value['rows'] as $position => $rawRow) {
            $row = self::map($rawRow, ['id', 'columns', 'gap', 'minHeight']);
            $id = self::id($row['id'] ?? 'row-' . ($position + 1));
            if (isset($identities[$id]) || !is_array($row['columns'] ?? null) || !array_is_list($row['columns']) || count($row['columns']) < 1 || count($row['columns']) > (int) $field->settings['maximumColumns']) {
                throw new InvalidInput('validation.layout.rows_need_unique_identities_and_a');
            }
            $identities[$id] = true;
            $columns = [];
            foreach ($row['columns'] as $index => $rawColumn) {
                $column = self::map($rawColumn, ['id', 'share', 'content', 'width', 'anchor', 'roleId', 'padding']);
                $columnId = self::id($column['id'] ?? $id . '-c' . ($index + 1));
                if (isset($identities[$columnId])) {
                    throw new InvalidInput('validation.layout.column_identities_must_be_unique');
                }
                $identities[$columnId] = true;
                $anchor = $column['anchor'] ?? 'top-left';
                $role = $column['roleId'] ?? null;
                if (!is_string($anchor) || !in_array($anchor, self::ANCHORS, true) || ($role !== null && (!is_string($role) || !in_array($role, array_column($design['typography'] ?? [], 'id'), true)))) {
                    throw new InvalidInput('validation.layout.choose_an_available_anchor_and_typography');
                }
                $padding = self::map($column['padding'] ?? [], ['top', 'right', 'bottom', 'left']);
                $columns[] = ['id' => $columnId, 'share' => self::number($column['share'] ?? 1, 0.01, 1000),
                    'content' => self::content($column['content'] ?? null, $field, $type, $value['formatVersion'], $locales),
                    'width' => self::number($column['width'] ?? 100, 1, 100), 'anchor' => $anchor, 'roleId' => $role,
                    'padding' => ['top' => self::length($padding['top'] ?? null, $registry), 'right' => self::length($padding['right'] ?? null, $registry),
                        'bottom' => self::length($padding['bottom'] ?? null, $registry), 'left' => self::length($padding['left'] ?? null, $registry)]];
            }
            $rows[] = ['id' => $id, 'gap' => ($row['gap'] ?? null) === null ? null : self::length($row['gap'], $registry),
                'minHeight' => self::length($row['minHeight'] ?? null, $registry), 'columns' => $columns];
        }
        return new self(['formatVersion' => $value['formatVersion'],
            'columnGap' => self::length($value['columnGap'] ?? $field->settings['columnGap'] ?? null, $registry),
            'rowGap' => self::length($value['rowGap'] ?? $field->settings['rowGap'] ?? null, $registry),
            'padding' => self::length($value['padding'] ?? null, $registry), 'stackAt' => $stackAt, 'rows' => $rows], $defaultRole);
    }

    public function defaultRole(): ?string
    {
        return $this->defaultRole;
    }

    /** @return Layout */
    public function document(): array
    {
        return $this->value;
    }

    /**
     * Same versioned semantics, omitting engine-owned constant defaults from transport and storage.
     * @return array<string, mixed>
     */
    public function compact(): array
    {
        $value = $this->value;
        foreach ($value['rows'] as &$row) {
            if ($row['gap'] === null) {
                unset($row['gap']);
            }
            if ($row['minHeight'] === ['unit' => 'px', 'value' => 0.0]) {
                unset($row['minHeight']);
            }
            foreach ($row['columns'] as &$column) {
                if ($column['width'] === 100.0) {
                    unset($column['width']);
                }
                if ($column['anchor'] === 'top-left') {
                    unset($column['anchor']);
                }
                if ($column['roleId'] === null) {
                    unset($column['roleId']);
                }
                foreach ($column['padding'] as $edge => $length) {
                    if ($length === ['unit' => 'px', 'value' => 0.0]) {
                        unset($column['padding'][$edge]);
                    }
                }
                if ($column['padding'] === []) {
                    unset($column['padding']);
                }
            }
            unset($column);
        }
        unset($row);
        return $value;
    }

    /** @return list<string> */
    public function mediaIds(): array
    {
        $ids = [];
        foreach ($this->value['rows'] as $row) {
            foreach ($row['columns'] as $column) {
                if (($column['content']['kind'] ?? null) === 'media') {
                    $ids[] = $column['content']['id'];
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /** @return list<RecordReference> */
    public function links(): array
    {
        $links = [];
        foreach ($this->value['rows'] as $row) {
            foreach ($row['columns'] as $column) {
                if (isset($column['content']['link'])) {
                    $links[] = RecordReference::from($column['content']['link']);
                }
            }
        }
        return $links;
    }

    /** @return list<Source> */
    public function linkedImages(): array
    {
        $sources = [];
        foreach ($this->value['rows'] as $row) {
            foreach ($row['columns'] as $column) {
                if (isset($column['content']['link'])) {
                    $sources[] = $column['content'];
                }
            }
        }
        return $sources;
    }

    /** @param Source $source */
    public static function caption(array $source, string $locale, string $defaultLocale, string $mediaCaption = ''): string
    {
        $caption = $source['captions'][$locale] ?? '';
        if (trim($caption) === '') {
            $caption = $source['captions'][$defaultLocale] ?? '';
        }
        return trim($caption) === '' ? $mediaCaption : $caption;
    }

    /** @param list<string> $locales
     * @return Source|null
     */
    private static function content(mixed $raw, FieldDefinition $composition, RecordTypeDefinition $type, int $version, array $locales): ?array
    {
        if ($raw === null) {
            return null;
        }
        $value = self::map($raw, $version === 1 ? ['kind', 'id', 'field'] : ['kind', 'id', 'field', 'link', 'captions']);
        if (($value['kind'] ?? null) === 'media' && !array_key_exists('field', $value) && is_string($value['id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/', $value['id']) === 1) {
            $source = ['kind' => 'media', 'id' => $value['id']];
            if (array_key_exists('link', $value)) {
                $link = RecordReference::from($value['link']);
                if (!in_array($link->typeId, $composition->targetTypes, true)) {
                    throw new InvalidInput('composition.link_invalid');
                }
                $source['link'] = $link->document();
            }
            if (array_key_exists('captions', $value)) {
                $captions = self::map($value['captions'], array_keys(is_array($value['captions']) ? $value['captions'] : []));
                if (count($captions) > 16) {
                    throw new InvalidInput('composition.caption_invalid');
                }
                foreach ($captions as $locale => $caption) {
                    if (!is_string($locale) || preg_match('/\A[a-z]{2,3}(?:-[A-Z]{2})?\z/', $locale) !== 1
                        || ($locales !== [] && !in_array($locale, $locales, true)) || !is_string($caption) || !mb_check_encoding($caption, 'UTF-8')
                        || mb_strlen($caption) > 500 || preg_match('/[<>\x00-\x1f\x7f]/u', $caption) !== 0) {
                        throw new InvalidInput('composition.caption_invalid');
                    }
                }
                if ($captions !== []) {
                    $source['captions'] = $captions;
                }
            }
            return $source;
        }
        if (!is_string($value['field'] ?? null) || count($value) !== 2 || !in_array($value['kind'] ?? null, ['rich_text', 'video', 'block'], true)) {
            throw new InvalidInput('validation.layout.choose_an_allowed_composition_asset');
        }
        foreach ($type->fields as $field) {
            $allowed = match ($value['kind']) {
                'rich_text' => $field->type === 'rich_text',
                'video' => $field->type === 'video_reference' && ($composition->settings['videoEnabled'] ?? false) === true,
                'block' => in_array($field->id, $composition->settings['blockFields'] ?? [], true) && in_array($field->type, ['short_text', 'localized_short_text', 'multiline_text'], true),
            };
            if ($field->id === $value['field'] && $allowed) {
                return ['kind' => $value['kind'], 'field' => $field->id];
            }
        }
        throw new InvalidInput('validation.layout.the_composition_source_is_not_enabled');
    }

    /** @return Length */
    public static function length(mixed $raw, ?RegistryDocument $registry): array
    {
        if ($raw === null) {
            return ['unit' => 'px', 'value' => 0.0];
        }
        $value = self::map($raw, ['unit', 'value']);
        if (count($value) !== 2 || !in_array($value['unit'] ?? null, ['px', 'rem', 'vw', 'vh', 'token'], true)) {
            throw new InvalidInput('validation.layout.use_a_named_space_or_a');
        }
        if ($value['unit'] === 'token') {
            if (!is_string($value['value'] ?? null) || !in_array($value['value'], array_column($registry?->document()['spacing'] ?? [], 'id'), true)) {
                throw new InvalidInput('validation.layout.choose_an_available_spacing_token');
            }
            return ['unit' => 'token', 'value' => $value['value']];
        }
        return ['unit' => $value['unit'], 'value' => self::number($value['value'] ?? null, 0, $value['unit'] === 'px' ? 4000 : 250)];
    }

    private static function number(mixed $raw, float $min, float $max): float
    {
        if ((!is_int($raw) && !is_float($raw)) || !is_finite((float) $raw) || $raw < $min || $raw > $max) {
            throw new InvalidInput('validation.layout.enter_layout_sizes_within_the_displayed');
        }
        return (float) $raw;
    }

    private static function id(mixed $raw): string
    {
        if (!is_string($raw) || preg_match('/\A[a-z][a-z0-9_-]{0,79}\z/', $raw) !== 1) {
            throw new InvalidInput('validation.layout.layout_identity_is_invalid');
        }
        return $raw;
    }

    /** @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function map(mixed $raw, array $keys): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw)) || array_diff(array_keys($raw), $keys) !== []) {
            throw new InvalidInput('validation.layout.layout_contains_unsupported_values');
        }
        return $raw;
    }
}
