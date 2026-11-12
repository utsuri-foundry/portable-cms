<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

/** Discovers a bounded batch on each qualified worker wake, including upgraded/restored media. */
final readonly class MariaDbRasterWidthQueue
{
    public function __construct(private \PDO $pdo) {}

    public function enqueue(\DateTimeImmutable $now): void
    {
        // The prepared image tells us the oriented axis even for legacy original metadata.
        $rows = $this->pdo->query(<<<'SQL'
SELECT m.id, m.owner_record_id, m.byte_size, m.declared_type, w.maximum_width
FROM cms_media m
JOIN cms_media_derivatives d ON d.media_id = m.id AND d.variant = '960-webp'
CROSS JOIN (SELECT 480 AS maximum_width UNION ALL SELECT 1600 UNION ALL SELECT 2400) w
WHERE m.status = 'ready' AND m.detected_type IN ('image/jpeg', 'image/png', 'image/webp')
  AND w.maximum_width <= CASE WHEN d.width < 960 THEN d.width
      WHEN d.width >= d.height THEN GREATEST(m.width, m.height) ELSE LEAST(m.width, m.height) END
  AND NOT EXISTS (SELECT 1 FROM cms_media_derivatives x
      WHERE x.media_id = m.id AND x.variant = CAST(CONCAT(w.maximum_width, '-webp') AS BINARY))
  AND NOT EXISTS (SELECT 1 FROM cms_jobs j
      WHERE j.id = CAST(MD5(CONCAT('raster-width-v1:', m.id, ':', w.maximum_width)) AS BINARY))
ORDER BY m.id, w.maximum_width
LIMIT 8
SQL)->fetchAll(\PDO::FETCH_ASSOC);
        $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO cms_jobs (id, job_type, status, payload_json, available_at, created_at, updated_at)
VALUES (:id, 'media_image_width', 'queued', :payload, :available, :created, :updated)
ON DUPLICATE KEY UPDATE id = id
SQL);
        $time = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        foreach ($rows as $row) {
            $width = (int) $row['maximum_width'];
            RasterWidths::variant($width);
            $id = (string) $row['id'];
            $insert->execute([
                'id' => md5('raster-width-v1:' . $id . ':' . $width),
                'payload' => json_encode(['formatVersion' => 1, 'mediaId' => $id, 'ownerRecordId' => $row['owner_record_id'], 'expectedByteSize' => (int) $row['byte_size'], 'declaredType' => (string) $row['declared_type'], 'maximumWidth' => $width], JSON_THROW_ON_ERROR),
                'available' => $time, 'created' => $time, 'updated' => $time,
            ]);
        }
    }
}
