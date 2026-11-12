<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\SearchSharing;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Media\MediaText;

/** Validated per-route selections, never URLs or access to private storage. */
final readonly class SharingSelections
{
    /** @param array<string, ?string> $byRoute */
    private function __construct(public array $byRoute) {}

    /**
     * @param \Closure(string, string): SearchSharing $metadata Route and actual discovery locale.
     * @param null|\Closure(string, string): ?string $cover Optional profile-owned cover selection.
     */
    public static function resolve(FrozenSnapshot $snapshot, RouteDiscovery $routes, \Closure $metadata, ?string $defaultId = null, ?\Closure $cover = null): self
    {
        $selections = [];
        $failures = [];
        foreach ($routes->paths() as $path) {
            $locale = $routes->route($path)->locale;
            if (!in_array($locale, $snapshot->locales, true)) {
                throw new \InvalidArgumentException('Sharing route locale is not frozen');
            }
            $id = $metadata($path, $locale)->resolveImage($cover === null ? null : $cover($path, $locale), $defaultId)->mediaId;
            $media = $id === null ? null : $snapshot->media($id);
            if ($id !== null && ($media === null || !in_array($media->mime, ['image/jpeg', 'image/png', 'image/webp'], true))) {
                $failures[] = new ProfileBuildFailure(ProfileBuildReason::SharingImage, route: $path);
                continue;
            }
            if ($id !== null && !($media->text ?? MediaText::from())->publishable($locale, $snapshot->defaultLocale)) {
                throw new InvalidInput('preflight.issue.media_text', ['route' => $path, 'id' => substr($id, 0, 8), 'locale' => $locale]);
            }
            $selections[$path] = $id;
        }
        if ($failures !== []) {
            throw new ProfileBuildFailures(array_slice($failures, 0, 100));
        }
        return new self($selections);
    }

    /** Reads a bounded optional media field from a selected settings singleton. */
    public static function setting(PublishedRecords $records, string $typeId, string $fieldId, bool $required = false): ?string
    {
        return SearchSharing::from(['sharingImage' => $records->singleton($typeId, $required)?->body[$fieldId] ?? []])->sharingImageId;
    }

    /** @return list<string> */
    public function mediaIds(): array
    {
        $ids = array_values(array_unique(array_filter($this->byRoute, static fn(?string $id): bool => $id !== null)));
        sort($ids, SORT_STRING);
        return $ids;
    }

    public function image(string $path, SharingImages $images): ?SharingImage
    {
        if (!array_key_exists($path, $this->byRoute)) {
            throw new \InvalidArgumentException('Sharing route is unavailable');
        }
        return $images->image($this->byRoute[$path]);
    }
}
