<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\Composition;
use Utsuri\PortableCms\Conventional\Design\RegistryDocument;
use Utsuri\PortableCms\Conventional\Design\SizeCurve;
use Utsuri\PortableCms\Conventional\Http\Html;

/** Identical CSS and semantic geometry for the editor iframe and profile renderer. */
final readonly class CompositionView
{
    public function __construct(public string $html, public string $css) {}

    /** @param \Closure(array{kind: string, id?: string, field?: string, link?: array{typeId: string, recordId: string}, captions?: array<string, string>}): string $asset Reviewed, escaped producer. */
    public static function render(Composition $composition, ?RegistryDocument $registry, string $scope, \Closure $asset): self
    {
        $class = 'cms-layout-' . substr(hash('sha256', $scope), 0, 24);
        $selector = '.' . $class;
        $layout = $composition->document();
        $css = $selector . '{display:grid;gap:' . self::length($layout['rowGap']) . ';padding:' . self::length($layout['padding']) . ';box-sizing:border-box;width:100%;min-width:0}'
            . $selector . ' *{box-sizing:border-box}' . $selector . ' .cms-layout-row{display:grid;min-width:0}'
            . $selector . ' .cms-layout-column{display:flex;flex-direction:column;min-width:0}'
            . $selector . ' .cms-layout-asset{min-width:0;max-width:100%;overflow-wrap:anywhere}'
            . $selector . ' img,' . $selector . ' video{display:block;width:100%;height:auto}'
            . $selector . ' figure{margin:0}'
            . $selector . ' figcaption{max-width:100%;margin:0.45rem 0 0;font:inherit;font-size:0.82em;line-height:1.4;color:inherit}'
            . $selector . ' .cms-layout-asset>:first-child{margin-top:0}' . $selector . ' .cms-layout-asset>:last-child{margin-bottom:0}'
            . $selector . ' .cms-small-caps{font-variant-caps:all-small-caps;font-synthesis:none}';
        $html = '<div class="' . $class . ($composition->defaultRole() === null ? '' : ' cms-type-' . Html::attribute($composition->defaultRole())) . '" data-layout>';
        foreach ($layout['rows'] as $position => $row) {
            $rowClass = 'cms-layout-row-' . $position;
            $shares = array_map(static fn(array $column): string => 'minmax(0,' . SizeCurve::number($column['share']) . 'fr)', $row['columns']);
            $css .= $selector . ' .' . $rowClass . '{grid-template-columns:' . implode(' ', $shares) . ';gap:' . self::length($row['gap'] ?? $layout['columnGap']) . ';min-height:' . self::length($row['minHeight']) . '}';
            $html .= '<div class="cms-layout-row ' . $rowClass . '" data-layout-row="' . Html::attribute($row['id']) . '">';
            foreach ($row['columns'] as $index => $column) {
                $columnClass = 'cms-layout-column-' . $position . '-' . $index;
                [$vertical, $horizontal] = explode('-', $column['anchor']);
                $css .= $selector . ' .' . $columnClass . '{justify-content:' . ['top' => 'flex-start', 'mid' => 'center', 'bottom' => 'flex-end'][$vertical]
                    . ';align-items:' . ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$horizontal] . ';padding:'
                    . implode(' ', array_map(self::length(...), $column['padding'])) . '}'
                    . $selector . ' .' . $columnClass . '>.cms-layout-asset{width:' . SizeCurve::number($column['width']) . '%}';
                $html .= '<div class="cms-layout-column ' . $columnClass . '" data-layout-column="' . Html::attribute($column['id']) . '">';
                if ($column['content'] !== null) {
                    $html .= '<div class="cms-layout-asset' . ($column['roleId'] === null ? '' : ' cms-type-' . Html::attribute($column['roleId'])) . '">' . $asset($column['content']) . '</div>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        if ($layout['stackAt'] !== null) {
            foreach ($registry?->document()['responsive'] ?? [] as $range) {
                if ($range['id'] === $layout['stackAt']) {
                    $css .= '@media(width<' . $range['minWidth'] . 'px){' . $selector . ' .cms-layout-row{grid-template-columns:minmax(0,1fr)}}';
                }
            }
        }
        return new self($html . '</div>', $css . "\n");
    }

    /** @param array{unit: 'px'|'rem'|'vw'|'vh'|'token', value: float|string} $length */
    private static function length(array $length): string
    {
        return $length['unit'] === 'token' ? 'var(--space-' . $length['value'] . ')' : SizeCurve::number((float) $length['value']) . $length['unit'];
    }
}
