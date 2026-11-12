<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';

final readonly class EditorialHttpResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}

final class EditorialHttpClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $origin,
    ) {}

    /** @param array<string, string> $headers */
    public function request(string $method, string $path, string $body = '', array $headers = []): EditorialHttpResponse
    {
        $requestHeaders = [
            'Host' => (string) parse_url($this->origin, PHP_URL_HOST),
            'Connection' => 'close',
        ];
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $requestHeaders['Cookie'] = implode('; ', $pairs);
        }
        foreach ($headers as $name => $value) {
            $requestHeaders[$name] = $value;
        }
        if ($body !== '') {
            $requestHeaders['Content-Length'] = (string) strlen($body);
        }
        $lines = [];
        foreach ($requestHeaders as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $lines),
                'content' => $body,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 10,
            ],
        ]);
        $http_response_header = [];
        $responseBody = @file_get_contents($this->baseUrl . $path, false, $context);
        $rawHeaders = $http_response_header;
        $status = isset($rawHeaders[0]) && preg_match('/\s([0-9]{3})\s/', $rawHeaders[0], $match) === 1
            ? (int) $match[1]
            : 0;
        $responseHeaders = [];
        foreach (array_slice($rawHeaders, 1) as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));
            $responseHeaders[$name][] = $value;
            if ($name !== 'set-cookie') {
                continue;
            }
            $pair = explode(';', $value, 2)[0];
            $equals = strpos($pair, '=');
            if ($equals === false) {
                continue;
            }
            $cookieName = substr($pair, 0, $equals);
            $cookieValue = substr($pair, $equals + 1);
            if ($cookieValue === '') {
                unset($this->cookies[$cookieName]);
            } else {
                $this->cookies[$cookieName] = $cookieValue;
            }
        }
        return new EditorialHttpResponse(
            $status,
            $responseHeaders,
            is_string($responseBody) ? $responseBody : '',
        );
    }

    /** @param array<string, string|list<string>> $values */
    public function form(string $path, array $values): EditorialHttpResponse
    {
        return $this->request(
            'POST',
            $path,
            http_build_query($values, '', '&', PHP_QUERY_RFC3986),
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Origin' => $this->origin,
                'Sec-Fetch-Site' => 'same-origin',
            ],
        );
    }

    public function csrfToken(): string
    {
        $token = $this->cookies['__Host-cms_csrf'] ?? '';
        if ($token === '') {
            throw new RuntimeException('Editorial journey did not receive a CSRF cookie');
        }
        return $token;
    }

    /** @param array<string, mixed> $value */
    public function json(string $path, array $value): EditorialHttpResponse
    {
        return $this->request(
            'POST',
            $path,
            json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            [
                'Content-Type' => 'application/json',
                'Origin' => $this->origin,
                'Sec-Fetch-Site' => 'same-origin',
                'X-CSRF-Token' => $this->csrfToken(),
            ],
        );
    }

    public function binary(string $method, string $path, string $bytes): EditorialHttpResponse
    {
        return $this->request($method, $path, $bytes, [
            'Content-Type' => 'application/octet-stream',
            'Origin' => $this->origin,
            'Sec-Fetch-Site' => 'same-origin',
            'X-CSRF-Token' => $this->csrfToken(),
        ]);
    }
}

/** @return never */
function editorialFailure(string $message): never
{
    throw new RuntimeException('Black-box editorial journey failed: ' . $message);
}

function editorialAssertStatus(EditorialHttpResponse $response, int $expected, string $step): void
{
    if ($response->status !== $expected) {
        editorialFailure($step . ' returned HTTP ' . $response->status . ' instead of ' . $expected);
    }
}

function editorialLocationId(EditorialHttpResponse $response, string $prefix): string
{
    $location = $response->header('location') ?? '';
    if (preg_match('#\A' . preg_quote($prefix, '#') . '([a-f0-9]{32})\z#', $location, $match) !== 1) {
        editorialFailure('response did not contain the expected opaque record location');
    }
    return $match[1];
}

