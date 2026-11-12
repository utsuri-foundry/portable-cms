<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\Blocks;
use Utsuri\PortableCms\Conventional\Content\BlocksForm;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\EditorialForm;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\View\BlocksControls;
use Utsuri\PortableCms\Conventional\View\RecordPages;

/** The browser's successful controls, including PHP's list-name projection.
 * @return array<string, mixed>
 */
function blocksEditorForm(string $html): array
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body><form>' . $html . '</form></body></html>');
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $form = [];
    foreach ((new DOMXPath($dom))->query('//input[@name]|//textarea[@name]|//select[@name]') as $control) {
        if (!$control instanceof DOMElement || $control->hasAttribute('disabled') || ($control->getAttribute('type') === 'checkbox' && !$control->hasAttribute('checked'))) {
            continue;
        }
        $name = $control->getAttribute('name');
        $value = $control->tagName === 'textarea' ? $control->textContent : $control->getAttribute('value');
        if ($control->tagName === 'select') {
            $options = (new DOMXPath($dom))->query('.//option[@selected]', $control);
            $option = $options->length > 0 ? $options->item(0) : $control->getElementsByTagName('option')->item(0);
            $value = $option instanceof DOMElement ? $option->getAttribute('value') : '';
        }
        if (str_ends_with($name, '[]')) {
            $form[substr($name, 0, -2)][] = $value;
        } else {
            $form[$name] = $value;
        }
    }
    return $form + ['_editorial_end' => '1'];
}

