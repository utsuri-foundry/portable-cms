<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Preview\PreviewArtifact;
use Utsuri\PortableCms\Conventional\Preview\PreviewBuild;
use Utsuri\PortableCms\Conventional\Publication\FilesystemPublicationTarget;
use Utsuri\PortableCms\Conventional\Publication\PublicationFailure;
use Utsuri\PortableCms\Conventional\Publication\Release;
use Utsuri\PortableCms\Conventional\Publication\ReleaseArtifact;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';

$tests['release native routes enforce authentication origin and CSRF before target work'] = static function (): void {
    ['app' => $app] = contentTestApplication(withPreviews: true);
    assertSameValue(303, $app->handle(new \Utsuri\PortableCms\Conventional\Http\Request('GET', '/releases'))->status, 'anonymous release page requires login');
    $cookies = authenticatedTestCookies($app);
    $post = ['_csrf' => $cookies[\Utsuri\PortableCms\Conventional\Identity\IdentityService::CSRF_COOKIE], 'action' => 'approve', 'id' => str_repeat('1', 32), 'previous' => '', 'confirmed' => '1'];
    assertSameValue(403, $app->handle(new \Utsuri\PortableCms\Conventional\Http\Request('POST', '/releases', contentMutationHeaders(), [...$post, '_csrf' => 'incorrect'], $cookies))->status, 'CSRF checked before missing capability');
    assertSameValue(403, $app->handle(new \Utsuri\PortableCms\Conventional\Http\Request('POST', '/releases', ['origin' => 'https://attacker.example'], $post, $cookies))->status, 'foreign origin cannot queue publication');
    assertSameValue(501, $app->handle(new \Utsuri\PortableCms\Conventional\Http\Request('POST', '/releases', contentMutationHeaders(), $post, $cookies))->status, 'absent target never queues unsupported work');
    $page = $app->handle(new \Utsuri\PortableCms\Conventional\Http\Request('GET', '/releases', cookies: $cookies));
    assertSameValue(200, $page->status, 'capability limitation remains visible to editors');
    assertSameValue(['no-store'], $page->headers()['cache-control'], 'release information stays private');
};

/** @param array<string, mixed>|null $changed */
function publicationFixture(?array $changed = null): ReleaseArtifact
{
    $root = dirname(__DIR__, 2);
    $value = $changed ?? json_decode(file_get_contents($root . '/profiles/reference-studio/fixtures/expected/artifact.json'), true, 64, JSON_THROW_ON_ERROR);
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $entries = array_map(static fn(array $file): PreviewArtifact => new PreviewArtifact($file['path'], $file['mime'], $file['byteSize'], $file['sha256']), $value['files']);
    $entries[] = new PreviewArtifact('artifact.json', 'application/json', strlen($json), hash('sha256', $json));
    $context = PublicUrlContext::from(array_intersect_key($value['publicUrlContext'], array_flip(['formatVersion', 'baseUrl'])));
    return new ReleaseArtifact($json, new PreviewBuild($entries), $context, 'reference-studio', file_get_contents($root . '/schemas/portable-cms-artifact-v1.schema.json'));
}

$tests['release producer contract rejects drafts wrong rules context and unsafe target files'] = static function (): void {
    $fixture = publicationFixture();
    assertTrueValue(in_array('/', $fixture->publicPaths, true), 'entry utility route is verified without a canonical history identity');
    assertTrueValue(!in_array('/', $fixture->routes->paths(), true), 'utility aliases never fabricate canonical history');
    $changed = $fixture->document;
    $changed['includeDrafts'] = true;
    try {
        publicationFixture($changed);
        throw new RuntimeException('Draft artifact accepted');
    } catch (PublicationFailure $error) {
        assertSameValue('artifact_not_releasable', $error->codeName, 'review-only artifact is rejected independently of queue status');
    }
    foreach (['assets/escape.php.jpg', 'assets/.htaccess'] as $path) {
        $changed = $fixture->document;
        $changed['files'][0]['path'] = $path;
        try {
            publicationFixture($changed);
            throw new RuntimeException('Executable public artifact accepted');
        } catch (PublicationFailure $error) {
            assertSameValue('unsafe_public_file', $error->codeName, 'public filenames cannot enable scripts or override directory policy');
        }
    }
    $changed = $fixture->document;
    $changed['delivery']['sha256'] = str_repeat('0', 64);
    try {
        publicationFixture($changed);
        throw new RuntimeException('Unverified delivery rules accepted');
    } catch (PublicationFailure $error) {
        assertSameValue('artifact_inventory_mismatch', $error->codeName, 'applied rules must equal generated route policy');
    }
};

