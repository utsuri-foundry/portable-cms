<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\SnapshotLanguages;
use Utsuri\PortableCms\Conventional\Content\AdministrationLabels;
use Utsuri\PortableCms\Conventional\Content\Blocks;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\EditorialForm;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Upgrade\ProfileManifestChanges;

/** @return array{ProfileManifest, FieldDefinition, array<string, mixed>} */
function blocksFixture(): array
{
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $body = array_values(array_filter($document['records'], static fn(array $record): bool => $record['typeId'] === 'landing'))[0]['body'];
    $field = array_column($profile->type('landing')->fields, null, 'id')['blocks'];
    return [$profile, $field, $body];
}

/** @param Closure(): mixed $operation */
function blocksReject(Closure $operation): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Invalid block content was accepted');
}

$tests['blocks declare bounded unique reviewed shapes and localizable administration labels'] = static function (): void {
    [$profile, $field] = blocksFixture();
    assertSameValue(['hero', 'quote', 'call-to-action'], array_keys($field->blockTypes), 'block shape identities are stable');
    foreach ([
        [...$field->settings, 'unknown' => true],
        [...$field->settings, 'blockTypes' => []],
        [...$field->settings, 'blockTypes' => array_fill(0, 17, $field->settings['blockTypes'][0])],
        [...$field->settings, 'blockTypes' => [$field->settings['blockTypes'][0], $field->settings['blockTypes'][0]]],
        [...$field->settings, 'minimumItems' => 33],
        [...$field->settings, 'maximumItems' => 65],
    ] as $settings) {
        blocksReject(static fn() => new FieldDefinition('blocks', 'Blocks', 'blocks', true, false, false, $settings, maximumItems: $settings['maximumItems']));
    }
    foreach (['blocks', 'repeatable_group', 'group'] as $nested) {
        $settings = $field->settings;
        $settings['blockTypes'][0]['fields'][0]['type'] = $nested;
        blocksReject(static fn() => new FieldDefinition('blocks', 'Blocks', 'blocks', true, false, false, $settings, maximumItems: 32));
    }
    foreach (['id' => '../unsafe', 'label' => "Unsafe\nname", 'itemLabel' => '', 'renderer' => 'unreviewed.php'] as $key => $bad) {
        $settings = $field->settings;
        $settings['blockTypes'][0][$key] = $bad;
        blocksReject(static fn() => new FieldDefinition('blocks', 'Blocks', 'blocks', true, false, false, $settings, maximumItems: 32));
    }
    $labels = ['formatVersion' => 1, 'profileId' => $profile->id, 'languages' => ['ca' => ['blocks' => ['landing.blocks.hero' => 'Capçalera'], 'items' => ['landing.blocks.hero' => 'Capçalera'], 'fields' => ['landing.blocks.hero.heading' => 'Títol']]]];
    $names = AdministrationLabels::fromDocument($labels, $profile)->field($field, 'ca', 'landing.blocks');
    assertSameValue(['Capçalera', 'Capçalera', 'Títol'], [$names->blockTypes['hero']->label, $names->blockTypes['hero']->itemLabel, $names->blockTypes['hero']->fields[0]->label], 'block names and child names follow interface language');
    $labels['languages']['ca']['blocks']['landing.blocks.missing'] = 'Unknown';
    blocksReject(static fn() => AdministrationLabels::fromDocument($labels, $profile));
};

