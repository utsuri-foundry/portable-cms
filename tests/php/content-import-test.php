<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Import\ImportBody;
use Utsuri\PortableCms\Conventional\Import\ImportPlanner;
use Utsuri\PortableCms\Conventional\Import\ImportRejected;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/restore-fixture.php';

$tests['content import approval changes after an automatic edit within the same revision'] = static function (): void {
    $package = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $source = \Utsuri\PortableCms\Conventional\Import\ImportSource::from(new \Utsuri\PortableCms\Conventional\Export\NormalizedExport(RestoreTestFixture::document($package)));
    $inventory = new \Utsuri\PortableCms\Conventional\Import\VerifiedImportInventory(str_repeat('a', 64), [], [], $source);
    $plan = static fn(int $sequence) => new \Utsuri\PortableCms\Conventional\Import\ImportPlan([
        new \Utsuri\PortableCms\Conventional\Import\ImportRecord(str_repeat('1', 32), str_repeat('2', 32), 'project', [], str_repeat('b', 64), 3, 7, 'conflict', [], $sequence),
    ], [], ['formatVersion' => 1], $inventory);
    assertTrueValue($plan(41)->sha256 !== $plan(42)->sha256, 'approval binds the exact editing sequence, even while both plans see revision 7');
    assertSameValue($plan(42)->sha256, $plan(42)->sha256, 'an unchanged destination retains its reviewed digest');
};

$tests['content import remaps only declared record taxonomy media and composition identity slots'] = static function (): void {
    $package = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $document = RestoreTestFixture::document($package);
    $body = $document['revisions'][0]['body'];
    $literal = $body['related'][0];
    $body['title']['en'] = $literal;
    $body['body']['en']['nodes'][0]['children'][0]['text'] = $literal;
    $mediaId = $document['media'][0]['id'];
    $mapped = ImportBody::remap($package->manifest->type('project')->fields, $body, $package->manifest->locales, [$literal => str_repeat('a', 32)], [$mediaId => str_repeat('b', 32)]);
    assertSameValue($literal, $mapped['title']['en'], 'ordinary text resembling an identity stays literal');
    assertSameValue(str_repeat('a', 32), $mapped['related'][0], 'declared reference maps');
    assertSameValue(str_repeat('b', 32), $mapped['gallery'][0], 'declared image maps');
    assertSameValue(str_repeat('b', 32), $mapped['layout']['rows'][0]['columns'][0]['content']['id'], 'composition image maps');
    assertTrueValue(in_array(str_repeat('b', 32), ImportBody::mediaIds($package->manifest->type('project')->fields, $mapped, $package->manifest->locales), true), 'media graph includes composition and gallery identities');
    assertSameValue($body['body'], $mapped['body'], 'rich text is never searched and replaced');
    foreach ([['rows' => '<?php hostile ?>'], ['rows' => ['invalid', ['columns' => 'invalid'], ['columns' => ['invalid']]]]] as $layout) {
        assertSameValue([], ImportBody::mediaIds($package->manifest->type('project')->fields, ['layout' => $layout], $package->manifest->locales), 'malformed composition contributes no trusted media identities');
    }
};

$tests['content import source namespaces reject executable text paths controls and oversized identifiers'] = static function (): void {
    assertSameValue('fictional-study.v1', ImportPlanner::source('fictional-study.v1'), 'stable explicit source namespace');
    foreach (['../source', '/etc/passwd', '<?php echo 1;?>', "source\nname", str_repeat('a', 81), 'Source', ''] as $source) {
        try {
            ImportPlanner::source($source);
            throw new RuntimeException('Hostile source namespace accepted');
        } catch (ImportRejected $error) {
            assertSameValue('invalid_source_namespace', $error->reason, 'stable rejection without echoing source text');
        }
    }
};

