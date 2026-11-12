<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\Identity\Clock;

final readonly class PreviewCleanupReview
{
    /**
     * @param array<string, PreviewCleanupItem|null> $items
     * @param list<string> $approved
     * @param list<string> $recover
     */
    public function __construct(public array $items, public array $approved, public string $token, public array $recover = []) {}
}

final readonly class PreviewCleanupResult
{
    /** @param array<string, string> $outcomes */
    public function __construct(public array $outcomes, public ?string $retry) {}
}

final readonly class PreviewCleanupService
{
    public function __construct(private PreviewCleanupRepository $repository, private PreviewDeletion $deletion, private PreviewSelection $selection, private Clock $clock) {}

    public function page(string $cursor): PreviewCleanupPage
    {
        $items = $this->repository->cleanupPage(PreviewCursor::from($cursor), PreviewSelection::LIMIT + 1, $this->clock->now());
        $more = count($items) > PreviewSelection::LIMIT;
        $items = array_slice($items, 0, PreviewSelection::LIMIT);
        return new PreviewCleanupPage($items, $more ? PreviewCursor::after($items[count($items) - 1]->preview) : null);
    }

    public function pageToken(PreviewCleanupPage $page, string $actor): ?string
    {
        $ids = array_values(array_map(static fn(PreviewCleanupItem $item): string => $item->preview->id, array_filter($page->items, static fn(PreviewCleanupItem $item): bool => $item->eligible())));
        return $ids === [] ? null : $this->selection->issue($ids, $ids, $actor, 'page');
    }

    /** @param list<string> $ids */
    public function review(array $ids, string $actor, ?string $pageToken = null): PreviewCleanupReview
    {
        $ids = $pageToken === null ? PreviewSelection::ids($ids) : $this->selection->read($pageToken, $actor, 'page')['ids'];
        $items = [];
        $approved = [];
        $recover = [];
        $pending = $this->deletion->pending();
        foreach ($ids as $id) {
            $item = $this->repository->cleanupItem($id, $this->clock->now());
            $items[$id] = $item;
            if ($item?->eligible()) {
                $approved[] = $id;
            }
            if (in_array($id, $pending, true)) {
                $recover[] = $id;
            }
        }
        return new PreviewCleanupReview($items, $approved, $this->selection->issue($ids, $approved, $actor, 'confirm', $recover), $recover);
    }

    public function confirm(string $token, string $actor, ?PreviewCleanupBudget $budget = null): PreviewCleanupResult
    {
        $selection = $this->selection->read($token, $actor, 'confirm');
        $budget ??= new PreviewCleanupBudget($this->clock);
        $outcomes = [];
        $retry = [];
        foreach ($selection['ids'] as $id) {
            $approved = in_array($id, $selection['approved'], true);
            if (!$approved && !in_array($id, $selection['recover'], true)) {
                $outcomes[$id] = $this->repository->find($id) === null ? 'absent' : 'protected';
                continue;
            }
            try {
                $outcomes[$id] = $approved ? $this->deletion->remove($id, $actor, $budget) : $this->deletion->restoreOnly($id, $budget);
            } catch (\Throwable) {
                // Never render host paths, database diagnostics or secrets to an editor.
                HostIo::recordFailure('cleanup_failed');
                $outcomes[$id] = 'failed';
            }
            if (in_array($outcomes[$id], ['failed', 'cleanup_pending', 'unprocessed'], true)) {
                $retry[] = $id;
            }
        }
        return new PreviewCleanupResult($outcomes, $retry === [] ? null : $this->selection->issue($retry, array_values(array_intersect($retry, $selection['approved'])), $actor, 'confirm', array_values(array_intersect($retry, $selection['recover']))));
    }

    public function retrySingle(string $id, string $actor, string $outcome): PreviewCleanupResult
    {
        if (!in_array($outcome, ['unprocessed', 'cleanup_pending'], true)) {
            throw new PreviewSelectionInvalid();
        }
        return new PreviewCleanupResult([$id => $outcome], $this->selection->issue([$id], [$id], $actor, 'confirm'));
    }

    public function recoveryToken(string $actor): ?string
    {
        $ids = $this->deletion->pending();
        return $ids === [] ? null : $this->selection->issue($ids, $ids, $actor, 'page');
    }
}
