<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Preview\PreviewStatus;
use Utsuri\PortableCms\Conventional\Preview\SharedLink;
use Utsuri\PortableCms\Conventional\Preview\SharedLinkLifetime;

/**
 * Sharing in the editor's words. The Share panel asks three things: what the link shows, for how
 * long, and who it is for. The Shared links list names each link by its note, says what it shows
 * and until when in the site's time zone, and offers Copy, Extend and Stop; an expired link keeps
 * Extend and Remove. Identities, hashes and UTC never appear.
 */
final class SharedLinksView
{
    public static function panel(PreviewStatus $preview, string $csrf, AdminChrome $chrome): string
    {
        $t = $chrome->translator();
        $id = 'share-' . substr($preview->id, 0, 8);
        return '<details class="admin-optional admin-share" data-share-panel><summary><span>' . $t->html('share.title') . '</span></summary>'
            . '<form method="post" action="/previews/shares" class="admin-share__form">' . self::csrf($csrf)
            . '<input type="hidden" name="preview" value="' . Html::attribute($preview->id) . '">'
            . '<fieldset class="admin-field" aria-labelledby="' . $id . '-what" aria-describedby="' . $id . '-what-help">'
            . FieldHelp::legend($id . '-what', $t->html('share.what'), $id . '-what-help', $t->text('share.what'), $t)
            . FieldHelp::text($id . '-what-help', $t->html('share.what_help'))
            . '<div class="admin-share__choices">'
            . '<label class="admin-checkbox"><input type="radio" name="kind" value="preview" checked> ' . $t->html('share.kind.preview') . '</label>'
            . '<label class="admin-checkbox"><input type="radio" name="kind" value="newest"> ' . $t->html('share.kind.newest') . '</label></div></fieldset>'
            . self::lifetime($id . '-days', $t)
            . '<div class="admin-field"><label for="' . $id . '-note">' . $t->html('share.note') . '</label>'
            . '<input id="' . $id . '-note" type="text" name="note" maxlength="120" autocomplete="off"></div>'
            . '<div class="admin-actions"><button type="submit" class="admin-button--primary">' . $t->html('share.create') . '</button></div>'
            . '</form></details>';
    }

    /**
     * @param list<SharedLink> $links
     * @param \Closure(string): ?string $actorName
     * @param \Closure(string): ?\DateTimeImmutable $previewTime
     */
    public static function section(array $links, ?SharedLink $created, string $csrf, AdminChrome $chrome, \DateTimeZone $zone, ?\Closure $actorName, \Closure $previewTime): string
    {
        if ($links === [] && $created === null) {
            return '';
        }
        $t = $chrome->translator();
        $html = '<section class="admin-section admin-share__links" id="shared-links" aria-labelledby="shared-links-title"><h2 id="shared-links-title">' . $t->html('share.links') . '</h2>';
        if ($created !== null && $created->url !== null) {
            $html .= '<div class="admin-notice admin-share__created" data-share-created><p class="admin-share__name">' . Html::text($created->note ?? $t->text('share.created')) . '</p>'
                . '<div class="admin-share__copy"><label class="admin-visually-hidden" for="share-link">' . $t->html('share.link') . '</label>'
                . '<input id="share-link" type="text" readonly value="' . Html::attribute($created->url) . '">' . self::copy($created->url, $t) . '</div>'
                . '<p class="admin-meta">' . self::shows($created, $chrome, $zone, $previewTime, $t) . '</p></div>';
        }
        $rows = '';
        $number = count($links);
        foreach ($links as $link) {
            $name = $link->note ?? $t->text('share.name', ['number' => $number]);
            --$number;
            $expired = $link->expired($chrome->now);
            $meta = [self::what($link, $chrome, $zone, $previewTime, $t)];
            $meta[] = $t->html($expired ? 'share.expired' : 'share.until', ['time' => self::when($link->expiresAt, $zone, $chrome->now, $t, true)]);
            $by = $link->createdBy === null || $actorName === null ? null : $actorName($link->createdBy);
            if ($by !== null) {
                $meta[] = $t->html('share.by', ['name' => $by]);
            }
            $route = '/previews/shares/' . $link->kind . '/' . $link->id;
            $actions = $link->url === null || $expired ? '' : self::copy($link->url, $t);
            $actions .= '<details class="admin-optional admin-share__extend"><summary><span>' . $t->html('share.extend') . '</span></summary>'
                . '<form method="post" action="' . Html::url($route . '/extend') . '">' . self::csrf($csrf) . self::lifetime('extend-' . substr($link->id, 0, 8), $t)
                . '<button type="submit">' . $t->html('share.extend') . '</button></form></details>';
            $actions .= '<form method="post" action="' . Html::url($route . '/stop') . '">' . self::csrf($csrf)
                . '<button type="submit" class="admin-button--quiet">' . $t->html($expired ? 'share.remove' : 'share.stop') . '</button></form>';
            $rows .= '<li class="admin-release__row" data-shared-link="' . Html::attribute($link->kind . ':' . $link->id) . '"' . ($expired ? ' data-expired' : '') . '>'
                . '<div><p class="admin-table__title">' . Html::text($name) . '</p><p class="admin-meta">' . implode(' · ', $meta) . '</p></div>'
                . '<div class="admin-release__actions">' . $actions . '</div></li>';
        }
        return $html . ($rows === '' ? '' : '<ul class="admin-release__rows">' . $rows . '</ul>') . '</section>';
    }

