<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use const Utsuri\PortableCms\Conventional\Export\EXPORT_MAX_DERIVATIVES;
use const Utsuri\PortableCms\Conventional\Export\EXPORT_MAX_JSON_BYTES;
use const Utsuri\PortableCms\Conventional\Export\EXPORT_MAX_MEDIA;
use const Utsuri\PortableCms\Conventional\Export\EXPORT_MAX_RECORDS;
use const Utsuri\PortableCms\Conventional\Export\EXPORT_MAX_RELATIONSHIPS;
use const Utsuri\PortableCms\Conventional\Export\EXPORT_MAX_REVISIONS;

use Utsuri\PortableCms\Conventional\Export\ExportCapacity;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/** Native administrator explanation; enforced limits remain owned by the export contract. */
final class ExportCapacityView
{
    public static function render(Translator $t): string
    {
        return '<details id="export-capacity"><summary>' . $t->html('export.capacity.details') . '</summary>'
            . '<p>' . $t->html('export.capacity.legacy', [
                'records' => EXPORT_MAX_RECORDS, 'revisions' => EXPORT_MAX_REVISIONS,
                'relationships' => EXPORT_MAX_RELATIONSHIPS, 'media' => EXPORT_MAX_MEDIA,
                'mib' => intdiv(EXPORT_MAX_JSON_BYTES, 1_048_576),
            ]) . '</p>'
            . '<p>' . $t->html('export.capacity.chunked', [
                'records' => ExportCapacity::limit('records'), 'revisions' => ExportCapacity::limit('revisions'),
                'relationships' => ExportCapacity::limit('relationships'), 'media' => ExportCapacity::limit('media'),
                'mib' => intdiv(ExportCapacity::PACKAGE_BYTES, 1_048_576),
            ]) . '</p>'
            . '<p>' . $t->html('export.capacity.scope', ['derivatives' => EXPORT_MAX_DERIVATIVES]) . '</p>'
            . '<p>' . $t->html('export.capacity.restore') . '</p>'
            . '<p>' . $t->html('export.capacity.bytes') . '</p></details>';
    }
}
