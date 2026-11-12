<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Content\Composition;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaDerivative;
use Utsuri\PortableCms\Conventional\Media\MediaText;
use Utsuri\PortableCms\Conventional\View\CompositionAssets;
use Utsuri\PortableCms\Conventional\View\CompositionMedia;
use Utsuri\PortableCms\Conventional\View\CompositionView;

/** @return array{profile: \Utsuri\PortableCms\Conventional\Content\ProfileManifest, type: \Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition, field: \Utsuri\PortableCms\Conventional\Content\FieldDefinition, raw: array<string, mixed>} */
function linkedCompositionFixture(): array
{
    $fixture = mixedReferenceFixture();
    $profile = $fixture['profile'];
    $type = $profile->type('home');
    $field = array_values(array_filter($type->fields, static fn($field): bool => $field->id === 'collage'))[0];
    return ['profile' => $profile, 'type' => $type, 'field' => $field, 'raw' => $fixture['home']->body['collage']];
}

$tests['empty home collage submits a complete native creation form'] = static function (): void {
    ['profile' => $profile, 'type' => $type] = linkedCompositionFixture();
    $fixture = contentTestApplication(profileOverride: $profile);
    $cookies = authenticatedTestCookies($fixture['app']);
    $page = $fixture['app']->handle(new Request('GET', '/records/home/new', cookies: $cookies));
    $form = compositionNativeValues($page->body);
    $input = \Utsuri\PortableCms\Conventional\Content\EditorialForm::input(new Request('POST', '/records/home/new', form: $form), $type, $profile, null);
    assertSameValue([], $input['collage']['rows'], 'empty native layout remains empty');
    foreach ($profile->locales as $locale) {
        $form['introduction__' . $locale] = 'Fictional studio';
    }
    assertSameValue(303, $fixture['app']->handle(new Request('POST', '/records/home/new', contentMutationHeaders(), $form, $cookies))->status, 'empty optional collage can save');
    $record = $fixture['contentRepository']->list('home', 1)[0];
    $service = new ContentService($fixture['contentRepository'], $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-05T12:00:00Z')), new IdentityTestRandom());
    assertTrueValue($service->workflow()->transition('home', $record->id, $record->revisionNumber, 'published', workflowTestActor()) instanceof RecordSnapshot, 'collage remains optional for an empty installation');
};

$tests['composition v2 bounds typed links and localized captions without reinterpreting v1'] = static function (): void {
    ['profile' => $profile, 'type' => $type, 'field' => $field, 'raw' => $raw] = linkedCompositionFixture();
    $layout = Composition::from($raw, $field, $type, $profile->designRegistries, $profile->locales);
    $settings = $field->settings;
    unset($settings['targetTypes']);
    $settings['formatVersion'] = 1;
    $legacyField = new \Utsuri\PortableCms\Conventional\Content\FieldDefinition($field->id, $field->label, 'composition', false, false, false, $settings);
    mixedReferenceRejected(static fn() => Composition::from($raw, $legacyField, $type, $profile->designRegistries, $profile->locales));
    $legacy = $raw;
    $legacy['formatVersion'] = 1;
    foreach ($legacy['rows'] as &$row) {
        foreach ($row['columns'] as &$column) {
            unset($column['content']['link'], $column['content']['captions']);
        }
        unset($column);
    }
    unset($row);
    Composition::from($legacy, $legacyField, $type, $profile->designRegistries);
    $editor = new \Utsuri\PortableCms\Conventional\View\CompositionEditor($profile, $type, new Request('GET', '/records/home/new'), null, $profile->designRegistries);
    $html = $editor->field($legacyField, $legacy, null);
    assertTrueValue(str_contains($html, 'not enabled by this profile') && !str_contains($html, '__caption__'), 'legacy producers retain v1 editing without offering unsupported details');
    assertSameValue($layout->document(), Composition::from($layout->compact(), $field, $type, $profile->designRegistries, $profile->locales)->document(), 'v2 compact transport is lossless');
    assertSameValue(['project', 'article', 'project'], array_map(static fn($link): string => $link->typeId, $layout->links()), 'mixed link order follows image cells');
    $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-composition-v2.schema.json');
    JsonSchemaValidator::validateJson(json_encode($layout->compact(), JSON_THROW_ON_ERROR), $schema);
    $source = $raw['rows'][0]['columns'][0]['content'];
    assertSameValue($source['captions']['ca'], Composition::caption($source, 'fr', 'ca', 'Shared caption'), 'absent language uses the authored default');
    $source['captions']['es'] = '';
    assertSameValue($source['captions']['ca'], Composition::caption($source, 'es', 'ca'), 'blank language uses the authored default');
    $source['captions'] = ['en' => str_repeat('é', 500)];
    $bounded = $raw;
    $bounded['rows'][0]['columns'][0]['content'] = $source;
    Composition::from($bounded, $field, $type, $profile->designRegistries, $profile->locales);
    $mutations = [
        static function (array &$value): void {
            $value['formatVersion'] = 1;
        },
        static function (array &$value): void {
            $value['formatVersion'] = 3;
        },
        static function (array &$value): void {
            $value['rows'][0]['columns'][0]['content']['link'] = ['href' => 'javascript:alert(1)'];
        },
        static function (array &$value): void {
            $value['rows'][0]['columns'][0]['content']['link']['typeId'] = 'person';
        },
        static function (array &$value): void {
            $value['rows'][0]['columns'][0]['content']['link']['recordId'] = '../record';
        },
        static function (array &$value): void {
            $value['rows'][0]['columns'][0]['content']['captions']['en'] = '<img src=x onerror=alert(1)>';
        },
        static function (array &$value): void {
            $value['rows'][0]['columns'][0]['content']['captions']['en'] = "text\0";
        },
        static function (array &$value): void {
            $value['rows'][0]['columns'][0]['content']['captions']['en'] = str_repeat('é', 501);
        },
        static function (array &$value): void {
            $value['rows'][0]['columns'][0]['content']['captions']['fr'] = 'Unknown site language';
        },
        static function (array &$value): void {
            $value['rows'][0]['columns'][0]['content'] = ['kind' => 'block', 'field' => 'introduction', 'captions' => ['en' => 'Caption']];
        },
    ];
    foreach ($mutations as $mutate) {
        $changed = $raw;
        $mutate($changed);
        mixedReferenceRejected(static fn() => Composition::from($changed, $field, $type, $profile->designRegistries, $profile->locales));
    }
};

$tests['linked image renderer creates one named accessible link and one escaped caption with unchanged geometry'] = static function (): void {
    ['profile' => $profile, 'type' => $type, 'field' => $field, 'raw' => $raw] = linkedCompositionFixture();
    $layout = Composition::from($raw, $field, $type, $profile->designRegistries, $profile->locales);
    $source = $layout->linkedImages()[0];
    $source['captions'] = ['ca' => 'Study & "sketch"'];
    $decorative = static fn(string $id): CompositionMedia => new CompositionMedia('/media/study.webp', 960, 640, caption: 'Shared caption', credit: 'Author');
    $render = static fn(array $value, ?Closure $link = null): string => CompositionAssets::render($value, $type, [], 'es', $decorative, '/es/', $link, 'ca');
    $html = $render($source, static fn(): string => '/es/projects/study/');
    assertSameValue(1, substr_count($html, '<a '), 'one image produces one link');
    assertSameValue(1, substr_count($html, '<figcaption>'), 'caption is outside the link, without a nested figure');
    assertTrueValue(str_contains($html, 'aria-label="Study &amp; &quot;sketch&quot;"'), 'decorative image gets its link name from the localized caption');
    assertTrueValue(str_contains($html, 'href="../es/projects/study/"'), 'record route stays inside the artifact mount');
    assertTrueValue(str_contains($html, 'cms-media-credit'), 'shared credit remains beside the cell caption');
    $described = CompositionAssets::render($source, $type, [], 'en', static fn(): CompositionMedia => new CompositionMedia('/media/study.webp', 960, 640, 'Authored alternative'), link: static fn(): string => '/en/projects/study/', defaultLocale: 'ca');
    assertTrueValue(!str_contains($described, 'aria-label=') && str_contains($described, 'alt="Authored alternative"'), 'authored image alternative names the link naturally');
    mixedReferenceRejected(static fn() => $render($source));
    foreach (['javascript:alert(1)', '//evil.test/', '/../escape/', '/unsafe\\path/'] as $url) {
        mixedReferenceRejected(static fn() => $render($source, static fn(): string => $url));
    }
    $source['captions'] = [];
    mixedReferenceRejected(static fn() => CompositionAssets::render($source, $type, [], 'en', static fn(): CompositionMedia => new CompositionMedia('/image.webp', 960, 640), link: static fn(): string => '/en/projects/study/'));
    $legacy = $layout->compact();
    $legacy['formatVersion'] = 1;
    foreach ($legacy['rows'] as &$row) {
        foreach ($row['columns'] as &$column) {
            unset($column['content']['link'], $column['content']['captions']);
        }
        unset($column);
    }
    unset($row);
    $geometry = static fn(Composition $value): string => CompositionView::render($value, $profile->designRegistries, 'parity', static fn(): string => '')->css;
    assertSameValue($geometry(Composition::from($legacy, $field, $type, $profile->designRegistries)), $geometry($layout), 'links and captions do not alter track or asset geometry');
};

$tests['native collage save preview conflict and publication reject unavailable or nameless targets'] = static function (): void {
    $media = editorialMediaFixture();
    $fixture = mixedReferenceFixture();
    $profile = $fixture['profile'];
    $snapshot = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($snapshot['media'] as $item) {
        $derivative = new MediaDerivative($item['id'], '960-webp', 'derivatives/' . $item['id'] . '/display.webp', 'display.webp', 'image/webp', 960, 640, 512, str_repeat('a', 64), 'fixture', '1', 1, []);
        $media['repository']->items[$item['id']] = new LibraryMedia($item['id'], $item['ownerRecordId'], 'Study image', 'ready', 'image/webp', 512, 960, 640, 1, null, $derivative, MediaText::from($item['text']));
    }
    try {
        $state = contentTestApplication(profileOverride: $profile, library: $media['library']);
        $state['contentRepository']->records = $fixture['repository']->records;
        $service = new ContentService($state['contentRepository'], $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-05T12:00:00Z')), new IdentityTestRandom(), $media['library']);
        $home = $fixture['home'];
        $app = $state['app'];
        $cookies = authenticatedTestCookies($app);
        $path = '/records/home/' . $home->id;
        $initial = $app->handle(new Request('GET', $path, cookies: $cookies));
        assertTrueValue(str_contains($initial->body, 'value="project@10000000000000000000000000000001" selected'), 'native link selector retains a target');
        $form = compositionNativeValues($initial->body);
        $form['collage__collage-top__c0__caption__en'] = 'Unsaved & localized caption';
        $form['collage__locale'] = 'en';
        $preview = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, '_composition_preview' => 'collage'], $cookies));
        assertSameValue(200, $preview->status, 'native controls render the same semantic image producer');
        assertTrueValue(str_contains($preview->body, 'Unsaved &amp; localized caption'), 'preview uses unsaved cell caption');
        $invalid = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, 'introduction__ca' => str_repeat('x', 1001)], $cookies));
        assertSameValue(422, $invalid->status, 'ordinary field validation preserves layout input');
        assertTrueValue(str_contains($invalid->body, 'Unsaved &amp; localized caption'), 'rejected caption remains in the inspector and row strip');
        assertSameValue(303, $app->handle(new Request('POST', $path, contentMutationHeaders(), $form, $cookies))->status, 'native collage saves');
        $saved = $service->find('home', $home->id);
        assertSameValue('Unsaved & localized caption', $saved->body['collage']['rows'][0]['columns'][0]['content']['captions']['en'], 'localized caption survives storage');
        ['type' => $type, 'field' => $field] = linkedCompositionFixture();
        $originalLayout = Composition::from($home->body['collage'], $field, $type, $profile->designRegistries)->document();
        $savedLayout = Composition::from($saved->body['collage'], $field, $type, $profile->designRegistries)->document();
        assertSameValue($originalLayout['rows'][1], $savedLayout['rows'][1], 'inactive rows retain their complete layout semantics');
        $conflict = $app->handle(new Request('POST', $path, contentMutationHeaders(), $form, $cookies));
        assertSameValue(409, $conflict->status, 'stale native save cannot overwrite');
        assertTrueValue(str_contains($conflict->body, 'Unsaved &amp; localized caption'), 'conflict retains all caption controls');
        $body = $saved->body;
        $body['collage']['rows'][0]['columns'][0]['content']['link']['recordId'] = str_repeat('f', 32);
        assertTrueValue($service->update('home', $home->id, $saved->revisionNumber, $body, str_repeat('1', 32)) instanceof ValidationFailure, 'missing record cannot become a new link');
        $form['collage__collage-top__c0__link'] = 'project@10000000000000000000000000000002';
        assertSameValue(422, $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, '_composition_preview' => 'collage'], $cookies))->status, 'draft target produces an editor preview correction');
        $body = $saved->body;
        $body['collage']['rows'][0]['columns'][0]['content']['link']['recordId'] = '10000000000000000000000000000002';
        $draftLink = $service->update('home', $home->id, $saved->revisionNumber, $body, str_repeat('1', 32));
        assertTrueValue($draftLink instanceof RecordSnapshot, 'draft editing can prepare a link to another draft');
        $failed = $service->workflow()->transition('home', $home->id, $draftLink->revisionNumber, 'published', workflowTestActor());
        assertTrueValue($failed instanceof ValidationFailure && isset($failed->errors['collage']), 'publication checks current link workflow state');
        $body = $saved->body;
        unset($body['collage']['rows'][0]['columns'][0]['content']['captions']);
        $assetId = $body['collage']['rows'][0]['columns'][0]['content']['id'];
        $asset = $media['repository']->items[$assetId];
        $media['repository']->items[$assetId] = new LibraryMedia($asset->id, $asset->ownerRecordId, $asset->name, 'ready', 'image/webp', 512, 960, 640, 1, null, $asset->thumbnail, MediaText::from(['formatVersion' => 1, 'decorative' => true, 'translations' => []]));
        $nameless = $service->update('home', $home->id, $draftLink->revisionNumber, $body, str_repeat('1', 32));
        $failed = $service->workflow()->transition('home', $home->id, $nameless->revisionNumber, 'published', workflowTestActor());
        assertTrueValue($failed instanceof ValidationFailure && $failed->errors['collage']->key === 'composition.link_name_required', 'decorative image alone cannot name a link');
        $project = $service->find('project', '10000000000000000000000000000001');
        $state['contentRepository']->records[$project->id] = new RecordSnapshot($project->id, 'project', 'draft', $project->revisionId, $project->revisionNumber, $project->body, $project->updatedAt);
        $body = $project->body;
        $body['gallery'] = json_encode($body['gallery'], JSON_THROW_ON_ERROR);
        $body['year'] = (string) $body['year'];
        $body['sortOrder'] = (string) $body['sortOrder'];
        $body['related'] = [];
        $body['layout']['formatVersion'] = 2;
        $body['layout']['rows'][0]['columns'][0]['content']['link'] = ['typeId' => 'project', 'recordId' => $project->id];
        $body['layout']['rows'][0]['columns'][0]['content']['captions'] = ['ca' => 'Self link'];
        $selfLink = $service->update('project', $project->id, $project->revisionNumber, $body, str_repeat('1', 32));
        assertTrueValue($selfLink instanceof RecordSnapshot, 'draft can prepare its own image link');
        assertTrueValue($service->workflow()->transition('project', $project->id, $selfLink->revisionNumber, 'published', workflowTestActor()) instanceof RecordSnapshot, 'atomic publication makes a self link available');
    } finally {
        removeUploadTestTree($media['root']);
    }
};

