<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FilesystemMediaSource;
use Utsuri\PortableCms\Conventional\Compiler\FrozenMedia;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\ImagickWebsiteIconProcessor;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\SearchSharing;
use Utsuri\PortableCms\Conventional\Content\WebsiteIconSelection;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;

$tests['website description follows override summary default and empty without borrowing another locale'] = static function (): void {
    foreach ([['Override', 'Summary', 'Default', 'Override', 'override'], ['', 'Summary', 'Default', 'Summary', 'content_summary'],
        ['', ' ', 'Default', 'Default', 'website_default'], ['', '', '', '', 'content_summary']] as [$override, $summary, $default, $expected, $source]) {
        $effective = SearchSharing::from(['description' => $override])->resolve('Title', $summary, 'Site', websiteDescription: $default);
        assertSameValue($expected, $effective->description, 'bounded fallback order');
        assertSameValue($source, $effective->sources['description'], 'the editor can identify website defaults');
        assertSameValue($expected, $effective->sharingDescription, 'sharing inherits effective description');
    }
    foreach ([str_repeat('x', 501), "Bad\0text"] as $bad) {
        try {
            SearchSharing::from(null)->resolve('Title', '', 'Site', websiteDescription: $bad);
            throw new RuntimeException('Invalid website default accepted');
        } catch (InvalidArgumentException) {
        }
    }
};

$tests['native website settings save bounded localized defaults and every mapped page using ordinary SEO controls'] = static function (): void {
    $profile = staticCompilerFixture()['package']->manifest;
    $state = contentTestApplication(profileOverride: $profile);
    $cookies = authenticatedTestCookies($state['app']);
    $description = EditorialControls::childName('projectsMetadata_en', 'description');
    $form = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_editorial_end' => '1', 'siteName' => 'A studio',
        'defaultSearchDescription__en' => 'Website default.', '_present_projectsMetadata' => '1', $description => 'Work index override.'];
    $created = $state['app']->handle(new Request('POST', '/records/site-settings', contentMutationHeaders(), $form, $cookies));
    assertSameValue(303, $created->status, 'website defaults and mapped overrides share the singleton save');
    $path = $created->headers()['location'][0];
    $record = $state['contentRepository']->records[basename($path)];
    assertSameValue('Website default.', $record->body['defaultSearchDescription']['en'], 'plain localized default saved');
    assertSameValue('Work index override.', $record->body['projectsMetadata']['en']['description'], 'bounded page mapping persists');
    $page = $state['app']->handle(new Request('GET', $path, cookies: $cookies));
    assertSameValue(7, substr_count($page->body, 'data-search-sharing-editor'), 'all seven recordless pages have the SEO editor');
    assertTrueValue(!str_contains($page->body, '<details class="admin-optional"><summary>'), 'each mapped page uses one collapse layer');
    assertTrueValue(!str_contains($page->body, 'name="' . EditorialControls::childName('settingsMetadata_en', 'excludeFromSearch') . '"'), 'fixed utility indexing policy has no misleading toggle');
    assertTrueValue(str_contains($page->body, 'This utility page is excluded from search by the profile.'), 'the editor explains fixed exclusion');
    assertTrueValue(str_contains($page->body, 'placeholder="Website default."') && str_contains($page->body, 'Automatic: website default.'), 'other pages visibly inherit the submitted website default');
    $invalid = $state['app']->handle(new Request('POST', $path, contentMutationHeaders(), [...$form, '_revision' => '1', 'defaultSearchDescription__en' => str_repeat('x', 501)], $cookies));
    assertSameValue(422, $invalid->status, 'over-long localized default fails before saving');
    assertSameValue(1, $state['contentRepository']->records[basename($path)]->revisionNumber, 'invalid defaults do not create a revision');
};

