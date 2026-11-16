<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Database;

use PDO;

final readonly class MariaDbWorkerQueue
{
    public function __construct(private PDO $pdo) {}

    /**
     * Waiting jobs include delayed retries, but exclude exhausted attempts and running jobs.
     * @return array{media: int, preview: int}
     */
    public function queued(): array
    {
        $counts = ['media' => 0, 'preview' => 0];
        $rows = $this->pdo->query(<<<'SQL'
SELECT job_type, COUNT(*) AS queued
FROM cms_jobs
WHERE job_type IN ('media_derivatives', 'media_image_width', 'preview_build')
  AND status IN ('queued', 'retry') AND attempts < max_attempts
GROUP BY job_type
SQL)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $lane = $row['job_type'] === 'preview_build' ? 'preview' : 'media';
            $counts[$lane] += (int) $row['queued'];
        }
        return $counts;
    }
}
