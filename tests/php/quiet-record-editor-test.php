<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\AdministrationLabels;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\VideoUploadPolicy;
use Utsuri\PortableCms\Conventional\Upgrade\ProfileManifestChanges;
use Utsuri\PortableCms\Conventional\View\Pages;
use Utsuri\PortableCms\Conventional\View\RecordPages;
use Utsuri\PortableCms\Conventional\View\ReferenceControls;
use Utsuri\PortableCms\Conventional\View\VideoUploadHelp;

function quietEditorDom(string $html): DOMXPath
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $dom->loadHTML('<meta charset="utf-8">' . $html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    return new DOMXPath($dom);
}

function quietEditorElement(DOMXPath $xpath, string $query): DOMElement
{
    $node = $xpath->query($query)->item(0);
    if (!$node instanceof DOMElement) {
        throw new RuntimeException('Missing field element: ' . $query);
    }
    return $node;
}

$tests['quiet record with five image and two video fields has native choices and exact field limits without repeated help'] = static function (): void {
    $f = editedFieldFixture();
    try {
        $fields = [];
        for ($index = 1; $index <= 5; $index++) {
            $fields[] = new FieldDefinition('pictures' . $index, 'Pictures ' . $index, 'media_references', false, false, false, ['maximumItems' => 8, 'kinds' => ['image']], maximumItems: 8);
        }
        foreach (['loop' => 6, 'trailer' => 18] as $id => $limit) {
            $fields[] = new FieldDefinition($id, ucfirst($id), 'video_reference', false, false, false, ['posterRequired' => true, 'maximumBytes' => $limit * 1048576]);
        }
        $type = new RecordTypeDefinition('project', 'Projects', 'Project', $fields, 'collection', 'portfolio_entry', 'reference-project', 'projects');
        $controls = new ReferenceControls($f['service'], $f['profile'], new Request('GET', $f['path']), $f['media']['library']);
        $html = Pages::recordForm('csrf', $f['profile'], $type, [], $f['record'], mediaUploadEnabled: true, referenceControls: $controls);
        $xpath = quietEditorDom($html);
        assertSameValue(5, $xpath->query('//details[contains(@class,"admin-picker__browse")]/summary[text()="Add images"]')->length, 'one Add images control per image field');
        assertSameValue(5, $xpath->query('//details[@class="admin-picker__upload" and not(@open)]')->length, 'upload choices start collapsed without requiring scripts');
        assertSameValue(5, $xpath->query('//details[@class="admin-picker__library" and not(@open)]')->length, 'library selection is the other native choice');
        foreach (['loop' => 6, 'trailer' => 18] as $id => $limit) {
            $upload = quietEditorElement($xpath, '//*[@data-upload-field="' . $id . '"]');
            assertSameValue((string) ($limit * 1048576), $upload->getAttribute('data-video-max-bytes'), 'the upload island receives this field limit');
            $line = $xpath->query('//*[@id="media-upload-help-' . $id . '"]')->item(0)->textContent;
            assertTrueValue(str_contains($line, 'MP4, WebM · up to ' . $limit . ' MiB'), 'the same bound is visible before upload');
            assertSameValue(1, substr_count($line, $limit . ' MiB'), 'identical native limits are not repeated');
            assertTrueValue(str_contains($line, '40 MiB, 25 MP'), 'the poster image limit remains independent');
        }
        $help = [];
        foreach ($xpath->query('//p[contains(concat(" ",@class," ")," admin-help ")]') as $paragraph) {
            $text = trim($paragraph->textContent);
            assertTrueValue(!in_array($text, $help, true), 'help paragraph occurs once: ' . $text);
            $help[] = $text;
        }
        assertTrueValue(!str_contains($html, 'resumes after interruption') && !str_contains($html, 'has not qualified'), 'long upload and host explanations are in media documentation');
        $post = new ReferenceControls($f['service'], $f['profile'], new Request('POST', $f['path'], form: ['_upload_field' => 'pictures1']), $f['media']['library']);
        $result = quietEditorDom($post->field($fields[0], [], null, '<input type="file">'));
        assertSameValue(1, $result->query('//details[@class="admin-picker__upload" and @open]')->length, 'native upload responses keep their choice open');
        $qualified = VideoUploadHelp::limits(Translator::english(), new VideoUploadPolicy(maximumBytes: 200 * 1048576, nativeMaximumBytes: 40 * 1048576), 100 * 1048576);
        assertTrueValue(str_contains($qualified, 'up to 100 MiB') && str_contains($qualified, 'Without scripts: 40 MiB'), 'a smaller native limit remains explicit on a qualified host');
    } finally {
        editedFieldCleanup($f);
    }
};

