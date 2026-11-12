<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Publication\PublicationService;

final class PublicationPage
{
    public static function render(ProfileManifest $profile, AdminChrome $chrome, ?PublicationService $publication, ?PreviewService $previews, ?string $error = null): string
    {
        $t = AdminShell::translator($chrome);
        $manage = $chrome->role !== 'editor';
        $body = AdminShell::header($t->text('publication.title'), '<p>' . $t->html('publication.help') . '</p>');
        if ($publication === null || $previews === null) {
            $body .= '<section class="admin-notice"><p>' . $t->html('publication.unavailable') . '</p></section>';
        } else {
            $head = $publication->head();
            $holding = $publication->holding;
            $settings = $holding?->settings->load();
            $comingSoon = $settings?->mode() === 'coming_soon';
            if ($settings !== null) {
                $body .= '<section class="admin-notice"><h2>' . $t->html('holding.mode.' . $settings->mode()) . '</h2><p>' . $t->html('holding.standard') . '</p>' . ($manage ? '<p><a href="/settings/publication">' . $t->html('holding.settings') . '</a></p>' : '') . '</section>';
                if ($comingSoon && $manage) {
                    $body .= '<section class="admin-card"><h2>' . $t->html('holding.build') . '</h2><p>' . $t->html('holding.build_help') . '</p><form method="post" action="/releases">' . self::hidden($chrome, $head->activeId)
                        . '<input type="hidden" name="action" value="build_holding"><input type="hidden" name="id" value="' . str_repeat('0', 32) . '"><input type="hidden" name="_revision" value="' . $settings->revision() . '"><button type="submit">' . $t->html('holding.build') . '</button></form></section>';
                }
                $body .= $manage ? self::holdingBuilds($publication, $chrome, $head->activeId) : '';
            }
            if ($error !== null) {
                $key = match ($error) {
                    'schedule_time_invalid' => 'schedule.error.invalid',
                    'schedule_time_ambiguous' => 'schedule.error.ambiguous',
                    'schedule_time_range' => 'schedule.error.range',
                    default => 'publication.error',
                };
                $body .= '<section class="admin-notice admin-notice--danger" role="alert"><p>' . $t->html($key) . '</p><p><code>' . Html::text($error) . '</code></p></section>';
            }
            $body .= '<p>' . $t->html('publication.current', ['id' => $head->activeId ?? $t->text('publication.none')]) . '</p>';
            if ($head->pendingId === null && $publication->runnable()) {
                $body .= '<section class="admin-notice" role="status"><p>' . $t->html('publication.feed_pending') . '</p></section>';
            }
            if ($head->pendingId !== null) {
                $body .= '<section class="admin-notice" role="status"><p>' . $t->html('publication.pending') . '</p>' . ($manage ? self::form('wake', $head->pendingId, $head->activeId, $chrome, $t->text('publication.refresh_work'), false) : '') . '</section>';
            } elseif ($manage) {
                $options = '';
                foreach (($comingSoon ? $holding->builds : $previews)->list() as $preview) {
                    if ($preview->status === 'ready' && !$preview->includeDrafts && !$preview->includePreparation) {
                        $options .= '<option value="' . Html::attribute($preview->id) . '">' . Html::text($preview->createdAt->format('Y-m-d H:i') . ' · ' . substr($preview->id, 0, 8)) . '</option>';
                    }
                }
                if ($options !== '') {
                    $body .= '<section class="admin-card"><h2>' . $t->html('publication.approve') . '</h2><p>' . ($comingSoon ? $t->html('holding.inspect_help') : '<a href="/previews">' . $t->html('publication.inspect') . '</a>') . '</p>'
                        . '<form method="post" action="/releases">' . self::hidden($chrome, $head->activeId)
                        . '<input type="hidden" name="action" value="approve"><label>' . $t->html($comingSoon ? 'holding.builds' : 'publication.preview') . '<select name="id" required>' . $options . '</select></label>'
                        . ReleaseScheduleView::choices($publication, $t)
                        . '<label><input type="checkbox" name="confirmed" value="1" required> ' . $t->html($comingSoon ? 'holding.publish_confirm' : 'publication.confirm') . '</label><button type="submit">' . $t->html('publication.approve') . '</button></form></section>';
                }
            }
            $body .= '<section class="admin-card"><h2>' . $t->html('publication.history') . '</h2><ul class="admin-list">';
            foreach ($publication->list() as $release) {
                $internal = $holding?->builds->find($release->previewId) !== null;
                $body .= '<li><h3><code>' . Html::text(substr($release->id, 0, 8)) . '</code> · ' . $t->html('publication.status.' . $release->status) . '</h3><p>' . ($internal ? $t->html('holding.mode.coming_soon') : '<a href="' . Html::url('/previews/' . $release->previewId) . '">' . $t->html('publication.inspect') . '</a>') . '</p>';
                if ($release->scheduledAt !== null) {
                    $body .= '<p data-scheduled-release="' . Html::attribute($release->id) . '">' . Html::text(ReleaseScheduleView::time($release, $publication)) . '</p>';
                }
                if (in_array($release->status, ['scheduled', 'needs_approval'], true)) {
                    $body .= '<p>' . $t->html($release->status === 'scheduled' ? 'schedule.interval' : 'schedule.stale') . '</p>';
                    $body .= $manage ? ReleaseScheduleView::controls($release, $publication, $chrome) : '';
                }
                if ($release->errorCode !== null) {
                    $body .= '<p><code>' . Html::text($release->errorCode) . '</code></p>';
                }
                if ($manage && $head->pendingId === null && $release->status === 'retained') {
                    $body .= self::form('rollback', $release->id, $head->activeId, $chrome, $t->text('publication.rollback'), true, $internal ? 'holding.publish_confirm' : 'publication.confirm');
                }
                if ($manage && $head->pendingId === null && in_array($release->status, ['retained', 'failed', 'cancelled', 'needs_approval'], true)) {
                    $body .= '<details><summary>' . $t->html('publication.retention') . '</summary><p>' . $t->html('publication.forget_help') . '</p>' . self::form('forget', $release->id, $head->activeId, $chrome, $t->text('publication.forget'), true) . '</details>';
                }
                $body .= '</li>';
            }
            $body .= '</ul></section>';
        }
        return AdminShell::application($t->text('publication.title'), TrustedHtml::fromEngineMarkup($body), $profile, 'releases', 'standard', $chrome);
    }

