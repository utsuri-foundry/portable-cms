<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/** Escaped profile guidance attached to the field's native control or control group, folded behind its "i". */
final class FieldHint
{
    public static function render(string $markup, FieldDefinition $field, ?Translator $t = null): string
    {
        if ($field->hint === null) {
            return $markup;
        }
        $t ??= Translator::english();
        $id = 'hint-' . $field->id;
        $text = FieldHelp::text($id, Html::text($field->hint));
        $toggle = FieldHelp::toggle($id, $field->label, $t);
        if (str_starts_with($markup, '<fieldset') && str_contains($markup, '<legend')) {
            // The group keeps its words as its name; the control and the text follow them.
            $labelId = 'label-' . $field->id;
            $markup = preg_replace_callback(
                '/<legend\b[^>]*>(.*?)<\/legend>/s',
                static fn(array $match): string => FieldHelp::legend($labelId, $match[1], $id, $field->label, $t) . $text,
                $markup,
                1,
            ) ?? $markup;
            $markup = self::nameBy($markup, $labelId);
        } else {
            $markup = preg_replace_callback('/<label\b[^>]*>.*?<\/label>/s', static fn(array $match): string => $match[0] . $toggle . $text, $markup, 1) ?? $markup;
        }
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

    /** Names the opening fieldset by the given element unless it already names itself. */
    public static function nameBy(string $markup, string $labelId): string
    {
        return preg_replace_callback('/^<fieldset\b[^>]*>/', static function (array $match) use ($labelId): string {
            $tag = $match[0];
            return str_contains($tag, 'aria-labelledby="') ? $tag : substr($tag, 0, -1) . ' aria-labelledby="' . Html::attribute($labelId) . '">';
        }, $markup, 1) ?? $markup;
    }
}