$tests['blocks editor native transport actions preserve the whole draft and stable identities'] = static function (): void {
    [$profile, $field, $body] = blocksFixture();
    $type = $profile->type('landing');
    $random = new IdentityTestRandom();
    $values = $body;
    $originalOtherLanguage = $body['blocks']['ca'];
    $original = $body['blocks']['en'];
    $controls = new \Utsuri\PortableCms\Conventional\View\ReferenceControls(new ContentService(new MemoryRecordRepository(), $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-24T09:00:00Z')), $random), $profile, new Request('GET', '/'), null);
    $render = static fn(array $draft): array => blocksEditorForm(RecordPages::field($field, $draft['blocks'], null, $profile, referenceControls: $controls));
    $form = $render($values);
    $form['title__en'] = 'Whole unsaved record';
    $form['_editor_action'] = 'add:blocks:en';
    $form['blocks_en__add_end'] = 'hero';
    $values = EditorialControls::apply(new Request('POST', '/', form: $form), $type, $profile, EditorialForm::input(new Request('POST', '/', form: $form), $type, $profile, null), $random);
    $blocks = EditorialControls::structured($values['blocks']);
    $new = $blocks['en'][3];
    assertSameValue('hero', $new['typeId'], 'selected declared type appends');
    assertSameValue($originalOtherLanguage, $blocks['ca'], 'other language is retained');
    assertSameValue('Whole unsaved record', $values['title']['en'], 'unrelated unsaved field is retained');
    $id = $new['_itemId'];
    $name = EditorialControls::childName(Blocks::groupId($field, 'en', $id), 'heading');
    $form = $render($values);
    $form[$name] = 'Unsaved heading';
    $values['blocks'] = json_encode(BlocksForm::input(new Request('POST', '/', form: $form), $field, $profile, null), JSON_THROW_ON_ERROR);
    foreach (['up', 'down', 'duplicate', 'insert', 'remove'] as $operation) {
        $form = $render($values);
        $form['_editor_action'] = $operation . ':blocks:en:' . $id;
        $form['blocks_en__add_' . $id] = 'quote';
        $before = EditorialControls::structured($values['blocks']);
        $values = EditorialControls::apply(new Request('POST', '/', form: $form), $type, $profile, $values, $random);
        $blocks = EditorialControls::structured($values['blocks']);
        assertSameValue($originalOtherLanguage, $blocks['ca'], $operation . ' keeps other languages');
        assertSameValue('Whole unsaved record', $values['title']['en'], $operation . ' keeps unsaved title');
        if ($operation === 'up') {
            assertSameValue($id, $blocks['en'][2]['_itemId'], 'native up moves one position');
        } elseif ($operation === 'down') {
            assertSameValue($id, $blocks['en'][3]['_itemId'], 'native down moves one position');
        } elseif ($operation === 'duplicate') {
            assertSameValue($blocks['en'][3]['values'], $blocks['en'][4]['values'], 'duplicate keeps complete unsaved values');
            assertTrueValue($id !== $blocks['en'][4]['_itemId'], 'duplicate receives a new identity');
        } elseif ($operation === 'insert') {
            assertSameValue('quote', $blocks['en'][4]['typeId'], 'insert chosen type immediately below target');
        } else {
            assertTrueValue(!in_array($id, array_column($blocks['en'], '_itemId'), true), 'remove deletes only target');
            assertSameValue(count($before['en']) - 1, count($blocks['en']), 'remove changes count once');
        }
    }
    assertSameValue($original, array_slice($blocks['en'], 0, 3), 'original block bytes never changed');
};

$tests['blocks editor rejects truncation forged identities types languages and item limits'] = static function (): void {
    [$profile, $field, $body] = blocksFixture();
    $form = blocksEditorForm(RecordPages::field($field, $body['blocks'], null, $profile));
    $id = $body['blocks']['en'][0]['_itemId'];
    foreach (['truncated', 'duplicate-id', 'bad-id', 'unknown-type', 'changed-type'] as $case) {
        $bad = $form;
        if ($case === 'truncated') {
            unset($bad['_editorial_end']);
        } elseif ($case === 'duplicate-id') {
            $bad['blocks_en_items'][] = $id;
        } elseif ($case === 'bad-id') {
            $bad['blocks_en_items'][0] = '../outside';
        } else {
            $bad['blocks_en_i' . $id . '__type'] = $case === 'unknown-type' ? 'unknown' : 'quote';
        }
        blocksReject(static fn() => BlocksForm::input(new Request('POST', '/', form: $bad), $field, $profile, $body['blocks']));
    }
    $bounded = new FieldDefinition('blocks', 'Blocks', 'blocks', true, false, false, [...$field->settings, 'minimumItems' => 3, 'maximumItems' => 3], maximumItems: 3);
    foreach (['add:blocks:en', 'insert:blocks:en:' . $id, 'duplicate:blocks:en:' . $id, 'remove:blocks:en:' . $id, 'up:blocks:xx:' . $id, 'remove:blocks:en:' . str_repeat('f', 32), 'unknown:blocks:en:' . $id] as $action) {
        blocksReject(static fn() => BlocksForm::apply(new Request('POST', '/', form: ['_editor_action' => $action, 'blocks_en__add_end' => 'hero']), $bounded, $profile, $body['blocks'], new IdentityTestRandom()));
    }
    $partial = array_fill_keys($profile->locales, []);
    $appended = BlocksForm::apply(new Request('POST', '/', form: ['_editor_action' => 'add:blocks:en', 'blocks_en__add_end' => 'hero']), $bounded, $profile, $partial, new IdentityTestRandom());
    assertSameValue(1, count($appended['en']), 'can assemble a required list before reaching its minimum');
    blocksReject(static fn() => Blocks::items($bounded, $appended, $profile->locales));
};

$tests['blocks editor cards reuse leaf controls and open only cards with invalid fields'] = static function (): void {
    [$profile, $field, $body] = blocksFixture();
    foreach ($body['blocks'] as &$items) {
        array_pop($items);
    }
    unset($items);
    $service = new ContentService(new MemoryRecordRepository(), $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-24T09:00:00Z')), new IdentityTestRandom());
    $body['blocks']['en'][0]['values']['heading'] = '';
    $failure = $service->create('landing', $body, str_repeat('a', 32));
    assertTrueValue($failure instanceof ValidationFailure, 'required block child fails save');
    $name = EditorialControls::childName(Blocks::groupId($field, 'en', $body['blocks']['en'][0]['_itemId']), 'heading');
    assertTrueValue(isset($failure->errors[$name]), 'validation addresses the affected native leaf');
    $html = RecordPages::field($field, $body['blocks'], 'Check blocks', $profile, errors: $failure->errors);
    assertSameValue(1, substr_count($html, 'class="admin-group-card__details" open'), 'only affected card opens');
    assertTrueValue(str_contains($html, 'aria-invalid="true"'), 'affected leaf is marked invalid');
    assertTrueValue(str_contains($html, 'data-field="blocks.' . $body['blocks']['en'][0]['_itemId'] . '.heading"'), 'live path uses stable item identity');
    assertTrueValue(str_contains($html, 'data-group-label="Move up,'), 'actions expose type and position templates');
    assertSameValue(3, substr_count($html, 'data-blocks="'), 'whole-field locales keep their panels');
    $t = Translator::load('es');
    $spanish = BlocksControls::field($field, $body['blocks'], $profile, static fn(FieldDefinition $child, mixed $value): string => '', null, [], $t);
    assertTrueValue(str_contains($spanish, 'Añadir bloque'), 'native chrome follows administration language');
};

$tests['blocks editor reuses localized rich text media references and scoped layout controls'] = static function (): void {
    [$original] = blocksFixture();
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json'), true, flags: JSON_THROW_ON_ERROR);
    $rich = array_values(array_filter($manifest['recordTypes'][0]['fields'], static fn(array $field): bool => $field['id'] === 'body'))[0];
    $rich['id'] = 'copy';
    $settings = ['minimumItems' => 0, 'maximumItems' => 4, 'blockTypes' => [['id' => 'content', 'label' => 'Content', 'fields' => [
        groupRowsChild('heading', 'short_text', true), $rich,
        ['id' => 'images', 'label' => 'Images', 'type' => 'media_references', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['maximumItems' => 2, 'kinds' => ['image']]],
        ['id' => 'target', 'label' => 'Target', 'type' => 'record_reference', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['targetTypes' => ['project']]],
        ['id' => 'layout', 'label' => 'Layout', 'type' => 'composition', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['formatVersion' => 2, 'maximumRows' => 2, 'maximumColumns' => 2]],
    ]]]];
    $field = new FieldDefinition('blocks', 'Blocks', 'blocks', false, false, false, $settings, maximumItems: 4);
    $profile = blocksTestProfile($field);
    $type = $profile->type('landing');
    $random = new IdentityTestRandom();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-24T09:00:00Z'));
    $service = new ContentService(new MemoryRecordRepository(), $profile, $clock, $random);
    $controls = new \Utsuri\PortableCms\Conventional\View\ReferenceControls($service, $profile, new Request('GET', '/'), null);
    $composition = new \Utsuri\PortableCms\Conventional\View\CompositionEditor($profile, $type, new Request('GET', '/'), null, $profile->designRegistries);
    $id = str_repeat('c', 32);
    $values = ['heading' => ['ca' => 'Títol', 'es' => 'Título', 'en' => 'Title'], 'copy' => array_fill_keys($profile->locales, ['formatVersion' => 1, 'nodes' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'Unsaved rich text', 'marks' => []]]]]]), 'images' => [str_repeat('d', 32)], 'target' => ['typeId' => 'project', 'recordId' => str_repeat('e', 32)], 'layout' => null];
    $body = [['_itemId' => $id, 'typeId' => 'content', 'values' => $values]];
    $html = RecordPages::field($field, $body, null, $profile, referenceControls: $controls, compositionEditor: $composition);
    assertTrueValue(str_contains($html, 'data-rich-editor'), 'rich text uses its ordinary native rows');
    assertTrueValue(str_contains($html, 'data-composition-editor'), 'layouts use their ordinary row workspace');
    $form = blocksEditorForm($html);
    $input = BlocksForm::input(new Request('POST', '/', form: $form), $field, $profile, $body);
    assertSameValue($values['heading'], $input[0]['values']['heading'], 'localized child keeps all panels');
    assertSameValue($values['images'], $input[0]['values']['images'], 'media keeps ordered hidden selections');
    assertSameValue($values['target'], $input[0]['values']['target'], 'typed reference remains structured');
    assertSameValue('Unsaved rich text', $input[0]['values']['copy']['en']['nodes'][0]['children'][0]['text'], 'rich content round trips');
    $prefix = Blocks::groupId($field, '', $id);
    $target = EditorialControls::childName($prefix, 'target');
    $updated = EditorialControls::apply(new Request('POST', '/', form: ['_editor_action' => 'select:' . $target . ':project@' . str_repeat('f', 32)]), $type, $profile, ['blocks' => json_encode($input, JSON_THROW_ON_ERROR)], $random);
    assertSameValue(str_repeat('f', 32), EditorialControls::structured($updated['blocks'])[0]['values']['target']['recordId'], 'nested picker updates only its declared block leaf');
    assertSameValue($input[0]['values']['copy'], EditorialControls::structured($updated['blocks'])[0]['values']['copy'], 'nested picker keeps unsaved rich text');
    $layout = EditorialControls::childName($prefix, 'layout');
    $context = BlocksForm::find($type, $profile, $updated, $layout);
    assertSameValue('heading', $context['type']->fields[0]->id, 'layout sources retain their original sibling names');
    $document = \Utsuri\PortableCms\Conventional\Content\Composition::from($context['value'], $context['field'], $context['type'], $profile->designRegistries);
    $changed = \Utsuri\PortableCms\Conventional\Content\CompositionForm::apply('add-row', '', $document, $context['field'], $random);
    $updated = BlocksForm::replace($updated, $context, $changed);
    assertSameValue(1, count(EditorialControls::structured($updated['blocks'])[0]['values']['layout']['rows']), 'layout actions store back into the owning item');
    $http = new \Utsuri\PortableCms\Conventional\Content\EditorialRecordHttp($service, $profile, $random, null, false, false);
    $form['_composition_action'] = 'add-row:' . $layout;
    $form['title__ca'] = 'Unsaved page';
    $response = $http->handle(new Request('POST', '/records/landing', form: $form), ['kind' => 'new', 'typeId' => 'landing', 'recordId' => null], siteLanguagesActor('owner'), 'blocks-test');
    assertSameValue(200, $response->status, 'scoped composition action uses the native record route');
    $returned = blocksEditorForm($response->body);
    $transport = BlocksForm::input(new Request('POST', '/', form: $returned), $field, $profile, null);
    assertSameValue(1, count($transport[0]['values']['layout']['rows']), 'HTTP projection stores layout in the right card');
    assertSameValue('Unsaved page', $returned['title__ca'], 'layout action keeps unrelated unsaved text');
    assertSameValue([], $service->list('landing'), 'layout action never creates a record');
    unset($returned['_composition_action']);
    $returned['_composition_preview'] = $layout;
    $preview = $http->handle(new Request('POST', '/records/landing', form: $returned), ['kind' => 'new', 'typeId' => 'landing', 'recordId' => null], siteLanguagesActor('owner'), 'blocks-test');
    assertSameValue(200, $preview->status, 'layout preview resolves block sibling context');
};

