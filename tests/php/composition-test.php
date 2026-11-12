<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\Composition;
use Utsuri\PortableCms\Conventional\Content\CompositionForm;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\View\CompositionAssets;
use Utsuri\PortableCms\Conventional\View\CompositionEditor;
use Utsuri\PortableCms\Conventional\View\CompositionMedia;
use Utsuri\PortableCms\Conventional\View\CompositionView;

/**
 * Native successful controls, as a browser submits them; no hand-written alternative protocol.
 * @return array<string, string|list<string>>
 */
function compositionNativeValues(string $html): array
{
    $previous = libxml_use_internal_errors(true);
    try {
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);
        $controls = $xpath->query('//form[@data-editorial-form]//*[self::input or self::textarea or self::select]');
        $result = [];
        foreach ($controls as $control) {
            if (!$control instanceof DOMElement) {
                continue;
            }
            $name = $control->getAttribute('name');
            if ($name === '' || $control->hasAttribute('disabled') || ($control->getAttribute('type') === 'checkbox' && !$control->hasAttribute('checked'))) {
                continue;
            }
            $value = $control->tagName === 'textarea' ? $control->textContent : $control->getAttribute('value');
            if ($control->tagName === 'select') {
                foreach ($control->getElementsByTagName('option') as $index => $option) {
                    if ($index === 0 || $option->hasAttribute('selected')) {
                        $value = $option->getAttribute('value');
                    }
                }
            }
            if (str_ends_with($name, '[]')) {
                $result[substr($name, 0, -2)][] = $value;
            } else {
                $result[$name] = $value;
            }
        }
        return $result;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

/** @return array{ProfileManifest, \Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition, \Utsuri\PortableCms\Conventional\Content\FieldDefinition, array<string, mixed>} */
function compositionFixture(): array
{
    $root = dirname(__DIR__, 2);
    $profile = ProfileManifest::load($root . '/profiles/reference-studio/profile.json');
    $type = $profile->type('project');
    foreach ($type->fields as $field) {
        if ($field->type === 'composition') {
            $snapshot = json_decode((string) file_get_contents($root . '/profiles/reference-studio/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
            return [$profile, $type, $field, $snapshot['records'][0]['body']['layout']];
        }
    }
    throw new RuntimeException('Layout fixture missing');
}

$tests['composition validates bounded sources geometry tokens and legacy defaults'] = static function (): void {
    [$profile, $type, $field, $raw] = compositionFixture();
    $layout = Composition::from($raw, $field, $type, $profile->designRegistries);
    $document = $layout->document();
    assertSameValue('intro-c1', $document['rows'][0]['columns'][0]['id'], 'legacy column identities are deterministic');
    assertSameValue('tablet', $document['stackAt'], 'profile responsive defaults apply');
    assertSameValue($document, Composition::from($document, $field, $type, $profile->designRegistries)->document(), 'normalization is idempotent');
    assertSameValue($document, Composition::from($layout->compact(), $field, $type, $profile->designRegistries)->document(), 'compact transport preserves all semantics');
    $neverStack = $document;
    $neverStack['stackAt'] = null;
    assertSameValue(null, Composition::from($neverStack, $field, $type, $profile->designRegistries)->document()['stackAt'], 'Never stack overrides the profile default');
    $bad = [];
    $changed = $document;
    $changed['rows'][0]['columns'][0]['share'] = 0;
    $bad[] = $changed;
    $changed = $document;
    $changed['rows'][0]['columns'][0]['width'] = 101;
    $bad[] = $changed;
    $changed = $document;
    $changed['rows'][0]['columns'][0]['anchor'] = 'fixed';
    $bad[] = $changed;
    $changed = $document;
    $changed['rows'][0]['columns'][0]['roleId'] = 'unknown';
    $bad[] = $changed;
    $changed = $document;
    $changed['rows'][0]['columns'][0]['content'] = ['kind' => 'rich_text', 'field' => 'title'];
    $bad[] = $changed;
    $changed = $document;
    $changed['rows'][0]['columns'][0]['content'] = ['kind' => 'html', 'html' => '<script>'];
    $bad[] = $changed;
    $changed = $document;
    $changed['rows'][0]['columns'][0]['content'] = ['kind' => 'video', 'field' => 'title'];
    $bad[] = $changed;
    $changed = $document;
    $changed['rows'][0]['gap'] = ['unit' => 'token', 'value' => 'unknown'];
    $bad[] = $changed;
    $changed = $document;
    $changed['rows'][0]['minHeight'] = ['unit' => 'rem', 'value' => 251];
    $bad[] = $changed;
    $changed = $document;
    $changed['rows'][0]['css'] = 'url(https://evil.invalid)';
    $bad[] = $changed;
    $changed = $document;
    $changed['rows'][1]['id'] = 'intro';
    $bad[] = $changed;
    foreach ($bad as $value) {
        try {
            Composition::from($value, $field, $type, $profile->designRegistries);
            throw new RuntimeException('Invalid layout accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $path = sys_get_temp_dir() . '/cms-layout-' . bin2hex(random_bytes(8)) . '.json';
    try {
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
        JsonSchemaValidator::validateFile($path, dirname(__DIR__, 2) . '/schemas/portable-cms-composition-v1.schema.json');
    } finally {
        unlink($path);
    }
};

$tests['composition native operations retain stable identities and reject overflow'] = static function (): void {
    [$profile, $type, $field, $raw] = compositionFixture();
    $random = new IdentityTestRandom();
    $layout = Composition::from($raw, $field, $type, $profile->designRegistries);
    $moved = CompositionForm::apply('down-row', 'intro', $layout, $field, $random);
    assertSameValue(['detail', 'intro'], array_column($moved['rows'], 'id'), 'row order changes without regenerating identities');
    $moved = CompositionForm::apply('right-column', 'intro-c1', $layout, $field, $random);
    assertSameValue(['intro-c2', 'intro-c1'], array_column($moved['rows'][0]['columns'], 'id'), 'column identity follows the asset');
    $added = CompositionForm::apply('add-column', 'intro', $layout, $field, $random);
    assertSameValue(null, $added['rows'][0]['columns'][2]['content'], 'new columns deliberately start blank');
    for ($index = 0; $index < 10; $index++) {
        $layout = Composition::from($added, $field, $type, $profile->designRegistries);
        try {
            $added = CompositionForm::apply('add-column', 'intro', $layout, $field, $random);
        } catch (InvalidArgumentException) {
            assertSameValue(12, count($layout->document()['rows'][0]['columns']), 'overflow preserves the last valid layout');
        }
    }
};

$tests['composition profile sources use named controls and verified prepared asset producers'] = static function (): void {
    [$profile, $type, $field, $raw] = compositionFixture();
    $enabled = new FieldDefinition($field->id, $field->label, $field->type, false, false, false, [...$field->settings, 'videoEnabled' => true, 'blockFields' => ['title']]);
    $raw['rows'][0]['columns'][0]['content'] = ['kind' => 'video', 'field' => 'trailer'];
    $raw['rows'][0]['columns'][1]['content'] = ['kind' => 'block', 'field' => 'title'];
    $layout = Composition::from($raw, $enabled, $type, $profile->designRegistries);
    $view = new CompositionEditor($profile, $type, new Request('GET', '/records/project'), null, $profile->designRegistries);
    $html = $view->field($enabled, $layout->document(), null);
    assertTrueValue(str_contains($html, 'value="video:trailer" selected'), 'video is selected through a labelled source control');
    assertTrueValue(str_contains($html, 'value="block:title" selected'), 'profile blocks use the same dedicated selector');
    $body = ['title' => ['en' => '<script>'], 'trailer' => ['mediaId' => 'video', 'posterId' => 'poster']];
    $asset = static fn(string $id): CompositionMedia => $id === 'video' ? new CompositionMedia('/media/prepared.mp4', 1920, 1080, mime: 'video/mp4') : new CompositionMedia('/media/poster.webp', 960, 540);
    $video = CompositionAssets::render(['kind' => 'video', 'field' => 'trailer'], $type, $body, 'en', $asset);
    assertTrueValue(str_contains($video, '<video controls preload="none" poster="/media/poster.webp"'), 'prepared video uses a verified poster and native controls');
    assertSameValue('<p>&lt;script&gt;</p>', CompositionAssets::render(['kind' => 'block', 'field' => 'title'], $type, $body, 'en', $asset), 'block content is escaped, never editor HTML');
};

$tests['composition shared renderer keeps gaps percentages anchors and CSS injection boundaries'] = static function (): void {
    [$profile, $type, $field, $raw] = compositionFixture();
    $raw['rows'][0]['gap'] = ['unit' => 'rem', 'value' => 2];
    $raw['rows'][0]['minHeight'] = ['unit' => 'vh', 'value' => 50];
    $raw['rows'][0]['columns'][1]['anchor'] = 'bottom-right';
    $raw['rows'][0]['columns'][1]['width'] = 80;
    $layout = Composition::from($raw, $field, $type, $profile->designRegistries);
    $render = CompositionView::render($layout, $profile->designRegistries, 'test', static fn(array $source): string => '<p>Reviewed asset</p>');
    foreach (['minmax(0,7fr) minmax(0,5fr)', 'gap:2rem', 'min-height:50vh', 'justify-content:flex-end', 'align-items:flex-end', 'width:80%', '@media(width<768px)'] as $rule) {
        assertTrueValue(str_contains($render->css, $rule), 'canonical renderer contains ' . $rule);
    }
    assertSameValue(4, substr_count($render->html, 'data-layout-column='), 'blank columns remain in source order');
    assertSameValue(3, substr_count($render->html, 'Reviewed asset'), 'blank columns have no asset');
};

$tests['composition native row workflow preview validation save and conflicts share one protocol'] = static function (): void {
    [$profile, $type, $field] = compositionFixture();
    $fixture = contentTestApplication(profileOverride: $profile);
    $app = $fixture['app'];
    $cookies = authenticatedTestCookies($app);
    $service = new ContentService($fixture['contentRepository'], $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-03T00:00:00Z')), new IdentityTestRandom());
    $record = $service->create('project', ['title' => array_fill_keys($profile->locales, 'Layout test'), 'gallery' => '[]'], str_repeat('a', 32));
    assertTrueValue($record instanceof RecordSnapshot, 'empty draft is valid');
    $path = '/records/project/' . $record->id;
    $initial = $app->handle(new Request('GET', $path, cookies: $cookies));
    $form = compositionNativeValues($initial->body);
    $added = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, '_composition_action' => 'add-row:layout'], $cookies));
    assertSameValue(200, $added->status, 'native row creation is transient');
    assertSameValue(1, $fixture['contentRepository']->find('project', $record->id)->revisionNumber, 'layout actions do not save');
    $form = compositionNativeValues($added->body);
    $layout = json_decode($form['layout__document'], true, flags: JSON_THROW_ON_ERROR);
    $rowId = $layout['rows'][0]['id'];
    $more = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, '_composition_action' => 'add-column:layout:' . $rowId], $cookies));
    $form = compositionNativeValues($more->body);
    $form['layout__' . $rowId . '__minHeight__value'] = '20';
    $form['layout__' . $rowId . '__minHeight__unit'] = 'rem';
    $preview = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, '_composition_preview' => 'layout'], $cookies));
    assertSameValue(200, $preview->status, 'native iframe preview renders without saving');
    assertTrueValue(str_contains($preview->body, 'min-height:20rem'), 'preview consumes the submitted controls');
    assertTrueValue(str_contains($preview->headers()['content-security-policy'][0], "script-src 'none'"), 'preview cannot run scripts');
    assertTrueValue(!str_contains($preview->headers()['content-security-policy'][0], 'unsafe-inline'), 'generated style has a precise digest instead of unsafe-inline');
    assertSameValue(403, $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, '_composition_preview' => 'layout', '_csrf' => 'bad'], $cookies))->status, 'preview is CSRF protected');
    $invalid = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, 'layout__' . $rowId . '__c0__width' => '101'], $cookies));
    assertSameValue(422, $invalid->status, 'invalid geometry cannot save');
    assertTrueValue(str_contains($invalid->body, 'value="101"'), 'rejected input remains editable');
    assertSameValue(303, $app->handle(new Request('POST', $path, contentMutationHeaders(), $form, $cookies))->status, 'valid layout uses PRG');
    $conflict = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, '_composition_action' => 'add-column:layout:' . $rowId], $cookies));
    assertSameValue(409, $conflict->status, 'transient operations keep stale revision authority');
    assertTrueValue(str_contains($conflict->body, 'based on revision 1'), 'conflict exposes both versions');
    $incomplete = $form;
    unset($incomplete['layout__end']);
    assertSameValue(400, $app->handle(new Request('POST', $path, contentMutationHeaders(), $incomplete, $cookies))->status, 'PHP truncation cannot save partial rows');
    $hostile = $form;
    $hostile['layout__document'] = '{"formatVersion":1,"rows":[{"id":"bad","columns":[{"content":{"kind":["script"]}}]}]}';
    assertSameValue(400, $app->handle(new Request('POST', $path, contentMutationHeaders(), $hostile, $cookies))->status, 'malformed hidden sources fail before rendering');
    $hostile = $form;
    $hostile['_composition_action'] = 'undo:layout';
    $hostile['layout__undo'] = '{';
    $hostile['_revision'] = '2';
    unset($hostile['_editor_base_revision']);
    assertSameValue(422, $app->handle(new Request('POST', $path, contentMutationHeaders(), $hostile, $cookies))->status, 'malformed undo stays a recoverable field error');
    $hostile['_composition_action'] = 'add-row:unknown';
    assertSameValue(400, $app->handle(new Request('POST', $path, contentMutationHeaders(), $hostile, $cookies))->status, 'unknown composition targets fail closed');
};

