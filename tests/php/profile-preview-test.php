<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\ArtifactUrl;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\Preview\PreviewBuildFailure;
use Utsuri\PortableCms\Conventional\Preview\PreviewJob;
use Utsuri\PortableCms\Conventional\Preview\PreviewMedia;
use Utsuri\PortableCms\Conventional\Preview\PreviewPreflightFailure;
use Utsuri\PortableCms\Conventional\Preview\PreviewRecord;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;

/** @return array{root: string, snapshot: PreviewSnapshot, compiler: ProfilePreviewCompiler, package: ProfilePackage} */
function profilePreviewFixture(): array
{
    $fixture = staticCompilerFixture();
    $root = staticCompilerTempRoot();
    $media = [];
    foreach ($fixture['snapshot']->media as $asset) {
        $name = $asset->id . '-960-webp.webp';
        $key = 'derivatives/' . $asset->id . '/' . $name;
        $path = $root . '/media/' . $key;
        if (!mkdir(dirname($path), 0700, true) || !copy($fixture['media']->file($asset), $path)) {
            throw new RuntimeException('Profile preview media fixture failed');
        }
        $media[$asset->ownerRecordId][] = new PreviewMedia($asset->id, $key, $name, $asset->mime, $asset->byteSize, $asset->checksum, $asset->width ?? 1, $asset->height ?? 1, $asset->text);
    }
    $records = [];
    foreach ($fixture['snapshot']->records as $record) {
        $relations = [];
        foreach ($record->relationships as $relation) {
            $relations[$relation->fieldId][] = $relation->targetRecordId;
        }
        $records[] = new PreviewRecord($record->id, $record->typeId, $record->revisionId, $record->revisionNumber, $record->body, $relations, $media[$record->id] ?? [], $record->workflowState);
    }
    $package = $fixture['package'];
    $snapshot = new PreviewSnapshot($package->manifest->id, $package->manifest->version, $package->manifest->locales, $records, new DateTimeImmutable('2026-10-03T12:00:00Z'), $fixture['snapshot']->designRegistries, $package->checksum);
    $compiler = new ProfilePreviewCompiler($package, $root . '/media', dirname(__DIR__, 2) . '/schemas');
    return compact('root', 'snapshot', 'compiler', 'package');
}

$tests['profile preview compiles the frozen website and serves unchanged verified bytes'] = static function (): void {
    $fixture = profilePreviewFixture();
    try {
        $fixture['compiler']->preflight($fixture['snapshot']);
        $store = new FilesystemPreviewStore($fixture['root'] . '/previews');
        $job = new PreviewJob(str_repeat('1', 32), str_repeat('2', 32), 1, 3, str_repeat('3', 64), new DateTimeImmutable('2026-10-03T12:15:00Z'));
        $output = $store->workingPath($job);
        $build = $fixture['compiler']->compile($fixture['snapshot'], $output);
        $store->commit($job, $output, $build);
        assertSameValue(79, count($build->artifacts), 'a legacy preview includes real italic, speculation rules, collage media and robots but cannot fabricate a sitemap');
        foreach ($build->artifacts as $artifact) {
            $body = $store->artifact($job->previewId, $artifact->path, $build);
            assertTrueValue($body !== null, 'every declared file should be privately readable');
            assertSameValue($artifact->checksum, hash('sha256', $body->contents), 'serving must not rewrite artifact bytes');
        }
        $home = $store->artifact($job->previewId, 'index.html', $build);
        assertTrueValue(str_contains($home->contents ?? '', 'href="./assets/site.css"'), 'root assets must resolve beneath the preview mount');
        $page = $store->artifact($job->previewId, 'en/projects/archive-of-mist/index.html', $build);
        assertTrueValue(str_contains($page->contents ?? '', 'href="../../../assets/site.css"'), 'deep pages must remain mount independent');
        $css = $store->artifact($job->previewId, 'assets/design.css', $build);
        assertTrueValue(str_contains($css->contents ?? '', 'url("../assets/'), 'frozen fonts must resolve relative to the stylesheet');
        $frozen = $fixture['compiler']->freeze($fixture['snapshot']);
        assertSameValue('draft', $frozen->record('10000000000000000000000000000002')?->workflowState, 'a preview must not silently publish incomplete drafts');
    } finally {
        staticCompilerRemoveTree($fixture['root']);
    }
};

