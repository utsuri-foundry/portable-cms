<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\ContentFeedSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Content\ContentFeed;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\Publication\FeedRetention;
use Utsuri\PortableCms\Conventional\Publication\FilesystemPublicationTarget;
use Utsuri\PortableCms\Conventional\Publication\PublicationFailure;
use Utsuri\PortableCms\Conventional\Upgrade\ProfileManifestChanges;

/** @param array<string, mixed>|null $declaration
 * @param list<\Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition>|null $types
 */
function feedTestProfile(?array $declaration, ?array $types = null): ProfileManifest
{
    $p = staticCompilerFixture()['package']->manifest;
    $types ??= $p->types;
    return new ProfileManifest($p->id, $p->version, $p->compatibility, $p->locales, $p->localeDefinitions, $p->defaultLocale, $types, $p->navigation, $p->renderers, $p->assets, $p->capabilities, $p->privacy, $p->designRegistries, wording: $p->wording, envelope: $p->envelope, contentFeed: ContentFeed::from($declaration, $types));
}

/** @param Closure(): mixed $operation */
function feedReject(Closure $operation): void
{
    try {
        $operation();
    } catch (InvalidArgumentException|PublicationFailure|\Utsuri\PortableCms\Conventional\I18n\InvalidInput) {
        return;
    }
    throw new RuntimeException('Unsafe content feed input was accepted');
}

$tests['content feeds require explicit types and leaf fields and classify reviewed output separately from migrations'] = static function (): void {
    $fixture = staticCompilerFixture();
    $p = $fixture['package']->manifest;
    assertSameValue(['article', 'project'], array_keys($p->contentFeed->types), 'the reference profile exposes two reviewed types');
    assertSameValue(null, ContentFeedSnapshot::build(feedTestProfile(null), $fixture['snapshot']), 'no declaration produces no feed');
    $declaration = $p->contentFeed->document();
    foreach ([
        [...$declaration, 'private' => true], [...$declaration, 'pageSize' => 0], [...$declaration, 'pageSize' => 101],
        [...$declaration, 'recordTypes' => []], [...$declaration, 'recordTypes' => [$declaration['recordTypes'][0], $declaration['recordTypes'][0]]],
        [...$declaration, 'recordTypes' => [['typeId' => 'missing', 'fields' => ['title']]]],
        [...$declaration, 'recordTypes' => [['typeId' => 'project', 'fields' => ['title', 'title']]]],
        [...$declaration, 'recordTypes' => [['typeId' => 'project', 'fields' => ['seo']]]],
        [...$declaration, 'recordTypes' => [['typeId' => 'project', 'fields' => ['layout']]]],
        [...$declaration, 'recordTypes' => [['typeId' => 'landing', 'fields' => ['blocks']]]],
    ] as $bad) {
        feedReject(static fn() => ContentFeed::from($bad, $p->types));
    }
    $old = json_decode((string) file_get_contents($fixture['package']->root . '/profile.json'), true, flags: JSON_THROW_ON_ERROR);
    $new = $old;
    $new['contentFeed']['recordTypes'][0]['fields'] = ['title'];
    $changes = ProfileManifestChanges::classify($old, $new);
    assertSameValue([['contentFeed'], []], [$changes->neutral, $changes->affecting], 'a reviewed public projection needs a new artifact, without rewriting authored records');
    $new['contentFeed']['futureAutomaticExposure'] = true;
    assertTrueValue(ProfileManifestChanges::classify($old, $new)->affecting !== [], 'unknown declaration changes fail closed');
};

$tests['content feed snapshots contain only declared published values and immutable resized image URLs'] = static function (): void {
    $fixture = staticCompilerFixture();
    $feed = ContentFeedSnapshot::build($fixture['package']->manifest, $fixture['snapshot']);
    $schemaRoot = dirname(__DIR__, 2) . '/schemas';
    JsonSchemaValidator::validateJson(ContentFeedSnapshot::json($feed->manifest), (string) file_get_contents($schemaRoot . '/portable-cms-feed-snapshot-v1.schema.json'));
    foreach ($feed->documents as $json) {
        JsonSchemaValidator::validateJson($json, (string) file_get_contents($schemaRoot . '/portable-cms-feed-page-v1.schema.json'));
        $page = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        foreach ($page['records'] as $record) {
            assertSameValue($feed->manifest['records'][array_search($record['id'], array_column($feed->manifest['records'], 'id'), true)]['revisionNumber'], $record['revisionNumber'], 'feed records carry their frozen revision');
            assertSameValue([], array_values(array_intersect(['seo', 'slug', 'credits', 'layout'], array_keys($record['fields']))), 'undeclared fields never leave authoring');
        }
    }
    $json = ContentFeedSnapshot::json($feed->manifest) . implode('', $feed->documents);
    assertTrueValue(!str_contains($json, '30000000000000000000000000000002'), 'a draft article never enters the feed');
    assertTrueValue(!str_contains($json, 'originals/') && !str_contains($json, 'derivatives/') && !str_contains($json, 'releaseId') && !str_contains($json, 'createdBy'), 'feed bytes contain no originals, private keys or release authority');
    assertTrueValue(count($feed->media) > 0, 'declared gallery images are included');
    foreach ($feed->media as $path => $image) {
        assertSameValue('feed/v1/media/' . $image->checksum . '.webp', $path, 'feed derivatives retain exact immutable bytes under a checksum URL');
    }
};

