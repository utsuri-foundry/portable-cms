<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\View\Pages;
use Utsuri\PortableCms\Profile\ReferenceStudio\InterfaceCopy;
use Utsuri\PortableCms\Profile\ReferenceStudio\NavigationLink;
use Utsuri\PortableCms\Profile\ReferenceStudio\PageCard;
use Utsuri\PortableCms\Profile\ReferenceStudio\PageMedia;
use Utsuri\PortableCms\Profile\ReferenceStudio\PageMetadata;
use Utsuri\PortableCms\Profile\ReferenceStudio\PageView;

$referenceRoot = dirname(__DIR__, 2) . '/profiles/reference-studio';
require_once $referenceRoot . '/src/page-view.php';
require_once $referenceRoot . '/src/presentation.php';

function referenceStudioPageFixture(): PageView
{
    return new PageView(
        'en',
        'Reference Studio',
        'Archive <script>alert(1)</script>',
        '/en/projects/archive-of-mist/',
        [
            new NavigationLink('Work', '/en/projects/', true),
            new NavigationLink('About', '/en/about/'),
        ],
        ['A pavilion for observing air and light.'],
        [new PageMetadata('Year', '2025')],
        [new PageMedia('/media/archive.webp', 1600, 1067, 'A pale pavilion beside still water.', 'North view')],
        [new PageCard('Measuring Shade', '/en/articles/measuring-shade/', 'Notes on climate.')],
        'A fictional project used to verify Portable CMS.',
        new InterfaceCopy(
            'Skip to content',
            'Primary',
            'Search the archive',
            'Type a material, place, person, or idea',
            'result',
            'results',
            'Project composition',
        ),
    );
}

$tests['reference profile package verifies its trusted source and assets'] = static function () use (
    $referenceRoot,
): void {
    $package = ProfilePackage::load($referenceRoot);
    assertSameValue('reference-studio', $package->manifest->id, 'profile ID should be stable');
    assertSameValue('1.1.1', $package->manifest->version, 'profile version records its corrected engine minimum');
    assertSameValue('0.2.0', $package->manifest->compatibility->minimumEngineVersion, 'the reference declares the engine that introduced its APIs');
    assertTrueValue(
        preg_match('/\A[a-f0-9]{64}\z/', $package->checksum) === 1,
        'verified package should expose a deterministic checksum',
    );
    assertSameValue(
        ['project', 'article', 'person', 'home', 'about', 'site-settings', 'landing', 'discipline', 'sector'],
        array_map(static fn($type): string => $type->id, $package->manifest->types),
        'profile should install the bounded collection singleton and taxonomy breadth',
    );
    foreach ($package->manifest->renderers as $renderer) {
        assertTrueValue(is_file($package->rendererPath($renderer->id)), 'renderer entrypoint should be verified');
    }
    foreach ($package->manifest->assets as $asset) {
        assertTrueValue(is_file($package->assetPath($asset->path)), 'declared asset should be verified');
    }
};

$tests['reference snapshot covers multilingual editorial boundary'] = static function () use ($referenceRoot): void {
    $snapshotPath = $referenceRoot . '/fixtures/snapshot.json';
    JsonSchemaValidator::validateFile(
        $snapshotPath,
        dirname(__DIR__, 2) . '/schemas/portable-cms-snapshot-v1.schema.json',
    );
    $snapshot = json_decode((string) file_get_contents($snapshotPath), true, 32, JSON_THROW_ON_ERROR);
    assertTrueValue(is_array($snapshot), 'snapshot should decode to an object');
    $records = $snapshot['records'] ?? [];
    assertTrueValue(is_array($records), 'snapshot records should be a list');
    $types = [];
    $states = [];
    $ids = [];
    foreach ($records as $record) {
        assertTrueValue(is_array($record), 'snapshot record should be an object');
        $id = $record['id'] ?? null;
        $typeId = $record['typeId'] ?? null;
        $state = $record['workflowState'] ?? null;
        assertTrueValue(is_string($id) && is_string($typeId) && is_string($state), 'record identity should be typed');
        $ids[$id] = true;
        $types[$typeId] = true;
        $states[$state] = true;
    }
    $actualTypes = array_keys($types);
    sort($actualTypes, SORT_STRING);
    assertSameValue(
        ['about', 'article', 'discipline', 'home', 'landing', 'person', 'project', 'sector', 'site-settings'],
        $actualTypes,
        'snapshot should cover every installed editorial type',
    );
    assertTrueValue(isset($states['draft'], $states['published']), 'snapshot should cover draft and published states');

    $draft = null;
    foreach ($records as $record) {
        if (is_array($record) && ($record['workflowState'] ?? null) === 'draft') {
            $draft = $record;
            break;
        }
    }
    assertTrueValue(is_array($draft), 'snapshot should contain a draft');
    assertSameValue('', $draft['body']['title']['en'] ?? null, 'draft should exercise a partial translation');
    assertTrueValue(
        is_int($draft['body']['sortOrder'] ?? null),
        'draft should retain deterministic editorial ordering',
    );

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        foreach ($record['relationships'] ?? [] as $relationship) {
            $target = is_array($relationship) ? ($relationship['targetRecordId'] ?? null) : null;
            assertTrueValue(is_string($target) && isset($ids[$target]), 'relationships should resolve in the snapshot');
        }
    }
};

