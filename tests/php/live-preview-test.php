<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Http\SecurityHeaders;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Preview\LivePreviewDocument;
use Utsuri\PortableCms\Conventional\Preview\LivePreviewFailure;
use Utsuri\PortableCms\Conventional\Preview\LivePreviewRequest;
use Utsuri\PortableCms\Conventional\Preview\LivePreviewService;
use Utsuri\PortableCms\Conventional\Preview\LivePreviewStore;
use Utsuri\PortableCms\Conventional\Preview\PreviewRecord;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;

final class LivePreviewTestStore implements LivePreviewStore
{
    /** @var list<array{tokenHash: string, renderId: string, sessionId: string, recordKey: string, document: LivePreviewDocument}> */
    public array $saved = [];

    public function owns(string $tokenHash, string $sessionId, string $recordKey, DateTimeImmutable $now): bool
    {
        foreach ($this->saved as $render) {
            if ($render['tokenHash'] === $tokenHash && $render['sessionId'] === $sessionId && $render['recordKey'] === $recordKey) {
                return true;
            }
        }
        return false;
    }

    public function save(string $tokenHash, string $renderId, string $sessionId, string $recordKey, LivePreviewDocument $document, DateTimeImmutable $now, DateTimeImmutable $expiresAt): void
    {
        $this->saved[] = compact('tokenHash', 'renderId', 'sessionId', 'recordKey', 'document');
    }

    public function find(string $tokenHash, ?string $renderId, DateTimeImmutable $now): ?LivePreviewDocument
    {
        foreach (array_reverse($this->saved) as $render) {
            if ($render['tokenHash'] === $tokenHash && ($renderId === null || $render['renderId'] === $renderId)) {
                return $render['document'];
            }
        }
        return null;
    }
}

/**
 * @param list<float> $seconds Successive monotonic readings.
 * @param null|Closure(PreviewRecord): PreviewRecord $saved Changes saved records.
 * @return array{service: LivePreviewService, store: LivePreviewTestStore, snapshot: PreviewSnapshot, root: string}
 */
function livePreviewFixture(array $seconds = [], ?Closure $saved = null): array
{
    $fixture = profilePreviewFixture();
    if ($saved !== null) {
        $original = $fixture['snapshot'];
        $fixture['snapshot'] = new PreviewSnapshot($original->profileId, $original->profileVersion, $original->locales, array_map($saved, $original->records), $original->createdAt, $original->designRegistries, $original->profileChecksum);
    }
    $store = new LivePreviewTestStore();
    $clock = new class implements Clock {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-12T12:00:00Z');
        }
    };
    $service = new LivePreviewService(
        $fixture['package'],
        $fixture['compiler'],
        static fn(DateTimeImmutable $now): PreviewSnapshot => $fixture['snapshot'],
        $store,
        $clock,
        new SystemRandomSource(),
        $seconds === [] ? null : static function () use (&$seconds): float {
            return array_shift($seconds) ?? 0.0;
        },
    );
    return ['service' => $service, 'store' => $store, 'snapshot' => $fixture['snapshot'], 'root' => $fixture['root']];
}

/** @return array<string, mixed> */
function livePreviewBody(PreviewSnapshot $snapshot, string $id): array
{
    foreach ($snapshot->records as $record) {
        if ($record->id === $id) {
            return $record->body;
        }
    }
    throw new RuntimeException('Live preview fixture record is missing');
}

