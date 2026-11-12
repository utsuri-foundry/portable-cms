<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;

final class MediaUploadService
{
    public function __construct(
        private readonly UploadRepository $repository,
        private readonly FilesystemUploadStorage $storage,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        private readonly bool $videoEnabled = false,
        private readonly VideoUploadPolicy $videoUploads = new VideoUploadPolicy(),
        private readonly ?\Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile = null,
    ) {}

    public function assertVideoField(string $sessionId, string $actorUserId, \Utsuri\PortableCms\Conventional\Content\FieldDefinition $field): void
    {
        $session = $this->owned($sessionId, $actorUserId, true);
        if ($session->status !== 'completed' || !str_starts_with($session->declaredType, 'video/')) {
            throw new \Utsuri\PortableCms\Conventional\I18n\InvalidInput('video.selection_invalid');
        }
        $completed = $this->repository->completed($sessionId);
        $checksum = $completed['media']['checksum'] ?? null;
        if (!is_string($checksum)) {
            throw new \Utsuri\PortableCms\Conventional\I18n\InvalidInput('video.facts_unavailable', ['field' => $field->label]);
        }
        \Utsuri\PortableCms\Conventional\Content\VideoLimits::assert($field, $session->byteSize, $this->storage->videoFacts($session, $checksum));
    }

    public function create(
        string $typeId,
        string $recordId,
        int $expectedRevision,
        string $filename,
        string $declaredType,
        int $byteSize,
        string $clientUploadId,
        string $actorUserId,
    ): UploadSession {
        return $this->start($typeId, $recordId, $expectedRevision, $filename, $declaredType, $byteSize, $clientUploadId, $actorUserId);
    }

    public function createWebsite(string $filename, string $declaredType, int $byteSize, string $clientUploadId, string $actorUserId): UploadSession
    {
        return $this->start(null, null, null, $filename, $declaredType, $byteSize, $clientUploadId, $actorUserId);
    }

    private function start(?string $typeId, ?string $recordId, ?int $expectedRevision, string $filename, string $declaredType, int $byteSize, string $clientUploadId, string $actorUserId): UploadSession
    {
        $website = $recordId === null;
        $maximum = $this->videoUploads->maximumBytes;
        if ($this->profile !== null && $typeId !== null) {
            $maximum = min($maximum, MediaContract::videoFieldMaximum($this->profile->type($typeId)->fields ?? []));
        }
        // An acknowledged session retains its original size even if its parts consume qualification headroom.
        $intent = MediaContract::uploadIntent($filename, $declaredType, $byteSize, !$website && $this->videoEnabled && PreencodedVideoProcessor::available(), VideoSupport::MAX_BYTES);
        $clientUploadId = MediaContract::clientUploadId($clientUploadId);
        $owner = $recordId === null ? null : $this->repository->ownerRecord($recordId);
        if (!$website && ($owner === null || $owner['typeId'] !== $typeId)) {
            throw new UploadFailure('record_not_found', 404, 'upload.error.the_target_record_was_not_found');
        }
        $existing = $this->repository->findByClientId($recordId, $actorUserId, $clientUploadId);
        if ($existing !== null) {
            $same = $existing->originalName === $intent['filename']
                && $existing->declaredType === $intent['declaredType']
                && $existing->byteSize === $intent['byteSize'];
            if (!$same) {
                throw new UploadFailure('client_upload_id_conflict', 409, 'upload.error.this_upload_id_belongs_to_another_file');
            }
            if ($existing->status === 'completed' || $existing->expiresAt > $this->clock->now()) {
                return $existing;
            }
            $this->storage->abort($existing->id);
            $this->repository->transition(
                $existing->id,
                ['uploading', 'completing'],
                'expired',
                $this->clock->now(),
                true,
            );
        }
        if (!$website && $owner['revisionNumber'] !== $expectedRevision) {
            throw new UploadFailure('revision_conflict', 409, 'upload.error.the_record_changed_before_the_upload_started');
        }
        MediaContract::uploadIntent($filename, $declaredType, $byteSize, !$website && $this->videoEnabled && $maximum > 0 && PreencodedVideoProcessor::available(), $maximum);
        $this->storage->assertHeadroom($intent['byteSize'], str_starts_with($declaredType, 'video/'));
        $partCount = (int) ceil($intent['byteSize'] / MediaContract::PART_BYTES);
        $id = bin2hex($this->random->bytes(16));
        $session = new UploadSession(
            $id,
            $clientUploadId,
            $recordId,
            $actorUserId,
            'uploading',
            'originals/' . $id . '/source',
            $intent['filename'],
            $intent['declaredType'],
            $intent['byteSize'],
            MediaContract::PART_BYTES,
            $partCount,
            $expectedRevision,
            null,
            null,
            $this->clock->now()->modify('+' . MediaContract::SESSION_TTL_SECONDS . ' seconds'),
            [],
        );
        $activeKey = hash('sha256', ($recordId ?? 'website') . "\0" . $actorUserId . "\0" . $clientUploadId, true);
        try {
            return $this->repository->create($session, $activeKey, $this->clock->now());
        } catch (\Throwable $error) {
            $raced = $this->repository->findByClientId($recordId, $actorUserId, $clientUploadId);
            if (
                $raced !== null
                && $raced->originalName === $intent['filename']
                && $raced->declaredType === $intent['declaredType']
                && $raced->byteSize === $intent['byteSize']
            ) {
                return $raced;
            }
            throw $error;
        }
    }

    public function status(string $sessionId, string $actorUserId): UploadSession
    {
        return $this->owned($sessionId, $actorUserId, true);
    }

