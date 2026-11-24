<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\AssetVersion;
use Utsuri\PortableCms\Conventional\Compiler\CompilerIdentity;

$tests['asset versions mark stable-named files in pages, stylesheets and module imports and nothing else'] = static function (): void {
    $compiler = new CompilerIdentity('portable-cms', '0.15.1', str_repeat('a', 64));
    $version = AssetVersion::of(str_repeat('1', 64), str_repeat('2', 64), $compiler);
    assertTrueValue(preg_match('/\A[a-f0-9]{12}\z/', $version) === 1, 'a version is twelve hex characters');
    assertTrueValue($version !== AssetVersion::of(str_repeat('3', 64), str_repeat('2', 64), $compiler), 'new content gives a new version');
    $html = '<link rel="stylesheet" href="../../assets/site.css"><script type="module" src="../../assets/site.js" integrity="sha256-x"></script>'
        . '<link rel="icon" href="/icons/icon-32.png"><a href="../../about/">About</a><a href="https://example.test/x.css">Other</a>'
        . '<img src="../../media/abc-display.webp" srcset="../../assets/a.png 1x, ../../assets/b.png 2x"><video poster="../../assets/poster.jpg"></video>'
        . '<link href="../../assets/x.css?keep=1" rel="stylesheet"><a href="#top">Top</a><img src="data:image/png;base64,AA">'
        . '<p>Text that mentions src="../../assets/not-a-tag.css" stays text.</p>';
    $out = AssetVersion::html($html, '/films/hiedra/', $version);
    foreach (['href="../../assets/site.css?v=' . $version . '"', 'src="../../assets/site.js?v=' . $version . '" integrity="sha256-x"', 'href="/icons/icon-32.png?v=' . $version . '"',
        'srcset="../../assets/a.png?v=' . $version . ' 1x, ../../assets/b.png?v=' . $version . ' 2x"', 'poster="../../assets/poster.jpg?v=' . $version . '"'] as $expected) {
        assertTrueValue(str_contains($out, $expected), 'versioned: ' . $expected);
    }
    foreach (['href="../../about/"', 'href="https://example.test/x.css"', 'src="../../media/abc-display.webp"', 'href="../../assets/x.css?keep=1"', 'href="#top"', 'src="data:image/png;base64,AA"', 'src="../../assets/not-a-tag.css" stays text'] as $kept) {
        assertTrueValue(str_contains($out, $kept), 'unchanged: ' . $kept);
    }
    assertSameValue('../assets/font.woff2', AssetVersion::reference('../assets/font.woff2', '', $version), 'a reference that climbs out of the site is left alone');
    assertSameValue('robots.txt', AssetVersion::reference('robots.txt', '', $version), 'files for crawlers keep their address');
    assertSameValue('./icon.svg?v=' . $version . '#mark', AssetVersion::reference('./icon.svg#mark', 'assets/', $version), 'a fragment stays after the version');
    $css = '@import "./base.css"; @font-face { src: url("./sans.woff2") format("woff2"), url(../media/abc.woff2); } .a { background: url(data:image/svg+xml;utf8,<svg/>); }';
    assertSameValue(
        '@import "./base.css?v=' . $version . '"; @font-face { src: url("./sans.woff2?v=' . $version . '") format("woff2"), url(../media/abc.woff2); } .a { background: url(data:image/svg+xml;utf8,<svg/>); }',
        AssetVersion::css($css, 'assets/site.css', $version),
        'stylesheets version their imports and fonts but not media or data',
    );
    $js = 'import { a } from "./loops.js";' . "\n" . 'import "./side.js";' . "\n" . 'export { b } from \'./b.js\';' . "\n" . 'const m = await import("./player.js");' . "\n" . 'import x from "https://cdn.example/x.js";' . "\n" . 'const t = "from ./not-a-path";';
    assertSameValue(
        'import { a } from "./loops.js?v=' . $version . '";' . "\n" . 'import "./side.js?v=' . $version . '";' . "\n" . 'export { b } from \'./b.js?v=' . $version . '\';' . "\n" . 'const m = await import("./player.js?v=' . $version . '");' . "\n" . 'import x from "https://cdn.example/x.js";' . "\n" . 'const t = "from ./not-a-path";',
        AssetVersion::js($js, 'assets/site.js', $version),
        'modules version their relative imports, so every reference to a module names the same URL',
    );
    assertSameValue('no imports here', AssetVersion::js('no imports here', 'assets/x.js', $version), 'a module without imports keeps its bytes and its integrity');
};
