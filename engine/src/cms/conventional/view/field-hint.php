<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Http\Html;

/** Escaped profile guidance attached to the field's native control or control group. */
final class FieldHint
{
    public static function render(string $markup, FieldDefinition $field): string
    {
        if ($field->hint === null) {
            return $markup;
        }
        $id = 'hint-' . $field->id;
        $hint = '<span class="admin-field__hint" id="' . Html::attribute($id) . '">' . Html::text($field->hint) . '</span>';
        $markup = preg_replace_callback('/<\/(?:legend|label)>/', static fn(array $match): string => $match[0] . $hint, $markup, 1) ?? $markup;
        return self::describe($markup, $id);
    }

    public static function describe(string $markup, string $id): string
    {
        return preg_replace_callback('/<(?:fieldset|input(?![^>]*type="hidden")|textarea|select)\b[^>]*>/', static function (array $match) use ($id): string {
            $tag = $match[0];
            if (str_contains($tag, 'aria-describedby="')) {
                return str_replace('aria-describedby="', 'aria-describedby="' . Html::attribute($id) . ' ', $tag);
            }
            return substr($tag, 0, -1) . ' aria-describedby="' . Html::attribute($id) . '">';
        }, $markup, str_starts_with($markup, '<fieldset') ? 1 : -1) ?? $markup;
    }
}