    /** @param resource $body */
    public function uploadPart(
        string $sessionId,
        int $partNumber,
        int $contentLength,
        mixed $body,
        string $actorUserId,
    ): UploadSession {
        $session = $this->owned($sessionId, $actorUserId);
        if ($session->status !== 'uploading') {
            throw new UploadFailure('upload_not_writable', 409, 'upload.error.the_upload_no_longer_accepts_parts');
        }
        $expected = MediaContract::expectedPartSize($session, $partNumber);
        $this->storage->assertHeadroom($expected);
        if ($contentLength !== $expected) {
            throw new UploadFailure('invalid_part_size', 422, 'upload.error.the_upload_part_has_the_wrong_size');
        }
        $part = $this->storage->writePart($sessionId, $partNumber, $body, $expected);
        return $this->repository->recordPart($sessionId, $part, $this->clock->now());
    }

    /** @return array{session: UploadSession, media: array<string, mixed>, job: array<string, mixed>} */
    public function complete(string $sessionId, string $actorUserId, ?string $expectedChecksum = null): array
    {
        if ($expectedChecksum !== null && preg_match('/\A[a-f0-9]{64}\z/', $expectedChecksum) !== 1) {
            throw new UploadFailure('invalid_source_checksum', 422, 'upload.error.the_file_contents_do_not_match_the');
        }
        $session = $this->owned($sessionId, $actorUserId, true);
        if ($session->status === 'completed') {
            $result = $this->repository->completed($sessionId);
            $checksum = $result['media']['checksum'] ?? null;
            if ($expectedChecksum !== null && (!is_string($checksum) || !hash_equals($expectedChecksum, $checksum))) {
                throw new UploadFailure('source_integrity_mismatch', 422, 'upload.error.the_file_contents_do_not_match_the');
            }
            return $result;
        }
        if (!in_array($session->status, ['uploading', 'completing'], true)) {
            throw new UploadFailure('upload_state_conflict', 409, 'upload.error.the_upload_cannot_be_completed');
        }
        if (count($session->parts) !== $session->partCount) {
            throw new UploadFailure('upload_incomplete', 409, 'upload.error.the_upload_does_not_contain_every_required');
        }
        foreach ($session->parts as $index => $part) {
            $number = $index + 1;
            if ($part->partNumber !== $number || $part->byteSize !== MediaContract::expectedPartSize($session, $number)) {
                throw new UploadFailure('upload_incomplete', 409, 'upload.error.the_upload_does_not_contain_every_required');
            }
        }
        if ($session->status === 'uploading') {
            $session = $this->repository->transition(
                $session->id,
                ['uploading'],
                'completing',
                $this->clock->now(),
            );
        }
        $this->storage->assertHeadroom($session->byteSize, str_starts_with($session->declaredType, 'video/'), 1);
        $original = $this->storage->assemble($session, $this->videoUploads->requestTimeoutSeconds);
        if ($expectedChecksum !== null && !hash_equals($expectedChecksum, $original->checksum)) {
            throw new UploadFailure('source_integrity_mismatch', 422, 'upload.error.the_file_contents_do_not_match_the');
        }
        if (!MediaContract::signatureMatches($this->storage->originalPath($original), $session->declaredType)) {
            $this->storage->abort($session->id);
            $this->repository->transition(
                $session->id,
                ['completing'],
                'aborted',
                $this->clock->now(),
                true,
            );
            throw new UploadFailure('media_signature_mismatch', 422, 'upload.error.the_file_contents_do_not_match_the');
        }
        if (str_starts_with($session->declaredType, 'video/')) {
            try {
                PreencodedVideoProcessor::inspectFile($this->storage->originalPath($original), $session->declaredType, $session->byteSize);
            } catch (MediaProcessingFailure) {
                $this->storage->abort($session->id);
                $this->repository->transition($session->id, ['completing'], 'aborted', $this->clock->now(), true);
                throw new UploadFailure('invalid_preencoded_video', 422, 'video.invalid');
            }
        }
        $result = $this->repository->complete(
            $session->id,
            bin2hex($this->random->bytes(16)),
            bin2hex($this->random->bytes(16)),
            $original,
            bin2hex($this->random->bytes(16)),
            $this->clock->now(),
        );
        $this->storage->discardParts($session->id);
        return $result;
    }

    public function abort(string $sessionId, string $actorUserId): UploadSession
    {
        $session = $this->owned($sessionId, $actorUserId);
        $this->storage->abort($session->id);
        return $this->repository->transition(
            $session->id,
            ['uploading', 'completing'],
            'aborted',
            $this->clock->now(),
            true,
        );
    }

    private function owned(string $sessionId, string $actorUserId, bool $allowCompleted = false): UploadSession
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $sessionId) !== 1) {
            throw new UploadFailure('upload_not_found', 404, 'upload.error.the_upload_session_was_not_found');
        }
        $session = $this->repository->find($sessionId);
        if ($session === null) {
            throw new UploadFailure('upload_not_found', 404, 'upload.error.the_upload_session_was_not_found');
        }
        if ($session->actorUserId !== $actorUserId) {
            throw new UploadFailure('upload_forbidden', 403, 'upload.error.the_upload_session_belongs_to_another_editor');
        }
        if (in_array($session->status, ['uploading', 'completing'], true) && $session->expiresAt <= $this->clock->now()) {
            $this->storage->abort($session->id);
            $session = $this->repository->transition(
                $session->id,
                ['uploading', 'completing'],
                'expired',
                $this->clock->now(),
                true,
            );
        }
        if (!$allowCompleted && !in_array($session->status, ['uploading', 'completing'], true)) {
            throw new UploadFailure('upload_state_conflict', 409, 'upload.error.the_upload_is_no_longer_active');
        }
        return $session;
    }
}