$tests['frozen collages resolve actual localized routes and normalized restore preserves exact link and caption values'] = static function (): void {
    $fixture = staticCompilerFixture();
    $document = json_decode((string) file_get_contents($fixture['package']->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $site = $fixture['builder']->build($fixture['snapshot']);
    foreach (Composition::from(linkedCompositionFixture()['raw'], linkedCompositionFixture()['field'], linkedCompositionFixture()['type'], $fixture['snapshot']->designRegistries)->mediaIds() as $id) {
        assertTrueValue(in_array($id, $site->publicMediaIds, true), 'every rendered collage image enters the artifact inventory');
    }
    foreach (['ca', 'es', 'en'] as $locale) {
        $route = array_values(array_filter($site->routes, static fn($route): bool => $route->route === '/' . $locale . '/'))[0];
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($route->html);
            assertSameValue(3, (new DOMXPath($dom))->query('//*[@data-layout]//figcaption')->length, 'home has three semantic collage captions');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        assertTrueValue(str_contains($route->html, 'href="../' . $locale . '/projects/'), 'project link comes from the frozen localized record route');
        assertTrueValue(str_contains($route->html, 'href="../' . $locale . '/articles/'), 'article link comes from its own route');
    }
    foreach (['missing', 'wrong_type', 'unpublished'] as $case) {
        $bad = $document;
        foreach ($bad['records'] as &$record) {
            if ($record['typeId'] === 'home') {
                $record['body']['featuredContent'] = [];
                $record['relationships'] = [];
                $source = &$record['body']['collage']['rows'][0]['columns'][0]['content'];
                if ($case === 'missing') {
                    $source['link']['recordId'] = str_repeat('f', 32);
                }
                if ($case === 'wrong_type') {
                    $source['link']['typeId'] = 'article';
                }
                unset($source);
            }
            if ($case === 'unpublished' && $record['id'] === '30000000000000000000000000000001') {
                $record['workflowState'] = 'draft';
            }
        }
        unset($record);
        mixedReferenceRejected(static fn() => FrozenSnapshot::fromDocument($bad, $fixture['package']));
    }
    $plan = restoreFixturePlan();
    $home = array_values(array_filter(restoreFixtureExport($plan)->document['records'], static fn(array $record): bool => $record['typeId'] === 'home'))[0];
    $revision = array_values(array_filter(restoreFixtureExport($plan)->document['revisions'], static fn(array $revision): bool => $revision['id'] === $home['currentRevisionId']))[0];
    assertSameValue(linkedCompositionFixture()['raw'], $revision['body']['collage'], 'normalized export and restore plan preserve all authored cells exactly');
    foreach (['link', 'caption'] as $case) {
        try {
            restoreFixturePlan(static function (array &$export) use ($case): void {
                foreach ($export['revisions'] as &$revision) {
                    if (!isset($revision['body']['collage'])) {
                        continue;
                    }
                    if ($case === 'link') {
                        $revision['body']['collage']['rows'][0]['columns'][0]['content']['link']['recordId'] = str_repeat('f', 32);
                    } else {
                        $revision['body']['collage']['rows'][0]['columns'][0]['content']['captions']['en'] = '<script>';
                    }
                }
                unset($revision);
            });
            throw new RuntimeException('Unsafe restored collage accepted');
        } catch (\Utsuri\PortableCms\Conventional\Restore\RestoreRejected) {
        }
    }
};
