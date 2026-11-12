<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Design\DesignCss;
use Utsuri\PortableCms\Conventional\Design\RegistryDocument;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;
use Utsuri\PortableCms\Conventional\View\CompositionAssets;
use Utsuri\PortableCms\Conventional\View\CompositionMedia;
use Utsuri\PortableCms\Conventional\View\CompositionView;

/** Ephemeral preview has no persistence or job side effects. Parent enforces identity, origin and CSRF. */
final class CompositionPreview
{
    /** @param array<string, mixed> $values */
    public static function response(Request $request, FieldDefinition $field, RecordTypeDefinition $type, ProfileManifest $profile, array $values, ?RegistryDocument $registry, ?MediaLibrary $media, ?\Utsuri\PortableCms\Conventional\I18n\Translator $translator = null, ?ContentService $contentService = null): Response
    {
        $t = $translator ?? \Utsuri\PortableCms\Conventional\I18n\Translator::english();
        $sequence = max(0, min(2_147_483_647, (int) $request->formValue($field->id . '__sequence')));
        $locale = $request->formValue($field->id . '__locale') ?: $profile->defaultLocale;
        try {
            if (!in_array($locale, $profile->locales, true)) {
                throw new \InvalidArgumentException('Choose an enabled preview language');
            }
            $layout = Composition::from($values[$field->id] ?? null, $field, $type, $registry, $profile->supported());
            $points = [];
            $source = static function (string $id) use ($media, $locale, $profile, &$points): ?CompositionMedia {
                $item = $media?->find($id);
                if ($item !== null) {
                    $points[$id] = $item->focalPoint;
                }
                $text = ($item->text ?? \Utsuri\PortableCms\Conventional\Media\MediaText::from())->localized($locale, $profile->defaultLocale);
                return $item === null || $item->status !== 'ready' || $item->thumbnail === null ? null
                    : new CompositionMedia('/media/' . $id . (str_starts_with($item->mime, 'video/') ? '/video' : '/thumbnail'), $item->thumbnail->width, $item->thumbnail->height, $text['alternativeText'], $item->mime, $text['caption'], $text['credit'], $item->focalPoint);
            };
            $view = CompositionView::render(
                $layout,
                $registry,
                $request->path . ':' . $field->id,
                static fn(array $content): string => CompositionAssets::render($content, $type, $values, $locale, $source, link: static function (RecordReference $link) use ($contentService): ?string {
                    $target = $contentService?->find($link->typeId, $link->recordId);
                    return $target?->workflowState === 'published' ? '/records/' . $link->typeId . '/' . $link->recordId : null;
                }, defaultLocale: $profile->defaultLocale),
            );
            $fontPaths = [];
            foreach ($registry?->faces() ?? [] as $font) {
                if ($font['origin'] === 'upload') {
                    $fontPaths[$font['key']] = 'fonts/' . $font['sha256'] . '.woff2';
                    continue;
                }
                foreach ($profile->assets as $asset) {
                    if ($asset->checksum === $font['sha256'] && $asset->byteSize === $font['byteSize'] && $asset->mime === 'font/woff2') {
                        $fontPaths[$font['key']] = $asset->path;
                    }
                }
            }
            $css = 'html{font-size:16px}body{margin:0}p{margin:0 0 1em}.cms-layout-column{background:#f7f7f4;outline:1px dashed #ddddda}' . ($registry === null ? '' : DesignCss::compile($registry, $fontPaths)) . $view->css . \Utsuri\PortableCms\Conventional\Compiler\MediaFocalStyle::stylesheet(array_values($points));
            $role = $field->settings['defaultRole'] ?? null;
            $html = '<!doctype html><html lang="' . Html::attribute($locale) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<meta name="composition-sequence" content="' . $sequence . '"><title>' . $t->html('composition.preview_document') . '</title><style>' . $css . '</style></head><body'
                . (is_string($role) ? ' class="cms-type-' . Html::attribute($role) . '"' : '') . '>' . $view->html . '</body></html>';
            return new Response(200, $html, base64_encode(hash('sha256', $css, true)), true);
        } catch (\InvalidArgumentException $error) {
            return new Response(422, '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="composition-sequence" content="' . $sequence . '"><title>' . $t->html('composition.check_layout') . '</title></head><body><p role="alert">' . Html::text($t->failure($error)) . '.</p></body></html>', sameOriginFrame: true);
        }
    }
}
