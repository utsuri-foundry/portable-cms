<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Compiler\ArtifactStyles;
use Utsuri\PortableCms\Conventional\Compiler\ArtifactUrl;
use Utsuri\PortableCms\Conventional\Compiler\FilesystemMediaSource;
use Utsuri\PortableCms\Conventional\Compiler\MediaFocalStyle;
use Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuild;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

/** One record's unsaved, validated values, to be shown in place of its saved revision. */
final readonly class LivePreviewRequest
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, list<string>> $relationships
     */
    public function __construct(
        public string $typeId,
        public ?string $recordId,
        public array $body,
        public array $relationships,
        public string $page,
        public string $locale,
    ) {
        if (!in_array($page, ['record', 'home'], true) || preg_match('/\A[a-z]{2,3}(?:-[A-Z]{2})?\z/', $locale) !== 1) {
            throw new \InvalidArgumentException('Live preview page is invalid');
        }
    }
}

final readonly class LivePreviewResult
{
    /** @param list<'record'|'home'> $pages Pages of this record the editor can choose, in that order. */
    public function __construct(
        public string $token,
        public string $renderId,
        public string $route,
        public string $page,
        public array $pages,
        public int $elapsedMilliseconds,
        public bool $slow,
        public bool $drafts = false,
    ) {}

    public function url(): string
    {
        // The render is a query, so relative asset addresses stay the same for the whole workspace and can be cached.
        return '/live-previews/' . $this->token . $this->route . '?render=' . $this->renderId;
    }
}

/** A plain reason the page could not be drawn; the last good page stays visible. */
final class LivePreviewFailure extends \RuntimeException
{
    public function __construct(public readonly Message $reason, ?\Throwable $previous = null)
    {
        parent::__construct('Live preview could not be rendered.', previous: $previous);
    }
}

/**
 * The stored page of one render. Generated text files travel with it; media, fonts and profile
 * assets are named with their frozen identities and read from their verified sources on request.
 */
final readonly class LivePreviewDocument
{
    /**
     * @param array<string, array{mime: string, body: string}> $files
     * @param array<string, array{mediaId: string, ownerRecordId: ?string, variant: string, mime: string, byteSize: int, sha256: string}> $media
     * @param array<string, array{sha256: string, byteSize: int}> $fonts
     */
    public function __construct(public string $route, public array $files, public array $media, public array $fonts, public bool $vimeoFrame = false) {}

    /** @return array<string, mixed> */
    public function document(): array
    {
        return ['formatVersion' => 1, 'route' => $this->route, 'files' => $this->files, 'media' => $this->media, 'fonts' => $this->fonts, ...($this->vimeoFrame ? ['frameSources' => [\Utsuri\PortableCms\Conventional\Compiler\VimeoPresentation::ORIGIN]] : [])];
    }

    /** @param array<string, mixed> $document */
    public static function fromDocument(array $document): self
    {
        if (($document['formatVersion'] ?? null) !== 1 || !is_string($document['route'] ?? null) || !is_array($document['files'] ?? null)
            || !is_array($document['media'] ?? null) || !is_array($document['fonts'] ?? null)) {
            throw new \RuntimeException('Stored live preview is invalid');
        }
        if (array_key_exists('frameSources', $document) && $document['frameSources'] !== [\Utsuri\PortableCms\Conventional\Compiler\VimeoPresentation::ORIGIN]) {
            throw new \RuntimeException('Stored live preview frame sources are invalid');
        }
        return new self($document['route'], $document['files'], $document['media'], $document['fonts'], isset($document['frameSources']));
    }
}

interface LivePreviewStore
{
    /** Whether an unexpired workspace token was issued to this session for this record. */
    public function owns(string $tokenHash, string $sessionId, string $recordKey, \DateTimeImmutable $now): bool;

    /** Stores a render and keeps only the newest few for the same session and record. */
    public function save(string $tokenHash, string $renderId, string $sessionId, string $recordKey, LivePreviewDocument $document, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void;

    /** One render, or the newest, of an unexpired token whose session is still signed in. */
    public function find(string $tokenHash, ?string $renderId, \DateTimeImmutable $now): ?LivePreviewDocument;
}

/**
 * Builds the published site with one record replaced by its unsaved values, in memory, and keeps
 * only the requested page. The record is shown as published whatever its state. When the published
 * site cannot draw that page, as before anything is published, the site's drafts are drawn too.
 */
final class LivePreviewService
{
    public const LIFETIME_SECONDS = 600;
    /** Renders slower than this switch the pane to a manual Update button. */
    public const BUDGET_MILLISECONDS = 3000;
    public const MAX_DOCUMENT_BYTES = 4_194_304;
    public const BRIDGE_PATH = '_cms/live-bridge.js';

