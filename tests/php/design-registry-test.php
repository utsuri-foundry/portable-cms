<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Design\DesignCss;
use Utsuri\PortableCms\Conventional\Design\FilesystemFontStore;
use Utsuri\PortableCms\Conventional\Design\FontIngestion;
use Utsuri\PortableCms\Conventional\Design\FontRejected;
use Utsuri\PortableCms\Conventional\Design\FontUnavailable;
use Utsuri\PortableCms\Conventional\Design\NativeFontSanitizer;
use Utsuri\PortableCms\Conventional\Design\RegistryConflict;
use Utsuri\PortableCms\Conventional\Design\RegistryDocument;
use Utsuri\PortableCms\Conventional\Design\RegistryForm;
use Utsuri\PortableCms\Conventional\Design\RegistryRepository;
use Utsuri\PortableCms\Conventional\Design\RegistryService;
use Utsuri\PortableCms\Conventional\Design\SizeCurve;
use Utsuri\PortableCms\Conventional\Design\Woff2;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;

final class MemoryDesignRepository implements RegistryRepository
{
    /** @var array<int, RegistryDocument> */
    public array $history = [];
    public function load(RegistryDocument $defaults, ?int $revision = null): RegistryDocument
    {
        if ($revision === 0 || $this->history === []) {
            return $defaults;
        }
        return $this->history[$revision ?? max(array_keys($this->history))] ?? throw new InvalidArgumentException('Design revision unavailable');
    }
    public function save(RegistryDocument $intent, string $actor, string $auditId, DateTimeImmutable $now): RegistryDocument
    {
        $current = $this->load($intent->withRevision(0));
        if ($current->revision() !== $intent->revision()) {
            throw new RegistryConflict($current);
        }
        return $this->history[$intent->revision() + 1] = $intent->withRevision($intent->revision() + 1);
    }
}

function designFixture(): RegistryDocument
{
    return RegistryDocument::fromDocument(json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/fixtures/contracts/design-registries.accepted.json'), true, 32, JSON_THROW_ON_ERROR));
}

function designExecutable(string $name): string
{
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
        $path = $directory . '/' . $name;
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }
    throw new RuntimeException('Required pinned font processor is missing: ' . $name);
}

function designSanitizer(string $root): NativeFontSanitizer
{
    return new NativeFontSanitizer(designExecutable('ots-sanitize'), designExecutable('woff2_compress'), $root, new IdentityTestRandom());
}

$tests['design domain rejects unsafe identities values overrides and curve crossings'] = static function (): void {
    $base = designFixture()->document();
    $mutations = [
        static function (array &$v): void {
            $v['fonts'][0]['id'] = '../../font';
        },
        static function (array &$v): void {
            $v['typography'][0]['label'] = '<script>';
        },
        static function (array &$v): void {
            $v['typography'][0]['size']['min'] = NAN;
        },
        static function (array &$v): void {
            $v['typography'][0]['size']['from'] = $v['typography'][0]['size']['to'];
        },
        static function (array &$v): void {
            $v['typography'][0]['fontId'] = 'absent';
        },
        static function (array &$v): void {
            $v['responsive'][1]['minWidth'] = $v['responsive'][0]['minWidth'];
        },
        static function (array &$v): void {
            $v['typography'][0]['overrides'] = [['thresholdId' => 'absent', 'size' => $v['typography'][0]['size']]];
        },
        static function (array &$v): void {
            $v['typography'][0]['style'] = 'background:url(javascript:evil)';
        },
        static function (array &$v): void {
            $v['fonts'][0]['license'] = '';
        },
        static function (array &$v): void {
            $v['fonts'][] = $v['fonts'][0];
        },
        // Both curves are ordered at 360 and 1920, but cross at an interior knot.
        static function (array &$v): void {
            $v['spacing'] = [
                ['id' => 'a', 'label' => 'A', 'size' => ['min' => 10, 'max' => 40, 'from' => 400, 'to' => 600]],
                ['id' => 'b', 'label' => 'B', 'size' => ['min' => 20, 'max' => 60, 'from' => 600, 'to' => 1000]],
            ];
        },
    ];
    foreach ($mutations as $mutation) {
        $value = $base;
        $mutation($value);
        try {
            RegistryDocument::fromDocument($value);
            throw new RuntimeException('Unsafe design value unexpectedly accepted');
        } catch (InvalidArgumentException) {
        }
    }
};

