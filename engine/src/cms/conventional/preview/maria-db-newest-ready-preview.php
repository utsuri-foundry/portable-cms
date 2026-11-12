<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

/** One ordering and ready-website boundary for public capabilities and signed-in editors. */
final readonly class MariaDbNewestReadyPreview
{
    public function __construct(private \PDO $pdo) {}

    public function id(?bool $includeDrafts = null, bool $lock = false): ?string
    {
        $query = $this->pdo->prepare("SELECT id FROM cms_previews WHERE build_kind = 'website' AND status = 'ready'"
            . ($includeDrafts === null ? '' : ' AND include_drafts = :drafts')
            . ' ORDER BY created_at DESC, id DESC LIMIT 1' . ($lock ? ' LOCK IN SHARE MODE' : ''));
        $query->execute($includeDrafts === null ? [] : ['drafts' => $includeDrafts ? 1 : 0]);
        $id = $query->fetchColumn();
        return is_string($id) ? $id : null;
    }
}
