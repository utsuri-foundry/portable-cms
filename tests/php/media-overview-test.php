<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaDerivative;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\Media\MediaPage;
use Utsuri\PortableCms\Conventional\Media\MediaText;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\MediaLibraryPage;
use Utsuri\PortableCms\Conventional\View\Pages;

/** A library with every card state: described, decorative, undescribed, a video, one being prepared and one that failed.
 * @return array{library: MediaLibrary, repository: MemoryMediaLibrary, root: string, id: Closure(int): string}
 */
function mediaOverviewFixture(): array
{
    $root = sys_get_temp_dir() . '/portable-media-overview-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $repository = new MemoryMediaLibrary();
    $id = static fn(int $number): string => str_pad(dechex($number), 32, 'a', STR_PAD_LEFT);
    $derivative = static fn(string $media, string $variant, string $mime, int $width, int $height, array $parameters = []): MediaDerivative => new MediaDerivative($media, $variant, 'key', 'file', $mime, $width, $height, 4096, str_repeat('b', 64), 'test', '1', 1, $parameters);
    $described = MediaText::from(['formatVersion' => 1, 'decorative' => false, 'translations' => [['locale' => 'ca', 'alternativeText' => 'Un estudi de color', 'caption' => 'Estudi', 'credit' => '']]], ['ca', 'es', 'en']);
    $decorative = MediaText::from(['formatVersion' => 1, 'decorative' => true, 'translations' => []], ['ca', 'es', 'en']);
    $owner = str_repeat('1', 32);
    $repository->items[$id(1)] = new LibraryMedia($id(1), $owner, 'Described.webp', 'ready', 'image/webp', 2_097_152, 3840, 2160, 1, null, $derivative($id(1), '960-webp', 'image/webp', 960, 540), $described);
    $repository->items[$id(2)] = new LibraryMedia($id(2), null, 'Decorative.webp', 'ready', 'image/webp', 524_288, 1200, 800, 1, null, $derivative($id(2), '960-webp', 'image/webp', 960, 640), $decorative);
    $repository->items[$id(3)] = new LibraryMedia($id(3), $owner, 'Undescribed.webp', 'ready', 'image/webp', 524_288, 1200, 800, 1, null, $derivative($id(3), '960-webp', 'image/webp', 960, 640));
    $repository->items[$id(4)] = new LibraryMedia($id(4), $owner, 'Trailer.mp4', 'ready', 'video/mp4', 8_000_000, 1920, 1080, 1, null, $derivative($id(4), 'prepared-video', 'video/mp4', 1920, 1080, ['durationMilliseconds' => 12000, 'codec' => 'h264', 'hasAudio' => false]));
    $repository->items[$id(5)] = new LibraryMedia($id(5), $owner, 'Pending.jpg', 'queued', 'image/jpeg', 300_000, null, null, 1, null, null);
    $repository->items[$id(6)] = new LibraryMedia($id(6), $owner, 'Failed.png', 'needs_attention', 'image/png', 300_000, null, null, 1, 'processor_failed', null);
    $library = new MediaLibrary($repository, new FilesystemMediaStore($root), new IdentityTestClock(new DateTimeImmutable('2026-11-17T12:00:00Z')), new IdentityTestRandom());
    return compact('library', 'repository', 'root', 'id');
}

