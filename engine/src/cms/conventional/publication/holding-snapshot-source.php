<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Preview\PreviewMedia;
use Utsuri\PortableCms\Conventional\Preview\PreviewRecord;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;

/** Runs inside the build repository's repeatable-read snapshot transaction. */
final readonly class HoldingSnapshotSource
{
    public function __construct(private \PDO $pdo, private WebsitePublicationRepository $settings, private ?string $packageChecksum, private PublicUrlContext $context) {}

    /** @param list<string> $locales */
    public function freeze(string $profileId, string $version, array $locales, \DateTimeImmutable $now): PreviewSnapshot
    {
        $settings = $this->settings->load($profileId, $locales)->forWebsite();
        $settings->assertScope($profileId, $locales);
        if ($settings->mode() !== 'coming_soon') {
            throw new PublicationConflict();
        }
        $records = [];
        $websiteMedia = [];
        if (($id = $settings->document()['iconMediaId']) !== null) {
            $query = $this->pdo->prepare("SELECT m.id, m.owner_record_id, m.status, d.object_key, d.filename, d.mime, d.byte_size, d.checksum, d.width, d.height, r.type_id, r.current_revision_id, r.current_revision_number FROM cms_media m JOIN cms_media_derivatives d ON d.media_id = m.id AND d.variant = '960-webp' LEFT JOIN cms_records r ON r.id = m.owner_record_id WHERE m.id = :id");
            $query->execute(['id' => $id]);
            $row = $query->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row) || !\Utsuri\PortableCms\Conventional\Content\WebsiteIconSelection::eligible((string) $row['status'], (string) $row['mime'], (int) $row['width'], (int) $row['height'])) {
                throw new PublicationFailure('holding_icon_not_ready');
            }
            $media = new PreviewMedia($id, (string) $row['object_key'], (string) $row['filename'], (string) $row['mime'], (int) $row['byte_size'], (string) $row['checksum'], (int) $row['width'], (int) $row['height']);
            if ($row['owner_record_id'] === null) {
                $websiteMedia[] = $media;
            } else {
                $records[] = new PreviewRecord((string) $row['owner_record_id'], (string) $row['type_id'], (string) $row['current_revision_id'], (int) $row['current_revision_number'], [], [], [$media]);
            }
        }
        // The holding page shows its message in the public languages; the primary one is the document language.
        $languages = (new \Utsuri\PortableCms\Conventional\Content\MariaDbSiteLanguages($this->pdo))->scoped($profileId, $locales);
        $public = $languages?->public() ?? $locales;
        return new PreviewSnapshot($profileId, $version, $public, $records, $now, profileChecksum: $this->packageChecksum, publicUrlContext: $this->context, holdingPage: $settings->only($public), defaultLocale: $languages?->primary, websiteMedia: $websiteMedia);
    }
}