    private static function hidden(AdminChrome $chrome, ?string $previous): string
    {
        return '<input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '"><input type="hidden" name="previous" value="' . Html::attribute($previous ?? '') . '">';
    }

    private static function holdingBuilds(PublicationService $publication, AdminChrome $chrome, ?string $previous): string
    {
        $t = AdminShell::translator($chrome);
        $builds = $publication->holding?->builds->list() ?? [];
        if ($builds === []) {
            return '';
        }
        $body = '<section class="admin-card"><h2>' . $t->html('holding.builds') . '</h2><ul class="admin-list">';
        foreach ($builds as $build) {
            $body .= '<li><code>' . Html::text(substr($build->id, 0, 8)) . '</code> · ' . $t->html('holding.status.' . $build->status);
            if ($build->errorCode !== null) {
                $body .= '<p><code>' . Html::text($build->errorCode) . '</code></p>';
            }
            if (in_array($build->status, ['ready', 'needs_attention'], true)) {
                if ($build->status === 'ready') {
                    $body .= '<p><a href="' . Html::url('/releases/holding/' . $build->id . '/artifact/') . '">' . $t->html('publication.inspect') . '</a></p>';
                }
                $body .= '<details><summary>' . $t->html('publication.retention') . '</summary>' . self::form('delete_holding', $build->id, $previous, $chrome, $t->text('holding.delete'), true) . '</details>';
            }
            $body .= '</li>';
        }
        return $body . '</ul></section>';
    }

    private static function form(string $action, string $id, ?string $previous, AdminChrome $chrome, string $label, bool $confirmed, string $confirmationKey = 'publication.confirm'): string
    {
        $t = AdminShell::translator($chrome);
        return '<form method="post" action="/releases">' . self::hidden($chrome, $previous)
            . '<input type="hidden" name="action" value="' . Html::attribute($action) . '"><input type="hidden" name="id" value="' . Html::attribute($id) . '">'
            . ($confirmed ? '<label><input type="checkbox" name="confirmed" value="1" required> ' . $t->html($confirmationKey) . '</label>' : '')
            . '<button type="submit">' . Html::text($label) . '</button></form>';
    }
}
