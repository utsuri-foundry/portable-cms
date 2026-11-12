<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;

/**
 * Presents the engine-owned search and sharing override contract as a search result and a
 * social card. Field names, stored values and fallbacks are the ordinary group protocol;
 * automatic values appear as placeholders and in illustrative previews.
 */
final class SearchSharingEditor
{
    /** Contract keys, engine labels and whether an empty value falls back to an automatic one. */
    private const SEARCH = ['searchTitle' => 'seo.label.search_title', 'description' => 'seo.label.description'];
    private const SHARING = ['sharingTitle' => 'seo.label.sharing_title', 'sharingDescription' => 'seo.label.sharing_description'];

    /**
     * @param array<string, mixed> $values
     * @param \Closure(FieldDefinition, mixed): string $render Renders one native control.
     */
    public static function render(
        RecordTypeDefinition $type,
        FieldDefinition $field,
        array $values,
        ?string $error,
        ProfileManifest $profile,
        ContentService $content,
        ?MediaLibrary $media,
        \Closure $render,
        Request $request,
        Translator $t,
    ): string {
        $resolved = SearchSharingFeedback::resolve($type, $field, $values, $profile, $content, $media);
        $fixedExcluded = ($field->settings['searchSharing']['indexing'] ?? null) === 'excluded';
        $group = EditorialControls::structured($values[$field->id] ?? null);
        $group = is_array($group) ? $group : [];
        $children = [];
        foreach ($field->fields as $child) {
            $children[$child->id] = $child;
        }
        $action = $request->formValue('_editor_action');
        $open = $error !== null;
        $custom = [];
        $hidden = [];
        $panels = '';
        foreach ($field->localized ? $profile->localeDefinitions : [null] as $definition) {
            $locale = $definition->id ?? '';
            $prefix = $field->id . ($locale === '' ? '' : '_' . $locale);
            $item = $field->localized ? ($group[$locale] ?? []) : $group;
            $item = is_array($item) ? $item : [];
            $effective = $resolved[$locale !== '' ? $locale : $profile->defaultLocale] ?? null;
            $code = $locale === '' ? '' : strtoupper($locale);
            foreach (['searchTitle', 'description', 'sharingTitle', 'sharingDescription', 'sharingImage'] as $key) {
                $value = $item[$key] ?? null;
                if ((is_string($value) && trim($value) !== '') || (is_array($value) && $value !== [])) {
                    $custom[$code] = $code;
                }
            }
            if ($fixedExcluded || ($item['excludeFromSearch'] ?? false) === true) {
                $hidden[$code] = $code;
            }
            if (isset($children['sharingImage']) && str_contains($action, ':' . EditorialControls::childName($prefix, 'sharingImage'))) {
                $open = true;
            }
            $control = static function (string $key, string $label, string $automatic = '') use ($children, $prefix, $locale, $item, $render, $t, $effective, $fixedExcluded): string {
                $source = $effective['sources'][$key] ?? null;
                if (!isset($children[$key]) || ($key === 'excludeFromSearch' && $fixedExcluded)) {
                    return '';
                }
                $child = $children[$key];
                $named = GroupControls::child(new FieldDefinition(
                    $child->id,
                    $t->text($label),
                    $child->type,
                    $child->localized,
                    $child->requiredOnDraft,
                    $child->requiredOnPublish,
                    $child->settings,
                    [],
                    $child->maximumLength,
                    $child->targetType,
                    $child->maximumItems,
                    hint: $child->hint,
                ), $prefix, $locale);
                $html = $render($named, $item[$key] ?? null);
                if ($automatic !== '') {
                    if (trim((string) (is_string($item[$key] ?? null) ? $item[$key] : '')) === '' && isset($source)) {
                        $html .= '<p class="admin-help admin-seo__automatic">' . $t->html('seo.automatic', ['source' => $t->text('seo.source.' . $source)]) . '</p>';
                    }
                    // An empty override visibly means automatic: the field shows what will be used.
                    $replacement = ' name="' . $named->id . '" placeholder="' . Html::attribute($automatic) . '"';
                    $html = preg_replace_callback('/ name="' . preg_quote($named->id, '/') . '"/', static fn(): string => $replacement, $html, 1) ?? $html;
                }
                return $html;
            };
            $search = '';
            foreach (self::SEARCH as $key => $label) {
                $search .= $control($key, $label, $effective === null ? '' : ($key === 'searchTitle' ? $effective['searchTitle'] : $effective['description']));
            }
            $sharing = '';
            foreach (self::SHARING as $key => $label) {
                $sharing .= $control($key, $label, $effective === null ? '' : ($key === 'sharingTitle' ? $effective['searchTitle'] : $effective['description']));
            }
            $differentText = trim((string) ($item['sharingTitle'] ?? '')) !== '' || trim((string) ($item['sharingDescription'] ?? '')) !== '';
            $panel = '<div class="admin-seo__grid"><section class="admin-seo__block" aria-labelledby="' . Html::attribute($prefix . '-search') . '"><h3 id="' . Html::attribute($prefix . '-search') . '">' . $t->html('seo.search_result') . '</h3>'
                . $search . $control('excludeFromSearch', 'seo.label.exclude')
                . '<p class="admin-help">' . $t->html($fixedExcluded ? 'seo.indexing.excluded' : 'seo.exclude_help') . '</p></section>'
                . self::searchPreview($effective, $type, $values, $locale, $t) . '</div>'
                . '<div class="admin-seo__grid"><section class="admin-seo__block" aria-labelledby="' . Html::attribute($prefix . '-social') . '"><h3 id="' . Html::attribute($prefix . '-social') . '">' . $t->html('seo.social_card') . '</h3>'
                . $control('sharingImage', 'seo.label.sharing_image')
                . '<details class="admin-seo__different"' . ($differentText ? ' open' : '') . '><summary>' . $t->html('seo.different_text') . '</summary>' . $sharing . '</details></section>'
                . self::socialPreview($effective, $t) . '</div>';
            $panels .= $definition === null ? $panel
                : '<div class="admin-locale" data-locale-panel="' . Html::attribute($locale) . '" lang="' . Html::attribute($locale) . '"><span class="admin-locale__label">' . Html::text($definition->label) . '</span>' . $panel . '</div>';
        }
        $state = $custom === [] ? $t->html('seo.summary.automatic') : $t->html('seo.summary.custom', ['languages' => implode(', ', $custom)]);
        $visibility = $hidden === [] ? '<span class="admin-meta">' . $t->html('seo.summary.visible') . '</span>'
            : '<span class="admin-status admin-status--warning">' . $t->html('seo.summary.hidden', ['languages' => implode(', ', $hidden)]) . '</span>';
        $heading = isset($field->settings['searchSharing']['pagePath']) ? Html::text($field->label) : $t->html('seo.block');
        return '<details class="admin-optional admin-seo-toggle"' . ($open ? ' open' : '') . ' data-search-sharing-editor><summary><span>' . $heading . '</span>'
            . '<span class="admin-seo__state"><span class="admin-meta">' . $state . '</span>' . $visibility . '</span></summary>'
            . '<fieldset class="admin-field admin-seo" data-locale-optional id="field-' . Html::attribute($field->id) . '"><legend class="admin-visually-hidden">' . $heading . '</legend>'
            . '<input type="hidden" name="_present_' . Html::attribute($field->id) . '" value="1">'
            . ($error === null ? '' : '<p class="admin-field__error" role="alert">' . Html::text($error) . '</p>')
            . $panels . '</fieldset></details>';
    }