/** @return array<string, mixed> */
function editorialJson(EditorialHttpResponse $response, string $step): array
{
    try {
        $decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new RuntimeException($step . ' did not return valid JSON', previous: $error);
    }
    if (!is_array($decoded)) {
        editorialFailure($step . ' did not return a JSON object');
    }
    return $decoded;
}

/** @return array{process: resource, pipes: array<int, resource>} */
function editorialStartServer(string $configPath, int $port, string $repository): array
{
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment['CMS_CONFIG'] = $configPath;
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, 'tools/conventional-cms-web.php'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $repository,
        $environment,
    );
    if (!is_resource($process)) {
        editorialFailure('PHP HTTP server could not start');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return ['process' => $process, 'pipes' => $pipes];
}

function editorialRunWorker(string $configPath, string $repository): void
{
    $command = [
        PHP_BINARY,
        $repository . '/tools/conventional-media-worker.php',
        '--config=' . $configPath,
        '--max-jobs=3',
        '--max-seconds=30',
        '--worker-id=black-box-journey',
    ];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repository);
    if (!is_resource($process)) {
        editorialFailure('worker process could not start');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        editorialFailure('worker failed: ' . trim((string) $stdout . ' ' . (string) $stderr));
    }
}

function editorialRemoveTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) && !is_link($path) ? editorialRemoveTree($path) : @unlink($path);
    }
    @rmdir($directory);
}

