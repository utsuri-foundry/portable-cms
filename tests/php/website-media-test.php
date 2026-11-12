<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Http\OriginPolicy;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\RequestBody;
use Utsuri\PortableCms\Conventional\Http\UploadedFile;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\IdentityApplication;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\Media\AdministrationLogoDelivery;
use Utsuri\PortableCms\Conventional\Media\FilesystemUploadStorage;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaUploadService;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublicationService;

/** @return array<string, mixed> */
function websiteMediaFixture(): array
{
    $f = editedFieldFixture();
    $random = new IdentityTestRandom();
    $identity = new MemoryIdentityRepository();
    $identity->addUser(str_repeat('a', 32), 'admin@example.test', 'Administrator', password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 4]));
    $settings = new MemoryWebsitePublication(WebsitePublication::defaults($f['profile']->id, $f['profile']->locales));
    $uploads = new MediaUploadService($f['uploadRepository'], new FilesystemUploadStorage($f['mediaRoot']), $f['clock'], $random, profile: $f['profile']);
    $app = new IdentityApplication(
        new IdentityService($identity, $f['clock'], $random, str_repeat('t', 32)),
        new OriginPolicy('https://cms.example.test'),
        $f['clock'],
        $random,
        content: $f['service'],
        profile: $f['profile'],
        mediaUploads: $uploads,
        mediaLibrary: $f['media']['library'],
        websitePublication: new WebsitePublicationService($settings, $f['profile'], $f['clock'], $random),
    );
    return [...$f, 'app' => $app, 'identity' => $identity, 'settings' => $settings, 'uploads' => $uploads, 'cookies' => authenticatedTestCookies($app)];
}

$tests['website library uploads have no record dependency and retain normal access and type limits'] = static function (): void {
    $f = websiteMediaFixture();
    try {
        $f['uploadRepository']->records = [];
        $form = ['_csrf' => $f['cookies'][IdentityService::CSRF_COOKIE], 'owner' => 'website'];
        $page = $f['app']->handle(new Request('GET', '/media', cookies: $f['cookies']));
        assertTrueValue(str_contains($page->body, '<option value="website">'), 'the website is its own library ownership choice');
        $response = $f['app']->handle(new Request('POST', '/media/upload', contentMutationHeaders(), $form, $f['cookies'], files: [editedFieldImage('Website image.png')]));
        assertSameValue(202, $response->status, 'an image uploads without resolving any collection owner');
        $upload = array_values($f['uploadRepository']->sessions)[0];
        assertSameValue([null, null, null], [$upload->ownerRecordId, $upload->initialRecordRevision, $upload->publicValue()['ownerRecordId']], 'website ownership is explicit NULL, never a synthetic record');
        assertSameValue(1, count($f['uploadRepository']->jobs), 'the normal durable processing pipeline is used');
        $badCsrf = $f['app']->handle(new Request('POST', '/media/upload', contentMutationHeaders(), [...$form, '_csrf' => 'wrong'], $f['cookies'], files: [editedFieldImage('Rejected.png')]));
        assertSameValue(403, $badCsrf->status, 'website ownership does not bypass CSRF');
        assertSameValue(403, $f['app']->handle(new Request('POST', '/media/upload', contentMutationHeaders(), $form, files: [editedFieldImage('Private.png')]))->status, 'upload still requires an editorial session');
        foreach (['unknown', 'website/' . str_repeat('a', 32) . '/1'] as $owner) {
            assertSameValue(422, $f['app']->handle(new Request('POST', '/media/upload', contentMutationHeaders(), [...$form, 'owner' => $owner], $f['cookies'], files: [editedFieldImage('Bad owner.png')]))->status, 'an invalid owner cannot silently become website ownership');
        }
        $video = new UploadedFile('film.mp4', 'video/mp4', 8, RequestBody::fromString('notvideo'));
        assertSameValue(422, $f['app']->handle(new Request('POST', '/media/upload', contentMutationHeaders(), $form, $f['cookies'], files: [$video]))->status, 'website ownership admits images only');
        assertSameValue(1, count($f['uploadRepository']->media), 'refused requests leave no media or jobs');
    } finally {
        editedFieldCleanup($f);
    }
};