$tests['media library filters by kind and missing description and its cards say only what the editor can act on'] = static function (): void {
    $fixture = mediaOverviewFixture();
    try {
        $profile = ProfileManifest::gateZero();
        $library = $fixture['library'];
        $id = $fixture['id'];
        $all = $library->browse('', 1, 'all', $profile->locales, $profile->defaultLocale);
        assertSameValue(6, count($all->items), 'every file is listed under All');
        assertSameValue(1, $all->undescribed, 'only a ready image without a description counts; prepared and failed files wait');
        assertSameValue(5, count($library->browse('', 1, 'images', $profile->locales, $profile->defaultLocale)->items), 'Images includes files still being prepared');
        assertSameValue([$id(4)], array_map(static fn(LibraryMedia $media): string => $media->id, $library->browse('', 1, 'videos', $profile->locales, $profile->defaultLocale)->items), 'Videos lists the video alone');
        $undescribed = $library->browse('', 1, 'undescribed', $profile->locales, $profile->defaultLocale);
        assertSameValue([$id(3)], array_map(static fn(LibraryMedia $media): string => $media->id, $undescribed->items), 'the description filter lists the image to describe');
        assertSameValue(0, $library->browse('trailer', 1, 'all', $profile->locales, $profile->defaultLocale)->undescribed, 'the count follows the search');
        assertSameValue('all', $library->browse('', 1, 'bogus', $profile->locales, $profile->defaultLocale)->filter, 'an unknown filter is All');

        $html = MediaLibraryPage::listing($all, $profile, adminTestChrome(), null, 'csrf', null, true);
        assertTrueValue(str_contains($html, 'aria-current="page" data-media-filter="all"'), 'the current filter is marked');
        assertTrueValue(str_contains($html, 'data-media-filter="undescribed">Need a description <span class="admin-navigation__count">1</span>'), 'the description filter carries its count');
        assertTrueValue(!str_contains($html, 'data-media-filter="videos"'), 'a profile without video has no Videos filter');
        assertSameValue(6, substr_count($html, '<li class="admin-card admin-media-card"'), 'one card per file');
        assertTrueValue(!str_contains($html, '· ready') && !str_contains($html, '3840 × 2160') && !str_contains($html, ' MB'), 'cards carry a thumbnail and a name, no size or state');
        assertSameValue(1, substr_count($html, 'Describe this image in Català, Español and English before publishing.'), 'only the undescribed ready image warns, by language name');
        assertTrueValue(str_contains($html, 'Being prepared. Ready in a few minutes.'), 'a pending card says when it will be ready under a scheduled worker');
        assertTrueValue(str_contains($html, 'This file could not be prepared. Upload it again.'), 'a failed card says what to do');
        assertSameValue(4, substr_count($html, 'name="media_ids[]"'), 'images can be ticked for a description, the failed one cannot');
        assertTrueValue(str_contains($html, 'class="admin-visually-hidden">Describe Described.webp</span>'), 'the tick keeps its name for screen readers');
        assertTrueValue(str_contains($html, '>Describe selected</button>'), 'the batch action names the selection');
        assertTrueValue(!str_contains($html, 'Website (images)') && !str_contains($html, 'Describe images') && !str_contains($html, 'Processing needs attention'), 'owner labels and engine words are gone');
        assertTrueValue(!str_contains($html, 'id="media-upload"'), 'without upload support there is no upload action');
        assertTrueValue(str_contains(MediaLibraryPage::listing($all, $profile, adminTestChrome(), null, 'csrf'), 'Being prepared. Ready in a moment.'), 'a host that prepares after the response promises a moment');

        $page = MediaLibraryPage::listing($undescribed, $profile, adminTestChrome(), null, 'csrf');
        assertSameValue(1, substr_count($page, 'admin-media-card"'), 'the filtered page lists the filtered files');
        assertTrueValue(str_contains($page, 'name="show" value="undescribed"'), 'a search keeps the filter');
        $none = MediaLibraryPage::listing(new MediaPage([], false, 1, '', 'undescribed', 0), $profile, adminTestChrome(), null, 'csrf');
        assertTrueValue(str_contains($none, 'Every image is described.') && str_contains($none, 'aria-current="page" data-media-filter="undescribed"'), 'an empty description filter says so and stays selectable');
        assertTrueValue(str_contains(MediaLibraryPage::listing(new MediaPage([], false, 1, 'zzz'), $profile, adminTestChrome(), null, 'csrf'), 'Nothing matches'), 'an empty search says so');
        assertTrueValue(str_contains(MediaLibraryPage::listing(new MediaPage([], false, 1, ''), $profile, adminTestChrome(), null, 'csrf'), 'Nothing here yet'), 'an empty library invites the first upload');

        $state = contentTestApplication(withMediaUploads: true, library: $library);
        $cookies = authenticatedTestCookies($state['app']);
        $listing = $state['app']->handle(new Request('GET', '/media', cookies: $cookies, query: ['show' => 'images', 'q' => 'De']))->body;
        assertTrueValue(str_contains($listing, '<details class="admin-media-upload" id="media-upload"><summary class="admin-button admin-button--primary">Upload</summary>'), 'Upload is the primary action and opens in place');
        assertTrueValue(str_contains($listing, 'Images up to 40 MB.') && str_contains($listing, '<option value="website">The website</option>') && str_contains($listing, 'id="media-owner"') && str_contains($listing, 'id="media-files"'), 'the rule is one line and the native controls keep their identities');
        assertTrueValue(!str_contains($listing, 'JPEG') && !str_contains($listing, 'Keep images with'), 'formats and the storage model stay out of the editor\'s way');
        assertSameValue(3, substr_count($listing, 'admin-media-card"'), 'search and filter combine');
        assertTrueValue(str_contains($listing, 'aria-current="page" data-media-filter="images"') && str_contains($listing, 'href="/media?q=De"'), 'filters keep the search and All drops the filter');
        $catalan = $state['app']->handle(new Request('GET', '/media', cookies: $cookies, query: ['show' => 'undescribed']))->body;
        assertTrueValue(str_contains($catalan, 'Need a description'), 'the English shell is the default');
        foreach (['ca', 'es', 'de', 'fr'] as $language) {
            $t = Translator::load($language);
            $translated = MediaLibraryPage::listing($all, $profile, new AdminChrome(str_repeat('c', 32), 'Editor', translator: $t), null, 'csrf');
            assertTrueValue(str_contains($translated, $t->html('media.show.undescribed')) && str_contains($translated, $t->html('media.descriptions.selected')), $language . ' library is translated');
        }
    } finally {
        removeUploadTestTree($fixture['root']);
    }
};