$tests['profile hints validate in all profile schemas escape safely and translate by administration language'] = static function (): void {
    $encoding = mb_internal_encoding();
    try {
        mb_internal_encoding('ISO-8859-1');
        assertTrueValue(FieldDefinition::validHint(str_repeat('é', 160)), 'the character bound uses UTF-8 independently of the host default');
        assertTrueValue(!FieldDefinition::validHint(str_repeat('é', 161)), 'the UTF-8 bound remains exact');
    } finally {
        mb_internal_encoding($encoding);
    }
    $base = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/fixtures/profiles/inline-v2.accepted.json'), flags: JSON_THROW_ON_ERROR);
    foreach ([1, 2, 3] as $version) {
        $base->formatVersion = $version;
        $base->recordTypes[0]->fields[0]->hint = str_repeat('é', 160);
        $profile = fieldGroupsLoad($base);
        assertSameValue(str_repeat('é', 160), $profile->types[0]->fields[0]->hint, 'hint survives schema and domain parsing in v' . $version);
        foreach (['', ' ', str_repeat('é', 161), "line\nbreak", (object) ['en' => 'Invalid object']] as $bad) {
            $invalid = clone $base;
            $invalid = json_decode(json_encode($invalid, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
            $invalid->recordTypes[0]->fields[0]->hint = $bad;
            fieldGroupsReject($invalid);
        }
    }
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $field = new FieldDefinition('title', 'Title', 'short_text', false, false, false, ['maximumLength' => 100], maximumLength: 100, hint: '<b>& $1');
    $html = RecordPages::field($field, '', 'Check this value', $profile);
    assertTrueValue(str_contains($html, '&lt;b&gt;&amp; $1') && !str_contains($html, '<b>'), 'hints are plain escaped text, including replacement metacharacters');
    $xpath = quietEditorDom($html);
    assertSameValue('hint-title error-title', quietEditorElement($xpath, '//input[@name="title"]')->getAttribute('aria-describedby'), 'hint and error descriptions both remain associated');
    $labels = AdministrationLabels::fromDocument(['formatVersion' => 1, 'profileId' => $profile->id, 'languages' => ['ca' => ['hints' => ['project.title' => 'Un títol breu']]]], $profile);
    assertSameValue('Un títol breu', $labels->field($field, 'ca', 'project.title')->hint, 'the administration language selects the hint');
    assertSameValue($field->hint, $labels->field($field, 'de', 'project.title')->hint, 'an absent translation preserves the default');
    assertSameValue($field->hint, AdministrationLabels::none()->field($field, 'ca', 'project.title')->hint, 'profiles without translations preserve hints');
    foreach ([['unknown.title' => 'A hint'], ['project.title' => str_repeat('x', 161)], ['project.title' => "two\nlines"]] as $hints) {
        try {
            AdministrationLabels::fromDocument(['formatVersion' => 1, 'profileId' => $profile->id, 'languages' => ['ca' => ['hints' => $hints]]], $profile);
        } catch (InvalidArgumentException) {
            continue;
        }
        throw new RuntimeException('An unknown path or invalid translated hint must fail');
    }
    $groups = fieldGroupsDocument();
    $groups->fieldGroups->common->hint = 'Default group hint';
    $groups->fieldGroups->common->settings->fields[0]->hint = 'A short caption';
    $groups->recordTypes[0]->fields[3]->hint = 'A hint for this use';
    $expanded = fieldGroupsLoad($groups)->types[0]->fields[3];
    assertSameValue(['A hint for this use', 'A short caption'], [$expanded->hint, $expanded->fields[0]->hint], 'v3 references override a hint and retain nested leaf hints');
    $old = upgradeProfileDocument();
    $new = $old;
    $new['recordTypes'][0]['fields'][0]['hint'] = 'A short title';
    assertSameValue([], ProfileManifestChanges::classify($old, $new)->affecting, 'adding a hint never requires content migration');
};

$tests['record hints work on nested fields while single language tags disappear and Details retain profile order'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $one = siteLanguagesChoice($profile, ['es' => 'off', 'en' => 'off'])->editingProfile($profile);
    $children = [new FieldDefinition('name', 'Name', 'localized_short_text', true, false, false, ['maximumLength' => 80], maximumLength: 80, hint: 'A short credit')];
    $fields = [
        new FieldDefinition('director', 'Director', 'short_text', false, false, false, ['maximumLength' => 100], maximumLength: 100, editorialSection: 'details'),
        new FieldDefinition('genre', 'Genre', 'localized_short_text', true, false, false, ['maximumLength' => 100], maximumLength: 100, editorialSection: 'details', hint: 'One genre'),
        new FieldDefinition('year', 'Year', 'integer', false, false, false, [], editorialSection: 'details'),
        new FieldDefinition('credits', 'Credits', 'repeatable_group', false, false, false, ['maximumItems' => 3, 'presentation' => 'rows'], $children, maximumItems: 3),
    ];
    $type = new RecordTypeDefinition('project', 'Projects', 'Project', $fields, 'collection', 'portfolio_entry', 'reference-project', 'projects');
    $values = ['director' => '', 'genre' => ['ca' => ''], 'year' => '', 'credits' => [['_itemId' => str_repeat('a', 32), 'name' => ['ca' => 'Nora']]]];
    foreach ([$profile, $one] as $editing) {
        $html = Pages::recordForm('csrf', $editing, $type, $values, null);
        $xpath = quietEditorDom($html);
        assertSameValue(3, $xpath->query('//*[@id="section-details"]//*[@name="director" or @name="genre__ca" or @name="year"]')->length, 'translated and untranslated Details stay together');
        assertTrueValue(strpos($html, 'name="director"') < strpos($html, 'name="genre__ca"') && strpos($html, 'name="genre__ca"') < strpos($html, 'name="year"'), 'Details follow profile order');
        assertSameValue(count($editing->locales) > 1, str_contains($html, 'data-locale-tag'), 'tags exist only when languages need distinguishing');
        assertSameValue(count($editing->locales) > 1, str_contains($html, 'admin-group-cell__language'), 'group rows have no redundant language name in a single language');
        assertSameValue(1, $xpath->query('//*[@id="hint-credits-name"]')->length, 'a row hint is shown once in its column heading');
        assertSameValue('hint-credits-name', quietEditorElement($xpath, '//*[@id="field-credits"]//textarea[contains(@name,"__ca")]')->getAttribute('aria-describedby'), 'row controls refer to their column hint');
        if (count($editing->locales) === 1) {
            assertTrueValue(str_contains($html, 'admin-locale--single'), 'native single-language captions are visually hidden but retain accessible labels');
        }
    }
};
