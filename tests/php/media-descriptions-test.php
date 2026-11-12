<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\View\MediaDescriptionsForm;

$tests['batch image descriptions are authenticated bounded native forms with independent metadata conflicts'] = static function (): void {
    $fixture = editorialMediaFixture();
    try {
        $profile = ProfileManifest::gateZero();
        $app = contentTestApplication(library: $fixture['library'])['app'];
        $cookies = authenticatedTestCookies($app);
        $ids = array_slice(array_keys($fixture['repository']->items), 0, 2);
        $get = static fn(array $selected, array $cookies): Request => new Request('GET', '/media/descriptions', cookies: $cookies, query: ['ids' => implode(',', $selected)]);
        assertSameValue(303, $app->handle($get($ids, []))->status, 'descriptions are private');
        $page = $app->handle($get($ids, $cookies));
        assertSameValue(200, $page->status, 'two images on one native page');
        assertSameValue(2, substr_count($page->body, 'data-media-description='), 'both selected images rendered');
        foreach ($ids as $id) {
            foreach ($profile->locales as $locale) {
                assertTrueValue(str_contains($page->body, 'name="description_' . $id . '__alternativeText__' . $locale . '"'), 'every site language has distinct controls');
            }
        }
        $post = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'media_ids' => $ids, '_descriptions_end' => '1'];
        foreach ($ids as $index => $id) {
            $post['description_' . $id . '__revision'] = '1';
            $post['description_' . $id . '__alternativeText__en'] = 'Authored image ' . $index;
            $post['description_' . $id . '__caption__en'] = 'Caption ' . $index;
            $post['description_' . $id . '__credit__en'] = 'Credit ' . $index;
        }
        $mutate = static fn(array $data): Request => new Request('POST', '/media/descriptions', contentMutationHeaders(), $data, $cookies);
        assertSameValue(403, $app->handle($mutate([...$post, '_csrf' => 'bad']))->status, 'same CSRF boundary as library details');
        assertSameValue(200, $app->handle($mutate($post))->status, 'one submit describes both images');
        assertSameValue(2, $fixture['repository']->writes, 'ordinary metadata service used once per image');
        assertSameValue('Caption 1', $fixture['library']->find($ids[1])->text->localized('en', 'en')['caption'], 'second image saved');
        $fixture['library']->rename($ids[0], 2, 'Concurrent library name', str_repeat('1', 32));
        foreach ($ids as $id) {
            $post['description_' . $id . '__revision'] = '2';
        }
        $post['description_' . $ids[0] . '__caption__en'] = 'My unsaved caption';
        $conflict = $app->handle($mutate($post));
        assertSameValue(409, $conflict->status, 'stale image conflicts while the independent image saves');
        assertTrueValue(str_contains($conflict->body, 'My unsaved caption'), 'conflict retains submitted text');
        assertSameValue('Concurrent library name', $fixture['library']->find($ids[0])->name, 'concurrent library name retained');
        assertSameValue('Caption 0', $fixture['library']->find($ids[0])->text->localized('en', 'en')['caption'], 'concurrent metadata cannot be overwritten');
        assertSameValue(3, $fixture['library']->find($ids[1])->metadataRevision, 'independent successful item is reported and committed');
        $post['description_' . $ids[0] . '__revision'] = '3';
        $post['description_' . $ids[1] . '__revision'] = '3';
        $post['description_' . $ids[0] . '__alternativeText__en'] = str_repeat('é', 501);
        $post['description_' . $ids[1] . '__credit__en'] = '<script>hostile</script>';
        $invalid = $app->handle($mutate($post));
        assertSameValue(422, $invalid->status, 'overlong and hostile text use MediaText validation');
        assertTrueValue(str_contains($invalid->body, '&lt;script&gt;hostile&lt;/script&gt;'), 'invalid submitted values remain escaped');
        assertSameValue(4, $fixture['repository']->writes, 'neither invalid image writes');
        $post['description_' . $ids[0] . '__alternativeText__en'] = '';
        $post['description_' . $ids[0] . '__decorative'] = '1';
        $post['description_' . $ids[1] . '__alternativeText__en'] = '';
        $post['description_' . $ids[1] . '__credit__en'] = '';
        assertSameValue(200, $app->handle($mutate($post))->status, 'missing alternatives remain draftable and decoration saves');
        $decorative = $fixture['library']->find($ids[0]);
        assertSameValue('', MediaDescriptionsForm::warning($decorative, $profile, Translator::english()), 'decorative image has no missing-alternative warning');
        assertTrueValue(MediaDescriptionsForm::warning($fixture['library']->find($ids[1]), $profile, Translator::english()) !== '', 'missing description matches publication eligibility');
        $listing = $app->handle(new Request('GET', '/media', cookies: $cookies));
        assertTrueValue(str_contains($listing->body, 'Text alternative needed before publishing'), 'library flags incomplete images');
        $writes = $fixture['repository']->writes;
        foreach ([[], [$ids[0], $ids[0]], array_slice(array_keys($fixture['repository']->items), 0, 9), ['../private'], [str_repeat('f', 32)]] as $bad) {
            assertSameValue(422, $app->handle($mutate([...$post, 'media_ids' => $bad]))->status, 'invalid batch rejected before writes');
        }
        unset($post['_descriptions_end']);
        assertSameValue(422, $app->handle($mutate($post))->status, 'truncated form rejected before writes');
        assertSameValue($writes, $fixture['repository']->writes, 'rejected envelopes never write');
    } finally {
        removeUploadTestTree($fixture['root']);
    }
};

