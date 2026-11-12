<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Export;

use PDO;
use Throwable;

final class MariaDbExportRepository implements ExportRepository, ExportCapacityRepository
{
    public function __construct(private readonly PDO $pdo, private readonly ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $designDefaults = null, private readonly ?\Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext $publicUrlContext = null, private readonly ?\Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory $publishedSlugHistory = null, private readonly ?\Utsuri\PortableCms\Conventional\Content\ProfileManifest $wordingProfile = null)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function create(
        string $exportId,
        string $auditId,
        string $actorUserId,
        string $profileId,
        string $profileVersion,
        array $locales,
        \DateTimeImmutable $now,
    ): NormalizedExport {
        foreach ([$exportId, $auditId, $actorUserId] as $id) {
            self::assertId($id);
        }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();
        try {
            $capacity = $this->capacity();
            if ($capacity->chunkedRequired()) {
                throw new ExportCapacityFailure('legacy_export_requires_private_package');
            }
            ExportMemory::legacy($capacity->legacyMemoryBytes());
            $reader = new MariaDbExportRows($this->pdo);
            $records = iterator_to_array($reader->rows('records', EXPORT_MAX_RECORDS), false);
            $revisions = [];
            foreach ($reader->rows('revisions', EXPORT_MAX_REVISIONS) as $revision) {
                ExportCapacity::requireLegacyRevision($revision);
                $revisions[] = $revision;
            }
            $relationships = iterator_to_array($reader->rows('relationships', EXPORT_MAX_RELATIONSHIPS), false);
            $media = iterator_to_array($reader->rows('media', EXPORT_MAX_MEDIA), false);
            $document = $this->metadata($exportId, $profileId, $profileVersion, $locales, $now) + ['records' => $records, 'revisions' => $revisions, 'relationships' => $relationships, 'media' => $media];
            $export = new NormalizedExport($document);
            $export->json();
            $audit = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at)
VALUES (:id, :actor, 'normalized_export_created', :metadata, :occurred_at)
SQL);
            $audit->execute([
                'id' => $auditId,
                'actor' => $actorUserId,
                'metadata' => json_encode([
                    'exportId' => $exportId,
                    'profileId' => $profileId,
                    'profileVersion' => $profileVersion,
                    'recordCount' => count($records),
                    'revisionCount' => count($revisions),
                    'relationshipCount' => count($relationships),
                    'mediaCount' => count($media),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'occurred_at' => self::databaseTime($now),
            ]);
            $this->pdo->commit();
            return $export;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param list<string> $locales
     * @return array<string, mixed>
     */
    public function metadata(string $exportId, string $profileId, string $profileVersion, array $locales, \DateTimeImmutable $now): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Export metadata requires its owning snapshot');
        }
        $document = [
            'formatVersion' => 1,
            'siteSequence' => (new \Utsuri\PortableCms\Conventional\Database\MariaDbSiteSequence($this->pdo))->current(),
            'exportId' => $exportId,
            'createdAt' => self::timestamp($now),
            'profile' => [
                'id' => $profileId,
                'version' => $profileVersion,
                'locales' => array_values($locales),
            ],
            'websitePublication' => (new \Utsuri\PortableCms\Conventional\Publication\MariaDbWebsitePublication($this->pdo))->load($profileId, $locales)->document(),
        ];
        if ($this->publicUrlContext !== null) {
            $document['sourcePublicUrlContext'] = $this->publicUrlContext->document();
        }
        $history = $this->publishedSlugHistory ?? \Utsuri\PortableCms\Conventional\Publication\MariaDbPublicationRepository::history($this->pdo);
        if ($history !== null) {
            $document['sourcePublicUrlContext'] = $history->context->document();
            $document['publishedSlugHistory'] = $history->document();
        }
        if ($this->publicUrlContext === null && $this->publishedSlugHistory === null) {
            $provenanceJson = $this->pdo->query("SELECT provenance_json FROM cms_restore_state WHERE singleton_id = 1 AND status = 'completed'")->fetchColumn();
            if (is_string($provenanceJson)) {
                $provenance = json_decode($provenanceJson, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($provenance)) {
                    $document += array_intersect_key($provenance, array_flip(['sourcePublicUrlContext', 'publishedSlugHistory']));
                }
            }
        }
        $terminology = (new \Utsuri\PortableCms\Conventional\Content\MariaDbTerminologyRepository($this->pdo))->load($profileId);
        if ($terminology->revision > 0) {
            $document['editorialTerminology'] = $terminology->document();
        }
        $languagesQuery = $this->pdo->prepare('SELECT document_json FROM cms_site_languages WHERE profile_id = :profile');
        $languagesQuery->execute(['profile' => $profileId]);
        $languagesJson = $languagesQuery->fetchColumn();
        if (is_string($languagesJson)) {
            $document['siteLanguages'] = \Utsuri\PortableCms\Conventional\Content\SiteLanguages::scoped(json_decode($languagesJson, true, 8, JSON_THROW_ON_ERROR), $profileId, array_values($locales))->document();
        }
        $wordingQuery = $this->pdo->prepare('SELECT document_json FROM cms_site_wording WHERE profile_id = :profile');
        $wordingQuery->execute(['profile' => $profileId]);
        $wordingJson = $wordingQuery->fetchColumn();
        if (is_string($wordingJson)) {
            // Every saved text the installed profile still declares, also in languages that are off.
            $wording = json_decode($wordingJson, true, 8, JSON_THROW_ON_ERROR);
            $document['siteWording'] = $this->wordingProfile === null ? $wording : \Utsuri\PortableCms\Conventional\Content\SiteWording::stored($wording, $this->wordingProfile)->document();
        }
        $designQuery = $this->pdo->prepare('SELECT document_json FROM cms_design_registries WHERE profile_id = :profile');
        $designQuery->execute(['profile' => $profileId]);
        $designJson = $designQuery->fetchColumn();
        if ($this->designDefaults !== null) {
            $document['designRegistries'] = $this->designDefaults->document();
        }
        if (is_string($designJson)) {
            $document['designRegistries'] = \Utsuri\PortableCms\Conventional\Design\RegistryDocument::fromDocument(json_decode($designJson, true, 32, JSON_THROW_ON_ERROR))->document();
        }
        return $document;
    }