$tests['content feed snapshot identity ignores build and record ordering but follows public values and revisions'] = static function (): void {
    $fixture = staticCompilerFixture();
    $package = $fixture['package'];
    $document = json_decode((string) file_get_contents($package->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $original = ContentFeedSnapshot::build($package->manifest, $fixture['snapshot']);
    $document['snapshotId'] = str_repeat('f', 32);
    $document['createdAt'] = '2026-10-19T20:00:00.000000Z';
    $document['records'] = array_reverse($document['records']);
    $position = array_search('10000000000000000000000000000001', array_column($document['records'], 'id'), true);
    $document['records'][$position]['body']['year'] = 1901;
    $same = ContentFeedSnapshot::build($package->manifest, FrozenSnapshot::fromDocument($document, $package));
    assertSameValue($original->manifest['snapshotId'], $same->manifest['snapshotId'], 'private fields and build identity do not affect the feed content address');
    foreach ($document['records'] as &$record) {
        if ($record['id'] === '10000000000000000000000000000001') {
            $record['body']['title']['en'] = 'Changed public title';
        }
    }
    unset($record);
    $changed = ContentFeedSnapshot::build($package->manifest, FrozenSnapshot::fromDocument($document, $package));
    assertTrueValue($original->manifest['snapshotId'] !== $changed->manifest['snapshotId'], 'declared public text changes the snapshot identity');
};

$tests['content feed declarations never expose a field added later and preview details link the frozen snapshot'] = static function (): void {
    $fixture = staticCompilerFixture();
    $package = $fixture['package'];
    $profile = $package->manifest;
    $types = [];
    foreach ($profile->types as $type) {
        $types[] = $type->id !== 'project' ? $type : new \Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition(
            $type->id,
            $type->label,
            $type->singularLabel,
            [...$type->fields, new \Utsuri\PortableCms\Conventional\Content\FieldDefinition('internalNotes', 'Internal notes', 'multiline_text', true, false, false, ['maximumLength' => 200], maximumLength: 200)],
            $type->kind,
            $type->archetype,
            $type->renderer,
            $type->route,
        );
    }
    $document = json_decode((string) file_get_contents($package->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($document['records'] as &$record) {
        if ($record['typeId'] === 'project') {
            $record['body']['internalNotes'] = array_fill_keys($profile->locales, 'PRIVATE_NEW_FIELD');
        }
    }
    unset($record);
    $feed = ContentFeedSnapshot::build(feedTestProfile($profile->contentFeed->document(), $types), FrozenSnapshot::fromDocument($document, $package));
    assertTrueValue(!str_contains(implode('', $feed->documents), 'PRIVATE_NEW_FIELD'), 'a newly declared private field stays private without changing the feed allowlist');
    $snapshotPath = $feed->directory() . 'manifest.json';
    $inventory = new \Utsuri\PortableCms\Conventional\Preview\PreviewBuild([
        new \Utsuri\PortableCms\Conventional\Preview\PreviewArtifact('index.html', 'text/html; charset=utf-8', 1, str_repeat('a', 64)),
        new \Utsuri\PortableCms\Conventional\Preview\PreviewArtifact($snapshotPath, 'application/json', 1, str_repeat('b', 64)),
    ]);
    $now = new DateTimeImmutable('2026-10-19T12:00:00Z');
    $preview = new \Utsuri\PortableCms\Conventional\Preview\PreviewStatus(str_repeat('1', 32), 'ready', str_repeat('2', 32), null, $inventory->manifest(), $now, $now);
    $html = \Utsuri\PortableCms\Conventional\View\Pages::previewStatus('csrf', $profile, $preview);
    assertTrueValue(str_contains($html, '/previews/' . $preview->id . '/artifact/' . $snapshotPath) && str_contains($html, 'View content feed snapshot'), 'a ready exact preview offers its immutable feed snapshot through the existing private artifact boundary');
};

$tests['content feed pagination is stable bounded and discovers empty collections'] = static function (): void {
    $fixture = staticCompilerFixture();
    $package = $fixture['package'];
    $document = json_decode((string) file_get_contents($package->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $source = array_values(array_filter($document['records'], static fn(array $r): bool => $r['id'] === '10000000000000000000000000000001'))[0];
    for ($i = 1; $i <= 4; ++$i) {
        $copy = $source;
        $copy['id'] = substr(hash('sha256', 'feed-record-' . $i), 0, 32);
        $copy['revisionId'] = substr(hash('sha256', 'feed-revision-' . $i), 0, 32);
        $document['records'][] = $copy;
    }
    $profile = feedTestProfile(['formatVersion' => 1, 'pageSize' => 2, 'recordTypes' => [['typeId' => 'project', 'fields' => ['title']], ['typeId' => 'sector', 'fields' => ['label']]]]);
    $feed = ContentFeedSnapshot::build($profile, FrozenSnapshot::fromDocument($document, $package));
    $project = array_values(array_filter($feed->manifest['collections'], static fn(array $c): bool => $c['typeId'] === 'project' && $c['locale'] === 'en'))[0];
    assertSameValue([5, 3], [$project['recordCount'], count($project['pages'])], 'five records form three bounded pages');
    assertSameValue(2, json_decode($feed->documents['project/en/1.json'], true)['nextPage'], 'the first page points to the next page number');
    assertSameValue(null, json_decode($feed->documents['project/en/3.json'], true)['nextPage'], 'the last page ends explicitly');
    $document['includeDrafts'] = true;
    foreach ($document['records'] as &$record) {
        if ($record['typeId'] === 'project') {
            $record['workflowState'] = 'draft';
        }
    }
    unset($record);
    $empty = ContentFeedSnapshot::build($profile, FrozenSnapshot::fromDocument($document, $package));
    assertSameValue([], json_decode($empty->documents['project/en/1.json'], true)['records'], 'a declared type with no published records still has one discoverable empty page');
};

$tests['content feed filesystem discovery rejects foreign files and resumes interrupted bounded writes'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        mkdir($root . '/public');
        mkdir($root . '/private');
        $target = new FilesystemPublicationTarget($root . '/public', $root . '/private', str_repeat('s', 32));
        $documents = ['feed/v1/activations/1-' . str_repeat('a', 32) . '.json' => '{"approved":true}', 'feed/v1/activations.json' => '{"list":true}', 'feed/v1/current.json' => '{"current":true}'];
        mkdir($root . '/public/feed/v1', 0755, true);
        file_put_contents($root . '/public/feed/v1/current.json', 'unmanaged');
        feedReject(static fn() => $target->synchronizeFeed(new FeedRetention(), $documents, 1));
        assertSameValue('unmanaged', file_get_contents($root . '/public/feed/v1/current.json'), 'foreign data is never overwritten');
        unlink($root . '/public/feed/v1/current.json');
        assertSameValue(false, $target->synchronizeFeed(new FeedRetention(), $documents, 1), 'one bounded action leaves durable progress');
        assertTrueValue(!is_file($root . '/public/feed/v1/current.json'), 'the current pointer follows its immutable dependencies');
        $resumed = new FilesystemPublicationTarget($root . '/public', $root . '/private', str_repeat('s', 32));
        assertSameValue(false, $resumed->synchronizeFeed(new FeedRetention(), $documents, 1), 'a fresh target resumes the second action');
        assertSameValue(true, $resumed->synchronizeFeed(new FeedRetention(), $documents, 1), 'resumption finishes without repeating the first action');
        assertSameValue('{"current":true}', file_get_contents($root . '/public/feed/v1/current.json'), 'the pointer matches committed metadata');
        $remaining = ['feed/v1/current.json' => '{"current":false}'];
        for ($i = 0; $i < 5 && !$resumed->synchronizeFeed(new FeedRetention(), $remaining, 1); ++$i) {
        }
        assertTrueValue(!is_file($root . '/public/feed/v1/activations.json'), 'only obsolete managed files are removed');
        symlink($root . '/private', $root . '/public/feed/v1/activations');
        feedReject(static fn() => $resumed->synchronizeFeed(new FeedRetention(), $documents, 3));
    } finally {
        staticCompilerRemoveTree($root);
    }
};