$tests['design curves compile fixed fluid and sparse responsive values consistently'] = static function (): void {
    assertSameValue('1rem', (new SizeCurve(16, 16, 360, 1280))->css(), 'fixed values use rem');
    $fluid = new SizeCurve(16, 32, 400, 1200);
    assertSameValue(16.0, $fluid->at(240), 'curve clamps below its interval');
    assertSameValue(24.0, $fluid->at(800), 'curve interpolates');
    assertSameValue(32.0, $fluid->at(1920), 'curve clamps above its interval');
    assertSameValue('clamp(1rem, calc(0.5rem + 2vw), 2rem)', $fluid->css(), 'compiler uses the same affine calculation');
    $document = designFixture()->document();
    $document['typography'][0]['overrides'] = [['thresholdId' => 'wide', 'size' => ['min' => 24, 'max' => 24, 'from' => 360, 'to' => 1280]]];
    $registry = RegistryDocument::fromDocument($document);
    $paths = ['sans' => 'assets/studio-sans-latin.woff2', 'serif' => 'assets/studio-serif-latin.woff2'];
    $css = DesignCss::compile($registry, $paths);
    assertTrueValue(str_contains($css, '@media(min-width:1280px){:root{--type-body:1.5rem;}}'), 'responsive overrides use trusted threshold IDs');
    assertSameValue(24.0, DesignCss::specimens($registry)['body'][1920], 'specimen uses effective override');
    assertTrueValue(!str_contains($css, $document['typography'][0]['label']), 'editable labels never enter CSS');
    assertTrueValue(str_contains($css, "\n:root{"), 'compiled CSS uses real newlines');
    $permuted = array_reverse($document, true);
    $permuted['typography'][0] = array_reverse($document['typography'][0], true);
    $roundTrip = json_decode(json_encode($permuted, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    assertSameValue($registry->checksum(), RegistryDocument::fromDocument($roundTrip)->checksum(), 'key order and integer/decimal serialization cannot change canonical identity');
    JsonSchemaValidator::validateJson(json_encode($registry->document(), JSON_THROW_ON_ERROR), (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-design-registries-v1.schema.json'));
};

$tests['design native forms enforce authorization preserve validation and conflict input'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $repository = new MemoryDesignRepository();
        $service = new RegistryService($repository, designFixture(), new IdentityTestClock(new DateTimeImmutable('2026-10-03T00:00:00Z')), new IdentityTestRandom(), new FontIngestion(null, new FilesystemFontStore($root . '/fonts')));
        $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
        $app = contentTestApplication(profileOverride: $profile, designService: $service)['app'];
        assertSameValue(303, $app->handle(new Request('GET', '/settings/design'))->status, 'guest cannot read settings');
        $cookies = authenticatedTestCookies($app);
        $values = RegistryForm::values($service->load());
        $form = [...$values, '_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '0'];
        assertSameValue(403, $app->handle(new Request('POST', '/settings/design', ['origin' => 'https://evil.test'], $form, $cookies))->status, 'foreign origin cannot save');
        assertSameValue(403, $app->handle(new Request('POST', '/settings/design', contentMutationHeaders(), [...$form, '_csrf' => 'wrong'], $cookies))->status, 'CSRF is session bound');
        $invalid = $app->handle(new Request('POST', '/settings/design', contentMutationHeaders(), [...$form, 'responsive__wide__minWidth' => '700', 'typography__body__label' => 'Reading text'], $cookies));
        assertSameValue(422, $invalid->status, 'threshold order is checked server-side');
        assertTrueValue(str_contains($invalid->body, 'value="700"') && str_contains($invalid->body, 'value="Reading text"'), 'all submitted values survive validation');
        assertSameValue([], $repository->history, 'rejections cannot save');
        $calculated = $app->handle(new Request('POST', '/settings/design', contentMutationHeaders(), [...$form, '_action' => 'calculate', 'typography__body__min' => '20', 'typography__body__max' => '20'], $cookies));
        assertSameValue(200, $calculated->status, 'no-script specimen calculation succeeds');
        assertTrueValue(str_contains($calculated->body, '20 px ·'), 'specimens reflect unsaved values');
        assertSameValue([], $repository->history, 'specimen calculation is read-only');
        assertSameValue(303, $app->handle(new Request('POST', '/settings/design', contentMutationHeaders(), $form, $cookies))->status, 'valid native save redirects');
        $conflict = $app->handle(new Request('POST', '/settings/design', contentMutationHeaders(), [...$form, 'typography__body__label' => 'Unsaved reading text'], $cookies));
        assertSameValue(409, $conflict->status, 'stale revision cannot overwrite');
        assertTrueValue(str_contains($conflict->body, 'value="Unsaved reading text"') && str_contains($conflict->body, 'name="_revision" value="1"'), 'explicit conflict resubmission preserves input');
        $invalidStale = $app->handle(new Request('POST', '/settings/design', contentMutationHeaders(), [...$form, 'typography__body__min' => 'oops'], $cookies));
        assertTrueValue(str_contains($invalidStale->body, 'name="_revision" value="0"'), 'validation never silently rebases a stale form');
        assertSameValue(1, count($repository->history), 'conflict and validation cannot append history');
        assertTrueValue(str_contains($conflict->body, 'data-font-unavailable'), 'missing sanitizer is visible');
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['font ingestion sanitizes actual WOFF2 and rejects hostile bytes licensing and paths'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $store = new FilesystemFontStore($root . '/fonts');
        $ingestion = new FontIngestion(designSanitizer($root), $store);
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/assets/studio-serif-latin.woff2');
        // Add an opaque private block. It must be dropped by sanitize/re-encode.
        $private = $source . 'private metadata';
        $private = substr_replace($private, pack('N', strlen($private)), 8, 4);
        $private = substr_replace($private, pack('NN', strlen($source), 16), 40, 8);
        $font = $ingestion->ingest($private, 'reading', 'Reading font', 'Adobe Source Serif 4, test subset', 'SIL Open Font License 1.1');
        $path = $store->file($font['sha256'], $font['byteSize']);
        $bytes = (string) file_get_contents($path);
        Woff2::verify($bytes, true);
        assertTrueValue(!str_contains($bytes, 'private metadata'), 'private blocks cannot survive sanitization');
        assertSameValue(hash('sha256', $private), $font['sourceSha256'], 'original provenance checksum is retained');
        assertSameValue(0600, fileperms($path) & 0777, 'sanitized font stays private');
        assertSameValue($font, $ingestion->ingest($private, 'reading', 'Reading font', 'Adobe Source Serif 4, test subset', 'SIL Open Font License 1.1'), 'repeated ingestion is idempotent');
        foreach (['<?php evil();', substr($source, 0, -1), str_repeat('x', 2_097_153), substr_replace($source, 'evil', 48, 4)] as $hostile) {
            try {
                $ingestion->ingest($hostile, 'reading', 'Reading', 'Source', 'Permission');
                throw new RuntimeException('Hostile font bytes were accepted');
            } catch (FontRejected) {
            }
        }
        try {
            $ingestion->ingest($source, 'reading', 'Reading', 'Source', '');
            throw new RuntimeException('Missing licensing facts were accepted');
        } catch (FontRejected) {
        }
        try {
            (new FontIngestion(null, $store))->ingest($source, 'reading', 'Reading', 'Source', 'License');
            throw new RuntimeException('Missing capability accepted an upload');
        } catch (FontUnavailable) {
        }
        $collisionRandom = new class implements \Utsuri\PortableCms\Conventional\Identity\RandomSource {
            public function bytes(int $length): string
            {
                return str_repeat("\x01", $length);
            }
        };
        $collisionStore = new FilesystemFontStore($root . '/collision', $collisionRandom);
        $occupied = $root . '/collision/pending-' . str_repeat('01', 16);
        file_put_contents($occupied, 'another operation owns these bytes');
        try {
            $collisionStore->put($bytes);
            throw new RuntimeException('Occupied staging path unexpectedly reused');
        } catch (RuntimeException $error) {
            assertSameValue('Private font staging path is occupied.', $error->getMessage(), 'staging collision must fail safely');
        }
        assertSameValue('another operation owns these bytes', file_get_contents($occupied), 'cleanup cannot remove a staging path this operation did not create');
        $link = $root . '/link';
        symlink($root . '/fonts', $link);
        try {
            new FilesystemFontStore($link);
            throw new RuntimeException('Symlink font store was accepted');
        } catch (RuntimeException $error) {
            assertSameValue('Private font storage is unavailable.', $error->getMessage(), 'symlink storage must fail closed');
        }
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['static compiler pins design and publishes only checksum verified sanitized fonts'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $fixture = staticCompilerFixture();
        $store = new FilesystemFontStore($root . '/fonts');
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/assets/studio-serif-latin.woff2');
        $font = (new FontIngestion(designSanitizer($root), $store))->ingest($source, 'reading', 'Reading font', 'Adobe Source Serif 4', 'SIL Open Font License 1.1');
        $value = $fixture['package']->manifest->designRegistries?->document() ?? throw new RuntimeException('Reference design defaults are unavailable');
        $value['revision'] = 5;
        $value['fonts'][] = ['id' => $font['id'], 'label' => $font['label'], 'faces' => [[...$font, 'style' => 'normal', 'weightMin' => 400, 'weightMax' => 400]]];
        $value['typography'][0]['fontId'] = 'reading';
        $value['typography'][0]['size'] = ['min' => 20, 'max' => 20, 'from' => 360, 'to' => 1280];
        $registry = RegistryDocument::fromDocument($value);
        $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
        $document['designRegistries'] = $registry->document();
        $snapshot = \Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot::fromDocument($document, $fixture['package']);
        $result = $fixture['compiler']->compile($fixture['package'], $snapshot, $fixture['builder'], $fixture['media'], $fixture['request'], $root . '/artifact', $store);
        $publicPath = $result->outputRoot . '/fonts/' . $font['sha256'] . '.woff2';
        assertSameValue($font['sha256'], hash_file('sha256', $publicPath), 'public font bytes are exact sanitized bytes');
        assertSameValue(['revision' => 5, 'sha256' => $registry->checksum()], $result->manifest['designRegistries'], 'release pins complete canonical registry identity');
        $css = (string) file_get_contents($result->outputRoot . '/assets/design.css');
        assertTrueValue(str_contains($css, '--type-body:1.25rem;'), 'frozen role controls compiled CSS');
        assertTrueValue(!str_contains($css, 'Adobe') && !str_contains($css, $root), 'provenance and private paths do not enter public CSS');
        try {
            $fixture['compiler']->compile($fixture['package'], $snapshot, $fixture['builder'], $fixture['media'], $fixture['request'], $root . '/missing-font');
            throw new RuntimeException('Missing font source unexpectedly compiled');
        } catch (RuntimeException $error) {
            assertSameValue('Frozen uploaded font source is unavailable', $error->getMessage(), 'missing capability fails closed');
        }
        assertTrueValue(!file_exists($root . '/missing-font'), 'failed compilation cannot leave an artifact');
        $driftSource = $root . '/drift.woff2';
        file_put_contents($driftSource, str_repeat('x', $font['byteSize']));
        $driftingStore = new class ($driftSource) implements \Utsuri\PortableCms\Conventional\Design\FontStore {
            public function __construct(private readonly string $path) {}
            public function put(string $bytes): string
            {
                throw new LogicException('Not used');
            }
            public function file(string $checksum, int $byteSize): string
            {
                return $this->path;
            }
        };
        try {
            $fixture['compiler']->compile($fixture['package'], $snapshot, $fixture['builder'], $fixture['media'], $fixture['request'], $root . '/drift-font', $driftingStore);
            throw new RuntimeException('Font source drift unexpectedly compiled');
        } catch (RuntimeException $error) {
            assertSameValue('Copied font differs from its frozen identity', $error->getMessage(), 'copied bytes must independently match frozen font identity');
        }
        assertTrueValue(!file_exists($root . '/drift-font'), 'font drift cannot commit an artifact');
        file_put_contents($store->file($font['sha256'], $font['byteSize']), 'corrupt');
        try {
            $fixture['compiler']->compile($fixture['package'], $snapshot, $fixture['builder'], $fixture['media'], $fixture['request'], $root . '/corrupt-font', $store);
            throw new RuntimeException('Changed font bytes unexpectedly compiled');
        } catch (RuntimeException $error) {
            assertSameValue('Private font integrity check failed.', $error->getMessage(), 'font drift is rejected');
        }
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['maximum supported design fits the complete no-script PHP form budget'] = static function (): void {
    $value = designFixture()->document();
    $value['fonts'] = [];
    $value['responsive'] = [];
    $value['typography'] = [];
    $value['spacing'] = [];
    for ($i = 0; $i < 8; $i++) {
        $value['responsive'][] = ['id' => 'range-' . $i, 'label' => 'Range ' . $i, 'minWidth' => 300 + $i * 300];
    }
    for ($i = 0; $i < 16; $i++) {
        $value['typography'][] = ['id' => 'role-' . $i, 'label' => 'Role ' . $i, 'fontId' => null, 'fallback' => 'sans-serif', 'weight' => 400, 'lineHeight' => 1.5,
            'size' => ['min' => 16, 'max' => 16, 'from' => 360, 'to' => 1280], 'overrides' => []];
    }
    for ($i = 0; $i < 32; $i++) {
        $value['spacing'][] = ['id' => 'gap-' . $i, 'label' => 'Gap ' . $i, 'size' => ['min' => $i + 1, 'max' => $i + 1, 'from' => 360, 'to' => 1280]];
    }
    $max = RegistryDocument::fromDocument($value);
    $fields = RegistryForm::values($max);
    assertTrueValue(count($fields) + 32 + 18 < 1000, 'all registries and upload controls must fit PHP default max_input_vars');
    assertSameValue($max->checksum(), RegistryDocument::fromDocument(RegistryForm::project($max, $fields))->checksum(), 'maximum no-script form retains every value');
    $value['formatVersion'] = 2;
    for ($family = 0; $family < 32; $family++) {
        $faces = [];
        for ($face = 0; $face < 8; $face++) {
            $faces[] = [...designFixture()->document()['fonts'][0], 'id' => 'face-' . $face, 'style' => 'normal', 'weightMin' => 100 + $face * 100, 'weightMax' => 100 + $face * 100];
        }
        $value['fonts'][] = ['id' => 'family-' . $family, 'label' => 'Family ' . $family, 'faces' => $faces];
    }
    $families = RegistryDocument::fromDocument($value);
    assertSameValue(256, count($families->faces()), 'the complete family capacity remains bounded');
    assertSameValue(count($fields) + 32, count(RegistryForm::values($families)), 'read-only face facts do not inflate native input count');
    assertSameValue($families->checksum(), RegistryDocument::fromDocument(RegistryForm::project($families, RegistryForm::values($families)))->checksum(), 'the maximum family form preserves every face');
};
