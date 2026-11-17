<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\SharedLinksView;
use Utsuri\PortableCms\Conventional\View\TrustedHtml;

/** One shared link as the Previews page lists it, whichever kind it is. */
final readonly class SharedLink
{
    public function __construct(
        public string $kind,
        public string $id,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public ?string $note,
        public ?string $createdBy,
        public ?string $previewId,
        public ?string $url,
    ) {
        if (!in_array($kind, ['preview', 'newest'], true) || ($kind === 'preview') !== ($previewId !== null)) {
            throw new \InvalidArgumentException('Shared link is invalid');
        }
    }

    public function expired(?\DateTimeImmutable $now): bool
    {
        return $now !== null && $this->expiresAt <= $now;
    }

    public static function ofShare(PreviewShare $share, ?string $url): self
    {
        return new self('preview', $share->id, $share->createdAt, $share->expiresAt, $share->note, $share->createdBy, $share->previewId, $url);
    }

    public static function ofNewest(NewestPreviewLink $link, ?string $url): self
    {
        return new self('newest', $link->id, $link->createdAt, $link->expiresAt, $link->note, $link->createdBy, null, $url);
    }
}

/**
 * Sharing as editors meet it: a Share panel on a ready preview and one Shared links list under the
 * previews, whatever kind the link is. The caller has already checked the session, origin and CSRF.
 */
final class PreviewSharingHttp
{
    public function __construct(
        private readonly PreviewShareService $shares,
        private readonly NewestPreviewLinkService $newest,
        private readonly PreviewService $previews,
        private readonly string $origin,
        public readonly \DateTimeZone $timeZone,
    ) {}

    /** @return list<SharedLink> Newest first across both kinds. */
    public function links(): array
    {
        $links = [];
        foreach ($this->shares->listed() as $share) {
            $links[] = SharedLink::ofShare($share, $this->shares->url($share, $this->origin));
        }
        foreach ($this->newest->listed() as $link) {
            $links[] = SharedLink::ofNewest($link, $this->newest->url($link, $this->origin));
        }
        usort($links, static fn(SharedLink $a, SharedLink $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);
        return $links;
    }

    /** The Share panel for a ready website preview; nothing for any other build. */
    public function panel(PreviewStatus $preview, string $csrf, AdminChrome $chrome): string
    {
        if ($preview->status !== 'ready' || $preview->buildKind !== 'website') {
            return '';
        }
        return SharedLinksView::panel($preview, $csrf, $chrome);
    }

    /** @param \Closure(string): ?string $actorName */
    public function section(string $csrf, AdminChrome $chrome, ?\Closure $actorName, ?SharedLink $created = null): TrustedHtml
    {
        $times = [];
        foreach ($this->previews->list() as $preview) {
            $times[$preview->id] = $preview->createdAt;
        }
        $previewTime = function (string $previewId) use (&$times): ?\DateTimeImmutable {
            if (!array_key_exists($previewId, $times)) {
                $times[$previewId] = $this->previews->find($previewId)?->createdAt;
            }
            return $times[$previewId];
        };
        return TrustedHtml::fromEngineMarkup(SharedLinksView::section($this->links(), $created, $csrf, $chrome, $this->timeZone, $actorName, $previewTime));
    }

    /**
     * POST /previews/shares creates a link and renders the page with it; extend and stop return to the list.
     * @param \Closure(SharedLink): Response $page
     */
    public function manage(Request $request, string $actor, \Closure $page): Response
    {
        try {
            if ($request->path === '/previews/shares') {
                return $page($this->create($request, $actor));
            }
            if (preg_match('#\A/previews/shares/(preview|newest)/([a-f0-9]{32})/(extend|stop)\z#', $request->path, $match) !== 1) {
                return new Response(404);
            }
            [, $kind, $id, $action] = $match;
            if ($action === 'stop') {
                $kind === 'preview' ? $this->shares->revoke($id, $actor) : $this->newest->revoke($id, $actor);
            } else {
                $days = self::days($request);
                $kind === 'preview' ? $this->shares->extend($id, $actor, $days) : $this->newest->extend($id, $actor, $days);
            }
            return Response::redirect('/previews#shared-links');
        } catch (InvalidShareExpiry | InvalidShareNote) {
            return new Response(422);
        } catch (ShareUnavailable) {
            return new Response($request->path === '/previews/shares' ? 409 : 404);
        }
    }

    private function create(Request $request, string $actor): SharedLink
    {
        $kind = $request->formValue('kind');
        if (!in_array($kind, ['preview', 'newest'], true)) {
            throw new InvalidShareExpiry();
        }
        $days = self::days($request);
        $note = SharedLinkNote::normalize($request->formValue('note'));
        if ($kind === 'newest') {
            $created = $this->newest->create($actor, $days, $note);
            return SharedLink::ofNewest($created['link'], $this->origin . '/newest-previews/' . $created['link']->id . '/#' . $created['token']);
        }
        $previewId = $request->formValue('preview');
        $preview = preg_match('/\A[a-f0-9]{32}\z/', $previewId) === 1 ? $this->previews->find($previewId) : null;
        if ($preview === null || $preview->status !== 'ready' || $preview->buildKind !== 'website') {
            throw new ShareUnavailable();
        }
        $created = $this->shares->create($previewId, $actor, $days, $note);
        return SharedLink::ofShare($created['share'], $this->origin . '/shared-previews/' . $created['share']->id . '/#' . $created['token']);
    }

    private static function days(Request $request): int
    {
        $days = $request->formValue('days');
        if (preg_match('/\A[0-9]{1,2}\z/', $days) !== 1 || !in_array((int) $days, SharedLinkLifetime::DAYS, true)) {
            throw new InvalidShareExpiry();
        }
        return (int) $days;
    }
}
