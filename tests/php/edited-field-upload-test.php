<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\RequestBody;
use Utsuri\PortableCms\Conventional\Http\UploadedFile;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;

/** A fictional two-image field and a second field prove target isolation and the actual bound. */
function editedFieldProfile(): ProfileManifest
{
    $base = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $document = json_decode($base->definitionJson ?? throw new RuntimeException('expanded profile missing'), flags: JSON_THROW_ON_ERROR);
    foreach ($document->recordTypes as $type) {
        if ($type->id !== 'project') {
            continue;
        }
        foreach ($type->fields as $field) {
            if ($field->id === 'gallery') {
                $field->settings->maximumItems = 2;
                $other = clone $field;
                $other->id = 'otherImages';
                $other->label = 'Other images';
                $type->fields[] = $other;
                $child = clone $field;
                $child->id = 'images';
                $type->fields[] = (object) ['id' => 'imageGroup', 'label' => 'Image group', 'type' => 'group', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => (object) ['fields' => [$child]]];
                break;
            }
        }
    }
    $path = sys_get_temp_dir() . '/portable-field-profile-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
    try {
        return ProfileManifest::load($path);
    } finally {
        unlink($path);
    }
}

/** @return array<string, mixed> */
function editedFieldFixture(): array
{
    $media = editorialMediaFixture();
    $profile = editedFieldProfile();
    $state = contentTestApplication(withMediaUploads: true, profileOverride: $profile, library: $media['library']);
    $cookies = authenticatedTestCookies($state['app']);
    $service = new ContentService($state['contentRepository'], $profile, $state['clock'], new IdentityTestRandom(), $media['library']);
    $record = $service->create('project', ['title' => ['ca' => 'Saved', 'es' => 'Saved', 'en' => 'Saved'], 'gallery' => '[]'], str_repeat('1', 32));
    assertTrueValue($record instanceof RecordSnapshot, 'the upload owner exists');
    $state['uploadRepository']->records[$record->id] = ['typeId' => 'project', 'revisionNumber' => 1];
    $form = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '1', '_editorial_end' => '1', '_present_gallery' => '1', '_present_otherImages' => '1',
        'title__ca' => 'Unsaved title', 'title__es' => 'Saved', 'title__en' => 'Saved'];
    return [...$state, 'media' => $media, 'profile' => $profile, 'service' => $service, 'record' => $record, 'cookies' => $cookies, 'form' => $form, 'path' => '/records/project/' . $record->id];
}

/** @param array<string, mixed> $fixture */
function editedFieldCleanup(array $fixture): void
{
    removeUploadTestTree($fixture['media']['root']);
    removeUploadTestTree($fixture['mediaRoot']);
}

