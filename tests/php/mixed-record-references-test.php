<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\EditorialForm;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordReference;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\View\ReferenceControls;

/** @return array{profile: ProfileManifest, repository: MemoryRecordRepository, service: ContentService, home: RecordSnapshot, refs: list<array{typeId: string, recordId: string}>} */
function mixedReferenceFixture(?MemoryRecordRepository $repository = null): array
{
    $root = dirname(__DIR__, 2);
    $profile = ProfileManifest::load($root . '/profiles/reference-studio/profile.json');
    $snapshot = json_decode((string) file_get_contents($root . '/profiles/reference-studio/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
    $repository ??= new MemoryRecordRepository();
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-05T10:00:00Z'));
    foreach ($snapshot['records'] as $record) {
        $repository->records[$record['id']] = new RecordSnapshot($record['id'], $record['typeId'], $record['workflowState'], $record['revisionId'], $record['revisionNumber'], $record['body'], $clock->now());
    }
    $home = $repository->records['50000000000000000000000000000001'];
    return ['profile' => $profile, 'repository' => $repository, 'service' => new ContentService($repository, $profile, $clock, new IdentityTestRandom()), 'home' => $home, 'refs' => $home->body['featuredContent']];
}

/** @param Closure(): mixed $operation */
function mixedReferenceRejected(Closure $operation): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Invalid mixed reference unexpectedly passed');
}

$tests['profile v2 bounds mixed targets and preserves legacy v1 and single type declarations'] = static function (): void {
    $root = dirname(__DIR__, 2);
    $source = json_decode(ProfileManifest::load($root . '/profiles/reference-studio/profile.json')->definitionJson ?? throw new RuntimeException('Missing expanded profile'), false, 32, JSON_THROW_ON_ERROR);
    $v2 = (string) file_get_contents($root . '/schemas/portable-cms-profile-v2.schema.json');
    JsonSchemaValidator::validateJson(json_encode($source, JSON_THROW_ON_ERROR), $v2);
    $legacy = ProfileManifest::load($root . '/engine/src/cms/conventional/profile/gate-zero.json');
    assertSameValue(['topic'], $legacy->type('portfolio-entry')->fields[2]->targetTypes, 'v1 retains its target');
    $path = tempnam(sys_get_temp_dir(), 'cms-mixed-profile-');
    try {
        foreach ([[], ['project', 'project'], array_fill(0, 9, 'project'), ['project', '../article']] as $invalid) {
            $changed = json_decode(json_encode($source, JSON_THROW_ON_ERROR));
            $changed->singletons[0]->fields[1]->settings->targetTypes = $invalid;
            mixedReferenceRejected(static fn() => JsonSchemaValidator::validateJson(json_encode($changed, JSON_THROW_ON_ERROR), $v2));
        }
        foreach (['discipline', 'uninstalled'] as $invalid) {
            $changed = json_decode(json_encode($source, JSON_THROW_ON_ERROR));
            $changed->singletons[0]->fields[1]->settings->targetTypes = ['project', $invalid];
            file_put_contents($path, json_encode($changed, JSON_THROW_ON_ERROR));
            mixedReferenceRejected(static fn() => ProfileManifest::load($path));
        }
        $changed = json_decode(json_encode($source, JSON_THROW_ON_ERROR));
        $changed->formatVersion = 1;
        file_put_contents($path, json_encode($changed, JSON_THROW_ON_ERROR));
        mixedReferenceRejected(static fn() => ProfileManifest::load($path));
        $changed = json_decode(json_encode($source, JSON_THROW_ON_ERROR));
        $changed->singletons[0]->fields[1]->settings->targetType = 'project';
        mixedReferenceRejected(static fn() => JsonSchemaValidator::validateJson(json_encode($changed, JSON_THROW_ON_ERROR), $v2));
        unset($changed->singletons[0]->fields[1]->settings->targetTypes);
        file_put_contents($path, json_encode($changed, JSON_THROW_ON_ERROR));
        assertSameValue('project', ProfileManifest::load($path)->type('home')->fields[1]->targetType, 'v2 single type uses unchanged IDs');
    } finally {
        unlink($path);
    }
};

$tests['mixed reference service preserves order and rejects wrong missing duplicate and hostile targets'] = static function (): void {
    $fixture = mixedReferenceFixture();
    $service = $fixture['service'];
    $home = $fixture['home'];
    $references = array_reverse($fixture['refs']);
    $saved = $service->update('home', $home->id, $home->revisionNumber, [...$home->body, 'featuredContent' => $references], str_repeat('1', 32));
    assertTrueValue($saved instanceof RecordSnapshot, 'mixed ordered list saves');
    assertSameValue($references, $saved->body['featuredContent'], 'portable body retains exact mixed order');
    assertSameValue(array_column($references, 'recordId'), array_map(static fn($relation) => $relation->targetRecordId, $fixture['repository']->relationships[$home->id]), 'index follows one order across types');
    $service->validateCanonical('home', $saved->body);
    foreach ([
        [['typeId' => 'person', 'recordId' => '40000000000000000000000000000001']],
        [['typeId' => 'article', 'recordId' => $references[0]['recordId']]],
        [['typeId' => 'project', 'recordId' => str_repeat('f', 32)]],
        [$references[0], $references[0]],
        [['typeId' => '../project', 'recordId' => str_repeat('1', 32)]],
        [['typeId' => 'project', 'recordId' => '<script>']],
        [[...$references[0], 'providerId' => 'external-identity']],
        [$references[0]['recordId']],
        array_fill(0, 13, $references[0]),
    ] as $invalid) {
        $failed = $service->update('home', $home->id, $saved->revisionNumber, [...$home->body, 'featuredContent' => $invalid], str_repeat('1', 32));
        assertTrueValue($failed instanceof ValidationFailure && isset($failed->errors['featuredContent']), 'invalid reference fails before writing');
    }
    assertSameValue($saved->revisionNumber, $fixture['repository']->find('home', $home->id)->revisionNumber, 'hostile references write nothing');
};

$tests['mixed references retain type in single and nested native controls'] = static function (): void {
    $fixture = mixedReferenceFixture();
    $field = new FieldDefinition('lead', 'Lead', 'record_reference', false, false, false, ['targetTypes' => ['project', 'article']]);
    $type = new RecordTypeDefinition('home', 'Home', 'Home', [$field], 'singleton', 'page', 'home', '/');
    $token = RecordReference::from($fixture['refs'][0])->token();
    $selected = EditorialControls::apply(new Request('POST', '/', form: ['_editor_action' => 'select:lead:' . $token]), $type, $fixture['profile'], ['lead' => null], new IdentityTestRandom());
    assertSameValue($fixture['refs'][0], $selected['lead'], 'single mixed selection stores an object');
    $input = EditorialForm::input(new Request('POST', '/', form: ['_present_lead' => '1', 'lead' => $token]), $type, $fixture['profile'], null);
    assertSameValue($selected, $input, 'native token decodes to the portable value');
    mixedReferenceRejected(static fn() => RecordReference::fromToken('article@../bad'));
    $group = new FieldDefinition('curation', 'Curation', 'repeatable_group', false, false, false, [], [$field], maximumItems: 4);
    $identity = str_repeat('e', 32);
    $name = EditorialControls::childName('curation_i' . $identity, 'lead');
    $groupInput = EditorialControls::groupInput(new Request('POST', '/', form: ['curation_items' => [$identity], $name => $token]), $group, $fixture['profile']);
    assertSameValue($fixture['refs'][0], $groupInput[0]['lead'], 'nested control decodes a typed reference');
    $graph = RecordReference::fields([$group], ['curation' => $groupInput], $fixture['profile']->locales, true);
    assertSameValue($token, $graph[$name][0]->token(), 'nested frozen graph resolves the stable item identity');
};

$tests['native mixed picker filters types without scripts and keeps one ordered list'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $state = contentTestApplication(profileOverride: $profile);
    $fixture = mixedReferenceFixture($state['contentRepository']);
    $app = $state['app'];
    $cookies = authenticatedTestCookies($app);
    $home = $fixture['home'];
    $path = '/records/home/' . $home->id;
    $tokens = array_map(static fn(array $ref): string => RecordReference::from($ref)->token(), $fixture['refs']);
    $post = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => (string) $home->revisionNumber, '_editorial_end' => '1', '_present_featuredContent' => '1', 'featuredContent' => $tokens];
    foreach ($home->body['introduction'] as $locale => $text) {
        $post['introduction__' . $locale] = $text;
    }
    $filtered = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$post, '_type_featuredContent' => 'article', '_editor_action' => 'search:featuredContent'], $cookies));
    assertSameValue(200, $filtered->status, 'filter is a native unsaved action');
    assertTrueValue(str_contains($filtered->body, 'value="article" selected') && str_contains($filtered->body, 'name="featuredContent[]" value="' . $tokens[1] . '"'), 'filter retains selected project outside results');
    assertTrueValue(!str_contains($filtered->body, 'select:featuredContent:project@'), 'filtered candidates exclude projects');
    $reordered = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$post, '_editor_action' => 'up:featuredContent:' . $tokens[1]], $cookies));
    assertSameValue(200, $reordered->status, 'native reorder is transient');
    assertTrueValue(strpos($reordered->body, 'value="' . $tokens[1] . '"') < strpos($reordered->body, 'value="' . $tokens[0] . '"'), 'one order spans both types');
    assertSameValue($home->revisionNumber, $fixture['repository']->find('home', $home->id)->revisionNumber, 'browsing and ordering never save');
    $saved = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$post, 'featuredContent' => array_reverse($tokens)], $cookies));
    assertSameValue(303, $saved->status, 'native mixed list saves with redirect');
    assertSameValue(array_reverse($fixture['refs']), $fixture['repository']->find('home', $home->id)->body['featuredContent'], 'saved canonical values carry type');
    assertSameValue(400, $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$post, 'featuredContent' => ['article@<script>']], $cookies))->status, 'hostile native identifiers fail safely');
};