$tests['Website picker uploads preserve settings input and conflicts without saving or accepting stale bytes'] = static function (): void {
    $f = websiteMediaFixture();
    try {
        $form = ['_csrf' => $f['cookies'][IdentityService::CSRF_COOKIE], '_revision' => '0', '_complete' => '1', '_website_upload' => 'adminLogoMediaId', 'websiteName' => 'Unsaved website name', 'mode' => 'coming_soon', 'iconMediaId' => '', 'adminLogoMediaId' => '', 'darkAdminLogoMediaId' => ''];
        foreach ($f['profile']->locales as $locale) {
            $form['message_' . $locale] = 'Unsaved holding message';
            $form['contact_' . $locale] = 'Unsaved contact';
        }
        $before = $f['settings']->settings->document();
        $response = $f['app']->handle(new Request('POST', '/settings/publication', contentMutationHeaders(), $form, $f['cookies'], files: [editedFieldImage('Logo.png')]));
        assertSameValue(200, $response->status, 'direct native upload returns Website settings');
        assertTrueValue(str_contains($response->body, 'data-website-upload-result') && str_contains($response->body, 'value="Unsaved website name"') && str_contains($response->body, 'Unsaved holding message'), 'upload keeps the other pending settings');
        assertSameValue($before, $f['settings']->settings->document(), 'uploading does not save Website settings');
        assertSameValue(null, array_values($f['uploadRepository']->sessions)[0]->ownerRecordId, 'a Website picker never borrows a collection owner');
        $partial = $f['app']->handle(new Request('POST', '/settings/publication', contentMutationHeaders(), $form, $f['cookies'], files: [editedFieldImage('Complete.png'), editedFieldImage('Interrupted.png', false)]));
        assertSameValue(422, $partial->status, 'partial uploads report their failure');
        assertTrueValue(str_contains($partial->body, 'data-website-upload-result') && str_contains($partial->body, 'value="Unsaved website name"'), 'earlier completed images and the pending form survive');
        $sessions = count($f['uploadRepository']->sessions);
        $stale = $f['app']->handle(new Request('POST', '/settings/publication', contentMutationHeaders(), [...$form, '_revision' => '99'], $f['cookies'], files: [editedFieldImage('Stale.png')]));
        assertSameValue(409, $stale->status, 'settings revision protection also precedes upload intake');
        assertSameValue($sessions, count($f['uploadRepository']->sessions), 'no stale bytes enter storage');
        $f['identity']->users[str_repeat('a', 32)]['role'] = 'editor';
        assertSameValue(403, $f['app']->handle(new Request('POST', '/settings/publication', contentMutationHeaders(), $form, $f['cookies'], files: [editedFieldImage('Forbidden.png')]))->status, 'editors cannot mutate Website settings through uploads');
    } finally {
        editedFieldCleanup($f);
    }
};

$tests['keeping a prepared image for the website is administrator-only metadata CAS and preserves unsaved descriptions'] = static function (): void {
    $f = websiteMediaFixture();
    try {
        $id = array_key_first($f['media']['repository']->items);
        $before = $f['media']['library']->find($id);
        $form = ['_csrf' => $f['cookies'][IdentityService::CSRF_COOKIE], '_revision' => (string) $before->metadataRevision, '_website_owner' => '1', 'name' => 'Unsaved media name', '_media_text' => '1', 'caption__ca' => 'Unsaved caption'];
        $stale = $f['app']->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), [...$form, '_revision' => '99'], $f['cookies']));
        assertSameValue(409, $stale->status, 'stale ownership mutation is refused');
        assertSameValue($before->ownerRecordId, $f['media']['library']->find($id)->ownerRecordId, 'a conflict leaves the owner intact');
        $response = $f['app']->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), $form, $f['cookies']));
        assertSameValue(200, $response->status, 'a separate ownership action returns the pending form');
        $after = $f['media']['library']->find($id);
        assertSameValue([null, $before->metadataRevision + 1, $before->name, $before->text], [$after->ownerRecordId, $after->metadataRevision, $after->name, $after->text], 'only ownership and the metadata revision change');
        assertTrueValue(str_contains($response->body, 'value="Unsaved media name"') && str_contains($response->body, 'Unsaved caption'), 'pending name and descriptions are preserved without committing them');
        $f['identity']->users[str_repeat('a', 32)]['role'] = 'editor';
        assertSameValue(403, $f['app']->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), [...$form, '_revision' => (string) $after->metadataRevision], $f['cookies']))->status, 'ordinary media access does not grant ownership-management permission');
    } finally {
        editedFieldCleanup($f);
    }
};