$tests['description warnings follow default-language fallback and every interface localizes the batch editor'] = static function (): void {
    $fixture = editorialMediaFixture();
    try {
        $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
        $id = array_key_first($fixture['repository']->items);
        $text = \Utsuri\PortableCms\Conventional\Media\MediaText::from(['formatVersion' => 1, 'decorative' => false, 'translations' => [['locale' => $profile->defaultLocale, 'alternativeText' => 'Default authored text', 'caption' => '', 'credit' => '']]]);
        $fixture['library']->rename($id, 1, 'Image', str_repeat('1', 32), $text);
        foreach (['en', 'ca', 'es', 'de', 'fr'] as $locale) {
            $t = Translator::load($locale);
            assertSameValue('', MediaDescriptionsForm::warning($fixture['library']->find($id), $profile, $t), 'authored default completes every language');
            $markup = MediaDescriptionsForm::form([$fixture['library']->find($id)], $profile, $t, 'csrf');
            assertTrueValue(str_contains($markup, $t->html('media.text.alternative')), 'alternative label translated');
            assertTrueValue(str_contains($markup, $t->html('media.descriptions.save')), 'batch action translated');
        }
        $json = contentTestApplication(profileOverride: $profile, library: $fixture['library'])['app'];
        $cookies = authenticatedTestCookies($json);
        $response = $json->handle(new Request('GET', '/media/descriptions', ['accept' => 'application/json'], cookies: $cookies, query: ['ids' => $id]));
        $item = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR)['items'][0];
        assertSameValue(2, $item['revision'], 'enhanced form hydrates the actual library revision');
        assertSameValue($text->document(), $item['text'], 'resumed uploads receive existing descriptions instead of invented blanks');
        assertTrueValue(!str_contains($response->body, $fixture['root']), 'private storage paths never enter browser metadata');
    } finally {
        removeUploadTestTree($fixture['root']);
    }
};

$tests['description batches and individual media details preserve text in an off language'] = static function (): void {
    $fixture = editorialMediaFixture();
    try {
        $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
        $editing = siteLanguagesChoice($profile, ['es' => 'off'])->editingProfile($profile);
        $id = array_key_first($fixture['repository']->items);
        $text = \Utsuri\PortableCms\Conventional\Media\MediaText::from(['formatVersion' => 1, 'decorative' => false, 'translations' => [['locale' => 'es', 'alternativeText' => 'Texto conservado', 'caption' => 'Pie conservado', 'credit' => 'Autor conservado']]]);
        $fixture['library']->rename($id, 1, 'Image', str_repeat('1', 32), $text);
        $app = contentTestApplication(profileOverride: $editing, library: $fixture['library'])['app'];
        $cookies = authenticatedTestCookies($app);
        $post = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'media_ids' => [$id], '_descriptions_end' => '1', 'description_' . $id . '__revision' => '2', 'description_' . $id . '__alternativeText__ca' => 'Descripció nova'];
        assertSameValue(200, $app->handle(new Request('POST', '/media/descriptions', contentMutationHeaders(), $post, $cookies))->status, 'batch edits active languages');
        assertSameValue($text->localized('es', 'ca'), $fixture['library']->find($id)->text->localized('es', 'ca'), 'off-language text survives batch save');
        $detail = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '3', 'name' => 'Image', '_media_text' => '1', 'alternativeText__ca' => 'Descripció de la biblioteca'];
        assertSameValue(303, $app->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), $detail, $cookies))->status, 'same edit contract in individual details');
        assertSameValue($text->localized('es', 'ca'), $fixture['library']->find($id)->text->localized('es', 'ca'), 'off-language text survives individual save');
    } finally {
        removeUploadTestTree($fixture['root']);
    }
};