$tests['mixed picker reports missing unpublished and removed types in all interface languages'] = static function (): void {
    $fixture = mixedReferenceFixture();
    $field = $fixture['profile']->type('home')->fields[1];
    $removed = new FieldDefinition($field->id, $field->label, $field->type, false, false, false, ['targetTypes' => ['project'], 'maximumItems' => 12], maximumItems: 12);
    $draft = ['typeId' => 'project', 'recordId' => '10000000000000000000000000000002'];
    foreach (['en', 'ca', 'es', 'fr', 'de'] as $locale) {
        $t = \Utsuri\PortableCms\Conventional\I18n\Translator::load($locale);
        $controls = new ReferenceControls($fixture['service'], $fixture['profile'], new Request('GET', '/'), translator: $t);
        $missing = $controls->field($field, [['typeId' => 'article', 'recordId' => str_repeat('f', 32)]], null);
        assertTrueValue(str_contains($missing, $t->html('picker.target_missing')), 'missing targets are visible and removable in ' . $locale);
        assertTrueValue(str_contains($controls->field($field, [$draft], null), $t->html('picker.target_unpublished')), 'unpublished targets are reported in ' . $locale);
        assertTrueValue(str_contains($controls->field($removed, $fixture['refs'], null), $t->html('picker.type_removed')), 'removed types remain visible for correction in ' . $locale);
    }
};