    /**
     * @param array<string, mixed>|null $effective
     * @param array<string, mixed> $values
     */
    private static function searchPreview(?array $effective, RecordTypeDefinition $type, array $values, string $locale, Translator $t): string
    {
        $markup = '<figure class="admin-seo__preview" aria-label="' . $t->html('seo.preview.search') . '"><figcaption class="admin-seo__caption"><span>' . $t->html('seo.preview.search') . '</span><span>' . $t->html('seo.preview.illustrative') . '</span></figcaption>';
        if ($effective === null) {
            return $markup . '<p class="admin-meta">' . $t->html('seo.unavailable') . '</p></figure>';
        }
        $slug = $values['slug'] ?? null;
        $slug = is_array($slug) ? ($slug[$locale] ?? '') : $slug;
        $path = $type->route === null ? '' : ' › ' . $type->route . (is_string($slug) && $slug !== '' ? ' › ' . $slug : '');
        $markup .= '<div class="admin-serp"><span class="admin-serp__site">' . Html::text((string) $effective['siteName']) . '<small>' . Html::text($path) . '</small></span>'
            . '<span class="admin-serp__title">' . Html::text((string) $effective['searchTitle']) . '</span>'
            . ($effective['description'] === '' ? '' : '<span class="admin-serp__description">' . Html::text((string) $effective['description']) . '</span>') . '</div>';
        if ($effective['description'] === '') {
            $markup .= '<p class="admin-meta">' . $t->html('seo.empty_description') . '</p>';
        } elseif ($effective['description'] === $effective['searchTitle']) {
            $markup .= '<p class="admin-meta">' . $t->html('seo.repeated_copy') . '</p>';
        }
        return $markup . '</figure>';
    }

    /** @param array<string, mixed>|null $effective */
    private static function socialPreview(?array $effective, Translator $t): string
    {
        $markup = '<figure class="admin-seo__preview" aria-label="' . $t->html('seo.preview.social') . '"><figcaption class="admin-seo__caption"><span>' . $t->html('seo.preview.social') . '</span><span>' . $t->html('seo.preview.illustrative') . '</span></figcaption>';
        if ($effective === null) {
            return $markup . '<p class="admin-meta">' . $t->html('seo.unavailable') . '</p></figure>';
        }
        $image = $effective['image'];
        return $markup . '<div class="admin-social">'
            . ($image instanceof \Utsuri\PortableCms\Conventional\Media\LibraryMedia && $image->status === 'ready' && $image->thumbnail !== null
                ? '<img class="admin-social__image" src="' . Html::url('/media/' . $image->id . '/thumbnail') . '" alt="" loading="lazy">'
                : '<span class="admin-social__image admin-social__image--none">' . $t->html('seo.image_unavailable') . '</span>')
            . '<span class="admin-social__text"><small>' . Html::text((string) $effective['siteName']) . '</small><b>' . Html::text((string) $effective['sharingTitle']) . '</b>'
            . ($effective['sharingDescription'] === '' ? '' : '<span>' . Html::text((string) $effective['sharingDescription']) . '</span>') . '</span></div>'
            . '<p class="admin-meta">' . $t->html('seo.image_source', ['source' => $t->text('seo.source.' . $effective['imageSource'])]) . '</p></figure>';
    }
}
