<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\RichText;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Restore\ExportRecordLookup;
use Utsuri\PortableCms\Conventional\Restore\RestorePlan;
use Utsuri\PortableCms\Conventional\Restore\RestoreRejected;
use Utsuri\PortableCms\Conventional\View\RichTextEditor;

require_once __DIR__ . '/single-line-rich-text-fixture.php';
require_once __DIR__ . '/restore-fixture.php';

/** @return array<string, mixed> */
function singleLineDocument(int $blocks = 1): array
{
    return ['formatVersion' => 1, 'nodes' => array_fill(0, $blocks, ['type' => 'paragraph', 'children' => [['text' => 'Nora Sales', 'marks' => [['type' => 'link', 'href' => 'https://agency.example.invalid/']]]]])];
}

$tests['single-block profile setting validates across schema versions and cannot bypass native list grouping'] = static function (): void {
    $base = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/fixtures/profiles/inline-v2.accepted.json'), flags: JSON_THROW_ON_ERROR);
    foreach ([1, 2, 3] as $version) {
        $base->formatVersion = $version;
        $field = $base->recordTypes[0]->fields[0];
        $field->type = 'rich_text';
        $field->settings = (object) ['maximumNodes' => 100, 'maximumBlocks' => 1, 'blocks' => ['paragraph'], 'marks' => ['link']];
        assertSameValue(1, fieldGroupsLoad($base)->types[0]->fields[0]->settings['maximumBlocks'], 'single-block setting survives schema validation');
        foreach ([0, 2, true, null, '1'] as $invalid) {
            $bad = json_decode(json_encode($base, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
            $bad->recordTypes[0]->fields[0]->settings->maximumBlocks = $invalid;
            fieldGroupsReject($bad);
        }
    }
    foreach (['short_text', 'rich_text'] as $type) {
        try {
            new FieldDefinition('test', 'Test', $type, false, false, false, ['maximumBlocks' => 2]);
            throw new RuntimeException('Invalid direct field setting was accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $settings = ['maximumNodes' => 100, 'blocks' => ['paragraph', 'bullet_list'], 'marks' => ['link']];
    $single = new FieldDefinition('agent', 'Agent', 'rich_text', false, false, false, [...$settings, 'maximumBlocks' => 1]);
    $ordinary = new FieldDefinition('agent', 'Agent', 'rich_text', false, false, false, $settings);
    assertSameValue(singleLineDocument(), RichText::from(singleLineDocument(), $single)->document(), 'one linked name retains its canonical content');
    assertSameValue(2, count(RichText::from(singleLineDocument(2), $ordinary)->nodes), 'unconfigured fields remain unrestricted');
    $items = array_fill(0, 2, ['type' => 'list_item', 'children' => [['text' => 'Name', 'marks' => []]]]);
    foreach ([singleLineDocument(2), ['formatVersion' => 1, 'nodes' => [['type' => 'bullet_list', 'children' => $items]]]] as $invalid) {
        try {
            RichText::from($invalid, $single);
            throw new RuntimeException('Extra blocks were accepted');
        } catch (InvalidInput $error) {
            assertSameValue('validation.rich.single_block', $error->text()->key, 'native rows and canonical list items share the bound');
        }
    }
};

$tests['single-line values reject extra blocks in save preview publication and restore without changing content'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-single-line-' . bin2hex(random_bytes(8));
    try {
        $package = singleLineRichTextPackage($root);
        $field = array_values(array_filter($package->manifest->type('project')->fields, static fn($field): bool => $field->id === 'salesAgent'))[0];
        $html = RichTextEditor::field($field, null, null, $package->manifest);
        assertTrueValue(str_contains($html, 'rows="1"') && str_contains($html, 'data-rich-link'), 'native one-line input retains its link control');
        assertTrueValue(!str_contains($html, 'data-rich-action="add"'), 'native mode cannot add another block');
        $document = RestoreTestFixture::document($package);
        $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-25T12:00:00Z'));
        $service = new ContentService(new ExportRecordLookup($document), $package->manifest, $clock, new IdentityTestRandom());
        $body = $document['revisions'][0]['body'];
        $body['salesAgent'] = singleLineDocument();
        assertSameValue($body['salesAgent'], $service->validateCanonical('project', $body)->body['salesAgent'], 'canonical linked name stays unchanged');
        $body['salesAgent'] = singleLineDocument(2);
        foreach (['unsaved', 'create'] as $operation) {
            $result = $operation === 'unsaved' ? $service->unsaved('project', $body) : $service->create('project', $body, str_repeat('a', 32));
            assertTrueValue($result instanceof ValidationFailure, 'save and live preview reject an extra block before writing');
            assertTrueValue(isset($result->errors['salesAgent']), 'rejection belongs to the constrained field');
        }
        $snapshot = json_decode((string) file_get_contents($root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
        $snapshot['profile']['packageSha256'] = $package->checksum;
        $snapshot['records'][0]['body']['salesAgent'] = singleLineDocument(2);
        try {
            FrozenSnapshot::fromDocument($snapshot, $package);
            throw new RuntimeException('Publication accepted extra blocks');
        } catch (ProfileBuildFailure $error) {
            assertTrueValue($error->getPrevious() instanceof InvalidInput, 'public compiler uses the shared block bound');
        }
        $document['revisions'][0]['body']['salesAgent'] = singleLineDocument();
        $export = new NormalizedExport($document);
        new RestorePlan($export, hash('sha256', $export->json()), $package, $clock, new IdentityTestRandom());
        $document['revisions'][0]['body']['salesAgent'] = singleLineDocument(2);
        $export = new NormalizedExport($document);
        try {
            new RestorePlan($export, hash('sha256', $export->json()), $package, $clock, new IdentityTestRandom());
            throw new RuntimeException('Restore accepted extra blocks');
        } catch (RestoreRejected) {
        }
    } finally {
        RestoreTestFixture::remove($root);
    }
};
