<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\MediaFocalStyle;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Media\FocalPoint;

$tests['focal points validate bounded percentages and preserve legacy centre and portable documents'] = static function (): void {
    assertSameValue(['x' => 50, 'y' => 50], FocalPoint::from()->document(), 'legacy centre');
    foreach ([[0, 100], [100, 0], [85, 20]] as [$x, $y]) {
        $point = FocalPoint::from(['x' => $x, 'y' => $y]);
        assertSameValue($x . '% ' . $y . '%', MediaFocalStyle::position($point), 'safe object position');
        $document = normalizedExportFixture();
        $document['media'][0]['focalPoint'] = $point->document();
        assertSameValue($point->document(), (new NormalizedExport($document))->document['media'][0]['focalPoint'], 'lossless normalized metadata');
        \Utsuri\PortableCms\Conventional\JsonSchemaValidator::validateExportJson(json_encode($document, JSON_THROW_ON_ERROR), (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-export-v1.schema.json'));
    }
    foreach ([[], ['x' => -1, 'y' => 50], ['x' => 50, 'y' => 101], ['x' => 12.5, 'y' => 50], ['x' => '50', 'y' => 50], ['x' => true, 'y' => 50], ['x' => 50, 'y' => 50, 'css' => 'url(x)'], ['x' => '0; color:red', 'y' => 0]] as $bad) {
        try {
            FocalPoint::from($bad);
            throw new RuntimeException('Unsafe focal point accepted');
        } catch (InvalidInput $error) {
            assertSameValue('media.focal.invalid', $error->text()->key, 'typed safe validation');
        }
        $document = normalizedExportFixture();
        $document['media'][0]['focalPoint'] = $bad;
        try {
            \Utsuri\PortableCms\Conventional\JsonSchemaValidator::validateExportJson(json_encode($document, JSON_THROW_ON_ERROR), (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-export-v1.schema.json'));
            throw new RuntimeException('Unsafe focal point passed export schema');
        } catch (\Utsuri\PortableCms\Conventional\SchemaValidationFailure) {
        }
        try {
            new NormalizedExport($document);
            throw new RuntimeException('Unsafe exported focal point accepted');
        } catch (InvalidArgumentException) {
        }
    }
};

$tests['native focal metadata setting clearing conflicts and hostile input use one media revision'] = static function (): void {
    $fixture = editorialMediaFixture();
    try {
        $state = contentTestApplication(library: $fixture['library']);
        $app = $state['app'];
        $cookies = authenticatedTestCookies($app);
        $id = array_key_first($fixture['repository']->items);
        $post = ['_csrf' => $cookies[IdentityService::CSRF_COOKIE], '_revision' => '1', '_focal_point' => '1', 'name' => 'Study', 'focal_x' => '85', 'focal_y' => '20'];
        assertSameValue(303, $app->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), $post, $cookies))->status, 'native save');
        assertSameValue(['x' => 85, 'y' => 20], $fixture['library']->find($id)->focalPoint->document(), 'point is authoritative');
        $conflict = $app->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), [...$post, 'focal_x' => '12', 'focal_y' => '90'], $cookies));
        assertSameValue(409, $conflict->status, 'concurrent edits conflict');
        assertTrueValue(str_contains($conflict->body, 'name="focal_x" type="number" min="0" max="100" step="1" required value="12"'), 'submitted point preserved');
        assertSameValue(['x' => 85, 'y' => 20], $fixture['library']->find($id)->focalPoint->document(), 'conflict cannot write');
        foreach (['101', '-1', 'NaN', '1.5', '1e2', '<img src=x>'] as $value) {
            $invalid = $app->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), [...$post, '_revision' => '2', 'focal_x' => $value], $cookies));
            assertSameValue(422, $invalid->status, 'invalid native percentage rejected');
            assertTrueValue(str_contains($invalid->body, 'Enter whole percentages'), 'localized error');
        }
        assertSameValue(303, $app->handle(new Request('POST', '/media/' . $id, contentMutationHeaders(), [...$post, '_revision' => '2', 'focal_reset' => '1'], $cookies))->status, 'clear to centre');
        assertSameValue(['x' => 50, 'y' => 50], $fixture['library']->find($id)->focalPoint->document(), 'clear defaults');
        assertSameValue(3, $fixture['library']->find($id)->metadataRevision, 'only successful saves advance metadata');
        assertSameValue(2, $fixture['repository']->writes, 'no processing writes');
    } finally {
        removeUploadTestTree($fixture['root']);
    }
};

$tests['frozen focal points reach profile and shared image renderers with external safe CSS'] = static function (): void {
    $fixture = staticCompilerFixture();
    $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
    $id = $document['media'][0]['id'];
    $document['media'][0]['focalPoint'] = ['x' => 85, 'y' => 20];
    $snapshot = FrozenSnapshot::fromDocument($document, $fixture['package']);
    $document['media'][0]['focalPoint'] = ['x' => 10, 'y' => 90];
    assertSameValue(['x' => 85, 'y' => 20], $snapshot->media($id)->focalPoint->document(), 'frozen metadata is immutable');
    $site = $fixture['builder']->build($snapshot);
    $html = implode('', array_map(static fn($route): string => $route->html, $site->routes));
    assertTrueValue(str_contains($html, 'class="cms-focal-85-20"'), 'profile and composition render frozen focal class');
    assertTrueValue(str_contains($html, 'assets/media-focal.css'), 'external stylesheet is linked');
    assertTrueValue(!str_contains($html, 'style="object-position'), 'no inline editor CSS');
    $point = $snapshot->media($id)->focalPoint;
    $css = MediaFocalStyle::stylesheet([$point, new FocalPoint(), $point]);
    assertSameValue(1, substr_count($css, '.cms-focal-85-20{object-position:85% 20%}'), 'rules deduplicate safely');
    assertSameValue($css, MediaFocalStyle::stylesheet([new FocalPoint(), $point]), 'deterministic order');
};
