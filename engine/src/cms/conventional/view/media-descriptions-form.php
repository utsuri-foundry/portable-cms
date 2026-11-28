<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaText;

/** One native description form, also used as the enhanced uploader's inert template. */
final class MediaDescriptionsForm
{
    public const LIMIT = 8;
    public const TEMPLATE_ID = '00000000000000000000000000000000';

    public static function warning(LibraryMedia $media, ProfileManifest $profile, Translator $t): string
    {
        if (!str_starts_with($media->mime, 'image/')) {
            return '';
        }
        $missing = [];
        foreach ($profile->localeDefinitions as $locale) {
            if (in_array($locale->id, $profile->locales, true) && !($media->text ?? MediaText::from())->publishable($locale->id, $profile->defaultLocale)) {
                $missing[] = $locale->label;
            }
        }
        return $missing === [] ? '' : $t->text('media.descriptions.missing', ['languages' => self::languages($missing, $t)]);
    }

    /** Language names read as a sentence: "Catalan", "Catalan and English", "Catalan, Spanish and English".
     * @param list<string> $names
     */
    public static function languages(array $names, Translator $t): string
    {
        if (count($names) < 2) {
            return implode('', $names);
        }
        $last = array_pop($names);
        return $t->text('media.descriptions.pair', ['first' => implode(', ', $names), 'second' => $last]);
    }

    /** A card in an image field leaves its picture, name and missing description to the field's row above
     * it, which shows them once; the cards follow the rows' order and the name stays for screen readers.
     * @param ?array<string, mixed> $submitted
     */
    public static function card(LibraryMedia $media, ProfileManifest $profile, Translator $t, string $form = '', ?array $submitted = null, ?string $error = null, bool $saved = false, ?string $jobId = null, bool $standalone = true): string
    {
        $prefix = 'description_' . $media->id . '__';
        $association = $form === '' ? '' : ' form="' . Html::attribute($form) . '"';
        $missing = $standalone ? self::warning($media, $profile, $t) : '';
        return '<fieldset class="admin-section admin-media-description" data-media-description="' . $media->id . '"' . ($jobId === null ? '' : ' data-description-job="' . Html::attribute($jobId) . '"') . '><legend data-description-name' . ($standalone ? '' : ' class="admin-visually-hidden"') . '>' . Html::text($media->name) . '</legend>'
            . ($standalone ? ReferenceControls::thumbnail($media) : '')
            . '<input type="hidden" name="media_ids[]" value="' . $media->id . '"' . $association . '>'
            . '<input type="hidden" data-description-revision name="' . $prefix . 'revision" value="' . $media->metadataRevision . '"' . $association . '>'
            . '<p data-description-result role="' . ($error === null ? 'status' : 'alert') . '"' . ($error === null && !$saved ? ' hidden' : '') . '>' . Html::text($error ?? ($saved ? $t->text('media.descriptions.saved') : '')) . '</p>'
            . ($standalone ? '<p class="admin-field__error" data-description-warning' . ($missing === '' ? ' hidden' : '') . '>' . Html::text($missing) . '</p>' : '')
            . ($error === null ? '' : '<p><a href="' . Html::url('/media/' . $media->id) . '" target="_blank" rel="noopener">' . $t->html('media.descriptions.review') . '</a></p>')
            . MediaTextForm::render($media->text, $profile, $t, $submitted, $prefix, $form, instructions: false) . '</fieldset>';
    }

    /** @param list<LibraryMedia> $items
     * @param array<string, array<string, mixed>> $submitted
     * @param array<string, string> $errors
     * @param list<string> $saved
     */
    public static function form(array $items, ProfileManifest $profile, Translator $t, string $csrf, array $submitted = [], array $errors = [], array $saved = []): string
    {
        $markup = '<form class="admin-form" method="post" action="/media/descriptions" data-media-descriptions-form><input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '">';
        foreach ($items as $item) {
            $markup .= self::card($item, $profile, $t, submitted: $submitted[$item->id] ?? null, error: $errors[$item->id] ?? null, saved: in_array($item->id, $saved, true));
        }
        return $markup . '<input type="hidden" name="_descriptions_end" value="1"><button type="submit" class="admin-button--primary">' . $t->html('media.descriptions.save') . '</button></form>';
    }

    /** With scripts the cards keep themselves as the record does, so the Save button stays for the native form only.
     * @param list<array{media: LibraryMedia, jobId: ?string}> $items
     */
    public static function template(ProfileManifest $profile, Translator $t, array $items = [], string $form = 'media-descriptions-form', bool $instructions = true, bool $standalone = true): string
    {
        $media = new LibraryMedia(self::TEMPLATE_ID, self::TEMPLATE_ID, '', 'uploaded', 'image/webp', 0, null, null, 1, null, null);
        // Automatic keeping speaks the record keeper's words: "Saving", "Saved at" and its failures.
        $keep = array_intersect_key($t->bundle('client.keep'), array_flip(['saving', 'saved', 'failed', 'network', 'sign_in']));
        $messages = array_combine(array_map(static fn(string $key): string => 'keep.' . $key, array_keys($keep)), $keep) + ['load_failed' => $t->text('media.descriptions.load_failed'), 'title' => $t->text('media.descriptions.title'), 'review' => $t->text('media.descriptions.review')];
        $cards = '';
        foreach ($items as $item) {
            $cards .= self::card($item['media'], $profile, $t, $form, jobId: $item['jobId'], standalone: $standalone);
        }
        return '<template data-media-description-template>' . self::card($media, $profile, $t, $form, standalone: $standalone) . '</template>'
            . '<section data-upload-descriptions data-descriptions-form="' . Html::attribute($form) . '"' . ($items === [] ? ' hidden' : '') . ' data-messages="' . Html::attribute(json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . '">' . ($instructions ? FieldHelp::heading('<h3>' . $t->html('media.descriptions.title') . '</h3>', $form . '-text-help', $t->text('media.descriptions.title'), $t) : '<h3>' . $t->html('media.descriptions.title') . '</h3>')
            . ($instructions ? FieldHelp::text($form . '-text-help', $t->html('media.text.help', ['locale' => $profile->defaultLocale])) . '<noscript><p class="admin-help">' . $t->html('media.descriptions.keep_record') . '</p></noscript>' : '')
            . '<div data-description-cards>' . $cards . '</div><button type="submit" form="' . Html::attribute($form) . '">' . $t->html('media.descriptions.save') . '</button>'
            . '<p class="admin-meta" role="status" data-descriptions-status></p></section>';
    }
}
