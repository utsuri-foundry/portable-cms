<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';
require_once __DIR__ . '/trailer-fixture.php';

use Utsuri\PortableCms\Conventional\Compiler\DiscoveryRoute;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\PublicUrlContext;
use Utsuri\PortableCms\Conventional\Compiler\PublishedSlugHistory;
use Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery;

$tests = [];
require __DIR__ . '/static-site-compiler-test.php';
require __DIR__ . '/published-slug-history-test.php';

/** @return array{status: int, headers: array<string, string>, body: string} */
function apacheDeliveryRequest(string $url, string $range = ''): array
{
    $context = stream_context_create(['http' => ['timeout' => 3, 'follow_location' => 0, 'ignore_errors' => true, 'header' => "Connection: close\r\n" . ($range === '' ? '' : 'Range: ' . $range . "\r\n")]]);
    $body = file_get_contents($url, false, $context);
    if (!is_string($body) || !isset($http_response_header[0]) || preg_match('/\AHTTP\/\S+ (\d{3})/', $http_response_header[0], $status) !== 1) {
        throw new RuntimeException('Public delivery HTTP response is unavailable');
    }
    $headers = [];
    foreach (array_slice($http_response_header, 1) as $header) {
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    return ['status' => (int) $status[1], 'headers' => $headers, 'body' => $body];
}

function apacheDeliveryBinary(): string
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
        $candidate = $directory . '/httpd';
        if (is_file($candidate) && is_executable($candidate)) {
            $resolved = realpath($candidate);
            if (is_string($resolved)) {
                return $resolved;
            }
        }
    }
    throw new RuntimeException('Pinned Apache is required for the public delivery gate');
}