$tests['content import remaps block children video posters and linked collage images without rewriting text or addresses'] = static function (): void {
    $package = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $raw = json_decode((string) file_get_contents($package->root . '/profile.json'), true, flags: JSON_THROW_ON_ERROR);
    $project = array_values(array_filter($raw['recordTypes'], static fn(array $type): bool => $type['id'] === 'project'))[0];
    $children = array_values(array_filter($project['fields'], static fn(array $field): bool => in_array($field['id'], ['gallery', 'related', 'trailer'], true)));
    $children[] = ['id' => 'address', 'label' => 'Address', 'type' => 'web_address', 'localized' => true, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['maximumLength' => 2048]];
    $field = new \Utsuri\PortableCms\Conventional\Content\FieldDefinition('segments', 'Segments', 'blocks', true, false, false, ['minimumItems' => 0, 'maximumItems' => 4, 'blockTypes' => [['id' => 'feature', 'label' => 'Feature', 'fields' => $children]]], maximumItems: 4);
    $id = str_repeat('1', 32);
    $image = str_repeat('2', 32);
    $video = str_repeat('3', 32);
    $address = ['formatVersion' => 1, 'url' => 'https://example.test/' . $id, 'linkText' => $image];
    $item = ['_itemId' => str_repeat('4', 32), 'typeId' => 'feature', 'values' => ['gallery' => [$image], 'related' => [$id], 'trailer' => ['mediaId' => $video, 'posterId' => $image], 'address' => $address]];
    $layoutField = array_column($package->manifest->type('project')->fields, null, 'id')['layout'];
    $layout = RestoreTestFixture::document($package)['revisions'][0]['body']['layout'];
    $layout['formatVersion'] = 2;
    $layout['rows'][0]['columns'][0]['content'] = ['kind' => 'media', 'id' => $image, 'link' => ['typeId' => 'project', 'recordId' => $id], 'captions' => ['en' => $id]];
    $mapped = ImportBody::remap([$field, $layoutField], ['segments' => ['en' => [$item]], 'layout' => $layout], ['en'], [$id => str_repeat('a', 32)], [$image => str_repeat('b', 32), $video => str_repeat('c', 32)]);
    $values = $mapped['segments']['en'][0]['values'];
    assertSameValue([str_repeat('b', 32)], $values['gallery'], 'block images follow checksum identities');
    assertSameValue([str_repeat('a', 32)], $values['related'], 'block references follow destination identities');
    assertSameValue(['mediaId' => str_repeat('c', 32), 'posterId' => str_repeat('b', 32)], $values['trailer'], 'both video and poster identities remap');
    assertSameValue($address, $values['address'], 'web addresses and labels remain literal portable data');
    assertSameValue($item['_itemId'], $mapped['segments']['en'][0]['_itemId'], 'item identity stays stable');
    assertSameValue(str_repeat('a', 32), $mapped['layout']['rows'][0]['columns'][0]['content']['link']['recordId'], 'collage link follows the mapped record');
    assertSameValue(['en' => $id], $mapped['layout']['rows'][0]['columns'][0]['content']['captions'], 'captions remain literal');
    $ids = ImportBody::mediaIds([$field, $layoutField], $mapped, ['en']);
    sort($ids);
    assertTrueValue(in_array(str_repeat('b', 32), $ids, true) && in_array(str_repeat('c', 32), $ids, true), 'media admission checks every block and video dependency');
    assertTrueValue(!in_array($image, $ids, true) && !in_array($video, $ids, true), 'source image and video identities are replaced');
};

final readonly class ImportHistoryProbe implements \Utsuri\PortableCms\Conventional\Export\ExportDataset
{
    public function __construct(private \Utsuri\PortableCms\Conventional\Export\NormalizedExport $source, private bool $oversized = false) {}
    public function metadata(): array
    {
        return $this->source->metadata();
    }
    public function count(string $collection): int
    {
        return $collection === 'revisions' ? 10000 : $this->source->count($collection);
    }
    public function rows(string $collection): iterable
    {
        if ($collection === 'revisions') {
            throw new RuntimeException('Import tried to retain historical bodies');
        }
        yield from $this->source->rows($collection);
    }
    public function revision(string $id): array
    {
        $row = $this->source->revision($id);
        if ($this->oversized) {
            $row['body']['large'] = str_repeat('x', \Utsuri\PortableCms\Conventional\Import\ImportSource::MAXIMUM_BYTES);
        }
        return $row;
    }
}

$tests['content import retains only bounded current content independently of historical revision count'] = static function (): void {
    $package = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $export = new \Utsuri\PortableCms\Conventional\Export\NormalizedExport(RestoreTestFixture::document($package));
    $source = \Utsuri\PortableCms\Conventional\Import\ImportSource::from(new ImportHistoryProbe($export));
    assertSameValue($export->count('records'), $source->count('revisions'), 'one current revision per source record');
    assertSameValue(10000 - $export->count('records'), $source->historicalRevisions, 'history exclusion is reported without loading history');
    assertTrueValue($source->derivatives > 0, 'source derivatives are counted for the exclusion summary');
    assertSameValue([], $source->document['media'][0]['derivatives'], 'ready source processor facts are not retained as upload authority');
    try {
        \Utsuri\PortableCms\Conventional\Import\ImportSource::from(new ImportHistoryProbe($export, true));
        throw new RuntimeException('Oversized current projection accepted');
    } catch (ImportRejected $error) {
        assertSameValue('current_content_byte_limit', $error->reason, 'current projection fails before retaining a large plan');
    }
};

$tests['content import metadata identity covers alternatives captions credits and focal points'] = static function (): void {
    $value = ['originalName' => 'Image.webp', 'text' => ['formatVersion' => 1, 'decorative' => false, 'translations' => [['locale' => 'en', 'alternativeText' => 'A room', 'caption' => 'A caption', 'credit' => 'A credit']]], 'focalPoint' => ['x' => 25, 'y' => 75]];
    $digest = \Utsuri\PortableCms\Conventional\Import\ImportMedia::metadataDigest($value);
    foreach (['alternativeText', 'caption', 'credit'] as $field) {
        $changed = $value;
        $changed['text']['translations'][0][$field] .= ' changed';
        assertTrueValue($digest !== \Utsuri\PortableCms\Conventional\Import\ImportMedia::metadataDigest($changed), 'metadata change participates in import conflict checks');
    }
    $changed = $value;
    $changed['focalPoint']['x']++;
    assertTrueValue($digest !== \Utsuri\PortableCms\Conventional\Import\ImportMedia::metadataDigest($changed), 'focal point participates in import conflict checks');
};