$tests['maximum composition edits one bounded native row rather than thousands of controls'] = static function (): void {
    [$profile, $type, $field, $raw] = compositionFixture();
    $raw['rows'] = [];
    for ($row = 0; $row < 256; $row++) {
        $columns = [];
        for ($column = 0; $column < 12; $column++) {
            $columns[] = ['share' => 1, 'content' => null];
        }
        $raw['rows'][] = ['id' => 'r-' . $row, 'columns' => $columns];
    }
    $view = new CompositionEditor($profile, $type, new Request('GET', '/records/project'), null, $profile->designRegistries);
    $html = $view->field($field, $raw, null);
    assertTrueValue(substr_count($html, '<input ') + substr_count($html, '<select ') < 300, 'full layout keeps active-row controls bounded');
    assertTrueValue(strlen($html) < 1_048_576, 'maximum workspace stays below one megabyte');
};

$tests['layout workspace opens on demand with one inspector and a fitted device preview'] = static function (): void {
    [$profile, $type, $field, $raw] = compositionFixture();
    $layout = Composition::from($raw, $field, $type, $profile->designRegistries);
    $view = new CompositionEditor($profile, $type, new Request('GET', '/records/project'), null, $profile->designRegistries);
    $html = $view->field($field, $layout->document(), null);
    $rows = count($layout->document()['rows']);
    assertTrueValue(str_contains($html, '<p class="admin-layout-summary__count">' . $rows . ' row' . ($rows === 1 ? '' : 's') . '</p>'), 'the record shows a row count');
    assertTrueValue(str_contains($html, 'class="admin-layout-summary__schematic" aria-hidden="true"'), 'the summary carries a proportional schematic');
    assertTrueValue(str_contains($html, '<details class="admin-layout-workspace" data-layout-workspace><summary class="admin-layout-workspace__toggle">Edit layout</summary>'), 'the workspace starts closed behind Edit layout');
    assertTrueValue(str_contains($html, '<details class="admin-row-menu"><summary>Row settings</summary>'), 'row gap and height live in a row menu');
    assertTrueValue(str_contains($html, 'data-column-tabs hidden') && str_contains($html, 'data-column-panel="0"'), 'column inspectors are tabbed by the island and all visible without script');
    assertTrueValue(str_contains($html, 'name="layout__viewport" value="1440" checked>Desktop'), 'the preview defaults to the desktop width');
    foreach (['390' => 'Phone', '768' => 'Tablet'] as $width => $device) {
        assertTrueValue(str_contains($html, 'name="layout__viewport" value="' . $width . '">' . $device), "{$device} is a preview width");
    }
    assertTrueValue(!str_contains($html, 'Current window'), 'the window-dependent width is gone');
    assertTrueValue(str_contains($html, 'formtarget="_blank" formnovalidate class="admin-button--small admin-button--quiet">Open at full size'), 'the same unsaved preview opens at full size');
    assertTrueValue(str_contains($html, 'data-composition-box') && str_contains($html, 'data-composition-scale hidden'), 'the fitted frame states its scale once enhanced');
    $active = new CompositionEditor($profile, $type, new Request('POST', '/records/project', form: ['_composition_action' => 'add-row:layout', 'layout__viewport' => '390']), null, $profile->designRegistries);
    $reopened = $active->field($field, $layout->document(), null);
    assertTrueValue(str_contains($reopened, '<details class="admin-layout-workspace" data-layout-workspace open>'), 'a layout action reopens the workspace');
    assertTrueValue(str_contains($reopened, 'name="layout__viewport" value="390" checked>Phone'), 'the chosen width survives a native action');
    assertTrueValue(str_contains($view->field($field, $layout->document(), 'Check the layout'), 'data-layout-workspace open>'), 'an error reopens the workspace');
};
