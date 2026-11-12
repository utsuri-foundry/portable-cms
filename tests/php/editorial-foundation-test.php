<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\EditorialForm;
use Utsuri\PortableCms\Conventional\Content\EditorialTerminology;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\TerminologyConflict;
use Utsuri\PortableCms\Conventional\Content\TerminologyRepository;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;

final class MemoryTerminologyRepository implements TerminologyRepository
{
    public ?EditorialTerminology $current = null;

    public function load(string $profileId): EditorialTerminology
    {
        return $this->current ?? new EditorialTerminology($profileId, 0, []);
    }

    public function save(EditorialTerminology $intent, string $actor, string $auditId, DateTimeImmutable $now): EditorialTerminology
    {
        $current = $this->load($intent->profileId);
        if ($intent->revision !== $current->revision) {
            throw new TerminologyConflict($current);
        }
        return $this->current = new EditorialTerminology($intent->profileId, $intent->revision + 1, $intent->labels);
    }
}

$tests['CMS terminology changes presentation without changing identity or public routes'] = static function (): void {
    $profile = ProfileManifest::gateZero();
    $labels = new EditorialTerminology($profile->id, 1, [
        ['typeId' => 'portfolio-entry', 'singular' => '<Case>', 'plural' => 'Cases'],
    ]);
    $presented = $labels->present($profile);
    foreach ($profile->types as $type) {
        $after = $presented->type($type->id);
        assertTrueValue($after !== null, 'all original types must remain installed');
        assertSameValue($type->fields, $after->fields, 'labels must not mutate fields or relationship targets');
        assertSameValue($type->route, $after->route, 'labels must not rename public routes');
        assertSameValue($type->renderer, $after->renderer, 'labels must not change renderer identity');
    }
    assertSameValue('Portfolio entries', $profile->type('portfolio-entry')?->label, 'original profile must remain unchanged');
    assertSameValue('Cases', $presented->type('portfolio-entry')?->label, 'presentation should use the chosen terminology');
    $json = json_encode($labels->document(), JSON_THROW_ON_ERROR);
    JsonSchemaValidator::validateJson($json, (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-terminology-v1.schema.json'));
    assertSameValue($labels->document(), EditorialTerminology::fromDocument(json_decode($json, true, flags: JSON_THROW_ON_ERROR))->document(), 'exported names must round trip');
    foreach ([
        ['typeId' => 'not-installed', 'singular' => 'Item', 'plural' => 'Items'],
        ['typeId' => 'portfolio-entry', 'singular' => '', 'plural' => 'Items'],
    ] as $invalid) {
        try {
            (new EditorialTerminology($profile->id, 1, [$invalid]))->present($profile);
            throw new RuntimeException('Invalid label must be rejected');
        } catch (InvalidArgumentException) {
        }
    }
    try {
        new EditorialTerminology($profile->id, 1, [$labels->labels[0], $labels->labels[0]]);
        throw new RuntimeException('Duplicate types must be rejected');
    } catch (InvalidArgumentException) {
    }
};

$tests['terminology native forms enforce authentication CSRF validation and revision conflicts'] = static function (): void {
    $repository = new MemoryTerminologyRepository();
    $app = contentTestApplication(terminologyRepository: $repository)['app'];
    $guest = $app->handle(new Request('GET', '/settings/terminology'));
    assertSameValue(303, $guest->status, 'guests must not access naming settings');
    $cookies = authenticatedTestCookies($app);
    $form = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '0'];
    foreach (ProfileManifest::gateZero()->types as $type) {
        $form[$type->id . '__singular'] = $type->singularLabel;
        $form[$type->id . '__plural'] = $type->label;
    }
    $form['portfolio-entry__plural'] = 'Cases';
    $rejected = $app->handle(new Request('POST', '/settings/terminology', ['origin' => 'https://evil.test'], $form, $cookies));
    assertSameValue(403, $rejected->status, 'foreign origin must not save settings');
    $rejected = $app->handle(new Request('POST', '/settings/terminology', contentMutationHeaders(), [...$form, '_csrf' => 'wrong'], $cookies));
    assertSameValue(403, $rejected->status, 'CSRF must be session bound');
    $invalid = $app->handle(new Request('POST', '/settings/terminology', contentMutationHeaders(), [...$form, 'article__plural' => ''], $cookies));
    assertSameValue(422, $invalid->status, 'invalid names must preserve the form');
    assertTrueValue(str_contains($invalid->body, 'value="Cases"'), 'valid neighbouring input must survive validation');
    assertSameValue(null, $repository->current, 'validation and authorization must not save');
    $saved = $app->handle(new Request('POST', '/settings/terminology', contentMutationHeaders(), $form, $cookies));
    assertSameValue(303, $saved->status, 'success must use POST redirect GET');
    $conflict = $app->handle(new Request('POST', '/settings/terminology', contentMutationHeaders(), [...$form, 'portfolio-entry__plural' => '<Works>'], $cookies));
    assertSameValue(409, $conflict->status, 'stale names must not overwrite current names');
    assertTrueValue(str_contains($conflict->body, 'value="&lt;Works&gt;"'), 'conflict must preserve and escape editor input');
    assertTrueValue(str_contains($conflict->body, 'name="_revision" value="1"'), 'conflict resolution must explicitly target the newer revision');
    assertSameValue(1, $repository->current?->revision, 'conflict must leave saved revision alone');
};

$tests['ordinary forms preserve server-owned structured values and all localized input'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $type = $profile->type('project');
    assertTrueValue($type !== null, 'project fixture must be installed');
    $body = ['layout' => ['formatVersion' => 1, 'rows' => []], 'body' => array_fill_keys($profile->locales, ['formatVersion' => 1, 'nodes' => []]), 'gallery' => ['d0000000000000000000000000000001']];
    $record = new RecordSnapshot(str_repeat('a', 32), 'project', 'draft', str_repeat('b', 32), 2, $body, new DateTimeImmutable('2026-10-03T00:00:00Z'));
    $values = EditorialForm::input(new Request('POST', '/records/project/' . $record->id, form: [
        '_editorial_end' => '1',
        'title__en' => '<Unsaved>', 'title__es' => 'Sin guardar', 'title__ca' => 'Sense desar',
        'layout' => '{"hostile":true}', 'gallery' => '[]', 'body' => '',
    ]), $type, $profile, $record);
    foreach ($body as $key => $value) {
        assertSameValue($value, in_array($key, ['body', 'layout'], true) ? $values[$key] : json_decode($values[$key], true, flags: JSON_THROW_ON_ERROR), 'ordinary save must preserve server-owned ' . $key);
    }
    assertSameValue(['ca' => 'Sense desar', 'es' => 'Sin guardar', 'en' => '<Unsaved>'], $values['title'], 'every locale must retain its submitted input');
    assertSameValue('discovery', EditorialForm::section($type->fields[1]), 'profile must explicitly place slugs in Discovery');
};

$tests['HTTP edits preserve structured content through validation success and conflict'] = static function (): void {
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
    $form = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => (string) $record->revisionNumber,
        '_editorial_end' => '1',
        'year' => '1800', 'sortOrder' => '10', 'status' => 'complete', 'featured' => '1', 'publishedOn' => '2026-03-14',
        'lead' => $record->body['lead'], 'related' => $record->body['related'], 'disciplines' => $record->body['disciplines'],
        'layout' => '{"hostile":true}', 'body' => '', 'gallery' => '[]'];
    foreach ($profile->locales as $locale) {
        $form['title__' . $locale] = 'Unsaved ' . $locale;
        $form['slug__' . $locale] = $record->body['slug'][$locale];
    }
    $path = '/records/project/' . $record->id;
    $invalid = $app->handle(new Request('POST', $path, contentMutationHeaders(), $form, $cookies));
    assertSameValue(422, $invalid->status, 'invalid native number must be rejected server-side');
    assertTrueValue(str_contains($invalid->body, 'value="1800"'), 'invalid numeric input must not disappear');
    assertTrueValue(str_contains($invalid->body, 'data-issue-count="1"'), 'affected section must show its issue count');
    foreach ($profile->locales as $locale) {
        assertTrueValue(str_contains($invalid->body, 'value="Unsaved ' . $locale . '"'), 'every locale must survive validation');
    }
    $form['year'] = '2025';
    $saved = $app->handle(new Request('POST', $path, contentMutationHeaders(), $form, $cookies));
    assertSameValue(303, $saved->status, 'ordinary edits must save through native POST redirect GET');
    $current = $repository->records[$record->id];
    foreach (['layout', 'body', 'gallery', 'trailer', 'credits'] as $field) {
        assertSameValue($record->body[$field], $current->body[$field], 'saving must preserve structured field ' . $field);
    }
    $conflict = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, 'title__en' => 'Stale English'], $cookies));
    assertSameValue(409, $conflict->status, 'a stale localized edit must not overwrite anything');
    assertTrueValue(str_contains($conflict->body, 'value="Stale English"'), 'stale input must remain available');
    assertTrueValue(str_contains($conflict->body, 'data-conflict-summary'), 'conflict must have a focus boundary');
    assertSameValue($current, $repository->records[$record->id], 'conflict must not mutate the saved record');
};
