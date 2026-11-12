<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Preview\EditorExactPreviewHttp;
use Utsuri\PortableCms\Conventional\Preview\PreviewDiagnostics;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\EditorExactPreviewPage;
use Utsuri\PortableCms\Conventional\View\Pages;

$tests['record save bar separates live and exact previews in every language and keeps native preview read only'] = static function (): void {
    $profile = ProfileManifest::gateZero();
    $type = $profile->type('portfolio-entry');
    $record = new RecordSnapshot(str_repeat('a', 32), $type->id, 'draft', str_repeat('b', 32), 3, ['title' => 'Fictional record'], new DateTimeImmutable('2026-10-30T12:00:00Z'));
    foreach (['en', 'ca', 'es', 'fr', 'de'] as $locale) {
        $t = Translator::load($locale);
        foreach ([[], ['title' => new Message('validation.required', ['field' => 'Title'])]] as $errors) {
            $chrome = new AdminChrome('private-csrf', 'Fictional editor', translator: $t, role: 'editor');
            $html = Pages::recordForm('private-csrf', $profile, $type, $record->body, $record, errors: $errors, chrome: $chrome, pendingControls: $errors === [], exactHasPage: false);
            $xpath = quietEditorDom($html);
            assertSameValue($t->text($errors === [] ? 'editor.status.pending' : 'editor.status.invalid'), quietEditorElement($xpath, '//*[@data-save-status]')->textContent, 'preview controls retain the pending or failed save explanation');
            $live = quietEditorElement($xpath, '//*[@data-live-toggle]');
            assertSameValue($t->text('live.toggle'), $live->textContent, 'plain live label in ' . $locale);
            $exact = quietEditorElement($xpath, '//*[@data-exact-preview]');
            assertSameValue($t->text('exact.open_home'), $exact->textContent, 'known home fallback is explicit');
            assertSameValue('record-exact-preview-form', $exact->getAttribute('form'), 'preview does not submit record values');
            assertSameValue('record-exact-help record-save-status', $exact->getAttribute('aria-describedby'), 'preview describes whole-site/new-tab behavior and the current save state');
            $native = quietEditorElement($xpath, '//form[@id="record-exact-preview-form"]');
            assertSameValue('_blank', $native->getAttribute('target'), 'native new tab');
            assertSameValue('noopener', $native->getAttribute('rel'), 'new tab has no opener authority');
            assertSameValue('/records/portfolio-entry/' . $record->id . '/exact-preview', $native->getAttribute('action'), 'bounded record target');
            assertSameValue(2, $xpath->query('//form[@id="record-exact-preview-form"]//input')->length, 'only CSRF and locale cross the native boundary');
            assertTrueValue(str_contains($html, $t->html('exact.description')) && str_contains($html, $t->html('exact.save_first')), 'new tab, whole saved site and save-first guidance are localized');
            assertTrueValue(str_contains($html, 'data-exact-status') && str_contains($html, 'data-save-status'), 'keeping/failure explanations have stable accessible targets');
        }
        $withoutLive = Pages::recordForm('csrf', $profile, $type, $record->body, $record, chrome: new AdminChrome('csrf', 'Editor', unavailable: ['live-preview'], translator: $t));
        assertTrueValue(!str_contains($withoutLive, 'data-live-toggle') && str_contains($withoutLive, 'data-exact-preview'), 'exact preview works without live capability');
        $withoutExact = Pages::recordForm('csrf', $profile, $type, $record->body, $record, chrome: new AdminChrome('csrf', 'Editor', unavailable: ['previews'], translator: $t));
        assertTrueValue(str_contains($withoutExact, 'data-live-toggle') && !str_contains($withoutExact, 'data-exact-preview'), 'absent exact infrastructure is not offered');
    }
};

$tests['exact preview waiting and failure pages share ordinary localized diagnostics and safe refresh targets'] = static function (): void {
    $t = Translator::english();
    $refresh = '/previews/newest/records/article/' . str_repeat('a', 32) . '?locale=en&waiting=' . str_repeat('b', 32);
    foreach (['en', 'ca', 'es', 'fr', 'de'] as $locale) {
        $localized = Translator::load($locale);
        foreach ([['queued', false, 'exact.queued'], ['queued', true, 'exact.queued_scheduled'], ['building', false, 'exact.building'], ['building', true, 'exact.building']] as [$state, $cronOnly, $message]) {
            $waiting = EditorExactPreviewPage::waiting($localized, $refresh, $state, $cronOnly);
            assertTrueValue(str_contains($waiting, 'role="status"') && str_contains($waiting, $localized->html($message)), 'localized waiting state is accessible without scripts: ' . $locale . '/' . $state);
            assertTrueValue(str_contains($waiting, 'href="' . htmlspecialchars($refresh, ENT_QUOTES) . '"') && str_contains($waiting, $localized->html('preview.refresh')), 'manual refresh follows the same waiting build');
            assertTrueValue($state !== 'queued' || !str_contains($waiting, $localized->html('exact.building')), 'queued work is not described as already building');
        }
    }
    $failed = EditorExactPreviewPage::failed($t, ProfileManifest::gateZero(), new PreviewDiagnostics([new Message('validation.diagnostic', ['text' => '<script>hostile</script>'])], str_repeat('c', 32)));
    assertTrueValue(str_contains($failed, 'role="alert"') && str_contains($failed, '&lt;script&gt;hostile&lt;/script&gt;') && !str_contains($failed, '<script>hostile'), 'shared diagnostics remain escaped');
    foreach ([['GET', '/records/article/' . str_repeat('a', 32) . '/exact-preview'], ['POST', '/previews/newest/records/article/' . str_repeat('a', 32)], ['GET', '/previews/newest/records/article/../private']] as [$method, $path]) {
        assertSameValue(null, EditorExactPreviewHttp::route(new \Utsuri\PortableCms\Conventional\Http\Request($method, $path)), 'no mutation on GET or traversal route');
    }
};