$tests['reference search fixtures validate in every locale'] = static function () use ($referenceRoot): void {
    foreach (['ca', 'es', 'en'] as $locale) {
        $path = $referenceRoot . '/fixtures/expected/search.' . $locale . '.json';
        JsonSchemaValidator::validateFile(
            $path,
            dirname(__DIR__, 2) . '/schemas/portable-cms-search-v1.schema.json',
        );
        $search = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        assertSameValue($locale, $search['locale'] ?? null, 'search fixture locale should match its filename');
        assertSameValue(4, count($search['documents'] ?? []), 'search should cover project article person and landing blocks');
    }
};

$tests['reference artifact fixture pins verified representative output'] = static function () use ($referenceRoot): void {
    $artifactPath = $referenceRoot . '/fixtures/expected/artifact.json';
    JsonSchemaValidator::validateFile(
        $artifactPath,
        dirname(__DIR__, 2) . '/schemas/portable-cms-artifact-v1.schema.json',
    );
    $artifact = json_decode((string) file_get_contents($artifactPath), true, 24, JSON_THROW_ON_ERROR);
    assertTrueValue(is_array($artifact), 'artifact fixture should decode to an object');
    $package = ProfilePackage::load($referenceRoot);
    assertSameValue(
        $package->checksum,
        $artifact['profile']['sha256'] ?? null,
        'artifact should pin the exact trusted profile package',
    );
    $files = [];
    foreach ($artifact['files'] ?? [] as $file) {
        assertTrueValue(is_array($file) && is_string($file['path'] ?? null), 'artifact file should be typed');
        $files[$file['path']] = $file;
    }
    foreach (['ca', 'es', 'en'] as $locale) {
        $source = $referenceRoot . '/fixtures/expected/search.' . $locale . '.json';
        $expected = $files['search/' . $locale . '.json'] ?? null;
        assertTrueValue(is_array($expected), 'artifact should contain every locale search fixture');
        assertSameValue(filesize($source), $expected['byteSize'], 'search bytes should be pinned');
        assertSameValue(hash_file('sha256', $source), $expected['sha256'], 'search hash should be pinned');
    }
    foreach ($package->manifest->assets as $asset) {
        $expected = $files[$asset->path] ?? null;
        assertTrueValue(is_array($expected), 'artifact should contain every profile asset');
        if (\Utsuri\PortableCms\Conventional\Content\SpeculationRules::asset($asset)) {
            $compiledRules = \Utsuri\PortableCms\Conventional\Content\SpeculationRules::compile($package->assetBytes($asset->path, \Utsuri\PortableCms\Conventional\Content\SpeculationRules::MAX_BYTES));
            assertSameValue(strlen($compiledRules), $expected['byteSize'], 'rules inventory pins the compiler-scoped JSON');
            assertSameValue(hash('sha256', $compiledRules), $expected['sha256'], 'rules hash includes the mount guard');
        } else {
            assertSameValue($asset->byteSize, $expected['byteSize'], 'asset bytes should match its manifest');
            assertSameValue($asset->checksum, $expected['sha256'], 'asset hash should match its manifest');
        }
    }
    assertTrueValue(
        isset($files['en/projects/archive-of-mist/index.html']),
        'artifact should contain the representative project route',
    );
};

