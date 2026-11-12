<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Preview\PreviewCheck;
use Utsuri\PortableCms\Conventional\Preview\PreviewCheckHistory;
use Utsuri\PortableCms\Conventional\Preview\PreviewPreflightFailure;
use Utsuri\PortableCms\Conventional\Preview\PreviewStartup;
use Utsuri\PortableCms\Conventional\View\PreviewCheckPanel;

final class MemoryPreviewChecks implements PreviewCheckHistory
{
    public ?PreviewCheck $check = null;
    public ?MemoryPreviewRepository $previews = null;

    public function failed(string $profileId, string $actorUserId, string $auditId, PreviewCheck $check): void
    {
        $this->check = $check;
    }

    public function latest(string $profileId): ?PreviewCheck
    {
        foreach ($this->previews->previews ?? [] as $preview) {
            if ($this->check === null || $preview->createdAt > $this->check->checkedAt) {
                return new PreviewCheck($preview->createdAt, $preview->includeDrafts, []);
            }
        }
        return $this->check;
    }
}

$tests['failed preview checks retry with saved drafts and remain on Overview until a later pass'] = static function (): void {
    $history = new MemoryPreviewChecks();
    ['app' => $app, 'previewRepository' => $previews, 'clock' => $clock] = contentTestApplication(withPreviews: true, previewChecks: $history);
    $history->previews = $previews;
    $cookies = authenticatedTestCookies($app);
    $modes = [];
    $issues = [new Message('preflight.issue.missing_locale', ['type' => 'Article', 'id' => 'abcdef12', 'field' => '<Title>', 'locale' => 'en'])];
    $previews->preflight = static function (bool $drafts) use (&$modes, $issues): void {
        $modes[] = $drafts;
        throw new PreviewPreflightFailure($issues, reference: str_repeat('a', 32));
    };
    $form = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], 'include_drafts' => '1'];
    $first = $app->handle(new Request('POST', '/previews', contentMutationHeaders(), $form, $cookies));
    assertSameValue(422, $first->status, 'failed check is rendered');
    assertTrueValue(str_contains($first->body, str_repeat('a', 32)), 'preflight service retains the private diagnostic reference');
    assertTrueValue(str_contains($first->body, 'name="include_drafts" value="1"'), 'retry preserves the original option');
    assertTrueValue(str_contains($first->body, 'role="status"') && str_contains($first->body, '12:00:00.000'), 'visible timestamp announces completion');
    assertTrueValue(str_contains($first->body, '&lt;Title&gt;') && str_contains($first->body, 'href="/records/article"'), 'escaped issue links to its editor');
    $clock->current = $clock->current->modify('+1 second');
    $again = $app->handle(new Request('POST', '/previews', contentMutationHeaders(), $form, $cookies));
    assertSameValue(422, $again->status, 'repeated check runs');
    assertSameValue([true, true], $modes, 'each check sees the original draft mode');
    assertTrueValue(str_contains($again->body, '12:00:01.000') && $again->body !== $first->body, 'latest check is visibly new');
    assertSameValue([], $previews->previews, 'no failed build is queued');
    foreach (['/', '/previews'] as $path) {
        $page = $app->handle(new Request('GET', $path, cookies: $cookies));
        assertTrueValue(str_contains($page->body, 'The latest preview check needs attention') && str_contains($page->body, '&lt;Title&gt;') && str_contains($page->body, str_repeat('a', 32)) && str_contains($page->body, 'href="/records/article"'), 'same reason and correction link survive navigation');
    }
    assertSameValue(403, $app->handle(new Request('POST', '/previews', contentMutationHeaders(), [...$form, '_csrf' => 'wrong'], $cookies))->status, 'rejected mutation cannot replace the report');
    assertSameValue('12:00:01', $history->check->checkedAt->format('H:i:s'), 'rejection leaves check time unchanged');
    $clock->current = $clock->current->modify('+1 second');
    $previews->preflight = null;
    assertSameValue(202, $app->handle(new Request('POST', '/previews', contentMutationHeaders(), $form, $cookies))->status, 'a corrected check passes');
    foreach (['/', '/previews'] as $path) {
        assertTrueValue(!str_contains($app->handle(new Request('GET', $path, cookies: $cookies))->body, 'The latest preview check needs attention'), 'later pass clears the shared report');
    }
};