function editedFieldImage(string $name, bool $valid = true): UploadedFile
{
    $bytes = $valid ? (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6nM8AAAAASUVORK5CYII=') : 'incomplete image';
    return new UploadedFile($name, 'image/png', strlen($bytes), RequestBody::fromString($bytes));
}

/** @return list<string> */
function editedFieldSelections(string $html, string $field = 'gallery'): array
{
    preg_match_all('/name="' . preg_quote($field, '/') . '\[\]" value="([a-f0-9]{32})"/', $html, $matches);
    return $matches[1];
}

$tests['field native uploads append in order within the maximum and preserve the entire unsaved form'] = static function (): void {
    $f = editedFieldFixture();
    try {
        $existing = array_key_first($f['media']['repository']->items);
        $form = [...$f['form'], 'gallery' => [$existing], '_pick_otherImages' => [$existing], '_upload_field' => 'gallery'];
        $response = $f['app']->handle(new Request('POST', $f['path'] . '/upload', contentMutationHeaders(), $form, $f['cookies'], files: [editedFieldImage('first.png'), editedFieldImage('second.png'), editedFieldImage('third.png')]));
        assertSameValue(200, $response->status, 'native completion returns the editing form');
        $uploaded = array_column(array_values($f['uploadRepository']->media), 'id');
        assertSameValue(3, count($uploaded), 'images beyond the maximum still enter the library');
        assertSameValue([$existing, $uploaded[0]], editedFieldSelections($response->body), 'new images follow the existing selection in upload order');
        assertSameValue([], editedFieldSelections($response->body, 'otherImages'), 'the other field stays untouched');
        assertTrueValue(str_contains($response->body, 'name="_pick_otherImages[]" value="' . $existing . '" checked'), 'unsubmitted choices in another picker remain checked');
        assertTrueValue(str_contains($response->body, 'Kept only in the library: 2.') && str_contains($response->body, 'data-media-upload-result'), 'the editor sees the overflow count');
        assertTrueValue(str_contains($response->body, 'value="Unsaved title"') && str_contains($response->body, 'data-pending-input="true"'), 'unsaved non-media text survives');
        assertSameValue(['Saved', 1], [$f['contentRepository']->find('project', $f['record']->id)->body['title']['ca'], $f['contentRepository']->find('project', $f['record']->id)->revisionNumber], 'uploading never saves or changes a content revision');
        assertTrueValue(str_contains($response->body, 'name="images_otherImages[]"') && str_contains($response->body, 'value="otherImages" formaction='), 'each field has its own native upload intent including camel-case field IDs');
        foreach ($uploaded as $id) {
            $f['media']['repository']->items[$id] = new \Utsuri\PortableCms\Conventional\Media\LibraryMedia($id, $f['record']->id, 'Uploaded image', 'processing', 'image/png', 68, null, null, 1, null, null);
        }
        $receipt = $f['app']->handle(new Request('POST', $f['path'], contentMutationHeaders(), [...$form, '_upload_field' => '', '_editor_action' => 'uploaded-media:gallery', '_completed_uploads' => array_keys($f['uploadRepository']->sessions)], $f['cookies']));
        assertSameValue(200, $receipt->status, 'completed upload receipts return description controls with the pending selection');
        assertSameValue(3, substr_count($receipt->body, ' data-description-job='), 'all completed uploads remain describable, including overflow');
        assertTrueValue(str_contains($receipt->body, 'name="description_' . $uploaded[2] . '__alternativeText__ca"') && str_contains($receipt->body, 'form="media-descriptions-form-gallery"'), 'overflow descriptions belong to a separate field-specific form');
    } finally {
        editedFieldCleanup($f);
    }
};

$tests['field upload interruption keeps completed images and native stale requests accept no bytes'] = static function (): void {
    $f = editedFieldFixture();
    try {
        $form = [...$f['form'], '_upload_field' => 'otherImages'];
        $empty = $f['app']->handle(new Request('POST', $f['path'] . '/upload', contentMutationHeaders(), $form, $f['cookies']));
        assertSameValue(422, $empty->status, 'empty multipart batches return a field error');
        assertTrueValue(str_contains($empty->body, 'value="Unsaved title"'), 'empty uploads preserve the form');
        $partial = $f['app']->handle(new Request('POST', $f['path'] . '/upload', contentMutationHeaders(), $form, $f['cookies'], files: [editedFieldImage('complete.png'), editedFieldImage('broken.png', false), editedFieldImage('later.png')]));
        assertSameValue(422, $partial->status, 'a partial batch reports failure in the form');
        $ids = array_column(array_values($f['uploadRepository']->media), 'id');
        assertSameValue(1, count($ids), 'only the completed image became library content');
        assertSameValue($ids, editedFieldSelections($partial->body, 'otherImages'), 'completed images stay in the targeted second field');
        assertSameValue([], editedFieldSelections($partial->body), 'partial uploads do not target the first gallery by accident');
        assertTrueValue(str_contains($partial->body, 'value="Unsaved title"'), 'partial failure keeps unsaved text');
        $f['service']->update('project', $f['record']->id, 1, ['title' => ['ca' => 'Other editor', 'es' => 'Saved', 'en' => 'Saved'], 'gallery' => '[]'], str_repeat('1', 32));
        $stale = $f['app']->handle(new Request('POST', $f['path'] . '/upload', contentMutationHeaders(), $form, $f['cookies'], files: [editedFieldImage('stale.png')]));
        assertSameValue(409, $stale->status, 'a stale upload shows the ordinary revision conflict');
        assertSameValue(1, count($f['uploadRepository']->media), 'stale uploads do not accept bytes');
        assertTrueValue(str_contains($stale->body, 'value="Unsaved title"') && str_contains($stale->body, 'name="_editor_base_revision" value="1"'), 'the conflict preserves input and original authority');
        $rejected = $f['app']->handle(new Request('POST', $f['path'] . '/upload', contentMutationHeaders(), [...$form, '_csrf' => 'bad'], $f['cookies'], files: [editedFieldImage('forbidden.png')]));
        assertSameValue(403, $rejected->status, 'the new native path retains CSRF protection');
    } finally {
        editedFieldCleanup($f);
    }
};

$tests['media picker bulk selection is one bounded transient action with conflicts and independent removal'] = static function (): void {
    $f = editedFieldFixture();
    try {
        [$one, $two, $three] = array_keys($f['media']['repository']->items);
        $response = $f['app']->handle(new Request('POST', $f['path'], contentMutationHeaders(), [...$f['form'], 'gallery' => [$one], '_pick_gallery' => [$two, $three, $two], '_editor_action' => 'add-media:gallery'], $f['cookies']));
        assertSameValue(200, $response->status, 'one action adds checked library images');
        assertSameValue([$one, $two], editedFieldSelections($response->body), 'bulk selection retains order, deduplicates and enforces the field maximum');
        $nestedId = EditorialControls::childName('imageGroup', 'images');
        $nested = $f['app']->handle(new Request('POST', $f['path'], contentMutationHeaders(), [...$f['form'], '_present_imageGroup' => '1', $nestedId => [$one], '_pick_' . $nestedId => [$two, $three], '_editor_action' => 'add-media:' . $nestedId], $f['cookies']));
        assertSameValue(200, $nested->status, 'nested media pickers use the same batch action');
        assertSameValue([$one, $two], editedFieldSelections($nested->body, $nestedId), 'nested selection preserves existing values and enforces its own maximum');
        assertTrueValue(str_contains($response->body, 'Kept only in the library: 1.'), 'excess library selections are explained');
        assertTrueValue(str_contains($response->body, 'name="_pick_gallery[]"') && str_contains($response->body, 'value="add-media:gallery"'), 'the picker exposes native checkboxes and one submit button');
        $removed = $f['app']->handle(new Request('POST', $f['path'], contentMutationHeaders(), [...$f['form'], 'gallery' => [$one, $two], '_editor_action' => 'remove:gallery:' . $two], $f['cookies']));
        assertSameValue([$one], editedFieldSelections($removed->body), 'removal only changes the pending field');
        assertTrueValue($f['media']['library']->find($two) !== null && $f['media']['repository']->writes === 0, 'removal never deletes library media');
        $f['service']->update('project', $f['record']->id, 1, ['title' => ['ca' => 'Other editor', 'es' => 'Saved', 'en' => 'Saved'], 'gallery' => '[]'], str_repeat('1', 32));
        $conflict = $f['app']->handle(new Request('POST', $f['path'], contentMutationHeaders(), [...$f['form'], '_editor_action' => 'add-media:gallery', '_pick_gallery' => [$two, $three]], $f['cookies']));
        assertSameValue(409, $conflict->status, 'bulk picking keeps revision protection');
        assertSameValue([$two, $three], editedFieldSelections($conflict->body), 'the conflict keeps the pending selection');
        assertTrueValue(str_contains($conflict->body, 'value="Unsaved title"') && str_contains($conflict->body, 'name="_editor_base_revision" value="1"'), 'the conflict preserves all input and its old base');
        $invalid = $f['app']->handle(new Request('POST', $f['path'], contentMutationHeaders(), [...$f['form'], '_editor_action' => 'add-media:gallery', '_pick_gallery' => [str_repeat('f', 32)]], $f['cookies']));
        assertSameValue(409, $invalid->status, 'an invalid selection never dismisses an existing conflict');
        assertSameValue([], editedFieldSelections($invalid->body), 'unknown media are not added');
    } finally {
        editedFieldCleanup($f);
    }
};

$tests['completed resumable uploads attach once after interruption and reject foreign or unfinished sessions'] = static function (): void {
    $f = editedFieldFixture();
    try {
        $file = editedFieldImage('resume.png');
        $stream = $file->open();
        $bytes = stream_get_contents($stream);
        fclose($stream);
        $headers = [...contentMutationHeaders(), 'x-csrf-token' => $f['cookies'][IdentityService::CSRF_COOKIE]];
        $intent = ['formatVersion' => 1, 'expectedRevision' => 1, 'filename' => 'resume.png', 'declaredType' => 'image/png', 'byteSize' => strlen($bytes), 'clientUploadId' => 'edited-field-resume'];
        $create = static fn(): array => json_decode($f['app']->handle(new Request('POST', '/api' . $f['path'] . '/media-uploads', [...$headers, 'content-type' => 'application/json'], cookies: $f['cookies'], body: RequestBody::fromString(json_encode($intent, JSON_THROW_ON_ERROR))))->body, true, flags: JSON_THROW_ON_ERROR);
        $created = $create();
        $session = $created['upload']['id'];
        $part = $f['app']->handle(new Request('PUT', '/api/media-uploads/' . $session . '/parts/1', [...$headers, 'content-length' => (string) strlen($bytes)], cookies: $f['cookies'], body: RequestBody::fromString($bytes)));
        assertSameValue(200, $part->status, 'the image part is durable before completion');
        assertSameValue($session, $create()['upload']['id'], 'an interrupted upload resumes the same session');
        $post = [...$f['form'], '_editor_action' => 'uploaded-media:gallery', '_completed_uploads' => [$session]];
        $unfinished = $f['app']->handle(new Request('POST', $f['path'], contentMutationHeaders(), $post, $f['cookies']));
        assertSameValue(400, $unfinished->status, 'an unfinished session cannot be selected');
        assertSameValue([], $f['uploadRepository']->media, 'an incomplete upload creates no library image');
        $complete = static fn(): array => json_decode($f['app']->handle(new Request('POST', '/api/media-uploads/' . $session . '/complete', $headers, cookies: $f['cookies']))->body, true, flags: JSON_THROW_ON_ERROR);
        $result = $complete();
        assertSameValue($result['media']['id'], $complete()['media']['id'], 'completion replay names the same image');
        $attached = $f['app']->handle(new Request('POST', $f['path'], contentMutationHeaders(), $post, $f['cookies']));
        assertSameValue(200, $attached->status, 'completed image joins the pending field');
        assertSameValue([$result['media']['id']], editedFieldSelections($attached->body), 'the completed image is selected');
        $replay = $f['app']->handle(new Request('POST', $f['path'], contentMutationHeaders(), [...$post, 'gallery' => [$result['media']['id']]], $f['cookies']));
        assertSameValue([$result['media']['id']], editedFieldSelections($replay->body), 'replaying a completed batch never duplicates its selection');
        assertSameValue(1, count($f['uploadRepository']->media), 'resuming and attaching never create a second library image');
        $partial = $f['app']->handle(new Request('POST', $f['path'], contentMutationHeaders(), [...$post, '_upload_failed' => '1'], $f['cookies']));
        assertSameValue(422, $partial->status, 'enhanced partial failures return a visible field error');
        assertSameValue([$result['media']['id']], editedFieldSelections($partial->body), 'completed images remain selected after enhanced failure');
        $f['service']->update('project', $f['record']->id, 1, ['title' => ['ca' => 'Other editor', 'es' => 'Saved', 'en' => 'Saved'], 'gallery' => '[]'], str_repeat('1', 32));
        $stale = $f['app']->handle(new Request('POST', $f['path'], contentMutationHeaders(), $post, $f['cookies']));
        assertSameValue(409, $stale->status, 'completed receipts never grant permission to overwrite a newer revision');
        assertSameValue([$result['media']['id']], editedFieldSelections($stale->body), 'the conflict keeps completed images and the old unsaved form');
        assertTrueValue(str_contains($stale->body, 'name="_editor_base_revision" value="1"'), 'the original revision remains the conflict authority');
        $foreign = $f['uploadRepository']->sessions[$session];
        $f['uploadRepository']->sessions[$session] = new \Utsuri\PortableCms\Conventional\Media\UploadSession($foreign->id, $foreign->clientUploadId, str_repeat('f', 32), $foreign->actorUserId, $foreign->status, $foreign->objectKey, $foreign->originalName, $foreign->declaredType, $foreign->byteSize, $foreign->partSize, $foreign->partCount, $foreign->initialRecordRevision, $foreign->mediaId, $foreign->jobId, $foreign->expiresAt, $foreign->parts);
        $wrongOwner = $f['app']->handle(new Request('POST', $f['path'], contentMutationHeaders(), $post, $f['cookies']));
        assertSameValue(400, $wrongOwner->status, 'a completed session for another record cannot be attached');
        assertSameValue(2, $f['contentRepository']->find('project', $f['record']->id)->revisionNumber, 'selection operations never change the other editor’s revision');
    } finally {
        editedFieldCleanup($f);
    }
};

$tests['multipart transfer errors preserve field input without admitting untrusted files or wider native limits'] = static function (): void {
    $f = editedFieldFixture();
    try {
        $form = [...$f['form'], '_upload_field' => 'otherImages'];
        $original = [$_SERVER, $_POST, $_FILES, $_COOKIE, $_GET];
        try {
            $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $f['path'] . '/upload'];
            $_POST = $form;
            $_FILES = ['images_otherImages' => ['name' => ['partial.png'], 'type' => ['image/png'], 'size' => [16], 'tmp_name' => ['/not-an-upload'], 'error' => [UPLOAD_ERR_PARTIAL]]];
            $_COOKIE = $_GET = [];
            $parsed = Request::fromGlobals();
            assertSameValue([UPLOAD_ERR_PARTIAL], array_map(static fn(UploadedFile $file): int => $file->error, $parsed->files), 'the chosen camel-case field keeps its expected PHP transfer error');
            assertSameValue('Unsaved title', $parsed->formValue('title__ca'), 'the form survives transport-error parsing');
            $partial = $f['app']->handle(new Request('POST', $f['path'] . '/upload', contentMutationHeaders(), $form, $f['cookies'], files: [editedFieldImage('complete-before-interruption.png'), ...$parsed->files]));
            assertSameValue(422, $partial->status, 'PHP partial-transfer failures return a normal editing response');
            assertSameValue(array_column(array_values($f['uploadRepository']->media), 'id'), editedFieldSelections($partial->body, 'otherImages'), 'an earlier completed file is retained before the PHP failure');
            assertTrueValue(str_contains($partial->body, 'value="Unsaved title"'), 'transport failure preserves unrelated input');
            $_FILES['images_otherImages']['error'] = [UPLOAD_ERR_OK];
            try {
                Request::fromGlobals();
                throw new RuntimeException('A non-uploaded local path was admitted');
            } catch (\Utsuri\PortableCms\Conventional\Http\InvalidRequestBody) {
            }
        } finally {
            [$_SERVER, $_POST, $_FILES, $_COOKIE, $_GET] = $original;
        }
        $oversized = new UploadedFile('too-large.png', 'image/png', \Utsuri\PortableCms\Conventional\Media\MediaContract::MAX_BYTES + 1, RequestBody::fromString('not read'));
        $large = $f['app']->handle(new Request('POST', $f['path'] . '/upload', contentMutationHeaders(), $form, $f['cookies'], files: [$oversized]));
        assertSameValue(422, $large->status, 'native size refusal stays in the editing form');
        assertTrueValue(str_contains($large->body, '40 MB limit') && str_contains($large->body, 'value="Unsaved title"'), 'the existing native ceiling and submitted text are preserved');
        assertSameValue(1, count($f['uploadRepository']->media), 'no bytes are accepted after transport or size refusal');
    } finally {
        editedFieldCleanup($f);
    }
};
