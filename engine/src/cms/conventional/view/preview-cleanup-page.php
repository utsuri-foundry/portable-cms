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
use Utsuri\PortableCms\Conventional\Preview\PreviewStatus;

/**
 * The previews list when the installation can delete in bulk. The ordinary page shows plain rows;
 * "Select several" turns on checkboxes and the selection toolbar. Pages beyond the first keep the
 * same rows with Older and Latest links.
 */
final class PreviewCleanupPageView
{
    /**
     * @param (\Closure(PreviewStatus): string)|null $sharePanel
     * @param (\Closure(string): ?string)|null $actorName
     */
    public static function listing(PreviewCleanupPage $page, ?string $token, string $csrf, ?AdminChrome $chrome, ?string $recovery = null, bool $selecting = false, ?\Closure $sharePanel = null, ?\Closure $actorName = null, ?\DateTimeZone $zone = null): string
    {
        $t = AdminShell::translator($chrome);
        $body = $recovery === null ? '' : '<form method="post" action="/previews/delete/review" class="admin-notice admin-notice--warning">' . self::csrf($csrf) . '<p>' . $t->html('cleanup.recovery_help') . '</p><button name="page_selection" value="' . Html::attribute($recovery) . '">' . $t->html('cleanup.recovery') . '</button></form>';
        $rows = '';
        foreach ($page->items as $item) {
            $rows .= Pages::previewRow($item->preview, $item, $selecting, $csrf, $chrome, $t, $sharePanel, $actorName, $zone);
        }
        $list = $rows === '' ? Pages::previewEmpty($t) : '<ul class="admin-list">' . $rows . '</ul>';
        $query = $selecting ? '&select=1' : '';
        $nav = '<nav class="preview-cleanup-toolbar" aria-label="' . $t->html('cleanup.pagination') . '">';
        if ($page->next !== null) {
            $nav .= '<a class="admin-button" href="' . Html::url('/previews?before=' . $page->next->value() . $query) . '">' . $t->html('cleanup.older') . '</a>';
        }
        if ($selecting) {
            $body .= '<form method="post" action="/previews/delete/review" data-preview-selection>' . self::csrf($csrf)
                . '<div class="preview-cleanup-toolbar"><span role="status" data-preview-selected-count data-selected-label="' . $t->html('cleanup.selected', ['count' => '{count}']) . '">' . $t->html('cleanup.count_on_review') . '</span>'
                . '<button type="submit" class="admin-button--danger" data-preview-delete-selected>' . $t->html('cleanup.delete_selected') . '</button>';
            if ($token !== null) {
                $body .= '<button type="submit" name="page_selection" value="' . Html::attribute($token) . '">' . $t->html('cleanup.select_page') . '</button>';
            }
            $body .= '</div><p class="admin-meta">' . $t->html('cleanup.page_help') . '</p>' . $list . '</form>';
            $nav .= '<a class="admin-button admin-button--quiet" href="/previews">' . $t->html('cleanup.cancel') . '</a>';
        } else {
            $body .= $list;
            if (count($page->items) > 1 || $page->next !== null) {
                $nav .= '<a class="admin-button admin-button--quiet" href="' . Html::url('/previews?select=1') . '" data-preview-select>' . $t->html('cleanup.select_several') . '</a>';
            }
        }
        return '<link rel="stylesheet" href="/assets/preview-cleanup.css?v=2">' . $body . $nav . '</nav>' . ($selecting ? '<script type="module" src="/assets/preview-cleanup.js"></script>' : '');
    }

    public static function review(PreviewCleanupReview $review, string $csrf, ?ProfileManifest $profile, AdminChrome $chrome): string
    {
        $t = AdminShell::translator($chrome);
        $body = '<p>' . $t->html('cleanup.review_counts', ['eligible' => count($review->approved), 'protected' => count($review->items) - count($review->approved)]) . '</p><ul class="admin-list">';
        $shares = 0;
        foreach ($review->items as $id => $item) {
            $body .= '<li class="admin-list__item"><div>';
            if ($item !== null) {
                $body .= '<p class="admin-table__title">' . Html::text(Pages::previewName($item->preview, $chrome, $t)) . '</p><p class="admin-meta">' . self::reason($item, $t) . '</p>';
                if (!$item->eligible() && in_array($id, $review->recover, true)) {
                    $body .= '<p>' . $t->html('cleanup.restore_help') . '</p>';
                }
                if (in_array($id, $review->approved, true)) {
                    $shares += $item->activeShares;
                }
            } else {
                $body .= '<p class="admin-table__title">' . $t->html('preview.gone') . '</p><p class="admin-meta">' . $t->html(in_array($id, $review->recover, true) ? 'cleanup.pending' : 'cleanup.absent') . '</p>';
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
        $counts = [];
        foreach ($result->outcomes as $outcome) {
            $key = match ($outcome) {
                'restored' => 'cleanup.restored',
                'deleted' => 'cleanup.deleted', 'absent' => 'cleanup.absent', 'protected' => 'cleanup.protected', 'cleanup_pending' => 'cleanup.pending', 'unprocessed' => 'cleanup.unprocessed', default => 'cleanup.failed',
            };
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $body = '<ul class="admin-list" role="status">';
        foreach ($counts as $key => $count) {
            $body .= '<li class="admin-list__item"><div><p class="admin-table__title">' . $t->html('cleanup.previews', ['count' => $count]) . '</p><p class="admin-meta">' . $t->html($key) . '</p></div></li>';
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

    /** Why a preview cannot be deleted right now, or that it can. */
    public static function reason(PreviewCleanupItem $item, Translator $t): string
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
