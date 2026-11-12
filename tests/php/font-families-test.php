<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\ArtifactStyles;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Design\DesignCss;
use Utsuri\PortableCms\Conventional\Design\FilesystemFontStore;
use Utsuri\PortableCms\Conventional\Design\FontFamilies;
use Utsuri\PortableCms\Conventional\Design\FontIngestion;
use Utsuri\PortableCms\Conventional\Design\FontRejected;
use Utsuri\PortableCms\Conventional\Design\RegistryDocument;
use Utsuri\PortableCms\Conventional\Design\RegistryForm;
use Utsuri\PortableCms\Conventional\Design\RegistryService;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;

function familyFixture(): RegistryDocument
{
    return RegistryDocument::fromDocument(json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/fixtures/contracts/design-families.accepted.json'), true, 32, JSON_THROW_ON_ERROR));
}

$tests['font families preserve exact v1 identity and stylesheet bytes'] = static function (): void {
    $legacy = designFixture();
    assertSameValue('fd91792e9abedf55e2e374fc18ba1344cfc96aa6356d990f4bffe0b38ba5273b', $legacy->checksum(), 'legacy canonical identity is unchanged');
    $css = DesignCss::compile($legacy, ['sans' => 'assets/studio-sans-latin.woff2', 'serif' => 'assets/studio-serif-latin.woff2']);
    assertSameValue('fed9efa1f4defdcac0d6e8263683476446c5f857306d349e03eb39e7698cf878', hash('sha256', $css), 'legacy CSS keeps its exact descriptors and bytes');
    assertSameValue($legacy->checksum(), RegistryDocument::fromDocument(RegistryForm::project($legacy, RegistryForm::values($legacy)))->checksum(), 'ordinary forms never upgrade old documents');
    $upgraded = FontFamilies::upgrade($legacy);
    assertSameValue(2, $upgraded->document()['formatVersion'], 'face additions use an explicit projection');
    assertSameValue($upgraded->checksum(), FontFamilies::upgrade($upgraded)->checksum(), 'projection is idempotent');
    assertSameValue('sans', $upgraded->document()['typography'][0]['fontId'], 'role identities survive projection');
    $before = ['designRegistries' => $legacy->document()];
    $after = ['designRegistries' => $upgraded->document()];
    assertSameValue(['designRegistries'], \Utsuri\PortableCms\Conventional\Upgrade\ProfileManifestChanges::classify($before, $after)->neutral, 'a family projection retains the content contract');
    $after['designRegistries']['fonts'][0]['faces'][0]['futureDescriptor'] = 'unknown';
    assertTrueValue(\Utsuri\PortableCms\Conventional\Upgrade\ProfileManifestChanges::classify($before, $after)->affecting !== [], 'unknown nested face facts remain conservatively classified');
};

$tests['font families validate faces weights styles identities and finite bounds'] = static function (): void {
    $base = familyFixture()->document();
    JsonSchemaValidator::validateJson(json_encode($base, JSON_THROW_ON_ERROR), (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-design-registries-v2.schema.json'));
    $mutations = [
        static function (array &$d): void {
            $d['fonts'][0]['faces'] = [];
        },
        static function (array &$d): void {
            unset($d['fonts'][0]['faces'][0]['sha256']);
        },
        static function (array &$d): void {
            $d['fonts'][0]['faces'][0]['id'] = '../face';
        },
        static function (array &$d): void {
            $d['fonts'][0]['faces'][1]['id'] = 'regular';
        },
        static function (array &$d): void {
            $d['fonts'][0]['faces'][1]['style'] = 'normal';
        },
        static function (array &$d): void {
            $d['fonts'][0]['faces'][0]['style'] = 'italic;src:url(evil)';
        },
        static function (array &$d): void {
            $d['fonts'][0]['faces'][0]['weightMin'] = 500;
        },
        static function (array &$d): void {
            $d['fonts'][0]['faces'][0]['weightMin'] = 99;
        },
        static function (array &$d): void {
            $d['fonts'][0]['faces'][0]['weightMax'] = 901;
        },
        static function (array &$d): void {
            $d['fonts'][0]['faces'][0]['weightMax'] = 400.5;
        },
        static function (array &$d): void {
            $d['fonts'][0]['faces'][0]['license'] = '';
        },
        static function (array &$d): void {
            $d['fonts'][0]['faces'][0]['origin'] = 'https://font.test';
        },
        static function (array &$d): void {
            $d['fonts'][0]['faces'][0]['byteSize'] = 2_097_153;
        },
        static function (array &$d): void {
            $d['fonts'][0]['faces'][0]['sourceSha256'] = 'invalid';
        },
        static function (array &$d): void {
            $d['fonts'][0]['faces'] = array_fill(0, 9, $d['fonts'][0]['faces'][0]);
        },
    ];
    foreach ($mutations as $mutation) {
        $d = $base;
        $mutation($d);
        try {
            RegistryDocument::fromDocument($d);
            throw new RuntimeException('Hostile family declaration was accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $bold = $base['fonts'][0]['faces'][0];
    $bold['id'] = 'bold';
    $bold['weightMin'] = $bold['weightMax'] = 700;
    $base['fonts'][0]['faces'][] = $bold;
    assertSameValue(3, count(RegistryDocument::fromDocument($base)->faces()), 'distinct weights of the same style are allowed');
    $base['fonts'][0]['faces'][0]['weightMax'] = 700;
    try {
        RegistryDocument::fromDocument($base);
        throw new RuntimeException('Overlapping variable range was accepted');
    } catch (InvalidArgumentException) {
    }
};

$tests['font families compile one face per style under a shared family and reject missing paths'] = static function (): void {
    $registry = familyFixture();
    $paths = ['reading:regular' => 'assets/studio-serif-latin.woff2', 'reading:italic' => 'assets/studio-serif-italic-latin.woff2'];
    $css = DesignCss::compile($registry, $paths, artifactRelative: true);
    assertSameValue(2, substr_count($css, '@font-face{font-family:"cms-reading";'), 'both styles share the role family');
    assertTrueValue(str_contains($css, 'font-style:normal;font-weight:400;') && str_contains($css, 'font-style:italic;font-weight:400;'), 'descriptors declare actual static weights and styles');
    assertTrueValue(str_contains($css, 'font-family:"cms-reading",serif;') && str_contains($css, ';font-synthesis:none}'), 'roles choose families without fabricated styles');
    foreach ([['reading:regular' => $paths['reading:regular']], [...$paths, 'reading:italic' => 'assets/../private.woff2']] as $invalid) {
        try {
            DesignCss::compile($registry, $invalid);
            throw new RuntimeException('Missing or hostile face path was accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $fixture = staticCompilerFixture();
    $document = json_decode((string) file_get_contents($fixture['package']->root . '/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
    $document['designRegistries']['fonts'][1]['faces'][1]['sha256'] = str_repeat('f', 64);
    $snapshot = FrozenSnapshot::fromDocument($document, $fixture['package']);
    try {
        ArtifactStyles::fonts($fixture['package'], $snapshot);
        throw new RuntimeException('A missing packaged italic was accepted');
    } catch (InvalidArgumentException) {
    }
};

$tests['font families add sanitized faces through native forms preserving historical v1 and immutable facts'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $repository = new MemoryDesignRepository();
        $service = new RegistryService($repository, designFixture(), new IdentityTestClock(new DateTimeImmutable('2026-10-21T00:00:00Z')), new IdentityTestRandom(), new FontIngestion(designSanitizer($root), new FilesystemFontStore($root . '/fonts')));
        $values = [...RegistryForm::values($service->load()), 'font_family' => 'serif', 'font_face_id' => 'italic', 'font_face_label' => 'Italic', 'font_style' => 'italic', 'font_weight_min' => '400', 'font_weight_max' => '400',
            'font_provenance' => 'Studio Serif Italic, reviewed renamed OFL subset', 'font_license' => 'SIL Open Font License 1.1', 'font_permission' => 'yes'];
        $bytes = (string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/assets/studio-serif-italic-latin.woff2');
        $saved = $service->save(0, $values, 'test-actor', $bytes);
        assertSameValue(2, $saved->document()['formatVersion'], 'adding a face upgrades the new revision only');
        assertSameValue(designFixture()->checksum(), $service->load(0)->checksum(), 'old revision keeps the original checksum');
        $face = $saved->faces()[2];
        assertSameValue('serif:italic', $face['key'], 'new face is grouped without changing its family identity');
        assertSameValue(hash('sha256', $bytes), $face['sourceSha256'], 'every face retains the source checksum');
        assertSameValue($face['sha256'], hash('sha256', $service->fonts->previewBytes($face['sha256'], $face['byteSize'])), 'every face is stored through verified ingestion');
        $current = [...RegistryForm::values($saved), ...array_filter($values, static fn(string $key): bool => str_starts_with($key, 'font_'), ARRAY_FILTER_USE_KEY)];
        try {
            $service->save(1, $current, 'test-actor', $bytes);
            throw new RuntimeException('A duplicate face was accepted');
        } catch (InvalidArgumentException) {
        }
        assertSameValue(1, count($repository->history), 'rejection does not add a revision');
        $new = [...$current, 'font_family' => '', 'font_id' => 'new-family', 'font_label' => 'New family', 'font_face_id' => 'roman', 'font_face_label' => 'Roman', 'font_style' => 'normal'];
        try {
            $service->save(1, $new, 'test-actor', '<?php evil();');
            throw new RuntimeException('Hostile face bytes were accepted');
        } catch (FontRejected) {
        }
        $roman = (string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/assets/studio-serif-latin.woff2');
        $legacy = \Utsuri\PortableCms\Conventional\Design\FontUpload::add(designFixture(), [
            'font_id' => 'legacy-font', 'font_label' => 'Legacy font', 'font_provenance' => 'Studio Serif test copy', 'font_license' => 'SIL Open Font License 1.1', 'font_permission' => 'yes',
        ], $roman, $service->fonts);
        assertSameValue(1, $legacy->document()['formatVersion'], 'an old upload request still produces v1');
        $newFamily = $service->save(1, $new, 'test-actor', $roman);
        assertSameValue('new-family', $newFamily->document()['fonts'][2]['id'], 'new families use the same ingestion path');
        $form = RegistryForm::values($newFamily);
        $form['fonts__serif__faces'] = 'hostile replacement';
        assertSameValue($newFamily->checksum(), RegistryDocument::fromDocument(RegistryForm::project($newFamily, $form))->checksum(), 'forms cannot rewrite face facts');
        $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
        $app = contentTestApplication(profileOverride: $profile, designService: $service)['app'];
        $cookies = authenticatedTestCookies($app);
        $page = $app->handle(new Request('GET', '/settings/design', cookies: $cookies));
        assertTrueValue(str_contains($page->body, 'data-font-face="serif:italic"') && str_contains($page->body, 'name="font_family"') && str_contains($page->body, 'name="font_weight_min"'), 'native settings show the saved face and upload controls');
        assertSameValue(200, $app->handle(new Request('GET', '/fonts/' . $face['sha256'] . '.woff2', cookies: $cookies))->status, 'authenticated preview resolves a nested uploaded face');
        assertSameValue(403, $app->handle(new Request('GET', '/fonts/' . $face['sha256'] . '.woff2'))->status, 'font objects remain private to editors');
        assertTrueValue(isset($cookies[IdentityService::CSRF_COOKIE]), 'native form retains ordinary CSRF authority');
    } finally {
        staticCompilerRemoveTree($root);
    }
};
