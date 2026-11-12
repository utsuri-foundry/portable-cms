<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Message;

final class PreviewPreflightPage
{
    /**
     * @param list<Message> $issues
     * @param list<string|null> $links
     */
    public static function render(?ProfileManifest $profile, string $csrfToken, array $issues, ?AdminChrome $chrome = null, bool $includeDrafts = false, ?string $reference = null, ?\DateTimeImmutable $checkedAt = null, array $links = [], bool $includePreparation = false): string
    {
        $t = AdminShell::translator($chrome);
        $check = $checkedAt === null ? '' : PreviewCheckPanel::checked(new \Utsuri\PortableCms\Conventional\Preview\PreviewCheck($checkedAt, $includeDrafts, $issues, $links, $reference), $t);
        $list = PreviewCheckPanel::issues($issues, $profile, $t, $links) . PreviewDiagnosticList::reference($reference, $t);
        $body = TrustedHtml::fromEngineMarkup(
            AdminShell::breadcrumbs([[$t->text('preview.list.title'), '/previews'], [$t->text('preflight.title'), null]], $t)
            . AdminShell::header($t->text('preflight.title'), '<p class="admin-lede">' . $t->html('preflight.lede') . '</p>')
            . $check . '<section class="admin-notice admin-notice--danger" role="alert" aria-labelledby="preflight-heading"><h2 id="preflight-heading">'
            . $t->html('preflight.issues', ['count' => count($issues)]) . '</h2>' . $list . '</section>'
            . '<form method="post" action="/previews"><input type="hidden" name="_csrf" value="' . Html::attribute($csrfToken) . '">'
            . ($includeDrafts ? '<input type="hidden" name="include_drafts" value="1">' : '')
            . ($includePreparation ? '<input type="hidden" name="include_preparation" value="1">' : '')
            . '<button type="submit" class="admin-button--primary">' . $t->html('preflight.submit') . '</button></form>',
        );
        return AdminShell::application($t->text('preflight.title'), $body, $profile, 'previews', 'standard', $chrome);
    }
}