$tests['media item page reads name and description per language with caption and credit folded and a square crop'] = static function (): void {
    $fixture = mediaOverviewFixture();
    try {
        $id = $fixture['id'];
        $app = contentTestApplication(library: $fixture['library'])['app'];
        $cookies = authenticatedTestCookies($app);
        $page = $app->handle(new Request('GET', '/media/' . $id(1), cookies: $cookies))->body;
        assertTrueValue(str_contains($page, '<label for="media-name">Name</label>') && str_contains($page, 'id="media-name-help"'), 'the name is a field with its help folded');
        assertTrueValue(!str_contains($page, 'Image details') && !str_contains($page, 'Name shown in pickers') && !str_contains($page, 'Text alternative'), 'engine wording is gone');
        foreach (['Català', 'Español', 'English'] as $language) {
            assertTrueValue(str_contains($page, '<legend>' . $language . '</legend>'), $language . ' is named, not coded');
        }
        assertTrueValue(!str_contains($page, '<legend>CA</legend>'), 'no language codes');
        assertSameValue(3, substr_count($page, '>Description</label>'), 'one description per language');
        assertSameValue(1, substr_count($page, '<details class="admin-optional admin-optional--quiet admin-media-text__more" open><summary><span>Caption and credit</span>'), 'a language with a caption shows it');
        assertSameValue(2, substr_count($page, '<details class="admin-optional admin-optional--quiet admin-media-text__more"><summary><span>Caption and credit</span>'), 'languages without caption or credit keep them folded');
        assertTrueValue(str_contains($page, 'Decorative image, needs no description') && str_contains($page, 'id="media-decorative-help"'), 'the decorative choice explains itself on demand');
        assertTrueValue(str_contains($page, 'class="admin-focal__crop" viewBox="0 0 240 240"') && str_contains($page, '<summary><span>As numbers</span></summary>'), 'the crop is square and the percentages fold');
        assertTrueValue(str_contains($page, '<p class="admin-meta">3840 × 2160 · 2.0 MB</p>') && !str_contains($page, '· ready'), 'size facts appear once, without a state word');
        assertTrueValue(str_contains($page, '<h2 id="media-usage-heading">Used in</h2>') && str_contains($page, 'No page uses this file yet.') && str_contains($page, 'id="media-usage-help"'), 'usage reads as pages, with its explanation folded');
        assertTrueValue(str_contains($page, '>Save</button>') && !str_contains($page, 'Save media details'), 'one Save');
        assertTrueValue(str_contains($page, 'name="_website_owner"') && str_contains($page, 'class="admin-button--quiet"'), 'an administrator keeps a page image for the website through a quiet control');
        assertSameValue(1, substr_count($page, '<form class="admin-form admin-media-detail"'), 'the picture and the fields are one form');

        $video = $app->handle(new Request('GET', '/media/' . $id(4), cookies: $cookies))->body;
        assertTrueValue(str_contains($video, 'controls muted preload="metadata"') && str_contains($video, 'Video · 0:12 · 1920 × 1080'), 'a video shows its player and facts');
        assertTrueValue(!str_contains($video, 'H.264') && !str_contains($video, '<legend>Català</legend>') && !str_contains($video, 'data-focal-point'), 'codecs, descriptions and crops do not apply to a video');

        $pending = $app->handle(new Request('GET', '/media/' . $id(5), cookies: $cookies))->body;
        assertTrueValue(str_contains($pending, 'Being prepared.') && !str_contains($pending, 'data-focal-point'), 'a file being prepared says so instead of showing an empty crop');
        $failed = $app->handle(new Request('GET', '/media/' . $id(6), cookies: $cookies))->body;
        assertTrueValue(str_contains($failed, 'This file could not be prepared. Upload it again.'), 'a failed file says what to do');
    } finally {
        removeUploadTestTree($fixture['root']);
    }
};