    /** A readable time in the site's zone; inside a sentence the relative word loses its capital. */
    public static function when(\DateTimeImmutable $at, \DateTimeZone $zone, ?\DateTimeImmutable $now, Translator $t, bool $inline = false): string
    {
        $text = AdminShell::timeText($at->setTimezone($zone), $now?->setTimezone($zone), $t);
        return $inline ? mb_strtolower(mb_substr($text, 0, 1)) . mb_substr($text, 1) : $text;
    }

    private static function lifetime(string $id, Translator $t): string
    {
        $html = '<fieldset class="admin-field admin-share__lifetime"><legend>' . $t->html('share.how_long') . '</legend><div class="admin-share__choices">';
        foreach (SharedLinkLifetime::DAYS as $days) {
            $html .= '<label class="admin-checkbox"><input type="radio" name="days" value="' . $days . '"' . ($days === SharedLinkLifetime::DEFAULT_DAYS ? ' checked' : '') . '> ' . $t->html('share.days', ['days' => $days]) . '</label>';
        }
        return $html . '</div></fieldset>';
    }

    /** @param \Closure(string): ?\DateTimeImmutable $previewTime */
    private static function what(SharedLink $link, AdminChrome $chrome, \DateTimeZone $zone, \Closure $previewTime, Translator $t): string
    {
        if ($link->kind === 'newest' || $link->previewId === null) {
            return $t->html('share.row.newest');
        }
        $time = $previewTime($link->previewId);
        $label = $time === null ? $t->html('share.row.preview_gone') : $t->html('share.row.preview', ['time' => self::when($time, $zone, $chrome->now, $t, true)]);
        return '<a href="' . Html::url('/previews/' . $link->previewId) . '">' . $label . '</a>';
    }

    /** @param \Closure(string): ?\DateTimeImmutable $previewTime */
    private static function shows(SharedLink $link, AdminChrome $chrome, \DateTimeZone $zone, \Closure $previewTime, Translator $t): string
    {
        $until = self::when($link->expiresAt, $zone, $chrome->now, $t, true);
        if ($link->kind === 'newest' || $link->previewId === null) {
            return $t->html('share.shows.newest', ['until' => $until]);
        }
        $time = $previewTime($link->previewId);
        return $t->html('share.shows.preview', ['time' => $time === null ? '' : self::when($time, $zone, $chrome->now, $t, true), 'until' => $until]);
    }

    private static function copy(string $value, Translator $t): string
    {
        return '<button type="button" class="admin-button admin-button--small" data-copy="' . Html::attribute($value) . '" data-copied="' . $t->html('share.copied') . '">' . $t->html('share.copy') . '</button>';
    }

    private static function csrf(string $csrf): string
    {
        return '<input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '">';
    }
}
