<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure;
use Utsuri\PortableCms\Conventional\Compiler\ProfileBuildReason;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Preview\PreviewBuildFailure;
use Utsuri\PortableCms\Conventional\Preview\PreviewDiagnostics;
use Utsuri\PortableCms\Conventional\Preview\PreviewErrorReporter;
use Utsuri\PortableCms\Conventional\Preview\PreviewPreflightFailure;
use Utsuri\PortableCms\Conventional\Preview\PreviewRecord;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;
use Utsuri\PortableCms\Conventional\Preview\ProfilePreviewCompiler;
use Utsuri\PortableCms\Conventional\View\PreviewPreflightPage;

/** @param list<PreviewRecord> $records */
function diagnosticSnapshot(PreviewSnapshot $snapshot, array $records, ?string $checksum = null): PreviewSnapshot
{
    return new PreviewSnapshot($snapshot->profileId, $snapshot->profileVersion, $snapshot->locales, $records, $snapshot->createdAt, $snapshot->designRegistries, $checksum ?? $snapshot->profileChecksum, $snapshot->publicUrlContext, $snapshot->publishedSlugHistory, $snapshot->includeDrafts);
}

/** @param array<string, mixed> $body
 * @param array<string, list<string>>|null $relationships
 */
function diagnosticRecord(PreviewRecord $record, array $body, ?array $relationships = null): PreviewRecord
{
    return new PreviewRecord($record->id, $record->typeId, $record->revisionId, $record->revisionNumber, $body, $relationships ?? $record->relationships, $record->media, $record->workflowState);
}