$tests['release size contract allows large prepared videos while retaining ordinary asset and total bounds'] = static function (): void {
    $original = publicationFixture()->document;
    $video = ['path' => 'media/' . str_repeat('e', 32) . '-prepared_video.mp4', 'mime' => 'video/mp4', 'byteSize' => 250 * 1048576, 'sha256' => str_repeat('a', 64), 'cachePolicy' => 'immutable'];
    $document = $original;
    $document['files'][] = $video;
    assertTrueValue(count(publicationFixture($document)->files) > count($original['files']), 'one typical trailer no longer trips the 200 MiB non-video ceiling');
    foreach ([['path' => 'assets/pretend.mp4'], ['byteSize' => \Utsuri\PortableCms\Conventional\Media\VideoSupport::MAX_BYTES + 1], ['mime' => 'video/webm']] as $mutation) {
        $document = $original;
        $document['files'][] = [...$video, ...$mutation];
        try {
            publicationFixture($document);
            throw new RuntimeException('Unsafe public video was accepted');
        } catch (PublicationFailure|InvalidArgumentException) {
        }
    }
    foreach (['video', 'image'] as $kind) {
        $document = $original;
        for ($number = 0; $number < ($kind === 'video' ? 33 : 6); $number++) {
            $document['files'][] = $kind === 'video'
                ? [...$video, 'path' => 'media/' . str_pad(dechex($number), 32, '0', STR_PAD_LEFT) . '-prepared_video.mp4', 'byteSize' => \Utsuri\PortableCms\Conventional\Media\VideoSupport::MAX_BYTES]
                : [...$video, 'path' => 'media/image-' . $number . '.webp', 'mime' => 'image/webp', 'byteSize' => 40 * 1048576];
        }
        try {
            publicationFixture($document);
            throw new RuntimeException('Unbounded release was accepted');
        } catch (PublicationFailure $failure) {
            assertSameValue('release_size_limit', $failure->codeName, 'total and non-video ceilings remain enforced');
        }
    }
};

$tests['publication target refuses unsafe roots locks foreign paths and forged receipts'] = static function (): void {
    $state = sys_get_temp_dir() . '/portable-target-test-' . bin2hex(random_bytes(8));
    mkdir($state, 0700);
    mkdir($state . '/public', 0700);
    mkdir($state . '/private', 0700);
    $fixture = publicationFixture();
    $release = new Release(str_repeat('1', 32), str_repeat('2', 32), null, 'queued', $fixture->checksum, str_repeat('3', 32), null, str_repeat('4', 64));
    try {
        $target = new FilesystemPublicationTarget($state . '/public', $state . '/private', str_repeat('s', 32));
        assertSameValue(true, $target->locked(static fn(): bool => true), 'target lock can be acquired');
        $contender = new FilesystemPublicationTarget($state . '/public', $state . '/private', str_repeat('s', 32));
        $target->locked(static function () use ($contender): bool {
            assertSameValue(false, $contender->locked(static fn(): bool => throw new RuntimeException('Second writer acquired lock')), 'concurrent target writers cannot interleave');
            return true;
        });
        file_put_contents($state . '/public/index.html', 'unrelated deployment');
        try {
            $target->advance($release, $fixture, null, static fn(): string => 'bytes', 1, false);
            throw new RuntimeException('Unmanaged root overwritten');
        } catch (PublicationFailure $error) {
            assertSameValue('unmanaged_public_file_conflict', $error->codeName, 'operator must provide dedicated root');
        }
        assertSameValue(true, $target->advance($release, $fixture, null, static fn(): string => 'bytes', 1, true), 'recovery without a journal does not delete foreign files');
        assertSameValue('unrelated deployment', file_get_contents($state . '/public/index.html'), 'foreign file survives rejected deployment and recovery');
        unlink($state . '/public/index.html');
        symlink($state . '/private', $state . '/public/assets');
        try {
            $target->advance($release, $fixture, null, static fn(): string => 'bytes', 1, false);
            throw new RuntimeException('Symlink target accepted');
        } catch (PublicationFailure $error) {
            assertSameValue('unsafe_target_path', $error->codeName, 'publication rejects symlink path components');
        }
        unlink($state . '/public/assets');
        try {
            $target->authenticate('{"payload":"{}","signature":"forged"}', $release, $fixture);
            throw new RuntimeException('Forged receipt accepted');
        } catch (PublicationFailure $error) {
            assertSameValue('invalid_target_receipt', $error->codeName, 'target receipt is authenticated before SQL activation');
        }
    } finally {
        foreach (new DirectoryIterator($state . '/private') as $entry) {
            if (!$entry->isDot()) {
                unlink($entry->getPathname());
            }
        }
        rmdir($state . '/private');
        rmdir($state . '/public');
        rmdir($state);
    }
};