$tests['website identity is edited only in engine settings and icon eligibility remains strict'] = static function (): void {
    $profile = staticCompilerFixture()['package']->manifest;
    $fields = array_column($profile->type('site-settings')->fields, 'id');
    assertTrueValue(!in_array('siteName', $fields, true) && !in_array('websiteIcon', $fields, true), 'profile discovery defaults do not duplicate engine identity controls');
    $settings = \Utsuri\PortableCms\Conventional\Publication\WebsitePublication::defaults($profile->id, $profile->locales);
    $page = \Utsuri\PortableCms\Conventional\View\WebsitePublicationPage::render($profile, new \Utsuri\PortableCms\Conventional\View\AdminChrome('csrf', 'Administrator'), $settings);
    assertTrueValue(str_contains($page, 'name="websiteName"') && str_contains($page, 'name="iconMediaId"'), 'one native engine form owns the name and icon');
    foreach ([['image/svg+xml', 512, 512, 'ready'], ['image/webp', 511, 511, 'ready'], ['image/jpeg', 800, 600, 'ready'], ['image/png', 512, 512, 'processing']] as [$mime, $width, $height, $status]) {
        assertTrueValue(!WebsiteIconSelection::eligible($status, $mime, $width, $height), 'only ready square raster images remain eligible');
    }
    foreach (['image/png', 'image/webp', 'image/jpeg'] as $mime) {
        assertTrueValue(WebsiteIconSelection::eligible('ready', $mime, 512, 512), 'supported square raster at lower boundary');
    }
};