$tests['preview diagnostics identify multiple translations and safely escape hostile record titles'] = static function (): void {
    $fixture = profilePreviewFixture();
    try {
        $snapshot = $fixture['snapshot'];
        $records = $snapshot->records;
        $expectedLinks = [];
        foreach ($records as $index => $record) {
            if ($record->typeId === 'project' && $snapshot->includes($record)) {
                $body = $record->body;
                $body['title']['ca'] = '<img src=x onerror=alert(1)> {reason}';
                $body['title']['en'] = '';
                $body['title']['es'] = '';
                $records[$index] = diagnosticRecord($record, $body);
                $expectedLinks = ['/records/project/' . $record->id, '/records/project/' . $record->id];
                break;
            }
        }
        $reporter = new PreviewErrorReporter(new IdentityTestClock($snapshot->createdAt), new IdentityTestRandom(), $fixture['root'] . '/private.log', $fixture['package']->root);
        $compiler = new ProfilePreviewCompiler($fixture['package'], $fixture['root'] . '/media', dirname(__DIR__, 2) . '/schemas', $reporter->report(...));
        try {
            $compiler->preflight(diagnosticSnapshot($snapshot, $records));
            throw new RuntimeException('Missing translations passed');
        } catch (PreviewPreflightFailure $failure) {
            assertSameValue(2, count($failure->issues), 'each missing translation is a distinct cause');
            assertSameValue($expectedLinks, $failure->links, 'translated causes retain stable correction links');
            $html = PreviewPreflightPage::render($fixture['package']->manifest, 'csrf', $failure->issues, reference: $failure->reference);
            assertTrueValue(str_contains($html, 'Project') && str_contains($html, 'Title in en') && str_contains($html, 'Title in es'), 'record type, title, field and languages are named');
            assertTrueValue(str_contains($html, '&lt;img src=x onerror=alert(1)&gt; {reason}') && !str_contains($html, '<img src=x'), 'titles are escaped plain text, never ICU patterns or HTML');
            assertTrueValue($failure->reference !== null && str_contains($html, $failure->reference), 'the private diagnostic reference is shown');
            $log = json_decode(trim((string) file_get_contents($fixture['root'] . '/private.log')), true, flags: JSON_THROW_ON_ERROR);
            assertSameValue($failure->reference, $log['reference'], 'the reference matches the private log');
            assertSameValue(0600, fileperms($fixture['root'] . '/private.log') & 0777, 'private logs are owner-only');
            $document = json_decode(json_encode((new PreviewDiagnostics($failure->issues, $failure->reference))->document(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $stored = PreviewDiagnostics::fromDocument($document);
            foreach (['ca', 'es', 'en', 'fr', 'de'] as $locale) {
                assertTrueValue(str_contains(Translator::load($locale)->message($stored->issues[0]), '<img src=x'), 'structured causes survive storage and remain translatable in ' . $locale);
            }
        }
    } finally {
        staticCompilerRemoveTree($fixture['root']);
    }
};

$tests['preview diagnostics identify broken relationships and missing profile settings'] = static function (): void {
    $fixture = profilePreviewFixture();
    try {
        $snapshot = $fixture['snapshot'];
        $records = $snapshot->records;
        foreach ($records as $index => $record) {
            if ($record->typeId === 'home') {
                $relationships = $record->relationships;
                $relationships['featuredContent'] = [str_repeat('f', 32)];
                $records[$index] = diagnosticRecord($record, $record->body, $relationships);
                break;
            }
        }
        try {
            $fixture['compiler']->preflight(diagnosticSnapshot($snapshot, $records));
            throw new RuntimeException('Broken relationship passed');
        } catch (PreviewPreflightFailure $failure) {
            $text = implode(' ', array_map(Translator::english()->message(...), $failure->issues));
            assertTrueValue(str_contains($text, 'Home') && str_contains($text, 'Featured') && str_contains($text, 'available record'), 'the relationship owner and field are reported');
            assertTrueValue(!str_contains($text, 'Frozen snapshot') && !str_contains($text, 'Check saved translations'), 'technical and generic exception messages are absent');
        }
        $records = array_values(array_filter($snapshot->records, static fn(PreviewRecord $record): bool => $record->typeId !== 'home'));
        try {
            $fixture['compiler']->preflight(diagnosticSnapshot($snapshot, $records));
            throw new RuntimeException('Missing settings passed');
        } catch (PreviewPreflightFailure $failure) {
            $text = Translator::english()->message($failure->issues[0]);
            assertTrueValue(str_contains($text, 'Home') && str_contains($text, 'exactly one record'), 'empty installations identify the missing singleton type');
        }
    } finally {
        staticCompilerRemoveTree($fixture['root']);
    }
};

$tests['preview diagnostics report unsupported sharing images per affected page'] = static function (): void {
    $fixture = profilePreviewFixture();
    try {
        $records = $fixture['snapshot']->records;
        foreach ($records as $index => $record) {
            if ($record->typeId === 'site-settings') {
                $body = $record->body;
                $id = str_repeat('f', 32);
                $body['defaultSharingImage'] = [$id];
                $bytes = (string) file_get_contents(dirname(__DIR__) . '/fixtures/video/prepared.mp4');
                $directory = $fixture['root'] . '/media/derivatives/' . $id;
                mkdir($directory, 0700, true);
                file_put_contents($directory . '/' . $id . '-prepared-video.mp4', $bytes);
                $video = new \Utsuri\PortableCms\Conventional\Preview\PreviewMedia($id, 'derivatives/' . $id . '/' . $id . '-prepared-video.mp4', $id . '-prepared-video.mp4', 'video/mp4', strlen($bytes), hash('sha256', $bytes), 160, 90);
                $records[$index] = new PreviewRecord($record->id, $record->typeId, $record->revisionId, $record->revisionNumber, $body, $record->relationships, [...$record->media, $video], $record->workflowState);
            }
        }
        try {
            $fixture['compiler']->preflight(diagnosticSnapshot($fixture['snapshot'], $records));
            throw new RuntimeException('Unavailable sharing image passed');
        } catch (PreviewPreflightFailure $failure) {
            assertTrueValue(count($failure->issues) > 1, 'each affected route has a cause');
            $text = Translator::english()->message($failure->issues[0]);
            assertTrueValue(str_contains($text, 'Page /') && str_contains($text, 'JPEG, PNG or WebP'), 'route and supported image types are named');
        }
    } finally {
        staticCompilerRemoveTree($fixture['root']);
    }
};

/** Builds a verified fictional package whose renderer fails unexpectedly. */
function diagnosticFailurePackage(ProfilePackage $source, string $root): ProfilePackage
{
    mkdir($root, 0700);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $entry) {
        $path = $root . '/' . substr($entry->getPathname(), strlen($source->root) + 1);
        if ($entry->isDir()) {
            mkdir($path, 0700);
        } else {
            copy($entry->getPathname(), $path);
        }
    }
    file_put_contents($root . '/src/compiler.php', <<<'PHP'
<?php
return static fn($package) => new class implements \Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuilder {
    public function build(\Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot $snapshot): \Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuild {
        return \Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure::atRoute('/en/broken/', static function () {
            throw new \RuntimeException('Invalid renderer link; SELECT private FROM cms_users; password=fictional-secret');
        });
    }
};
PHP);
    return ProfilePackage::load($root);
}

$tests['unexpected profile exceptions retain page context and private technical evidence across build failures'] = static function (): void {
    $fixture = profilePreviewFixture();
    try {
        $package = diagnosticFailurePackage($fixture['package'], $fixture['root'] . '/profile');
        $snapshot = diagnosticSnapshot($fixture['snapshot'], $fixture['snapshot']->records, $package->checksum);
        $logPath = $fixture['root'] . '/private.log';
        $reporter = new PreviewErrorReporter(new IdentityTestClock($snapshot->createdAt), new IdentityTestRandom(), $logPath, $package->root);
        $compiler = new ProfilePreviewCompiler($package, $fixture['root'] . '/media', dirname(__DIR__, 2) . '/schemas', $reporter->report(...));
        foreach (['preflight', 'compile'] as $operation) {
            try {
                if ($operation === 'preflight') {
                    $compiler->preflight($snapshot);
                } else {
                    $compiler->compile($snapshot, $fixture['root'] . '/output');
                }
                throw new RuntimeException('Unexpected profile exception passed');
            } catch (PreviewPreflightFailure|PreviewBuildFailure $failure) {
                $diagnostics = $failure instanceof PreviewPreflightFailure ? new PreviewDiagnostics($failure->issues, $failure->reference) : $failure->diagnostics;
                assertTrueValue($diagnostics !== null, 'preflight and background builds both carry safe diagnostics');
                $html = PreviewPreflightPage::render($package->manifest, 'csrf', $diagnostics->issues, reference: $diagnostics->reference);
                assertTrueValue(str_contains($html, '/en/broken/') && str_contains($html, 'profile developer'), 'unexpected failures name the failing route');
                foreach (['SELECT private', 'Invalid renderer link', 'fictional-secret', $package->root, 'RuntimeException', 'src/compiler.php'] as $private) {
                    assertTrueValue(!str_contains($html, $private), 'technical evidence stays private: ' . $private);
                }
                $lines = explode("\n", trim((string) file_get_contents($logPath)));
                $log = json_decode($lines[count($lines) - 1], true, flags: JSON_THROW_ON_ERROR);
                assertSameValue($diagnostics->reference, $log['reference'], 'editor reference locates the actual exception');
                assertSameValue(RuntimeException::class, $log['exception'], 'private diagnostics retain the technical exception class');
                assertTrueValue(str_contains($log['message'], 'Invalid renderer link') && str_starts_with($log['profileSource'], 'src/compiler.php:'), 'private diagnostics retain cause and relative profile source location');
                assertTrueValue(!str_contains($log['message'], 'fictional-secret'), 'credentials in technical messages are redacted');
                if ($failure instanceof PreviewBuildFailure) {
                    assertSameValue(false, $failure->retryable, 'repeating a broken renderer cannot repair content');
                    assertTrueValue(!is_dir($fixture['root'] . '/output'), 'failed builds retain no public artifact');
                }
            }
        }
        $record = $compiler->freeze($snapshot)->records[0];
        try {
            ProfileBuildFailure::atRecord($record, static fn() => throw new Error('private record renderer'));
        } catch (ProfileBuildFailure $failure) {
            assertSameValue($record->id, $failure->recordId, 'record-scoped unexpected errors preserve the owner');
            assertSameValue(ProfileBuildReason::ProfileError, $failure->reason, 'unexpected Error has a stable generic profile reason');
        }
        $multiple = new \Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailures([
            new ProfileBuildFailure(ProfileBuildReason::Relationship, $record->id, $record->typeId),
            new ProfileBuildFailure(ProfileBuildReason::SharingImage, route: '/en/broken/'),
        ]);
        foreach ([static fn() => ProfileBuildFailure::atRoute('/en/broken/', static fn() => throw $multiple), static fn() => ProfileBuildFailure::atRecord($record, static fn() => throw $multiple)] as $wrapped) {
            try {
                $wrapped();
            } catch (\Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailures $failure) {
                assertSameValue($multiple, $failure, 'context wrappers preserve every typed cause instead of replacing the batch with a generic error');
            }
        }
        mkdir($fixture['root'] . '/blocked-log');
        $blocked = new PreviewErrorReporter(new IdentityTestClock($snapshot->createdAt), new IdentityTestRandom(), $fixture['root'] . '/blocked-log', $package->root);
        assertSameValue(32, strlen($blocked->report(new RuntimeException('still diagnosable'))), 'unavailable private logging cannot replace the workflow failure');
    } finally {
        staticCompilerRemoveTree($fixture['root']);
    }
};
