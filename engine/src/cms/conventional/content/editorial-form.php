<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;

final class EditorialForm
{
    public static function supported(FieldDefinition $field): bool
    {
        if ($field->type === 'blocks') {
            return true;
        }
        if (in_array($field->type, ['group', 'repeatable_group'], true)) {
            foreach ($field->fields as $child) {
                if ($child->type === 'composition' || !self::supported($child)) {
                    return false;
                }
            }
        }
        return !($field->type === 'composition' && $field->localized);
    }

    public static function section(FieldDefinition $field): string
    {
        if ($field->editorialSection !== null) {
            return $field->editorialSection;
        }
        return match ($field->type) {
            'record_reference', 'record_references', 'taxonomy_references' => 'relationships',
            'media_references', 'video_reference', 'vimeo_video' => 'media',
            'composition' => 'layout',
            'rich_text', 'group', 'repeatable_group', 'blocks' => 'content',
            default => $field->localized ? 'content' : 'details',
        };
    }

    /** @return array<string, mixed> */
    public static function input(Request $request, RecordTypeDefinition $type, ProfileManifest $profile, ?RecordSnapshot $saved): array
    {
        $values = [];
        foreach ($request->form as $name => $value) {
            if (str_ends_with($name, '__rows') && $request->formValue('_editorial_end') !== '1') {
                throw new \InvalidArgumentException('Incomplete editorial form');
            }
        }
        foreach ($type->fields as $field) {
            if ($field->type === 'composition' && self::supported($field)) {
                if ($request->formValue('_editorial_end') !== '1') {
                    throw new \InvalidArgumentException('Incomplete editorial form');
                }
                $values[$field->id] = isset($request->form[$field->id . '__document'])
                    ? CompositionForm::input($request, $field->id) : ($saved?->body[$field->id] ?? null);
            } elseif ($field->type === 'rich_text') {
                if ($request->formValue('_editorial_end') !== '1') {
                    throw new \InvalidArgumentException('Incomplete editorial form');
                }
                $localized = [];
                foreach ($field->localized ? $profile->locales : [''] as $locale) {
                    $name = $field->id . ($locale === '' ? '' : '__' . $locale);
                    if (!isset($request->form[$name . '__rows'])) {
                        $localized[$locale] = $field->localized ? ($saved?->body[$field->id][$locale] ?? ['formatVersion' => 1, 'nodes' => []]) : ($saved?->body[$field->id] ?? ['formatVersion' => 1, 'nodes' => []]);
                    } else {
                        $localized[$locale] = RichTextForm::input($request, $name, $field);
                    }
                }
                $values[$field->id] = $field->localized ? $localized : $localized[''];
            } elseif ($field->type === 'blocks') {
                $values[$field->id] = json_encode($request->formValue('_present_' . $field->id) === '1'
                    ? BlocksForm::input($request, $field, $profile, $saved?->body[$field->id] ?? null) : ($saved?->body[$field->id] ?? null), JSON_THROW_ON_ERROR);
            } elseif (!self::supported($field)) {
                // Pending editors cannot mutate these fields. Restore from the server, never the submitted form.
                $value = $saved?->body[$field->id] ?? null;
                $values[$field->id] = $value === null ? '' : json_encode($value, JSON_THROW_ON_ERROR);
            } elseif (in_array($field->type, ['group', 'repeatable_group'], true)) {
                $values[$field->id] = json_encode($request->formValue('_present_' . $field->id) === '1'
                    ? EditorialControls::groupInput($request, $field, $profile) : ($saved?->body[$field->id] ?? null), JSON_THROW_ON_ERROR);
            } elseif ($field->typedReferences()) {
                $values[$field->id] = $request->formValue('_present_' . $field->id) === '1'
                    ? EditorialControls::referenceInput($field, $request) : ($saved?->body[$field->id] ?? ($field->type === 'record_reference' ? null : []));
            } elseif ($field->type === 'web_address') {
                if ($request->formValue('_present_' . $field->id) === '1' && $request->formValue('_editorial_end') !== '1') {
                    throw new \InvalidArgumentException('Incomplete editorial form');
                }
                $value = $request->formValue('_present_' . $field->id) === '1'
                    ? WebAddressForm::input($request, $field->id, $field->localized, $profile->locales) : ($saved?->body[$field->id] ?? null);
                $values[$field->id] = $value === null ? '' : json_encode($value, JSON_THROW_ON_ERROR);
            } elseif ($field->type === 'vimeo_video') {
                if ($request->formValue('_present_' . $field->id) === '1' && $request->formValue('_editorial_end') !== '1') {
                    throw new \InvalidArgumentException('Incomplete editorial form');
                }
                $value = $request->formValue('_present_' . $field->id) === '1'
                    ? VimeoVideoForm::input($request, $field->id, $field->localized, $profile->locales) : ($saved?->body[$field->id] ?? null);
                $values[$field->id] = $value === null ? '' : json_encode($value, JSON_THROW_ON_ERROR);
            } elseif ($field->type === 'video_reference') {
                if ($request->formValue('_present_' . $field->id) === '1' && $request->formValue('_editorial_end') !== '1') {
                    throw new \InvalidArgumentException('Incomplete editorial form');
                }
                $value = $request->formValue('_present_' . $field->id) === '1'
                    ? VideoReference::input($request, $field->id, $field->localized, $profile->locales) : ($saved?->body[$field->id] ?? null);
                $values[$field->id] = $value === null ? '' : json_encode($value, JSON_THROW_ON_ERROR);
            } elseif ($field->type === 'media_references') {
                $value = $request->formValue('_present_' . $field->id) === '1'
                    ? $request->formValues($field->id) : ($saved?->body[$field->id] ?? null);
                $values[$field->id] = $value === null ? '' : json_encode($value, JSON_THROW_ON_ERROR);
            } elseif ($field->localized) {
                $localized = [];
                foreach ($profile->locales as $locale) {
                    $localized[$locale] = $request->formValue($field->id . '__' . $locale);
                }
                $values[$field->id] = $localized;
            } elseif (in_array($field->type, ['record_references', 'taxonomy_references'], true)) {
                $values[$field->id] = $request->formValues($field->id);
            } elseif ($field->type === 'boolean') {
                $values[$field->id] = $request->formValue($field->id) === '1';
            } else {
                $values[$field->id] = $request->formValue($field->id);
            }
        }
        // The form shows only the languages in use; languages that are off keep their saved text.
        return SiteLanguages::keep($type, $values, $saved?->body, array_values(array_diff($profile->supported(), $profile->locales)));
    }
}