    /**
     * @param \Closure(\DateTimeImmutable): PreviewSnapshot $snapshot Saved records; published ones are drawn, and drafts only when those cannot show the page.
     * @param null|\Closure(): float $seconds Monotonic time for the render budget.
     */
    public function __construct(
        private readonly ProfilePackage $package,
        private readonly ProfilePreviewCompiler $compiler,
        private readonly \Closure $snapshot,
        private readonly LivePreviewStore $store,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        private readonly ?\Closure $seconds = null,
    ) {}

    /** @param string $presented The workspace token of the previous render, kept when it still belongs to this session and record. */
    public function render(LivePreviewRequest $request, string $sessionId, string $presented = ''): LivePreviewResult
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $sessionId) !== 1) {
            throw new \InvalidArgumentException('Live preview session is invalid');
        }
        $started = $this->seconds();
        $now = $this->clock->now();
        [$snapshot, $recordId] = $this->substitute(($this->snapshot)($now), $request);
        if (!in_array($request->locale, $snapshot->locales, true)) {
            throw new \InvalidArgumentException('Live preview language is not a site language');
        }
        $drafts = false;
        try {
            [$frozen, $site, $routes, $pages] = $this->draw($snapshot, $recordId, $request);
        } catch (LivePreviewFailure $published) {
            if (!self::hasDrafts($snapshot)) {
                throw $published;
            }
            try {
                [$frozen, $site, $routes, $pages] = $this->draw(self::withDrafts($snapshot), $recordId, $request);
                $drafts = true;
            } catch (LivePreviewFailure) {
                // The published site's reason stays the one to fix: sites with published content keep their messages.
                throw $published;
            }
        }
        $page = isset($pages[$request->page]) ? $request->page : (string) array_key_first($pages);
        $route = $pages[$page];
        $vimeo = \Utsuri\PortableCms\Conventional\Compiler\VimeoPresentation::enabled($this->package->manifest, $frozen);
        $html = \Utsuri\PortableCms\Conventional\Compiler\VimeoPresentation::document($routes[$route], $route, $vimeo);
        if ($vimeo) {
            $html = str_replace('<script type="module"', '<script data-vimeo-workspace type="module"', $html);
        }
        $files = [self::routePath($route) => ['mime' => 'text/html; charset=utf-8', 'body' => self::withBridge($html, $route)]];
        if ($vimeo) {
            $files += \Utsuri\PortableCms\Conventional\Compiler\VimeoPresentation::assets();
        }
        $fonts = [];
        if ($frozen->designRegistries !== null) {
            $declared = ArtifactStyles::fonts($this->package, $frozen);
            $files['assets/design.css'] = ['mime' => 'text/css; charset=utf-8', 'body' => (string) ArtifactStyles::design($frozen, $declared['paths'])];
            foreach ($declared['uploads'] as $font) {
                $fonts[$font['path']] = ['sha256' => $font['sha256'], 'byteSize' => $font['byteSize']];
            }
        }
        $composition = ArtifactStyles::composition($this->package, $frozen);
        if ($composition !== '') {
            $files['assets/composition.css'] = ['mime' => 'text/css; charset=utf-8', 'body' => $composition];
        }
        $media = [];
        $focalPoints = [];
        foreach ($site->publicMediaIds as $mediaId) {
            $source = $frozen->media($mediaId) ?? throw new LivePreviewFailure(new Message('preflight.issue.website', ['reason' => new Message('preflight.reason.profile_error')]));
            if (str_starts_with($source->mime, 'image/')) {
                $focalPoints[] = $source->focalPoint;
            }
            foreach ($source->renditions() as $rendition) {
                $media[FilesystemMediaSource::publicPath($rendition)] = ['mediaId' => $rendition->id, 'ownerRecordId' => $rendition->ownerRecordId, 'variant' => $rendition->variant, 'mime' => $rendition->mime, 'byteSize' => $rendition->byteSize, 'sha256' => $rendition->checksum];
            }
        }
        $files['assets/media-focal.css'] = ['mime' => 'text/css; charset=utf-8', 'body' => MediaFocalStyle::stylesheet($focalPoints)];
        foreach ($site->searchIndexes as $index) {
            $files['search/' . $index->locale . '.json'] = ['mime' => 'application/json', 'body' => \Utsuri\PortableCms\Conventional\Compiler\StaticSiteCompiler::json($index->document)];
        }
        $document = new LivePreviewDocument($route, $files, $media, $fonts, $vimeo);
        if (strlen(json_encode($document->document(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > self::MAX_DOCUMENT_BYTES) {
            throw new LivePreviewFailure(new Message('live.error.too_large'));
        }
        $recordKey = $request->typeId . ':' . ($request->recordId ?? 'new');
        $token = preg_match('/\A[a-f0-9]{64}\z/', $presented) === 1 && $this->store->owns(hash('sha256', $presented), $sessionId, $recordKey, $now)
            ? $presented : bin2hex($this->random->bytes(32));
        $renderId = bin2hex($this->random->bytes(16));
        $this->store->save(hash('sha256', $token), $renderId, $sessionId, $recordKey, $document, $now, $now->modify('+' . self::LIFETIME_SECONDS . ' seconds'));
        $elapsed = (int) round(($this->seconds() - $started) * 1000);
        return new LivePreviewResult($token, $renderId, $route, $page, array_keys($pages), $elapsed, $elapsed > self::BUDGET_MILLISECONDS, $drafts);
    }

    /** @return array{\Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot, ProfileSiteBuild, array<string, string>, non-empty-array<string, string>} */
    private function draw(PreviewSnapshot $snapshot, string $recordId, LivePreviewRequest $request): array
    {
        try {
            $this->compiler->checkRequired($snapshot);
            // Only live pages of this record may carry field marks.
            $frozen = $this->compiler->freeze($snapshot)->live($recordId);
            $site = $this->package->siteBuilder()->build($frozen);
        } catch (PreviewPreflightFailure $error) {
            throw new LivePreviewFailure($error->issues[0] ?? new Message('preflight.issue.website', ['reason' => new Message('preflight.reason.profile_error')]), $error);
        } catch (\Throwable $error) {
            throw new LivePreviewFailure($this->compiler->diagnostics($error, $snapshot)->issues[0] ?? new Message('preflight.issue.website', ['reason' => new Message('preflight.reason.profile_error')]), $error);
        }
        $routes = [];
        foreach ($site->routes as $route) {
            $routes[$route->route] = $route->html;
        }
        $pages = self::pages($site, $routes, $recordId, $request);
        if ($pages === []) {
            throw new LivePreviewFailure(new Message('live.error.no_page'));
        }
        return [$frozen, $site, $routes, $pages];
    }

    private static function hasDrafts(PreviewSnapshot $snapshot): bool
    {
        foreach ($snapshot->records as $record) {
            if ($record->workflowState === 'draft') {
                return true;
            }
        }
        return false;
    }

    private static function withDrafts(PreviewSnapshot $snapshot): PreviewSnapshot
    {
        return $snapshot->with($snapshot->records, true);
    }

    public function document(string $token, ?string $renderId = null): ?LivePreviewDocument
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $token) === 1 ? $this->store->find(hash('sha256', $token), $renderId, $this->clock->now()) : null;
    }

    /** @return array{PreviewSnapshot, string} The snapshot to draw and the record's identity in it. */
    private function substitute(PreviewSnapshot $saved, LivePreviewRequest $request): array
    {
        $records = [];
        $current = null;
        foreach ($saved->records as $record) {
            if ($request->recordId !== null && $record->id === $request->recordId) {
                if ($record->typeId !== $request->typeId) {
                    throw new \InvalidArgumentException('Live preview record type differs');
                }
                $current = $record;
                continue;
            }
            $records[] = $record;
        }
        if ($request->recordId !== null && $current === null) {
            throw new \InvalidArgumentException('Live preview record is not saved');
        }
        // A record that has never been saved takes a provisional identity for this render only.
        $recordId = $current->id ?? bin2hex($this->random->bytes(16));
        $records[] = new PreviewRecord(
            $recordId,
            $request->typeId,
            $current->revisionId ?? bin2hex($this->random->bytes(16)),
            $current->revisionNumber ?? 1,
            $request->body,
            $request->relationships,
            $current->media ?? [],
            // Shown as it will look once published, among the published site, so unrelated drafts cannot block it
            // unless nothing published can show it.
            'published',
        );
        return [$saved->with($records, false), $recordId];
    }

    /**
     * The record's own page in the chosen language, then the home page in that language.
     *
     * @param array<string, string> $routes
     * @return array<string, string>
     */
    private static function pages(ProfileSiteBuild $site, array $routes, string $recordId, LivePreviewRequest $request): array
    {
        $pages = [];
        $groups = ['record:' . $recordId, 'page:' . $request->typeId];
        foreach ($site->discovery?->paths() ?? [] as $path) {
            $route = $site->discovery->route($path);
            if ($route->locale === $request->locale && $route->path === $route->canonicalPath && in_array($route->translationGroup, $groups, true)) {
                $pages['record'] ??= $path;
            }
        }
        foreach (['/' . $request->locale . '/', '/'] as $home) {
            if (isset($routes[$home])) {
                $pages['home'] ??= $home;
            }
        }
        return $pages;
    }

    private static function routePath(string $route): string
    {
        return ltrim($route, '/') . 'index.html';
    }

    /** The engine bridge reports scrolling to the workspace; published bytes never carry it. */
    private static function withBridge(string $html, string $route): string
    {
        $tag = '<script src="' . ArtifactUrl::escaped('/' . self::BRIDGE_PATH, $route) . '" defer></script>';
        $position = stripos($html, '</head>');
        return $position === false ? $html . $tag : substr($html, 0, $position) . $tag . substr($html, $position);
    }

    private function seconds(): float
    {
        return $this->seconds === null ? hrtime(true) / 1e9 : ($this->seconds)();
    }
}
