<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\HostIo;

final class PreviewBuildFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $codeName,
        public readonly bool $retryable,
        string $message,
        ?\Throwable $previous = null,
        public readonly ?PreviewDiagnostics $diagnostics = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}

final readonly class PreviewArtifactBody
{
    public function __construct(public string $contents, public string $mime, public ?\Utsuri\PortableCms\Conventional\Http\FileBody $file = null) {}

    public function response(?string $range = null, bool $sameOriginFrame = false): \Utsuri\PortableCms\Conventional\Http\Response
    {
        return $this->file === null
            ? (new \Utsuri\PortableCms\Conventional\Http\Response(200, $this->contents, sameOriginFrame: $sameOriginFrame))->withHeader('content-type', $this->mime)
            : \Utsuri\PortableCms\Conventional\Http\Response::file($this->file, $this->mime, $range, $sameOriginFrame);
    }
}

final class FilesystemPreviewStore
{
    private string $root;

    public function __construct(string $root)
    {
        if (!str_starts_with($root, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Preview storage root must be absolute');
        }
        if (is_link($root)) {
            throw new \RuntimeException('Preview storage root is unsafe');
        }
        if (!is_dir($root) && !HostIo::attempt(fn() => mkdir($root, 0700, true))->value && !is_dir($root)) {
            throw new \RuntimeException('Preview storage root could not be created');
        }
        HostIo::required(fn() => chmod($root, 0700));
        $resolved = realpath($root);
        if ($resolved === false || HostIo::attempt(fn() => is_link($root))->value || !is_writable($resolved)) {
            throw new \RuntimeException('Preview storage root is unsafe');
        }
        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function workingPath(PreviewJob $job): string
    {
        self::assertId($job->id);
        if (preg_match('/\A[a-f0-9]{64}\z/', $job->leaseToken) !== 1) {
            throw new \InvalidArgumentException('Preview lease token is invalid');
        }
        $work = $this->root . '/work';
        $this->ensureDirectory($work);
        $path = $work . '/' . $job->id . '-' . substr($job->leaseToken, 0, 16);
        if (file_exists($path) || is_link($path)) {
            $this->removeWorkingPath($path);
        }
        return $path;
    }

    public function cleanupFiles(): PreviewCleanupFiles
    {
        return new PreviewCleanupFiles($this->root, $this);
    }

    public function commit(PreviewJob $job, string $workingPath, PreviewBuild $build): void
    {
        self::assertId($job->previewId);
        $workPrefix = $this->root . '/work/';
        if (!str_starts_with($workingPath, $workPrefix) || !is_dir($workingPath) || is_link($workingPath)) {
            throw new PreviewBuildFailure('preview_work_missing', true, 'Preview work is unavailable.');
        }
        $artifacts = $this->root . '/artifacts';
        $this->ensureDirectory($artifacts);
        $destination = $artifacts . '/' . $job->previewId;
        if (is_dir($destination) && !is_link($destination)) {
            $this->verifyBuild($destination, $build);
            return;
        }
        if (file_exists($destination) || is_link($destination)) {
            throw new PreviewBuildFailure(
                'immutable_preview_conflict',
                false,
                'The immutable preview destination is unsafe.',
            );
        }
        if (!HostIo::attempt(fn() => rename($workingPath, $destination))->value) {
            throw new PreviewBuildFailure('preview_commit_failed', true, 'Preview artifacts could not be committed.');
        }
        HostIo::required(fn() => chmod($destination, 0700));
        try {
            $this->verifyBuild($destination, $build);
        } catch (\Throwable $error) {
            throw new PreviewBuildFailure(
                'preview_commit_verification_failed',
                false,
                'Committed preview artifacts failed verification.',
                $error,
            );
        }
    }

    public function artifact(string $previewId, string $path, PreviewBuild $build): ?PreviewArtifactBody
    {
        self::assertId($previewId);
        PreviewArtifact::assertPath($path);
        $metadata = null;
        foreach ($build->artifacts as $artifact) {
            if ($artifact->path === $path) {
                $metadata = $artifact;
                break;
            }
        }
        if ($metadata === null) {
            return null;
        }
        $root = $this->artifactRoot($previewId);
        $candidate = $root . '/' . $path;
        $cursor = $root;
        foreach (explode('/', $path) as $segment) {
            $cursor .= '/' . $segment;
            if (is_link($cursor)) {
                return null;
            }
        }
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved) || !str_starts_with($resolved, $root . '/')) {
            return null;
        }
        if (in_array($metadata->mime, ['video/mp4', 'video/webm'], true)) {
            try {
                return new PreviewArtifactBody('', $metadata->mime, \Utsuri\PortableCms\Conventional\Http\FileBody::verified($resolved, $metadata->byteSize, $metadata->checksum));
            } catch (\RuntimeException $error) {
                throw new PreviewBuildFailure('preview_artifact_verification_failed', false, 'Preview artifact failed immutable verification.', $error);
            }
        }
        $contents = HostIo::attempt(fn() => file_get_contents($resolved))->value;
        if (
            !is_string($contents)
            || strlen($contents) !== $metadata->byteSize
            || !hash_equals($metadata->checksum, hash('sha256', $contents))
        ) {
            throw new PreviewBuildFailure(
                'preview_artifact_verification_failed',
                false,
                'Preview artifact failed immutable verification.',
            );
        }
        return new PreviewArtifactBody($contents, $metadata->mime);
    }

