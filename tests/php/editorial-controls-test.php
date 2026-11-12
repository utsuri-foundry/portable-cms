<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\EditorialForm;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\RequestBody;
use Utsuri\PortableCms\Conventional\Http\UploadedFile;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaDerivative;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaLibraryRepository;
use Utsuri\PortableCms\Conventional\Media\MediaPage;

final class MemoryMediaLibrary implements MediaLibraryRepository
{
    /** @var array<string, LibraryMedia> */
    public array $items = [];
    public int $writes = 0;

    public function search(string $query, int $page): MediaPage
    {
        $items = array_values(array_filter($this->items, static fn(LibraryMedia $item): bool => $query === '' || mb_stripos($item->name, $query) !== false));
        return new MediaPage(array_slice($items, ($page - 1) * 20, 20), count($items) > $page * 20, $page, $query);
    }

    public function find(string $id): ?LibraryMedia
    {
        return $this->items[$id] ?? null;
    }

    public function usage(string $id): array
    {
        return [];
    }

    public function rename(string $id, int $expectedRevision, string $name, string $actorId, string $auditId, DateTimeImmutable $now, ?\Utsuri\PortableCms\Conventional\Media\MediaText $text = null, ?\Utsuri\PortableCms\Conventional\Media\FocalPoint $focalPoint = null, bool $keepForWebsite = false): bool
    {
        $media = $this->find($id);
        if ($media === null || $media->metadataRevision !== $expectedRevision) {
            return false;
        }
        $this->items[$id] = new LibraryMedia($id, $keepForWebsite ? null : $media->ownerRecordId, $name, $media->status, $media->mime, $media->byteSize, $media->width, $media->height, $expectedRevision + 1, null, $media->thumbnail, $text ?? $media->text, $focalPoint ?? $media->focalPoint);
        $this->writes++;
        return true;
    }
}

/** @return array{library: MediaLibrary, repository: MemoryMediaLibrary, root: string} */
function editorialMediaFixture(): array
{
    $root = sys_get_temp_dir() . '/portable-editorial-media-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $repository = new MemoryMediaLibrary();
    for ($index = 1; $index <= 25; $index++) {
        $id = str_pad(dechex($index), 32, '0', STR_PAD_LEFT);
        $repository->items[$id] = new LibraryMedia($id, str_repeat('1', 32), 'Image ' . $index . '.webp', 'ready', 'image/webp', 512, 1200, 800, 1, null, null);
    }
    $library = new MediaLibrary($repository, new FilesystemMediaStore($root), new IdentityTestClock(new DateTimeImmutable('2026-10-03T12:00:00Z')), new IdentityTestRandom());
    return compact('library', 'repository', 'root');
}

$tests['media library is bounded private escaped and metadata conflicts preserve input'] = static function (): void {
    $fixture = editorialMediaFixture();
    try {
        $state = contentTestApplication(library: $fixture['library']);
        $app = $state['app'];
        $cookies = authenticatedTestCookies($app);
        $id = array_key_first($fixture['repository']->items);
        assertSameValue(303, $app->handle(new Request('GET', '/media'))->status, 'library is not public');
        $first = $app->handle(new Request('GET', '/media', cookies: $cookies));
        assertSameValue(20, substr_count($first->body, '<li class="admin-card">'), 'library page is bounded');
        assertTrueValue(str_contains($first->body, 'page=2'), 'library has native next page');
        $second = $app->handle(new Request('GET', '/media', cookies: $cookies, query: ['page' => '2']));
        assertSameValue(5, substr_count($second->body, '<li class="admin-card">'), 'second page contains remaining records');
        $post = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '1', 'name' => '<script>renamed</script>'];
        assertSameValue(403, $app->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), [...$post, '_csrf' => 'bad'], $cookies))->status, 'metadata requires CSRF');
        assertSameValue(303, $app->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), $post, $cookies))->status, 'metadata saves with redirect');
        $conflict = $app->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), [...$post, 'name' => 'my unsaved name'], $cookies));
        assertSameValue(409, $conflict->status, 'stale name is not overwritten');
        assertTrueValue(str_contains($conflict->body, 'my unsaved name'), 'name conflict preserves input');
        assertTrueValue(!str_contains($conflict->body, '<script>renamed'), 'names remain escaped');
        assertSameValue(1, $fixture['repository']->writes, 'conflict writes nothing');
        assertSameValue(404, $app->handle(new Request('GET', '/media/' . $id . '/thumbnail', cookies: $cookies))->status, 'missing derivative never returns an original');
    } finally {
        removeUploadTestTree($fixture['root']);
    }
};

