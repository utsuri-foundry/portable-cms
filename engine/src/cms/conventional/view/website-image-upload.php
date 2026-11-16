<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/** A native upload action in the existing Website settings form. */
final class WebsiteImageUpload
{
    public static function controls(string $field, Translator $t): string
    {
        if (!in_array($field, ['iconMediaId', 'adminLogoMediaId', 'darkAdminLogoMediaId'], true)) {
            throw new \LogicException('Unknown website image field');
        }
        $id = 'website-files-' . $field;
        return '<div class="admin-upload"><div class="admin-field"><label for="' . $id . '">' . $t->html('website_media.file') . '</label>'
            . FieldHelp::toggle($id . '-help', $t->text('website_media.file'), $t) . FieldHelp::text($id . '-help', $t->html('website_media.upload_help'))
            . '<input type="file" id="' . $id . '" name="images_' . Html::attribute($field) . '[]" accept="image/jpeg,image/png,image/webp" aria-describedby="' . $id . '-help"></div>'
            . '<button type="submit" name="_website_upload" value="' . $field . '" formenctype="multipart/form-data" formnovalidate>' . $t->html('website_media.upload') . '</button></div>';
    }
}
