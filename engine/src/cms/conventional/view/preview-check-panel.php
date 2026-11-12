<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Preview\PreviewCheck;
use Utsuri\PortableCms\Conventional\Preview\PreviewSetupItem;

/** Shared report markup keeps Overview, previews and the retry response consistent. */
final class PreviewCheckPanel
{
    public static function checked(PreviewCheck $check, Translator $t): string
    {
        $at = $check->checkedAt;
        $time = '<time datetime="' . Html::attribute($at->format('Y-m-d\TH:i:s.uP')) . '">'
            . Html::text($t->date($at, 'format.check_date_time', $at->getTimezone()) . '.' . substr($at->format('u'), 0, 3)) . '</time>';
        return '<p class="admin-meta" role="status">' . $t->markup('preflight.checked', [], ['time' => $time])
            . ' · ' . $t->html($check->includeDrafts ? 'preview.mode.drafts' : 'preview.mode.published') . '</p>';
    }

    /**
     * @param list<Message> $issues
     * @param list<string|null> $links
     */
    public static function issues(array $issues, ?ProfileManifest $profile, Translator $t, array $links = []): string
    {
        $list = '';
        foreach ($issues as $index => $issue) {
            $text = Html::text($t->message(\Utsuri\PortableCms\Conventional\Preview\PreviewDiagnostics::present($issue, $profile)));
            $types = array_values(array_filter($profile->types ?? [], static fn($type): bool => $type->singularLabel === ($issue->parameters['type'] ?? $issue->parameters['setting'] ?? null)));
            $link = $links[$index] ?? (count($types) === 1 ? '/records/' . $types[0]->id : null);
            if ($link !== null) {
                $text = '<a href="' . Html::url($link) . '">' . $text . '</a>';
            }
            $list .= '<li>' . $text . '</li>';
        }
        return '<ul>' . $list . '</ul>';
    }

    public static function failed(?PreviewCheck $check, ?ProfileManifest $profile, Translator $t, bool $overview = false): string
    {
        if ($check === null || $check->issues === []) {
            return '';
        }
        $details = self::checked($check, $t) . self::issues($check->issues, $profile, $t, $check->links) . PreviewDiagnosticList::reference($check->reference, $t);
        if ($overview) {
            return '<li><span class="admin-dot admin-dot--danger" aria-hidden="true"></span><div class="admin-rows__main">'
                . '<a href="' . Html::url('/previews#preview-check') . '">' . $t->html('preflight.last_failed') . '</a>' . $details . '</div></li>';
        }
        return '<section id="preview-check" class="admin-notice admin-notice--danger" aria-labelledby="preview-check-heading">'
            . '<h2 id="preview-check-heading">' . $t->html('preflight.last_failed') . '</h2>' . $details . '</section>';
    }

    /** @param list<PreviewSetupItem> $items */
    public static function setup(array $items, ?ProfileManifest $profile, Translator $t, bool $overview = false): string
    {
        if ($items === [] || $profile === null) {
            return '';
        }
        $list = '';
        foreach ($items as $item) {
            $type = $profile->type($item->typeId);
            if ($type === null) {
                continue;
            }
            $fields = [];
            foreach ($type->fields as $field) {
                if ($field->requiredOnDraft || $field->requiredOnPublish) {
                    $fields[] = $field->label;
                }
            }
            $path = '/records/' . $type->id . '/' . ($item->recordId ?? 'new');
            $list .= '<li><a href="' . Html::url($path) . '">' . Html::text($type->label) . '</a>'
                . ($fields === [] ? '' : '<span class="admin-meta"> · ' . $t->html('preview.setup.fields', ['fields' => implode(', ', $fields)]) . '</span>') . '</li>';
        }
        $contents = '<p>' . $t->html('preview.setup.help') . '</p><ul>' . $list . '</ul>';
        if ($overview) {
            return '<li><div class="admin-rows__main"><strong>' . $t->html('preview.setup.title') . '</strong>' . $contents . '</div></li>';
        }
        return '<section class="admin-notice" aria-labelledby="preview-setup-heading"><h2 id="preview-setup-heading">'
            . $t->html('preview.setup.title') . '</h2>' . $contents . '</section>';
    }
}