$tests['reference actions preserve selections across pagination validation and conflicts'] = static function (): void {
    $fixture = editorialMediaFixture();
    try {
        $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
        $state = contentTestApplication(profileOverride: $profile, library: $fixture['library']);
        $app = $state['app'];
        $cookies = authenticatedTestCookies($app);
        $repository = $state['contentRepository'];
        $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-03T12:00:00Z'));
        $service = new ContentService($repository, $profile, $clock, new IdentityTestRandom(), $fixture['library']);
        $record = $service->create('project', ['title' => ['ca' => 'Test', 'es' => 'Test', 'en' => 'Test'], 'gallery' => '[]'], str_repeat('1', 32));
        assertTrueValue($record instanceof RecordSnapshot, 'project is valid');
        $path = '/records/project/' . $record->id;
        $post = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '1', 'title__ca' => 'Test', 'title__es' => 'Test', 'title__en' => 'Test', '_present_gallery' => '1', '_editorial_end' => '1'];
        $id = array_key_first($fixture['repository']->items);
        $selection = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$post, '_editor_action' => 'select:gallery:' . $id], $cookies));
        assertSameValue(200, $selection->status, 'select is transient');
        assertTrueValue(str_contains($selection->body, 'name="gallery[]" value="' . $id . '"'), 'selection is carried by a native control');
        assertSameValue(1, $repository->find('project', $record->id)->revisionNumber, 'selection does not save implicitly');
        $paged = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$post, 'gallery' => [$id], '_editor_action' => 'page:gallery:2'], $cookies));
        assertSameValue(200, $paged->status, 'paging is a native round trip');
        assertTrueValue(str_contains($paged->body, 'Image 25.webp'), 'paging reaches later images');
        assertTrueValue(str_contains($paged->body, 'name="gallery[]" value="' . $id . '"'), 'selection outside page remains selected');
        $invalid = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$post, 'gallery' => [$id], 'title__ca' => ''], $cookies));
        assertSameValue(422, $invalid->status, 'validation runs on save');
        assertTrueValue(str_contains($invalid->body, 'name="gallery[]" value="' . $id . '"'), 'validation retains media');
        $saved = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$post, 'gallery' => [$id]], $cookies));
        assertSameValue(303, $saved->status, 'ready media saves');
        $conflict = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$post, 'gallery' => [$id]], $cookies));
        assertSameValue(409, $conflict->status, 'old revision does not overwrite');
        assertTrueValue(str_contains($conflict->body, 'name="gallery[]" value="' . $id . '"'), 'conflict retains media');
        $conflictSearch = $app->handle(new Request('POST', $path, contentMutationHeaders(), [...$post, '_revision' => '2', '_editor_base_revision' => '1', 'gallery' => [$id], '_editor_action' => 'search:gallery'], $cookies));
        assertSameValue(409, $conflictSearch->status, 'browsing a conflicted form does not dismiss conflict authority');
        assertTrueValue(str_contains($conflictSearch->body, 'based on revision 1'), 'transient browsing retains the original stale base');
        $untrusted = $service->update('project', $record->id, 2, ['title' => ['ca' => 'Test', 'es' => 'Test', 'en' => 'Test'], 'gallery' => json_encode([str_repeat('f', 32)], JSON_THROW_ON_ERROR)], str_repeat('1', 32));
        assertTrueValue($untrusted instanceof ValidationFailure, 'unavailable media cannot be saved');
    } finally {
        removeUploadTestTree($fixture['root']);
    }
};

