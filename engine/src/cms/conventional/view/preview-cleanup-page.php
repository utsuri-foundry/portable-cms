<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Preview\PreviewCleanupItem;
use Utsuri\PortableCms\Conventional\Preview\PreviewCleanupPage;
use Utsuri\PortableCms\Conventional\Preview\PreviewCleanupResult;
use Utsuri\PortableCms\Conventional\Preview\PreviewCleanupReview;

final class PreviewCleanupPageView
{
    public static function listing(PreviewCleanupPage $page, ?string $token, string $csrf, ?AdminChrome $chrome, ?string $recovery = null): string
    {
        $t = AdminShell::translator($chrome);
        $body = $recovery === null ? '' : '<form method="post" action="/previews/delete/review" class="admin-notice admin-notice--warning">' . self::csrf($csrf) . '<p>' . $t->html('cleanup.recovery_help') . '</p><button name="page_selection" value="' . Html::attribute($recovery) . '">' . $t->html('cleanup.recovery') . '</button></form>';
        $body .= '<form method="post" action="/previews/delete/review" data-preview-selection>' . self::csrf($csrf)
            . '<div class="preview-cleanup-toolbar"><span role="status" data-preview-selected-count data-selected-label="' . $t->html('cleanup.selected', ['count' => '{count}']) . '">' . $t->html('cleanup.count_on_review') . '</span>'
            . '<button type="submit" class="admin-button--danger" data-preview-delete-selected>' . $t->html('cleanup.delete_selected') . '</button>';
        if ($token !== null) {
            $body .= '<button type="submit" name="page_selection" value="' . Html::attribute($token) . '">' . $t->html('cleanup.select_page') . '</button>';
        }
        $body .= '</div><p class="admin-meta">' . $t->html('cleanup.page_help') . '</p><ul class="admin-list">';
        foreach ($page->items as $item) {
            $preview = $item->preview;
            $name = $t->text('preview.name', ['id' => substr($preview->id, 0, 8)]);
            $body .= '<li class="admin-list__item preview-cleanup-row"><label class="preview-cleanup-check"><input type="checkbox" name="preview_ids[]" value="' . Html::attribute($preview->id) . '" aria-label="' . $t->html('cleanup.select', ['name' => $name]) . '"' . ($item->eligible() ? '' : ' disabled') . '></label><div class="preview-cleanup-details"><a class="admin-table__title" href="' . Html::url('/previews/' . $preview->id) . '">' . Html::text($name) . '</a><p class="admin-meta">'
                . AdminShell::time($preview->createdAt, $chrome?->now, $t) . ' · ' . Pages::previewMode($preview, $t) . '</p>'
                . ($item->eligible() ? '' : '<p class="admin-meta">' . self::reason($item, $t) . '</p>') . '</div>' . AdminShell::status($preview->status, null, $t) . '</li>';
        }
        if ($page->items === []) {
            $body .= '<li class="admin-empty">' . $t->html('preview.empty.help') . '</li>';
        }
        $body .= '</ul></form><nav class="preview-cleanup-toolbar" aria-label="' . $t->html('cleanup.pagination') . '"><a class="admin-button" href="/previews">' . $t->html('cleanup.latest') . '</a>';
        if ($page->next !== null) {
            $body .= '<a class="admin-button" href="' . Html::url('/previews?before=' . $page->next->value()) . '">' . $t->html('cleanup.older') . '</a>';
        }
        return '<link rel="stylesheet" href="/assets/preview-cleanup.css">' . $body . '</nav><script type="module" src="/assets/preview-cleanup.js"></script>';
    }

