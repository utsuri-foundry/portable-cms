<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Upgrade\ProfileManifestChanges;

function fieldGroupsDocument(): stdClass
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/fixtures/profiles/field-groups-v3.accepted.json'), false, 32, JSON_THROW_ON_ERROR);
}

function fieldGroupsLoad(stdClass $document): ProfileManifest
{
    $path = tempnam(sys_get_temp_dir(), 'cms-field-groups-');
    try {
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
        return ProfileManifest::load($path);
    } finally {
        unlink($path);
    }
}

function fieldGroupsReject(stdClass $document, ?string $message = null): void
{
    try {
        fieldGroupsLoad($document);
    } catch (InvalidArgumentException $error) {
        if ($message !== null) {
            assertTrueValue(str_contains($error->getMessage(), $message), 'manifest rejection explains ' . $message . ': ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Invalid field group manifest unexpectedly accepted');
}

$tests['profile v3 expands named groups for records singletons ordinary groups and blocks'] = static function (): void {
    $document = fieldGroupsDocument();
    $document->recordTypes[1]->fields[] = (object) ['id' => 'inline', 'label' => 'Inline', 'type' => 'group', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => (object) ['fields' => [(object) ['includeFieldsFrom' => 'common']]]];
    $document->recordTypes[1]->fields[] = (object) ['id' => 'modules', 'label' => 'Modules', 'type' => 'blocks', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => (object) ['minimumItems' => 0, 'maximumItems' => 4, 'blockTypes' => [(object) ['id' => 'notice', 'label' => 'Notice', 'fields' => [(object) ['includeFieldsFrom' => 'common']]]]]];
    $profile = fieldGroupsLoad($document);
    $record = $profile->type('portfolio-entry')->fields[3];
    assertSameValue('details', $record->id, 'use binds the stored field identity');
    assertSameValue('Portfolio details', $record->label, 'use changes only its own group label');
    assertSameValue(['caption', 'flag'], array_column($record->fields, 'id'), 'the original leaf order is retained');
    assertSameValue('Portfolio caption', $record->fields[0]->label, 'per-use child label');
    $singleton = $profile->type($document->singletons[0]->id);
    $composed = $singleton->fields[array_key_last($singleton->fields)];
    assertSameValue(['caption', 'flag', 'note'], array_column($composed->fields, 'id'), 'other groups include flat child definitions');
    assertSameValue('Shared caption', $composed->fields[0]->label, 'use overrides never leak into the cached declaration');
    $article = $profile->type('article');
    assertSameValue('Caption', $article->fields[4]->fields[0]->label, 'inline group gets its own pristine copy');
    assertSameValue(['caption', 'flag'], array_column($article->fields[5]->blockTypes['notice']->fields, 'id'), 'block leaf lists share the same expansion');
    $inline = json_decode($profile->definitionJson, false, 32, JSON_THROW_ON_ERROR);
    assertSameValue(2, $inline->formatVersion, 'consumers get the existing field contract');
    assertTrueValue(!isset($inline->fieldGroups), 'authoring declarations are absent from the loaded definition');
    assertTrueValue($inline->recordTypes[0]->fields[3]->settings->fields[1]->settings instanceof stdClass, 'empty settings stay JSON objects during schema validation');
};

$tests['reusable group overrides admit labels and mapping only and revalidate the expanded contract'] = static function (): void {
    $document = fieldGroupsDocument();
    $document->fieldGroups->common->type = 'repeatable_group';
    $document->fieldGroups->common->settings->maximumItems = 3;
    $document->recordTypes[0]->fields[3]->itemLabel = 'Detail';
    assertSameValue('Detail', fieldGroupsLoad($document)->type('portfolio-entry')->fields[3]->itemLabel(), 'item label is presentation, not a new stored shape');
    foreach (['localized' => true, 'requiredOnPublish' => true, 'type' => 'short_text', 'settings' => (object) ['maximumItems' => 999], 'fields' => []] as $key => $value) {
        $changed = fieldGroupsDocument();
        $changed->recordTypes[0]->fields[3]->$key = $value;
        fieldGroupsReject($changed);
    }
    $changed = fieldGroupsDocument();
    $changed->recordTypes[0]->fields[3]->labels->missing = 'Unknown';
    fieldGroupsReject($changed, 'Unknown field');
    $changed = fieldGroupsDocument();
    $changed->recordTypes[0]->fields[3]->itemLabel = 'Not repeatable';
    fieldGroupsReject($changed);
    $source = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json'), false, 32, JSON_THROW_ON_ERROR);
    $reference = $source->recordTypes[0]->fields[array_key_last($source->recordTypes[0]->fields)];
    $reference->mapping->descriptionField = null;
    $profile = fieldGroupsLoad($source);
    $field = $profile->type('project')->fields[array_key_last($profile->type('project')->fields)];
    assertTrueValue(!isset($field->settings['searchSharing']['descriptionField']), 'null removes only the requested optional mapping');
    assertSameValue('title', $field->settings['searchSharing']['titleField'], 'other use mappings are retained');
    $reference->mapping->siteNameSource = null;
    fieldGroupsReject($source);
};

$tests['unknown recursive conflicting and oversized reusable groups fail closed even when unused'] = static function (): void {
    $changed = fieldGroupsDocument();
    $changed->recordTypes[0]->fields[3]->fieldGroup = 'unknown';
    fieldGroupsReject($changed, 'Unknown profile field group');
    $changed = fieldGroupsDocument();
    $changed->fieldGroups->unused = clone $changed->fieldGroups->composed;
    $changed->fieldGroups->unused->settings = (object) ['fields' => [(object) ['includeFieldsFrom' => 'unknown']]];
    fieldGroupsReject($changed, 'Unknown profile field group');
    foreach (['common', 'composed'] as $target) {
        $changed = fieldGroupsDocument();
        $changed->fieldGroups->common->settings->fields = [(object) ['includeFieldsFrom' => $target]];
        fieldGroupsReject($changed, 'Recursive profile field group');
    }
    $changed = fieldGroupsDocument();
    $changed->recordTypes[0]->fields[3]->id = 'title';
    fieldGroupsReject($changed, 'Conflicting profile field');
    $changed = fieldGroupsDocument();
    $changed->fieldGroups->composed->settings->fields[] = (object) ['includeFieldsFrom' => 'common'];
    fieldGroupsReject($changed, 'Conflicting profile field');
    $changed = fieldGroupsDocument();
    $leaf = $changed->fieldGroups->common->settings->fields[0];
    unset($changed->recordTypes[0]->fields[3]->labels, $changed->fieldGroups->composed->settings->fields[0]->labels);
    $changed->fieldGroups->common->settings->fields = [];
    for ($index = 0; $index < 32; $index++) {
        $copy = clone $leaf;
        $copy->id = 'field' . $index;
        $changed->fieldGroups->common->settings->fields[] = $copy;
    }
    fieldGroupsReject($changed, 'field limit');
    unset($changed->fieldGroups->composed);
    $changed->singletons[0]->fields[array_key_last($changed->singletons[0]->fields)]->fieldGroup = 'common';
    for ($index = 0; $index < 60; $index++) {
        $changed->recordTypes[0]->fields[] = (object) ['id' => 'group' . $index, 'fieldGroup' => 'common'];
    }
    fieldGroupsReject($changed, '256 KiB');
};

$tests['expanded groups retain stored body bytes and need no content migration merely to adopt references'] = static function (): void {
    $profile = fieldGroupsLoad(fieldGroupsDocument());
    $inline = json_decode($profile->definitionJson, false, 32, JSON_THROW_ON_ERROR);
    $legacy = fieldGroupsLoad($inline);
    assertTrueValue($profile->types == $legacy->types, 'legacy inline and v3 authoring build identical typed definitions');
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-20T00:00:00Z'));
    $repository = new MemoryRecordRepository();
    $body = ['title' => 'Unchanged record', 'details' => ['caption' => 'Preserved caption', 'flag' => false]];
    foreach ([$profile, $legacy] as $definition) {
        $service = new ContentService($repository, $definition, $clock, new IdentityTestRandom());
        assertSameValue(json_encode($body, JSON_THROW_ON_ERROR), json_encode($service->validateCanonical('portfolio-entry', $body)->body, JSON_THROW_ON_ERROR), 'stored content is validated without rewriting or wrapping it');
    }
    assertSameValue([], $repository->records, 'manifest expansion and validation never write content');
    $before = json_decode($legacy->definitionJson, true, 32, JSON_THROW_ON_ERROR);
    $after = json_decode($profile->definitionJson, true, 32, JSON_THROW_ON_ERROR);
    $before['recordTypes'][0]['fields'][3] = array_reverse($before['recordTypes'][0]['fields'][3], true);
    assertSameValue([], ProfileManifestChanges::classify($before, $after)->manifestKeys, 'JSON object key order has no model meaning');
    assertSameValue([], ProfileManifestChanges::classify($before, $after)->affecting, 'operator compares expanded definitions, not authoring syntax');
    $changed = fieldGroupsDocument();
    $changed->fieldGroups->common->settings->fields[0]->settings->maximumLength = 159;
    $changed = fieldGroupsLoad($changed);
    assertTrueValue(ProfileManifestChanges::classify($before, json_decode($changed->definitionJson, true, 32, JSON_THROW_ON_ERROR))->affecting !== [], 'a validation change inside a reused definition still requires migration');
};
