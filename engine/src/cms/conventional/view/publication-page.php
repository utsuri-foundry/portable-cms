<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\PreviewStatus;
use Utsuri\PortableCms\Conventional\Publication\PublicationService;
use Utsuri\PortableCms\Conventional\Publication\Release;

/**
 * Releases: what visitors see now, what is ready to publish, and what can be restored. The page
 * speaks of pages, times and people; identities, checksums and the worker stay out of sight.
 */
final class PublicationPage
{
    private const VISIBLE_CANDIDATES = 3;

    /** @param ?\Closure(string): ?string $actorName names an account for "by {name}" */
    public static function render(ProfileManifest $profile, AdminChrome $chrome, ?PublicationService $publication, ?PreviewService $previews, ?string $error = null, ?\Closure $actorName = null): string
    {
        $t = AdminShell::translator($chrome);
        $manage = $chrome->role !== 'editor';
        $body = AdminShell::header($t->text('publication.title'), '<p class="admin-lede">' . $t->html('publication.lede') . '</p>');
        if ($publication === null || $previews === null) {
            $body .= '<section class="admin-notice"><p>' . $t->html('publication.unavailable') . '</p></section>';
            return AdminShell::application($t->text('publication.title'), TrustedHtml::fromEngineMarkup($body), $profile, 'releases', 'standard', $chrome);
        }
        $head = $publication->head();
        $holding = $publication->holding;
        $settings = $holding?->settings->load();
        $comingSoon = $settings?->mode() === 'coming_soon';
        $releases = $publication->list();
        $isComingSoonRelease = static fn(Release $release): bool => $holding?->builds->find($release->previewId) !== null;
        if ($error !== null) {
            $key = match ($error) {
                'schedule_time_invalid' => 'schedule.error.invalid',
                'schedule_time_ambiguous' => 'schedule.error.ambiguous',
                'schedule_time_range' => 'schedule.error.range',
                default => 'publication.error',
            };
            $body .= '<section class="admin-notice admin-notice--danger" role="alert"><p>' . $t->html($key)
                . ($manage ? FieldHelp::toggle('release-error-details', $t->text('publication.error'), $t) : '') . '</p>'
                . ($manage ? FieldHelp::text('release-error-details', $t->html('publication.error_details', ['code' => $error])) : '') . '</section>';
        }

        // On the website now.
        $active = null;
        foreach ($releases as $release) {
            if ($release->id === $head->activeId) {
                $active = $release;
            }
        }
        $body .= '<section class="admin-card admin-release" aria-labelledby="release-now"><h2 id="release-now">' . $t->html('publication.now.title') . '</h2>';
        if ($active === null) {
            $body .= '<p class="admin-meta">' . $t->html('publication.now.none') . '</p>';
        } else {
            $at = $active->updatedAt ?? $active->createdAt;
            $name = $actorName === null ? null : $actorName($active->actorId);
            $body .= '<div class="admin-release__now"><div><p><strong>' . $t->html($isComingSoonRelease($active) ? 'publication.what.coming_soon' : 'publication.what.website') . '</strong></p>'
                . ($at === null ? '' : '<p class="admin-meta">' . ($name === null ? $t->html('publication.now.at', ['time' => ReleaseScheduleView::when($at, $publication, $chrome->now, $t, true)]) : $t->html('publication.now.by', ['time' => ReleaseScheduleView::when($at, $publication, $chrome->now, $t, true), 'name' => $name])) . '</p>')
                . '</div><a class="admin-button" href="' . Html::attribute($publication->websiteUrl()) . '" target="_blank" rel="noopener">' . $t->html('publication.open_website') . '</a></div>';
        }
        $body .= '</section>';

        // A running publication is one line; the rest of the page waits for it.
        if ($head->pendingId !== null) {
            $body .= '<section class="admin-notice" role="status"><p>' . $t->html('publication.pending') . '</p>'
                . ($manage ? self::form('wake', $head->pendingId, $head->activeId, $chrome, $t->text('publication.refresh_work'), false, quiet: true) : '') . '</section>';
        } elseif ($publication->runnable()) {
            $body .= '<section class="admin-notice" role="status"><p>' . $t->html('publication.feed_pending') . '</p></section>';
        }

        if ($manage && $head->pendingId === null) {
            $body .= self::ready($publication, $previews, $chrome, $t, $comingSoon, $head->activeId, $settings?->revision());
        }

        // Scheduled releases, with their own controls.
        $scheduled = array_values(array_filter($releases, static fn(Release $release): bool => in_array($release->status, ['scheduled', 'needs_approval'], true)));
        if ($scheduled !== []) {
            $body .= '<section class="admin-card admin-release" aria-labelledby="release-scheduled"><h2 id="release-scheduled">' . $t->html('publication.scheduled.title') . '</h2><ul class="admin-release__rows">';
            foreach ($scheduled as $release) {
                $body .= '<li class="admin-release__row" data-release-status="' . Html::attribute($release->status) . '" data-scheduled-release="' . Html::attribute($release->id) . '"><div>'
                    . '<p><strong>' . $t->html($isComingSoonRelease($release) ? 'publication.what.coming_soon' : 'publication.what.website') . '</strong></p>'
                    . '<p class="admin-meta">' . ($release->status === 'scheduled' && $release->scheduledAt !== null ? $t->html('schedule.for', ['time' => ReleaseScheduleView::when($release->scheduledAt, $publication, $chrome->now, $t, true)]) : $t->html('schedule.stale')) . '</p></div>'
                    . ($manage ? '<div class="admin-release__actions">' . ReleaseScheduleView::controls($release, $publication, $chrome) . '</div>' : '') . '</li>';
            }
            $body .= '</ul></section>';
        }

        // Earlier releases: dated rows with a readable status, Restore and a quiet Remove.
        $earlier = array_values(array_filter($releases, static fn(Release $release): bool => !in_array($release->status, ['scheduled', 'needs_approval'], true) && $release->id !== $head->pendingId));
        $body .= '<section class="admin-card admin-release" aria-labelledby="release-earlier">'
            . FieldHelp::heading('<h2 id="release-earlier">' . $t->html('publication.history') . '</h2>', 'release-earlier-help', $t->text('publication.history'), $t)
            . FieldHelp::text('release-earlier-help', $t->html('publication.history.help'));
        if ($earlier === []) {
            $body .= '<p class="admin-meta">' . $t->html('publication.history.none') . '</p>';
        } else {
            $body .= '<ul class="admin-release__rows">';
            foreach ($earlier as $release) {
                $at = $release->updatedAt ?? $release->createdAt;
                $comingSoonRelease = $isComingSoonRelease($release);
                $body .= '<li class="admin-release__row" data-release-status="' . Html::attribute($release->status) . '"' . ($release->scheduledAt === null ? '' : ' data-scheduled-release="' . Html::attribute($release->id) . '"') . '><div>'
                    . '<p><strong>' . $t->html($comingSoonRelease ? 'publication.what.coming_soon' : 'publication.what.website') . '</strong>' . ($at === null ? '' : ' <span class="admin-meta">' . Html::text(ReleaseScheduleView::when($at, $publication, $chrome->now, $t)) . '</span>') . '</p>'
                    . '<p class="admin-meta">' . $t->html('publication.status.' . $release->status) . '</p></div>';
                if ($manage) {
                    $actions = '';
                    if ($release->status === 'retained') {
                        $actions .= '<details class="admin-optional"><summary><span>' . $t->html('publication.rollback') . '</span></summary>' . self::form('rollback', $release->id, $head->activeId, $chrome, $t->text('publication.rollback'), true, $comingSoonRelease ? 'holding.publish_confirm' : 'publication.confirm') . '</details>';
                    }
                    if (in_array($release->status, ['retained', 'failed', 'cancelled', 'needs_approval'], true)) {
                        $actions .= '<details class="admin-optional"><summary><span>' . $t->html('publication.forget') . '</span></summary><p class="admin-meta">' . $t->html('publication.forget_help') . '</p>' . self::form('forget', $release->id, $head->activeId, $chrome, $t->text('publication.forget'), true, 'publication.forget_confirm') . '</details>';
                    }
                    $body .= $actions === '' ? '' : '<div class="admin-release__actions">' . $actions . '</div>';
                }
                $body .= '</li>';
            }
            $body .= '</ul>';
        }
        $body .= '</section>';
        return AdminShell::application($t->text('publication.title'), TrustedHtml::fromEngineMarkup($body), $profile, 'releases', 'standard', $chrome);
    }

