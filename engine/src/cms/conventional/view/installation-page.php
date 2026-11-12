<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;

/** Read-only installation facts, never configuration, credentials or filesystem paths. */
final class InstallationPage
{
    public static function render(ProfileManifest $profile, AdminChrome $chrome, ?string $releaseId): string
    {
        $t = $chrome->translator();
        $facts = [
            'installation.website_name' => AdminShell::installationName($profile, $chrome->websiteIdentity),
            'installation.profile_id' => $profile->id,
            'installation.profile_version' => $profile->version,
            'installation.engine_version' => ProfileManifest::ENGINE_VERSION,
            'installation.release_id' => $releaseId ?? $t->text('installation.development'),
        ];
        $body = AdminShell::header($t->text('installation.title'), '<p class="admin-lede">' . $t->html('installation.help') . '</p>')
            . '<section class="admin-card"><dl class="admin-installation-facts">';
        foreach ($facts as $label => $value) {
            $body .= '<dt>' . $t->html($label) . '</dt><dd>' . Html::text($value) . '</dd>';
        }
        $body .= '</dl><p class="admin-help">' . $t->html('installation.release_help') . '</p>'
            . ($chrome->websiteIdentity === null ? '' : '<p><a href="/settings/publication">' . $t->html('holding.settings') . '</a></p>') . '</section>';
        return AdminShell::application($t->text('installation.title'), TrustedHtml::fromEngineMarkup($body), $profile, 'installation', 'standard', $chrome);
    }
}