$tests['profile preview preflight reports missing translations and rejects package drift'] = static function (): void {
    $fixture = profilePreviewFixture();
    try {
        $records = $fixture['snapshot']->records;
        foreach ($records as $index => $record) {
            if ($record->typeId === 'project' && $record->workflowState === 'published') {
                $body = $record->body;
                $body['title']['en'] = '';
                $records[$index] = new PreviewRecord($record->id, $record->typeId, $record->revisionId, $record->revisionNumber, $body, $record->relationships, $record->media, $record->workflowState);
                break;
            }
        }
        $snapshot = $fixture['snapshot'];
        try {
            $fixture['compiler']->preflight(new PreviewSnapshot($snapshot->profileId, $snapshot->profileVersion, $snapshot->locales, $records, $snapshot->createdAt, $snapshot->designRegistries, $snapshot->profileChecksum));
            throw new RuntimeException('Missing translation unexpectedly passed preflight');
        } catch (PreviewPreflightFailure $failure) {
            assertTrueValue(str_starts_with($failure->links[0], '/records/project/'), 'already checked required field links to its exact record');
            assertTrueValue(str_contains(implode(' ', array_map(static fn(\Utsuri\PortableCms\Conventional\I18n\Message $issue): string => \Utsuri\PortableCms\Conventional\I18n\Translator::english()->message($issue), $failure->issues)), 'Title in en'), 'preflight should identify the field and language');
        }
        try {
            $fixture['compiler']->freeze(new PreviewSnapshot($snapshot->profileId, $snapshot->profileVersion, $snapshot->locales, $snapshot->records, $snapshot->createdAt, $snapshot->designRegistries, str_repeat('0', 64)));
            throw new RuntimeException('Changed profile unexpectedly compiled');
        } catch (PreviewBuildFailure $failure) {
            assertSameValue('preview_profile_changed', $failure->codeName, 'package drift must be terminal and require a new snapshot');
            assertSameValue(false, $failure->retryable, 'retries cannot repair an old package identity');
        }
    } finally {
        staticCompilerRemoveTree($fixture['root']);
    }
};

$tests['profile preview rejects changed derivative bytes without retaining partial output'] = static function (): void {
    $fixture = profilePreviewFixture();
    try {
        $asset = $fixture['snapshot']->records[0]->media[0] ?? null;
        foreach ($fixture['snapshot']->records as $record) {
            if ($record->workflowState === 'published' && $record->media !== []) {
                $asset = $record->media[0];
                break;
            }
        }
        assertTrueValue($asset instanceof PreviewMedia, 'fixture needs a referenced derivative');
        file_put_contents($fixture['root'] . '/media/' . $asset->objectKey, 'changed derivative');
        try {
            $fixture['compiler']->compile($fixture['snapshot'], $fixture['root'] . '/output');
            throw new RuntimeException('Changed derivative unexpectedly compiled');
        } catch (RuntimeException $failure) {
            assertTrueValue(!is_dir($fixture['root'] . '/output'), 'corruption must not leave a ready artifact');
        }
    } finally {
        staticCompilerRemoveTree($fixture['root']);
    }
};

$tests['artifact URLs stay inside a mount and reject schemes traversal and separators'] = static function (): void {
    assertSameValue('../../../en/about/', ArtifactUrl::relative('/en/about/', '/en/projects/example/'), 'deep navigation should resolve against the artifact root');
    assertSameValue('./', ArtifactUrl::relative('/', '/'), 'root navigation must remain inside the current mount');
    foreach (['//evil.test/', '/a/../b', '/a//b', '/a\\b', 'javascript:alert(1)', '/a/"bad'] as $url) {
        try {
            ArtifactUrl::relative($url, '/en/');
            throw new RuntimeException('Unsafe artifact URL unexpectedly accepted');
        } catch (InvalidArgumentException) {
        }
    }
};
