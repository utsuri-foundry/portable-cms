<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;

/** Optional trusted builder declaration for guidance, not a replacement for preflight. */
interface PreviewStartupRequirements
{
    /** @return list<string> Singleton type IDs the builder already requires. */
    public function requiredSingletons(): array;
}

final readonly class PreviewSetupItem
{
    public function __construct(public string $typeId, public ?string $recordId) {}
}

final readonly class PreviewStartup
{
    /** @param list<string> $requiredSingletons */
    public function __construct(private ProfileManifest $profile, private array $requiredSingletons)
    {
        if (!array_is_list($requiredSingletons) || count($requiredSingletons) > 64 || count(array_unique($requiredSingletons)) !== count($requiredSingletons)) {
            throw new \InvalidArgumentException('Preview startup requirements are invalid');
        }
        foreach ($requiredSingletons as $id) {
            if (!is_string($id) || $profile->type($id)?->kind !== 'singleton') {
                throw new \InvalidArgumentException('Preview startup requires an installed singleton type');
            }
        }
    }

    /** @return list<PreviewSetupItem> */
    public function missing(ContentService $content): array
    {
        $items = [];
        foreach ($this->requiredSingletons as $id) {
            $record = $content->list($id)[0] ?? null;
            if ($record === null || $record->workflowState !== 'published') {
                $items[] = new PreviewSetupItem($id, $record?->id);
            }
        }
        return $items;
    }
}
