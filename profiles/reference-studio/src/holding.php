<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\HoldingAssets;
use Utsuri\PortableCms\Conventional\Compiler\HoldingPageAssetRenderer;
use Utsuri\PortableCms\Conventional\Compiler\HoldingPageCompiler;
use Utsuri\PortableCms\Conventional\Compiler\HoldingPresentation;
use Utsuri\PortableCms\Conventional\Compiler\WebsiteIcon;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;

return static fn(): HoldingPageAssetRenderer => new class implements HoldingPageAssetRenderer {
    public function assets(): array
    {
        return ['assets/studio-sans-latin.woff2'];
    }

    public function render(WebsitePublication $settings, ?WebsiteIcon $icon, HoldingAssets $assets): HoldingPresentation
    {
        $base = HoldingPageCompiler::fallback($settings, $icon);
        $css = '@font-face{font-family:"Studio Sans";src:url("' . $assets->cssUrl('assets/studio-sans-latin.woff2') . '") format("woff2");font-weight:300 700;font-style:normal;font-display:swap}';
        return new HoldingPresentation($base->body, $base->css . $css . 'html{font-family:"Studio Sans",sans-serif}');
    }
};