$tests['blocks validate every declared item on save and preserve identity order and unavailable form values'] = static function (): void {
    [$profile, $field, $body] = blocksFixture();
    // Hero/quote do not need a graph lookup, so test ordinary writes without a ready media store.
    foreach ($body['blocks'] as &$items) {
        array_pop($items);
    }
    unset($items);
    $repository = new MemoryRecordRepository();
    $service = new ContentService($repository, $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-19T09:00:00Z')), new IdentityTestRandom());
    $saved = $service->create('landing', $body, str_repeat('a', 32));
    assertTrueValue($saved instanceof RecordSnapshot, 'typed blocks use ordinary content saves');
    assertSameValue($body, $saved->body, 'all localized values and stable identities survive save');
    $reordered = $body;
    $reordered['blocks']['en'] = array_reverse($reordered['blocks']['en']);
    $next = $service->update('landing', $saved->id, 1, $reordered, str_repeat('a', 32));
    assertTrueValue($next instanceof RecordSnapshot, 'reordering is an ordinary revision');
    assertSameValue($reordered, $next->body, 'reordering does not regenerate identities or change other languages');
    assertTrueValue(EditorialForm::supported($field), 'blocks have native controls');
    $input = EditorialForm::input(new Request('POST', '/', form: ['title__ca' => 'Nou', 'title__es' => 'Nuevo', 'title__en' => 'New', 'blocks' => 'hostile replacement']), $profile->type('landing'), $profile, $next);
    assertSameValue($reordered['blocks'], json_decode($input['blocks'], true, flags: JSON_THROW_ON_ERROR), 'server ignores forged controls and preserves saved blocks');
    foreach (['unknown-type', 'duplicate', 'missing-identity', 'unknown-value', 'wrong-type', 'required-text', 'overflow', 'unknown-language', 'null-item', 'object-list'] as $case) {
        $bad = $body;
        switch ($case) {
            case 'unknown-type': $bad['blocks']['en'][0]['typeId'] = 'unknown';
                break;
            case 'duplicate': $bad['blocks']['en'][] = $bad['blocks']['en'][0];
                break;
            case 'missing-identity': unset($bad['blocks']['en'][0]['_itemId']);
                break;
            case 'unknown-value': $bad['blocks']['en'][0]['values']['script'] = '<script>';
                break;
            case 'wrong-type': $bad['blocks']['en'][0]['values'] = $bad['blocks']['en'][1]['values'];
                break;
            case 'required-text': $bad['blocks']['en'][0]['values']['heading'] = '';
                break;
            case 'overflow': $bad['blocks']['en'] = array_fill(0, 33, $bad['blocks']['en'][0]);
                break;
            case 'unknown-language': $bad['blocks']['xx'] = [];
                break;
            case 'null-item': $bad['blocks']['en'][0] = null;
                break;
            case 'object-list': $bad['blocks']['en'] = ['item' => $bad['blocks']['en'][0]];
                break;
        }
        assertTrueValue($service->create('landing', $bad, str_repeat('a', 32)) instanceof ValidationFailure, 'save refuses ' . $case);
    }
    assertSameValue($reordered, $repository->find('landing', $saved->id)->body, 'invalid saves never mutate the stored revision');
};

$tests['blocks freeze child validation relationships and public-language filtering'] = static function (): void {
    [$profile, $field, $body] = blocksFixture();
    $package = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $document = json_decode((string) file_get_contents($package->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $landing = array_key_last($document['records']);
    $snapshot = FrozenSnapshot::fromDocument($document, $package);
    assertSameValue($body, $snapshot->record($document['records'][$landing]['id'])->body, 'frozen blocks preserve canonical values exactly');
    foreach (['missing-target', 'missing-index', 'extra-index', 'unknown-type', 'required-child', 'too-long', 'wrong-scalar'] as $case) {
        $bad = $document;
        if ($case === 'missing-target') {
            $bad['records'][$landing]['body']['blocks']['en'][2]['values']['target'] = str_repeat('f', 32);
        }
        if ($case === 'missing-index') {
            $bad['records'][$landing]['relationships'] = [];
        }
        if ($case === 'extra-index') {
            $bad['records'][$landing]['relationships'][] = [...$bad['records'][$landing]['relationships'][0], 'position' => 1];
        }
        if ($case === 'unknown-type') {
            $bad['records'][$landing]['body']['blocks']['en'][0]['typeId'] = 'removed';
        }
        if ($case === 'required-child') {
            $bad['records'][$landing]['body']['blocks']['en'][0]['values']['heading'] = '';
        }
        if ($case === 'too-long') {
            $bad['records'][$landing]['body']['blocks']['en'][0]['values']['heading'] = str_repeat('x', 201);
        }
        if ($case === 'wrong-scalar') {
            $bad['records'][$landing]['body']['blocks']['en'][0]['values']['heading'] = ['unsafe'];
        }
        blocksReject(static fn() => FrozenSnapshot::fromDocument($bad, $package));
    }
    $filtered = (new SnapshotLanguages(['es'], 'es'))->record($profile->type('landing'), $body);
    assertSameValue(['es'], array_keys($filtered['blocks']), 'off languages never enter whole-localized blocks');
    assertTrueValue(str_contains(Blocks::text($field, $body['blocks'], $profile->locales, 'en'), 'Spaces find meaning'), 'search includes textual block values');
    assertTrueValue(!str_contains(Blocks::text($field, $body['blocks'], $profile->locales, 'en'), $body['blocks']['en'][0]['_itemId']), 'search excludes block identities');
};

$tests['blocks upgrades treat additions and names as presentation and removed or changed shapes as content'] = static function (): void {
    $old = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json'), true, flags: JSON_THROW_ON_ERROR);
    $position = array_key_last($old['singletons']);
    $blockField = 1;
    $new = $old;
    $new['singletons'][$position]['fields'][$blockField]['settings']['blockTypes'][] = ['id' => 'new-type', 'label' => 'New type', 'fields' => $old['singletons'][$position]['fields'][$blockField]['settings']['blockTypes'][0]['fields']];
    assertSameValue([], ProfileManifestChanges::classify($old, $new)->affecting, 'adding an unused type preserves every existing item');
    $new = $old;
    $new['singletons'][$position]['fields'][$blockField]['settings']['blockTypes'][0]['label'] = 'New display name';
    assertSameValue([], ProfileManifestChanges::classify($old, $new)->affecting, 'names do not affect stored content');
    foreach (['remove', 'change-child'] as $case) {
        $new = $old;
        if ($case === 'remove') {
            array_pop($new['singletons'][$position]['fields'][$blockField]['settings']['blockTypes']);
        } else {
            $new['singletons'][$position]['fields'][$blockField]['settings']['blockTypes'][0]['fields'][0]['settings']['maximumLength'] = 50;
        }
        assertSameValue(['landing'], ProfileManifestChanges::classify($old, $new)->affectedTypes(), 'shape changes require explicit mapping for ' . $case);
    }
};

function blocksTestProfile(FieldDefinition $field): ProfileManifest
{
    [$profile] = blocksFixture();
    $types = [];
    foreach ($profile->types as $type) {
        $types[] = $type->id !== 'landing' ? $type : new \Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition($type->id, $type->label, $type->singularLabel, [$type->fields[0], $field], $type->kind, $type->archetype, $type->renderer, $type->route);
    }
    return new ProfileManifest($profile->id, $profile->version, $profile->compatibility, $profile->locales, $profile->localeDefinitions, $profile->defaultLocale, $types, $profile->navigation, $profile->renderers, $profile->assets, $profile->capabilities, $profile->privacy, $profile->designRegistries, wording: $profile->wording);
}

$tests['blocks distinguish draft and publication child requirements and expose native block controls'] = static function (): void {
    [$profile, $field, $body] = blocksFixture();
    $settings = $field->settings;
    foreach ($settings['blockTypes'][0]['fields'] as &$child) {
        $child['requiredOnDraft'] = false;
    }
    unset($child);
    $field = new FieldDefinition('blocks', 'Page blocks', 'blocks', true, false, false, $settings, maximumItems: 32);
    $profile = blocksTestProfile($field);
    foreach ($body['blocks'] as &$items) {
        $items = [['_itemId' => $items[0]['_itemId'], 'typeId' => 'hero', 'values' => ['heading' => '', 'intro' => '']]];
    }
    unset($items);
    $service = new ContentService(new MemoryRecordRepository(), $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-19T09:00:00Z')), new IdentityTestRandom());
    $saved = $service->create('landing', $body, str_repeat('a', 32));
    assertTrueValue($saved instanceof RecordSnapshot, 'incomplete publication-only values can be saved as drafts');
    blocksReject(static fn() => $service->validateBlocksCanonical($profile->type('landing'), $saved->body, true));
    $parts = \Utsuri\PortableCms\Conventional\View\EditorialRecordLayout::parts($profile->type('landing'), $saved->body, [], static fn(FieldDefinition $field, mixed $value): string => \Utsuri\PortableCms\Conventional\View\RecordPages::field($field, $value, null, $profile), false);
    assertTrueValue(str_contains($parts['sections'], 'Page blocks'), 'native block field keeps its profile name');
    assertTrueValue(str_contains($parts['sections'], 'data-block-type="hero"'), 'native cards expose declared block types');
    assertTrueValue($service->create('landing', [...$body, 'blocks' => '{invalid json'], str_repeat('a', 32)) instanceof ValidationFailure, 'malformed JSON never silently clears an optional blocks field');
};

$tests['blocks retain localized children and validate rich text web addresses and media leaves'] = static function (): void {
    [$original, $field, $body] = blocksFixture();
    $profileDocument = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json'), true, flags: JSON_THROW_ON_ERROR);
    $rich = array_values(array_filter($profileDocument['recordTypes'][0]['fields'], static fn(array $field): bool => $field['id'] === 'body'))[0];
    $rich['id'] = 'copy';
    $settings = ['minimumItems' => 0, 'maximumItems' => 4, 'blockTypes' => [['id' => 'content', 'label' => 'Content', 'fields' => [
        ['id' => 'heading', 'label' => 'Heading', 'type' => 'short_text', 'localized' => true, 'requiredOnDraft' => true, 'requiredOnPublish' => true, 'settings' => ['maximumLength' => 200]],
        $rich,
        ['id' => 'link', 'label' => 'Link', 'type' => 'web_address', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['maximumLength' => 2048]],
        ['id' => 'images', 'label' => 'Images', 'type' => 'media_references', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['maximumItems' => 2, 'kinds' => ['image']]],
    ]]]];
    $field = new FieldDefinition('blocks', 'Blocks', 'blocks', false, false, false, $settings, maximumItems: 4);
    $profile = blocksTestProfile($field);
    $copy = array_fill_keys($profile->locales, ['formatVersion' => 1, 'nodes' => [['type' => 'paragraph', 'children' => [['text' => 'First paragraph.', 'marks' => []]]], ['type' => 'paragraph', 'children' => [['text' => 'Second searchable paragraph.', 'marks' => []]]]]]);
    $values = ['heading' => $body['title'], 'copy' => $copy, 'link' => ['formatVersion' => 1, 'url' => 'https://example.test/', 'linkText' => 'Visit'], 'images' => ['d0000000000000000000000000000001']];
    $body['blocks'] = [['_itemId' => str_repeat('1', 32), 'typeId' => 'content', 'values' => $values]];
    $service = new ContentService(new MemoryRecordRepository(), $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-19T09:00:00Z')), new IdentityTestRandom());
    assertSameValue($body, $service->validateCanonical('landing', $body)->body, 'canonical validation retains language maps, rich text and media selections');
    $projected = (new SnapshotLanguages(['es'], 'es'))->record($profile->type('landing'), $body);
    assertSameValue(['es'], array_keys($projected['blocks'][0]['values']['heading']), 'off languages leave localized children of unlocalized blocks');
    assertTrueValue(str_contains(Blocks::text($field, $body['blocks'], $profile->locales, 'en'), 'Second searchable paragraph.'), 'search includes every rich-text paragraph');
    $off = $body;
    $off['blocks'][0]['values']['heading']['en'] = 'Replaced';
    $kept = \Utsuri\PortableCms\Conventional\Content\SiteLanguages::keep($profile->type('landing'), $off, $body, ['en']);
    assertSameValue($body['blocks'][0]['values']['heading']['en'], $kept['blocks'][0]['values']['heading']['en'], 'saving while a language is off preserves its child text by item identity');
    foreach (['link', 'copy', 'images'] as $leaf) {
        $bad = $body;
        if ($leaf === 'link') {
            $bad['blocks'][0]['values']['link']['url'] = 'javascript:alert(1)';
        }
        if ($leaf === 'copy') {
            $bad['blocks'][0]['values']['copy']['en']['nodes'][0]['children'][0]['marks'] = ['script'];
        }
        if ($leaf === 'images') {
            $bad['blocks'][0]['values']['images'] = [str_repeat('x', 32)];
        }
        blocksReject(static fn() => $service->validateCanonical('landing', $bad));
    }
};

$tests['blocks render escaped reviewed markup and preserve exact canonical export values'] = static function (): void {
    $fixture = staticCompilerFixture();
    $package = $fixture['package'];
    $document = json_decode((string) file_get_contents($package->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $position = array_key_last($document['records']);
    $document['records'][$position]['body']['blocks']['en'][0]['values']['heading'] = '<script>alert(1)</script>';
    $snapshot = FrozenSnapshot::fromDocument($document, $package);
    $site = $fixture['builder']->build($snapshot);
    $page = array_values(array_filter($site->routes, static fn($route): bool => $route->route === '/en/landing/'))[0];
    assertTrueValue(str_contains($page->html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'profile escapes block text instead of treating it as markup');
    assertTrueValue(!str_contains($page->html, '<script>alert(1)</script>'), 'block text cannot add executable source');
    assertTrueValue(str_contains($page->html, '<blockquote>') && str_contains($page->html, 'Discover the project'), 'distinct reviewed renderers retain the ordered block types');
    $search = array_values(array_filter($site->routes, static fn($route): bool => $route->route === '/en/search/'))[0];
    assertTrueValue(str_contains($search->html, 'Spaces find meaning when we share them.'), 'the visible search list includes the landing page and its block text');
    require_once __DIR__ . '/restore-fixture.php';
    $exportDocument = RestoreTestFixture::document($package);
    $envelope = new \Utsuri\PortableCms\Conventional\Export\NormalizedExport($exportDocument);
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-19T09:00:00Z'));
    $plan = new \Utsuri\PortableCms\Conventional\Restore\RestorePlan($envelope, hash('sha256', $envelope->json()), $package, $clock, new IdentityTestRandom());
    $landing = array_values(array_filter(restoreFixtureExport($plan)->document['revisions'], static fn(array $revision): bool => $revision['recordId'] === 'c9000000000000000000000000000001'))[0];
    assertSameValue(blocksFixture()[2], $landing['body'], 'restore planning preserves all localized block identities, type choices and values exactly');
    $bad = $exportDocument;
    foreach ($bad['revisions'] as &$revision) {
        if ($revision['recordId'] === $landing['recordId']) {
            $revision['body']['blocks']['en'][0]['typeId'] = 'unknown';
        }
    }
    unset($revision);
    $poisoned = new \Utsuri\PortableCms\Conventional\Export\NormalizedExport($bad);
    try {
        new \Utsuri\PortableCms\Conventional\Restore\RestorePlan($poisoned, hash('sha256', $poisoned->json()), $package, $clock, new IdentityTestRandom());
        throw new RuntimeException('Poisoned blocks reached restoration');
    } catch (\Utsuri\PortableCms\Conventional\Restore\RestoreRejected $error) {
        assertSameValue('invalid_or_lossy_revision_body', $error->reason, 'restore rejects unknown block types before writing');
    }
};

$tests['blocks layout leaves resolve sibling sources and frozen images fail closed'] = static function (): void {
    [$profile, $field, $body] = blocksFixture();
    $settings = $field->settings;
    $settings['blockTypes'] = [$settings['blockTypes'][0]];
    $settings['blockTypes'][0]['fields'][] = ['id' => 'layout', 'label' => 'Layout', 'type' => 'composition', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['formatVersion' => 1, 'maximumRows' => 2, 'maximumColumns' => 2, 'blockFields' => ['heading']]];
    $field = new FieldDefinition('blocks', 'Blocks', 'blocks', true, false, false, $settings, maximumItems: 32);
    $profile = blocksTestProfile($field);
    foreach ($body['blocks'] as &$items) {
        $items = [$items[0]];
        $items[0]['values']['layout'] = ['formatVersion' => 1, 'rows' => [['id' => 'intro', 'columns' => [['id' => 'text', 'share' => 1, 'content' => ['kind' => 'block', 'field' => 'heading']], ['id' => 'image', 'share' => 1, 'content' => ['kind' => 'media', 'id' => 'd0000000000000000000000000000001']]]]]];
    }
    unset($items);
    $service = new ContentService(new MemoryRecordRepository(), $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-19T09:00:00Z')), new IdentityTestRandom());
    $service->validateBlocksCanonical($profile->type('landing'), $body, true, $profile->designRegistries);
    assertSameValue(3, count(Blocks::layouts($profile->type('landing'), $body, $profile->locales, $profile->designRegistries)), 'each localized layout resolves its block sibling fields');
    $editable = $body;
    foreach ($editable['blocks'] as &$items) {
        array_pop($items[0]['values']['layout']['rows'][0]['columns']);
    }
    unset($items);
    $saved = $service->create('landing', $editable, str_repeat('a', 32));
    assertTrueValue($saved instanceof RecordSnapshot, 'block layouts save through ordinary validation');
    $actor = new \Utsuri\PortableCms\Conventional\Identity\SessionContext('session', 'authenticated', str_repeat('a', 32), 'admin@example.test', 'Administrator', 'administrator', 'token', 'csrf', new DateTimeImmutable('2026-10-19T09:00:00Z'), false);
    $published = $service->workflow()->transition('landing', $saved->id, 1, 'published', $actor);
    assertTrueValue($published instanceof RecordSnapshot && $published->workflowState === 'published', 'native publication resolves layout sources against their block siblings');
    $snapshot = staticCompilerFixture()['snapshot'];
    $records = array_map(static fn($record) => $record->typeId !== 'landing' ? $record : new \Utsuri\PortableCms\Conventional\Compiler\FrozenRecord($record->id, $record->typeId, 'published', $record->revisionId, $record->revisionNumber, $body, []), $snapshot->records);
    $media = array_column($snapshot->media, null, 'id');
    \Utsuri\PortableCms\Conventional\Compiler\FrozenBlockValidation::check($records, $media, $profile, SnapshotLanguages::all($profile), $profile->designRegistries, false);
    unset($media['d0000000000000000000000000000001']);
    blocksReject(static fn() => \Utsuri\PortableCms\Conventional\Compiler\FrozenBlockValidation::check($records, $media, $profile, SnapshotLanguages::all($profile), $profile->designRegistries, false));
};

$tests['blocks reference landing remains optional for an empty installation'] = static function (): void {
    $fixture = staticCompilerFixture();
    $document = json_decode((string) file_get_contents($fixture['package']->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $document['records'] = array_values(array_filter($document['records'], static fn(array $record): bool => $record['typeId'] !== 'landing'));
    $snapshot = FrozenSnapshot::fromDocument($document, $fixture['package']);
    $site = $fixture['builder']->build($snapshot);
    $pages = array_filter($site->routes, static fn($route): bool => $route->route === '/en/landing/');
    assertSameValue(1, count($pages), 'the declared optional route has a complete static empty shell');
    assertSameValue(['home', 'about'], $fixture['builder']->requiredSingletons(), 'blocks do not add an installation startup requirement and discovery defaults remain optional');
};

$tests['blocks filter off-language layout captions inside whole-field translations'] = static function (): void {
    [$profile, $field, $body] = blocksFixture();
    $settings = $field->settings;
    $settings['blockTypes'] = [$settings['blockTypes'][0]];
    $settings['blockTypes'][0]['fields'][] = ['id' => 'layout', 'label' => 'Layout', 'type' => 'composition', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['formatVersion' => 2, 'maximumRows' => 2, 'maximumColumns' => 2]];
    $field = new FieldDefinition('blocks', 'Blocks', 'blocks', true, false, false, $settings, maximumItems: 32);
    $profile = blocksTestProfile($field);
    $layout = json_decode('{"formatVersion":2,"rows":[{"id":"row","columns":[{"id":"image","share":1,"content":{"kind":"media","id":"d0000000000000000000000000000001","captions":{"ca":"Peu","es":"Pie","en":"Caption"}}}]}]}', true, flags: JSON_THROW_ON_ERROR);
    foreach ($body['blocks'] as &$items) {
        $items = [$items[0]];
        $items[0]['values']['layout'] = $layout;
    }
    unset($items);
    $filtered = (new SnapshotLanguages(['ca', 'es'], 'es'))->record($profile->type('landing'), $body);
    assertSameValue(['ca' => 'Peu', 'es' => 'Pie'], $filtered['blocks']['ca'][0]['values']['layout']['rows'][0]['columns'][0]['content']['captions'], 'nested layout captions never leak a language that is off');
    assertSameValue(2, count(Blocks::layouts($profile->type('landing'), $filtered, ['ca', 'es'], $profile->designRegistries)), 'the projected layouts validate against exactly the drawn languages');
    assertSameValue('Caption', $body['blocks']['ca'][0]['values']['layout']['rows'][0]['columns'][0]['content']['captions']['en'], 'projection never edits stored block values');
};