$tests['empty-installation guidance names only declared required records and tracks their saved state'] = static function (): void {
    $package = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $builder = $package->siteBuilder();
    assertTrueValue($builder instanceof \Utsuri\PortableCms\Conventional\Preview\PreviewStartupRequirements, 'Reference Studio declares startup requirements');
    if (!$builder instanceof \Utsuri\PortableCms\Conventional\Preview\PreviewStartupRequirements) {
        throw new RuntimeException('Reference startup declaration unavailable');
    }
    $startup = new PreviewStartup($package->manifest, $builder->requiredSingletons());
    ['app' => $app, 'contentRepository' => $records, 'clock' => $clock] = contentTestApplication(withPreviews: true, profileOverride: $package->manifest, previewStartup: $startup);
    $cookies = authenticatedTestCookies($app);
    foreach (['/', '/previews'] as $path) {
        $page = $app->handle(new Request('GET', $path, cookies: $cookies));
        foreach (['home', 'about'] as $type) {
            assertTrueValue(str_contains($page->body, 'href="/records/' . $type . '/new"'), 'missing singleton is linked before a check');
        }
        assertTrueValue(!str_contains($page->body, 'Site name') && str_contains($page->body, 'Before your first preview'), 'startup does not require duplicating the engine website name');
    }
    foreach (['site-settings', 'home', 'about'] as $index => $type) {
        $id = str_repeat((string) ($index + 1), 32);
        $records->records[$id] = new RecordSnapshot($id, $type, 'draft', str_repeat('f', 32), 1, [], $clock->current);
    }
    assertTrueValue(str_contains($app->handle(new Request('GET', '/previews', cookies: $cookies))->body, '/records/home/' . str_repeat('2', 32)), 'saved drafts link to the existing record');
    foreach ($records->records as $id => $record) {
        $records->records[$id] = new RecordSnapshot($id, $record->typeId, 'published', $record->revisionId, 1, [], $clock->current);
    }
    assertTrueValue(!str_contains($app->handle(new Request('GET', '/', cookies: $cookies))->body, 'Before your first preview'), 'completed startup is no longer presented');
    foreach ([['project'], ['not-installed'], ['home', 'home']] as $invalid) {
        try {
            new PreviewStartup($package->manifest, $invalid);
            throw new RuntimeException('Invalid startup declaration accepted');
        } catch (InvalidArgumentException) {
        }
    }
};

$tests['saved preview messages retain nested translations and refuse malformed persisted data'] = static function (): void {
    $check = new PreviewCheck(new DateTimeImmutable('2026-10-11T12:34:56.123456Z'), true, [new Message('validation.detail', ['field' => '<script>', 'detail' => new Message('validation.required', ['field' => 'Name'])])], reference: str_repeat('b', 32));
    $document = json_decode(json_encode($check->document(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $restored = PreviewCheck::fromDocument($check->checkedAt, $document);
    assertSameValue($check->reference, $restored->reference, 'private reference survives check persistence');
    foreach (['en', 'ca', 'es', 'fr', 'de'] as $locale) {
        $translator = Translator::load($locale);
        assertSameValue($translator->message($check->issues[0]), $translator->message($restored->issues[0]), 'messages remain keys rather than stored English');
        $html = PreviewCheckPanel::failed($restored, null, $translator);
        assertTrueValue(!str_contains($html, '<script>') && str_contains($html, '12:34:56.123') && str_contains($html, str_repeat('b', 32)), 'localized result escapes hostile input and retains milliseconds');
    }
    foreach ([['includeDrafts' => '1', 'issues' => []], ['includeDrafts' => false, 'issues' => [null]], ['includeDrafts' => false, 'issues' => [['key' => 'validation.required', 'parameters' => ['field' => true]]]]] as $invalid) {
        try {
            PreviewCheck::fromDocument($check->checkedAt, $invalid);
            throw new LogicException('Invalid persisted report accepted');
        } catch (RuntimeException) {
        }
    }
    foreach (['javascript:alert(1)', '/login', '//example.test/', '/records/article/../../login'] as $link) {
        try {
            new PreviewCheck($check->checkedAt, true, $check->issues, [$link]);
            throw new RuntimeException('Unsafe report link accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $link = '/records/article/' . str_repeat('a', 32);
    $linked = new PreviewCheck($check->checkedAt, true, $check->issues, [$link]);
    $restoredLink = PreviewCheck::fromDocument($linked->checkedAt, json_decode(json_encode($linked->document(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    assertTrueValue(str_contains(PreviewCheckPanel::failed($restoredLink, null, Translator::load('de')), 'href="' . $link . '"'), 'stable record identity survives storage and interface-language changes');
};