$tests['live preview draws one unsaved page among the published site and keeps nothing else'] = static function (): void {
    $live = livePreviewFixture();
    try {
        $body = livePreviewBody($live['snapshot'], '10000000000000000000000000000001');
        $body['title']['en'] = 'Archive of Mist, unsaved';
        $result = $live['service']->render(new LivePreviewRequest('project', '10000000000000000000000000000001', $body, [], 'record', 'en'), str_repeat('5', 32));
        assertSameValue(['/en/projects/archive-of-mist/', 'record', ['record', 'home']], [$result->route, $result->page, $result->pages], 'the record page is drawn first and the home page is offered');
        assertTrueValue(preg_match('#\A/live-previews/[a-f0-9]{64}/en/projects/archive-of-mist/\?render=[a-f0-9]{32}\z#', $result->url()) === 1, 'the page address carries the token and the render');
        $saved = $live['store']->saved[0];
        assertSameValue([hash('sha256', $result->token), 'project:10000000000000000000000000000001'], [$saved['tokenHash'], $saved['recordKey']], 'only the token hash is stored, with the record it shows');
        $document = $saved['document'];
        $html = $document->files['en/projects/archive-of-mist/index.html']['body'] ?? '';
        assertTrueValue(str_contains($html, 'Archive of Mist, unsaved'), 'the page shows the unsaved title');
        assertTrueValue(str_contains($html, '<script src="../../../_cms/live-bridge.js" defer></script></head>'), 'the bridge joins the head with a mount-independent address');
        assertTrueValue(str_contains($html, '<h1 class="cms-type-title" data-cms-field="title">Archive of Mist, unsaved</h1>'), 'the live page names the fields of the edited record');
        $kept = array_values(array_filter(array_keys($document->files), static fn(string $path): bool => !str_starts_with($path, 'search/')));
        sort($kept);
        assertSameValue(['assets/composition.css', 'assets/design.css', 'assets/media-focal.css', 'en/projects/archive-of-mist/index.html'], $kept, 'only the page and its generated styles are kept');
        assertTrueValue($document->media !== [] && array_filter(array_keys($document->media), static fn(string $path): bool => !str_starts_with($path, 'media/')) === [], 'media are named by their public paths and read from verified sources');
        assertSameValue(false, $result->slow, 'a quick render keeps automatic updates');
        assertTrueValue(array_filter($live['snapshot']->records, static fn(PreviewRecord $record): bool => $record->workflowState === 'draft') !== [], 'the site has an unfinished draft');
        assertSameValue(false, $result->drafts, 'a page the published site can draw leaves drafts out, even an unfinished one');
        $home = $live['service']->render(new LivePreviewRequest('project', '10000000000000000000000000000001', $body, [], 'home', 'ca'), str_repeat('5', 32), $result->token);
        assertSameValue(['/ca/', 'home', $result->token], [$home->route, $home->page, $home->token], 'the home page in the chosen language keeps the workspace token');
        $foreign = $live['service']->render(new LivePreviewRequest('project', '10000000000000000000000000000001', $body, [], 'home', 'ca'), str_repeat('6', 32), $result->token);
        assertTrueValue($foreign->token !== $result->token, 'another session never adopts a token');
        assertSameValue($html, $live['service']->document($result->token, $result->renderId)?->files['en/projects/archive-of-mist/index.html']['body'], 'an earlier render stays addressable');
        assertSameValue(null, $live['service']->document('not-a-token'), 'malformed tokens find nothing');
    } finally {
        staticCompilerRemoveTree($live['root']);
    }
};

$tests['live preview shows a draft or new record as published and reports what blocks it'] = static function (): void {
    $live = livePreviewFixture([10.0, 14.0]);
    try {
        $body = livePreviewBody($live['snapshot'], '10000000000000000000000000000001');
        $body['slug'] = ['ca' => 'arxiu-nou', 'es' => 'archivo-nuevo', 'en' => 'new-archive'];
        $result = $live['service']->render(new LivePreviewRequest('project', null, $body, [], 'record', 'en'), str_repeat('5', 32));
        assertSameValue('/en/projects/new-archive/', $result->route, 'an unsaved new record takes a provisional identity and its own page');
        assertSameValue('project:new', $live['store']->saved[0]['recordKey'], 'new records share one workspace key');
        assertSameValue([true, 4000], [$result->slow, $result->elapsedMilliseconds], 'a render over the budget switches the pane to manual updates');
        try {
            $live['service']->render(new LivePreviewRequest('project', '10000000000000000000000000000002', livePreviewBody($live['snapshot'], '10000000000000000000000000000002'), [], 'record', 'en'), str_repeat('5', 32));
            assertTrueValue(false, 'an incomplete record cannot be drawn');
        } catch (LivePreviewFailure $failure) {
            $text = \Utsuri\PortableCms\Conventional\I18n\Translator::english()->message($failure->reason);
            assertTrueValue(str_contains($text, 'Taller de llum') && str_contains($text, ' in en: ') && str_contains($text, 'missing translation'), 'the reason names the record, field and language: ' . $text);
        }
        assertSameValue(1, count($live['store']->saved), 'a failed render stores nothing');
    } finally {
        staticCompilerRemoveTree($live['root']);
    }
};

/** The home is still a draft, as before anything is published; an unfinished draft can be archived. */
function livePreviewDraftSettings(PreviewRecord $record, bool $archiveUnfinished = false): PreviewRecord
{
    $state = match (true) {
        $record->typeId === 'home' => 'draft',
        $archiveUnfinished && $record->id === '10000000000000000000000000000002' => 'archived',
        default => $record->workflowState,
    };
    return new PreviewRecord($record->id, $record->typeId, $record->revisionId, $record->revisionNumber, $record->body, $record->relationships, $record->media, $state);
}

