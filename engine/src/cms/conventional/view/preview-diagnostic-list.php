<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Preview\PreviewDiagnostics;

final class PreviewDiagnosticList
{
    public static function render(PreviewDiagnostics $diagnostics, Translator $translator, ?\Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile = null): string
    {
        $html = '<ul>';
        foreach ($diagnostics->issues as $issue) {
            $html .= '<li>' . Html::text($translator->message(PreviewDiagnostics::present($issue, $profile))) . '</li>';
        }
        $html .= '</ul>';
        return $html . self::reference($diagnostics->reference, $translator);
    }

    public static function reference(?string $reference, Translator $translator): string
    {
        return $reference === null ? '' : '<p>' . $translator->html('preflight.reference', ['reference' => $reference]) . '</p>';
    }
}