$tests['repeatable groups use stable identities native actions and nested validation'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $type = $profile->type('project');
    $random = new IdentityTestRandom();
    $values = EditorialControls::apply(new Request('POST', '/', form: ['_editor_action' => 'add:credits:ca']), $type, $profile, ['credits' => '{"ca":[],"es":[],"en":[]}'], $random);
    $group = EditorialControls::structured($values['credits']);
    $id = $group['ca'][0]['_itemId'];
    $role = EditorialControls::childName('credits_ca_i' . $id, 'role');
    $name = EditorialControls::childName('credits_ca_i' . $id, 'name');
    $input = EditorialForm::input(new Request('POST', '/', form: ['_editorial_end' => '1', '_present_credits' => '1', 'credits_ca_items' => [$id], $role => 'Photographer', $name => 'Iris']), $type, $profile, null);
    $input['title'] = ['ca' => 'T', 'es' => 'T', 'en' => 'T'];
    $input['gallery'] = '[]';
    $repository = new MemoryRecordRepository();
    $service = new ContentService($repository, $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-03T12:00:00Z')), $random);
    $saved = $service->create('project', $input, str_repeat('1', 32));
    assertTrueValue($saved instanceof RecordSnapshot, 'native group validates');
    assertSameValue($id, $saved->body['credits']['ca'][0]['_itemId'], 'identity persists');
    assertSameValue('Photographer', $saved->body['credits']['ca'][0]['role'], 'localized parent carries scalar child');
    $input['credits'] = json_encode(['ca' => [['_itemId' => $id, 'name' => str_repeat('x', 161)]], 'es' => [], 'en' => []], JSON_THROW_ON_ERROR);
    assertTrueValue($service->update('project', $saved->id, 1, $input, str_repeat('1', 32)) instanceof ValidationFailure, 'nested limits are enforced');
    try {
        EditorialForm::input(new Request('POST', '/', form: ['_present_credits' => '1', 'credits_ca_items' => [$id, $id]]), $type, $profile, $saved);
        throw new RuntimeException('duplicate group identities accepted');
    } catch (InvalidArgumentException) {
    }
    $ordered = EditorialControls::apply(new Request('POST', '/', form: ['_editor_action' => 'add:credits:ca']), $type, $profile, ['credits' => json_encode($saved->body['credits'], JSON_THROW_ON_ERROR)], $random);
    $ids = array_column(EditorialControls::structured($ordered['credits'])['ca'], '_itemId');
    $moved = EditorialControls::apply(new Request('POST', '/', form: ['_editor_action' => 'up:credits:ca:' . $ids[1]]), $type, $profile, $ordered, $random);
    assertSameValue(array_reverse($ids), array_column(EditorialControls::structured($moved['credits'])['ca'], '_itemId'), 'reorder retains identity');
};

$tests['taxonomy inline creation is authorized and deprecated selections are guarded'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $state = contentTestApplication(profileOverride: $profile);
    $app = $state['app'];
    $cookies = authenticatedTestCookies($app);
    $post = ['_editorial_end' => '1', '_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_editor_action' => 'term:disciplines', '_term_disciplines__ca' => 'Fusta', '_term_disciplines__es' => 'Madera', '_term_disciplines__en' => 'Wood'];
    assertSameValue(403, $app->handle(new Request('POST', '/records/project', contentMutationHeaders(), $post))->status, 'anonymous term creation is rejected');
    $created = $app->handle(new Request('POST', '/records/project', contentMutationHeaders(), $post, $cookies));
    assertSameValue(200, $created->status, 'term creates without saving the new project');
    assertTrueValue(str_contains($created->body, 'Fusta'), 'new term is selected by its label');
    $terms = $state['contentRepository']->list('discipline', 100);
    assertSameValue(1, count($terms), 'only the taxonomy is saved');
    $clock = new IdentityTestClock(new DateTimeImmutable('2026-10-03T12:00:00Z'));
    $service = new ContentService($state['contentRepository'], $profile, $clock, new IdentityTestRandom());
    $term = $service->update('discipline', $terms[0]->id, 1, ['label' => $terms[0]->body['label'], 'aliases' => ['ca' => 'roure', 'es' => 'roble', 'en' => 'oak'], 'deprecated' => true], str_repeat('1', 32));
    assertSameValue(1, count($service->search('discipline', 'OAK', 1)->records), 'search includes localized aliases irrespective of case');
    $invalid = $service->create('project', ['title' => ['ca' => 'T', 'es' => 'T', 'en' => 'T'], 'gallery' => '[]', 'disciplines' => [$term->id]], str_repeat('1', 32));
    assertTrueValue($invalid instanceof ValidationFailure, 'deprecated term cannot be newly selected');
};

$tests['native upload shares resumable validation and durable processing without scripts'] = static function (): void {
    $state = contentTestApplication(withMediaUploads: true);
    try {
        $app = $state['app'];
        $cookies = authenticatedTestCookies($app);
        $created = $app->handle(new Request('POST', '/records/portfolio-entry', contentMutationHeaders(), ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'title' => 'Native upload', 'summary' => ''], $cookies));
        $path = $created->headers()['location'][0];
        $id = basename($path);
        $state['uploadRepository']->records[$id] = ['typeId' => 'portfolio-entry', 'revisionNumber' => 1];
        $bytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6nM8AAAAASUVORK5CYII=');
        $file = new UploadedFile('native.png', 'image/png', strlen($bytes), RequestBody::fromString($bytes));
        $upload = $app->handle(new Request('POST', $path . '/upload', contentMutationHeaders(), ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '1'], $cookies, files: [$file]));
        assertSameValue(202, $upload->status, 'native multipart path accepts image');
        assertSameValue(1, count($state['uploadRepository']->jobs), 'native upload enqueues the same durable job');
        assertSameValue(1, count($state['uploadRepository']->media), 'original is durable');
        $rejected = $app->handle(new Request('POST', $path . '/upload', contentMutationHeaders(), ['_csrf' => 'bad', '_revision' => '1'], $cookies, files: [$file]));
        assertSameValue(403, $rejected->status, 'native upload is CSRF protected before opening files');
    } finally {
        removeUploadTestTree($state['mediaRoot']);
    }
};

