<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Design;

/** Reviewed selector and token vocabulary; labels and source metadata never become CSS. */
final class DesignCss
{
    /** @param array<string, string> $fontPaths Verified package or checksum-addressed public paths. */
    public static function compile(RegistryDocument $registry, array $fontPaths, bool $artifactRelative = false): string
    {
        $value = $registry->document();
        $css = '';
        foreach ($registry->faces() as $font) {
            $path = $fontPaths[$font['key']] ?? '';
            if (preg_match('#\A(?:assets/[a-zA-Z0-9_./-]+\.woff2|fonts/[a-f0-9]{64}\.woff2)\z#', $path) !== 1 || str_contains($path, '..')) {
                throw new \InvalidArgumentException('Design font public path is invalid.');
            }
            $descriptors = $value['formatVersion'] === 1 ? 'font-weight:100 900;' : 'font-style:' . $font['style'] . ';font-weight:'
                . $font['weightMin'] . ($font['weightMin'] === $font['weightMax'] ? '' : ' ' . $font['weightMax']) . ';';
            $css .= '@font-face{font-family:"cms-' . $font['familyId'] . '";src:url("' . ($artifactRelative ? '../' : '/') . $path . '") format("woff2");' . $descriptors . 'font-display:swap}\n';
        }
        $css .= ':root{';
        foreach ($value['spacing'] as $space) {
            $css .= '--space-' . $space['id'] . ':' . SizeCurve::fromDocument($space['size'])->css() . ';';
        }
        foreach ($value['typography'] as $role) {
            $css .= '--type-' . $role['id'] . ':' . SizeCurve::fromDocument($role['size'])->css() . ';';
        }
        $css .= '}\n';
        foreach ($value['typography'] as $role) {
            $family = $role['fontId'] === null ? '' : '"cms-' . $role['fontId'] . '",';
            $css .= '.cms-type-' . $role['id'] . '{font-family:' . $family . $role['fallback'] . ';font-size:var(--type-' . $role['id'] . ');font-weight:' . $role['weight'] . ';line-height:' . SizeCurve::number((float) $role['lineHeight'])
                . ($value['formatVersion'] === 2 && $role['fontId'] !== null ? ';font-synthesis:none' : '') . '}\n';
        }
        foreach ($value['responsive'] as $threshold) {
            $tokens = '';
            foreach ($value['typography'] as $role) {
                foreach ($role['overrides'] as $override) {
                    if ($override['thresholdId'] === $threshold['id']) {
                        $tokens .= '--type-' . $role['id'] . ':' . SizeCurve::fromDocument($override['size'])->css() . ';';
                    }
                }
            }
            if ($tokens !== '') {
                $css .= '@media(min-width:' . $threshold['minWidth'] . 'px){:root{' . $tokens . '}}\n';
            }
        }
        return str_replace('\\n', "\n", $css);
    }

    /** @return array<string, array<int, float>> */
    public static function specimens(RegistryDocument $registry): array
    {
        $value = $registry->document();
        $result = [];
        foreach ($value['typography'] as $role) {
            foreach ([360, 768, 1280, 1920] as $viewport) {
                $curve = SizeCurve::fromDocument($role['size']);
                foreach ($value['responsive'] as $threshold) {
                    if ($threshold['minWidth'] <= $viewport) {
                        foreach ($role['overrides'] as $override) {
                            if ($override['thresholdId'] === $threshold['id']) {
                                $curve = SizeCurve::fromDocument($override['size']);
                            }
                        }
                    }
                }
                $result[$role['id']][$viewport] = $curve->at($viewport);
            }
        }
        return $result;
    }
}
