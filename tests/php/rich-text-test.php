<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Content\EditorialForm;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\RichText;
use Utsuri\PortableCms\Conventional\Content\RichTextForm;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\View\RichTextEditor;

function richTextField(): FieldDefinition
{
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    foreach ($profile->type('project')->fields as $field) {
        if ($field->id === 'body') {
            return $field;
        }
    }
    throw new RuntimeException('Rich text test field is missing');
}

/** @return array<string, string> */
function richTextControls(string $name = 'body__en'): array
{
    return [$name . '__rows' => '1', $name . '__0__type' => 'paragraph', $name . '__0__runs' => '1',
        $name . '__0__0__text' => '<Unsaved> café 👋', $name . '__0__0__bold' => '1',
        $name . '__0__0__link' => '/en/projects/', $name . '__end' => '1', '_editorial_end' => '1'];
}

$tests['small capitals are versioned original text and profile-gated in native controls'] = static function (): void {
    $root = dirname(__DIR__, 2);
    $document = json_decode((string) file_get_contents($root . '/schemas/fixtures/contracts/rich-text-small-caps.accepted.json'), true, flags: JSON_THROW_ON_ERROR);
    $field = richTextField();
    $rich = RichText::from($document, $field);
    assertSameValue($document, $rich->document(), 'v2 canonical bytes preserve every mark and original letters');
    assertSameValue('<p><strong><em><u><span class="cms-small-caps"><a href="/en/projects/">RIBA &amp; Café</a></span></u></em></strong></p>', $rich->html(), 'published mark uses only an engine class, not inline styles or uppercase substitution');
    assertSameValue('RIBA & Café', $rich->excerpt(), 'search and descriptions see original letters');
    assertSameValue($document, RichTextForm::document(RichTextForm::rows($document)), 'native rows retain the new version and mark');
    $controls = [...richTextControls(), 'body__en__0__0__smallCaps' => '1'];
    assertSameValue(2, RichTextForm::input(new Request('POST', '/', form: $controls), 'body__en', $field)['formatVersion'], 'native checkbox upgrades only marked content');
    $restricted = new FieldDefinition('body', 'Body', 'rich_text', true, false, false, ['maximumNodes' => 100, 'blocks' => ['paragraph'], 'marks' => ['bold', 'italic', 'underline', 'link']]);
    foreach ([[$document, $restricted], [[...$document, 'formatVersion' => 1], $field]] as [$invalid, $definition]) {
        try {
            RichText::from($invalid, $definition);
            throw new RuntimeException('Disabled or unversioned small capitals were accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $profile = ProfileManifest::load($root . '/profiles/reference-studio/profile.json');
    foreach (['en', 'ca', 'es', 'fr', 'de'] as $locale) {
        $t = Translator::load($locale);
        $markup = RichTextEditor::field($field, ['en' => $document], null, $profile, $t);
        assertTrueValue(str_contains($markup, 'data-rich-mark="smallCaps"'), 'enabled native mark is editable without JavaScript');
        assertTrueValue(str_contains($markup, $t->html('rich.mark.small_caps')), 'native accessible name is localized');
        assertTrueValue(!str_contains(RichTextEditor::field($restricted, null, null, $profile, $t), 'data-rich-mark="smallCaps"'), 'disabled fields have no checkbox');
        assertTrueValue($t->has('client.rich.mark.small_caps') && $t->has('client.rich.mark.small_caps_short'), 'toolbar names exist in every interface language');
    }
};

$tests['rich text canonicalizes marks and runs and renders semantic escaped HTML'] = static function (): void {
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/fixtures/contracts/rich-text.accepted.json'), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue($fixture, RichText::from($fixture, richTextField())->document(), 'shared accepted fixture must pass the domain boundary unchanged');
    $runs = [['text' => '<Hello> ', 'marks' => ['italic', 'bold']], ['text' => 'world', 'marks' => ['bold', 'italic']]];
    $document = ['formatVersion' => 1, 'nodes' => [
        ['type' => 'heading_2', 'children' => $runs],
        ['type' => 'bullet_list', 'children' => [['type' => 'list_item', 'children' => [['text' => 'Link & label', 'marks' => [['type' => 'link', 'href' => 'https://example.invalid/?a=1&b=2'], 'underline']]]]]],
        ['type' => 'ordered_list', 'children' => [['type' => 'list_item', 'children' => [['text' => "One\r\nTwo", 'marks' => []]]]]],
    ]];
    $rich = RichText::from($document, richTextField());
    $positions = [];
    $marked = $rich->html(null, static function (int $position) use (&$positions): string {
        $positions[] = $position;
        return ' data-cms-field="body:' . $position . '"';
    });
    assertSameValue([0, 1, 2], $positions, 'headings and individual list items use the native flattened document positions');
    assertTrueValue(str_contains($marked, '<h2 data-cms-field="body:0">') && str_contains($marked, '<ul><li data-cms-field="body:1">') && str_contains($marked, '<ol><li data-cms-field="body:2">'), 'the position marks the editable block, never its list container');
    assertSameValue($rich->html(), preg_replace('/ data-cms-field="body:[0-9]+"/', '', $marked), 'marking retains the exact safe inline HTML');
    assertSameValue('<h2><strong><em>&lt;Hello&gt; world</em></strong></h2><ul><li><u><a href="https://example.invalid/?a=1&amp;b=2">Link &amp; label</a></u></li></ul><ol><li>One<br>\nTwo</li></ol>', str_replace("\n", '\n', $rich->html()), 'only escaped semantic markup should compile');
    assertSameValue($rich->document(), RichText::from($rich->document(), richTextField())->document(), 'canonical serialization must be idempotent');
    assertSameValue($rich->document(), RichText::from(RichTextForm::document(RichTextForm::rows($rich->document())), richTextField())->document(), 'ordered form must round-trip all blocks and marks');
    $adjacent = RichText::from(['formatVersion' => 1, 'nodes' => [$document['nodes'][1], $document['nodes'][1]]], richTextField());
    assertSameValue(1, count($adjacent->nodes), 'adjacent lists must have the same canonical grouping as native forms');
    assertSameValue($adjacent->document(), RichText::from(RichTextForm::document(RichTextForm::rows($adjacent->document())), richTextField())->document(), 'adjacent list canonical bytes must survive reload');
    assertSameValue(false, RichText::from(['formatVersion' => 1, 'nodes' => [['type' => 'paragraph', 'children' => [['text' => '  ', 'marks' => []]]]]], richTextField())->hasText(), 'blank structure cannot satisfy a required rich-text field');
};

$tests['rich text rejects hostile attributes protocols nodes marks and exceeded bounds'] = static function (): void {
    $base = ['formatVersion' => 1, 'nodes' => [['type' => 'paragraph', 'children' => [['text' => 'text', 'marks' => []]]]]];
    $bad = [];
    $value = $base;
    $value['style'] = 'color:red';
    $bad[] = $value;
    $value = $base;
    $value['nodes'][0]['onclick'] = 'alert(1)';
    $bad[] = $value;
    $value = $base;
    $value['nodes'][0]['type'] = 'script';
    $bad[] = $value;
    $value = $base;
    $value['nodes'][0]['children'][0]['marks'] = ['strikethrough'];
    $bad[] = $value;
    $value = $base;
    $value['nodes'][0]['children'][0]['marks'] = ['bold', 'bold'];
    $bad[] = $value;
    $value = $base;
    $value['nodes'][0]['children'][0]['text'] = str_repeat('x', 10001);
    $bad[] = $value;
    $value = $base;
    $value['nodes'] = array_fill(0, 1001, $base['nodes'][0]);
    $bad[] = $value;
    $value = $base;
    $value['nodes'][0]['children'][0]['text'] = str_repeat('x', 10000);
    $value['nodes'] = array_fill(0, 107, $value['nodes'][0]);
    $bad[] = $value;
    $value = $base;
    $value['nodes'][0]['children'][0]['text'] = "bad\0text";
    $bad[] = $value;
    foreach (['javascript:alert(1)', 'data:text/html,hello', '//example.invalid/', '/\\example.invalid', 'https://user:pass@example.invalid/', "https://example.invalid/\n", 'https://', 'HTTPS://example.invalid/'] as $href) {
        $value = $base;
        $value['nodes'][0]['children'][0]['marks'] = [['type' => 'link', 'href' => $href]];
        $bad[] = $value;
    }
    $value = $base;
    $value['nodes'][0]['children'][0]['marks'] = [['type' => 'link', 'href' => '/safe', 'target' => '_blank']];
    $bad[] = $value;
    $value = $base;
    $value['nodes'][0] = ['type' => 'bullet_list', 'children' => [$base['nodes'][0]]];
    $bad[] = $value;
    foreach ($bad as $document) {
        try {
            RichText::from($document, richTextField());
            throw new RuntimeException('Hostile rich text was accepted');
        } catch (InvalidArgumentException) {
            // The domain boundary must reject before any trusted HTML is produced.
        }
    }
    foreach (['/safe?q=one&two=three', 'https://example.invalid/', 'http://example.invalid/'] as $href) {
        assertTrueValue(RichText::validLink($href), 'declared URL policy should allow ' . $href);
    }
    $restricted = new FieldDefinition('body', 'Body', 'rich_text', true, false, false, ['maximumNodes' => 2, 'blocks' => ['paragraph'], 'marks' => []]);
    $value = $base;
    $value['nodes'][0]['children'][0]['marks'] = ['bold'];
    try {
        RichText::from($value, $restricted);
        throw new RuntimeException('Undeclared profile mark was accepted');
    } catch (InvalidArgumentException) {
    }
};

$tests['native rich text actions preserve input and truncation fails closed'] = static function (): void {
    $controls = richTextControls();
    $field = richTextField();
    $document = RichTextForm::input(new Request('POST', '/', form: $controls), 'body__en', $field);
    $added = RichTextForm::input(new Request('POST', '/', form: [...$controls, '_rich_action' => 'body__en:add:-1:-1']), 'body__en', $field);
    assertSameValue($document['nodes'][0], $added['nodes'][0], 'adding a block must preserve every submitted run');
    assertSameValue(2, count($added['nodes']), 'ordinary native POST must add a block');
    foreach (['body__en__end', 'body__en__0__runs'] as $key) {
        $incomplete = $controls;
        unset($incomplete[$key]);
        try {
            RichTextForm::input(new Request('POST', '/', form: $incomplete), 'body__en', $field);
            throw new RuntimeException('Truncated rich form was accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    unset($controls['_editorial_end']);
    try {
        EditorialForm::input(new Request('POST', '/', form: $controls), $profile->type('project'), $profile, null);
        throw new RuntimeException('Truncated complete form was accepted');
    } catch (InvalidArgumentException) {
    }
};

$tests['rich text HTTP actions validation save reload and conflicts preserve every language'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $fixture = contentTestApplication(profileOverride: $profile);
    $app = $fixture['app'];
    $repository = $fixture['contentRepository'];
    $snapshot = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($snapshot['records'] as $source) {
        $repository->records[$source['id']] = new RecordSnapshot($source['id'], $source['typeId'], $source['workflowState'], $source['revisionId'], $source['revisionNumber'], $source['body'], new DateTimeImmutable('2026-10-03T00:00:00Z'));
    }
    $record = $repository->records['10000000000000000000000000000001'];
    $cookies = authenticatedTestCookies($app);
    $path = '/records/project/' . $record->id;
    $form = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => (string) $record->revisionNumber,
        'year' => '2025', 'sortOrder' => '10', 'status' => 'complete', 'featured' => '1', 'publishedOn' => '2026-03-14',
        'lead' => $record->body['lead'], 'related' => $record->body['related'], 'disciplines' => $record->body['disciplines']];
    foreach ($profile->locales as $locale) {
        $form['title__' . $locale] = 'Title ' . $locale;
        $form['slug__' . $locale] = 'route-' . $locale;
        $form = [...$form, ...richTextControls('body__' . $locale)];
    }
    $action = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, '_rich_action' => 'body__en:add:-1:-1'], $cookies));
    assertSameValue(200, $action->status, 'no-script action should render unsaved form');
    assertTrueValue(str_contains($action->body, 'name="body__en__rows" value="2"'), 'no-script addition must expose new native block controls');
    assertSameValue($record, $repository->records[$record->id], 'structure actions must never save drafts implicitly');
    $invalid = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, 'body__en__0__0__link' => 'javascript:alert(1)'], $cookies));
    assertSameValue(422, $invalid->status, 'unsafe links must reject the save');
    assertTrueValue(str_contains($invalid->body, 'value="javascript:alert(1)"'), 'invalid link must be editable after rejection');
    assertSameValue(3, substr_count($invalid->body, '&lt;Unsaved&gt; café 👋</textarea>'), 'every locale editor control must survive rejection independently of metadata specimens');
    $save = $app->handle(new Request('POST', $path, contentMutationHeaders(), $form, $cookies));
    assertSameValue(303, $save->status, 'native rich text save must use PRG');
    $reload = $app->handle(new Request('GET', $path, cookies: $cookies));
    assertSameValue(3, substr_count($reload->body, '&lt;Unsaved&gt; café 👋</textarea>'), 'every locale editor control must survive reload');
    $conflict = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, 'body__en__0__0__text' => 'Stale rich text'], $cookies));
    assertSameValue(409, $conflict->status, 'stale rich text must not overwrite newer revision');
    assertTrueValue(str_contains($conflict->body, '>Stale rich text</textarea>'), 'conflict must preserve submitted text');
    $bad = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, '_editorial_end' => ''], $cookies));
    assertSameValue(400, $bad->status, 'PHP input truncation must never save a partial record');
    $early = $app->handle(new Request('POST', $path, contentMutationHeaders(), ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => $form['_revision'], 'title__en' => 'Truncated before rich text'], $cookies));
    assertSameValue(400, $early->status, 'truncation before the first rich control must also fail closed');
};

$tests['frozen snapshots reject invalid rich text before public compilation'] = static function (): void {
    $root = dirname(__DIR__, 2) . '/profiles/reference-studio';
    $package = ProfilePackage::load($root);
    $document = json_decode((string) file_get_contents($root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $document['profile']['packageSha256'] = $package->checksum;
    $document['records'][0]['body']['body']['en']['nodes'][0]['children'][0]['marks'] = [['type' => 'link', 'href' => 'javascript:alert(1)']];
    try {
        FrozenSnapshot::fromDocument($document, $package);
        throw new RuntimeException('Hostile snapshot was accepted');
    } catch (\Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure $error) {
        assertSameValue('Rich text link is invalid', $error->getPrevious()?->getMessage(), 'compiler retains the authoritative domain failure');
        assertSameValue($document['records'][0]['id'], $error->recordId, 'the hostile content owner is identified');
    }
};
