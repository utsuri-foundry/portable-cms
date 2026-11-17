<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/**
 * Explanations stay out of sight until asked for. A small "i" beside a label, legend or
 * heading discloses one line of help. The text is always in the page: assistive technology
 * reads it through aria-describedby, and without scripts it simply shows inline. Required
 * fields carry a star after their label, and one legend per form explains the star.
 */
final class FieldHelp
{
    /** The control, placed inside a legend or heading, or right after a label. */
    public static function toggle(string $id, string $about, Translator $t): string
    {
        return '<button type="button" class="admin-help-control__toggle" aria-expanded="false" aria-controls="' . Html::attribute($id) . '" data-help-toggle>'
            . '<span aria-hidden="true">i</span><span class="admin-visually-hidden">' . $t->html('field.about', ['label' => $about]) . '</span></button>';
    }

    /** The explanation, as escaped markup, folded until its control opens it. */
    public static function text(string $id, string $html, string $element = 'p'): string
    {
        return '<' . $element . ' class="admin-help-control__text" id="' . Html::attribute($id) . '">' . $html . '</' . $element . '>';
    }

    /** A heading and its control on one line; the heading alone names the section. */
    public static function heading(string $headingMarkup, string $id, string $about, Translator $t): string
    {
        return '<div class="admin-help-row">' . $headingMarkup . self::toggle($id, $about, $t) . '</div>';
    }

    /**
     * The legend's words in a named span, then the control. The fieldset points at the span
     * with aria-labelledby, so the group is named by its words alone.
     */
    public static function legend(string $labelId, string $innerMarkup, string $helpId, string $about, Translator $t): string
    {
        return '<legend><span class="admin-legend__text" id="' . Html::attribute($labelId) . '">' . $innerMarkup . '</span>' . self::toggle($helpId, $about, $t) . '</legend>';
    }

    /** The star after a required label; the word stays for assistive technology. */
    public static function required(Translator $t): string
    {
        return ' <span class="admin-field__required"><span aria-hidden="true">*</span><span class="admin-visually-hidden">' . $t->html('field.required') . '</span></span>';
    }

    /** One line per form explaining the star. */
    public static function requiredLegend(Translator $t): string
    {
        return '<p class="admin-form__required"><span class="admin-field__required" aria-hidden="true">*</span> ' . $t->html('form.required_legend') . '</p>';
    }
}