$tests['frozen index utility and taxonomy pages use editable defaults and mapped search sharing overrides'] = static function (): void {
    $fixture = staticCompilerFixture();
    $document = json_decode((string) file_get_contents($fixture['package']->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($document['records'] as &$record) {
        if ($record['typeId'] === 'site-settings') {
            $record['body']['defaultSearchDescription'] = ['en' => 'English website default.'];
            $record['body']['projectsMetadata'] = ['en' => ['description' => 'Work override.', 'sharingTitle' => 'Shared work', 'excludeFromSearch' => true, 'sharingImage' => ['d0000000000000000000000000000001']]];
            $record['body']['searchMetadata'] = ['en' => ['searchTitle' => 'Find studio work']];
        }
    }
    unset($record);
    $snapshot = FrozenSnapshot::fromDocument($document, $fixture['package']);
    $site = $fixture['builder']->build($snapshot);
    $routes = array_column($site->routes, 'html', 'route');
    foreach (['articles', 'people', 'disciplines', 'sectors', 'search', 'settings', 'disciplines/architecture'] as $page) {
        assertTrueValue(str_contains($routes['/en/' . $page . '/'], 'name="description" content="English website default."'), 'recordless and summaryless pages inherit website description');
    }
    assertTrueValue(str_contains($routes['/en/projects/'], 'content="Work override."') && str_contains($routes['/en/projects/'], 'content="Shared work"'), 'page overrides win');
    assertTrueValue(!str_contains($routes['/es/projects/'], 'English website default.'), 'missing locale never borrows another locale');
    assertTrueValue(str_contains($routes['/en/search/'], '<title>Find studio work</title>'), 'utility title is editable');
    assertTrueValue(!str_contains($site->discovery->sitemap(), '<loc>https://reference-studio.example/en/projects/</loc>'), 'mapped visibility participates in discovery');
    assertSameValue('d0000000000000000000000000000001', $fixture['builder']->sharingMediaIds($snapshot)[0], 'index image override participates in preparation');
};

/** @return array{FrozenMedia, string} */
function websiteIconTestSource(string $root, string $format = 'png'): array
{
    $image = new Imagick();
    try {
        $image->newImage(640, 640, new ImagickPixel('#314151'));
        $image->setImageFormat($format);
        $image->setImageProperty('comment', 'Private editor metadata');
        $data = $image->getImageBlob();
    } finally {
        $image->clear();
    }
    $source = new FrozenMedia('d0000000000000000000000000000003', '10000000000000000000000000000002', 'display_icon', 'image/' . $format, strlen($data), hash('sha256', $data), 640, 640);
    $path = $root . '/' . FilesystemMediaSource::relativePath($source);
    file_put_contents($path, $data);
    return [$source, $path];
}

$tests['icon processor verifies signatures geometry source and stripped deterministic PNG output'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $processor = new ImagickWebsiteIconProcessor();
        $limits = [Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY), Imagick::getResourceLimit(Imagick::RESOURCETYPE_TIME)];
        foreach (['png', 'webp', 'jpeg'] as $format) {
            [$source, $path] = websiteIconTestSource($root, $format);
            $output = $processor->process($source, $path);
            assertSameValue($output->bytes, $processor->process($source, $path)->bytes, 'same frozen bytes and recipe are deterministic');
            foreach ($output->icon->files as $file) {
                $image = new Imagick();
                try {
                    $image->readImageBlob($output->bytes[$file['size']]);
                    assertSameValue([$file['size'], $file['size']], [$image->getImageWidth(), $image->getImageHeight()], 'browser and device icon sizes');
                    assertSameValue([], $image->getImageProfiles('*', false), 'private profiles are stripped');
                    assertSameValue(false, $image->getImageProperty('comment'), 'private comment is stripped');
                    assertSameValue(hash('sha256', $output->bytes[$file['size']]), $file['sha256'], 'public identity attests actual bytes');
                } finally {
                    $image->clear();
                }
            }
        }
        assertSameValue($limits, [Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY), Imagick::getResourceLimit(Imagick::RESOURCETYPE_TIME)], 'resource limits restored');
        $hostile = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="640"><script>alert(1)</script></svg>';
        file_put_contents($root . '/hostile.png', $hostile);
        $forged = new FrozenMedia($source->id, $source->ownerRecordId, 'display_icon', 'image/png', strlen($hostile), hash('sha256', $hostile), 640, 640);
        foreach ([$forged, new FrozenMedia($source->id, $source->ownerRecordId, 'display_icon', $source->mime, $source->byteSize, str_repeat('f', 64), 640, 640),
            new FrozenMedia($source->id, $source->ownerRecordId, 'display_icon', $source->mime, $source->byteSize, $source->checksum, 512, 512)] as $bad) {
            try {
                $processor->process($bad, $bad === $forged ? $root . '/hostile.png' : $path);
                throw new RuntimeException('Forged icon accepted');
            } catch (InvalidArgumentException) {
            }
        }
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['compiler publishes verified selected icon sizes on every route and fails closed without a processor'] = static function (): void {
    $fixture = staticCompilerFixture();
    $document = json_decode((string) file_get_contents($fixture['package']->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $root = staticCompilerTempRoot();
    try {
        [$source] = websiteIconTestSource($root);
        foreach ($fixture['snapshot']->media as $media) {
            if ($media->id !== $source->id) {
                foreach ($media->renditions() as $rendition) {
                    copy($fixture['media']->file($rendition), $root . '/' . FilesystemMediaSource::relativePath($rendition));
                }
            }
        }
        foreach ($document['media'] as &$media) {
            if ($media['id'] === $source->id) {
                $media = ['id' => $source->id, 'ownerRecordId' => $source->ownerRecordId, 'variant' => $source->variant, 'mime' => $source->mime,
                    'byteSize' => $source->byteSize, 'sha256' => $source->checksum, 'width' => $source->width, 'height' => $source->height,
                    'text' => ['formatVersion' => 1, 'decorative' => true, 'translations' => []]];
            }
        }
        unset($media);
        $document['websiteIdentity']['iconMediaId'] = $source->id;
        $fixture['snapshot'] = FrozenSnapshot::fromDocument($document, $fixture['package']);
        $fixture['media'] = new FilesystemMediaSource($root);
        $built = staticCompilerBuild($fixture, $root . '/artifact');
        assertSameValue([32, 180, 512], array_column($built->manifest['websiteIcon']['files'], 'size'), 'manifest attests complete icon set');
        foreach ($built->manifest['websiteIcon']['files'] as $file) {
            assertSameValue($file['sha256'], hash_file('sha256', $built->outputRoot . '/' . $file['path']), 'published bytes are verified assets');
        }
        foreach ($built->files as $file) {
            if (str_starts_with($file['mime'], 'text/html')) {
                $html = (string) file_get_contents($built->outputRoot . '/' . $file['path']);
                assertSameValue(3, substr_count($html, 'type="image/png"'), 'all public routes link every icon size');
                assertTrueValue(!str_contains($html, 'assets/icon.svg'), 'selected icon replaces packaged fallback');
            }
        }
        $fixture['compiler'] = new \Utsuri\PortableCms\Conventional\Compiler\StaticSiteCompiler(dirname(__DIR__, 2) . '/schemas/portable-cms-artifact-v1.schema.json', dirname(__DIR__, 2) . '/schemas/portable-cms-search-v1.schema.json', new \Utsuri\PortableCms\Conventional\Compiler\ImagickSharingImageProcessor());
        try {
            staticCompilerBuild($fixture, $root . '/failed');
            throw new RuntimeException('Selected icon silently fell back without a processor');
        } catch (InvalidArgumentException) {
        }
        assertTrueValue(!is_dir($root . '/failed') && !is_dir($root . '/failed.working-' . $fixture['request']->releaseId), 'failed compilation leaves no incomplete artifact');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['profile website metadata mappings reject ambiguous paths missing sources and unrestricted icon selections'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json'), false, flags: JSON_THROW_ON_ERROR);
        $settingsIndex = array_search('site-settings', array_column($document->singletons, 'id'), true);
        $indices = array_flip(array_column($document->singletons[$settingsIndex]->fields, 'id'));
        foreach (['duplicate-path', 'unknown-default', 'path-traversal', 'icon-list'] as $case) {
            $bad = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);
            $fields = &$bad->singletons[$settingsIndex]->fields;
            if ($case === 'duplicate-path') {
                $fields[$indices['searchMetadata']]->mapping->pagePath = '/projects/';
            } elseif ($case === 'unknown-default') {
                $fields[$indices['projectsMetadata']]->mapping->defaultDescriptionField = 'missingDescription';
            } elseif ($case === 'path-traversal') {
                $fields[$indices['projectsMetadata']]->mapping->pagePath = '/../';
            } else {
                $fields[] = ['id' => 'legacyIcon', 'label' => 'Legacy icon', 'type' => 'media_references', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['maximumItems' => 2, 'kinds' => ['image'], 'purpose' => 'website-icon']];
            }
            unset($fields);
            file_put_contents($root . '/profile.json', json_encode($bad, JSON_THROW_ON_ERROR));
            try {
                \Utsuri\PortableCms\Conventional\Content\ProfileManifest::load($root . '/profile.json');
                throw new RuntimeException('Unbounded website metadata profile mapping accepted');
            } catch (InvalidArgumentException|\Utsuri\PortableCms\Conventional\SchemaValidationFailure $error) {
                if ($case === 'duplicate-path') {
                    assertTrueValue(str_contains($error->getMessage(), 'uniquely'), 'the duplicate expanded path is the rejected fact');
                } elseif ($case === 'unknown-default') {
                    assertTrueValue(str_contains($error->getMessage(), 'description mapping'), 'the expanded source must actually be checked');
                }
            }
        }
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['malformed icon processor output fails before publication without leaking decoder warnings'] = static function (): void {
    $data = "\x89PNG\r\n\x1a\ninvalid image";
    $files = [];
    $bytes = [];
    foreach ([32, 180, 512] as $size) {
        $sha256 = hash('sha256', $data);
        $files[] = ['size' => $size, 'path' => 'icons/' . $sha256 . '.png', 'sha256' => $sha256, 'byteSize' => strlen($data)];
        $bytes[$size] = $data;
    }
    $warnings = 0;
    set_error_handler(static function () use (&$warnings): bool {
        $warnings++;
        return true;
    }, E_WARNING);
    try {
        try {
            new \Utsuri\PortableCms\Conventional\Compiler\WebsiteIconOutput(new \Utsuri\PortableCms\Conventional\Compiler\WebsiteIcon(str_repeat('a', 32), str_repeat('b', 64), 'fixture', $files), $bytes);
            throw new RuntimeException('Malformed PNG accepted');
        } catch (InvalidArgumentException) {
        }
        assertSameValue(0, $warnings, 'native decoder warnings stay inside the checked host boundary');
    } finally {
        restore_error_handler();
    }
};