$tests['blocks editor preserves off languages and respects preparation item minima'] = static function (): void {
    [$profile, $field, $body] = blocksFixture();
    $editing = siteLanguagesChoice($profile, ['ca' => 'public', 'es' => 'preparation', 'en' => 'off'])->editingProfile($profile);
    $body['blocks']['es'] = [];
    $saved = new RecordSnapshot(str_repeat('a', 32), 'landing', 'draft', str_repeat('b', 32), 1, $body, new DateTimeImmutable('2026-10-24T09:00:00Z'));
    $controls = new \Utsuri\PortableCms\Conventional\View\ReferenceControls(new ContentService(new MemoryRecordRepository(), $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-24T09:00:00Z')), new IdentityTestRandom()), $editing, new Request('GET', '/'), null);
    $form = blocksEditorForm(RecordPages::field($field, $body['blocks'], null, $editing, referenceControls: $controls));
    assertTrueValue(!isset($form['blocks_en_items']), 'off language has no native controls');
    $form['_editor_action'] = 'add:blocks:es';
    $form['blocks_es__add_end'] = 'hero';
    $input = EditorialForm::input(new Request('POST', '/', form: $form), $editing->type('landing'), $editing, $saved);
    $input = EditorialControls::apply(new Request('POST', '/', form: $form), $editing->type('landing'), $editing, $input, new IdentityTestRandom());
    $result = EditorialControls::structured($input['blocks']);
    assertSameValue($body['blocks']['en'], $result['en'], 'transient action retains off-language bytes');
    $html = RecordPages::field($field, $input['blocks'], null, $editing, referenceControls: $controls);
    assertTrueValue(str_contains($html, 'data-blocks="blocks_es"'), 're-render can contain preserved off-language data privately');
    assertTrueValue(!str_contains($html, 'data-blocks="blocks_en"'), 'off language stays hidden after action');
    $bounded = new FieldDefinition('blocks', 'Blocks', 'blocks', true, false, false, [...$field->settings, 'minimumItems' => 3], maximumItems: 32);
    $removed = BlocksForm::apply(new Request('POST', '/', form: ['_editor_action' => 'remove:blocks:es:' . $result['es'][0]['_itemId']]), $bounded, $editing, $result, new IdentityTestRandom());
    assertSameValue([], $removed['es'], 'preparation can remove below the publication minimum');
};

$tests['blocks editor native actions retain stale revision conflicts without saving'] = static function (): void {
    [$profile, $field, $body] = blocksFixture();
    foreach ($body['blocks'] as &$items) {
        array_pop($items);
    }
    unset($items);
    $random = new IdentityTestRandom();
    $service = new ContentService(new MemoryRecordRepository(), $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-24T09:00:00Z')), $random);
    $saved = $service->create('landing', $body, str_repeat('1', 32));
    assertTrueValue($saved instanceof RecordSnapshot, 'seed a record for concurrent editing');
    $current = $service->update('landing', $saved->id, 1, [...$body, 'title' => array_fill_keys($profile->locales, 'Other editor')], str_repeat('2', 32));
    assertTrueValue($current instanceof RecordSnapshot, 'another editor saves revision two');
    $form = blocksEditorForm(RecordPages::field($field, $saved->body['blocks'], null, $profile));
    foreach ($profile->locales as $locale) {
        $form['title__' . $locale] = 'My unsaved page';
    }
    $form['_revision'] = '1';
    $form['_editor_action'] = 'add:blocks:en';
    $form['blocks_en__add_end'] = 'hero';
    $http = new \Utsuri\PortableCms\Conventional\Content\EditorialRecordHttp($service, $profile, $random, null, false, false);
    $response = $http->handle(new Request('POST', '/records/landing/' . $saved->id, form: $form), ['kind' => 'record', 'typeId' => 'landing', 'recordId' => $saved->id], siteLanguagesActor('owner'), 'blocks-test');
    assertSameValue(409, $response->status, 'transient block action exposes the concurrent revision');
    $returned = blocksEditorForm($response->body);
    assertSameValue('1', $returned['_editor_base_revision'], 'original stale base remains explicit');
    assertSameValue('My unsaved page', $returned['title__en'], 'conflict retains unsaved unrelated text');
    assertSameValue(3, count($returned['blocks_en_items']), 'conflict retains the unsaved new card');
    assertSameValue(2, $service->find('landing', $saved->id)->revisionNumber, 'block action never implicitly saves a third revision');
};

$tests['blocks editor retains unavailable saved videos and decodes qualified selections'] = static function (): void {
    [$profile] = blocksFixture();
    $video = ['id' => 'clip', 'label' => 'Clip', 'type' => 'video_reference', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['posterRequired' => true, 'maximumBytes' => 41943040]];
    $field = new FieldDefinition('blocks', 'Blocks', 'blocks', false, false, false, ['minimumItems' => 0, 'maximumItems' => 3, 'blockTypes' => [['id' => 'film', 'label' => 'Film', 'fields' => [$video]]]], maximumItems: 3);
    $id = str_repeat('a', 32);
    $selection = ['mediaId' => str_repeat('b', 32), 'posterId' => str_repeat('c', 32)];
    $body = [['_itemId' => $id, 'typeId' => 'film', 'values' => ['clip' => $selection]]];
    $html = RecordPages::field($field, $body, null, $profile);
    assertTrueValue(str_contains($html, 'disabled'), 'unqualified video controls are visibly disabled');
    $form = blocksEditorForm($html);
    $input = BlocksForm::input(new Request('POST', '/', form: $form), $field, $profile, $body);
    assertSameValue($body, $input, 'disabled video controls retain the saved selection');
    $clip = EditorialControls::childName(Blocks::groupId($field, '', $id), 'clip');
    $form['_present_' . $clip] = '1';
    $form[$clip . '__video'] = str_repeat('d', 32);
    $form[$clip . '__poster'] = $selection['posterId'];
    $input = BlocksForm::input(new Request('POST', '/', form: $form), $field, $profile, $body);
    assertSameValue(str_repeat('d', 32), $input[0]['values']['clip']['mediaId'], 'qualified video uses the structured decoder');
    assertSameValue($selection['posterId'], $input[0]['values']['clip']['posterId'], 'poster selection survives the round trip');
};