$tests['website-owned images remain private except for the resolved administration logo derivatives'] = static function (): void {
    $f = administrationLogoFixture();
    try {
        foreach ($f['repository']->items as $id => $media) {
            $f['repository']->items[$id] = new LibraryMedia($id, null, $media->name, $media->status, $media->mime, $media->byteSize, $media->width, $media->height, $media->metadataRevision, $media->errorCode, $media->thumbnail, $media->text, $media->focalPoint);
        }
        $logos = (new AdministrationLogoDelivery($f['library']))->resolve($f['settings']->settings);
        assertTrueValue($logos !== null, 'website-owned selections use the existing bounded logo policy');
        $response = $f['app']->handle(new Request('GET', $logos->light->url()));
        assertSameValue(200, $response->status, 'the chosen prepared derivative is reachable before sign-in');
        assertTrueValue(!isset($response->headers()['set-cookie']), 'anonymous logo delivery creates no session');
        assertTrueValue($f['app']->handle(new Request('GET', '/media/' . $logos->light->mediaId . '/thumbnail'))->status !== 200, 'ordinary website media remains private');
        $unused = $f['library']->find(str_pad('3', 32, '0', STR_PAD_LEFT));
        assertSameValue(404, $f['app']->handle(new Request('GET', '/administration-logo/' . $unused->thumbnail->checksum . '.webp'))->status, 'website ownership does not open other images');
        assertSameValue([], $f['identity']->sessions, 'private media ownership introduces no anonymous sessions');
    } finally {
        removeUploadTestTree($f['root']);
    }
};

$tests['website ownership is explicit in portable and frozen contracts and legacy record meaning stays exact'] = static function (): void {
    $legacy = restoreFixturePlan();
    $website = restoreFixturePlan(static function (array &$document): void {
        $document['media'][0]['ownerRecordId'] = null;
    });
    assertSameValue(null, restoreFixtureExport($website)->document['media'][0]['ownerRecordId'], 'restore planning keeps website ownership');
    assertSameValue(restoreFixtureExport($legacy)->document['media'][1], restoreFixtureExport($website)->document['media'][1], 'legacy media keeps the same record-owned bytes and meaning');
    assertSameValue(count($legacy->files), count($website->files), 'ownership does not change the checksum-addressed byte inventory');
    $root = dirname(__DIR__, 2);
    JsonSchemaValidator::validateExportJson(restoreFixtureExport($website)->json(), (string) file_get_contents($root . '/schemas/portable-cms-export-v1.schema.json'));
    foreach (['missing', 'unknown-record', 'video'] as $invalid) {
        $export = restoreFixtureExport($website)->document;
        if ($invalid === 'missing') {
            unset($export['media'][0]['ownerRecordId']);
        } elseif ($invalid === 'unknown-record') {
            $export['media'][0]['ownerRecordId'] = str_repeat('f', 32);
        } else {
            $export['media'][0]['declaredType'] = 'video/mp4';
        }
        assertInvalidNormalizedExport(static fn() => new \Utsuri\PortableCms\Conventional\Export\NormalizedExport($export), 'Invalid website ownership was accepted: ' . $invalid);
    }
    $package = referenceStudioPackage();
    $document = json_decode((string) file_get_contents($package->root . '/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
    $document['media'][0]['ownerRecordId'] = null;
    JsonSchemaValidator::validateSnapshotJson(json_encode($document, JSON_THROW_ON_ERROR), (string) file_get_contents($root . '/schemas/portable-cms-snapshot-v1.schema.json'));
    assertSameValue(null, FrozenSnapshot::fromDocument($document, $package)->media($document['media'][0]['id'])->ownerRecordId, 'frozen media can be selected without a synthetic record');
    unset($document['media'][0]['ownerRecordId']);
    try {
        FrozenSnapshot::fromDocument($document, $package);
        throw new RuntimeException('Missing ownership was silently reinterpreted');
    } catch (InvalidArgumentException) {
    }
};