$root = staticCompilerTempRoot();
$process = null;
try {
    $binary = apacheDeliveryBinary();
    $moduleRoot = dirname(dirname($binary)) . '/modules';
    $documentRoot = $root . '/htdocs';
    mkdir($documentRoot, 0700);
    $fixture = staticCompilerFixture();
    $document = json_decode((string) file_get_contents($fixture['package']->root . '/fixtures/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $fixture['package'] = trailerQualifiedPackage($root . '/qualified-profile');
    $fixture['builder'] = new \Utsuri\PortableCms\Profile\ReferenceStudio\ReferenceStudioSiteBuilder($fixture['package']);
    $mediaRoot = $fixture['package']->root . '/fixtures/media';
    $fixture['media'] = new \Utsuri\PortableCms\Conventional\Compiler\FilesystemMediaSource($mediaRoot);
    $videoId = str_repeat('e', 32);
    $videoSize = 250 * 1048576;
    $videoPath = $videoId . '-prepared_video.mp4';
    $videoChecksum = trailerFixture($mediaRoot . '/' . $videoPath, $videoSize);
    $document['profile']['packageSha256'] = $fixture['package']->checksum;
    $document['records'][0]['body']['trailer'] = ['mediaId' => $videoId, 'posterId' => $document['media'][0]['id']];
    $document['media'][] = ['id' => $videoId, 'ownerRecordId' => $document['records'][0]['id'], 'variant' => 'prepared_video', 'mime' => 'video/mp4', 'byteSize' => $videoSize, 'sha256' => $videoChecksum, 'width' => 160, 'height' => 90];
    $context = PublicUrlContext::from(['formatVersion' => 1, 'baseUrl' => 'https://reference-studio.example/site']);
    $document['publicUrlContext'] = $context->document();
    $previous = new RouteDiscovery($context, [new DiscoveryRoute('/en/old.v1/', 'en', 'record:10000000000000000000000000000001')]);
    $document['publishedSlugHistory'] = PublishedSlugHistory::afterVerifiedActivation($previous, 'reference-studio', publicationReceiptFixture())->document();
    $fixture['snapshot'] = FrozenSnapshot::fromDocument($document, $fixture['package']);
    $built = staticCompilerBuild($fixture, $root . '/compiled');
    // Filesystem hashes are the mandatory gate. HTTP below is a delivery smoke check.
    foreach ($built->files as $file) {
        assertSameValue($file['sha256'], hash_file('sha256', $built->outputRoot . '/' . $file['path']), 'complete target file set must match approved bytes');
        assertSameValue($file['byteSize'], filesize($built->outputRoot . '/' . $file['path']), 'target byte sizes must match');
    }
    $delivery = $built->manifest['delivery'];
    assertSameValue($delivery['sha256'], hash_file('sha256', $built->outputRoot . '/' . $delivery['path']), 'delivery rules are approved artifact bytes');
    mkdir($documentRoot . '/site', 0700);
    mkdir($root . '/journal', 0700);
    $artifactJson = (string) file_get_contents($built->outputRoot . '/artifact.json');
    $entries = array_map(static fn(array $file): \Utsuri\PortableCms\Conventional\Preview\PreviewArtifact => new \Utsuri\PortableCms\Conventional\Preview\PreviewArtifact($file['path'], $file['mime'], $file['byteSize'], $file['sha256']), $built->files);
    $entries[] = new \Utsuri\PortableCms\Conventional\Preview\PreviewArtifact('artifact.json', 'application/json', strlen($artifactJson), hash('sha256', $artifactJson));
    $inventory = new \Utsuri\PortableCms\Conventional\Preview\PreviewBuild($entries);
    $artifact = new \Utsuri\PortableCms\Conventional\Publication\ReleaseArtifact($artifactJson, $inventory, $context, 'reference-studio', (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-artifact-v1.schema.json'));
    $target = new \Utsuri\PortableCms\Conventional\Publication\FilesystemPublicationTarget($documentRoot . '/site', $root . '/journal', str_repeat('test-secret', 4));
    $release = new \Utsuri\PortableCms\Conventional\Publication\Release(str_repeat('a', 32), str_repeat('b', 32), null, 'applying', $artifact->checksum, str_repeat('c', 32), null, $target->identity());
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);
    $read = static fn(string $path): string|\Utsuri\PortableCms\Conventional\Media\MediaDelivery => $path === 'media/' . $videoPath
        ? \Utsuri\PortableCms\Conventional\Http\FileBody::verified($built->outputRoot . '/' . $path, $videoSize, $videoChecksum)
        : (string) file_get_contents($built->outputRoot . '/' . $path);
    $complete = false;
    for ($batch = 0; $batch < 32 && !$complete; $batch++) {
        $complete = $target->advance($release, $artifact, null, $read, 8, false);
    }
    assertSameValue(true, $complete, 'large trailer release reaches complete filesystem verification');
    assertTrueValue(memory_get_peak_usage(true) - $baseline < 16 * 1048576, 'publication stages the trailer through bounded streaming');
    assertSameValue($videoChecksum, hash_file('sha256', $documentRoot . '/site/media/' . $videoPath), 'publisher retains complete trailer bytes');
    assertSameValue($delivery['sha256'], hash_file('sha256', $documentRoot . '/site/.htaccess'), 'installed rules match approved identity');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('Apache loopback port is unavailable');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address)) {
        throw new RuntimeException('Apache loopback address is unavailable');
    }
    $port = (int) substr($address, strrpos($address, ':') + 1);
    $origin = 'http://127.0.0.1:' . $port;
    $lines = ['ServerRoot "' . $root . '"', 'Listen 127.0.0.1:' . $port,
        'ServerName 127.0.0.1', 'PidFile "' . $root . '/httpd.pid"',
        'ErrorLog "' . $root . '/error.log"', 'LogLevel warn', 'KeepAlive Off'];
    foreach (['mpm_event', 'unixd', 'authz_core', 'alias', 'headers', 'dir', 'mime'] as $module) {
        $lines[] = 'LoadModule ' . $module . '_module "' . $moduleRoot . '/mod_' . $module . '.so"';
    }
    $lines[] = 'User #' . posix_geteuid();
    $lines[] = 'Group #' . posix_getegid();
    $lines[] = 'TypesConfig "' . dirname(dirname($binary)) . '/conf/mime.types"';
    $lines[] = 'DocumentRoot "' . $documentRoot . '"';
    $lines[] = '<Directory "' . $documentRoot . '">';
    $lines[] = 'Require all granted';
    $lines[] = 'Options -Indexes -MultiViews';
    $lines[] = 'AllowOverride FileInfo Indexes';
    $lines[] = '</Directory>';
    $configuration = $root . '/httpd.conf';
    if (file_put_contents($configuration, implode("\n", $lines) . "\n") === false) {
        throw new RuntimeException('Test server configuration could not be written');
    }
    $process = proc_open([$binary, '-X', '-f', $configuration], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/stdout.log', 'w'], 2 => ['file', $root . '/stderr.log', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Apache test process could not start');
    }
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        set_error_handler(static fn(): bool => true);
        try {
            $connection = stream_socket_client('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage, 0.05);
        } finally {
            restore_error_handler();
        }
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        if (!proc_get_status($process)['running']) {
            throw new RuntimeException('Apache test process exited: ' . (string) file_get_contents($root . '/stderr.log'));
        }
        usleep(20_000);
    }
    assertSameValue(true, $ready, 'Apache must become ready within the bounded startup window');
    $old = apacheDeliveryRequest($origin . '/site/en/old.v1/');
    assertSameValue(308, $old['status'], 'actual conventional host must issue a permanent HTTP redirect');
    assertSameValue('https://reference-studio.example/site/en/projects/archive-of-mist/', $old['headers']['location'] ?? null, 'redirect uses exact frozen production context and current localized path');
    foreach (['/site/en/oldXv1/', '/site/en/old.v1/extra/'] as $unmatched) {
        assertSameValue(404, apacheDeliveryRequest($origin . $unmatched)['status'], 'regex must not match unrelated paths or suffixes');
    }
    $page = apacheDeliveryRequest($origin . '/site/en/projects/archive-of-mist/');
    assertSameValue(200, $page['status'], 'redirect destination is a real static page');
    assertSameValue(hash_file('sha256', $built->outputRoot . '/en/projects/archive-of-mist/index.html'), hash('sha256', $page['body']), 'served route must match filesystem-verified original bytes');
    assertTrueValue(str_contains($page['body'], 'href="https://reference-studio.example/site/en/projects/archive-of-mist/"'), 'served canonical is production metadata, not test request origin');
    assertSameValue(null, $page['headers']['x-robots-tag'] ?? null, 'private CMS directives must not propagate onto public pages');
    assertSameValue('nosniff', $page['headers']['x-content-type-options'] ?? null, 'public delivery applies required security headers');
    assertSameValue('"/site/navigation-rules.json"', $page['headers']['speculation-rules'] ?? null, 'published HTML points at the same-mount rules through a structured header');
    $speculation = apacheDeliveryRequest($origin . '/site/navigation-rules.json');
    assertSameValue('application/speculationrules+json', $speculation['headers']['content-type'] ?? null, 'Apache overrides generic JSON with the speculation rules MIME');
    assertSameValue(hash_file('sha256', $built->outputRoot . '/navigation-rules.json'), hash('sha256', $speculation['body']), 'public rules retain the verified compiler-scoped bytes');
    assertSameValue(null, $speculation['headers']['speculation-rules'] ?? null, 'rules do not recursively fetch themselves');
    assertSameValue('public, max-age=0, must-revalidate', $page['headers']['cache-control'] ?? null, 'stable asset names cannot be declared immutable across releases');
    assertTrueValue(str_contains($page['headers']['content-security-policy'] ?? '', "frame-ancestors 'none'") && !str_contains($page['headers']['content-security-policy'] ?? '', 'unsafe-inline'), 'public headers retain strict framing and script policy');
    $image = $built->manifest['sharingImages'][0];
    $servedImage = apacheDeliveryRequest($origin . '/site/' . $image['path']);
    assertSameValue('image/jpeg', $servedImage['headers']['content-type'] ?? null, 'social derivative is delivered with correct MIME');
    assertSameValue($image['sha256'], hash('sha256', $servedImage['body']), 'served sharing JPEG matches pinned identity');
    assertSameValue($videoChecksum, hash_file('sha256', $built->outputRoot . '/media/' . $videoPath), 'compiler publishes the complete trailer without buffering it');
    $opening = apacheDeliveryRequest($origin . '/site/media/' . $videoPath, 'bytes=0-11');
    assertSameValue(206, $opening['status'], 'static host starts large-video playback with a partial response');
    assertSameValue('bytes 0-11/' . $videoSize, $opening['headers']['content-range'] ?? null, 'public range identifies the complete 250 MiB trailer');
    assertSameValue('12', $opening['headers']['content-length'] ?? null, 'public playback transfers only the requested bytes');
    assertSameValue('video/mp4', $opening['headers']['content-type'] ?? null, 'public trailer MIME remains correct');
    assertSameValue('ftyp', substr($opening['body'], 4, 4), 'public range preserves the MP4 header');
    $tail = apacheDeliveryRequest($origin . '/site/media/' . $videoPath, 'bytes=' . ($videoSize - 1) . '-');
    assertSameValue(206, $tail['status'], 'static host seeks to the trailer end');
    assertSameValue("\0", $tail['body'], 'tail playback transfers one byte');
    assertSameValue(416, apacheDeliveryRequest($origin . '/site/media/' . $videoPath, 'bytes=' . $videoSize . '-')['status'], 'out-of-file public range is refused');
    // A separate mount must not inherit this public policy or a public cache directive.
    mkdir($documentRoot . '/cms', 0700);
    file_put_contents($documentRoot . '/cms/index.html', 'Separate test mount');
    $separate = apacheDeliveryRequest($origin . '/cms/index.html');
    assertSameValue(null, $separate['headers']['content-security-policy'] ?? null, 'target rules apply only to the dedicated public mount');
    assertSameValue(null, $separate['headers']['speculation-rules'] ?? null, 'a separate mount never inherits speculation rules');
    // Missing/ignored target rules must be observable, not treated as activation success.
    rename($documentRoot . '/site/.htaccess', $documentRoot . '/site/.htaccess.disabled');
    assertSameValue(404, apacheDeliveryRequest($origin . '/site/en/old.v1/')['status'], 'inert redirect data is not a working host redirect');
    fwrite(STDOUT, "PASS real Apache public delivery: verified streamed 250 MiB trailer publication and ranges, exact permanent redirects, complete filesystem hashes, served metadata/JPEG, strict headers and separate CMS mount.\n");
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    staticCompilerRemoveTree($root);
}
