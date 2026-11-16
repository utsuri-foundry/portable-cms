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
    /** Now or a date; the date field shows only when a date is chosen, and always without `:has()` support. */
    public static function choices(PublicationService $publication, Translator $t): string
    {
        return '<fieldset class="admin-field admin-release__when-group" aria-labelledby="release-when-label" aria-describedby="release-when-help">'
            . FieldHelp::legend('release-when-label', $t->html('schedule.when'), 'release-when-help', $t->text('schedule.when'), $t)
            . FieldHelp::text('release-when-help', $t->html('schedule.help'))
            . '<div class="admin-release__when"><label class="admin-checkbox"><input type="radio" name="timing" value="now" checked> ' . $t->html('schedule.now') . '</label>'
            . '<label class="admin-checkbox"><input type="radio" name="timing" value="later"> ' . $t->html('schedule.later') . '</label></div>'
            . '<div class="admin-release__date">' . self::dateInput($publication, $t) . '</div></fieldset>';
    }

    public static function controls(Release $release, PublicationService $publication, AdminChrome $chrome): string
    {
        $t = $chrome->translator();
        $hidden = '<input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '"><input type="hidden" name="id" value="' . Html::attribute($release->id) . '"><input type="hidden" name="schedule_revision" value="' . $release->scheduleRevision . '"><input type="hidden" name="confirmed" value="1">';
        $move = $release->status === 'scheduled' ? '<form method="post" action="/releases" class="admin-release__move">' . $hidden . '<input type="hidden" name="action" value="move_schedule">' . self::dateInput($publication, $t, $release->scheduledAt) . '<button type="submit">' . $t->html('schedule.move') . '</button></form>' : '';
        return $move . '<form method="post" action="/releases">' . $hidden . '<input type="hidden" name="action" value="cancel_schedule"><button type="submit" class="admin-button--quiet">' . $t->html('schedule.cancel') . '</button></form>';
    }

    /** @param list<Release> $releases */
    public static function notice(array $releases, PublicationService $publication, Translator $t, ?\DateTimeImmutable $now = null): string
    {
        $items = '';
        foreach ($releases as $release) {
            if ($release->status === 'scheduled' && $release->scheduledAt !== null) {
                $items .= '<li>' . $t->html('schedule.included', ['time' => self::when($release->scheduledAt, $publication, $now, $t, true)]) . '</li>';
            }
        }
        return $items === '' ? '' : '<section class="admin-notice" data-release-schedules><ul>' . $items . '</ul><p>' . $t->html('schedule.frozen') . '</p><a href="/releases">' . $t->html('publication.title') . '</a></section>';
    }

    /**
     * A readable time in the installation's zone, relative to today when the page knows the time.
     * Inside a sentence the relative word loses its capital ("published today, 13:25").
     */
    public static function when(\DateTimeImmutable $at, PublicationService $publication, ?\DateTimeImmutable $now, Translator $t, bool $inline = false): string
    {
        $text = AdminShell::timeText($at->setTimezone($publication->timeZone), $now?->setTimezone($publication->timeZone), $t);
        return $inline ? mb_strtolower(mb_substr($text, 0, 1)) . mb_substr($text, 1) : $text;
    }

    private static function dateInput(PublicationService $publication, Translator $t, ?\DateTimeImmutable $at = null): string
    {
        return '<label>' . $t->html('schedule.date', ['zone' => $publication->timeZone->getName()]) . '<input type="datetime-local" name="scheduled_at" step="60" value="' . Html::attribute($at?->setTimezone($publication->timeZone)->format('Y-m-d\TH:i') ?? '') . '"></label>';
    }
}