    public function removeWorkingPath(string $workingPath): void
    {
        if (!str_starts_with($workingPath, $this->root . '/work/') || !is_dir($workingPath) || is_link($workingPath)) {
            return;
        }
        $this->removeTree($workingPath);
    }

    public function quarantine(string $previewId, string $token): ?string
    {
        self::assertId($previewId);
        if (preg_match('/\A[a-f0-9]{32}\z/', $token) !== 1) {
            throw new \InvalidArgumentException('Preview quarantine token is invalid');
        }
        $source = $this->root . '/artifacts/' . $previewId;
        if (is_link($this->root . '/artifacts') || is_link($source)) {
            throw new \RuntimeException('Preview artifact root is unsafe');
        }
        if (!file_exists($source)) {
            return null;
        }
        if (!is_dir($source)) {
            throw new \RuntimeException('Preview artifact root is unsafe');
        }
        $trash = $this->root . '/trash';
        $this->ensureDirectory($trash);
        $destination = $trash . '/' . $previewId . '-' . $token;
        if (file_exists($destination) || is_link($destination) || !HostIo::attempt(fn() => rename($source, $destination))->value) {
            throw new \RuntimeException('Preview artifact could not be quarantined');
        }
        return $destination;
    }

    public function restoreQuarantine(string $previewId, string $quarantined): void
    {
        self::assertId($previewId);
        if (
            !str_starts_with($quarantined, $this->root . '/trash/' . $previewId . '-')
            || !is_dir($quarantined)
            || is_link($quarantined)
        ) {
            throw new \RuntimeException('Preview quarantine path is invalid');
        }
        $destination = $this->root . '/artifacts/' . $previewId;
        if (is_link($this->root . '/artifacts') || is_link($destination) || file_exists($destination) || !HostIo::attempt(fn() => rename($quarantined, $destination))->value) {
            throw new \RuntimeException('Preview artifact quarantine could not be restored');
        }
    }

    public function purgeQuarantine(string $quarantined): void
    {
        if (!str_starts_with($quarantined, $this->root . '/trash/') || !is_dir($quarantined) || is_link($quarantined)) {
            throw new \RuntimeException('Preview quarantine path is invalid');
        }
        $this->removeTree($quarantined);
        if (file_exists($quarantined)) {
            throw new \RuntimeException('Preview quarantine could not be removed');
        }
    }

    private function verifyBuild(string $root, PreviewBuild $build): void
    {
        foreach ($build->artifacts as $artifact) {
            $candidate = $root . '/' . $artifact->path;
            if (!is_file($candidate) || is_link($candidate)) {
                throw new \RuntimeException('Preview artifact is missing or unsafe');
            }
            $size = HostIo::attempt(fn() => filesize($candidate))->value;
            $checksum = HostIo::attempt(fn() => hash_file('sha256', $candidate))->value;
            if (
                $size !== $artifact->byteSize
                || !is_string($checksum)
                || !hash_equals($artifact->checksum, $checksum)
            ) {
                throw new \RuntimeException('Preview artifact bytes do not match the manifest');
            }
        }
    }

    private function artifactRoot(string $previewId): string
    {
        $path = $this->root . '/artifacts/' . $previewId;
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved) || is_link($path)) {
            throw new PreviewBuildFailure('preview_artifact_missing', false, 'Preview artifact is unavailable.');
        }
        return $resolved;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!str_starts_with($directory, $this->root . '/')) {
            throw new \RuntimeException('Preview directory escaped its root');
        }
        if (is_link($directory)) {
            throw new \RuntimeException('Preview directory cannot be a symbolic link');
        }
        if (!is_dir($directory) && !HostIo::attempt(fn() => mkdir($directory, 0700, true))->value && !is_dir($directory)) {
            throw new \RuntimeException('Preview directory could not be created');
        }
        HostIo::required(fn() => chmod($directory, 0700));
    }

    private function removeTree(string $root): void
    {
        foreach (HostIo::attempt(fn() => scandir($root))->value ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                HostIo::cleanup(fn() => unlink($path));
            }
        }
        HostIo::cleanup(fn() => rmdir($root));
    }

    private static function assertId(string $id): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('Preview identity is invalid');
        }
    }
}