$tests['private thumbnails verify immutable bytes and reject symbolic links'] = static function (): void {
    $fixture = editorialMediaFixture();
    try {
        $id = array_key_first($fixture['repository']->items);
        $directory = $fixture['root'] . '/derivatives/' . $id;
        mkdir($directory, 0700, true);
        $bytes = 'verified fixture derivative';
        $path = $directory . '/' . $id . '-960-webp.webp';
        file_put_contents($path, $bytes);
        $derivative = new MediaDerivative($id, '960-webp', 'derivatives/' . $id . '/' . basename($path), basename($path), 'image/webp', 20, 10, strlen($bytes), hash('sha256', $bytes), 'imagick', 'test', 2, []);
        $storage = new FilesystemMediaStore($fixture['root']);
        assertSameValue($bytes, $storage->readThumbnail($derivative), 'verified bytes are returned');
        file_put_contents($path, 'changed');
        try {
            $storage->readThumbnail($derivative);
            throw new RuntimeException('changed derivative was served');
        } catch (\Utsuri\PortableCms\Conventional\Media\MediaProcessingFailure) {
        }
        unlink($path);
        symlink(dirname(__DIR__) . '/php/assertions.php', $path);
        try {
            $storage->readThumbnail($derivative);
            throw new RuntimeException('symbolic link was served');
        } catch (\Utsuri\PortableCms\Conventional\Media\MediaProcessingFailure) {
        }
        unlink($path);
    } finally {
        removeUploadTestTree($fixture['root']);
    }
};

$tests['nested taxonomy controls validate stable projections and required selections'] = static function (): void {
    $path = sys_get_temp_dir() . '/portable-editorial-profile-' . bin2hex(random_bytes(8)) . '.json';
    $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json'), flags: JSON_THROW_ON_ERROR);
    foreach ($document->recordTypes[0]->fields as $field) {
        if ($field->id === 'credits') {
            $field->settings->fields[] = ['id' => 'topics', 'label' => 'Topics', 'type' => 'taxonomy_references', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['targetType' => 'discipline', 'maximumItems' => 2]];
        }
        if ($field->id === 'gallery') {
            $field->requiredOnDraft = true;
        }
    }
    file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
    $fixture = editorialMediaFixture();
    try {
        try {
            $profile = ProfileManifest::load($path);
        } catch (\Utsuri\PortableCms\Conventional\SchemaValidationFailure $failure) {
            throw new RuntimeException(json_encode($failure->issues, JSON_THROW_ON_ERROR), previous: $failure);
        }
        $state = contentTestApplication(profileOverride: $profile, library: $fixture['library']);
        $cookies = authenticatedTestCookies($state['app']);
        $type = $profile->type('project');
        $item = str_repeat('a', 32);
        $control = EditorialControls::childName('credits_ca_i' . $item, 'topics');
        $post = ['_editorial_end' => '1', '_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_present_credits' => '1', 'credits_ca_items' => [$item], '_editor_action' => 'term:' . $control, '_term_' . $control . '__ca' => 'Fusta', '_term_' . $control . '__es' => 'Madera', '_term_' . $control . '__en' => 'Wood'];
        $response = $state['app']->handle(new Request('POST', '/records/project', contentMutationHeaders(), $post, $cookies));
        assertSameValue(200, $response->status, 'nested inline term uses the authorized native route');
        $term = $state['contentRepository']->list('discipline', 100)[0];
        assertTrueValue(str_contains($response->body, 'name="' . $control . '[]" value="' . $term->id . '"'), 'nested term remains selected');
        $values = ['title' => ['ca' => 'T', 'es' => 'T', 'en' => 'T'], 'gallery' => '[]', 'credits' => json_encode(['ca' => [['_itemId' => $item, 'topics' => [$term->id]]], 'es' => [], 'en' => []], JSON_THROW_ON_ERROR)];
        $service = new ContentService($state['contentRepository'], $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-03T12:00:00Z')), new IdentityTestRandom(), $fixture['library']);
        $empty = $service->create('project', $values, str_repeat('1', 32));
        assertTrueValue($empty instanceof ValidationFailure && isset($empty->errors['gallery']), 'an empty required media list is invalid');
        $values['gallery'] = json_encode([array_key_first($fixture['repository']->items)], JSON_THROW_ON_ERROR);
        $record = $service->create('project', $values, str_repeat('1', 32));
        assertTrueValue($record instanceof RecordSnapshot, 'nested typed reference saves');
        assertSameValue($control, $state['contentRepository']->relationships[$record->id][0]->fieldId, 'nested projection uses stable group identity');
        assertSameValue('discipline', EditorialControls::nestedDefinition($type, $profile, $record->body, $control)?->targetType, 'compiler can recover the typed nested target');
    } finally {
        unlink($path);
        removeUploadTestTree($fixture['root']);
    }
};