$tests['frozen mixed references retain types resolve each route and fail on stale targets and projections'] = static function (): void {
    $fixture = staticCompilerFixture();
    $document = json_decode((string) file_get_contents($fixture['package']->root . '/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
    $homeIndex = array_search('home', array_column($document['records'], 'typeId'), true);
    $routes = $fixture['builder']->build($fixture['snapshot'])->routes;
    $html = array_values(array_filter($routes, static fn($route): bool => $route->route === '/en/'))[0]->html;
    assertTrueValue(strpos($html, 'href="../en/articles/measuring-shade/') < strpos($html, 'href="../en/projects/archive-of-mist/'), 'curated mixed order resolves each target route');
    $home = $fixture['snapshot']->record('50000000000000000000000000000001');
    assertSameValue(['article', 'project'], array_map(static fn($relation) => $relation->targetTypeId, $home->relationships), 'frozen graph records the types');
    foreach (['wrong-type', 'missing-type', 'unpublished', 'deleted', 'wrong-body', 'missing-projection'] as $failure) {
        $changed = $document;
        if ($failure === 'wrong-type') {
            $changed['records'][$homeIndex]['relationships'][0]['targetTypeId'] = 'project';
        } elseif ($failure === 'missing-type') {
            unset($changed['records'][$homeIndex]['relationships'][0]['targetTypeId']);
        } elseif ($failure === 'wrong-body') {
            $changed['records'][$homeIndex]['body']['featuredContent'] = array_reverse($changed['records'][$homeIndex]['body']['featuredContent']);
        } elseif ($failure === 'missing-projection') {
            $changed['records'][$homeIndex]['relationships'] = [];
        } else {
            $index = array_search('article', array_column($changed['records'], 'typeId'), true);
            if ($failure === 'deleted') {
                array_splice($changed['records'], $index, 1);
            } else {
                $changed['records'][$index]['workflowState'] = 'draft';
            }
        }
        mixedReferenceRejected(static fn() => FrozenSnapshot::fromDocument($changed, $fixture['package']));
    }
};