    /** One form: choose what to publish, when, confirm, Publish. The coming soon build is a secondary action. */
    private static function ready(PublicationService $publication, PreviewService $previews, AdminChrome $chrome, Translator $t, bool $comingSoon, ?string $activeId, ?int $settingsRevision): string
    {
        $holding = $publication->holding;
        $candidates = [];
        $building = [];
        $attention = [];
        foreach (($comingSoon && $holding !== null ? $holding->builds : $previews)->list() as $preview) {
            if ($preview->status === 'ready' && !$preview->includeDrafts && !$preview->includePreparation) {
                $candidates[] = $preview;
            } elseif ($comingSoon && in_array($preview->status, ['queued', 'building'], true)) {
                $building[] = $preview;
            } elseif ($comingSoon && $preview->status === 'needs_attention') {
                $attention[] = $preview;
            }
        }
        $body = '<section class="admin-card admin-release" aria-labelledby="release-ready">'
            . FieldHelp::heading('<h2 id="release-ready">' . $t->html('publication.ready.title') . '</h2>', 'release-ready-help', $t->text('publication.ready.title'), $t)
            . FieldHelp::text('release-ready-help', $t->html($comingSoon ? 'publication.ready.help_coming_soon' : 'publication.ready.help'));
        if ($candidates === []) {
            $body .= '<p class="admin-meta">' . $t->html('publication.ready.none') . ($comingSoon ? '' : ' <a href="/previews">' . $t->html('publication.ready.create_preview') . '</a>') . '</p>';
        } else {
            $body .= '<form method="post" action="/releases" class="admin-release__form">' . self::hidden($chrome, $activeId) . '<input type="hidden" name="action" value="approve">'
                . '<fieldset class="admin-field"><legend>' . $t->html('publication.choose') . '</legend><ul class="admin-release__candidates">';
            foreach (array_slice($candidates, 0, self::VISIBLE_CANDIDATES) as $index => $preview) {
                $body .= self::candidate($preview, $index === 0, $publication, $chrome, $t, $comingSoon);
            }
            $older = array_slice($candidates, self::VISIBLE_CANDIDATES);
            if ($older !== []) {
                $body .= '<li><details><summary>' . $t->html('publication.older', ['count' => count($older)]) . '</summary><ul class="admin-release__candidates">';
                foreach ($older as $preview) {
                    $body .= self::candidate($preview, false, $publication, $chrome, $t, $comingSoon);
                }
                $body .= '</ul></details></li>';
            }
            $body .= '</ul></fieldset>' . ReleaseScheduleView::choices($publication, $t)
                . '<label class="admin-checkbox"><input type="checkbox" name="confirmed" value="1" required> ' . $t->html($comingSoon ? 'holding.publish_confirm' : 'publication.confirm') . '</label>'
                . '<div class="admin-actions"><button type="submit" class="admin-button--primary">' . $t->html('publication.approve') . '</button></div></form>';
        }
        if ($comingSoon && $holding !== null && $settingsRevision !== null) {
            foreach ($building as $preview) {
                $body .= '<p class="admin-meta" data-holding-build="' . Html::attribute($preview->id) . '">' . $t->html('holding.building', ['status' => $t->text('holding.status.' . $preview->status)]) . '</p>';
            }
            foreach ($attention as $preview) {
                $body .= '<div class="admin-release__actions" data-holding-build="' . Html::attribute($preview->id) . '"><span class="admin-meta">' . $t->html('publication.what.coming_soon') . ' · ' . $t->html('holding.status.needs_attention') . '</span>'
                    . self::form('delete_holding', $preview->id, $activeId, $chrome, $t->text('holding.delete'), true, 'holding.delete_help', quiet: true) . '</div>';
            }
            $body .= '<div class="admin-release__build"><p class="admin-meta">' . $t->html('holding.build_question') . '</p><form method="post" action="/releases">' . self::hidden($chrome, $activeId)
                . '<input type="hidden" name="action" value="build_holding"><input type="hidden" name="id" value="' . str_repeat('0', 32) . '"><input type="hidden" name="_revision" value="' . $settingsRevision . '">'
                . '<button type="submit">' . $t->html('holding.build') . '</button>' . FieldHelp::toggle('release-build-help', $t->text('holding.build'), $t) . '</form>' . FieldHelp::text('release-build-help', $t->html('holding.build_help'))
                . '<p class="admin-meta"><a href="/settings/publication">' . $t->html('holding.settings') . '</a></p></div>';
        }
        return $body . '</section>';
    }

