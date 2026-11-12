<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordHistorySettings;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Identity\AdministrationPermissions;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\RecordHistoryPage;

$tests['history capacity warning and confirmation are readable in every administration language'] = static function (): void {
    foreach (['en', 'ca', 'es', 'fr', 'de'] as $locale) {
        $t = Translator::load($locale);
        $chrome = new AdminChrome('csrf', 'Fictional administrator', translator: $t);
        $ordinary = RecordHistoryPage::render(ProfileManifest::gateZero(), $chrome, new RecordHistorySettings(), 17_999, 50);
        assertTrueValue(!str_contains($ordinary, $t->html('history.warning')), 'below 90% has no warning');
        $page = RecordHistoryPage::render(ProfileManifest::gateZero(), $chrome, new RecordHistorySettings(), 18_000, 10, 75);
        assertTrueValue(str_contains($page, $t->html('history.warning')), '90% warns in ' . $locale);
        assertTrueValue(str_contains($page, $t->html('history.confirm', ['limit' => 10, 'count' => 75])), 'localized count before confirmation');
        assertTrueValue(str_contains($page, 'name="confirmed" value="1" required'), 'explicit native confirmation');
        assertTrueValue(str_contains($page, $t->html('history.backup')), 'loss and backup explained before destructive work');
    }
};

$tests['history settings deny editors at the route boundary'] = static function (): void {
    foreach (['editor', 'administrator', 'owner'] as $role) {
        $actor = new SessionContext('history', 'authenticated', str_repeat('a', 32), 'fiction@example.test', 'Fictional user', $role, 'token', 'csrf', new DateTimeImmutable('2026-10-30T12:00:00Z'), false);
        foreach (['GET', 'POST'] as $method) {
            assertSameValue($role !== 'editor', AdministrationPermissions::allows($actor, new Request($method, '/settings/history'), ProfileManifest::gateZero()), 'read and write authority for ' . $role);
        }
    }
};