$tests['reference administration derives navigation and ordinary controls from its manifest'] = static function () use (
    $referenceRoot,
): void {
    $profile = ProfilePackage::load($referenceRoot)->manifest;
    $dashboard = Pages::dashboard(str_repeat('a', 32), 'Editor', $profile);
    $positions = [];
    foreach (
        ['Projects', 'Articles', 'People', 'Home', 'About', 'Disciplines', 'Sectors', 'Previews', 'Discovery defaults', 'Content names'] as $label
    ) {
        $position = strpos($dashboard, '<span>' . $label . '</span>');
        assertTrueValue(is_int($position), "navigation should include {$label}");
        $positions[] = $position;
    }
    assertSameValue($positions, array_values(array_unique($positions)), 'navigation entries should be unique');
    $sorted = $positions;
    sort($sorted, SORT_NUMERIC);
    assertSameValue($sorted, $positions, 'navigation should follow positions within Content, Pages, Library, Publish and Website groups');

    $project = $profile->type('project');
    assertTrueValue($project !== null, 'project definition should be installed');
    $form = Pages::recordForm(str_repeat('b', 32), $profile, $project, []);
    foreach (['title__ca', 'title__es', 'title__en', 'year', 'sortOrder', 'featured', 'status'] as $name) {
        assertTrueValue(
            str_contains($form, 'name="' . $name . '"'),
            "profile form should derive the {$name} control",
        );
    }
    assertTrueValue(str_contains($form, 'id="field-layout"'), 'pending composition editor should keep its section anchor');
};

$tests['reference ordinary fields and typed relationships persist through the generic service'] = static function () use (
    $referenceRoot,
): void {
    $profile = ProfilePackage::load($referenceRoot)->manifest;
    $repository = new MemoryRecordRepository();
    $service = new ContentService(
        $repository,
        $profile,
        new IdentityTestClock(new DateTimeImmutable('2026-10-03T09:00:00Z')),
        new IdentityTestRandom(),
    );
    $actor = str_repeat('a', 32);
    $discipline = $service->create('discipline', [
        'label' => ['ca' => 'Arquitectura', 'es' => 'Arquitectura', 'en' => 'Architecture'],
    ], $actor);
    $person = $service->create('person', [
        'name' => 'Nora Vidal',
        'slug' => ['ca' => 'nora-vidal', 'es' => 'nora-vidal', 'en' => 'nora-vidal'],
        'sortOrder' => '10',
        'role' => ['ca' => 'Arquitecta', 'es' => 'Arquitecta', 'en' => 'Architect'],
        'portrait' => '[]',
    ], $actor);
    assertTrueValue($discipline instanceof RecordSnapshot, 'taxonomy term should use the generic service');
    assertTrueValue($person instanceof RecordSnapshot, 'person should use the generic service');

    $project = $service->create('project', [
        'title' => ['ca' => 'Arxiu', 'es' => 'Archivo', 'en' => 'Archive'],
        'slug' => ['ca' => 'arxiu', 'es' => 'archivo', 'en' => 'archive'],
        'year' => '2025',
        'sortOrder' => '20',
        'featured' => true,
        'publishedOn' => '2026-03-14',
        'status' => 'complete',
        'body' => array_fill_keys($profile->locales, ['formatVersion' => 1, 'nodes' => []]),
        'gallery' => '[]',
        'trailer' => '',
        'lead' => $person->id,
        'related' => [],
        'disciplines' => [$discipline->id],
        'credits' => '{"ca":[],"es":[],"en":[]}',
        'layout' => '{"formatVersion":1,"rows":[]}',
    ], $actor);
    assertTrueValue(!($project instanceof ValidationFailure), 'valid manifest-derived project should save');
    assertTrueValue($project instanceof RecordSnapshot, 'project should use the generic record repository');
    assertSameValue(2025, $project->body['year'], 'integer controls should persist integers');
    assertSameValue(true, $project->body['featured'], 'checkbox controls should persist booleans');
    assertSameValue('complete', $project->body['status'], 'enum controls should persist allowlisted values');
    assertSameValue(
        ['record', 'taxonomy'],
        array_map(static fn($relationship): string => $relationship->kind, $repository->relationships[$project->id]),
        'manifest relationships should project with their declared kinds',
    );
};

$tests['reference renderer returns escaped semantic original markup'] = static function () use ($referenceRoot): void {
    $renderer = require $referenceRoot . '/renderers/project.php';
    assertTrueValue($renderer instanceof Closure, 'trusted renderer entrypoint should return a closure');
    $html = $renderer(referenceStudioPageFixture());
    assertTrueValue(str_starts_with($html, '<!doctype html>'), 'renderer should return a complete document');
    assertTrueValue(str_contains($html, '<main id="content"'), 'renderer should expose a main landmark');
    assertTrueValue(str_contains($html, 'aria-current="page"'), 'renderer should identify current navigation');
    assertTrueValue(!str_contains($html, '<script>alert(1)</script>'), 'editorial text must not execute');
    assertTrueValue(str_contains($html, '&lt;script&gt;'), 'escaped editorial text should remain visible');
};