    private static function candidate(PreviewStatus $preview, bool $checked, PublicationService $publication, AdminChrome $chrome, Translator $t, bool $comingSoon): string
    {
        $label = $t->text('publication.candidate', ['what' => $t->text($comingSoon ? 'publication.what.coming_soon' : 'publication.what.website'), 'time' => ReleaseScheduleView::when($preview->createdAt, $publication, $chrome->now, $t)]);
        $href = $comingSoon ? '/releases/holding/' . $preview->id . '/artifact/' : '/previews/' . $preview->id;
        return '<li class="admin-release__candidate"><label class="admin-checkbox"><input type="radio" name="id" value="' . Html::attribute($preview->id) . '"' . ($checked ? ' checked' : '') . ' required> ' . Html::text($label) . '</label>'
            . '<a href="' . Html::url($href) . '">' . $t->html('publication.inspect') . '</a></li>';
    }

    private static function hidden(AdminChrome $chrome, ?string $previous): string
    {
        return '<input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '"><input type="hidden" name="previous" value="' . Html::attribute($previous ?? '') . '">';
    }

    private static function form(string $action, string $id, ?string $previous, AdminChrome $chrome, string $label, bool $confirmed, string $confirmationKey = 'publication.confirm', bool $quiet = false): string
    {
        $t = AdminShell::translator($chrome);
        return '<form method="post" action="/releases" class="admin-release__action">' . self::hidden($chrome, $previous)
            . '<input type="hidden" name="action" value="' . Html::attribute($action) . '"><input type="hidden" name="id" value="' . Html::attribute($id) . '">'
            . ($confirmed ? '<label class="admin-checkbox"><input type="checkbox" name="confirmed" value="1" required> ' . $t->html($confirmationKey) . '</label>' : '')
            . '<button type="submit"' . ($quiet ? ' class="admin-button--quiet"' : '') . '>' . Html::text($label) . '</button></form>';
    }
}