$tests['live preview draws the site drafts when nothing published can show the page'] = static function (): void {
    // The unfinished draft project is set aside, so the drafts build can succeed.
    $live = livePreviewFixture([], static fn(PreviewRecord $record): PreviewRecord => livePreviewDraftSettings($record, true));
    try {
        $body = livePreviewBody($live['snapshot'], '10000000000000000000000000000001');
        $body['title']['en'] = 'Archive of Mist, before launch';
        $result = $live['service']->render(new LivePreviewRequest('project', '10000000000000000000000000000001', $body, [], 'record', 'en'), str_repeat('5', 32));
        assertSameValue(['/en/projects/archive-of-mist/', true], [$result->route, $result->drafts], 'the page is drawn with the site drafts and says so');
        $html = $live['store']->saved[0]['document']->files['en/projects/archive-of-mist/index.html']['body'] ?? '';
        assertTrueValue(str_contains($html, 'Archive of Mist, before launch') && str_contains($html, 'data-cms-field="title"'), 'the drafts page shows the unsaved values with field marks');
    } finally {
        staticCompilerRemoveTree($live['root']);
    }
    $live = livePreviewFixture([], static fn(PreviewRecord $record): PreviewRecord => livePreviewDraftSettings($record));
    try {
        $live['service']->render(new LivePreviewRequest('project', '10000000000000000000000000000001', livePreviewBody($live['snapshot'], '10000000000000000000000000000001'), [], 'record', 'en'), str_repeat('5', 32));
        assertTrueValue(false, 'a page neither build can draw fails');
    } catch (LivePreviewFailure $failure) {
        $text = \Utsuri\PortableCms\Conventional\I18n\Translator::english()->message($failure->reason);
        assertTrueValue(!str_contains($text, 'Taller de llum'), 'the published build reason is reported, not the unfinished draft: ' . $text);
        assertSameValue([], $live['store']->saved, 'a failed render stores nothing');
    } finally {
        staticCompilerRemoveTree($live['root']);
    }
};

$tests['live preview responses keep the public policy in an opaque sandbox framed only by the CMS'] = static function (): void {
    $response = SecurityHeaders::apply((new Response(200, '<!doctype html>'))->withHeader('content-type', 'text/html; charset=utf-8')->asIsolated(), str_repeat('a', 24));
    $policy = $response->headers()['content-security-policy'][0];
    assertTrueValue(str_starts_with($policy, str_replace("frame-ancestors 'none'", "frame-ancestors 'self'", \Utsuri\PortableCms\Conventional\Compiler\ApachePublicDelivery::CONTENT_SECURITY_POLICY)), 'the public site policy is kept, with framing by the CMS only');
    assertTrueValue(str_contains($policy, '; sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox') && !str_contains($policy, 'allow-same-origin'), 'scripts run in an opaque origin even when opened in a tab');
    assertSameValue(['cross-origin', '*', 'no-store'], [$response->headers()['cross-origin-resource-policy'][0], $response->headers()['access-control-allow-origin'][0], $response->headers()['cache-control'][0]], 'the opaque frame may read the page, which is never cached');
    $asset = SecurityHeaders::apply((new Response(200, 'a'))->withHeader('cache-control', 'private, max-age=600')->asIsolated(), str_repeat('a', 24));
    assertSameValue(['private, max-age=600'], $asset->headers()['cache-control'], 'verified assets keep their workspace caching');
    $admin = SecurityHeaders::apply(new Response(200, 'a'), str_repeat('a', 24));
    assertSameValue(['same-site', false], [$admin->headers()['cross-origin-resource-policy'][0], $admin->hasHeader('access-control-allow-origin')], 'administration responses are unchanged');
};

