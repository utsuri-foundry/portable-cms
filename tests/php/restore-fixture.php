<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfilePackage;

/** Only fictional fixtures. The unsupported video reference is deliberately absent. */
final class RestoreTestFixture
{
    /** @return array<string, mixed> */
    public static function document(ProfilePackage $package): array
    {
        $snapshot = json_decode((string) file_get_contents($package->root . '/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
        $actor = str_repeat('a', 32);
        $time = '2026-10-04T09:00:00.000000Z';
        $result = ['formatVersion' => 1, 'exportId' => str_repeat('b', 32), 'createdAt' => $time,
            'profile' => ['id' => $package->manifest->id, 'version' => $package->manifest->version, 'locales' => $package->manifest->locales],
            'records' => [], 'revisions' => [], 'relationships' => [], 'media' => []];
        foreach ($snapshot['records'] as $record) {
            $body = $record['body'];
            if (array_key_exists('trailer', $body)) {
                $body['trailer'] = null;
            }
            if ($record['typeId'] === 'project' && $record['workflowState'] === 'draft') {
                $body['title']['en'] = 'Light Workshop';
            }
            $result['records'][] = ['id' => $record['id'], 'typeId' => $record['typeId'], 'workflowState' => $record['workflowState'], 'currentRevisionId' => $record['revisionId'], 'currentRevisionNumber' => 1, 'createdBy' => $actor, 'createdAt' => $time, 'updatedBy' => $actor, 'updatedAt' => $time];
            $result['revisions'][] = ['id' => $record['revisionId'], 'recordId' => $record['id'], 'revisionNumber' => 1, 'profileId' => $package->manifest->id, 'profileVersion' => $package->manifest->version, 'body' => $body, 'createdBy' => $actor, 'createdAt' => $time];
            foreach ($record['relationships'] as $relationship) {
                unset($relationship['targetTypeId']);
                $result['relationships'][] = ['recordId' => $record['id'], 'revisionId' => $record['revisionId'], ...$relationship];
            }
        }
        foreach ($snapshot['media'] as $media) {
            if (!str_starts_with($media['mime'], 'image/')) {
                continue;
            }
            $facts = ['mime' => $media['mime'], 'byteSize' => $media['byteSize'], 'sha256' => $media['sha256']];
            $result['media'][] = ['id' => $media['id'], 'ownerRecordId' => $media['ownerRecordId'], 'status' => 'ready', 'originalName' => 'Fixture.webp', 'declaredType' => $media['mime'], 'detectedType' => $media['mime'], 'width' => $media['width'], 'height' => $media['height'], 'original' => $facts,
                'derivatives' => [['variant' => '960-webp', 'fileName' => $media['id'] . '-960-webp.webp', ...$facts, 'width' => $media['width'], 'height' => $media['height'], 'processorId' => 'fixture', 'processorVersion' => '1', 'recipeVersion' => 1, 'parameters' => ['test' => true], 'createdAt' => $time]], 'createdBy' => $actor, 'createdAt' => $time, 'updatedAt' => $time];
        }
        return $result;
    }

    public static function remove(string $path): void
    {
        foreach (new DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isDir() && !$entry->isLink()) {
                self::remove($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($path);
    }
}