$dsn = getenv('CMS_TEST_JOURNEY_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('CMS_TEST_JOURNEY_DSN is required');
}
$repository = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/portable-cms-http-journey-' . bin2hex(random_bytes(8));
$privateRoot = $root . '/private';
$mediaRoot = $privateRoot . '/media';
mkdir($mediaRoot, 0700, true);
$configPath = $privateRoot . '/cms.json';
$origin = 'https://cms.editorial.test';
$password = 'editorial-journey-password';
file_put_contents($configPath, json_encode([
    'origin' => $origin,
    'database' => [
        'dsn' => $dsn,
        'username' => 'portable',
        'password' => 'integration-secret',
    ],
    'storage' => ['mediaRoot' => $mediaRoot],
    'throttleSecretBase64' => base64_encode(random_bytes(32)),
    'mediaWakeStrategy' => 'cron_only',
    'errorLog' => $privateRoot . '/errors.log',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
chmod($configPath, 0600);

$server = null;
try {
    $configuration = RuntimeConfiguration::load($configPath);
    $migrationPaths = glob($repository . '/engine/migrations/*.sql');
    if ($migrationPaths === false) {
        editorialFailure('migration files could not be enumerated');
    }
    sort($migrationPaths, SORT_STRING);
    (new MigrationRunner($configuration->connect()))->apply($migrationPaths);
    (new AdministratorBootstrap(
        $configuration->connect(),
        new SystemClock(),
        new SystemRandomSource(),
    ))->create('editor@example.test', 'Editorial Tester', $password);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!is_resource($socket)) {
        editorialFailure('local test port could not be allocated');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address) || preg_match('/:(\d+)\z/', $address, $portMatch) !== 1) {
        editorialFailure('local test port could not be identified');
    }
    $port = (int) $portMatch[1];
    $server = editorialStartServer($configPath, $port, $repository);
    $client = new EditorialHttpClient('http://127.0.0.1:' . $port, $origin);

    $loginPage = null;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        usleep(20_000);
        $candidate = $client->request('GET', '/login');
        if ($candidate->status !== 0) {
            $loginPage = $candidate;
            break;
        }
    }
    if (!$loginPage instanceof EditorialHttpResponse) {
        editorialFailure('PHP HTTP server did not become ready');
    }
    editorialAssertStatus($loginPage, 200, 'login page');
    assertTrueValue(
        str_contains($loginPage->body, 'method="post" action="/login"'),
        'login should remain a native HTML form',
    );

    $login = $client->form('/login', [
        '_csrf' => $client->csrfToken(),
        'login' => 'editor@example.test',
        'password' => $password,
    ]);
    editorialAssertStatus($login, 303, 'login');
    assertSameValue('/', $login->header('location'), 'login should enter Overview');
    $overview = $client->request('GET', '/');
    editorialAssertStatus($overview, 200, 'overview');
    assertTrueValue(str_contains($overview->body, 'Editorial Tester'), 'named editor should be visible');

    $topic = $client->form('/records/topic', [
        '_csrf' => $client->csrfToken(),
        'label__ca' => 'Matèria',
        'label__es' => 'Materia',
        'label__en' => 'Material',
    ]);
    editorialAssertStatus($topic, 303, 'topic creation');
    $topicId = editorialLocationId($topic, '/records/topic/');

    $portfolio = $client->form('/records/portfolio-entry', [
        '_csrf' => $client->csrfToken(),
        'title' => 'Casa Àlber',
        'summary' => "Timber, shade, and a recovered courtyard.\nSecond line.",
        'topics' => [$topicId],
    ]);
    editorialAssertStatus($portfolio, 303, 'portfolio creation');
    $portfolioId = editorialLocationId($portfolio, '/records/portfolio-entry/');

    $article = $client->form('/records/article', [
        '_csrf' => $client->csrfToken(),
        'title' => 'Notes on material reuse',
        'body' => 'A short article connected to the portfolio entry.',
        'featuredEntry' => $portfolioId,
        'topics' => [$topicId],
    ]);
    editorialAssertStatus($article, 303, 'article creation');
    $articleId = editorialLocationId($article, '/records/article/');

    $image = new Imagick();
    $image->newImage(1200, 800, new ImagickPixel('#8c7258'));
    $image->setImageFormat('png');
    $imageBytes = $image->getImagesBlob();
    $image->clear();
    $image->destroy();
    $uploadIntent = $client->json('/api/records/portfolio-entry/' . $portfolioId . '/media-uploads', [
        'formatVersion' => 1,
        'expectedRevision' => 1,
        'filename' => 'courtyard.png',
        'declaredType' => 'image/png',
        'byteSize' => strlen($imageBytes),
        'clientUploadId' => bin2hex(random_bytes(16)),
    ]);
    editorialAssertStatus($uploadIntent, 201, 'upload creation');
    $uploadDocument = editorialJson($uploadIntent, 'upload creation');
    $uploadId = $uploadDocument['upload']['id'] ?? null;
    if (!is_string($uploadId) || preg_match('/\A[a-f0-9]{32}\z/', $uploadId) !== 1) {
        editorialFailure('upload creation did not return an opaque session ID');
    }
    $part = $client->binary('PUT', '/api/media-uploads/' . $uploadId . '/parts/1', $imageBytes);
    editorialAssertStatus($part, 200, 'upload part');
    $completion = $client->form('/api/media-uploads/' . $uploadId . '/complete', [
        '_csrf' => $client->csrfToken(),
    ]);
    editorialAssertStatus($completion, 202, 'upload completion');
    $completionDocument = editorialJson($completion, 'upload completion');
    $jobId = $completionDocument['job']['id'] ?? null;
    if (!is_string($jobId) || preg_match('/\A[a-f0-9]{32}\z/', $jobId) !== 1) {
        editorialFailure('upload completion did not return a derivative job');
    }
    editorialRunWorker($configPath, $repository);
    $heartbeat = json_decode(
        (string) file_get_contents($mediaRoot . '/worker-heartbeat.json'),
        true,
        16,
        JSON_THROW_ON_ERROR,
    );
    assertSameValue(2, $heartbeat['formatVersion'] ?? null, 'worker heartbeat should use the soak-report format');
    assertSameValue(2, count($heartbeat['mediaRuns'] ?? []), 'worker retains separate measurements for the default and eligible extra width');
    assertSameValue(true, $heartbeat['mediaRuns'][0]['completed'] ?? null, 'measured media run should complete');
    assertSameValue(true, $heartbeat['mediaRuns'][1]['completed'] ?? null, 'the measured extra width completes independently');
    if (PHP_OS_FAMILY === 'Linux') {
        assertTrueValue(
            is_int($heartbeat['residentMemoryAtStartBytes'] ?? null)
                && is_int($heartbeat['residentMemoryAtEndBytes'] ?? null)
                && is_int($heartbeat['residentMemoryGrowthBytes'] ?? null),
            'worker heartbeat should expose Linux resident-memory retention evidence',
        );
    }
    $job = $client->request('GET', '/api/jobs/' . $jobId);
    editorialAssertStatus($job, 200, 'job status');
    assertSameValue('completed', editorialJson($job, 'job status')['job']['status'] ?? null, 'derivative should complete');

    $preview = $client->form('/previews', ['_csrf' => $client->csrfToken(), 'include_drafts' => '1']);
    editorialAssertStatus($preview, 202, 'preview creation');
    $refresh = $preview->header('refresh') ?? '';
    if (preg_match('#url=/previews/([a-f0-9]{32})\z#', $refresh, $previewMatch) !== 1) {
        editorialFailure('preview creation did not identify its status route');
    }
    $previewId = $previewMatch[1];
    editorialRunWorker($configPath, $repository);
    $previewStatus = $client->request('GET', '/previews/' . $previewId);
    editorialAssertStatus($previewStatus, 200, 'preview status');
    assertTrueValue(str_contains($previewStatus->body, 'The exact preview is ready.'), 'preview should become ready');
    $rootArtifact = $client->request('GET', '/previews/' . $previewId . '/artifact/');
    editorialAssertStatus($rootArtifact, 200, 'preview root artifact');
    assertTrueValue(str_contains($rootArtifact->body, 'Casa Àlber'), 'preview root should list the portfolio record');
    assertTrueValue(str_contains($rootArtifact->body, 'Notes on material reuse'), 'preview root should list the article');
    assertTrueValue(str_contains($rootArtifact->body, 'name="robots" content="noindex, nofollow"'), 'preview must stay non-indexable');
    assertTrueValue(str_contains($rootArtifact->body, 'href="assets/preview.css"'), 'preview should use an external stylesheet');
    assertTrueValue(!str_contains($rootArtifact->body, '<script'), 'preview should not require executable JavaScript');
    $portfolioArtifact = $client->request(
        'GET',
        '/previews/' . $previewId . '/artifact/portfolio-entry/' . $portfolioId . '/index.html',
    );
    editorialAssertStatus($portfolioArtifact, 200, 'portfolio preview artifact');
    assertTrueValue(str_contains($portfolioArtifact->body, 'Timber, shade, and a recovered courtyard.'), 'preview should render copy');
    assertTrueValue(str_contains($portfolioArtifact->body, 'Topics: Matèria'), 'preview should render taxonomy labels');
    assertTrueValue(str_contains($portfolioArtifact->body, '<img '), 'preview should render the prepared derivative');
    $articleArtifact = $client->request(
        'GET',
        '/previews/' . $previewId . '/artifact/article/' . $articleId . '/index.html',
    );
    editorialAssertStatus($articleArtifact, 200, 'article preview artifact');
    assertTrueValue(str_contains($articleArtifact->body, 'Featured portfolio entry:'), 'preview should render typed relationships');

    $export = $client->form('/exports', ['_csrf' => $client->csrfToken()]);
    editorialAssertStatus($export, 200, 'normalized export');
    assertSameValue('application/json; charset=utf-8', $export->header('content-type'), 'export should be JSON');
    $exportDocument = editorialJson($export, 'normalized export');
    assertSameValue(1, $exportDocument['formatVersion'] ?? null, 'export format should remain versioned');
    assertSameValue(3, count($exportDocument['records'] ?? []), 'export should contain both types and the taxonomy');
    assertSameValue(3, count($exportDocument['relationships'] ?? []), 'export should contain taxonomy and record relationships');
    assertSameValue(1, count($exportDocument['media'] ?? []), 'export should contain the uploaded media identity');

    fwrite(STDOUT, "PASS black-box HTTP editorial journey and artifact boundary\n");
} finally {
    if (is_array($server)) {
        proc_terminate($server['process']);
        foreach ($server['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($server['process']);
    }
    editorialRemoveTree($root);
}