$tests['overview keeps installation problems to owners and shows them only while they exist'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $queued = ['status' => 'queued', 'attempts' => 0, 'maxAttempts' => 3, 'errorCode' => null];
    $editor = Pages::dashboard('csrf', 'Editor', $profile, $queued, new AdminChrome(str_repeat('c', 32), 'Editor', role: 'editor'));
    assertTrueValue(!str_contains($editor, 'Signed in as') && !str_contains($editor, '3 languages'), 'the header no longer repeats the account or counts languages');
    assertTrueValue(!str_contains($editor, 'id="system-heading"') && !str_contains($editor, 'id="agency-heading"') && !str_contains($editor, 'action="/jobs/wake"') && !str_contains($editor, 'Media processing'), 'editors never see the installation card');
    assertTrueValue(str_contains($editor, 'id="continue-heading"') && str_contains($editor, 'id="attention-heading"') && str_contains($editor, 'id="content-heading"'), 'editors keep their three cards');
    $owner = Pages::dashboard('csrf', 'Owner', $profile, $queued, adminTestChrome());
    assertTrueValue(str_contains($owner, '<h2 id="agency-heading">For the agency</h2>') && str_contains($owner, 'The latest upload is queued and waiting to process.') && str_contains($owner, 'action="/jobs/wake"'), 'owners see a queued upload with its recovery');
    assertSameValue(1, substr_count($owner, 'href="/exports"'), 'Export is reached from the navigation alone');
    assertTrueValue(!str_contains($owner, 'Exact previews') && !str_contains($owner, 'href="/previews"><span>Open'), 'the card no longer doubles as a Previews entry');
    foreach ([['status' => 'completed'], null] as $healthy) {
        $quiet = Pages::dashboard('csrf', 'Owner', $profile, $healthy, adminTestChrome());
        assertTrueValue(!str_contains($quiet, 'id="agency-heading"') && !str_contains($quiet, 'Media processing'), 'a healthy installation shows no card at all');
        assertTrueValue(!str_contains($quiet, 'class="admin-lede"'), 'without a change or a publication the header has no line');
    }
    $unavailable = Pages::dashboard('csrf', 'Owner', $profile, ['status' => 'completed'], new AdminChrome(str_repeat('c', 32), 'Owner', unavailable: ['previews']));
    assertTrueValue(str_contains($unavailable, 'id="agency-heading"') && str_contains($unavailable, 'Not available'), 'a missing capability is a problem line for owners');
    foreach (['ca', 'es', 'de', 'fr'] as $language) {
        $t = Translator::load($language);
        $translated = Pages::dashboard('csrf', 'Owner', $profile, $queued, new AdminChrome(str_repeat('c', 32), 'Owner', translator: $t));
        assertTrueValue(str_contains($translated, $t->html('overview.media.queued')) && str_contains($translated, $t->html('shell.group.agency')), $language . ' overview is translated');
    }
};
