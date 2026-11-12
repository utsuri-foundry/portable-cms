<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Preview\EditorPreviewPage;
use Utsuri\PortableCms\Conventional\Preview\PreviewDiagnostics;
use Utsuri\PortableCms\Conventional\Preview\PreviewStatus;

final class EditorExactPreviewPage
{
    public static function waiting(Translator $t, string $refresh, string $status = 'queued', bool $cronOnly = false): string
    {
        $message = match ($status) {
            'queued' => $cronOnly ? 'exact.queued_scheduled' : 'exact.queued',
            'building' => 'exact.building',
            default => throw new \InvalidArgumentException('Unknown exact-preview waiting state'),
        };
        return self::document('<main><h1>' . $t->html('exact.title') . '</h1><p role="status">' . $t->html($message) . '</p><p>' . $t->html('exact.help') . '</p><a href="' . Html::url($refresh) . '">' . $t->html('preview.refresh') . '</a></main>', $t);
    }

    public static function failed(Translator $t, ProfileManifest $profile, ?PreviewDiagnostics $diagnostics, ?PreviewStatus $preview = null): string
    {
        $reason = $diagnostics === null ? '<p>' . $t->markup('preview.error', [], ['code' => '<code>' . Html::text($preview->errorCode ?? 'preview_build_failed') . '</code>']) . '</p>' : PreviewDiagnosticList::render($diagnostics, $t, $profile);
        return self::document('<main><h1>' . $t->html('exact.failed') . '</h1><div role="alert">' . $reason . '</div><p><a href="' . Html::url($preview === null ? '/previews' : '/previews/' . $preview->id) . '">' . $t->html('preview.list.title') . '</a></p></main>', $t);
    }

    public static function viewer(Translator $t, string $files, string $reload, EditorPreviewPage $page, string $previewId): string
    {
        return self::document('<aside><span>' . $t->html($page->home ? 'exact.home_notice' : 'exact.help') . '</span><a href="' . Html::url($reload) . '">' . $t->html('exact.reload') . '</a></aside>'
            . '<iframe data-editor-exact-frame data-preview-id="' . Html::attribute($previewId) . '" title="' . $t->html('exact.title') . '" src="' . Html::url($files) . '" sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"></iframe>', $t);
    }

    private static function document(string $body, Translator $t): string
    {
        return '<!doctype html><html lang="' . Html::attribute($t->locale()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $t->html('exact.title') . '</title><link rel="stylesheet" href="/assets/preview-share.css"><script type="module" src="/assets/preview-vimeo.js?v=1"></script></head><body>' . $body . '</body></html>';
    }
}
