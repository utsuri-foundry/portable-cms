<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

/** One active row per native form keeps large layouts below shared-host input limits. */
final class CompositionForm
{
    /** @return array<string, mixed> */
    public static function input(Request $request, string $name): array
    {
        $json = $request->formValue($name . '__document');
        if (strlen($json) > 1_048_576 || $request->formValue($name . '__end') !== '1') {
            throw new InvalidInput('validation.layout_form.incomplete_composition_form');
        }
        try {
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new InvalidInput('validation.layout_form.invalid_composition_form', previous: $error);
        }
        if (!is_array($value) || !is_array($value['rows'] ?? null)) {
            throw new InvalidInput('validation.layout_form.invalid_composition_form');
        }
        if (!array_is_list($value['rows']) || count($value['rows']) > 256) {
            throw new InvalidInput('validation.layout_form.invalid_composition_row_bounds');
        }
        foreach ($value['rows'] as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || preg_match('/\A[a-z][a-z0-9_-]{0,79}\z/', $row['id']) !== 1
                || !is_array($row['columns'] ?? null) || !array_is_list($row['columns']) || count($row['columns']) > 12 || $row['columns'] === []) {
                throw new InvalidInput('validation.layout_form.invalid_composition_row');
            }
            foreach ($row['columns'] as $column) {
                if (!is_array($column)) {
                    throw new InvalidInput('validation.layout_form.invalid_composition_column');
                }
                foreach (['id', 'anchor', 'roleId'] as $key) {
                    if (isset($column[$key]) && (!is_string($column[$key]) || strlen($column[$key]) > 160)) {
                        throw new InvalidInput('validation.layout_form.invalid_composition_column_identity');
                    }
                }
                if (isset($column['content'])) {
                    if (!is_array($column['content'])) {
                        throw new InvalidInput('validation.layout_form.invalid_composition_source');
                    }
                    foreach ($column['content'] as $key => $item) {
                        if (in_array($key, ['link', 'captions'], true) && is_array($item)) {
                            foreach ($item as $text) {
                                if (!is_string($text) || strlen($text) > 2000) {
                                    throw new InvalidInput('validation.layout_form.invalid_composition_source');
                                }
                            }
                        } elseif (!is_string($item) || strlen($item) > 160) {
                            throw new InvalidInput('validation.layout_form.invalid_composition_source');
                        }
                    }
                }
            }
        }
        foreach (['columnGap', 'rowGap', 'padding'] as $key) {
            $value[$key] = self::length($request, $name . '__' . $key);
        }
        $value['stackAt'] = $request->formValue($name . '__stackAt') ?: null;
        $active = $request->formValue($name . '__active');
        foreach ($value['rows'] as &$row) {
            if (!is_array($row) || ($row['id'] ?? '') !== $active) {
                continue;
            }
            $prefix = $name . '__' . $active;
            $row['gap'] = $request->formValue($prefix . '__gap__unit') === '' ? null : self::length($request, $prefix . '__gap');
            $row['minHeight'] = self::length($request, $prefix . '__minHeight');
            if (!is_array($row['columns'] ?? null)) {
                throw new InvalidInput('validation.layout_form.invalid_composition_columns');
            }
            foreach ($row['columns'] as $index => &$column) {
                if (!is_array($column)) {
                    throw new InvalidInput('validation.layout_form.invalid_composition_column');
                }
                $prefix = $name . '__' . $active . '__c' . $index;
                $column['share'] = self::number($request->formValue($prefix . '__share'));
                $column['width'] = self::number($request->formValue($prefix . '__width'));
                $column['anchor'] = $request->formValue($prefix . '__anchor');
                $column['roleId'] = $request->formValue($prefix . '__roleId') ?: null;
                $source = explode(':', $request->formValue($prefix . '__source'), 2);
                $column['content'] = $source[0] === '' ? null : ['kind' => $source[0], $source[0] === 'media' ? 'id' : 'field' => $source[1] ?? ''];
                if ($source[0] === 'media') {
                    $link = $request->formValue($prefix . '__link');
                    if ($link !== '') {
                        $column['content']['link'] = RecordReference::fromToken($link)->document();
                        $value['formatVersion'] = 2;
                    }
                    foreach ($request->form as $control => $caption) {
                        if (!str_starts_with($control, $prefix . '__caption__')) {
                            continue;
                        }
                        if (!is_string($caption)) {
                            throw new InvalidInput('composition.caption_invalid');
                        }
                        if (trim($caption) !== '') {
                            $column['content']['captions'][substr($control, strlen($prefix . '__caption__'))] = trim($caption);
                            $value['formatVersion'] = 2;
                        }
                    }
                }
                $column['padding'] = [];
                foreach (['top', 'right', 'bottom', 'left'] as $edge) {
                    $column['padding'][$edge] = self::length($request, $prefix . '__' . $edge);
                }
            }
            unset($column);
        }
        unset($row);
        return $value;
    }

    /** @return array<string, mixed> */
    public static function apply(string $operation, string $target, Composition $composition, FieldDefinition $field, RandomSource $random): array
    {
        $document = $composition->document();
        if (!in_array($operation, ['add-row', 'remove-row', 'up-row', 'down-row', 'add-column', 'remove-column', 'left-column', 'right-column'], true)) {
            throw new InvalidInput('validation.layout_form.unknown_layout_action');
        }
        if ($operation === 'add-row') {
            if (count($document['rows']) >= (int) $field->settings['maximumRows']) {
                throw new InvalidInput('validation.layout_form.this_layout_has_reached_its_row');
            }
            $document['rows'][] = ['id' => 'r' . bin2hex($random->bytes(12)), 'columns' => [self::column($random)], 'gap' => null, 'minHeight' => ['unit' => 'px', 'value' => 0.0]];
            return $document;
        }
        foreach ($document['rows'] as $index => $row) {
            if ($row['id'] !== $target) {
                continue;
            }
            if ($operation === 'remove-row') {
                array_splice($document['rows'], $index, 1);
            } elseif ($operation === 'up-row' && $index > 0) {
                [$document['rows'][$index - 1], $document['rows'][$index]] = [$row, $document['rows'][$index - 1]];
            } elseif ($operation === 'down-row' && $index + 1 < count($document['rows'])) {
                [$document['rows'][$index + 1], $document['rows'][$index]] = [$row, $document['rows'][$index + 1]];
            } elseif ($operation === 'add-column') {
                if (count($row['columns']) >= (int) $field->settings['maximumColumns']) {
                    throw new InvalidInput('validation.layout_form.this_row_has_reached_its_column');
                }
                $document['rows'][$index]['columns'][] = self::column($random);
            }
            return $document;
        }
        foreach ($document['rows'] as &$row) {
            foreach ($row['columns'] as $index => $column) {
                if ($column['id'] !== $target) {
                    continue;
                }
                if ($operation === 'remove-column' && count($row['columns']) > 1) {
                    array_splice($row['columns'], $index, 1);
                } elseif ($operation === 'left-column' && $index > 0) {
                    [$row['columns'][$index - 1], $row['columns'][$index]] = [$column, $row['columns'][$index - 1]];
                } elseif ($operation === 'right-column' && $index + 1 < count($row['columns'])) {
                    [$row['columns'][$index + 1], $row['columns'][$index]] = [$column, $row['columns'][$index + 1]];
                }
                return $document;
            }
        }
        throw new InvalidInput('validation.layout_form.layout_action_target_is_unavailable');
    }

    /** @return array<string, mixed> */
    private static function length(Request $request, string $name): array
    {
        $unit = $request->formValue($name . '__unit');
        $value = $request->formValue($name . '__value');
        return ['unit' => $unit, 'value' => $unit === 'token' ? $value : self::number($value)];
    }

    private static function number(string $value): float|string
    {
        return preg_match('/\A[0-9]{1,6}(?:\.[0-9]{1,6})?\z/', $value) === 1 ? (float) $value : $value;
    }

    /** @return array<string, mixed> */
    private static function column(RandomSource $random): array
    {
        return ['id' => 'c' . bin2hex($random->bytes(12)), 'share' => 1.0, 'content' => null, 'width' => 100.0, 'anchor' => 'top-left', 'roleId' => null,
            'padding' => array_fill_keys(['top', 'right', 'bottom', 'left'], ['unit' => 'px', 'value' => 0.0])];
    }
}