    public static function review(PreviewCleanupReview $review, string $csrf, ?ProfileManifest $profile, AdminChrome $chrome): string
    {
        $t = AdminShell::translator($chrome);
        $body = '<p>' . $t->html('cleanup.review_counts', ['eligible' => count($review->approved), 'protected' => count($review->items) - count($review->approved)]) . '</p><ul class="admin-list">';
        $shares = 0;
        foreach ($review->items as $id => $item) {
            $body .= '<li class="admin-list__item"><div><p>' . $t->html('preview.name', ['id' => substr($id, 0, 8)]) . '</p><code>' . Html::text($id) . '</code>';
            if ($item !== null) {
                $body .= '<p class="admin-meta">' . AdminShell::time($item->preview->createdAt, $chrome->now, $t) . '</p><p>' . self::reason($item, $t) . '</p>';
                if (!$item->eligible() && in_array($id, $review->recover, true)) {
                    $body .= '<p>' . $t->html('cleanup.restore_help') . '</p>';
                }
                if (in_array($id, $review->approved, true)) {
                    $shares += $item->activeShares;
                }
            } else {
                $body .= '<p>' . $t->html(in_array($id, $review->recover, true) ? 'cleanup.pending' : 'cleanup.absent') . '</p>';
            }
            $body .= '</div></li>';
        }
        $body .= '</ul>';
        if ($review->approved !== []) {
            $body .= '<div class="admin-notice admin-notice--warning"><p>' . $t->html('cleanup.irreversible') . '</p><p>' . $t->html('cleanup.share_warning', ['count' => $shares]) . '</p></div>';
        }
        if ($review->approved !== [] || $review->recover !== []) {
            $body .= '<form method="post" action="/previews/delete/confirm">' . self::csrf($csrf) . '<input type="hidden" name="selection" value="' . Html::attribute($review->token) . '"><button class="admin-button--danger" type="submit">' . $t->html($review->approved === [] ? 'cleanup.confirm_recovery' : 'cleanup.confirm') . '</button></form>';
        }
        return self::document('cleanup.review_title', $body . '<p><a class="admin-button" href="/previews">' . $t->html('cleanup.cancel') . '</a></p>', $profile, $chrome);
    }

    public static function result(PreviewCleanupResult $result, string $csrf, ?ProfileManifest $profile, AdminChrome $chrome): string
    {
        $t = AdminShell::translator($chrome);
        $body = '<ul class="admin-list" role="status">';
        foreach ($result->outcomes as $id => $outcome) {
            $key = match ($outcome) {
                'restored' => 'cleanup.restored',
                'deleted' => 'cleanup.deleted', 'absent' => 'cleanup.absent', 'protected' => 'cleanup.protected', 'cleanup_pending' => 'cleanup.pending', 'unprocessed' => 'cleanup.unprocessed', default => 'cleanup.failed',
            };
            $body .= '<li class="admin-list__item"><div><p>' . $t->html('preview.name', ['id' => substr($id, 0, 8)]) . '</p><code>' . Html::text($id) . '</code><p>' . $t->html($key) . '</p></div></li>';
        }
        $body .= '</ul>';
        if ($result->retry !== null) {
            $body .= '<form method="post" action="/previews/delete/confirm">' . self::csrf($csrf) . '<input type="hidden" name="selection" value="' . Html::attribute($result->retry) . '"><p>' . $t->html('cleanup.retry_help') . '</p><button type="submit">' . $t->html('cleanup.retry') . '</button></form>';
        }
        return self::document('cleanup.result_title', $body . '<p><a href="/previews">' . $t->html('cleanup.back') . '</a></p>', $profile, $chrome);
    }

    public static function invalid(?ProfileManifest $profile, AdminChrome $chrome): string
    {
        $t = AdminShell::translator($chrome);
        return self::document('cleanup.review_title', '<div role="alert"><p>' . $t->html('cleanup.invalid') . '</p><a href="/previews">' . $t->html('cleanup.back') . '</a></div>', $profile, $chrome);
    }

    private static function reason(PreviewCleanupItem $item, Translator $t): string
    {
        return $t->html(match ($item->reason()) {
            'holding' => 'cleanup.holding', 'release' => 'cleanup.release', 'busy' => 'cleanup.busy', default => 'cleanup.eligible',
        });
    }

    private static function csrf(string $csrf): string
    {
        return '<input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '">';
    }

    private static function document(string $title, string $body, ?ProfileManifest $profile, AdminChrome $chrome): string
    {
        $t = AdminShell::translator($chrome);
        return AdminShell::application($t->text($title), TrustedHtml::fromEngineMarkup(AdminShell::breadcrumbs([[$t->text('preview.list.title'), '/previews'], [$t->text($title), null]], $t) . AdminShell::header($t->text($title)) . '<section class="admin-form">' . $body . '</section>'), $profile, 'previews', 'standard', $chrome);
    }
}
