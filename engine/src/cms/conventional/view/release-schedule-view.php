<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Publication\PublicationService;
use Utsuri\PortableCms\Conventional\Publication\Release;

/** Native schedule controls and read-only notices share one explicit zone projection. */
final class ReleaseScheduleView
{
    public static function choices(PublicationService $publication, Translator $t): string
    {
        return '<label>' . $t->html('schedule.when') . '<select name="timing"><option value="now">' . $t->html('schedule.now') . '</option><option value="later">' . $t->html('schedule.later') . '</option></select></label>'
            . self::dateInput($publication, $t) . '<p class="admin-help">' . $t->html('schedule.interval') . '</p>';
    }

    public static function controls(Release $release, PublicationService $publication, AdminChrome $chrome): string
    {
        $t = $chrome->translator();
        $hidden = '<input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '"><input type="hidden" name="id" value="' . Html::attribute($release->id) . '"><input type="hidden" name="schedule_revision" value="' . $release->scheduleRevision . '"><input type="hidden" name="confirmed" value="1">';
        $move = $release->status === 'scheduled' ? '<form method="post" action="/releases">' . $hidden . '<input type="hidden" name="action" value="move_schedule">' . self::dateInput($publication, $t, $release->scheduledAt) . '<button type="submit">' . $t->html('schedule.move') . '</button></form>' : '';
        return $move . '<form method="post" action="/releases">' . $hidden . '<input type="hidden" name="action" value="cancel_schedule"><button type="submit" class="admin-button--danger">' . $t->html('schedule.cancel') . '</button></form>';
    }

    /** @param list<Release> $releases */
    public static function notice(array $releases, PublicationService $publication, Translator $t): string
    {
        $items = '';
        foreach ($releases as $release) {
            if ($release->status === 'scheduled') {
                $items .= '<li>' . $t->html('schedule.included', ['time' => self::time($release, $publication), 'id' => substr($release->id, 0, 8)]) . '</li>';
            }
        }
        return $items === '' ? '' : '<section class="admin-notice" data-release-schedules><ul>' . $items . '</ul><p>' . $t->html('schedule.frozen') . '</p><a href="/releases">' . $t->html('publication.title') . '</a></section>';
    }

    public static function time(Release $release, PublicationService $publication): string
    {
        return $release->scheduledAt?->setTimezone($publication->timeZone)->format('Y-m-d H:i T (e)') ?? '';
    }

    private static function dateInput(PublicationService $publication, Translator $t, ?\DateTimeImmutable $at = null): string
    {
        return '<label>' . $t->html('schedule.date', ['zone' => $publication->timeZone->getName()]) . '<input type="datetime-local" name="scheduled_at" step="60" value="' . Html::attribute($at?->setTimezone($publication->timeZone)->format('Y-m-d\TH:i') ?? '') . '"></label>';
    }
}