    /** Capture content once. Immutable media named by it are packaged after releasing the snapshot.
     * @param list<string> $locales
     * @return array<string, mixed>
     */
    public function capture(ChunkedExportWriter $writer, string $exportId, string $auditId, string $actorId, string $profileId, string $profileVersion, array $locales, \DateTimeImmutable $now, ExportBudget $budget = new ExportBudget()): array
    {
        ExportMemory::chunked();
        foreach ([$exportId, $auditId, $actorId] as $id) {
            self::assertId($id);
        }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();
        try {
            // Reject a single oversized body before buffering even one page of source revisions.
            $capacity = $this->capacity();
            foreach ($capacity->counts as $kind => $count) {
                if ($count > ExportCapacity::limit($kind)) {
                    throw new \Utsuri\PortableCms\Conventional\Restore\RestoreRejected('chunked_export_capacity_exceeded');
                }
            }
            if ($capacity->payloadBytes() > ExportCapacity::PACKAGE_BYTES) {
                throw new \Utsuri\PortableCms\Conventional\Restore\RestoreRejected('export_byte_capacity_exceeded');
            }
            if ($this->pdo->query("SELECT 1 FROM cms_media WHERE status NOT IN ('ready', 'needs_attention') LIMIT 1")->fetchColumn() !== false) {
                throw new \Utsuri\PortableCms\Conventional\Restore\RestoreRejected('finish_media_processing_before_export');
            }
            $size = $this->pdo->query('SELECT COALESCE(MAX(OCTET_LENGTH(body_json)), 0) FROM cms_record_revisions')->fetchColumn();
            if ((int) $size >= ExportCapacity::ROW_BYTES) {
                throw new \Utsuri\PortableCms\Conventional\Restore\RestoreRejected('export_revision_body_capacity_exceeded');
            }
            $derivatives = $this->pdo->query('SELECT COALESCE(MAX(bytes), 0) FROM (SELECT SUM(OCTET_LENGTH(parameters_json) + 1024) AS bytes FROM cms_media_derivatives GROUP BY media_id) AS media_recipe_sizes')->fetchColumn();
            if ((int) $derivatives >= ExportCapacity::ROW_BYTES) {
                throw new \Utsuri\PortableCms\Conventional\Restore\RestoreRejected('export_media_recipe_capacity_exceeded');
            }
            $reader = new MariaDbExportRows($this->pdo, $budget);
            $header = $this->metadata($exportId, $profileId, $profileVersion, $locales, $now);
            $manifest = $writer->capture($header, fn(string $kind): iterable => $reader->rows($kind, ExportCapacity::limit($kind)));
            $budget->check();
            $audit = $this->pdo->prepare("INSERT INTO cms_audit_events (id, actor_user_id, event_type, metadata_json, occurred_at) VALUES (:id, :actor, 'chunked_export_captured', :metadata, :at)");
            $counts = [];
            foreach ($manifest['collections'] as $kind => $chunks) {
                $counts[$kind] = array_sum(array_column($chunks, 'rowCount'));
            }
            $audit->execute(['id' => $auditId, 'actor' => $actorId, 'metadata' => json_encode(['exportId' => $exportId, 'counts' => $counts], JSON_THROW_ON_ERROR), 'at' => self::databaseTime($now)]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        $writer->sealCapture($manifest);
        return $manifest;
    }

    private static function assertId(string $id): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('Normalized export identity is invalid');
        }
    }

    public function capacity(): ExportCapacityStatus
    {
        $counts = [];
        foreach (['records' => 'cms_records', 'revisions' => 'cms_record_revisions', 'relationships' => 'cms_record_relationship_index', 'media' => 'cms_media'] as $kind => $table) {
            $counts[$kind] = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }
        $facts = $this->pdo->query('SELECT COALESCE(SUM(OCTET_LENGTH(body_json)), 0) AS total, COALESCE(MAX(OCTET_LENGTH(body_json)), 0) AS largest FROM cms_record_revisions')->fetch(PDO::FETCH_ASSOC);
        $current = (int) $this->pdo->query('SELECT COALESCE(SUM(OCTET_LENGTH(v.body_json)), 0) FROM cms_records r JOIN cms_record_revisions v ON v.id = r.current_revision_id')->fetchColumn();
        $media = (int) $this->pdo->query('SELECT COALESCE(SUM(OCTET_LENGTH(text_json)), 0) FROM cms_media')->fetchColumn();
        $derivatives = $this->pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(OCTET_LENGTH(parameters_json)), 0) AS bytes FROM cms_media_derivatives')->fetch(PDO::FETCH_ASSOC);
        return new ExportCapacityStatus($counts, (int) $facts['total'], (int) $facts['largest'], $current, $media + (int) $derivatives['bytes'], (int) $derivatives['total']);
    }

    private static function databaseTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function timestamp(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

}