$tests['field marks appear only on live pages of the edited record and published bytes stay the same'] = static function (): void {
    $fixture = staticCompilerFixture();
    $snapshot = $fixture['snapshot'];
    $record = $snapshot->record('10000000000000000000000000000001');
    assertTrueValue($record !== null, 'the fixture project exists');
    $marks = \Utsuri\PortableCms\Conventional\Compiler\FieldMarks::class;
    assertSameValue(['', '', ' data-cms-field="title"'], [$marks::none()->attribute('title'), $marks::for($snapshot, $record)->attribute('title'), $marks::for($snapshot->live($record->id), $record)->attribute('title')], 'only the live snapshot of this record marks its fields');
    $itemId = str_repeat('a', 32);
    assertSameValue(['', ' data-cms-field="body:3"'], [$marks::for($snapshot, $record)->blockAttribute('body', 3), $marks::for($snapshot->live($record->id), $record)->blockAttribute('body', 3)], 'rich block positions exist only in live pages');
    foreach ([-1, 5000] as $position) {
        try {
            $marks::none()->blockAttribute('body', $position);
            throw new RuntimeException('Invalid rich block position accepted');
        } catch (InvalidArgumentException) {
        }
    }
    assertSameValue(['', ' data-cms-field="credits.' . $itemId . '.name"'], [$marks::for($snapshot, $record)->itemAttribute('credits', $itemId, 'name'), $marks::for($snapshot->live($record->id), $record)->itemAttribute('credits', $itemId, 'name')], 'group children carry stable identities on live pages only');
    foreach ([['credits.nested', $itemId, 'name'], ['credits', '1', 'name'], ['credits', strtoupper($itemId), 'name'], ['credits', $itemId, 'name" onclick="x']] as $parts) {
        try {
            $marks::none()->itemAttribute(...$parts);
            throw new RuntimeException('A group mark accepted a nested path, position or hostile identity');
        } catch (InvalidArgumentException) {
        }
    }
    foreach ([static fn() => $marks::none()->attribute('title" onclick="x'), static fn() => $snapshot->live(str_repeat('9', 32))] as $invalid) {
        try {
            $invalid();
            throw new RuntimeException('A mark named something other than a field, or a record outside the snapshot went live');
        } catch (InvalidArgumentException) {
        }
    }
    $routes = static function (\Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot $snapshot) use ($fixture): array {
        $html = [];
        foreach ($fixture['package']->siteBuilder()->build($snapshot)->routes as $route) {
            $html[$route->route] = $route->html;
        }
        return $html;
    };
    $published = $routes($snapshot);
    $live = $routes($snapshot->live($record->id));
    assertSameValue([], array_keys(array_filter($published, static fn(string $html): bool => str_contains($html, 'data-cms-field'))), 'published pages carry no field marks');
    assertSameValue($published, array_map(static fn(string $html): string => (string) preg_replace('/ data-cms-field="[^"]*"/', '', $html), $live), 'removing the marks leaves the published bytes');
    $marked = array_keys(array_filter($live, static fn(string $html): bool => str_contains($html, 'data-cms-field')));
    sort($marked);
    assertSameValue(['/ca/projects/arxiu-de-la-boira/', '/en/projects/archive-of-mist/', '/es/projects/archivo-de-la-niebla/'], $marked, 'only the edited record\'s own pages are marked');
    $page = $live['/en/projects/archive-of-mist/'];
    foreach (['<h1 class="cms-type-title" data-cms-field="title">', '<dd data-cms-field="year">', '<section class="studio-composition" aria-label="Project composition" data-cms-field="layout">'] as $expected) {
        assertTrueValue(str_contains($page, $expected), "the project page marks {$expected}");
    }
    assertTrueValue(preg_match('/<dd data-cms-field="credits\.[a-f0-9]{32}\.name">Iris Soler<\/dd>/', $page) === 1, 'Reference Studio marks a rendered credit by identity');
    $article = $snapshot->record('30000000000000000000000000000001');
    assertTrueValue($article !== null, 'the fixture article exists');
    $liveArticle = $routes($snapshot->live($article->id));
    assertSameValue($published, array_map(static fn(string $html): string => (string) preg_replace('/ data-cms-field="[^"]*"/', '', $html), $liveArticle), 'rich row marks also leave every public page byte unchanged');
    assertTrueValue(str_contains($liveArticle['/en/articles/measuring-shade/'] ?? '', '<h2 data-cms-field="body:0">Shade as material</h2>'), 'the heading marks its exact stored row');
    assertTrueValue(str_contains($liveArticle['/en/articles/measuring-shade/'] ?? '', '<p data-cms-field="body:1"><em>Measuring is also a way of listening.</em></p>'), 'the paragraph keeps its semantic marks and its own position');
};

$tests['record form names every field for the live preview'] = static function (): void {
    $profile = \Utsuri\PortableCms\Conventional\Content\ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $type = $profile->type('project');
    assertTrueValue($type !== null, 'Reference Studio project type exists');
    $record = new \Utsuri\PortableCms\Conventional\Content\RecordSnapshot(str_repeat('1', 32), 'project', 'draft', str_repeat('2', 32), 1, ['title' => ['ca' => 'Arxiu', 'es' => '', 'en' => '']], new DateTimeImmutable('2026-10-02T08:00:00Z'));
    $html = \Utsuri\PortableCms\Conventional\View\Pages::recordForm(str_repeat('b', 32), $profile, $type, $record->body, $record, chrome: adminTestChrome());
    foreach ($type->fields as $field) {
        if (\Utsuri\PortableCms\Conventional\Content\EditorialForm::supported($field)) {
            assertSameValue(1, substr_count($html, ' data-field="' . $field->id . '" data-field-label="'), "{$field->id} is named once on its outermost element");
        }
    }
    assertTrueValue(str_contains($html, '<fieldset data-field="title" data-field-label="Title" data-field-match="text" class="admin-field" id="field-title">'), 'short text is recognised by its value');
    assertTrueValue(str_contains($html, 'data-field="body" data-field-label="Body" data-field-match="rich"'), 'top-level rich text exposes its mounted editor');
    assertTrueValue(preg_match('/<[a-z]+ data-field="gallery" data-field-label="Gallery" data-field-match="media"/', $html) === 1, 'media are recognised by their images');
};
